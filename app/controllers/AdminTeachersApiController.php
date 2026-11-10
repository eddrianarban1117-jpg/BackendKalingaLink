<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminTeachersApiController extends Controller
{
    private $cdc_status;

    public function __construct()
    {
        parent::__construct();
        date_default_timezone_set('Asia/Manila');
        $this->call->library('api');
        $this->cdc_status = $this->call->library('CdcStatus', $this->db);
        $this->call->model('UsersModel');
    }

    public function index()
    {
        $this->api->require_method('GET');
        $admin = $this->authenticated_admin();
        if (!$admin) return;

        $stats = $this->db->raw(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN w.status IN (\'active\', \'approved_awaiting_acceptance\') THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN w.status = \'pending_review\' THEN 1 ELSE 0 END) AS pending_review,
                SUM(CASE WHEN w.status = \'under_review\' THEN 1 ELSE 0 END) AS under_review,
                SUM(CASE WHEN w.status = \'revision_required\' THEN 1 ELSE 0 END) AS revision_required,
                SUM(CASE WHEN w.status = \'rejected\' THEN 1 ELSE 0 END) AS rejected,
                SUM(CASE WHEN tp.cdc_id IS NOT NULL THEN 1 ELSE 0 END) AS assigned
             FROM users u
             LEFT JOIN teacher_profiles tp ON tp.user_id = u.id
             LEFT JOIN teacher_account_verification w ON w.teacher_user_id = u.id
             WHERE u.role = \'teacher\''
        )->fetch(PDO::FETCH_ASSOC);

        $teachers = $this->db->raw(
            'SELECT u.id, u.username, u.email, u.first_name, u.middle_name,
                    u.last_name, u.phone, u.created_at, u.is_active,
                    tp.gender, tp.birth_date, tp.home_address, tp.education,
                    tp.certification, tp.nationality, tp.years_of_service,
                    tp.skills, tp.languages, tp.position, tp.employment_status,
                    tp.employee_number, COALESCE(d.name, \'Unassigned\') AS center_name,
                    requested_center.name AS requested_center_name,
                    v.title AS review_status, v.message AS review_note,
                    v.created_at AS reviewed_at, w.status AS workflow_status,
                    invite.email_status, invite.email_error,
                    invite.sent_at AS message_sent_at
             FROM users u
             LEFT JOIN teacher_profiles tp ON tp.user_id = u.id
             LEFT JOIN cdcs d ON d.id = tp.cdc_id
             LEFT JOIN cdcs requested_center ON requested_center.id = tp.requested_cdc_id
             LEFT JOIN teacher_account_verification w ON w.teacher_user_id = u.id
             LEFT JOIN notifications v ON v.id = (
                 SELECT latest.id FROM notifications latest
                 WHERE latest.related_type = \'teacher_verification\'
                   AND latest.related_id = u.id ORDER BY latest.id DESC LIMIT 1
             )
             LEFT JOIN teacher_account_invitations invite ON invite.id = (
                 SELECT latest_invite.id FROM teacher_account_invitations latest_invite
                 WHERE latest_invite.teacher_user_id = u.id
                 ORDER BY latest_invite.id DESC LIMIT 1
             )
             WHERE u.role = \'teacher\'
             ORDER BY u.last_name, u.first_name, u.id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $position_rows = $this->db->raw(
            'SELECT COALESCE(NULLIF(TRIM(tp.position), \'\'), \'Unspecified\') AS name,
                    COUNT(*) AS total
             FROM users u
             LEFT JOIN teacher_profiles tp ON tp.user_id = u.id
             WHERE u.role = \'teacher\'
             GROUP BY name
             ORDER BY total DESC, name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $gender_rows = $this->db->raw(
            'SELECT tp.gender, COUNT(*) AS total
             FROM users u
             LEFT JOIN teacher_profiles tp ON tp.user_id = u.id
             WHERE u.role = \'teacher\'
             GROUP BY tp.gender'
        )->fetchAll(PDO::FETCH_ASSOC);
        $gender = ['male' => 0, 'female' => 0, 'other' => 0];
        foreach ($gender_rows as $row) {
            $value = strtolower(trim((string) ($row['gender'] ?? '')));
            if ($value === 'male') {
                $gender['male'] += (int) $row['total'];
            } elseif ($value === 'female') {
                $gender['female'] += (int) $row['total'];
            } else {
                $gender['other'] += (int) $row['total'];
            }
        }

        $activity_rows = $this->db->raw(
            'SELECT u.first_name, u.middle_name, u.last_name, d.name AS center_name,
                    u.created_at
             FROM users u
             LEFT JOIN teacher_profiles tp ON tp.user_id = u.id
             LEFT JOIN cdcs d ON d.id = tp.cdc_id
             LEFT JOIN cdcs requested_center ON requested_center.id = tp.requested_cdc_id
             WHERE u.role = \'teacher\'
             ORDER BY u.created_at DESC, u.id DESC
             LIMIT 5'
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->api->respond([
            'status' => true,
            'data' => [
                'stats' => array_map('intval', $stats),
                'teachers' => array_map(static function ($teacher) {
                    $status = $teacher['workflow_status'] ?: 'pending_review';
                    if ($status === 'active' && (int) $teacher['is_active'] !== 1) {
                        $status = 'deactivated';
                    }
                    return [
                        'id' => (int) $teacher['id'],
                        'name' => trim(implode(' ', array_filter([
                            $teacher['first_name'],
                            $teacher['middle_name'],
                            $teacher['last_name'],
                        ]))),
                        'gender' => $teacher['gender'] ?: 'Unspecified',
                        'position' => trim((string) $teacher['position']) ?: 'Unspecified',
                        'center' => $teacher['center_name'],
                        'requested_center' => $teacher['requested_center_name'],
                        'email' => $teacher['email'],
                        'phone' => $teacher['phone'] ?? '',
                        'birth_date' => $teacher['birth_date'],
                        'address' => $teacher['home_address'],
                        'education' => $teacher['education'],
                        'certification' => $teacher['certification'],
                        'nationality' => $teacher['nationality'],
                        'years_of_service' => $teacher['years_of_service'],
                        'skills' => $teacher['skills'],
                        'languages' => $teacher['languages'],
                        'employee_number' => $teacher['employee_number'],
                        'account_active' => (int) $teacher['is_active'] === 1,
                        'status' => $status,
                        'review_note' => $teacher['review_note'],
                        'reviewed_at' => $teacher['reviewed_at'],
                        'email_status' => $teacher['email_status'],
                        'message_sent_at' => $teacher['message_sent_at'],
                        'email_error' => $teacher['email_error'],
                        'created_at' => $teacher['created_at'],
                    ];
                }, $teachers),
                'positions' => array_map(static function ($position) {
                    return [
                        'name' => $position['name'],
                        'count' => (int) $position['total'],
                    ];
                }, $position_rows),
                'gender' => $gender,
                'activities' => array_map(static function ($activity) {
                    return [
                        'name' => trim(implode(' ', array_filter([
                            $activity['first_name'],
                            $activity['middle_name'],
                            $activity['last_name'],
                        ]))),
                        'center' => $activity['center_name'] ?: 'Unassigned',
                        'created_at' => $activity['created_at'],
                    ];
                }, $activity_rows),
            ],
        ]);
    }

    public function show($id)
    {
        $this->api->require_method('GET');
        if (!$this->authenticated_admin()) return;
        $teacher = $this->find_teacher($id);
        if (!$teacher) {
            $this->api->respond_error('Teacher account was not found.', 404);
            return;
        }
        $history = $this->db->raw(
            'SELECT n.id, n.title AS status, n.message, n.created_at, n.review_snapshot,
                    actor.role AS reviewer_role,
                    COALESCE(NULLIF(TRIM(CONCAT_WS(\' \', actor.first_name, actor.last_name)), \'\'), actor.role, \'KalingaLink\') AS reviewer
             FROM notifications n
             LEFT JOIN users actor ON actor.id = n.actor_user_id
             WHERE n.related_type = \'teacher_verification\' AND n.related_id = ?
             ORDER BY n.id ASC',
            [(int) $teacher['id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        $documents = $this->db->raw(
            'SELECT id, original_name, mime_type, file_size, uploaded_at
             FROM teacher_verification_documents
             WHERE teacher_user_id = ? ORDER BY id ASC',
            [(int) $teacher['id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($documents as &$document) {
            $document['id'] = (int) $document['id'];
            $document['file_size'] = (int) $document['file_size'];
            $document['download_url'] = '/api/admin/teachers/' . (int) $teacher['id']
                . '/documents/' . $document['id'];
        }
        unset($document);
        $teacher['review_history'] = $history;
        $teacher['documents'] = $documents;
        $this->api->respond(['status' => true, 'data' => $teacher]);
    }

    public function download_document($teacher_id, $document_id)
    {
        $this->api->require_method('GET');
        if (!$this->authenticated_admin()) return;
        $document = $this->db->raw(
            'SELECT d.original_name, d.storage_path, d.mime_type, d.sha256
             FROM teacher_verification_documents d
             INNER JOIN users u ON u.id = d.teacher_user_id
             WHERE d.id = ? AND d.teacher_user_id = ? AND u.role = \'teacher\'
             LIMIT 1',
            [(int) $document_id, (int) $teacher_id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$document) {
            $this->api->respond_error('Teacher document was not found.', 404);
            return;
        }

        $storage_root = realpath(ROOT_DIR . 'runtime/uploads/teacher-verification');
        $file_path = realpath(ROOT_DIR . $document['storage_path']);
        if (!$storage_root || !$file_path
            || strpos($file_path, $storage_root . DIRECTORY_SEPARATOR) !== 0
            || !is_file($file_path)) {
            $this->api->respond_error('Teacher document is unavailable or failed integrity verification.', 404);
            return;
        }
        $actual_hash = hash_file('sha256', $file_path);
        if ($actual_hash === false || !hash_equals($document['sha256'], $actual_hash)) {
            $this->api->respond_error('Teacher document is unavailable or failed integrity verification.', 404);
            return;
        }

        header('Content-Type: application/octet-stream');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($file_path));
        header('Content-Disposition: attachment; filename="document"; filename*=UTF-8\'\'' . rawurlencode($document['original_name']));
        readfile($file_path);
        exit;
    }

    public function review($id)
    {
        $this->api->require_method('PATCH');
        $admin = $this->authenticated_admin();
        if (!$admin) return;
        $teacher = $this->find_teacher($id);
        if (!$teacher) {
            $this->api->respond_error('Teacher account was not found.', 404);
            return;
        }

        $input = $this->api->body(false);
        $action = is_array($input) ? ($input['action'] ?? '') : '';
        $note = is_array($input) && is_string($input['note'] ?? null)
            ? trim(mb_substr($input['note'], 0, 3000))
            : '';
        if (!in_array($action, ['approve', 'reject'], true)) {
            $this->api->respond_error('Choose approve or reject.', 400);
            return;
        }
        if ($action === 'reject' && $note === '') {
            $this->api->respond_error('Enter a reason for rejecting the teacher registration.', 400);
            return;
        }

        $workflow_status = $action === 'approve'
            ? 'approved_awaiting_acceptance'
            : 'rejected';
        $status = $action === 'approve'
            ? 'Approved'
            : 'Rejected';
        if ($teacher['verification_status'] === $workflow_status
            && ($action === 'approve'
                || trim((string) ($teacher['review_note'] ?? '')) === $note)) {
            $this->api->respond([
                'status' => true,
                'message' => 'The same teacher review decision is already recorded.',
                'data' => [
                    'id' => (int) $teacher['id'],
                    'verification_status' => $workflow_status,
                    'is_active' => false,
                    'email_delivery' => [
                        'sent' => $teacher['email_status'] === 'sent',
                        'reason' => $teacher['email_status'] === 'sent'
                            ? null
                            : ($teacher['email_error'] ?? 'No duplicate email was sent.'),
                    ],
                ],
            ]);
            return;
        }
        if ($action === 'approve' && $teacher['verification_status'] === 'active') {
            $this->api->respond_error('This teacher account has already been activated.', 409);
            return;
        }
        $reviewer = trim(implode(' ', array_filter([
            $admin['first_name'] ?? '',
            $admin['last_name'] ?? '',
        ]))) ?: 'MSWDO Admin';
        $message = $action === 'approve'
            ? 'Your teacher application has been approved. Your account will be available after the approval message is sent.'
            : $note;
        $token = $action === 'approve' ? bin2hex(random_bytes(32)) : null;
        $invitation_id = null;
        $now = date('Y-m-d H:i:s');

        $this->db->transaction();
        try {
            $current_workflow = $this->db->raw(
                'SELECT status FROM teacher_account_verification
                 WHERE teacher_user_id = ? LIMIT 1 FOR UPDATE',
                [(int) $teacher['id']]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$current_workflow) {
                throw new RuntimeException('Teacher verification workflow record is missing.');
            }
            if ($action === 'approve'
                && $current_workflow['status'] === 'approved_awaiting_acceptance') {
                $this->db->roll_back();
                $this->api->respond_error('This teacher is already approved. Send the approval message instead.', 409);
                return;
            }
            $this->db->table('users')->where('id', (int) $teacher['id'])->update([
                'is_active' => 0,
                'updated_at' => $now,
            ]);
            $workflow_update = [
                'status' => $workflow_status,
                'updated_at' => $now,
            ];
            if ($action === 'approve') {
                $workflow_update['approved_by'] = (int) $admin['id'];
                $workflow_update['approved_at'] = $now;
                $workflow_update['rejected_by'] = null;
                $workflow_update['rejected_at'] = null;
                $workflow_update['rejection_remarks'] = null;
            } elseif ($action === 'reject') {
                $workflow_update['approved_by'] = null;
                $workflow_update['approved_at'] = null;
                $workflow_update['rejected_by'] = (int) $admin['id'];
                $workflow_update['rejected_at'] = $now;
                $workflow_update['rejection_remarks'] = $note;
            }
            $this->db->table('teacher_account_verification')
                ->where('teacher_user_id', (int) $teacher['id'])
                ->update($workflow_update);
            $this->db->table(config_item('refresh_token_table'))
                ->where('user_id', (int) $teacher['id'])
                ->delete();
            $this->db->raw(
                'UPDATE teacher_account_invitations
                 SET invalidated_at = ?
                 WHERE teacher_user_id = ? AND invalidated_at IS NULL',
                [$now, (int) $teacher['id']]
            );
            if ($action === 'approve') {
                $this->db->table('teacher_account_invitations')->insert([
                    'teacher_user_id' => (int) $teacher['id'],
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => date('Y-m-d H:i:s', time() + 7 * 86400),
                    'email_status' => 'pending',
                    'created_by' => (int) $admin['id'],
                    'created_at' => $now,
                ]);
                $invitation_id = (int) $this->db->last_id();
            }
            $this->db->table('notifications')->insert([
                'actor_user_id' => (int) $admin['id'],
                'title' => $status,
                'message' => $message,
                'audience' => 'single',
                'related_type' => 'teacher_verification',
                'related_id' => (int) $teacher['id'],
                'related_module' => 'teacher_management',
                'notification_type' => 'System Update',
                'action_url' => '/login',
                'review_snapshot' => json_encode([
                    'first_name' => $teacher['first_name'],
                    'middle_name' => $teacher['middle_name'] ?? null,
                    'last_name' => $teacher['last_name'],
                    'phone' => $teacher['phone'],
                    'gender' => $teacher['gender'],
                    'birth_date' => $teacher['birth_date'],
                    'nationality' => $teacher['nationality'],
                    'home_address' => $teacher['address'],
                    'education' => $teacher['education'],
                    'certification' => $teacher['certification'],
                    'years_of_service' => $teacher['years_of_service'],
                    'skills' => $teacher['skills'],
                    'languages' => $teacher['languages'],
                    'cdc_id' => $teacher['cdc_id'],
                ], JSON_UNESCAPED_SLASHES),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $notification_id = (int) $this->db->last_id();
            $this->db->table('notification_recipients')->insert([
                'notification_id' => $notification_id,
                'user_id' => (int) $teacher['id'],
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $center_id = $this->cdc_status->unassign_teacher((int) $teacher['id']);
            $this->db->raw(
                'UPDATE enrollment_applications a
                 JOIN children c ON c.id = a.child_id
                 SET a.assigned_teacher_user_id = NULL
                 WHERE a.assigned_teacher_user_id = ?
                   AND a.status IN (\'pending\', \'under_review\')
                   AND c.enrollment_status <> \'enrolled\'',
                [(int) $teacher['id']]
            );
            if ($center_id !== null) {
                $this->cdc_status->sync($center_id);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        $email_delivery = $action === 'approve'
            ? $this->send_approval_message($teacher, $invitation_id)
            : $this->send_review_email($teacher, $status, $note);
        $verification_status = $action === 'approve' && $email_delivery['sent']
            ? 'active'
            : $workflow_status;
        $this->api->respond([
            'status' => true,
            'message' => 'Teacher review saved.',
            'data' => [
                'id' => (int) $teacher['id'],
                'verification_status' => $verification_status,
                'is_active' => $verification_status === 'active',
                'email_delivery' => $email_delivery,
                'reviewer' => $reviewer,
            ],
        ]);
    }

    public function send_message($id)
    {
        $this->api->require_method('POST');
        $admin = $this->authenticated_admin();
        if (!$admin) return;
        $this->api->rate_limit('teacher-approval-message', 5, 3600);
        $teacher = $this->find_teacher($id);
        if (!$teacher) {
            $this->api->respond_error('Teacher account was not found.', 404);
            return;
        }
        if (!in_array($teacher['verification_status'], ['approved_awaiting_acceptance', 'active'], true)
            || ($teacher['verification_status'] === 'active' && !$teacher['is_active'])
            || ($teacher['verification_status'] === 'approved_awaiting_acceptance' && $teacher['is_active'])) {
            $this->api->respond_error('Only approved teacher accounts can receive an approval message.', 409);
            return;
        }

        $token = bin2hex(random_bytes(32));
        $now = date('Y-m-d H:i:s');
        $this->db->transaction();
        try {
            $current_workflow = $this->db->raw(
                'SELECT v.status, u.is_active
                 FROM teacher_account_verification v
                 JOIN users u ON u.id = v.teacher_user_id
                 WHERE v.teacher_user_id = ? LIMIT 1 FOR UPDATE',
                [(int) $teacher['id']]
            )->fetch(PDO::FETCH_ASSOC);
            $is_awaiting_message = $current_workflow
                && $current_workflow['status'] === 'approved_awaiting_acceptance'
                && (int) $current_workflow['is_active'] === 0;
            $is_active = $current_workflow
                && $current_workflow['status'] === 'active'
                && (int) $current_workflow['is_active'] === 1;
            if (!$is_awaiting_message && !$is_active) {
                $this->db->roll_back();
                $this->api->respond_error('This teacher account is no longer eligible for an approval message.', 409);
                return;
            }
            $this->db->raw(
                'UPDATE teacher_account_invitations
                 SET invalidated_at = ?
                 WHERE teacher_user_id = ? AND invalidated_at IS NULL',
                [$now, (int) $teacher['id']]
            );
            $this->db->table('teacher_account_invitations')->insert([
                'teacher_user_id' => (int) $teacher['id'],
                'token_hash' => hash('sha256', $token),
                'expires_at' => date('Y-m-d H:i:s', time() + 7 * 86400),
                'email_status' => 'pending',
                'created_by' => (int) $admin['id'],
                'created_at' => $now,
            ]);
            $invitation_id = (int) $this->db->last_id();
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        $email_delivery = $this->send_approval_message($teacher, $invitation_id);
        $current_teacher = $this->find_teacher($teacher['id']);
        $this->api->respond([
            'status' => true,
            'message' => $email_delivery['sent']
                ? 'The approval message was sent.'
                : 'The approval message could not be sent. Teacher account access remains disabled.',
            'data' => [
                'id' => (int) $teacher['id'],
                'verification_status' => $current_teacher['verification_status'],
                'is_active' => $current_teacher['is_active'],
                'email_delivery' => $email_delivery,
            ],
        ]);
    }

    private function find_teacher($id)
    {
        $teacher_id = filter_var($id, FILTER_VALIDATE_INT);
        if (!$teacher_id || $teacher_id < 1) return false;
        $teacher = $this->db->raw(
            'SELECT u.id, u.username, u.email, u.first_name, u.middle_name,
                    u.last_name, u.phone, u.is_active, u.created_at,
                    tp.gender, tp.birth_date, tp.home_address AS address,
                    tp.nationality, tp.cdc_id, tp.education, tp.certification,
                    tp.years_of_service, tp.skills, tp.languages, tp.position,
                    tp.employee_number, tp.employment_status,
                    COALESCE(d.name, \'Unassigned\') AS center,
                    requested_center.name AS requested_center,
                    v.title AS review_status, v.message AS review_note,
                    v.created_at AS reviewed_at, w.status AS workflow_status,
                    invite.email_status, invite.email_error,
                    invite.sent_at AS message_sent_at
             FROM users u
             LEFT JOIN teacher_profiles tp ON tp.user_id = u.id
             LEFT JOIN cdcs d ON d.id = tp.cdc_id
             LEFT JOIN cdcs requested_center ON requested_center.id = tp.requested_cdc_id
             LEFT JOIN teacher_account_verification w ON w.teacher_user_id = u.id
             LEFT JOIN notifications v ON v.id = (
                 SELECT latest.id FROM notifications latest
                 WHERE latest.related_type = \'teacher_verification\'
                   AND latest.related_id = u.id ORDER BY latest.id DESC LIMIT 1
             )
             LEFT JOIN teacher_account_invitations invite ON invite.id = (
                 SELECT latest_invite.id FROM teacher_account_invitations latest_invite
                 WHERE latest_invite.teacher_user_id = u.id
                 ORDER BY latest_invite.id DESC LIMIT 1
             )
             WHERE u.id = ? AND u.role = \'teacher\' LIMIT 1',
            [(int) $teacher_id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$teacher) return false;
        $teacher['id'] = (int) $teacher['id'];
        $teacher['is_active'] = (int) $teacher['is_active'] === 1;
        $teacher['name'] = trim(implode(' ', array_filter([
            $teacher['first_name'], $teacher['middle_name'], $teacher['last_name'],
        ])));
        $teacher['verification_status'] = $teacher['workflow_status'] ?: 'pending_review';
        $teacher['email_status'] = $teacher['email_status'] ?: null;
        $teacher['message_sent_at'] = $teacher['message_sent_at'] ?: null;
        $teacher['status'] = $teacher['verification_status'] === 'active' && !$teacher['is_active']
            ? 'deactivated'
            : $teacher['verification_status'];
        $teacher['account_active'] = $teacher['is_active'];
        $teacher['requested_center'] = $teacher['requested_center'] ?: null;
        unset($teacher['review_status'], $teacher['workflow_status']);
        return $teacher;
    }

    private function send_review_email(array $teacher, $status, $note)
    {
        try {
            $name = $this->teacher_name($teacher);
            $subject = 'Update Regarding Your KalingaLink Teacher Account Application';
            $text = "Dear {$name},\n\n"
                . "UPDATE ON YOUR TEACHER ACCOUNT APPLICATION\n\n"
                . "Thank you for your interest in becoming part of KalingaLink.\n\n"
                . "After carefully reviewing your submitted information and requirements, "
                . "we regret to inform you that your Teacher Account application has not been approved "
                . "by the KalingaLink Administration.\n\n"
                . "REASON FOR REJECTION\n\n{$note}\n\n"
                . "We understand that this may be disappointing. However, the decision was made based on "
                . "the verification and evaluation of the information and documents submitted.\n\n"
                . "WHAT'S NEXT?\n\n"
                . "Please review the reason for rejection provided above. If you believe that the decision "
                . "was made based on incomplete or incorrect information, you may contact the KalingaLink "
                . "Administration for further clarification.\n\n"
                . "If your application can be reconsidered, please follow the instructions provided by the "
                . "administration regarding the necessary corrections or requirements.\n\n"
                . "Thank you for your understanding and interest in KalingaLink.\n\n"
                . "Best regards,\n\n"
                . "KalingaLink Administration\n"
                . "Municipal Social Welfare and Development Office (MSWDO)\n"
                . "Pinamalayan, Oriental Mindoro";
            $safe_name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
            $safe_note = nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8'));
            $html = '<p>Dear ' . $safe_name . ',</p>'
                . '<h2>UPDATE ON YOUR TEACHER ACCOUNT APPLICATION</h2>'
                . '<p>Thank you for your interest in becoming part of KalingaLink.</p>'
                . '<p>After carefully reviewing your submitted information and requirements, we regret to inform you that your Teacher Account application has <strong>not been approved</strong> by the KalingaLink Administration.</p>'
                . '<h2>REASON FOR REJECTION</h2><p>' . $safe_note . '</p>'
                . '<p>We understand that this may be disappointing. However, the decision was made based on the verification and evaluation of the information and documents submitted.</p>'
                . '<h2>WHAT\'S NEXT?</h2>'
                . '<p>Please review the reason for rejection provided above. If you believe that the decision was made based on incomplete or incorrect information, you may contact the KalingaLink Administration for further clarification.</p>'
                . '<p>If your application can be reconsidered, please follow the instructions provided by the administration regarding the necessary corrections or requirements.</p>'
                . '<p>Thank you for your understanding and interest in KalingaLink.</p>'
                . '<p>Best regards,<br><strong>KalingaLink Administration</strong><br>'
                . 'Municipal Social Welfare and Development Office (MSWDO)<br>'
                . 'Pinamalayan, Oriental Mindoro</p>';
            $this->send_mail($teacher['email'], $subject, $text, $html);
            return ['sent' => true, 'reason' => null];
        } catch (Throwable $error) {
            error_log('Teacher review email failed (' . get_class($error) . ').');
            return ['sent' => false, 'reason' => 'The email provider could not send the review email. Check the server mail configuration.'];
        }
    }

    private function send_approval_message(array $teacher, $message_id)
    {
        $reason = 'The email provider could not send the approval message. Check the server mail configuration.';
        try {
            $name = $this->teacher_name($teacher);
            $username = (string) ($teacher['username'] ?? '');
            $subject = 'Congratulations! Your KalingaLink Teacher Account Has Been Approved';
            $text = "Dear {$name},\n\n"
                . "CONGRATULATIONS!\n\n"
                . "We are pleased to inform you that your Teacher Account has been successfully reviewed "
                . "and approved by the KalingaLink Administration.\n\n"
                . "After carefully reviewing your submitted information and requirements, we are happy to "
                . "welcome you as an officially approved teacher of KalingaLink!\n\n"
                . "YOUR ACCOUNT CREDENTIALS\n\n"
                . "Username: {$username}\n"
                . "Password: Use the password you confirmed during registration. For your security, it is not included in this email.\n\n"
                . "Best regards,\n\n"
                . "KalingaLink Administration\n"
                . "Municipal Social Welfare and Development Office (MSWDO)\n"
                . "Pinamalayan, Oriental Mindoro";
            $safe_name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
            $safe_username = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
            $html = '<p>Dear ' . $safe_name . ',</p>'
                . '<h2>CONGRATULATIONS!</h2>'
                . '<p>We are pleased to inform you that your Teacher Account has been successfully reviewed and approved by the KalingaLink Administration.</p>'
                . '<p>After carefully reviewing your submitted information and requirements, we are happy to welcome you as an officially approved teacher of KalingaLink!</p>'
                . '<h2>YOUR ACCOUNT CREDENTIALS</h2>'
                . '<p>Username: ' . $safe_username . '</p>'
                . '<p>Password: Use the password you confirmed during registration. For your security, it is not included in this email.</p>'
                . '<p>Best regards,<br><strong>KalingaLink Administration</strong><br>'
                . 'Municipal Social Welfare and Development Office (MSWDO)<br>'
                . 'Pinamalayan, Oriental Mindoro</p>';
            $this->send_mail($teacher['email'], $subject, $text, $html);
            $this->db->transaction();
            try {
                $workflow = $this->db->raw(
                    'SELECT v.status, u.is_active
                     FROM teacher_account_verification v
                     JOIN users u ON u.id = v.teacher_user_id
                     WHERE v.teacher_user_id = ? LIMIT 1 FOR UPDATE',
                    [(int) $teacher['id']]
                )->fetch(PDO::FETCH_ASSOC);
                if (!$workflow
                    || !in_array($workflow['status'], ['approved_awaiting_acceptance', 'active'], true)) {
                    throw new RuntimeException('The teacher approval state changed before the message was recorded.');
                }
                if ($workflow['status'] === 'approved_awaiting_acceptance') {
                    $this->db->table('users')->where('id', (int) $teacher['id'])->update([
                        'is_active' => 1,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    $this->db->table('teacher_account_verification')
                        ->where('teacher_user_id', (int) $teacher['id'])
                        ->update([
                            'status' => 'active',
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                    $this->db->table(config_item('refresh_token_table'))
                        ->where('user_id', (int) $teacher['id'])
                        ->delete();
                } elseif ((int) $workflow['is_active'] !== 1) {
                    throw new RuntimeException('The teacher account is not active.');
                }
                $now = date('Y-m-d H:i:s');
                $this->db->raw(
                    'UPDATE teacher_account_invitations
                     SET invalidated_at = ?
                     WHERE teacher_user_id = ? AND invalidated_at IS NULL',
                    [$now, (int) $teacher['id']]
                );
                $this->db->table('teacher_account_invitations')
                    ->where('id', (int) $message_id)
                    ->update([
                        'email_status' => 'sent',
                        'sent_at' => $now,
                        'email_error' => null,
                    ]);
                $this->db->commit();
            } catch (Throwable $error) {
                $this->db->roll_back();
                throw $error;
            }
            return ['sent' => true, 'reason' => null];
        } catch (Throwable $error) {
            $error_message = preg_replace('/[\r\n]+/', ' ', $error->getMessage());
            error_log(
                'Teacher approval message failed (' . get_class($error) . '): '
                . substr((string) $error_message, 0, 240)
            );
            $this->db->table('teacher_account_invitations')->where('id', (int) $message_id)->update([
                'email_status' => 'failed',
                'email_error' => $reason,
            ]);
            return ['sent' => false, 'reason' => $reason];
        }
    }

    private function send_mail($recipient, $subject, $text, $html)
    {
        $mailer = $this->call->library('SmtpMailer');
        $mailer->send($recipient, $subject, $text, $html);
    }

    private function teacher_name(array $teacher)
    {
        return trim(implode(' ', array_filter([
            $teacher['first_name'] ?? '',
            $teacher['middle_name'] ?? '',
            $teacher['last_name'] ?? '',
        ])));
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
            $this->api->respond_error('You are not authorized to view teacher records.', 403);
            return null;
        }
        return $user;
    }
}
