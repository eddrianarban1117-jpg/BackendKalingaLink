<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminUsersApiController extends Controller
{
    private const MANAGED_ROLES = ['parent', 'teacher'];
    private $cdc_status;

    public function __construct()
    {
        parent::__construct();
        $this->call->library('api');
        $this->cdc_status = $this->call->library('CdcStatus', $this->db);
        $this->call->model('UsersModel');
    }

    public function index()
    {
        $this->api->require_method('GET');
        if (!$this->authenticated_admin()) return;

        foreach (['page', 'per_page', 'search', 'role'] as $parameter) {
            if (isset($_GET[$parameter]) && !is_scalar($_GET[$parameter])) {
                $this->api->respond_error("{$parameter} must be a single value.", 400);
                return;
            }
        }
        $page = min(1000000, max(1, (int) ($_GET['page'] ?? 1)));
        $per_page = min(100, max(1, (int) ($_GET['per_page'] ?? 25)));
        $search = trim((string) ($_GET['search'] ?? ''));
        $role = trim((string) ($_GET['role'] ?? ''));
        if (strlen($search) > 100) {
            $this->api->respond_error('Search text must be 100 characters or fewer.', 400);
            return;
        }
        if ($role !== '' && !in_array($role, self::MANAGED_ROLES, true)) {
            $this->api->respond_error('Role filter must be parent or teacher.', 400);
            return;
        }

        $where = "u.role IN ('parent', 'teacher')";
        $params = [];
        if ($role !== '') {
            $where .= ' AND u.role = ?';
            $params[] = $role;
        }
        if ($search !== '') {
            $where .= " AND (u.first_name LIKE ? OR u.middle_name LIKE ?
                OR u.last_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $count = $this->db->raw(
            "SELECT COUNT(*) AS total FROM users u WHERE {$where}",
            $params
        )->fetch(PDO::FETCH_ASSOC);
        $offset = ($page - 1) * $per_page;
        $rows = $this->db->raw(
            "SELECT u.id, u.username, u.email, u.role, u.is_active,
                    u.first_name, u.middle_name, u.last_name, u.phone,
                    u.created_at, tp.cdc_id, c.name AS center_name,
                    tp.position, tp.employment_status
             FROM users u
             LEFT JOIN teacher_profiles tp ON tp.user_id = u.id
             LEFT JOIN cdcs c ON c.id = tp.cdc_id
             WHERE {$where}
             ORDER BY u.created_at DESC, u.id DESC
             LIMIT {$per_page} OFFSET {$offset}",
            $params
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->api->respond([
            'status' => true,
            'data' => array_map(function ($user) {
                return $this->format_user($user);
            }, $rows),
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => (int) ($count['total'] ?? 0),
                'total_pages' => (int) ceil((int) ($count['total'] ?? 0) / $per_page),
            ],
        ]);
    }

    private function record_teacher_pending($teacher_id, array $recipient_ids, $first_name, $last_name)
    {
        $this->db->table('notifications')->insert([
            'actor_user_id' => (int) $teacher_id,
            'title' => 'Pending Review',
            'message' => trim($first_name . ' ' . $last_name) . ' is awaiting MSWDO Admin verification.',
            'audience' => 'single',
            'related_type' => 'teacher_verification',
            'related_id' => (int) $teacher_id,
            'related_module' => 'teacher_management',
            'notification_type' => 'System Update',
            'action_url' => '/admin/teachers?teacherId=' . (int) $teacher_id,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $notification_id = (int) $this->db->last_id();
        foreach (array_unique(array_map('intval', $recipient_ids)) as $recipient_id) {
            $this->db->table('notification_recipients')->insert([
                'notification_id' => $notification_id,
                'user_id' => $recipient_id,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function show($id)
    {
        $this->api->require_method('GET');
        if (!$this->authenticated_admin()) return;

        $user = $this->find_managed_user($id);
        if (!$user) {
            $this->api->respond_error('User account was not found.', 404);
            return;
        }
        $this->api->respond(['status' => true, 'data' => $this->format_user($user, true)]);
    }

    public function create()
    {
        $this->api->require_method('POST');
        if (!$this->authenticated_admin()) return;

        $input = $this->api->body(false);
        $role = trim((string) ($input['role'] ?? ''));
        if (!in_array($role, self::MANAGED_ROLES, true)) {
            $this->api->respond_error('Only parent and teacher accounts can be created here.', 400);
            return;
        }
        $allowed_fields = [
            'role', 'first_name', 'middle_name', 'last_name', 'username',
            'email', 'phone', 'password', 'password_confirmation',
        ];
        if ($role === 'teacher') {
            $allowed_fields = array_merge($allowed_fields, ['cdc_id', 'position']);
        }
        if (array_diff(array_keys($input), $allowed_fields)) {
            $this->api->respond_error('The request includes fields that cannot be set during account creation.', 400);
            return;
        }

        $first_name = $this->text($input, 'first_name', 100);
        $middle_name = $this->text($input, 'middle_name', 100);
        $last_name = $this->text($input, 'last_name', 100);
        $username = strtolower($this->text($input, 'username', 100));
        $email = strtolower($this->text($input, 'email', 255));
        $phone = $this->text($input, 'phone', 30);
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        $confirmation = is_string($input['password_confirmation'] ?? null)
            ? $input['password_confirmation']
            : '';

        if ($first_name === '' || $last_name === '') {
            $this->api->respond_error('First and last name are required.', 400);
            return;
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
            $this->api->respond_error('Username must be 3–100 letters, numbers, periods, underscores, or hyphens.', 400);
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->api->respond_error('A valid email address is required.', 400);
            return;
        }
        if (!$this->valid_password($password) || $password !== $confirmation) {
            $this->api->respond_error('Password must be at least 12 characters and include letters and numbers; confirmation must match.', 400);
            return;
        }
        if ($this->identity_exists($email, $username)) {
            $this->api->respond_error('That email or username is already registered.', 409);
            return;
        }

        $cdc_id = null;
        if ($role === 'teacher') {
            $cdc_id = filter_var($input['cdc_id'] ?? null, FILTER_VALIDATE_INT);
            $center = $cdc_id
                ? $this->db->table('cdcs')->where('id', $cdc_id)->get()
                : false;
            if (!$center) {
                $this->api->respond_error('Select a registered Child Development Center for the teacher.', 400);
                return;
            }
        }
        $position = $this->text($input, 'position', 120);

        $now = date('Y-m-d H:i:s');
        try {
            $this->db->transaction();
            $this->db->table('users')->insert([
                'username' => $username,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
                'is_active' => $role === 'teacher' ? 0 : 1,
                'first_name' => $first_name,
                'middle_name' => $middle_name ?: null,
                'last_name' => $last_name,
                'phone' => $phone ?: null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $user_id = (int) $this->db->last_id();
            if ($user_id < 1) {
                throw new RuntimeException('The user account could not be created.');
            }

            if ($role === 'teacher') {
                $this->db->table('teacher_profiles')->insert([
                    'user_id' => $user_id,
                    'cdc_id' => null,
                    'requested_cdc_id' => $cdc_id,
                    'position' => $position ?: null,
                    'employment_status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->db->table('teacher_account_verification')->insert([
                    'teacher_user_id' => $user_id,
                    'status' => 'pending_review',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $admin_ids = $this->db->raw(
                    'SELECT id FROM users WHERE role = \'admin\' AND is_active = 1'
                )->fetchAll(PDO::FETCH_COLUMN);
                $this->record_teacher_pending($user_id, array_merge([$user_id], $admin_ids), $first_name, $last_name);
            } else {
                $this->db->table('parent_profiles')->insert([
                    'user_id' => $user_id,
                    'contact_email' => $email,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $profile_table = $role === 'teacher' ? 'teacher_profiles' : 'parent_profiles';
            $profile = $this->db->table($profile_table)
                ->select('user_id')
                ->where('user_id', $user_id)
                ->get();
            if (!$profile) {
                throw new RuntimeException('The role-specific account profile could not be saved.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            if ((string) $error->getCode() === '23000') {
                $this->api->respond_error('That email or username is already registered.', 409);
                return;
            }
            throw $error;
        }

        $created = $this->find_managed_user($user_id);
        if (!$created) {
            $this->api->respond_error('The account was created but could not be loaded.', 500);
            return;
        }
        $this->api->respond([
            'status' => true,
            'message' => 'User account created.',
            'data' => $this->format_user($created, true),
        ], 201);
    }

    public function update($id)
    {
        $this->api->require_method('PUT');
        if (!$this->authenticated_admin()) return;

        $user = $this->find_managed_user($id);
        if (!$user) {
            $this->api->respond_error('User account was not found.', 404);
            return;
        }
        $input = $this->api->body(false);
        $account_fields = ['first_name', 'middle_name', 'last_name', 'username', 'email', 'phone'];
        $profile_fields = $user['role'] === 'teacher'
            ? ['cdc_id', 'position']
            : [
                'birth_date', 'gender', 'relationship', 'address', 'street', 'barangay',
                'municipality', 'province', 'postal_code', 'contact_email',
                'emergency_name', 'emergency_relationship', 'emergency_phone',
                'household_income', 'preferred_language', 'referral_source',
            ];
        $allowed_fields = array_merge($account_fields, $profile_fields, [
            'password', 'password_confirmation',
        ]);
        if (array_diff(array_keys($input), $allowed_fields)) {
            $this->api->respond_error('The request includes fields that cannot be changed through this endpoint.', 400);
            return;
        }
        if (!$input) {
            $this->api->respond_error('Provide at least one account or profile field to update.', 400);
            return;
        }

        $values = [];
        foreach (['first_name' => 100, 'middle_name' => 100, 'last_name' => 100, 'phone' => 30] as $field => $max) {
            if (array_key_exists($field, $input)) {
                $text = $this->text($input, $field, $max);
                if (in_array($field, ['first_name', 'last_name'], true) && $text === '') {
                    $this->api->respond_error(ucfirst(str_replace('_', ' ', $field)) . ' cannot be empty.', 400);
                    return;
                }
                $values[$field] = $text !== '' ? $text : null;
            }
        }
        if (array_key_exists('username', $input)) {
            $values['username'] = strtolower($this->text($input, 'username', 100));
            if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $values['username'])) {
                $this->api->respond_error('Username must be 3–100 letters, numbers, periods, underscores, or hyphens.', 400);
                return;
            }
        }
        if (array_key_exists('email', $input)) {
            $values['email'] = strtolower($this->text($input, 'email', 255));
            if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
                $this->api->respond_error('A valid email address is required.', 400);
                return;
            }
        }
        if (
            (isset($values['username']) || isset($values['email']))
            && $this->identity_exists($values['email'] ?? $user['email'], $values['username'] ?? $user['username'], (int) $user['id'])
        ) {
            $this->api->respond_error('That email or username is already registered.', 409);
            return;
        }
        if (array_key_exists('password', $input) || array_key_exists('password_confirmation', $input)) {
            $password = is_string($input['password'] ?? null) ? $input['password'] : '';
            $confirmation = is_string($input['password_confirmation'] ?? null)
                ? $input['password_confirmation']
                : '';
            if (!$this->valid_password($password) || $password !== $confirmation) {
                $this->api->respond_error('Password must be at least 12 characters and include letters and numbers; confirmation must match.', 400);
                return;
            }
            $values['password'] = password_hash($password, PASSWORD_DEFAULT);
        }

        $profile_values = $this->validated_profile_values($input, $user['role']);
        if ($profile_values === false) return;
        $now = date('Y-m-d H:i:s');

        try {
            $this->db->transaction();
            if ($values) {
                $values['updated_at'] = $now;
                $this->db->table('users')->where('id', (int) $user['id'])->update($values);
            }
            if ($profile_values) {
                $profile_values['updated_at'] = $now;
                $table = $user['role'] === 'teacher' ? 'teacher_profiles' : 'parent_profiles';
                $this->db->table($table)->where('user_id', (int) $user['id'])->update($profile_values);
            }
            if (isset($values['password'])) {
                $this->db->table(config_item('refresh_token_table'))
                    ->where('user_id', (int) $user['id'])
                    ->delete();
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            if ((string) $error->getCode() === '23000') {
                $this->api->respond_error('That email or username is already registered.', 409);
                return;
            }
            throw $error;
        }

        $updated = $this->find_managed_user($id);
        if (!$updated) {
            $this->api->respond_error('The account was updated but could not be loaded.', 500);
            return;
        }
        $this->api->respond([
            'status' => true,
            'message' => 'User account updated.',
            'data' => $this->format_user($updated, true),
        ]);
    }

    public function update_status($id)
    {
        $this->api->require_method('PATCH');
        $admin = $this->authenticated_admin();
        if (!$admin) return;

        $user = $this->find_managed_user($id);
        if (!$user) {
            $this->api->respond_error('User account was not found.', 404);
            return;
        }
        $input = $this->api->body(false);
        if (array_diff(array_keys($input), ['is_active'])) {
            $this->api->respond_error('Only is_active can be changed through this endpoint.', 400);
            return;
        }
        $active_value = $input['is_active'] ?? null;
        if (!in_array($active_value, [true, false, 1, 0, '1', '0', 'true', 'false'], true)) {
            $this->api->respond_error('is_active must be true or false.', 400);
            return;
        }
        $active = filter_var($active_value, FILTER_VALIDATE_BOOLEAN);
        if ($active && $user['role'] === 'teacher') {
            $approved_teacher = $this->db->raw(
                'SELECT u.id FROM users u
                 JOIN teacher_profiles tp ON tp.user_id = u.id
                 JOIN teacher_account_verification verification
                   ON verification.teacher_user_id = u.id AND verification.status = \'active\'
                 JOIN notifications review ON review.id = (
                     SELECT latest.id FROM notifications latest
                     WHERE latest.related_type = \'teacher_verification\'
                       AND latest.related_id = u.id ORDER BY latest.id DESC LIMIT 1
                 )
                 WHERE u.id = ? AND tp.employment_status = \'active\'
                   AND review.title = \'Approved\'',
                [(int) $user['id']]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$approved_teacher) {
                $this->api->respond_error('Only an activated teacher with active employment can be reactivated.', 409);
                return;
            }
        }

        $this->db->transaction();
        try {
            $center_id = null;
            if (!$active && $user['role'] === 'teacher') {
                $center_id = $this->cdc_status->unassign_teacher((int) $user['id']);
                if ($center_id !== null) {
                    $applications = $this->db->raw(
                        'SELECT a.id, a.parent_user_id
                         FROM enrollment_applications a
                         JOIN children c ON c.id = a.child_id
                         WHERE a.assigned_teacher_user_id = ?
                           AND a.status IN (\'pending\', \'under_review\')
                           AND c.enrollment_status <> \'enrolled\'',
                        [(int) $user['id']]
                    )->fetchAll(PDO::FETCH_ASSOC);
                    $this->db->raw(
                        'UPDATE enrollment_applications a
                         JOIN children c ON c.id = a.child_id
                         SET a.assigned_teacher_user_id = NULL
                         WHERE a.assigned_teacher_user_id = ?
                           AND a.status IN (\'pending\', \'under_review\')
                           AND c.enrollment_status <> \'enrolled\'',
                        [(int) $user['id']]
                    );
                    foreach ($applications as $application) {
                        $this->notify_user(
                            (int) $admin['id'],
                            (int) $application['parent_user_id'],
                            'Teacher account deactivated',
                            'The assigned teacher for your open enrollment application is no longer active. MSWDO Admin will review the application.',
                            'enrollment',
                            (int) $application['id'],
                            '/parent/application-status'
                        );
                    }
                    $this->notify_admins(
                        (int) $admin['id'],
                        'CDC teacher deactivated',
                        'A teacher assigned to a CDC was deactivated. The center is now inactive until an approved, active teacher is assigned.',
                        $center_id
                    );
                }
            }
            $this->db->table('users')->where('id', (int) $user['id'])->update([
                'is_active' => $active ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            if ($center_id !== null) {
                $this->cdc_status->sync($center_id);
            } elseif ($active && $user['role'] === 'teacher') {
                $this->cdc_status->sync_teacher_center((int) $user['id']);
            }
            if (!$active) {
                $this->db->table(config_item('refresh_token_table'))
                    ->where('user_id', (int) $user['id'])
                    ->delete();
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        $updated = $this->find_managed_user($id);
        if (!$updated) {
            $this->api->respond_error('The account status was updated but could not be loaded.', 500);
            return;
        }
        $this->api->respond([
            'status' => true,
            'message' => $active ? 'User account activated.' : 'User account deactivated.',
            'data' => $this->format_user($updated, true),
        ]);
    }

    public function archive($id)
    {
        $this->api->require_method('DELETE');
        $admin = $this->authenticated_admin();
        if (!$admin) return;

        $user = $this->find_managed_user($id);
        if (!$user) {
            $this->api->respond_error('User account was not found.', 404);
            return;
        }
        $this->db->transaction();
        try {
            $center_id = null;
            if ($user['role'] === 'teacher') {
                $center_id = $this->cdc_status->unassign_teacher((int) $user['id']);
                $this->db->raw(
                    'UPDATE enrollment_applications a
                     JOIN children c ON c.id = a.child_id
                     SET a.assigned_teacher_user_id = NULL
                     WHERE a.assigned_teacher_user_id = ?
                       AND a.status IN (\'pending\', \'under_review\')
                       AND c.enrollment_status <> \'enrolled\'',
                    [(int) $user['id']]
                );
            }
            $this->db->table('users')->where('id', (int) $user['id'])->update([
                'is_active' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            if ($center_id !== null) {
                $this->cdc_status->sync($center_id);
            }
            $this->db->table(config_item('refresh_token_table'))
                ->where('user_id', (int) $user['id'])
                ->delete();
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        $this->api->respond([
            'status' => true,
            'message' => 'User account deactivated.',
            'data' => ['id' => (int) $user['id'], 'is_active' => 0],
        ]);
    }

    private function authenticated_admin()
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
        if ($user['role'] !== 'admin') {
            $this->api->respond_error('You are not authorized to manage user accounts.', 403);
            return null;
        }
        return $user;
    }

    private function find_managed_user($id)
    {
        $user_id = filter_var($id, FILTER_VALIDATE_INT);
        if (!$user_id || $user_id < 1) return false;
        return $this->db->raw(
            "SELECT u.id, u.username, u.email, u.role, u.is_active,
                    u.first_name, u.middle_name, u.last_name, u.phone,
                    u.created_at, tp.cdc_id, c.name AS center_name,
                    tp.position, tp.employment_status,
                    pp.birth_date, pp.gender, pp.relationship, pp.address,
                    pp.street, pp.barangay, pp.municipality, pp.province,
                    pp.postal_code, pp.contact_email, pp.emergency_name,
                    pp.emergency_relationship, pp.emergency_phone,
                    pp.household_income, pp.preferred_language, pp.referral_source
             FROM users u
             LEFT JOIN teacher_profiles tp ON tp.user_id = u.id
             LEFT JOIN cdcs c ON c.id = tp.cdc_id
             LEFT JOIN parent_profiles pp ON pp.user_id = u.id
             WHERE u.id = ? AND u.role IN ('parent', 'teacher')
             LIMIT 1",
            [(int) $user_id]
        )->fetch(PDO::FETCH_ASSOC);
    }

    private function notify_admins($actor_id, $title, $message, $related_id)
    {
        $admins = $this->db->raw(
            'SELECT id FROM users WHERE role = \'admin\' AND is_active = 1 AND id <> ?',
            [(int) $actor_id]
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $recipient_id) {
            $this->notify_user(
                (int) $actor_id,
                (int) $recipient_id,
                $title,
                $message,
                'cdc_assignment',
                (int) $related_id,
                '/admin/centers'
            );
        }
    }

    private function notify_user($actor_id, $recipient_id, $title, $message, $related_type, $related_id, $action_url)
    {
        $this->db->table('notifications')->insert([
            'actor_user_id' => (int) $actor_id,
            'title' => $title,
            'message' => $message,
            'audience' => 'single',
            'related_type' => $related_type,
            'related_id' => (int) $related_id,
            'related_module' => 'user_management',
            'notification_type' => 'System Update',
            'action_url' => $action_url,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $notification_id = (int) $this->db->last_id();
        $this->db->table('notification_recipients')->insert([
            'notification_id' => $notification_id,
            'user_id' => (int) $recipient_id,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function identity_exists($email, $username, $except_id = null)
    {
        $sql = 'SELECT id FROM users WHERE (email = ? OR username = ?)';
        $params = [$email, $username];
        if ($except_id !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $except_id;
        }
        $sql .= ' LIMIT 1';
        return (bool) $this->db->raw($sql, $params)->fetch(PDO::FETCH_ASSOC);
    }

    private function validated_profile_values(array $input, $role)
    {
        if ($role === 'teacher') {
            $values = [];
            if (array_key_exists('cdc_id', $input)) {
                $cdc_id = filter_var($input['cdc_id'], FILTER_VALIDATE_INT);
                $center = $cdc_id
                    ? $this->db->table('cdcs')->where('id', $cdc_id)->get()
                    : false;
                if (!$center) {
                    $this->api->respond_error('Select a registered Child Development Center.', 400);
                    return false;
                }
                $values['requested_cdc_id'] = $cdc_id;
            }
            if (array_key_exists('position', $input)) {
                $values['position'] = $this->text($input, 'position', 120) ?: null;
            }
            return $values;
        }

        $lengths = [
            'gender' => 40, 'relationship' => 80, 'address' => 255, 'street' => 160,
            'barangay' => 120, 'municipality' => 120, 'province' => 120, 'postal_code' => 12,
            'contact_email' => 255, 'emergency_name' => 160, 'emergency_relationship' => 80,
            'emergency_phone' => 30, 'household_income' => 80, 'preferred_language' => 80,
            'referral_source' => 120,
        ];
        $values = [];
        foreach ($lengths as $field => $max) {
            if (array_key_exists($field, $input)) {
                $values[$field] = $this->text($input, $field, $max) ?: null;
            }
        }
        if (array_key_exists('birth_date', $input)) {
            if (!is_string($input['birth_date'])) {
                $this->api->respond_error('birth_date must be a date string.', 400);
                return false;
            }
            $birth_date = trim($input['birth_date']);
            if ($birth_date === '') {
                $values['birth_date'] = null;
            } else {
                $parsed_date = DateTime::createFromFormat('!Y-m-d', $birth_date);
                if (!$parsed_date || $parsed_date->format('Y-m-d') !== $birth_date || $birth_date > date('Y-m-d')) {
                    $this->api->respond_error('Provide a valid birth_date that is not in the future.', 400);
                    return false;
                }
                $values['birth_date'] = $birth_date;
            }
        }
        if (isset($values['contact_email']) && $values['contact_email'] !== null
            && !filter_var($values['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $this->api->respond_error('Provide a valid contact email address.', 400);
            return false;
        }
        return $values;
    }

    private function format_user(array $user, $include_profile = false)
    {
        $formatted = [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
            'is_active' => (int) $user['is_active'] === 1,
            'first_name' => $user['first_name'] ?? '',
            'middle_name' => $user['middle_name'] ?? '',
            'last_name' => $user['last_name'] ?? '',
            'phone' => $user['phone'] ?? '',
            'created_at' => $user['created_at'] ?? null,
            'center' => $user['center_name'] ?? null,
            'cdc_id' => isset($user['cdc_id']) ? (int) $user['cdc_id'] : null,
            'position' => $user['position'] ?? null,
            'employment_status' => $user['employment_status'] ?? null,
        ];
        if ($include_profile && $user['role'] === 'parent') {
            $formatted['parent_profile'] = [
                'birth_date' => $user['birth_date'] ?? null,
                'gender' => $user['gender'] ?? null,
                'relationship' => $user['relationship'] ?? null,
                'address' => $user['address'] ?? null,
                'street' => $user['street'] ?? null,
                'barangay' => $user['barangay'] ?? null,
                'municipality' => $user['municipality'] ?? null,
                'province' => $user['province'] ?? null,
                'postal_code' => $user['postal_code'] ?? null,
                'contact_email' => $user['contact_email'] ?? null,
                'emergency_name' => $user['emergency_name'] ?? null,
                'emergency_relationship' => $user['emergency_relationship'] ?? null,
                'emergency_phone' => $user['emergency_phone'] ?? null,
                'household_income' => $user['household_income'] ?? null,
                'preferred_language' => $user['preferred_language'] ?? null,
                'referral_source' => $user['referral_source'] ?? null,
            ];
        }
        return $formatted;
    }

    private function valid_password($password)
    {
        return is_string($password)
            && strlen($password) >= 12
            && preg_match('/[A-Za-z]/', $password)
            && preg_match('/[0-9]/', $password);
    }

    private function text(array $input, $field, $max_length)
    {
        $value = $input[$field] ?? '';
        if (!is_string($value) && !is_numeric($value)) {
            $this->api->respond_error("{$field} must be text.", 400);
            return '';
        }
        $value = trim((string) $value);
        if (strlen($value) > $max_length) {
            $this->api->respond_error("{$field} must be {$max_length} characters or fewer.", 400);
            return '';
        }
        return $value;
    }
}
