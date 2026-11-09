<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class NotificationsApiController extends Controller
{
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
        $user = $this->authenticated_user();
        if (!$user) return;

        [$event_clause, $event_params] = $this->event_visibility_clause($user);
        $rows = $this->db->raw(
            'SELECT n.id, n.title, n.message, n.notification_type,
                    n.related_module, n.related_type, n.related_id, n.action_url,
                    n.created_at, r.read_at,
                    COALESCE(NULLIF(TRIM(CONCAT_WS(\' \', actor.first_name, actor.last_name)), \'\'), \'KalingaLink\') AS sender
             FROM notification_recipients r
             JOIN notifications n ON n.id = r.notification_id
             LEFT JOIN users actor ON actor.id = n.actor_user_id
             LEFT JOIN events e ON n.related_type = \'event\' AND e.id = n.related_id
             WHERE r.user_id = ?
               AND (n.related_type IS NULL OR n.related_type <> \'event\' OR (' . $event_clause . '))
             ORDER BY n.created_at DESC, n.id DESC
             LIMIT 200',
            array_merge([(int) $user['id']], $event_params)
        )->fetchAll(PDO::FETCH_ASSOC);

        $notifications = [];
        foreach ($rows as $row) {
            $notifications[] = $this->present_notification($row, 'notification');
        }

        usort($notifications, static function ($left, $right) {
            return strcmp($right['created_at'], $left['created_at'])
                ?: ($right['id'] <=> $left['id']);
        });

        $this->api->respond([
            'status' => true,
            'data' => [
                'notifications' => array_slice($notifications, 0, 200),
                'unread_count' => $this->unread_count($user),
            ],
        ]);
    }

    public function recipient_options()
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        if (!$user) return;
        if (!in_array($user['role'], ['admin', 'teacher'], true)) {
            $this->api->respond_error('You are not authorized to send notifications.', 403);
            return;
        }

        $candidates = $this->eligible_recipient_candidates($user);
        $allowed_center_ids = $this->allowed_center_ids($user);
        $centers = [];
        if ($allowed_center_ids) {
            $placeholders = implode(', ', array_fill(0, count($allowed_center_ids), '?'));
            $centers = $this->db->raw(
                'SELECT id, name FROM cdcs
                 WHERE status = \'active\' AND id IN (' . $placeholders . ')
                 ORDER BY name',
                $allowed_center_ids
            )->fetchAll(PDO::FETCH_ASSOC);
        }

        $this->api->respond([
            'status' => true,
            'data' => [
                'roles' => $user['role'] === 'teacher'
                    ? [['value' => 'parent', 'label' => 'Parents']]
                    : [
                        ['value' => 'parent', 'label' => 'Parents'],
                        ['value' => 'teacher', 'label' => 'Teachers'],
                        ['value' => 'admin', 'label' => 'Administrators'],
                    ],
                'centers' => array_map(static function ($center) {
                    return ['id' => (int) $center['id'], 'name' => $center['name']];
                }, $centers),
                'users' => array_values($candidates),
                'all_eligible_label' => $user['role'] === 'teacher'
                    ? 'All eligible parents in my assigned CDC'
                    : 'All eligible users',
            ],
        ]);
    }

    public function preview_recipients()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        if (!$user) return;
        if (!in_array($user['role'], ['admin', 'teacher'], true)) {
            $this->api->respond_error('You are not authorized to send notifications.', 403);
            return;
        }

        $input = $this->api->body(false);
        if (!is_array($input)) {
            $this->api->respond_error('Provide recipient criteria.', 400);
            return;
        }
        $recipients = $this->resolve_recipients($user, $input);
        if ($recipients === false) return;

        $this->api->respond([
            'status' => true,
            'data' => [
                'count' => count($recipients),
                'recipients' => array_values($recipients),
                'roles' => array_values(array_unique(array_column($recipients, 'role'))),
                'centers' => array_values(array_unique(array_merge(
                    [],
                    ...array_map(static function ($recipient) {
                        return $recipient['cdc_names'];
                    }, $recipients)
                ))),
            ],
        ]);
    }

    public function create()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        if (!$user) return;
        if (!in_array($user['role'], ['admin', 'teacher'], true)) {
            $this->api->respond_error('You are not authorized to send notifications.', 403);
            return;
        }

        $input = $this->api->body(false);
        if (!is_array($input)) {
            $this->api->respond_error('Provide notification details.', 400);
            return;
        }
        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
        $message = is_string($input['message'] ?? null) ? trim($input['message']) : '';
        $type = is_string($input['notification_type'] ?? null)
            ? trim($input['notification_type'])
            : 'General';
        $module = is_string($input['related_module'] ?? null)
            ? trim($input['related_module'])
            : '';
        $request_key = is_string($input['request_key'] ?? null)
            ? trim($input['request_key'])
            : '';
        if ($title === '' || mb_strlen($title) > 180 || $message === ''
            || mb_strlen($message) > 10000 || mb_strlen($type) > 80 || mb_strlen($module) > 80
            || $request_key === '' || mb_strlen($request_key) > 128) {
            $this->api->respond_error('Provide a title, message, category, and valid request key.', 400);
            return;
        }

        $recipients = $this->resolve_recipients($user, $input);
        if ($recipients === false) return;
        $now = date('Y-m-d H:i:s');
        $stored_request_key = hash('sha256', (int) $user['id'] . ':' . $request_key);
        $criteria = [
            'all_eligible' => ($input['all_eligible'] ?? false) === true,
            'roles' => $input['roles'] ?? [],
            'cdc_ids' => $input['cdc_ids'] ?? [],
            'user_ids' => $input['user_ids'] ?? [],
        ];

        $this->db->raw('START TRANSACTION');
        try {
            $existing = $this->db->raw(
                'SELECT notification_id AS id FROM notification_requests
                 WHERE actor_user_id = ? AND request_key = ? LIMIT 1',
                [(int) $user['id'], $stored_request_key]
            )->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $this->db->raw('COMMIT');
                $this->api->respond([
                    'status' => true,
                    'message' => 'Notification was already published.',
                    'data' => ['id' => (int) $existing['id'], 'duplicate' => true],
                ]);
                return;
            }

            $this->db->table('notifications')->insert([
                'actor_user_id' => (int) $user['id'],
                'title' => $title,
                'message' => $message,
                'audience' => 'single',
                'related_type' => $module !== '' ? $module : null,
                'related_module' => $module !== '' ? $module : null,
                'notification_type' => $type !== '' ? $type : 'General',
                'action_url' => null,
                'recipient_criteria' => json_encode($criteria, JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
            ]);
            $notification_id = (int) $this->db->last_id();
            $this->db->table('notification_requests')->insert([
                'actor_user_id' => (int) $user['id'],
                'request_key' => $stored_request_key,
                'notification_id' => $notification_id,
                'created_at' => $now,
            ]);
            foreach ($recipients as $recipient) {
                $this->db->table('notification_recipients')->insert([
                    'notification_id' => $notification_id,
                    'user_id' => (int) $recipient['id'],
                    'created_at' => $now,
                ]);
            }
            $this->db->raw('COMMIT');
        } catch (Throwable $error) {
            $this->db->raw('ROLLBACK');
            $existing = $this->db->raw(
                'SELECT notification_id AS id FROM notification_requests
                 WHERE actor_user_id = ? AND request_key = ? LIMIT 1',
                [(int) $user['id'], $stored_request_key]
            )->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $this->api->respond([
                    'status' => true,
                    'message' => 'Notification was already published.',
                    'data' => ['id' => (int) $existing['id'], 'duplicate' => true],
                ]);
                return;
            }
            throw $error;
        }

        $this->api->respond([
            'status' => true,
            'message' => 'Notification published.',
            'data' => ['id' => $notification_id, 'recipient_count' => count($recipients)],
        ], 201);
    }

    public function count()
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        if (!$user) return;

        $this->api->respond([
            'status' => true,
            'data' => ['unread_count' => $this->unread_count($user)],
        ]);
    }

    public function update_read()
    {
        $this->api->require_method('PATCH');
        $user = $this->authenticated_user();
        if (!$user) return;

        $input = $this->api->body(false);
        $id = is_array($input) ? filter_var($input['id'] ?? null, FILTER_VALIDATE_INT) : false;
        $source = is_array($input) ? ($input['source'] ?? 'notification') : '';
        $read = is_array($input) ? ($input['read'] ?? null) : null;
        if (!$id || $id < 1 || $source !== 'notification'
            || !is_bool($read)) {
            $this->api->respond_error('Provide a valid notification and read status.', 400);
            return;
        }

        $read_at = $read ? date('Y-m-d H:i:s') : null;
        if ($source === 'notification') {
            [$event_clause, $event_params] = $this->event_visibility_clause($user);
            $notification = $this->db->raw(
                'SELECT r.notification_id
                 FROM notification_recipients r
                 JOIN notifications n ON n.id = r.notification_id
                 LEFT JOIN events e ON n.related_type = \'event\' AND e.id = n.related_id
                 WHERE r.user_id = ? AND n.id = ?
                   AND (n.related_type IS NULL OR n.related_type <> \'event\' OR (' . $event_clause . '))
                 LIMIT 1',
                array_merge([(int) $user['id'], (int) $id], $event_params)
            )->fetch(PDO::FETCH_ASSOC);
            if (!$notification) {
                $this->api->respond_error('Notification not found.', 404);
                return;
            }

            $this->db->table('notification_recipients')
                ->where('notification_id', (int) $id)
                ->where('user_id', (int) $user['id'])
                ->update(['read_at' => $read_at]);
        }

        $this->api->respond([
            'status' => true,
            'message' => 'Notification status saved.',
            'data' => ['unread_count' => $this->unread_count($user)],
        ]);
    }

    public function mark_all_read()
    {
        $this->api->require_method('PATCH');
        $user = $this->authenticated_user();
        if (!$user) return;

        $now = date('Y-m-d H:i:s');
        [$event_clause, $event_params] = $this->event_visibility_clause($user);
        $visible_ids = $this->db->raw(
            'SELECT r.notification_id
             FROM notification_recipients r
             JOIN notifications n ON n.id = r.notification_id
             LEFT JOIN events e ON n.related_type = \'event\' AND e.id = n.related_id
             WHERE r.user_id = ? AND r.read_at IS NULL
               AND (n.related_type IS NULL OR n.related_type <> \'event\' OR (' . $event_clause . '))',
            array_merge([(int) $user['id']], $event_params)
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($visible_ids as $notification_id) {
            $this->db->table('notification_recipients')
                ->where('notification_id', (int) $notification_id)
                ->where('user_id', (int) $user['id'])
                ->update(['read_at' => $now]);
        }

        $this->api->respond([
            'status' => true,
            'message' => 'Notifications marked as read.',
            'data' => ['unread_count' => $this->unread_count($user)],
        ]);
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
        if (!in_array($user['role'], ['admin', 'teacher', 'parent'], true)) {
            $this->api->respond_error('You are not authorized to view notifications.', 403);
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

    private function event_visibility_clause(array $user)
    {
        if ($user['role'] === 'admin') return ['e.id IS NOT NULL', []];
        if ($user['role'] === 'teacher') {
            return [
                'e.id IS NOT NULL AND (e.cdc_id IS NULL OR EXISTS (
                    SELECT 1 FROM teacher_profiles tp
                    JOIN cdcs c ON c.id = tp.cdc_id
                    WHERE tp.user_id = ? AND tp.employment_status = \'active\'
                      AND c.status = \'active\' AND tp.cdc_id = e.cdc_id
                ))',
                [(int) $user['id']],
            ];
        }
        return [
            'e.id IS NOT NULL AND (e.cdc_id IS NULL OR EXISTS (
                SELECT 1 FROM children child
                LEFT JOIN classes child_class ON child_class.id = child.class_id
                JOIN cdcs c ON c.id = e.cdc_id
                WHERE child.parent_user_id = ? AND c.status = \'active\'
                  AND (child.preferred_cdc_id = e.cdc_id OR child_class.cdc_id = e.cdc_id)
            ))',
            [(int) $user['id']],
        ];
    }

    private function unread_count(array $user)
    {
        [$event_clause, $event_params] = $this->event_visibility_clause($user);
        $count = (int) $this->db->raw(
            'SELECT COUNT(*)
             FROM notification_recipients r
             JOIN notifications n ON n.id = r.notification_id
             LEFT JOIN events e ON n.related_type = \'event\' AND e.id = n.related_id
             WHERE r.user_id = ? AND r.read_at IS NULL
               AND (n.related_type IS NULL OR n.related_type <> \'event\' OR (' . $event_clause . '))',
            array_merge([(int) $user['id']], $event_params)
        )->fetchColumn();

        return $count;
    }

    private function present_notification(array $row, $source)
    {
        return [
            'id' => (int) $row['id'],
            'source' => $source,
            'title' => $row['title'],
            'message' => $row['message'],
            'notification_type' => $row['notification_type'] ?? 'System Update',
            'related_module' => $row['related_module'] ?? $row['related_type'] ?? null,
            'related_type' => $row['related_type'] ?? null,
            'related_id' => isset($row['related_id']) && $row['related_id'] !== null
                ? (int) $row['related_id']
                : null,
            'created_at' => $row['created_at'],
            'sender' => $row['sender'] ?? 'KalingaLink',
            'action_url' => $row['action_url'] ?? null,
            'unread' => $row['read_at'] === null,
        ];
    }

    private function eligible_recipient_candidates(array $user)
    {
        $users = $this->db->raw(
            'SELECT id, role, first_name, last_name, email
             FROM users
             WHERE is_active = 1 AND role IN (\'admin\', \'teacher\', \'parent\')
               AND id <> ?
             ORDER BY first_name, last_name, email',
            [(int) $user['id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        $candidates = [];
        foreach ($users as $candidate) {
            $id = (int) $candidate['id'];
            $candidates[$id] = [
                'id' => $id,
                'name' => trim(($candidate['first_name'] ?? '') . ' ' . ($candidate['last_name'] ?? ''))
                    ?: $candidate['email'],
                'email' => $candidate['email'],
                'role' => $candidate['role'],
                'cdc_ids' => [],
                'cdc_names' => [],
            ];
        }

        $memberships = $this->db->raw(
            'SELECT tp.user_id, c.id AS cdc_id, c.name AS cdc_name
             FROM teacher_profiles tp
             JOIN users u ON u.id = tp.user_id AND u.is_active = 1 AND u.role = \'teacher\'
             JOIN cdcs c ON c.id = tp.cdc_id AND c.status = \'active\'
             WHERE tp.employment_status = \'active\'
             UNION
             SELECT child.parent_user_id, c.id, c.name
             FROM children child
             JOIN users u ON u.id = child.parent_user_id AND u.is_active = 1 AND u.role = \'parent\'
             JOIN cdcs c ON c.id = child.preferred_cdc_id AND c.status = \'active\'
             UNION
             SELECT child.parent_user_id, c.id, c.name
             FROM children child
             JOIN users u ON u.id = child.parent_user_id AND u.is_active = 1 AND u.role = \'parent\'
             JOIN classes cl ON cl.id = child.class_id
             JOIN cdcs c ON c.id = cl.cdc_id AND c.status = \'active\''
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($memberships as $membership) {
            $id = (int) $membership['user_id'];
            if (!isset($candidates[$id])) continue;
            $candidates[$id]['cdc_ids'][] = (int) $membership['cdc_id'];
            $candidates[$id]['cdc_names'][] = $membership['cdc_name'];
        }
        foreach ($candidates as &$candidate) {
            $candidate['cdc_ids'] = array_values(array_unique($candidate['cdc_ids']));
            $candidate['cdc_names'] = array_values(array_unique($candidate['cdc_names']));
        }
        unset($candidate);

        if ($user['role'] === 'teacher') {
            $allowed_center_ids = $this->allowed_center_ids($user);
            $candidates = array_filter($candidates, static function ($candidate) use ($allowed_center_ids) {
                return $candidate['role'] === 'parent'
                    && array_intersect($candidate['cdc_ids'], $allowed_center_ids);
            });
        }
        return $candidates;
    }

    private function allowed_center_ids(array $user)
    {
        if ($user['role'] !== 'teacher') {
            return array_map('intval', $this->db->raw(
                'SELECT id FROM cdcs WHERE status = \'active\''
            )->fetchAll(PDO::FETCH_COLUMN));
        }
        return array_map('intval', $this->db->raw(
            'SELECT tp.cdc_id
             FROM teacher_profiles tp
             JOIN cdcs c ON c.id = tp.cdc_id AND c.status = \'active\'
             WHERE tp.user_id = ? AND tp.employment_status = \'active\'',
            [(int) $user['id']]
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    private function resolve_recipients(array $user, array $input)
    {
        $all_eligible = $input['all_eligible'] ?? false;
        $roles = $input['roles'] ?? [];
        $center_ids = $input['cdc_ids'] ?? [];
        $user_ids = $input['user_ids'] ?? [];
        if (!is_bool($all_eligible) || !is_array($roles) || !is_array($center_ids)
            || !is_array($user_ids) || count($roles) > 3
            || count($center_ids) > 100 || count($user_ids) > 1000
            || ($all_eligible && $roles)) {
            $this->api->respond_error('Provide valid recipient criteria.', 400);
            return false;
        }

        $allowed_roles = $user['role'] === 'teacher'
            ? ['parent']
            : ['admin', 'teacher', 'parent'];
        foreach ($roles as $role) {
            if (!is_string($role)) {
                $this->api->respond_error('Provide valid recipient roles.', 400);
                return false;
            }
        }
        $roles = array_values(array_unique($roles));
        foreach ($roles as $role) {
            if (!in_array($role, $allowed_roles, true)) {
                $this->api->respond_error('You cannot target one or more selected roles.', 403);
                return false;
            }
        }
        $allowed_centers = $this->allowed_center_ids($user);
        foreach ($center_ids as $center_id) {
            if (filter_var($center_id, FILTER_VALIDATE_INT) === false) {
                $this->api->respond_error('Provide valid CDC identifiers.', 400);
                return false;
            }
        }
        $center_ids = array_values(array_unique(array_map('intval', $center_ids)));
        if (array_diff($center_ids, $allowed_centers)) {
            $this->api->respond_error('You cannot target one or more selected centers.', 403);
            return false;
        }
        foreach ($user_ids as $user_id) {
            if (filter_var($user_id, FILTER_VALIDATE_INT) === false) {
                $this->api->respond_error('Provide valid user identifiers.', 400);
                return false;
            }
        }
        $user_ids = array_values(array_unique(array_map('intval', $user_ids)));
        $candidates = $this->eligible_recipient_candidates($user);
        if (array_diff($user_ids, array_keys($candidates))) {
            $this->api->respond_error('You cannot target one or more selected users.', 403);
            return false;
        }
        if (!$all_eligible && !$roles && !$center_ids && !$user_ids) {
            $this->api->respond_error('Select at least one recipient.', 400);
            return false;
        }

        $selected = [];
        foreach ($candidates as $candidate_id => $candidate) {
            $center_match = !$center_ids
                || (bool) array_intersect($candidate['cdc_ids'], $center_ids);
            $group_match = false;
            if ($all_eligible) {
                $group_match = $center_match;
            } elseif ($roles) {
                $group_match = in_array($candidate['role'], $roles, true) && $center_match;
            } elseif ($center_ids) {
                $group_match = $center_match;
            }
            if ($group_match || in_array((int) $candidate_id, $user_ids, true)) {
                $selected[$candidate_id] = $candidate;
            }
        }
        if (!$selected) {
            $this->api->respond_error('The selected criteria have no eligible recipients.', 400);
            return false;
        }
        return $selected;
    }
}
