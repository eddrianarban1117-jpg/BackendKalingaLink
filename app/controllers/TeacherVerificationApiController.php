<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class TeacherVerificationApiController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->library('api');
        $this->call->model('UsersModel');
    }

    public function index()
    {
        $this->api->require_method('GET');
        $teacher = $this->authenticated_teacher();
        if (!$teacher) return;
        $this->api->respond(['status' => true, 'data' => $this->teacher_data((int) $teacher['id'])]);
    }

    public function update()
    {
        $this->api->require_method('PUT');
        $this->api->require_trusted_origin();
        $teacher = $this->authenticated_teacher();
        if (!$teacher) return;

        $this->save_profile((int) $teacher['id'], true);
    }

    public function save_draft()
    {
        $this->api->require_method('PATCH');
        $this->api->require_trusted_origin();
        $teacher = $this->authenticated_teacher();
        if (!$teacher) return;

        $this->save_profile((int) $teacher['id'], false);
    }

    private function save_profile($teacher_id, $resubmit)
    {
        $input = $this->api->body(false);
        $allowed = [
            'first_name', 'middle_name', 'last_name', 'phone', 'gender',
            'birth_date', 'nationality', 'home_address', 'education',
            'certification', 'years_of_service', 'skills', 'languages', 'cdc_id',
        ];
        if (!is_array($input) || !$input || array_diff(array_keys($input), $allowed)) {
            $this->api->respond_error('Submit corrected teacher profile fields only.', 400);
            return;
        }

        $current = $this->teacher_data($teacher_id);
        $editable_statuses = ['pending_review', 'revision_required'];
        if (!$current || !in_array($current['verification_status'], $editable_statuses, true)) {
            if ($resubmit && $current && $current['verification_status'] === 'under_review') {
                $this->api->respond([
                    'status' => true,
                    'message' => 'This teacher profile is already under review.',
                    'data' => $current,
                ]);
                return;
            }
            $this->api->respond_error('This teacher profile is not available for editing.', 403);
            return;
        }

        $user_fields = ['first_name' => 100, 'middle_name' => 100, 'last_name' => 100, 'phone' => 30];
        $profile_fields = [
            'gender' => 40,
            'nationality' => 80,
            'home_address' => 255,
            'education' => 255,
            'certification' => 2000,
            'years_of_service' => 60,
            'skills' => 2000,
            'languages' => 500,
        ];
        $user_updates = [];
        $profile_updates = [];
        foreach ($input as $field => $value) {
            if ($field === 'cdc_id') {
                if ($value === null || $value === '') {
                    $profile_updates['cdc_id'] = null;
                    continue;
                }
                $cdc_id = filter_var($value, FILTER_VALIDATE_INT);
                if (!$cdc_id || $cdc_id < 1) {
                    $this->api->respond_error('Choose a valid Child Development Center.', 400);
                    return;
                }
                $center = $this->db->raw(
                    'SELECT id FROM cdcs WHERE id = ? LIMIT 1',
                    [$cdc_id]
                )->fetch(PDO::FETCH_ASSOC);
                if (!$center) {
                    $this->api->respond_error('Choose a registered Child Development Center.', 400);
                    return;
                }
                $profile_updates['requested_cdc_id'] = (int) $cdc_id;
                continue;
            }
            if (!is_string($value)) {
                $this->api->respond_error('Teacher profile values must be text.', 400);
                return;
            }
            $value = trim($value);
            if (isset($user_fields[$field])) {
                if ($resubmit && in_array($field, ['first_name', 'last_name'], true) && $value === '') {
                    $this->api->respond_error('First and last name are required.', 400);
                    return;
                }
                if (mb_strlen($value) > $user_fields[$field]) {
                    $this->api->respond_error('A teacher account field exceeds its allowed length.', 400);
                    return;
                }
                $user_updates[$field] = $value !== ''
                    ? $value
                    : (in_array($field, ['first_name', 'last_name'], true) ? '' : null);
            } elseif ($field === 'birth_date') {
                if ($value !== '') {
                    $date = DateTime::createFromFormat('!Y-m-d', $value);
                    if (!$date || $date->format('Y-m-d') !== $value || $value > date('Y-m-d')) {
                        $this->api->respond_error('Enter a valid birthdate that is not in the future.', 400);
                        return;
                    }
                }
                $profile_updates[$field] = $value !== '' ? $value : null;
            } else {
                if (mb_strlen($value) > $profile_fields[$field]) {
                    $this->api->respond_error('A teacher profile field exceeds its allowed length.', 400);
                    return;
                }
                $profile_updates[$field] = $value !== '' ? $value : null;
            }
        }

        $merged = $current;
        foreach (array_merge($user_updates, $profile_updates) as $field => $value) {
            $merged[$field] = $value;
        }
        if ($resubmit) {
            $required = [
                'first_name' => 'First name',
                'last_name' => 'Last name',
                'phone' => 'Phone number',
                'gender' => 'Gender',
                'birth_date' => 'Date of birth',
                'nationality' => 'Nationality',
                'home_address' => 'Complete address',
                'cdc_id' => 'Assigned Child Development Center',
                'education' => 'Educational attainment',
                'years_of_service' => 'Years of service',
            ];
            foreach ($required as $field => $label) {
                if (trim((string) ($merged[$field] ?? '')) === '') {
                    $this->api->respond_error($label . ' is required before resubmission.', 400);
                    return;
                }
            }
            if (!is_numeric($merged['years_of_service']) || (float) $merged['years_of_service'] < 0) {
                $this->api->respond_error('Years of service must be a non-negative number.', 400);
                return;
            }
        }

        $changed = false;
        foreach ($user_updates as $field => $value) {
            if (($current[$field] ?? null) !== $value) $changed = true;
        }
        foreach ($profile_updates as $field => $value) {
            if (($current[$field] ?? null) !== $value) $changed = true;
        }
        if (!$changed && !$resubmit) {
            $this->api->respond([
                'status' => true,
                'message' => 'No teacher profile changes were submitted.',
                'data' => $current,
            ]);
            return;
        }

        $admin_ids = [];
        if ($resubmit) {
            $admin_ids = $this->db->raw(
                'SELECT id FROM users WHERE role = \'admin\' AND is_active = 1'
            )->fetchAll(PDO::FETCH_COLUMN);
            if (!$admin_ids) {
                $this->api->respond_error('No active MSWDO Admin reviewer is available. Please try again later.', 503);
                return;
            }
        }

        $this->db->transaction();
        try {
            $this->db->raw(
                'SELECT id FROM users WHERE id = ? FOR UPDATE',
                [$teacher_id]
            )->fetch(PDO::FETCH_ASSOC);
            $locked_profile = $this->teacher_data($teacher_id);
            if (
                !$locked_profile
                || !in_array($locked_profile['verification_status'], $editable_statuses, true)
            ) {
                $this->db->roll_back();
                if ($resubmit && $locked_profile && $locked_profile['verification_status'] === 'under_review') {
                    $this->api->respond([
                        'status' => true,
                        'message' => 'This teacher profile is already under review.',
                        'data' => $locked_profile,
                    ]);
                    return;
                }
                $this->api->respond_error('This teacher profile is no longer available for editing.', 409);
                return;
            }
            if ($user_updates) {
                $user_updates['updated_at'] = date('Y-m-d H:i:s');
                $this->db->table('users')->where('id', $teacher_id)->update($user_updates);
            }
            if ($profile_updates) {
                $profile_updates['updated_at'] = date('Y-m-d H:i:s');
                $this->db->table('teacher_profiles')->where('user_id', $teacher_id)->update($profile_updates);
            }
            if ($resubmit) {
                $name = trim(implode(' ', array_filter([
                    $merged['first_name'] ?? '',
                    $merged['middle_name'] ?? '',
                    $merged['last_name'] ?? '',
                ])));
                $this->record_review_event(
                    $teacher_id,
                    'Under Review',
                    $name . ' (TCH-' . str_pad((string) $teacher_id, 4, '0', STR_PAD_LEFT)
                        . ') resubmitted their profile for verification.',
                    array_map('intval', $admin_ids),
                    '/admin/teachers?teacherId=' . $teacher_id,
                    $teacher_id,
                    $this->teacher_data($teacher_id)
                );
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        $this->api->respond([
            'status' => true,
            'message' => $resubmit
                ? 'Your updated profile was submitted to MSWDO Admin for review.'
                : 'Your draft was saved. Your verification status has not changed.',
            'data' => $this->teacher_data($teacher_id),
        ]);
    }

    private function teacher_data($teacher_id)
    {
        $teacher = $this->db->raw(
            'SELECT u.id, u.username, u.email, u.first_name, u.middle_name,
                    u.last_name, u.phone, u.is_active, tp.gender, tp.birth_date,
                    tp.nationality, tp.home_address, tp.education, tp.certification,
                    tp.years_of_service, tp.skills, tp.languages,
                    tp.requested_cdc_id AS cdc_id,
                    latest.title AS review_status,
                    feedback.message AS review_note,
                    feedback.created_at AS reviewed_at
             FROM users u
             LEFT JOIN teacher_profiles tp ON tp.user_id = u.id
             LEFT JOIN notifications latest ON latest.id = (
                 SELECT n.id FROM notifications n
                 WHERE n.related_type = \'teacher_verification\'
                   AND n.related_id = u.id ORDER BY n.id DESC LIMIT 1
             )
             LEFT JOIN notifications feedback ON feedback.id = (
                 SELECT n.id FROM notifications n
                 WHERE n.related_type = \'teacher_verification\'
                   AND n.related_id = u.id
                   AND n.title IN (\'Revision Required\', \'Rejected\', \'Approved\')
                 ORDER BY n.id DESC LIMIT 1
             )
             WHERE u.id = ? AND u.role = \'teacher\' LIMIT 1',
            [(int) $teacher_id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$teacher) return null;
        $teacher['id'] = (int) $teacher['id'];
        $teacher['cdc_id'] = $teacher['cdc_id'] !== null ? (int) $teacher['cdc_id'] : null;
        $teacher['is_active'] = (int) $teacher['is_active'] === 1;
        $teacher['verification_status'] = strtolower(str_replace(' ', '_', $teacher['review_status'] ?: ($teacher['is_active'] ? 'Approved' : 'Pending Verification')));
        $teacher['review_history'] = $this->db->raw(
            'SELECT n.id, n.title AS status, n.message, n.created_at,
                    actor.role AS reviewer_role,
                    COALESCE(NULLIF(TRIM(CONCAT_WS(\' \', actor.first_name, actor.last_name)), \'\'), actor.role, \'KalingaLink\') AS reviewer
             FROM notifications n
             LEFT JOIN users actor ON actor.id = n.actor_user_id
             WHERE n.related_type = \'teacher_verification\' AND n.related_id = ?
             ORDER BY n.id ASC',
            [(int) $teacher_id]
        )->fetchAll(PDO::FETCH_ASSOC);
        unset($teacher['review_status']);
        return $teacher;
    }

    private function record_review_event($teacher_id, $status, $message, array $recipient_ids, $action_url = null, $actor_user_id = null, ?array $review_snapshot = null)
    {
        $this->db->table('notifications')->insert([
            'actor_user_id' => $actor_user_id !== null ? (int) $actor_user_id : (int) $teacher_id,
            'title' => $status,
            'message' => $message,
            'audience' => 'single',
            'related_type' => 'teacher_verification',
            'related_id' => (int) $teacher_id,
            'related_module' => 'teacher_management',
            'notification_type' => 'System Update',
            'action_url' => $action_url,
            'review_snapshot' => $review_snapshot
                ? json_encode($this->snapshot_fields($review_snapshot), JSON_UNESCAPED_SLASHES)
                : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $notification_id = (int) $this->db->last_id();
        foreach (array_unique(array_map('intval', $recipient_ids)) as $recipient_id) {
            if ($recipient_id < 1) continue;
            $this->db->table('notification_recipients')->insert([
                'notification_id' => $notification_id,
                'user_id' => $recipient_id,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function snapshot_fields(array $profile)
    {
        $fields = [
            'first_name', 'middle_name', 'last_name', 'phone', 'gender',
            'birth_date', 'nationality', 'home_address', 'education',
            'certification', 'years_of_service', 'skills', 'languages', 'cdc_id',
        ];
        return array_intersect_key($profile, array_flip($fields));
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
        if (!$user || $user['role'] !== 'teacher') {
            $this->api->respond_error('Teacher account was not found.', 404);
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