<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class TeacherProfileApiController extends Controller
{
    private const PROFILE_FIELDS = [
        'gender' => 40,
        'marital_status' => 40,
        'nationality' => 80,
        'education' => 255,
        'years_of_service' => 60,
        'home_address' => 255,
        'emergency_name' => 160,
        'emergency_relationship' => 80,
        'emergency_phone' => 30,
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
        $user = $this->authenticated_teacher();
        if (!$user) return;

        $profile = $this->db->raw(
            'SELECT u.id, u.username, u.email, u.role, u.is_active, u.first_name,
                    u.middle_name, u.last_name, u.phone, u.profile_photo, u.last_login_at,
                    tp.position, tp.hire_date, tp.birth_date, tp.gender, tp.marital_status,
                    tp.nationality, tp.education, tp.certification, tp.years_of_service,
                    tp.home_address, tp.emergency_name, tp.emergency_relationship,
                    tp.emergency_phone, tp.skills, tp.languages, tp.working_schedule
             FROM users u
             JOIN teacher_profiles tp ON tp.user_id = u.id
             WHERE u.id = ?',
            [(int) $user['id']]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$profile) {
            $this->api->respond_error('Teacher profile not found.', 404);
            return;
        }

        $this->api->respond([
            'status' => true,
            'data' => ['profile' => $profile],
        ]);
    }

    public function update()
    {
        $this->api->require_method('PUT');
        $user = $this->authenticated_teacher();
        if (!$user) return;

        $input = $this->api->body(false);
        if (!is_array($input)) {
            $this->api->respond_error('Provide valid profile details.', 400);
            return;
        }

        $first_name = $this->text($input, 'first_name', 100);
        $middle_name = $this->text($input, 'middle_name', 100);
        $last_name = $this->text($input, 'last_name', 100);
        $username = $this->text($input, 'username', 100);
        $email = strtolower($this->text($input, 'email', 255));
        $phone = $this->text($input, 'phone', 30);

        if ($this->has_invalid_text($input, [
            'first_name' => 100,
            'middle_name' => 100,
            'last_name' => 100,
            'username' => 100,
            'email' => 255,
            'phone' => 30,
        ]) || $first_name === '' || $last_name === '' || $username === ''
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || !preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
            $this->api->respond_error('Provide a valid name, username, and email address.', 400);
            return;
        }

        $conflict = $this->db->raw(
            'SELECT id FROM users
             WHERE id <> ? AND (username = ? OR email = ?)
             LIMIT 1',
            [(int) $user['id'], $username, $email]
        )->fetch(PDO::FETCH_ASSOC);
        if ($conflict) {
            $this->api->respond_error('That username or email address is already in use.', 409);
            return;
        }

        if ($this->has_invalid_text($input, [
            'gender' => 40,
            'marital_status' => 40,
            'nationality' => 80,
            'education' => 255,
            'years_of_service' => 60,
            'home_address' => 255,
            'emergency_name' => 160,
            'emergency_relationship' => 80,
            'emergency_phone' => 30,
            'certification' => 10000,
            'skills' => 10000,
            'languages' => 10000,
            'working_schedule' => 10000,
        ])) {
            $this->api->respond_error('One or more profile fields exceed the allowed length.', 400);
            return;
        }

        $profile_values = [];
        foreach (self::PROFILE_FIELDS as $field => $max_length) {
            $value = $this->text($input, $field, $max_length);
            $profile_values[$field] = $value !== '' ? $value : null;
        }
        $profile_values['certification'] = $this->text($input, 'certification', 10000) ?: null;
        $profile_values['skills'] = $this->text($input, 'skills', 10000) ?: null;
        $profile_values['languages'] = $this->text($input, 'languages', 10000) ?: null;
        $profile_values['working_schedule'] = $this->text($input, 'working_schedule', 10000) ?: null;

        $birth_date = $this->date_or_null($input['birth_date'] ?? null);
        if (($input['birth_date'] ?? '') !== '' && !$birth_date) {
            $this->api->respond_error('Provide a valid date of birth.', 400);
            return;
        }
        if ($birth_date && $birth_date > date('Y-m-d')) {
            $this->api->respond_error('Date of birth cannot be in the future.', 400);
            return;
        }
        $profile_values['birth_date'] = $birth_date;
        $profile_values['updated_at'] = date('Y-m-d H:i:s');

        $this->db->transaction();
        try {
            $this->db->table('users')
                ->where('id', (int) $user['id'])
                ->update([
                    'first_name' => $first_name,
                    'middle_name' => $middle_name !== '' ? $middle_name : null,
                    'last_name' => $last_name,
                    'username' => $username,
                    'email' => $email,
                    'phone' => $phone !== '' ? $phone : null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            $this->db->table('teacher_profiles')
                ->where('user_id', (int) $user['id'])
                ->update($profile_values);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        $this->api->respond([
            'status' => true,
            'message' => 'Profile updated.',
        ]);
    }

    private function text(array $input, $field, $maximum)
    {
        $value = $input[$field] ?? '';
        if (!is_string($value)) return '';
        $value = trim($value);
        return mb_strlen($value) <= $maximum ? $value : '';
    }

    private function has_invalid_text(array $input, array $limits)
    {
        foreach ($limits as $field => $maximum) {
            if (array_key_exists($field, $input)
                && (!is_string($input[$field]) || mb_strlen(trim($input[$field])) > $maximum)) {
                return true;
            }
        }
        return false;
    }

    private function date_or_null($value)
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value)) return false;
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        $errors = DateTime::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))
            || $date->format('Y-m-d') !== $value) {
            return false;
        }
        return $value;
    }

    private function authenticated_teacher()
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
        if ($user['role'] !== 'teacher') {
            $this->api->respond_error('You are not authorized to manage this profile.', 403);
            return null;
        }
        $teacher_access = $this->call->library('TeacherAccess', $this->db);
        if (!$teacher_access->is_active((int) $user['id'])) {
            $this->api->respond_error('Teacher account approval and invitation acceptance are required.', 403);
            return null;
        }
        return $user;
    }
}
