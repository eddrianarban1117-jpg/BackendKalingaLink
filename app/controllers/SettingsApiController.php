<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class SettingsApiController extends Controller
{
    private const ALLOWED_SETTINGS = [
        'language',
        'theme',
        'notifications',
        'dateFormat',
        'timeFormat',
        'timeZone',
        'defaultDashboard',
    ];

    private const NOTIFICATION_KEYS = [
        'announcements',
        'systemAlerts',
        'reminders',
        'email',
        'inApp',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->call->library('api');
        $this->call->model('UsersModel');
    }

    public function index()
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        if (!$user) return;

        $rows = $this->db->table('user_settings')
            ->select('setting_key, setting_value')
            ->where('user_id', $user['id'])
            ->order_by('setting_key', 'ASC')
            ->get_all();
        $settings = [];
        foreach ($rows as $row) {
            $value = json_decode($row['setting_value'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new UnexpectedValueException('A saved account setting contains invalid JSON.');
            }
            $settings[$row['setting_key']] = $value;
        }

        $this->api->respond([
            'status' => true,
            'data' => [
                'profile' => [
                    'first_name' => $user['first_name'],
                    'last_name' => $user['last_name'],
                    'email' => $user['email'],
                ],
                'preferences' => $settings,
            ],
        ]);
    }

    public function save()
    {
        $this->api->require_method('PUT');
        $user = $this->authenticated_user();
        if (!$user) return;

        $input = $this->api->body(false);
        $profile = $input['profile'] ?? [];
        $settings = $input['settings'] ?? [];
        if (!is_array($profile) || !is_array($settings) || (!$profile && !$settings)) {
            $this->api->respond_error('Provide account profile or preference changes to save.', 400);
            return;
        }

        if ($profile) {
            $allowed_profile_keys = ['first_name', 'last_name', 'email'];
            foreach ($profile as $key => $value) {
                if (!in_array($key, $allowed_profile_keys, true)
                    || !is_string($value)
                    || trim($value) === ''
                    || mb_strlen(trim($value)) > ($key === 'email' ? 255 : 100)
                    || ($key === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false)) {
                    $this->api->respond_error('Provide a valid first name, last name, and email address.', 400);
                    return;
                }
            }
            $profile = array_map('trim', $profile);
            if (isset($profile['email'])) {
                $duplicate = $this->db->raw(
                    'SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1',
                    [$profile['email'], $user['id']]
                )->fetch(PDO::FETCH_ASSOC);
                if ($duplicate) {
                    $this->api->respond_error('That email address is already in use.', 409);
                    return;
                }
            }
        }

        foreach ($settings as $key => $value) {
            if (!in_array($key, self::ALLOWED_SETTINGS, true) || !$this->valid_setting($key, $value)) {
                $this->api->respond_error('One or more account settings have invalid values.', 400);
                return;
            }
        }

        $this->db->transaction();
        try {
            if ($profile) {
                $this->db->table('users')
                    ->where('id', $user['id'])
                    ->update($profile + [
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            }
            foreach ($settings as $key => $value) {
                $encoded = json_encode($value, JSON_THROW_ON_ERROR);
                $existing = $this->db->table('user_settings')
                    ->select('id')
                    ->where('user_id', $user['id'])
                    ->where('setting_key', $key)
                    ->get();
                if ($existing) {
                    $this->db->table('user_settings')
                        ->where('id', $existing['id'])
                        ->update([
                            'setting_value' => $encoded,
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                } else {
                    $this->db->table('user_settings')->insert([
                        'user_id' => $user['id'],
                        'setting_key' => $key,
                        'setting_value' => $encoded,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        $this->api->respond([
            'status' => true,
            'message' => 'Account settings saved.',
            'data' => [
                'profile' => array_merge([
                    'first_name' => $user['first_name'],
                    'last_name' => $user['last_name'],
                    'email' => $user['email'],
                ], $profile),
                'preferences' => $settings,
            ],
        ]);
    }

    public function profile_photo()
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        if (!$user) return;

        $relative_path = (string) ($user['profile_photo'] ?? '');
        if ($relative_path === '') {
            http_response_code(204);
            return;
        }

        $user_id = (int) $user['id'];
        if (!preg_match(
            '#^runtime/uploads/profile-photos/' . $user_id . '/[a-f0-9]{40}\.(?:jpg|png|webp)$#D',
            $relative_path
        )) {
            $this->api->respond_error('The saved profile photo path is invalid.', 500);
            return;
        }

        $path = ROOT_DIR . str_replace('/', DIRECTORY_SEPARATOR, $relative_path);
        if (!is_file($path) || !is_readable($path)) {
            $this->api->respond_error('The saved profile photo could not be found.', 404);
            return;
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $this->api->respond_error('The saved profile photo has an unsupported format.', 500);
            return;
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public function upload_profile_photo()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        if (!$user) return;

        $first_name = trim((string) ($_POST['first_name'] ?? ''));
        $last_name = trim((string) ($_POST['last_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($first_name === '' || $last_name === ''
            || mb_strlen($first_name) > 100 || mb_strlen($last_name) > 100
            || $email === '' || mb_strlen($email) > 255
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->api->respond_error('Provide a valid full name and email address.', 400);
            return;
        }

        $duplicate = $this->db->raw(
            'SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1',
            [$email, $user['id']]
        )->fetch(PDO::FETCH_ASSOC);
        if ($duplicate) {
            $this->api->respond_error('That email address is already in use.', 409);
            return;
        }

        try {
            $photo = $this->validate_account_profile_photo($_FILES['profile_photo'] ?? null);
        } catch (InvalidArgumentException $error) {
            $this->api->respond_error($error->getMessage(), 400);
            return;
        }

        $stored_photo = null;
        $old_photo_path = (string) ($user['profile_photo'] ?? '');
        try {
            if ($photo) {
                $stored_photo = $this->store_account_profile_photo($photo, (int) $user['id']);
            }

            $updates = [
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($stored_photo) {
                $updates['profile_photo'] = $stored_photo['relative_path'];
            }

            $this->db->transaction();
            try {
                $this->db->table('users')
                    ->where('id', (int) $user['id'])
                    ->update($updates);
                $this->db->commit();
            } catch (Throwable $error) {
                $this->db->roll_back();
                throw $error;
            }
        } catch (Throwable $error) {
            if ($stored_photo && is_file($stored_photo['absolute_path'])) {
                unlink($stored_photo['absolute_path']);
            }
            throw $error;
        }

        if ($stored_photo) {
            $this->delete_account_profile_photo($old_photo_path, (int) $user['id']);
        }

        $profile_photo = $stored_photo
            ? $stored_photo['relative_path']
            : ($old_photo_path !== '' ? $old_photo_path : null);
        $this->api->respond([
            'status' => true,
            'message' => 'Profile updated.',
            'data' => [
                'profile' => [
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'email' => $email,
                    'profile_photo' => $profile_photo,
                ],
            ],
        ]);
    }

    private function validate_account_profile_photo($file)
    {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK
            || !is_uploaded_file($file['tmp_name'] ?? '')
            || ($file['size'] ?? 0) <= 0
            || $file['size'] > 2097152) {
            throw new InvalidArgumentException('Profile pictures must be JPG or PNG files no larger than 2 MB.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $image = @getimagesize($file['tmp_name']);
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ];
        if (!isset($extensions[$mime]) || !$image || ($image['mime'] ?? null) !== $mime) {
            throw new InvalidArgumentException('Profile pictures must be valid JPG or PNG images.');
        }

        return [
            'tmp_name' => $file['tmp_name'],
            'extension' => $extensions[$mime],
        ];
    }

    private function store_account_profile_photo(array $photo, $user_id)
    {
        $directory = ROOT_DIR . 'runtime/uploads/profile-photos/' . (int) $user_id;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the private profile photo directory.');
        }

        $stored_name = bin2hex(random_bytes(20)) . '.' . $photo['extension'];
        $absolute_path = $directory . DIRECTORY_SEPARATOR . $stored_name;
        if (!move_uploaded_file($photo['tmp_name'], $absolute_path)) {
            throw new RuntimeException('Could not store the profile photo.');
        }

        return [
            'absolute_path' => $absolute_path,
            'relative_path' => 'runtime/uploads/profile-photos/' . (int) $user_id . '/' . $stored_name,
        ];
    }

    private function delete_account_profile_photo($relative_path, $user_id)
    {
        if (!preg_match(
            '#^runtime/uploads/profile-photos/' . (int) $user_id . '/[a-f0-9]{40}\.(?:jpg|png|webp)$#D',
            (string) $relative_path
        )) {
            return;
        }
        $path = ROOT_DIR . str_replace('/', DIRECTORY_SEPARATOR, $relative_path);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function authenticated_user()
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
        if ($user['role'] === 'teacher') {
            $teacher_access = $this->call->library('TeacherAccess', $this->db);
            if (!$teacher_access->is_active((int) $user['id'])) {
                $this->api->respond_error('Teacher account approval and invitation acceptance are required.', 403);
                return null;
            }
        }
        return $user;
    }

    private function valid_setting($key, $value)
    {
        if ($key === 'language') return in_array($value, ['en-US', 'fil'], true);
        if ($key === 'theme') return in_array($value, ['light', 'dark', 'system'], true);
        if ($key === 'dateFormat') return in_array($value, ['MM/DD/YYYY', 'DD/MM/YYYY', 'YYYY-MM-DD'], true);
        if ($key === 'timeFormat') return in_array($value, ['12-hour (AM/PM)', '24-hour'], true);
        if ($key === 'timeZone') return in_array($value, ['(UTC+08:00) Manila', '(UTC+00:00) UTC', '(UTC-05:00) Eastern Time'], true);
        if ($key === 'defaultDashboard') {
            return in_array($value, [
                'MSWDO Dashboard',
                'Child Development Centers',
                'Enrollment Management',
                'Reports and Analytics',
            ], true);
        }
        if ($key !== 'notifications' || !is_array($value)) return false;

        foreach ($value as $notification_key => $enabled) {
            if (!in_array($notification_key, self::NOTIFICATION_KEYS, true) || !is_bool($enabled)) {
                return false;
            }
        }
        return true;
    }
}
