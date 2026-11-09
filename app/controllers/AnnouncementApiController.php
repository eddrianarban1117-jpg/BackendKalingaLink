<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AnnouncementApiController extends Controller
{
    private const CATEGORIES = [
        'General',
        'Enrollment',
        'Meeting',
        'Health',
        'Training',
        'Event',
        'System Update',
    ];
    private const AUDIENCES = [
        'parents' => 'parents',
        'teachers' => 'teachers',
        'all-users' => 'both',
    ];
    private const MAX_ATTACHMENT_SIZE = 10485760;

    public function __construct()
    {
        parent::__construct();
        date_default_timezone_set('Asia/Manila');
        $this->call->library('api');
        $this->call->model('UsersModel');
    }

    public function index()
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user(['admin', 'teacher', 'parent']);
        if (!$user) return;

        $sql = 'SELECT a.id, a.author_user_id, a.title, a.message, a.category, a.audience,
                       a.published_at, a.status, a.created_at, a.updated_at,
                       a.attachment_path, a.attachment_name, a.attachment_mime, a.attachment_size,
                       u.first_name AS author_first_name, u.last_name AS author_last_name,
                       u.role AS author_role,
                       CASE WHEN a.status = \'scheduled\' AND a.published_at <= ?
                            THEN \'published\' ELSE a.status END AS effective_status
                FROM announcements a
                JOIN users u ON u.id = a.author_user_id';
        $now = date('Y-m-d H:i:s');
        $params = [$now];
        if ($user['role'] !== 'admin') {
            $audience = $user['role'] === 'parent' ? 'parents' : 'teachers';
            $sql .= ' WHERE a.audience IN (?, \'both\')
                      AND a.status IN (\'published\', \'scheduled\')
                      AND a.published_at <= ?
                      AND (a.expires_at IS NULL OR a.expires_at > ?)';
            $params[] = $audience;
            $params[] = $now;
            $params[] = $now;
        }
        $sql .= ' ORDER BY a.published_at DESC, a.created_at DESC, a.id DESC';
        $rows = $this->db->raw($sql, $params)->fetchAll(PDO::FETCH_ASSOC);

        $announcements = array_map(
            fn($row) => $this->present_announcement($row),
            $rows
        );

        $this->api->respond([
            'status' => true,
            'data' => $announcements,
        ]);
    }

    public function create()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user(['admin']);
        if (!$user) return;

        $input = $this->api->body(false);
        $announcement = $this->validated_input($input);
        if (!$announcement) return;
        $upload = $this->validate_attachment();
        if ($upload === false) return;

        $stored = $upload ? $this->store_attachment($upload) : null;
        try {
            $this->db->table('announcements')->insert([
                'author_user_id' => (int) $user['id'],
                'title' => $announcement['title'],
                'message' => $announcement['message'],
                'category' => $announcement['category'],
                'audience' => $announcement['audience'],
                'published_at' => $announcement['published_at'],
                'status' => $announcement['status'],
                'attachment_path' => $stored['path'] ?? null,
                'attachment_name' => $stored['name'] ?? null,
                'attachment_mime' => $stored['mime'] ?? null,
                'attachment_size' => $stored['size'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $error) {
            $this->remove_attachment($stored['path'] ?? null);
            throw $error;
        }

        $this->api->respond([
            'status' => true,
            'message' => 'Announcement created.',
            'id' => (int) $this->db->last_id(),
        ], 201);
    }

    public function update($id)
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user(['admin']);
        if (!$user) return;

        $id = filter_var($id, FILTER_VALIDATE_INT);
        if (!$id) {
            $this->api->respond_error('Announcement not found.', 404);
            return;
        }
        $existing = $this->find_announcement((int) $id);
        if (!$existing) {
            $this->api->respond_error('Announcement not found.', 404);
            return;
        }

        $input = $this->api->body(false);
        $announcement = $this->validated_input($input);
        if (!$announcement) return;
        $upload = $this->validate_attachment();
        if ($upload === false) return;
        $stored = $upload ? $this->store_attachment($upload) : null;

        try {
            $values = [
                'title' => $announcement['title'],
                'message' => $announcement['message'],
                'category' => $announcement['category'],
                'audience' => $announcement['audience'],
                'published_at' => $announcement['published_at'],
                'status' => $announcement['status'],
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($stored) {
                $values['attachment_path'] = $stored['path'];
                $values['attachment_name'] = $stored['name'];
                $values['attachment_mime'] = $stored['mime'];
                $values['attachment_size'] = $stored['size'];
            } elseif (($input['remove_attachment'] ?? '') === 'true') {
                $values['attachment_path'] = null;
                $values['attachment_name'] = null;
                $values['attachment_mime'] = null;
                $values['attachment_size'] = null;
            }
            $this->db->table('announcements')
                ->where('id', (int) $id)
                ->update($values);
        } catch (Throwable $error) {
            $this->remove_attachment($stored['path'] ?? null);
            throw $error;
        }

        if ($stored || (($input['remove_attachment'] ?? '') === 'true')) {
            $this->remove_attachment($existing['attachment_path']);
        }
        $this->api->respond([
            'status' => true,
            'message' => 'Announcement updated.',
        ]);
    }

    public function delete($id)
    {
        $this->api->require_method('DELETE');
        $user = $this->authenticated_user(['admin']);
        if (!$user) return;

        $id = filter_var($id, FILTER_VALIDATE_INT);
        $existing = $id ? $this->find_announcement((int) $id) : null;
        if (!$existing) {
            $this->api->respond_error('Announcement not found.', 404);
            return;
        }

        $this->db->table('announcements')->where('id', (int) $id)->delete();
        $this->remove_attachment($existing['attachment_path']);
        $this->api->respond([
            'status' => true,
            'message' => 'Announcement deleted.',
        ]);
    }

    public function download($id)
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user(['admin', 'teacher', 'parent']);
        if (!$user) return;

        $id = filter_var($id, FILTER_VALIDATE_INT);
        $row = $id ? $this->find_announcement((int) $id) : null;
        if (!$row || !$row['attachment_path']) {
            $this->api->respond_error('Attachment not found.', 404);
            return;
        }
        if ($user['role'] !== 'admin') {
            $audience = $user['role'] === 'parent' ? 'parents' : 'teachers';
            if (!in_array($row['audience'], [$audience, 'both'], true)
                || !in_array($row['status'], ['published', 'scheduled'], true)
                || strtotime($row['published_at']) > time()) {
                $this->api->respond_error('You are not authorized to access this attachment.', 403);
                return;
            }
        }

        $path = ROOT_DIR . 'runtime' . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'announcements' . DIRECTORY_SEPARATOR
            . basename($row['attachment_path']);
        if (!is_file($path) || !is_readable($path)) {
            $this->api->respond_error('Attachment file is unavailable.', 404);
            return;
        }
        $name = str_replace(["\r", "\n", '"'], '', $row['attachment_name']);
        header('Content-Type: ' . $row['attachment_mime']);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
    }

    private function validated_input(array $input)
    {
        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
        $message = is_string($input['message'] ?? null) ? trim($input['message']) : '';
        $category = is_string($input['category'] ?? null) ? $input['category'] : '';
        $audience = is_string($input['audience'] ?? null) ? $input['audience'] : '';
        $status = is_string($input['status'] ?? null) ? $input['status'] : '';
        $published_at = $this->parse_publication_time($input['published_at'] ?? null);

        if ($title === '' || mb_strlen($title) > 180 || $message === ''
            || !in_array($category, self::CATEGORIES, true)
            || !isset(self::AUDIENCES[$audience])
            || !in_array($status, ['draft', 'published', 'scheduled'], true)) {
            $this->api->respond_error('Provide a title, message, valid category, audience, and status.', 400);
            return null;
        }
        if ($published_at === false) {
            $this->api->respond_error('Choose a valid publication date and time.', 400);
            return null;
        }

        $now = time();
        if ($status === 'published' && strtotime($published_at) > $now) {
            $this->api->respond_error('A future publication time must be saved as Scheduled.', 400);
            return null;
        }
        if ($status === 'scheduled' && strtotime($published_at) <= $now) {
            $this->api->respond_error('Scheduled announcements must have a future publication time.', 400);
            return null;
        }
        if ($status === 'published') {
            $published_at = date('Y-m-d H:i:s');
        }

        return [
            'title' => $title,
            'message' => $message,
            'category' => $category,
            'audience' => self::AUDIENCES[$audience],
            'status' => $status,
            'published_at' => $published_at,
        ];
    }

    private function parse_publication_time($value)
    {
        if ($value === null || $value === '') {
            return date('Y-m-d H:i:s');
        }
        if (!is_string($value)) return false;
        $format = strpos($value, 'T') !== false ? 'Y-m-d\TH:i' : 'Y-m-d H:i:s';
        $date = DateTime::createFromFormat('!' . $format, $value);
        $errors = DateTime::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format($format) !== $value) {
            return false;
        }
        return $date->format('Y-m-d H:i:s');
    }

    private function validate_attachment()
    {
        $file = $_FILES['attachment'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK
            || !is_uploaded_file($file['tmp_name'] ?? '')
            || ($file['size'] ?? 0) <= 0
            || $file['size'] > self::MAX_ATTACHMENT_SIZE) {
            $this->api->respond_error('Attachments must be no larger than 10 MB.', 400);
            return false;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extensions = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ];
        if (!isset($extensions[$mime])) {
            $this->api->respond_error('Attachments must be PDF, JPG, or PNG files.', 400);
            return false;
        }
        return [
            'tmp_name' => $file['tmp_name'],
            'original_name' => mb_substr(basename((string) $file['name']), 0, 255),
            'mime' => $mime,
            'extension' => $extensions[$mime],
            'size' => (int) $file['size'],
        ];
    }

    private function store_attachment(array $file)
    {
        $directory = ROOT_DIR . 'runtime' . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'announcements';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the private announcements upload directory.');
        }
        $name = bin2hex(random_bytes(20)) . '.' . $file['extension'];
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (!move_uploaded_file($file['tmp_name'], $path)) {
            throw new RuntimeException('Could not store the announcement attachment.');
        }
        return [
            'path' => $name,
            'name' => $file['original_name'],
            'mime' => $file['mime'],
            'size' => $file['size'],
        ];
    }

    private function remove_attachment($name)
    {
        if (!is_string($name) || $name === '') return;
        $path = ROOT_DIR . 'runtime' . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'announcements' . DIRECTORY_SEPARATOR . basename($name);
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Could not remove the announcement attachment.');
        }
    }

    private function find_announcement($id)
    {
        return $this->db->raw(
            'SELECT id, title, message, category, audience, status, published_at, attachment_path,
                    attachment_name, attachment_mime, attachment_size
             FROM announcements WHERE id = ? LIMIT 1',
            [$id]
        )->fetch(PDO::FETCH_ASSOC);
    }

    private function present_announcement(array $row)
    {
        $audience = $row['audience'] === 'both' ? 'all-users' : $row['audience'];
        $result = [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'message' => $row['message'],
            'category' => $row['category'],
            'audience' => $audience,
            'status' => $row['effective_status'],
            'created_at' => $row['created_at'],
            'published_at' => $row['published_at'],
            'created_by' => trim($row['author_first_name'] . ' ' . $row['author_last_name']),
        ];
        if ($row['attachment_path']) {
            $result['attachment'] = [
                'name' => $row['attachment_name'],
                'mime_type' => $row['attachment_mime'],
                'size' => (int) $row['attachment_size'],
                'download_url' => '/api/announcements/' . (int) $row['id'] . '/attachment',
                'created_by_role' => $row['author_role'],
            ];
        } else {
            $result['attachment'] = null;
        }
        return $result;
    }

    private function authenticated_user(array $roles)
    {
        $payload = $this->api->require_jwt();
        $user_id = filter_var($payload['sub'] ?? null, FILTER_VALIDATE_INT);
        if (!$user_id) {
            $this->api->respond_error('Unauthorized.', 401);
            return null;
        }
        $user = $this->UsersModel->find_by_id($user_id);
        if (!$user || (int) $user['is_active'] !== 1) {
            $this->api->respond_error('Account is unavailable.', 401);
            return null;
        }
        if (!in_array($user['role'], $roles, true)) {
            $this->api->respond_error('You are not authorized to perform this action.', 403);
            return null;
        }
        if ($user['role'] === 'teacher') {
            $teacher_access = $this->call->library('TeacherAccess', $this->db);
            if (!$teacher_access->is_active((int) $user['id'])) {
                $this->api->respond_error('Teacher account approval and invitation acceptance are required.', 403);
                return null;
            }
        }
        return $user;
    }
}
