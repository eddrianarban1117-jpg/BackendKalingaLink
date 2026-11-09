<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class TeacherNotificationsApiController extends Controller
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
        $teacher = $this->authenticated_teacher();
        if (!$teacher) return;

        $user_id = (int) $teacher['id'];
        $event_visibility = $this->event_visibility_clause();
        $notifications = $this->db->raw(
            'SELECT n.id, n.title, n.message, n.notification_type,
                    n.related_module, n.related_type, n.related_id, n.action_url, n.created_at,
                    COALESCE(NULLIF(TRIM(CONCAT_WS(\' \', actor.first_name, actor.last_name)), \'\'), \'System\') AS sender,
                    r.read_at
             FROM notification_recipients r
             JOIN notifications n ON n.id = r.notification_id
             LEFT JOIN users actor ON actor.id = n.actor_user_id
             LEFT JOIN events e ON n.related_type = \'event\' AND e.id = n.related_id
             WHERE r.user_id = ?
               AND (n.related_type IS NULL OR n.related_type <> \'event\' OR (' . $event_visibility . '))
             ORDER BY n.created_at DESC, n.id DESC',
            [$user_id, $user_id]
        )->fetchAll(PDO::FETCH_ASSOC);
        $announcements = $this->db->raw(
            'SELECT a.id, a.title, a.message, a.category, a.published_at,
                    a.created_at, a.author_user_id,
                    COALESCE(NULLIF(TRIM(CONCAT_WS(\' \', author.first_name, author.last_name)), \'\'), \'KalingaLink\') AS sender,
                    r.read_at
             FROM announcements a
             JOIN users author ON author.id = a.author_user_id
             LEFT JOIN teacher_announcement_reads r
               ON r.announcement_id = a.id AND r.teacher_user_id = ?
             WHERE a.audience IN (\'teachers\', \'both\')
               AND a.status IN (\'published\', \'scheduled\')
               AND a.published_at <= ?
               AND (a.expires_at IS NULL OR a.expires_at > ?)
             ORDER BY a.published_at DESC, a.id DESC',
            [$user_id, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]
        )->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($notifications as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'source' => 'notification',
                'title' => $row['title'],
                'description' => $row['message'],
                'sender' => $row['sender'],
                'type' => $row['notification_type'] ?: 'System Update',
                'related_module' => $row['related_module'] ?: $row['related_type'],
                'related_id' => $row['related_id'] === null ? null : (int) $row['related_id'],
                'action_url' => $row['action_url'],
                'created_at' => $row['created_at'],
                'unread' => $row['read_at'] === null,
            ];
        }
        foreach ($announcements as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'source' => 'announcement',
                'title' => $row['title'],
                'description' => $row['message'],
                'sender' => $row['sender'],
                'type' => 'Announcement',
                'related_module' => 'announcements',
                'related_id' => (int) $row['id'],
                'action_url' => null,
                'created_at' => $row['published_at'] ?: $row['created_at'],
                'unread' => $row['read_at'] === null,
            ];
        }
        usort($items, static function ($left, $right) {
            return strcmp($right['created_at'], $left['created_at'])
                ?: strcmp($right['source'], $left['source'])
                ?: ($right['id'] <=> $left['id']);
        });

        $this->api->respond([
            'status' => true,
            'data' => [
                'notifications' => $items,
                'unread_count' => count(array_filter(
                    $items,
                    static fn($item) => $item['unread']
                )),
            ],
        ]);
    }

    public function count()
    {
        $this->api->require_method('GET');
        $teacher = $this->authenticated_teacher();
        if (!$teacher) return;

        $user_id = (int) $teacher['id'];
        $now = date('Y-m-d H:i:s');
        $event_visibility = $this->event_visibility_clause();
        $notification_count = (int) $this->db->raw(
            'SELECT COUNT(*)
             FROM notification_recipients r
             JOIN notifications n ON n.id = r.notification_id
             LEFT JOIN events e ON n.related_type = \'event\' AND e.id = n.related_id
             WHERE r.user_id = ? AND r.read_at IS NULL
               AND (n.related_type IS NULL OR n.related_type <> \'event\' OR (' . $event_visibility . '))',
            [$user_id, $user_id]
        )->fetchColumn();
        $announcement_count = (int) $this->db->raw(
            'SELECT COUNT(*)
             FROM announcements a
             LEFT JOIN teacher_announcement_reads r
               ON r.announcement_id = a.id AND r.teacher_user_id = ?
             WHERE a.audience IN (\'teachers\', \'both\')
               AND a.status IN (\'published\', \'scheduled\')
               AND a.published_at <= ?
               AND (a.expires_at IS NULL OR a.expires_at > ?)
               AND r.read_at IS NULL',
            [$user_id, $now, $now]
        )->fetchColumn();

        $this->api->respond([
            'status' => true,
            'data' => ['unread_count' => $notification_count + $announcement_count],
        ]);
    }

    public function update_read()
    {
        $this->api->require_method('PATCH');
        $teacher = $this->authenticated_teacher();
        if (!$teacher) return;

        $input = $this->api->body(false);
        $source = is_array($input) ? ($input['source'] ?? '') : '';
        $id = is_array($input) ? filter_var($input['id'] ?? null, FILTER_VALIDATE_INT) : false;
        $read = is_array($input) ? ($input['read'] ?? null) : null;
        if (!in_array($source, ['notification', 'announcement'], true)
            || !$id || !is_bool($read)) {
            $this->api->respond_error('Provide a valid notification and read status.', 400);
            return;
        }

        $user_id = (int) $teacher['id'];
        $read_at = $read ? date('Y-m-d H:i:s') : null;
        if ($source === 'notification') {
            $recipient = $this->db->raw(
                'SELECT r.id FROM notification_recipients r
                 JOIN notifications n ON n.id = r.notification_id
                 LEFT JOIN events e ON n.related_type = \'event\' AND e.id = n.related_id
                 WHERE r.notification_id = ? AND r.user_id = ?
                   AND (n.related_type IS NULL OR n.related_type <> \'event\' OR (' . $this->event_visibility_clause() . '))',
                [(int) $id, $user_id, $user_id]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$recipient) {
                $this->api->respond_error('Notification not found.', 404);
                return;
            }
            $this->db->table('notification_recipients')
                ->where('notification_id', (int) $id)
                ->where('user_id', $user_id)
                ->update(['read_at' => $read_at]);
        } else {
            $announcement = $this->db->raw(
                'SELECT id FROM announcements
                 WHERE id = ? AND audience IN (\'teachers\', \'both\')
                   AND status IN (\'published\', \'scheduled\')
                   AND published_at <= ?
                   AND (expires_at IS NULL OR expires_at > ?)',
                [(int) $id, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$announcement) {
                $this->api->respond_error('Announcement not found.', 404);
                return;
            }
            $this->db->raw(
                'INSERT INTO teacher_announcement_reads
                    (announcement_id, teacher_user_id, read_at, created_at)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)',
                [(int) $id, $user_id, $read_at, date('Y-m-d H:i:s')]
            );
        }

        $this->api->respond(['status' => true, 'message' => 'Notification status saved.']);
    }

    public function mark_all_read()
    {
        $this->api->require_method('PATCH');
        $teacher = $this->authenticated_teacher();
        if (!$teacher) return;

        $user_id = (int) $teacher['id'];
        $now = date('Y-m-d H:i:s');
        $event_visibility = $this->event_visibility_clause();
        $this->db->transaction();
        try {
            $this->db->raw(
                'UPDATE notification_recipients r
                 JOIN notifications n ON n.id = r.notification_id
                 LEFT JOIN events e ON n.related_type = \'event\' AND e.id = n.related_id
                 SET r.read_at = ?
                 WHERE r.user_id = ? AND r.read_at IS NULL
                   AND (n.related_type IS NULL OR n.related_type <> \'event\' OR (' . $event_visibility . '))',
                [$now, $user_id, $user_id]
            );
            $this->db->raw(
                'INSERT INTO teacher_announcement_reads
                    (announcement_id, teacher_user_id, read_at, created_at)
                 SELECT a.id, ?, ?, ?
                 FROM announcements a
                 WHERE a.audience IN (\'teachers\', \'both\')
                   AND a.status IN (\'published\', \'scheduled\')
                   AND a.published_at <= ?
                   AND (a.expires_at IS NULL OR a.expires_at > ?)
                 ON DUPLICATE KEY UPDATE read_at = COALESCE(read_at, VALUES(read_at))',
                [$user_id, $now, $now, $now, $now]
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        $this->api->respond(['status' => true, 'message' => 'Notifications marked as read.']);
    }

    private function event_visibility_clause()
    {
        return 'e.id IS NOT NULL AND (e.cdc_id IS NULL OR EXISTS (
            SELECT 1 FROM teacher_profiles tp
            JOIN cdcs c ON c.id = tp.cdc_id
            WHERE tp.user_id = ?
              AND tp.employment_status = \'active\'
              AND c.status = \'active\' AND tp.cdc_id = e.cdc_id
        ))';
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
            $this->api->respond_error('You are not authorized to view these notifications.', 403);
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
