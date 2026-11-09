<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ParentProfileApiController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->library('api');
        $this->call->model('UsersModel');
    }

    public function update()
    {
        $this->api->require_method('PATCH');
        $parent = $this->authenticated_parent();
        if (!$parent) return;

        $input = $this->api->body(false);
        if (!is_array($input) || !$input) {
            $this->api->respond_error('Provide parent profile fields to update.', 400);
            return;
        }

        $account_fields = ['first_name', 'middle_name', 'last_name', 'email', 'phone'];
        $profile_fields = [
            'birth_date', 'gender', 'relationship', 'address', 'street', 'barangay',
            'municipality', 'province', 'postal_code', 'contact_email',
            'emergency_name', 'emergency_relationship', 'emergency_phone',
            'household_income', 'preferred_language', 'referral_source',
        ];
        if (array_diff(array_keys($input), array_merge($account_fields, $profile_fields))) {
            $this->api->respond_error('The request contains fields that cannot be changed.', 400);
            return;
        }

        $account_updates = [];
        foreach ([
            'first_name' => 100,
            'middle_name' => 100,
            'last_name' => 100,
            'phone' => 30,
        ] as $field => $limit) {
            if (!array_key_exists($field, $input)) continue;
            $value = $this->text($input, $field, $limit);
            if ($value === false) return;
            if (in_array($field, ['first_name', 'last_name'], true) && $value === '') {
                $this->api->respond_error(ucfirst(str_replace('_', ' ', $field)) . ' cannot be empty.', 400);
                return;
            }
            if ($field === 'phone' && $value !== '' && !preg_match('/^[+0-9() -]{7,30}$/', $value)) {
                $this->api->respond_error('Enter a valid contact number.', 400);
                return;
            }
            $account_updates[$field] = $value !== '' ? $value : null;
        }
        if (array_key_exists('email', $input)) {
            $email = $this->text($input, 'email', 255);
            if ($email === false) return;
            $email = strtolower($email);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->api->respond_error('Provide a valid account email address.', 400);
                return;
            }
            if ($this->identity_exists($email, (int) $parent['id'])) {
                $this->api->respond_error('That email address is already registered.', 409);
                return;
            }
            $account_updates['email'] = $email;
        }

        $profile_updates = [];
        $lengths = [
            'gender' => 40, 'relationship' => 80, 'address' => 255, 'street' => 160,
            'barangay' => 120, 'municipality' => 120, 'province' => 120,
            'postal_code' => 12, 'contact_email' => 255, 'emergency_name' => 160,
            'emergency_relationship' => 80, 'emergency_phone' => 30,
            'household_income' => 80, 'preferred_language' => 80, 'referral_source' => 120,
        ];
        foreach ($lengths as $field => $limit) {
            if (!array_key_exists($field, $input)) continue;
            $value = $this->text($input, $field, $limit);
            if ($value === false) return;
            $profile_updates[$field] = $value !== '' ? $value : null;
        }
        foreach (['email' => 'account email', 'contact_email' => 'contact email'] as $field => $label) {
            $value = $field === 'email'
                ? ($account_updates['email'] ?? $parent['email'])
                : ($profile_updates['contact_email'] ?? null);
            if ($value !== null && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $this->api->respond_error('Provide a valid ' . $label . ' address.', 400);
                return;
            }
        }
        if (isset($profile_updates['gender'])
            && !in_array($profile_updates['gender'], ['Male', 'Female', 'Prefer not to say'], true)) {
            $this->api->respond_error('Choose a valid gender.', 400);
            return;
        }
        if (array_key_exists('birth_date', $input)) {
            $birth_date = $this->text($input, 'birth_date', 10);
            if ($birth_date === false) return;
            if ($birth_date === '') {
                $profile_updates['birth_date'] = null;
            } else {
                $parsed = DateTime::createFromFormat('!Y-m-d', $birth_date);
                if (!$parsed || $parsed->format('Y-m-d') !== $birth_date || $birth_date > date('Y-m-d')) {
                    $this->api->respond_error('Enter a valid birth date that is not in the future.', 400);
                    return;
                }
                $profile_updates['birth_date'] = $birth_date;
            }
        }
        if ($profile_updates['emergency_phone'] ?? null) {
            if (!preg_match('/^[+0-9() -]{7,30}$/', $profile_updates['emergency_phone'])) {
                $this->api->respond_error('Enter a valid emergency contact number.', 400);
                return;
            }
        }

        if (!$account_updates && !$profile_updates) {
            $this->api->respond_error('Provide at least one supported field to update.', 400);
            return;
        }
        $profile = $this->db->table('parent_profiles')
            ->select('user_id')
            ->where('user_id', (int) $parent['id'])
            ->get();
        if (!$profile) {
            $this->api->respond_error('Parent profile was not found.', 404);
            return;
        }

        $now = date('Y-m-d H:i:s');
        try {
            $this->db->transaction();
            if ($account_updates) {
                $account_updates['updated_at'] = $now;
                $this->db->table('users')
                    ->where('id', (int) $parent['id'])
                    ->where('role', 'parent')
                    ->update($account_updates);
            }
            if ($profile_updates) {
                $profile_updates['updated_at'] = $now;
                $this->db->table('parent_profiles')
                    ->where('user_id', (int) $parent['id'])
                    ->update($profile_updates);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            if ((string) $error->getCode() === '23000') {
                $this->api->respond_error('That email address is already registered.', 409);
                return;
            }
            throw $error;
        }

        $this->api->respond([
            'status' => true,
            'message' => 'Parent profile updated.',
        ]);
    }

    private function authenticated_parent()
    {
        $payload = $this->api->require_jwt();
        $user_id = filter_var($payload['sub'] ?? null, FILTER_VALIDATE_INT);
        if (!$user_id) {
            $this->api->respond_error('Unauthorized.', 401);
            return null;
        }
        $user = $this->UsersModel->find_by_id($user_id);
        if (!$user || (int) $user['is_active'] !== 1 || $user['role'] !== 'parent') {
            $this->api->respond_error('Parent account is unavailable.', 401);
            return null;
        }
        return $user;
    }

    private function identity_exists($email, $except_id)
    {
        return (bool) $this->db->raw(
            'SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1',
            [$email, (int) $except_id]
        )->fetch(PDO::FETCH_ASSOC);
    }

    private function text(array $input, $field, $max_length)
    {
        if (!is_string($input[$field] ?? null)) {
            $this->api->respond_error($field . ' must be a text value.', 400);
            return false;
        }
        $value = trim($input[$field]);
        if (mb_strlen($value) > $max_length) {
            $this->api->respond_error($field . ' must be ' . $max_length . ' characters or fewer.', 400);
            return false;
        }
        return $value;
    }
}
