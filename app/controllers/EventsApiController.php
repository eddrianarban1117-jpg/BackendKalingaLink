<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class EventsApiController extends Controller
{
    private const MAX_PHOTO_SIZE = 5242880;
    private const MAX_PHOTOS_PER_UPLOAD = 10;

    private const PHOTO_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

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

        $sql = 'SELECT e.id, e.title, e.event_date, e.start_time, e.end_time, e.venue,
                       e.description, e.cdc_id, e.created_by, e.created_at, e.updated_at,
                       c.name AS cdc_name,
                       EXISTS (
                           SELECT 1 FROM event_albums album
                           JOIN event_photos photo ON photo.album_id = album.id
                           WHERE album.event_id = e.id
                       ) AS has_photos,
                       TRIM(CONCAT_WS(\' \', creator.first_name, creator.last_name)) AS creator_name
                FROM events e
                LEFT JOIN cdcs c ON c.id = e.cdc_id
                LEFT JOIN users creator ON creator.id = e.created_by';
        $params = [];
        if ($user['role'] === 'teacher') {
            $sql .= ' WHERE e.cdc_id IS NULL OR EXISTS (
                          SELECT 1 FROM teacher_profiles tp
                          JOIN cdcs teacher_cdc ON teacher_cdc.id = tp.cdc_id
                          WHERE tp.user_id = ? AND tp.employment_status = \'active\'
                            AND teacher_cdc.status = \'active\' AND tp.cdc_id = e.cdc_id
                      )';
            $params[] = (int) $user['id'];
        } elseif ($user['role'] === 'parent') {
            $sql .= ' WHERE e.cdc_id IS NULL OR EXISTS (
                          SELECT 1 FROM children child
                          LEFT JOIN classes child_class ON child_class.id = child.class_id
                          JOIN cdcs parent_cdc ON parent_cdc.id = e.cdc_id
                          WHERE child.parent_user_id = ?
                            AND parent_cdc.status = \'active\'
                            AND (child.preferred_cdc_id = e.cdc_id
                                 OR child_class.cdc_id = e.cdc_id)
                      )';
            $params[] = (int) $user['id'];
        }
        $sql .= ' ORDER BY e.event_date DESC, e.start_time, e.id DESC';
        $rows = $this->db->raw($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
        $events = array_map(
            fn($row) => $this->present_event($row, $user),
            $rows
        );

        $this->api->respond(['status' => true, 'data' => $events]);
    }

    public function show($id)
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        if (!$user) return;
        $event = $this->find_event($id);
        if (!$event || !$this->can_access_event($user, $event)) {
            $this->api->respond_error('Event not found.', 404);
            return;
        }

        $result = $this->present_event($event, $user);
        $album = $this->find_album((int) $event['id']);
        if ($user['role'] !== 'parent') {
            $result['description'] = $event['description'];
            $result['start_time'] = $event['start_time'];
            $result['end_time'] = $event['end_time'];
            $result['venue'] = $event['venue'];
            $result['cdc_id'] = $event['cdc_id'] === null ? null : (int) $event['cdc_id'];
            $result['cdc_name'] = $event['cdc_name'];
            $result['created_by'] = $event['creator_name'] ?: 'KalingaLink user';
            $result['created_at'] = $event['created_at'];
            $result['updated_at'] = $event['updated_at'];
        }
        $result['album'] = $album ? [
            'id' => (int) $album['id'],
            'name' => $album['album_name'],
            'photos' => $this->album_photos((int) $album['id'], $user),
        ] : null;

        $this->api->respond(['status' => true, 'data' => $result]);
    }

    public function notifications()
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        if (!$user) return;

        [$access_clause, $access_params] = $this->event_notification_access_clause($user);
        $notifications = $this->db->raw(
            'SELECT n.id, n.title, n.message, n.related_id AS event_id, n.created_at,
                    r.read_at, e.title AS event_title, e.event_date,
                    TRIM(CONCAT_WS(\' \', actor.first_name, actor.last_name)) AS sender
             FROM notification_recipients r
             JOIN notifications n ON n.id = r.notification_id
             JOIN events e ON e.id = n.related_id
             LEFT JOIN users actor ON actor.id = n.actor_user_id
             WHERE r.user_id = ? AND n.related_type = \'event\' ' . $access_clause . '
             ORDER BY n.created_at DESC, n.id DESC
             LIMIT 100',
            array_merge([(int) $user['id']], $access_params)
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($notifications as &$notification) {
            $notification['id'] = (int) $notification['id'];
            $notification['event_id'] = (int) $notification['event_id'];
            $notification['unread'] = $notification['read_at'] === null;
            $notification['sender'] = trim((string) $notification['sender']) ?: 'KalingaLink';
        }
        unset($notification);

        $this->api->respond([
            'status' => true,
            'data' => [
                'notifications' => $notifications,
                'unread_count' => $this->event_notification_unread_count($user),
            ],
        ]);
    }

    public function notification_count()
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        if (!$user) return;

        $this->api->respond([
            'status' => true,
            'data' => ['unread_count' => $this->event_notification_unread_count($user)],
        ]);
    }

    public function update_notification_read()
    {
        $this->api->require_method('PATCH');
        $user = $this->authenticated_user();
        if (!$user) return;

        $input = $this->api->body(false);
        $notification_id = is_array($input)
            ? filter_var($input['id'] ?? null, FILTER_VALIDATE_INT)
            : false;
        $read = is_array($input) ? ($input['read'] ?? null) : null;
        if (!$notification_id || $notification_id < 1 || !is_bool($read)) {
            $this->api->respond_error('Provide a valid event notification and read status.', 400);
            return;
        }

        [$access_clause, $access_params] = $this->event_notification_access_clause($user);
        $notification = $this->db->raw(
            'SELECT r.notification_id
             FROM notification_recipients r
             JOIN notifications n ON n.id = r.notification_id
             JOIN events e ON e.id = n.related_id
             WHERE r.user_id = ? AND n.id = ? AND n.related_type = \'event\' ' . $access_clause . '
             LIMIT 1',
            array_merge([(int) $user['id'], (int) $notification_id], $access_params)
        )->fetch(PDO::FETCH_ASSOC);
        if (!$notification) {
            $this->api->respond_error('Event notification not found.', 404);
            return;
        }

        $this->db->table('notification_recipients')
            ->where('notification_id', (int) $notification_id)
            ->where('user_id', (int) $user['id'])
            ->update(['read_at' => $read ? date('Y-m-d H:i:s') : null]);

        $this->api->respond([
            'status' => true,
            'message' => 'Event notification status saved.',
            'data' => ['unread_count' => $this->event_notification_unread_count($user)],
        ]);
    }

    public function mark_event_notifications_read()
    {
        $this->api->require_method('PATCH');
        $user = $this->authenticated_user();
        if (!$user) return;

        [$access_clause, $access_params] = $this->event_notification_access_clause($user);
        $notifications = $this->db->raw(
            'SELECT r.notification_id
             FROM notification_recipients r
             JOIN notifications n ON n.id = r.notification_id
             JOIN events e ON e.id = n.related_id
             WHERE r.user_id = ? AND r.read_at IS NULL
               AND n.related_type = \'event\' ' . $access_clause,
            array_merge([(int) $user['id']], $access_params)
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($notifications as $notification_id) {
            $this->db->table('notification_recipients')
                ->where('notification_id', (int) $notification_id)
                ->where('user_id', (int) $user['id'])
                ->update(['read_at' => date('Y-m-d H:i:s')]);
        }

        $this->api->respond([
            'status' => true,
            'message' => 'Event notifications marked as read.',
            'data' => ['unread_count' => $this->event_notification_unread_count($user)],
        ]);
    }

    public function create()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user(['admin', 'teacher']);
        if (!$user) return;
        $input = $this->api->body(false);
        $event = $this->validated_event($input, $user);
        if (!$event) return;

        $this->db->transaction();
        try {
            $this->db->table('events')->insert([
                'title' => $event['title'],
                'description' => $event['description'],
                'event_date' => $event['event_date'],
                'start_time' => $event['start_time'],
                'end_time' => $event['end_time'],
                'venue' => $event['venue'],
                'cdc_id' => $event['cdc_id'],
                'created_by' => (int) $user['id'],
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $event_id = (int) $this->db->last_id();
            $this->create_album_record($event_id, $event['title'], (int) $user['id']);
            $this->notify_event_users($event_id, $event, $user, false);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        $this->api->respond([
            'status' => true,
            'message' => 'Event created.',
            'data' => ['id' => $event_id],
        ], 201);
    }

    public function update($id)
    {
        $this->api->require_method('PUT');
        $user = $this->authenticated_user(['admin', 'teacher']);
        if (!$user) return;
        $event_id = filter_var($id, FILTER_VALIDATE_INT);
        $existing = $event_id ? $this->find_event((int) $event_id) : null;
        if (!$existing || !$this->can_access_event($user, $existing)) {
            $this->api->respond_error('Event not found.', 404);
            return;
        }
        if (!$this->can_manage_event($user, $existing)) {
            $this->api->respond_error('Only the event creator may update this event.', 403);
            return;
        }

        $event = $this->validated_event($this->api->body(false), $user);
        if (!$event) return;
        $this->db->transaction();
        try {
            $this->db->table('events')
                ->where('id', (int) $event_id)
                ->update([
                    'title' => $event['title'],
                    'description' => $event['description'],
                    'event_date' => $event['event_date'],
                    'start_time' => $event['start_time'],
                    'end_time' => $event['end_time'],
                    'venue' => $event['venue'],
                    'cdc_id' => $event['cdc_id'],
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            $this->db->raw(
                'UPDATE event_albums SET album_name = ? WHERE event_id = ?',
                [$event['title'] . ' photo album', (int) $event_id]
            );
            $this->notify_event_users((int) $event_id, $event, $user, true);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        $this->api->respond(['status' => true, 'message' => 'Event updated.']);
    }

    public function delete($id)
    {
        $this->api->require_method('DELETE');
        $user = $this->authenticated_user(['admin', 'teacher']);
        if (!$user) return;
        $event_id = filter_var($id, FILTER_VALIDATE_INT);
        $event = $event_id ? $this->find_event((int) $event_id) : null;
        if (!$event || !$this->can_access_event($user, $event)) {
            $this->api->respond_error('Event not found.', 404);
            return;
        }
        if (!$this->can_manage_event($user, $event)) {
            $this->api->respond_error('Only the event creator may delete this event.', 403);
            return;
        }

        $photos = $this->db->raw(
            'SELECT p.image_path FROM event_photos p
             JOIN event_albums a ON a.id = p.album_id
             WHERE a.event_id = ?',
            [(int) $event_id]
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->db->transaction();
        try {
            $this->db->table('events')->where('id', (int) $event_id)->delete();
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        try {
            foreach ($photos as $image_path) {
                $this->remove_photo_file($image_path);
            }
        } catch (Throwable $error) {
            error_log('Event album cleanup failed after deleting event ' . (int) $event_id . ': ' . $error->getMessage());
            $this->api->respond_error('The event was deleted, but some album images could not be removed.', 500);
        }

        $this->api->respond(['status' => true, 'message' => 'Event and its album were deleted.']);
    }

    public function create_album($id)
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        if (!$user) return;
        $event = $this->find_event($id);
        if (!$event || !$this->can_access_event($user, $event)) {
            $this->api->respond_error('Event not found.', 404);
            return;
        }
        $album_id = $this->create_album_record(
            (int) $event['id'],
            $event['title'],
            (int) $user['id']
        );
        $this->api->respond([
            'status' => true,
            'message' => 'Event album is ready.',
            'data' => ['id' => $album_id],
        ]);
    }

    public function upload_photos($id)
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        if (!$user) return;
        $event = $this->find_event($id);
        if (!$event || !$this->can_access_event($user, $event)) {
            $this->api->respond_error('Event not found.', 404);
            return;
        }
        $uploads = $this->collect_photo_uploads();
        if ($uploads === false) return;
        if (!$uploads) {
            $this->api->respond_error('Choose at least one image to upload.', 400);
            return;
        }

        $input = $this->api->body(false);
        $caption = is_array($input) && is_string($input['caption'] ?? null)
            ? trim($input['caption'])
            : '';
        if (mb_strlen($caption) > 500) {
            $this->api->respond_error('Photo captions must be 500 characters or fewer.', 400);
            return;
        }

        $stored = [];
        $this->db->transaction();
        try {
            $album_id = $this->create_album_record(
                (int) $event['id'],
                $event['title'],
                (int) $user['id']
            );
            foreach ($uploads as $upload) {
                $saved = $this->store_photo($upload);
                $stored[] = $saved['path'];
                $this->db->table('event_photos')->insert([
                    'album_id' => $album_id,
                    'uploaded_by' => (int) $user['id'],
                    'image_path' => $saved['path'],
                    'mime_type' => $saved['mime'],
                    'file_size' => $saved['size'],
                    'caption' => $caption !== '' ? $caption : null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            foreach ($stored as $path) {
                $this->remove_photo_file($path);
            }
            throw $error;
        }

        $this->api->respond([
            'status' => true,
            'message' => count($stored) . ' photo(s) uploaded.',
        ], 201);
    }

    public function update_photo($id)
    {
        $this->api->require_method('PATCH');
        $user = $this->authenticated_user();
        if (!$user) return;
        $photo_id = filter_var($id, FILTER_VALIDATE_INT);
        $photo = $photo_id ? $this->find_photo((int) $photo_id) : null;
        if (!$photo || !$this->can_access_event($user, $photo)) {
            $this->api->respond_error('Photo not found.', 404);
            return;
        }
        if (!$this->can_manage_photo($user, $photo)) {
            $this->api->respond_error('You are not authorized to update this photo.', 403);
            return;
        }
        $input = $this->api->body(false);
        $caption = is_array($input) && is_string($input['caption'] ?? null)
            ? trim($input['caption'])
            : null;
        if ($caption === null || mb_strlen($caption) > 500) {
            $this->api->respond_error('Provide a caption with 500 characters or fewer.', 400);
            return;
        }

        $this->db->table('event_photos')
            ->where('id', (int) $photo_id)
            ->update([
                'caption' => $caption !== '' ? $caption : null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        $this->api->respond(['status' => true, 'message' => 'Photo caption updated.']);
    }

    public function delete_photo($id)
    {
        $this->api->require_method('DELETE');
        $user = $this->authenticated_user();
        if (!$user) return;
        $photo_id = filter_var($id, FILTER_VALIDATE_INT);
        $photo = $photo_id ? $this->find_photo((int) $photo_id) : null;
        if (!$photo || !$this->can_access_event($user, $photo)) {
            $this->api->respond_error('Photo not found.', 404);
            return;
        }
        if (!$this->can_manage_photo($user, $photo)) {
            $this->api->respond_error('You are not authorized to delete this photo.', 403);
            return;
        }

        $this->db->table('event_photos')->where('id', (int) $photo_id)->delete();
        try {
            $this->remove_photo_file($photo['image_path']);
        } catch (Throwable $error) {
            error_log('Event photo cleanup failed for photo ' . (int) $photo_id . ': ' . $error->getMessage());
            $this->api->respond_error('The photo record was deleted, but its image could not be removed.', 500);
        }
        $this->api->respond(['status' => true, 'message' => 'Photo deleted.']);
    }

    public function image($id)
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        if (!$user) return;
        $photo_id = filter_var($id, FILTER_VALIDATE_INT);
        $photo = $photo_id ? $this->find_photo((int) $photo_id) : null;
        if (!$photo || !$this->can_access_event($user, $photo)) {
            $this->api->respond_error('Photo not found.', 404);
            return;
        }
        $path = $this->photo_absolute_path($photo['image_path']);
        if (!is_file($path) || !is_readable($path)) {
            $this->api->respond_error('Photo image is unavailable.', 404);
            return;
        }

        header('Content-Type: ' . $photo['mime_type']);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="event-photo-' . (int) $photo_id . '"');
        header('Cache-Control: private, max-age=60');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
    }

    private function authenticated_user(array $roles = ['admin', 'teacher', 'parent'])
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
            $this->api->respond_error('You are not authorized to access Events.', 403);
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

    private function validated_event($input, array $user)
    {
        if (!is_array($input)) {
            $this->api->respond_error('Provide event details.', 400);
            return null;
        }
        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
        $description = is_string($input['description'] ?? null) ? trim($input['description']) : '';
        $venue = is_string($input['venue'] ?? null) ? trim($input['venue']) : '';
        $event_date = $this->valid_date($input['event_date'] ?? null);
        $start_time = $this->valid_time($input['start_time'] ?? null);
        $end_time = $this->valid_time($input['end_time'] ?? null);
        $cdc_id = $input['cdc_id'] ?? null;
        if ($cdc_id === '' || $cdc_id === 0 || $cdc_id === '0') $cdc_id = null;
        if ($cdc_id !== null) {
            $cdc_id = filter_var($cdc_id, FILTER_VALIDATE_INT);
        }

        if ($title === '' || mb_strlen($title) > 180
            || mb_strlen($description) > 10000
            || mb_strlen($venue) > 255
            || $event_date === false
            || $start_time === false
            || $end_time === false
            || ($start_time !== null && $end_time !== null && $end_time <= $start_time)
            || ($input['cdc_id'] ?? null) !== null && ($input['cdc_id'] ?? '') !== '' && !$cdc_id) {
            $this->api->respond_error('Provide a title, valid date and time, and valid event details.', 400);
            return null;
        }
        if ($cdc_id !== null && !$this->can_manage_cdc($user, (int) $cdc_id)) {
            $this->api->respond_error('You are not authorized to use that Child Development Center.', 403);
            return null;
        }

        return [
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'event_date' => $event_date,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'venue' => $venue !== '' ? $venue : null,
            'cdc_id' => $cdc_id,
        ];
    }

    private function valid_date($value)
    {
        if (!is_string($value)) return false;
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        $errors = DateTime::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))
            || $date->format('Y-m-d') !== $value) {
            return false;
        }
        return $value;
    }

    private function valid_time($value)
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value)) return false;
        $time = DateTime::createFromFormat('!H:i', $value);
        $errors = DateTime::getLastErrors();
        if (!$time || ($errors && ($errors['warning_count'] || $errors['error_count']))
            || $time->format('H:i') !== $value) {
            return false;
        }
        return $value . ':00';
    }

    private function can_manage_cdc(array $user, $cdc_id)
    {
        if ($user['role'] === 'admin') {
            $cdc = $this->db->raw('SELECT id FROM cdcs WHERE id = ? AND status = \'active\'', [$cdc_id])
                ->fetch(PDO::FETCH_ASSOC);
            return (bool) $cdc;
        }
        $teacher = $this->db->raw(
            'SELECT tp.user_id FROM teacher_profiles tp
             JOIN cdcs c ON c.id = tp.cdc_id
             WHERE tp.user_id = ? AND tp.cdc_id = ?
               AND tp.employment_status = \'active\' AND c.status = \'active\' LIMIT 1',
            [(int) $user['id'], $cdc_id]
        )->fetch(PDO::FETCH_ASSOC);
        return (bool) $teacher;
    }

    private function can_access_event(array $user, array $event)
    {
        if ($user['role'] === 'admin' || $event['cdc_id'] === null) return true;
        $cdc_id = (int) $event['cdc_id'];
        if ($user['role'] === 'teacher') {
            return (bool) $this->db->raw(
                'SELECT tp.user_id FROM teacher_profiles tp
                 JOIN cdcs c ON c.id = tp.cdc_id
                 WHERE tp.user_id = ? AND tp.cdc_id = ?
                   AND tp.employment_status = \'active\' AND c.status = \'active\' LIMIT 1',
                [(int) $user['id'], $cdc_id]
            )->fetch(PDO::FETCH_ASSOC);
        }
        return (bool) $this->db->raw(
            'SELECT child.id FROM children child
             LEFT JOIN classes child_class ON child_class.id = child.class_id
             JOIN cdcs c ON c.id = ?
             WHERE child.parent_user_id = ?
               AND c.status = \'active\'
               AND (child.preferred_cdc_id = ? OR child_class.cdc_id = ?)
             LIMIT 1',
            [$cdc_id, (int) $user['id'], $cdc_id, $cdc_id]
        )->fetch(PDO::FETCH_ASSOC);
    }

    private function can_manage_event(array $user, array $event)
    {
        return in_array($user['role'], ['admin', 'teacher'], true)
            && ($user['role'] === 'admin' && $event['created_by'] === null
                || (int) $user['id'] === (int) $event['created_by'])
            && $this->can_access_event($user, $event);
    }

    private function can_manage_photo(array $user, array $photo)
    {
        return (int) $photo['uploaded_by'] === (int) $user['id']
            || in_array($user['role'], ['admin', 'teacher'], true);
    }

    private function find_event($id)
    {
        $event_id = filter_var($id, FILTER_VALIDATE_INT);
        if (!$event_id) return null;
        return $this->db->raw(
            'SELECT e.id, e.title, e.description, e.event_date, e.start_time, e.end_time,
                    e.venue, e.cdc_id, e.created_by, e.created_at, e.updated_at,
                    c.name AS cdc_name,
                    EXISTS (
                        SELECT 1 FROM event_albums album
                        JOIN event_photos photo ON photo.album_id = album.id
                        WHERE album.event_id = e.id
                    ) AS has_photos,
                    TRIM(CONCAT_WS(\' \', creator.first_name, creator.last_name)) AS creator_name
             FROM events e
             LEFT JOIN cdcs c ON c.id = e.cdc_id
             LEFT JOIN users creator ON creator.id = e.created_by
             WHERE e.id = ? LIMIT 1',
            [(int) $event_id]
        )->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function present_event(array $event, array $user)
    {
        $is_manager = in_array($user['role'], ['admin', 'teacher'], true);
        $result = [
            'id' => (int) $event['id'],
            'title' => $event['title'],
            'event_date' => $event['event_date'],
            'has_photos' => !empty($event['has_photos']),
        ];
        if ($user['role'] !== 'parent') {
            $result += [
                'description' => $event['description'],
                'start_time' => $event['start_time'],
                'end_time' => $event['end_time'],
                'venue' => $event['venue'],
                'cdc_id' => $event['cdc_id'] === null ? null : (int) $event['cdc_id'],
                'cdc_name' => $event['cdc_name'] ?? null,
                'created_by' => $event['creator_name'] ?: 'KalingaLink user',
                'creator_id' => $event['created_by'] === null
                    ? null
                    : (int) $event['created_by'],
                'can_manage' => $is_manager
                    && (int) $user['id'] === (int) $event['created_by'],
                'created_at' => $event['created_at'],
                'updated_at' => $event['updated_at'],
            ];
        }
        return $result;
    }

    private function find_album($event_id)
    {
        return $this->db->raw(
            'SELECT id, album_name FROM event_albums WHERE event_id = ? LIMIT 1',
            [$event_id]
        )->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function create_album_record($event_id, $title, $user_id)
    {
        $this->db->raw(
            'INSERT INTO event_albums (event_id, album_name, created_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id',
            [$event_id, mb_substr($title . ' photo album', 0, 180), $user_id]
        );
        $album = $this->find_album($event_id);
        if (!$album || (int) $album['id'] < 1) {
            throw new RuntimeException('Unable to retrieve the event album after creation.');
        }
        return (int) $album['id'];
    }

    private function album_photos($album_id, array $user)
    {
        $photos = $this->db->raw(
            'SELECT p.id, p.uploaded_by, p.image_path, p.caption, p.created_at, p.updated_at,
                    TRIM(CONCAT_WS(\' \', uploader.first_name, uploader.last_name)) AS uploader_name
             FROM event_photos p
             LEFT JOIN users uploader ON uploader.id = p.uploaded_by
             WHERE p.album_id = ?
             ORDER BY p.created_at DESC, p.id DESC',
            [$album_id]
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(function ($photo) use ($user) {
            return [
                'id' => (int) $photo['id'],
                'caption' => $photo['caption'],
                'uploaded_by' => $photo['uploader_name'] ?: 'KalingaLink user',
                'uploaded_at' => $photo['created_at'],
                'updated_at' => $photo['updated_at'],
                'image_url' => '/api/events/photos/' . (int) $photo['id'] . '/image',
                'can_manage' => $this->can_manage_photo($user, $photo),
            ];
        }, $photos);
    }

    private function find_photo($id)
    {
        return $this->db->raw(
            'SELECT p.id, p.album_id, p.uploaded_by, p.image_path, p.mime_type,
                    a.event_id, e.cdc_id
             FROM event_photos p
             JOIN event_albums a ON a.id = p.album_id
             JOIN events e ON e.id = a.event_id
             WHERE p.id = ? LIMIT 1',
            [$id]
        )->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function collect_photo_uploads()
    {
        $input = $_FILES['photos'] ?? null;
        if (!$input) return [];
        if (!is_array($input['name'] ?? null)) {
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
                $input[$key] = [$input[$key] ?? null];
            }
        }
        $count = count($input['name'] ?? []);
        if ($count > self::MAX_PHOTOS_PER_UPLOAD) {
            $this->api->respond_error('Upload no more than 10 photos at a time.', 400);
            return false;
        }
        $uploads = [];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        for ($index = 0; $index < $count; $index++) {
            $error = $input['error'][$index] ?? UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE) continue;
            $tmp_name = $input['tmp_name'][$index] ?? '';
            $size = (int) ($input['size'][$index] ?? 0);
            if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($tmp_name)
                || $size <= 0 || $size > self::MAX_PHOTO_SIZE) {
                $this->api->respond_error('Each event photo must be a valid image no larger than 5 MB.', 400);
                return false;
            }
            $mime = $finfo->file($tmp_name);
            if (!isset(self::PHOTO_MIMES[$mime])) {
                $this->api->respond_error('Event photos must be JPG, PNG, or WebP images.', 400);
                return false;
            }
            $uploads[] = [
                'tmp_name' => $tmp_name,
                'mime' => $mime,
                'extension' => self::PHOTO_MIMES[$mime],
                'size' => $size,
            ];
        }
        return $uploads;
    }

    private function store_photo(array $upload)
    {
        $directory = ROOT_DIR . 'runtime' . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'events';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the private event photo directory.');
        }
        $name = bin2hex(random_bytes(20)) . '.' . $upload['extension'];
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (!move_uploaded_file($upload['tmp_name'], $path)) {
            throw new RuntimeException('Could not store the event photo.');
        }
        return ['path' => $name, 'mime' => $upload['mime'], 'size' => $upload['size']];
    }

    private function photo_absolute_path($image_path)
    {
        if (!is_string($image_path)
            || !preg_match('/^[a-f0-9]{40}\.(?:jpg|png|webp)$/D', $image_path)) {
            throw new RuntimeException('The stored event photo path is invalid.');
        }
        return ROOT_DIR . 'runtime' . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'events' . DIRECTORY_SEPARATOR . $image_path;
    }

    private function remove_photo_file($image_path)
    {
        $path = $this->photo_absolute_path($image_path);
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Could not remove a stored event photo.');
        }
    }

    private function notify_event_users($event_id, array $event, array $actor, $updated)
    {
        if (!$updated) {
            $existing = $this->db->raw(
                'SELECT id FROM notifications
                 WHERE related_type = \'event\' AND related_id = ?
                   AND title = \'New Event Announcement\'
                 LIMIT 1',
                [(int) $event_id]
            )->fetch(PDO::FETCH_ASSOC);
            if ($existing) return;

            if ($actor['role'] === 'admin') {
                $recipients = $this->event_creation_recipients($event, $actor, ['teacher', 'parent']);
                $sender = 'MSWDO Admin';
            } else {
                $admins = $this->db->raw(
                    'SELECT id FROM users
                     WHERE role = \'admin\' AND is_active = 1 AND id <> ?',
                    [(int) $actor['id']]
                )->fetchAll(PDO::FETCH_COLUMN);
                $parents = $this->event_creation_recipients($event, $actor, ['parent']);
                $recipients = array_values(array_unique(array_merge($admins, $parents)));
                $sender = trim(implode(' ', array_filter([
                    $actor['first_name'] ?? '',
                    $actor['last_name'] ?? '',
                ]))) ?: 'Teacher';
            }
            if (!$recipients) return;

            $event_date = DateTime::createFromFormat('!Y-m-d', $event['event_date']);
            $formatted_date = $event_date ? $event_date->format('F j, Y') : $event['event_date'];
            $this->db->table('notifications')->insert([
                'actor_user_id' => (int) $actor['id'],
                'title' => 'New Event Announcement',
                'message' => $sender . ' has published a new event: ' . $event['title']
                    . '. Scheduled on ' . $formatted_date . '. Click to view the event.',
                'audience' => 'single',
                'related_type' => 'event',
                'related_id' => (int) $event_id,
                'related_module' => 'events',
                'notification_type' => 'Event',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $notification_id = (int) $this->db->last_id();
            foreach ($recipients as $recipient_id) {
                $this->db->raw(
                    'INSERT INTO notification_recipients
                        (notification_id, user_id, created_at)
                     VALUES (?, ?, ?)',
                    [$notification_id, (int) $recipient_id, date('Y-m-d H:i:s')]
                );
            }
            return;
        }

        if ($event['cdc_id'] === null) {
            $recipients = $this->db->raw(
                'SELECT id FROM users
                 WHERE is_active = 1 AND role IN (\'teacher\', \'parent\') AND id <> ?',
                [(int) $actor['id']]
            )->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $recipients = $this->db->raw(
                'SELECT DISTINCT u.id
                 FROM users u
                 LEFT JOIN teacher_profiles tp ON tp.user_id = u.id
                 LEFT JOIN children child ON child.parent_user_id = u.id
                 LEFT JOIN classes child_class ON child_class.id = child.class_id
                 WHERE u.is_active = 1 AND u.id <> ?
                   AND ((u.role = \'teacher\' AND tp.employment_status = \'active\' AND tp.cdc_id = ?)
                     OR (u.role = \'parent\' AND (child.preferred_cdc_id = ? OR child_class.cdc_id = ?)))',
                [(int) $actor['id'], (int) $event['cdc_id'], (int) $event['cdc_id'], (int) $event['cdc_id']]
            )->fetchAll(PDO::FETCH_COLUMN);
        }
        if (!$recipients) return;

        $this->db->table('notifications')->insert([
            'actor_user_id' => (int) $actor['id'],
            'title' => $updated ? 'Event updated' : 'New event published',
            'message' => $updated
                ? $event['title'] . ' has been updated.'
                : $event['title'] . ' has been added to the Events calendar.',
            'audience' => 'both',
            'related_type' => 'event',
            'related_id' => $event_id,
            'related_module' => 'events',
            'notification_type' => 'Event',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $notification_id = (int) $this->db->last_id();
        foreach ($recipients as $recipient_id) {
            $this->db->table('notification_recipients')->insert([
                'notification_id' => $notification_id,
                'user_id' => (int) $recipient_id,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function event_creation_recipients(array $event, array $actor, array $roles)
    {
        $role_placeholders = implode(', ', array_fill(0, count($roles), '?'));
        $params = array_merge($roles, [(int) $actor['id']]);
        $sql = 'SELECT DISTINCT u.id
                FROM users u
                WHERE u.is_active = 1 AND u.role IN (' . $role_placeholders . ')
                  AND u.id <> ?';

        if ($event['cdc_id'] !== null) {
            $sql .= ' AND EXISTS (SELECT 1 FROM cdcs c WHERE c.id = ? AND c.status = \'active\')';
            $params[] = (int) $event['cdc_id'];

            $sql .= ' AND (
                (u.role = \'teacher\' AND EXISTS (
                    SELECT 1 FROM teacher_profiles tp
                    WHERE tp.user_id = u.id AND tp.cdc_id = ?
                      AND tp.employment_status = \'active\'
                ))
                OR (u.role = \'parent\' AND EXISTS (
                    SELECT 1 FROM children child
                    LEFT JOIN classes child_class ON child_class.id = child.class_id
                    WHERE child.parent_user_id = u.id
                      AND (child.preferred_cdc_id = ? OR child_class.cdc_id = ?)
                ))
            )';
            $params[] = (int) $event['cdc_id'];
            $params[] = (int) $event['cdc_id'];
            $params[] = (int) $event['cdc_id'];
        }

        return $this->db->raw($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    private function event_notification_access_clause(array $user)
    {
        if ($user['role'] === 'admin') return ['', []];
        if ($user['role'] === 'teacher') {
            return [
                ' AND (e.cdc_id IS NULL OR EXISTS (
                    SELECT 1 FROM teacher_profiles tp
                    JOIN cdcs c ON c.id = tp.cdc_id
                    WHERE tp.user_id = ? AND tp.employment_status = \'active\'
                      AND c.status = \'active\' AND tp.cdc_id = e.cdc_id
                ))',
                [(int) $user['id']],
            ];
        }
        return [
            ' AND (e.cdc_id IS NULL OR EXISTS (
                SELECT 1 FROM children child
                LEFT JOIN classes child_class ON child_class.id = child.class_id
                JOIN cdcs c ON c.id = e.cdc_id
                WHERE child.parent_user_id = ? AND c.status = \'active\'
                  AND (child.preferred_cdc_id = e.cdc_id OR child_class.cdc_id = e.cdc_id)
            ))',
            [(int) $user['id']],
        ];
    }

    private function event_notification_unread_count(array $user)
    {
        [$access_clause, $access_params] = $this->event_notification_access_clause($user);
        return (int) $this->db->raw(
            'SELECT COUNT(*)
             FROM notification_recipients r
             JOIN notifications n ON n.id = r.notification_id
             JOIN events e ON e.id = n.related_id
             WHERE r.user_id = ? AND r.read_at IS NULL
               AND n.related_type = \'event\' ' . $access_clause,
            array_merge([(int) $user['id']], $access_params)
        )->fetchColumn();
    }
}
