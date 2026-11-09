<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class SmtpMailer
{
    private $socket;
    private $timeout = 20;

    public function send($recipient, $subject, $text, $html)
    {
        $host = trim((string) getenv('MAIL_HOST'));
        $port = (int) (getenv('MAIL_PORT') ?: 587);
        $username = trim((string) getenv('MAIL_USERNAME'));
        $password = preg_replace('/\s+/', '', (string) getenv('MAIL_PASSWORD'));
        $encryption = strtolower(trim((string) getenv('MAIL_ENCRYPTION')));
        $sender = trim((string) getenv('MAIL_FROM_ADDRESS'));
        $sender_name = trim((string) (getenv('MAIL_FROM_NAME') ?: 'KalingaLink Administration'));

        if (!in_array($host, ['smtp.gmail.com', 'smtp-relay.brevo.com'], true)
            || $port !== 587 || $encryption !== 'tls'
            || $username === '' || $password === '' || $sender === ''
            || !filter_var($username, FILTER_VALIDATE_EMAIL)
            || !filter_var($sender, FILTER_VALIDATE_EMAIL)
            || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('SMTP settings are incomplete or invalid.');
        }
        foreach ([$username, $sender, $recipient] as $address) {
            if (preg_match('/[\r\n]/', $address)) {
                throw new RuntimeException('An email address contains invalid characters.');
            }
        }
        foreach ([$subject, $sender_name] as $header_value) {
            if (preg_match('/[\r\n]/', $header_value)) {
                throw new RuntimeException('An email header contains invalid characters.');
            }
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);
        $this->socket = @stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $error_number,
            $error_message,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$this->socket) {
            error_log(
                'SMTP connection failed (' . (int) $error_number . '): '
                . substr(preg_replace('/[\r\n]+/', ' ', (string) $error_message), 0, 200)
            );
            throw new RuntimeException('Could not connect to the SMTP server.');
        }
        stream_set_timeout($this->socket, $this->timeout);

        try {
            $this->expect([220]);
            $this->command('EHLO ' . $this->client_name(), [250]);
            $this->command('STARTTLS', [220]);
            $crypto_method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $crypto_method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            $crypto = stream_socket_enable_crypto($this->socket, true, $crypto_method);
            if ($crypto !== true) {
                throw new RuntimeException('Could not establish a secure SMTP connection.');
            }
            $this->command('EHLO ' . $this->client_name(), [250]);
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($username), [334]);
            $this->command(base64_encode($password), [235]);
            $this->command('MAIL FROM:<' . $sender . '>', [250]);
            $this->command('RCPT TO:<' . $recipient . '>', [250, 251]);
            $this->command('DATA', [354]);
            $message = $this->message($sender, $sender_name, $recipient, $subject, $text, $html);
            $message = preg_replace('/(?m)^\./', '..', $message);
            $this->write_all($message . "\r\n.\r\n");
            $this->expect([250]);
            $this->command('QUIT', [221]);
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    private function message($sender, $sender_name, $recipient, $subject, $text, $html)
    {
        $boundary = 'kalingalink_' . bin2hex(random_bytes(16));
        $from_name = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($sender_name, 'UTF-8', 'B', "\r\n")
            : $sender_name;
        $encoded_subject = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n")
            : $subject;
        $headers = [
            'From: ' . $from_name . ' <' . $sender . '>',
            'To: <' . $recipient . '>',
            'Subject: ' . $encoded_subject,
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . substr(strrchr($sender, '@'), 1) . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text), 76, "\r\n")
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n")
            . '--' . $boundary . '--';

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function client_name()
    {
        $host = gethostname();
        return preg_match('/^[A-Za-z0-9.-]+$/', (string) $host) ? $host : 'localhost';
    }

    private function command($command, array $expected)
    {
        $this->write_all($command . "\r\n");
        $this->expect($expected);
    }

    private function write_all($message)
    {
        $length = strlen($message);
        $written = 0;
        while ($written < $length) {
            $result = fwrite($this->socket, substr($message, $written));
            if ($result === false || $result === 0) {
                throw new RuntimeException('SMTP connection failed during message submission.');
            }
            $written += $result;
        }
    }

    private function expect(array $expected)
    {
        $response = '';
        do {
            $line = fgets($this->socket, 515);
            if ($line === false) {
                throw new RuntimeException('SMTP server did not return a complete response.');
            }
            $response .= $line;
        } while (isset($line[3]) && $line[3] === '-');

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            $safe_response = preg_replace('/[\r\n]+/', ' ', trim($response));
            error_log('SMTP server rejected a message: ' . substr($safe_response, 0, 300));
            throw new RuntimeException('SMTP server did not accept the message.');
        }
    }
}
