<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ChildTransfersApiController extends Controller
{
    private const REASONS = [
        'Change of Residence',
        'Closer to Home',
        'Parent/Guardian Request',
        'Change in Parent\'s Workplace',
        'Family Circumstances',
        'Accessibility and Transportation',
        'CDC Preference',
        'Other',
    ];

    public function __construct()
    {
        parent::__construct();
        date_default_timezone_set('Asia/Manila');
        $this->call->library('api');
        $this->call->model('UsersModel');
    }

    public function teacher_options()
    {
        $this->api->require_method('GET');
        $teacher = $this->authenticated_teacher();
        if (!$teacher) return;

        $current = $this->db->raw(
            'SELECT d.id AS cdc_id, d.name AS cdc_name,
                    TRIM(CONCAT_WS(\' \', u.first_name, u.last_name)) AS teacher_name
             FROM teacher_profiles tp
             JOIN cdcs d ON d.id = tp.cdc_id
             JOIN users u ON u.id = tp.user_id
             WHERE tp.user_id = ? AND tp.employment_status = \'active\'
               AND d.status = \'active\' AND u.is_active = 1
             LIMIT 1',
            [(int) $teacher['id']]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            $this->api->respond_error('You are not assigned to an active CDC.', 403);
            return;
        }

        $destinations = $this->db->raw(
            'SELECT d.id, d.name,
                    teacher.id AS teacher_user_id,
                    TRIM(CONCAT_WS(\' \', teacher.first_name, teacher.last_name)) AS teacher_name
             FROM cdcs d
             JOIN teacher_profiles tp ON tp.cdc_id = d.id
             JOIN users teacher ON teacher.id = tp.user_id
             WHERE d.id <> ? AND d.status = \'active\'
               AND tp.employment_status = \'active\'
               AND teacher.role = \'teacher\' AND teacher.is_active = 1
               AND EXISTS (
                   SELECT 1 FROM classes cl
                   WHERE cl.cdc_id = d.id AND cl.status = \'active\'
                     AND (cl.teacher_user_id = teacher.id OR cl.teacher_user_id IS NULL)
               )
               AND EXISTS (
                   SELECT 1 FROM notifications approval
                   WHERE approval.related_type = \'teacher_verification\'
                     AND approval.related_id = teacher.id
                     AND approval.title = \'Approved\'
               )
             ORDER BY d.name, d.id',
            [(int) $current['cdc_id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($destinations as &$destination) {
            $destination['id'] = (int) $destination['id'];
            $destination['teacher_user_id'] = (int) $destination['teacher_user_id'];
        }
        unset($destination);

        $this->api->respond([
            'status' => true,
            'data' => [
                'current_cdc_id' => (int) $current['cdc_id'],
                'current_cdc_name' => $current['cdc_name'],
                'current_teacher_name' => $current['teacher_name'],
                'destinations' => $destinations,
            ],
        ]);
    }

    public function teacher_create($child_id)
    {
        $this->api->require_method('POST');
        $teacher = $this->authenticated_teacher();
        if (!$teacher) return;
        $child_id = $this->valid_id($child_id);
        if (!$child_id) return;

        $input = $this->api->body(false);
        if (!is_array($input)) {
            $this->api->respond_error('Provide the transfer details.', 400);
            return;
        }
        if (!is_string($input['reason'] ?? null)
            || !is_string($input['other_reason'] ?? '')
            || !is_string($input['remarks'] ?? '')
            || !is_string($input['parent_confirmation'] ?? null)) {
            $this->api->respond_error('Provide valid transfer details.', 400);
            return;
        }
        $destination_id = filter_var($input['destination_cdc_id'] ?? null, FILTER_VALIDATE_INT);
        $receiving_teacher_id = filter_var($input['receiving_teacher_user_id'] ?? null, FILTER_VALIDATE_INT);
        $reason = trim((string) ($input['reason'] ?? ''));
        $other_reason = trim((string) ($input['other_reason'] ?? ''));
        $remarks = trim((string) ($input['remarks'] ?? ''));
        $parent_confirmation = $input['parent_confirmation'];
        if (!$destination_id || !$receiving_teacher_id
            || !in_array($parent_confirmation, ['yes', 'no'], true)
            || !in_array($reason, self::REASONS, true)
            || ($reason === 'Other' && $other_reason === '')
            || mb_strlen($other_reason) > 500 || mb_strlen($remarks) > 5000) {
            $this->api->respond_error('Choose a receiving CDC and teacher, record parent confirmation, and provide a valid transfer reason.', 400);
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->transaction();
        try {
            $child = $this->db->raw(
                'SELECT c.id, c.parent_user_id, c.class_id, c.enrollment_status,
                        c.first_name, c.middle_name, c.last_name,
                        cl.cdc_id AS current_cdc_id, current_cdc.name AS current_cdc_name,
                        TRIM(CONCAT_WS(\' \', current_teacher.first_name, current_teacher.last_name)) AS current_teacher_name
                 FROM children c
                 JOIN classes cl ON cl.id = c.class_id
                 JOIN cdcs current_cdc ON current_cdc.id = cl.cdc_id
                 JOIN class_students cs ON cs.child_id = c.id AND cs.class_id = c.class_id
                 JOIN teacher_profiles current_profile ON current_profile.cdc_id = cl.cdc_id
                 JOIN users current_teacher ON current_teacher.id = current_profile.user_id
                 WHERE c.id = ? AND c.enrollment_status = \'enrolled\'
                   AND cl.status = \'active\' AND current_cdc.status = \'active\'
                   AND current_profile.user_id = ?
                   AND current_profile.employment_status = \'active\'
                   AND current_teacher.role = \'teacher\' AND current_teacher.is_active = 1
                 LIMIT 1 FOR UPDATE',
                [$child_id, (int) $teacher['id']]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$child) {
                throw new DomainException('This child is not currently enrolled in one of your active classes.');
            }
            if ((int) $child['current_cdc_id'] === (int) $destination_id) {
                throw new DomainException('Choose a different receiving CDC.');
            }

            $pending = $this->db->raw(
                'SELECT id FROM child_transfer_requests
                 WHERE child_id = ? AND status IN (\'pending\', \'awaiting_receiving_cdc\')
                 LIMIT 1 FOR UPDATE',
                [$child_id]
            )->fetch(PDO::FETCH_ASSOC);
            if ($pending) {
                throw new DomainException('An older transfer request for this child is still awaiting review.');
            }

            $destination = $this->db->raw(
                'SELECT d.id AS cdc_id, d.name AS cdc_name, d.capacity AS cdc_capacity,
                        cl.id AS class_id, cl.capacity AS class_capacity,
                        teacher.id AS teacher_id,
                        TRIM(CONCAT_WS(\' \', teacher.first_name, teacher.last_name)) AS teacher_name
                 FROM cdcs d
                 JOIN teacher_profiles tp ON tp.cdc_id = d.id
                 JOIN users teacher ON teacher.id = tp.user_id
                 JOIN classes cl ON cl.cdc_id = d.id AND cl.status = \'active\'
                   AND (cl.teacher_user_id = teacher.id OR cl.teacher_user_id IS NULL)
                 WHERE d.id = ? AND teacher.id = ? AND d.status = \'active\'
                   AND tp.employment_status = \'active\'
                   AND teacher.role = \'teacher\' AND teacher.is_active = 1
                   AND EXISTS (
                       SELECT 1 FROM notifications approval
                       WHERE approval.related_type = \'teacher_verification\'
                         AND approval.related_id = teacher.id
                         AND approval.title = \'Approved\'
                   )
                 ORDER BY cl.id LIMIT 1 FOR UPDATE',
                [(int) $destination_id, (int) $receiving_teacher_id]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$destination) {
                throw new DomainException('The selected CDC has no active, approved receiving teacher and class.');
            }

            $class_count = (int) $this->db->raw(
                'SELECT COUNT(*) AS total FROM children
                 WHERE class_id = ? AND enrollment_status = \'enrolled\'',
                [(int) $destination['class_id']]
            )->fetch(PDO::FETCH_ASSOC)['total'];
            $cdc_count = (int) $this->db->raw(
                'SELECT COUNT(*) AS total
                 FROM children c
                 JOIN classes cl ON cl.id = c.class_id
                 WHERE cl.cdc_id = ? AND c.enrollment_status = \'enrolled\'',
                [(int) $destination['cdc_id']]
            )->fetch(PDO::FETCH_ASSOC)['total'];
            if ((int) $destination['class_capacity'] > 0
                && $class_count >= (int) $destination['class_capacity']) {
                throw new DomainException('The receiving class has reached its enrollment capacity.');
            }
            if ((int) $destination['cdc_capacity'] > 0
                && $cdc_count >= (int) $destination['cdc_capacity']) {
                throw new DomainException('The receiving CDC has reached its enrollment capacity.');
            }

            $this->db->table('child_transfer_requests')->insert([
                'reference' => 'TRF-' . strtoupper(bin2hex(random_bytes(5))),
                'child_id' => $child_id,
                'parent_user_id' => (int) $child['parent_user_id'],
                'previous_cdc_id' => (int) $child['current_cdc_id'],
                'destination_cdc_id' => (int) $destination['cdc_id'],
                'previous_teacher_user_id' => (int) $teacher['id'],
                'receiving_teacher_user_id' => (int) $destination['teacher_id'],
                'reason' => $reason,
                'other_reason' => $reason === 'Other' ? $other_reason : null,
                'requested_date' => date('Y-m-d'),
                'remarks' => $remarks !== '' ? $remarks : null,
                'teacher_reported_parent_confirmation' => $parent_confirmation,
                'status' => 'awaiting_receiving_cdc',
                'current_teacher_approved_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $transfer_id = (int) $this->db->last_id();
            $child_name = $this->child_name($child);
            $reason_label = $reason === 'Other' ? $other_reason : $reason;
            $this->record_event(
                $transfer_id,
                (int) $teacher['id'],
                'approved_by_current_teacher',
                $remarks
            );
            $this->notify_user(
                (int) $child['parent_user_id'],
                (int) $teacher['id'],
                'Transfer awaiting receiving teacher',
                $child_name . '\'s transfer request was submitted to '
                    . $destination['teacher_name'] . ' at ' . $destination['cdc_name']
                    . ' for acceptance. Parent/guardian confirmation as reported by the teacher: '
                    . ucfirst($parent_confirmation) . '. This records the teacher\'s report only.',
                $transfer_id,
                '/parent/children'
            );
            $this->notify_user(
                (int) $destination['teacher_id'],
                (int) $teacher['id'],
                'Incoming Child Transfer',
                $child_name . ' has been referred to your CDC by '
                    . $child['current_teacher_name'] . '. Review the student details and accept or decline the transfer.',
                $transfer_id,
                '/teacher/students'
            );
            $this->notify_admins(
                (int) $teacher['id'],
                'Child transfer awaiting acceptance',
                $child_name . ' (Child ID ' . $child_id . ') was referred from '
                    . $child['current_teacher_name'] . ' (' . $child['current_cdc_name'] . ') to '
                    . $destination['teacher_name'] . ' (' . $destination['cdc_name'] . '). Reason: '
                    . $reason_label . '. Teacher-reported parent/guardian confirmation: '
                    . ucfirst($parent_confirmation) . '.',
                $transfer_id
            );
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
            return;
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log(sprintf(
                '[teacher transfer] %s in %s:%d: %s',
                get_class($error),
                $error->getFile(),
                $error->getLine(),
                preg_replace('/[\r\n]+/', ' ', $error->getMessage())
            ));
            throw $error;
        }

        $this->api->respond([
            'status' => true,
            'message' => 'The transfer request was sent to the receiving teacher for acceptance.',
            'data' => ['transfer_id' => $transfer_id],
        ]);
    }

    public function centers()
    {
        $this->api->require_method('GET');
        if (!$this->authenticated_parent()) return;
        $centers = $this->db->raw(
            'SELECT d.id, d.name, d.address, d.barangay,
                    teacher.id AS teacher_user_id,
                    TRIM(CONCAT_WS(\' \', teacher.first_name, teacher.last_name)) AS teacher_name
             FROM cdcs d
             JOIN teacher_profiles tp ON tp.cdc_id = d.id
             JOIN users teacher ON teacher.id = tp.user_id
             WHERE d.status = \'active\' AND tp.employment_status = \'active\'
               AND teacher.role = \'teacher\' AND teacher.is_active = 1
               AND EXISTS (
                   SELECT 1 FROM classes cl
                   WHERE cl.cdc_id = d.id AND cl.status = \'active\'
               )
               AND EXISTS (
                   SELECT 1 FROM notifications approval
                   WHERE approval.related_type = \'teacher_verification\'
                     AND approval.related_id = teacher.id AND approval.title = \'Approved\'
               )
             ORDER BY d.name, d.id'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($centers as &$center) {
            $center['id'] = (int) $center['id'];
            $center['teacher_user_id'] = (int) $center['teacher_user_id'];
        }
        unset($center);
        $this->api->respond(['status' => true, 'data' => $centers]);
    }

    public function parent_history($child_id)
    {
        $this->api->require_method('GET');
        $parent = $this->authenticated_parent();
        if (!$parent) return;
        $child_id = $this->valid_id($child_id);
        if (!$child_id) return;
        $child = $this->db->table('children')
            ->select('id')
            ->where('id', $child_id)
            ->where('parent_user_id', (int) $parent['id'])
            ->get();
        if (!$child) {
            $this->api->respond_error('Child profile not found.', 404);
            return;
        }
        $requests = $this->db->raw(
            'SELECT t.id, t.reference, t.reason, t.other_reason, t.new_address,
                    t.new_barangay, t.new_municipality, t.requested_date, t.remarks,
                    t.status, t.previous_teacher_remarks, t.receiving_teacher_remarks,
                    t.current_teacher_approved_at, t.receiving_teacher_accepted_at,
                    t.completed_at, t.cancelled_at, t.created_at,
                    previous.name AS previous_cdc_name,
                    destination.name AS destination_cdc_name,
                    TRIM(CONCAT_WS(\' \', previous_teacher.first_name, previous_teacher.last_name)) AS previous_teacher_name,
                    TRIM(CONCAT_WS(\' \', receiving_teacher.first_name, receiving_teacher.last_name)) AS receiving_teacher_name
             FROM child_transfer_requests t
             JOIN cdcs previous ON previous.id = t.previous_cdc_id
             JOIN cdcs destination ON destination.id = t.destination_cdc_id
             LEFT JOIN users previous_teacher ON previous_teacher.id = t.previous_teacher_user_id
             LEFT JOIN users receiving_teacher ON receiving_teacher.id = t.receiving_teacher_user_id
             WHERE t.child_id = ? AND t.parent_user_id = ?
             ORDER BY t.id DESC',
            [$child_id, (int) $parent['id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($requests as &$request) {
            $request['documents'] = $this->documents((int) $request['id']);
            $request['events'] = $this->events((int) $request['id']);
        }
        unset($request);
        $this->api->respond(['status' => true, 'data' => $requests]);
    }

    public function create($child_id)
    {
        $this->api->require_method('POST');
        $parent = $this->authenticated_parent();
        if (!$parent) return;
        $this->api->respond_error(
            'Child transfers are processed directly by the current teacher. Please contact the teacher assigned to the child.',
            403
        );
        return;
    }

    public function cancel_parent($id)
    {
        $this->api->require_method('PATCH');
        $parent = $this->authenticated_parent();
        if (!$parent) return;
        $transfer_id = $this->valid_id($id);
        if (!$transfer_id) return;
        $this->db->transaction();
        try {
            $transfer = $this->db->raw(
                'SELECT id, child_id, previous_teacher_user_id,
                        receiving_teacher_user_id, status
                 FROM child_transfer_requests
                 WHERE id = ? AND parent_user_id = ? LIMIT 1 FOR UPDATE',
                [$transfer_id, (int) $parent['id']]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$transfer || !in_array($transfer['status'], ['pending', 'awaiting_receiving_cdc'], true)) {
                $this->db->roll_back();
                $this->api->respond_error('Only an unfinished transfer request can be cancelled by its parent.', 409);
                return;
            }
            $now = date('Y-m-d H:i:s');
            $this->db->table('child_transfer_requests')->where('id', $transfer_id)->update([
                'status' => 'cancelled_by_parent',
                'cancelled_at' => $now,
                'updated_at' => $now,
            ]);
            $this->record_event($transfer_id, (int) $parent['id'], 'cancelled_by_parent', null);
            $this->notify_user(
                (int) $transfer['previous_teacher_user_id'],
                (int) $parent['id'],
                'Transfer request cancelled',
                'The parent cancelled the pending child transfer request.',
                $transfer_id,
                '/teacher/students'
            );
            if ($transfer['receiving_teacher_user_id']) {
                $this->notify_user(
                    (int) $transfer['receiving_teacher_user_id'],
                    (int) $parent['id'],
                    'Transfer request cancelled',
                    'The parent cancelled the child transfer request.',
                    $transfer_id,
                    '/teacher/students'
                );
            }
            $this->notify_admins(
                (int) $parent['id'],
                'Child transfer request cancelled',
                'A parent cancelled a pending child transfer request.',
                $transfer_id
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        $this->api->respond(['status' => true, 'message' => 'Transfer request cancelled.']);
    }

    public function teacher_index()
    {
        $this->api->require_method('GET');
        $teacher = $this->authenticated_teacher();
        if (!$teacher) return;
        $requests = $this->db->raw(
            'SELECT t.id, t.reference, t.child_id, t.parent_user_id,
                    t.previous_cdc_id, t.destination_cdc_id,
                    t.previous_teacher_user_id, t.receiving_teacher_user_id,
                    t.reason, t.other_reason, t.new_address, t.new_barangay,
                    t.new_municipality, t.requested_date, t.remarks, t.status,
                    t.previous_teacher_remarks, t.receiving_teacher_remarks,
                    t.source_dismissed_at, t.current_teacher_approved_at,
                    t.receiving_teacher_accepted_at, t.created_at, t.completed_at,
                    c.first_name, c.middle_name, c.last_name, c.birth_date,
                    c.gender, c.nickname,
                    (c.profile_photo IS NOT NULL AND c.profile_photo <> \'\') AS has_profile_photo,
                    previous.name AS previous_cdc_name, destination.name AS destination_cdc_name,
                    TRIM(CONCAT_WS(\' \', parent.first_name, parent.last_name)) AS parent_name
             FROM child_transfer_requests t
             JOIN children c ON c.id = t.child_id
             JOIN users parent ON parent.id = t.parent_user_id
             JOIN cdcs previous ON previous.id = t.previous_cdc_id
             JOIN cdcs destination ON destination.id = t.destination_cdc_id
             WHERE ((t.previous_teacher_user_id = ? AND t.status = \'pending\'
                     AND t.source_dismissed_at IS NULL)
                 OR (t.receiving_teacher_user_id = ? AND t.status = \'awaiting_receiving_cdc\')
                 OR (t.status = \'completed\'
                     AND (t.previous_teacher_user_id = ? OR t.receiving_teacher_user_id = ?)))
             ORDER BY t.created_at, t.id',
            [
                (int) $teacher['id'],
                (int) $teacher['id'],
                (int) $teacher['id'],
                (int) $teacher['id'],
            ]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($requests as &$request) {
            $request['id'] = (int) $request['id'];
            $request['child_id'] = (int) $request['child_id'];
            $request['previous_teacher_user_id'] = $request['previous_teacher_user_id'] !== null
                ? (int) $request['previous_teacher_user_id']
                : null;
            $request['receiving_teacher_user_id'] = (int) $request['receiving_teacher_user_id'];
            $request['has_profile_photo'] = (int) $request['has_profile_photo'] === 1;
            $request['documents'] = $this->documents((int) $request['id']);
        }
        unset($request);
        $this->api->respond(['status' => true, 'data' => $requests]);
    }

    public function teacher_show($id)
    {
        $this->api->require_method('GET');
        $teacher = $this->authenticated_teacher();
        if (!$teacher) return;
        $transfer_id = $this->valid_id($id);
        if (!$transfer_id) return;
        $transfer = $this->teacher_transfer($transfer_id, (int) $teacher['id']);
        if (!$transfer) {
            $this->api->respond_error('Transfer request not found or not assigned to this teacher.', 404);
            return;
        }
        $transfer['documents'] = $this->documents($transfer_id);
        $transfer['attendance'] = $this->db->raw(
            'SELECT ar.attendance_date, ar.status, ar.time_in, ar.remarks,
                    cl.name AS class_name, d.name AS cdc_name
             FROM attendance_records ar
             JOIN classes cl ON cl.id = ar.class_id
             JOIN cdcs d ON d.id = cl.cdc_id
             WHERE ar.child_id = ? ORDER BY ar.attendance_date DESC LIMIT 100',
            [(int) $transfer['child_id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        $transfer['progress'] = $this->db->raw(
            'SELECT lp.domain, lp.skill, lp.rating, lp.observation, lp.recorded_at,
                    TRIM(CONCAT_WS(\' \', teacher.first_name, teacher.last_name)) AS teacher_name
             FROM learning_progress lp
             LEFT JOIN users teacher ON teacher.id = lp.teacher_user_id
             WHERE lp.child_id = ? ORDER BY lp.recorded_at DESC LIMIT 100',
            [(int) $transfer['child_id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        $transfer['events'] = $this->events($transfer_id);
        $this->api->respond(['status' => true, 'data' => $transfer]);
    }

    public function teacher_update($id)
    {
        $this->api->require_method('PATCH');
        $teacher = $this->authenticated_teacher();
        if (!$teacher) return;
        $transfer_id = $this->valid_id($id);
        if (!$transfer_id) return;
        $input = $this->api->body(false);
        $action = is_array($input) ? ($input['action'] ?? '') : '';
        $remarks = is_array($input) && is_string($input['remarks'] ?? null)
            ? trim(mb_substr($input['remarks'], 0, 3000))
            : '';
        if (!in_array($action, ['dismiss', 'approve', 'reject', 'decline', 'accept'], true)) {
            $this->api->respond_error('Choose a valid transfer review action.', 400);
            return;
        }
        $this->db->transaction();
        try {
            $transfer = $this->db->raw(
                'SELECT t.*, c.first_name, c.middle_name, c.last_name
                 FROM child_transfer_requests t
                 JOIN children c ON c.id = t.child_id
                 WHERE t.id = ? LIMIT 1 FOR UPDATE',
                [$transfer_id]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$transfer) {
                throw new DomainException('Transfer request not found.');
            }
            $current_reviewer = (int) $transfer['previous_teacher_user_id'] === (int) $teacher['id']
                && $transfer['status'] === 'pending'
                && $this->teacher_is_assigned((int) $teacher['id'], (int) $transfer['previous_cdc_id']);
            $receiving_reviewer = (int) $transfer['receiving_teacher_user_id'] === (int) $teacher['id']
                && $transfer['status'] === 'awaiting_receiving_cdc'
                && $this->teacher_is_assigned((int) $teacher['id'], (int) $transfer['destination_cdc_id']);
            $legacy_receiving_reviewer = (int) $transfer['receiving_teacher_user_id'] === (int) $teacher['id']
                && $transfer['status'] === 'completed'
                && $transfer['receiving_teacher_accepted_at'] === null
                && $this->teacher_is_assigned((int) $teacher['id'], (int) $transfer['destination_cdc_id']);
            $now = date('Y-m-d H:i:s');
            if ($action === 'dismiss' && $current_reviewer) {
                $this->db->table('child_transfer_requests')->where('id', $transfer_id)->update([
                    'source_dismissed_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->record_event($transfer_id, (int) $teacher['id'], 'current_teacher_review_dismissed', $remarks);
            } elseif ($action === 'reject' && $current_reviewer) {
                $this->db->table('child_transfer_requests')->where('id', $transfer_id)->update([
                    'status' => 'rejected_current_teacher',
                    'previous_teacher_remarks' => $remarks !== '' ? $remarks : null,
                    'cancelled_at' => $now,
                    'updated_at' => $now,
                ]);
                $name = $this->child_name($transfer);
                $this->record_event($transfer_id, (int) $teacher['id'], 'rejected_by_current_teacher', $remarks);
                $this->notify_user(
                    (int) $transfer['parent_user_id'],
                    (int) $teacher['id'],
                    'Transfer request rejected',
                    $name . '\'s transfer request was rejected by the current CDC teacher. The child remains enrolled at the current CDC.',
                    $transfer_id,
                    '/parent/children'
                );
                $this->notify_admins(
                    (int) $teacher['id'],
                    'Transfer request rejected',
                    $name . '\'s transfer request was rejected by the current CDC teacher.',
                    $transfer_id
                );
            } elseif ($action === 'approve' && $current_reviewer) {
                $this->db->table('child_transfer_requests')->where('id', $transfer_id)->update([
                    'status' => 'awaiting_receiving_cdc',
                    'previous_teacher_remarks' => $remarks !== '' ? $remarks : null,
                    'current_teacher_approved_at' => $now,
                    'updated_at' => $now,
                ]);
                $name = $this->child_name($transfer);
                $this->record_event($transfer_id, (int) $teacher['id'], 'approved_by_current_teacher', $remarks);
                $this->notify_user(
                    (int) $transfer['parent_user_id'],
                    (int) $teacher['id'],
                    'Transfer approved by current teacher',
                    $name . '\'s transfer request was approved and is awaiting the receiving CDC.',
                    $transfer_id,
                    '/parent/children'
                );
                $this->notify_admins(
                    (int) $teacher['id'],
                    'Transfer approved by current teacher',
                    $name . '\'s transfer request was approved and is awaiting the receiving CDC.',
                    $transfer_id
                );
                $this->notify_user(
                    (int) $transfer['receiving_teacher_user_id'],
                    (int) $teacher['id'],
                    'Incoming Child Transfer',
                    'A child transfer request has been approved by the previous CDC teacher. Please review the child information and confirm whether your CDC can accept the transfer.',
                    $transfer_id,
                    '/teacher/students'
                );
            } elseif ($action === 'decline' && $receiving_reviewer) {
                $this->db->table('child_transfer_requests')->where('id', $transfer_id)->update([
                    'status' => 'declined_receiving_cdc',
                    'receiving_teacher_remarks' => $remarks !== '' ? $remarks : null,
                    'cancelled_at' => $now,
                    'updated_at' => $now,
                ]);
                $name = $this->child_name($transfer);
                $this->record_event($transfer_id, (int) $teacher['id'], 'declined_by_receiving_cdc', $remarks);
                $this->notify_transfer_parties(
                    $transfer,
                    (int) $teacher['id'],
                    'Receiving CDC declined transfer',
                    $name . '\'s transfer request was declined by the receiving CDC. The child remains enrolled at the previous CDC.',
                    '/parent/children',
                    '/teacher/students'
                );
            } elseif ($action === 'accept' && $legacy_receiving_reviewer) {
                $this->db->table('child_transfer_requests')->where('id', $transfer_id)->update([
                    'receiving_teacher_accepted_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->record_event($transfer_id, (int) $teacher['id'], 'legacy_transfer_acknowledged', $remarks);
                $this->notify_transfer_parties(
                    $transfer,
                    (int) $teacher['id'],
                    'Transferee acknowledged',
                    $this->child_name($transfer) . ' was acknowledged by the receiving teacher. The transfer had already been completed.',
                    '/parent/children',
                    '/teacher/students'
                );
            } elseif ($action === 'accept' && $receiving_reviewer) {
                $this->complete_transfer($transfer, (int) $teacher['id'], $remarks, $now);
            } else {
                throw new DomainException('This transfer request is no longer available for that action.');
            }
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
            return;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        $this->api->respond(['status' => true, 'message' => 'Transfer request updated.']);
    }

    public function admin_index()
    {
        $this->api->require_method('GET');
        if (!$this->authenticated_admin()) return;
        $requests = $this->db->raw(
            'SELECT t.id, t.reference, t.child_id, t.parent_user_id, t.reason,
                    t.other_reason, t.requested_date, t.remarks, t.status,
                    t.previous_teacher_remarks, t.receiving_teacher_remarks,
                    t.current_teacher_approved_at, t.receiving_teacher_accepted_at,
                    t.completed_at, t.cancelled_at, t.created_at,
                    TRIM(CONCAT_WS(\' \', c.first_name, c.middle_name, c.last_name)) AS child_name,
                    TRIM(CONCAT_WS(\' \', parent.first_name, parent.last_name)) AS parent_name,
                    previous.name AS previous_cdc_name, destination.name AS destination_cdc_name,
                    TRIM(CONCAT_WS(\' \', old_teacher.first_name, old_teacher.last_name)) AS previous_teacher_name,
                    TRIM(CONCAT_WS(\' \', new_teacher.first_name, new_teacher.last_name)) AS receiving_teacher_name
             FROM child_transfer_requests t
             JOIN children c ON c.id = t.child_id
             JOIN users parent ON parent.id = t.parent_user_id
             JOIN cdcs previous ON previous.id = t.previous_cdc_id
             JOIN cdcs destination ON destination.id = t.destination_cdc_id
             LEFT JOIN users old_teacher ON old_teacher.id = t.previous_teacher_user_id
             LEFT JOIN users new_teacher ON new_teacher.id = t.receiving_teacher_user_id
             ORDER BY t.created_at DESC, t.id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($requests as &$request) {
            $request['documents'] = $this->documents((int) $request['id']);
            $request['events'] = $this->events((int) $request['id']);
        }
        unset($request);
        $this->api->respond(['status' => true, 'data' => $requests]);
    }

    public function teacher_remove($child_id)
    {
        $this->api->require_method('POST');
        $teacher = $this->authenticated_teacher();
        if (!$teacher) return;
        $child_id = $this->valid_id($child_id);
        if (!$child_id) return;

        $input = $this->api->body(false);
        if (!is_array($input)) {
            $this->api->respond_error('Provide the student removal details.', 400);
            return;
        }
        if (!is_string($input['outcome'] ?? null)
            || !is_string($input['reason_details'] ?? '')
            || !is_string($input['parent_confirmation'] ?? null)
            || !is_string($input['notes'] ?? '')) {
            $this->api->respond_error('Provide valid student removal details.', 400);
            return;
        }
        $outcome = trim((string) ($input['outcome'] ?? ''));
        $reason_details = trim((string) ($input['reason_details'] ?? ''));
        $parent_confirmation = $input['parent_confirmation'] ?? '';
        $notes = trim((string) ($input['notes'] ?? ''));
        if (!in_array($outcome, ['dropped', 'deceased', 'other'], true)
            || !in_array($parent_confirmation, ['yes', 'no'], true)
            || ($outcome === 'other' && $reason_details === '')
            || mb_strlen($reason_details) > 500 || mb_strlen($notes) > 5000) {
            $this->api->respond_error('Choose a valid removal reason and record parent/guardian confirmation.', 400);
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->transaction();
        try {
            $child = $this->db->raw(
                'SELECT c.id, c.parent_user_id, c.class_id, c.preferred_cdc_id,
                        c.enrollment_status, c.first_name, c.middle_name, c.last_name,
                        cl.cdc_id AS current_cdc_id,
                        TRIM(CONCAT_WS(\' \', current_teacher.first_name, current_teacher.last_name)) AS current_teacher_name,
                        current_cdc.name AS current_cdc_name
                 FROM children c
                 JOIN classes cl ON cl.id = c.class_id
                 JOIN cdcs current_cdc ON current_cdc.id = cl.cdc_id
                 JOIN class_students cs ON cs.child_id = c.id AND cs.class_id = c.class_id
                 JOIN teacher_profiles current_profile ON current_profile.cdc_id = cl.cdc_id
                 JOIN users current_teacher ON current_teacher.id = current_profile.user_id
                 WHERE c.id = ? AND c.enrollment_status = \'enrolled\'
                   AND cl.status = \'active\' AND current_cdc.status = \'active\'
                   AND current_profile.user_id = ?
                   AND current_profile.employment_status = \'active\'
                   AND current_teacher.role = \'teacher\' AND current_teacher.is_active = 1
                 LIMIT 1 FOR UPDATE',
                [$child_id, (int) $teacher['id']]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$child) {
                throw new DomainException('This child is not currently enrolled in one of your active classes.');
            }

            $pending = $this->db->raw(
                'SELECT id FROM child_transfer_requests
                 WHERE child_id = ? AND status IN (\'pending\', \'awaiting_receiving_cdc\')
                 LIMIT 1 FOR UPDATE',
                [$child_id]
            )->fetch(PDO::FETCH_ASSOC);
            if ($pending) {
                throw new DomainException('An older transfer request for this child is still awaiting review.');
            }

            $record_details = $outcome === 'other' ? $reason_details : null;
            $this->db->table('child_removal_records')->insert([
                'child_id' => $child_id,
                'parent_user_id' => (int) $child['parent_user_id'],
                'actor_user_id' => (int) $teacher['id'],
                'outcome' => $outcome,
                'reason_details' => $record_details,
                'parent_confirmation' => $parent_confirmation,
                'notes' => $notes !== '' ? $notes : null,
                'previous_class_id' => (int) $child['class_id'],
                'previous_cdc_id' => (int) $child['current_cdc_id'],
                'previous_teacher_user_id' => (int) $teacher['id'],
                'destination_class_id' => null,
                'destination_cdc_id' => null,
                'destination_teacher_user_id' => null,
                'created_at' => $now,
            ]);
            $record_id = (int) $this->db->last_id();
            $this->db->table('children')->where('id', $child_id)->update([
                'class_id' => null,
                'preferred_cdc_id' => null,
                'enrollment_status' => 'withdrawn',
                'updated_at' => $now,
            ]);

            $child_name = $this->child_name($child);
            $reason_label = $outcome === 'other' ? $reason_details : ucfirst($outcome);
            $confirmation = ucfirst($parent_confirmation);
            $message = $child_name . '\'s student record was marked ' . $reason_label . ' by '
                . $child['current_teacher_name'] . '. Teacher-reported parent/guardian confirmation: '
                . $confirmation . '. This records the teacher\'s report only.';
            $this->notify_removal_user(
                (int) $child['parent_user_id'],
                (int) $teacher['id'],
                'Student record updated',
                $message,
                $record_id,
                '/parent/children'
            );
            $admins = $this->db->raw(
                'SELECT id FROM users WHERE role = \'admin\' AND is_active = 1'
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($admins as $admin_id) {
                if ((int) $admin_id !== (int) $teacher['id']) {
                    $this->notify_removal_user(
                        (int) $admin_id,
                        (int) $teacher['id'],
                        'Student removal recorded',
                        $child_name . ' (Child ID ' . $child_id . ') was marked ' . $reason_label
                            . ' by ' . $child['current_teacher_name'] . ' at '
                            . $child['current_cdc_name'] . '. Teacher-reported parent/guardian confirmation: '
                            . $confirmation . '.',
                        $record_id,
                        '/admin/children'
                    );
                }
            }
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
            return;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        $this->api->respond([
            'status' => true,
            'message' => 'The student was removed from the active roster. Their profile and history were retained.',
            'data' => ['removal_id' => $record_id],
        ]);
    }

    public function admin_removals()
    {
        $this->api->require_method('GET');
        if (!$this->authenticated_admin()) return;

        $records = $this->db->raw(
            'SELECT r.id, r.child_id, r.outcome, r.reason_details, r.parent_confirmation,
                    r.notes, r.created_at,
                    TRIM(CONCAT_WS(\' \', c.first_name, c.middle_name, c.last_name)) AS child_name,
                    c.enrollment_status,
                    TRIM(CONCAT_WS(\' \', parent.first_name, parent.last_name)) AS parent_name,
                    TRIM(CONCAT_WS(\' \', actor.first_name, actor.last_name)) AS teacher_name,
                    previous.name AS previous_cdc_name,
                    destination.name AS destination_cdc_name,
                    TRIM(CONCAT_WS(\' \', receiving.first_name, receiving.last_name)) AS receiving_teacher_name
             FROM child_removal_records r
             JOIN children c ON c.id = r.child_id
             JOIN users parent ON parent.id = r.parent_user_id
             LEFT JOIN users actor ON actor.id = r.actor_user_id
             LEFT JOIN cdcs previous ON previous.id = r.previous_cdc_id
             LEFT JOIN cdcs destination ON destination.id = r.destination_cdc_id
             LEFT JOIN users receiving ON receiving.id = r.destination_teacher_user_id
             ORDER BY r.created_at DESC, r.id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($records as &$record) {
            $record['id'] = (int) $record['id'];
            $record['child_id'] = (int) $record['child_id'];
        }
        unset($record);
        $this->api->respond(['status' => true, 'data' => $records]);
    }

    public function teacher_child_history($child_id)
    {
        $this->api->require_method('GET');
        $teacher = $this->authenticated_teacher();
        if (!$teacher) return;
        $child_id = $this->valid_id($child_id);
        if (!$child_id) return;
        $requests = $this->db->raw(
            'SELECT t.id, t.reference, t.reason, t.other_reason, t.requested_date,
                    t.status, t.previous_teacher_remarks, t.receiving_teacher_remarks,
                    t.source_dismissed_at, t.created_at, t.completed_at,
                    previous.name AS previous_cdc_name, destination.name AS destination_cdc_name
             FROM child_transfer_requests t
             JOIN cdcs previous ON previous.id = t.previous_cdc_id
             JOIN cdcs destination ON destination.id = t.destination_cdc_id
             WHERE t.child_id = ?
               AND (t.previous_teacher_user_id = ? OR t.receiving_teacher_user_id = ?)
             ORDER BY t.created_at DESC, t.id DESC LIMIT 50',
            [$child_id, (int) $teacher['id'], (int) $teacher['id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($requests as &$request) {
            $request['id'] = (int) $request['id'];
            $request['documents'] = $this->documents($request['id']);
            $request['events'] = $this->events($request['id']);
        }
        unset($request);
        $this->api->respond(['status' => true, 'data' => $requests]);
    }

    public function download_document($transfer_id, $document_id)
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user(['parent', 'teacher', 'admin']);
        if (!$user) return;
        $document = $this->db->raw(
            'SELECT d.original_name, d.storage_path, d.mime_type, d.sha256,
                    t.parent_user_id, t.previous_teacher_user_id,
                    t.receiving_teacher_user_id
             FROM child_transfer_documents d
             JOIN child_transfer_requests t ON t.id = d.transfer_id
             WHERE d.id = ? AND t.id = ? LIMIT 1',
            [(int) $document_id, (int) $transfer_id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$document || !$this->can_access_transfer($user, $document)) {
            $this->api->respond_error('Transfer document not found or access denied.', 404);
            return;
        }
        $root = realpath(ROOT_DIR . 'runtime/uploads/child-transfers');
        $path = realpath(ROOT_DIR . $document['storage_path']);
        if (!$root || !$path || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
            $this->api->respond_error('Transfer document is unavailable.', 404);
            return;
        }
        $hash = hash_file('sha256', $path);
        if ($hash === false || !hash_equals($document['sha256'], $hash)) {
            $this->api->respond_error('Transfer document failed integrity verification.', 404);
            return;
        }
        header('Content-Type: application/octet-stream');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="document"; filename*=UTF-8\'\'' . rawurlencode($document['original_name']));
        readfile($path);
        exit;
    }

    private function complete_transfer(array $transfer, $teacher_id, $remarks, $now)
    {
        $child = $this->db->raw(
            'SELECT c.id, c.class_id, c.address, c.enrollment_status,
                    c.first_name, c.middle_name, c.last_name, cl.cdc_id AS current_cdc_id
             FROM children c
             LEFT JOIN classes cl ON cl.id = c.class_id
             WHERE c.id = ? LIMIT 1 FOR UPDATE',
            [(int) $transfer['child_id']]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$child || $child['enrollment_status'] !== 'enrolled'
            || (int) $child['current_cdc_id'] !== (int) $transfer['previous_cdc_id']) {
            throw new DomainException('The child is no longer enrolled at the previous CDC and cannot be transferred.');
        }
        $class = $this->db->raw(
            'SELECT cl.id, cl.name, cl.cdc_id, cl.capacity AS class_capacity,
                    d.capacity
             FROM classes cl
             JOIN cdcs d ON d.id = cl.cdc_id
             JOIN teacher_profiles tp ON tp.cdc_id = cl.cdc_id
             JOIN users u ON u.id = tp.user_id
             WHERE cl.cdc_id = ? AND cl.status = \'active\' AND d.status = \'active\'
               AND tp.user_id = ? AND tp.employment_status = \'active\' AND u.is_active = 1
               AND (cl.teacher_user_id = u.id OR cl.teacher_user_id IS NULL)
             ORDER BY cl.id LIMIT 1 FOR UPDATE',
            [(int) $transfer['destination_cdc_id'], $teacher_id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$class) {
            throw new DomainException('The receiving CDC no longer has an active class assigned to you.');
        }
        $class_enrolled_count = (int) $this->db->raw(
            'SELECT COUNT(*) AS total FROM children
             WHERE class_id = ? AND enrollment_status = \'enrolled\'',
            [(int) $class['id']]
        )->fetch(PDO::FETCH_ASSOC)['total'];
        if ((int) $class['class_capacity'] > 0
            && $class_enrolled_count >= (int) $class['class_capacity']) {
            throw new DomainException('The receiving class has reached its enrollment capacity.');
        }
        $enrolled_count = (int) $this->db->raw(
            'SELECT COUNT(*) AS total
             FROM children c
             JOIN classes cl ON cl.id = c.class_id
             WHERE cl.cdc_id = ? AND c.enrollment_status = \'enrolled\'',
            [(int) $class['cdc_id']]
        )->fetch(PDO::FETCH_ASSOC)['total'];
        if ((int) $class['capacity'] > 0 && $enrolled_count >= (int) $class['capacity']) {
            throw new DomainException('The receiving CDC has reached its enrollment capacity.');
        }
        $new_address_parts = array_filter([
            $transfer['new_address'] ?? null,
            $transfer['new_barangay'] ?? null,
            $transfer['new_municipality'] ?? null,
        ], static function ($part) {
            return is_string($part) && trim($part) !== '';
        });
        $child_updates = [
            'class_id' => (int) $class['id'],
            'preferred_cdc_id' => (int) $class['cdc_id'],
            'updated_at' => $now,
        ];
        if ($new_address_parts) {
            $child_updates['address'] = implode(', ', array_map('trim', $new_address_parts));
        }
        $this->db->table('children')->where('id', (int) $child['id'])->update($child_updates);
        $existing = $this->db->table('class_students')
            ->select('id')
            ->where('class_id', (int) $class['id'])
            ->where('child_id', (int) $child['id'])
            ->get();
        if (!$existing) {
            $this->db->table('class_students')->insert([
                'class_id' => (int) $class['id'],
                'child_id' => (int) $child['id'],
                'enrolled_at' => $now,
            ]);
        }
        $this->db->table('child_transfer_requests')->where('id', (int) $transfer['id'])->update([
            'status' => 'completed',
            'receiving_teacher_remarks' => $remarks !== '' ? $remarks : null,
            'receiving_teacher_accepted_at' => $now,
            'completed_at' => $now,
            'updated_at' => $now,
        ]);
        if (in_array($transfer['teacher_reported_parent_confirmation'] ?? null, ['yes', 'no'], true)) {
            $notes = array_filter([
                trim((string) ($transfer['remarks'] ?? '')),
                trim((string) $remarks),
            ], static function ($note) {
                return $note !== '';
            });
            $this->db->table('child_removal_records')->insert([
                'child_id' => (int) $child['id'],
                'parent_user_id' => (int) $transfer['parent_user_id'],
                'actor_user_id' => $transfer['previous_teacher_user_id']
                    ? (int) $transfer['previous_teacher_user_id']
                    : $teacher_id,
                'outcome' => 'transfer',
                'reason_details' => $transfer['reason'] === 'Other'
                    ? ($transfer['other_reason'] ?? 'Other')
                    : $transfer['reason'],
                'parent_confirmation' => $transfer['teacher_reported_parent_confirmation'],
                'notes' => $notes ? implode("\n\n", $notes) : null,
                'previous_class_id' => (int) $child['class_id'],
                'previous_cdc_id' => (int) $transfer['previous_cdc_id'],
                'previous_teacher_user_id' => $transfer['previous_teacher_user_id']
                    ? (int) $transfer['previous_teacher_user_id']
                    : null,
                'destination_class_id' => (int) $class['id'],
                'destination_cdc_id' => (int) $class['cdc_id'],
                'destination_teacher_user_id' => $teacher_id,
                'created_at' => $now,
            ]);
        }
        $name = $this->child_name($child);
        $this->record_event((int) $transfer['id'], $teacher_id, 'accepted_by_receiving_cdc', $remarks);
        $this->record_event((int) $transfer['id'], $teacher_id, 'transfer_completed', null);
        $this->notify_transfer_parties(
            $transfer,
            $teacher_id,
            'Child transfer completed',
            $name . ' has been transferred to the receiving CDC.',
            '/parent/children',
            '/teacher/students'
        );
    }

    private function teacher_transfer($id, $teacher_id)
    {
        return $this->db->raw(
            'SELECT t.*, c.first_name, c.middle_name, c.last_name, c.suffix,
                    c.nickname, c.birth_date, c.gender, c.address, c.blood_type,
                    c.allergies, c.medical_notes, c.immunization_status,
                    c.psa_birth_certificate_number, c.nationality, c.place_of_birth,
                    c.enrollment_status,
                    (c.profile_photo IS NOT NULL AND c.profile_photo <> \'\') AS has_profile_photo,
                    parent.phone AS parent_phone, parent.email AS parent_email,
                    parent.first_name AS parent_first_name, parent.last_name AS parent_last_name,
                    pp.relationship AS parent_relationship, pp.address AS parent_address,
                    pp.emergency_name, pp.emergency_relationship, pp.emergency_phone,
                    previous.name AS previous_cdc_name, destination.name AS destination_cdc_name,
                    old_teacher.first_name AS previous_teacher_first_name,
                    old_teacher.last_name AS previous_teacher_last_name,
                    new_teacher.first_name AS receiving_teacher_first_name,
                    new_teacher.last_name AS receiving_teacher_last_name
             FROM child_transfer_requests t
             JOIN children c ON c.id = t.child_id
             JOIN users parent ON parent.id = t.parent_user_id
             LEFT JOIN parent_profiles pp ON pp.user_id = parent.id
             JOIN cdcs previous ON previous.id = t.previous_cdc_id
             JOIN cdcs destination ON destination.id = t.destination_cdc_id
             LEFT JOIN users old_teacher ON old_teacher.id = t.previous_teacher_user_id
             LEFT JOIN users new_teacher ON new_teacher.id = t.receiving_teacher_user_id
             WHERE t.id = ? AND (t.previous_teacher_user_id = ? OR t.receiving_teacher_user_id = ?)
             LIMIT 1',
            [$id, $teacher_id, $teacher_id]
        )->fetch(PDO::FETCH_ASSOC);
    }

    private function teacher_is_assigned($teacher_id, $cdc_id)
    {
        return (bool) $this->db->raw(
            'SELECT 1
             FROM teacher_profiles tp
             JOIN users u ON u.id = tp.user_id
             WHERE tp.user_id = ? AND tp.cdc_id = ?
               AND tp.employment_status = \'active\'
               AND u.role = \'teacher\' AND u.is_active = 1
             LIMIT 1',
            [$teacher_id, $cdc_id]
        )->fetch(PDO::FETCH_ASSOC);
    }

    private function documents($transfer_id)
    {
        $documents = $this->db->raw(
            'SELECT id, original_name, mime_type, file_size, uploaded_at
             FROM child_transfer_documents WHERE transfer_id = ? ORDER BY id',
            [$transfer_id]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($documents as &$document) {
            $document['id'] = (int) $document['id'];
            $document['file_size'] = (int) $document['file_size'];
            $document['download_url'] = '/api/child-transfers/' . $transfer_id
                . '/documents/' . $document['id'];
        }
        unset($document);
        return $documents;
    }

    private function events($transfer_id)
    {
        return $this->db->raw(
            'SELECT e.id, e.event_type, e.remarks, e.created_at,
                    TRIM(CONCAT_WS(\' \', u.first_name, u.last_name)) AS actor_name,
                    u.role AS actor_role
             FROM child_transfer_events e
             LEFT JOIN users u ON u.id = e.actor_user_id
             WHERE e.transfer_id = ? ORDER BY e.id',
            [$transfer_id]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function record_event($transfer_id, $actor_id, $type, $remarks)
    {
        $this->db->table('child_transfer_events')->insert([
            'transfer_id' => (int) $transfer_id,
            'actor_user_id' => $actor_id ? (int) $actor_id : null,
            'event_type' => $type,
            'remarks' => $remarks !== '' ? $remarks : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function notify_transfer_parties(array $transfer, $actor_id, $title, $message, $parent_url, $teacher_url)
    {
        $this->notify_user(
            (int) $transfer['parent_user_id'],
            (int) $actor_id,
            $title,
            $message,
            (int) $transfer['id'],
            $parent_url
        );
        foreach (array_unique(array_filter([
            (int) ($transfer['previous_teacher_user_id'] ?? 0),
            (int) ($transfer['receiving_teacher_user_id'] ?? 0),
        ])) as $user_id) {
            if ($user_id !== (int) $actor_id) {
                $this->notify_user($user_id, (int) $actor_id, $title, $message, (int) $transfer['id'], $teacher_url);
            }
        }
        $this->notify_admins((int) $actor_id, $title, $message, (int) $transfer['id']);
    }

    private function notify_admins($actor_id, $title, $message, $transfer_id)
    {
        $admins = $this->db->raw(
            'SELECT id FROM users WHERE role = \'admin\' AND is_active = 1'
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $admin_id) {
            if ((int) $admin_id !== (int) $actor_id) {
                $this->notify_user((int) $admin_id, (int) $actor_id, $title, $message, $transfer_id, '/admin/centers');
            }
        }
    }

    private function notify_user($user_id, $actor_id, $title, $message, $transfer_id, $action_url)
    {
        if (!$user_id) return;
        $this->db->table('notifications')->insert([
            'actor_user_id' => $actor_id ?: null,
            'title' => $title,
            'message' => $message,
            'audience' => 'single',
            'related_type' => 'child_transfer',
            'related_id' => (int) $transfer_id,
            'related_module' => 'child_transfers',
            'notification_type' => 'System Update',
            'action_url' => $action_url,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $notification_id = (int) $this->db->last_id();
        $this->db->table('notification_recipients')->insert([
            'notification_id' => $notification_id,
            'user_id' => (int) $user_id,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function notify_removal_user($user_id, $actor_id, $title, $message, $record_id, $action_url)
    {
        if (!$user_id) return;
        $this->db->table('notifications')->insert([
            'actor_user_id' => $actor_id ?: null,
            'title' => $title,
            'message' => $message,
            'audience' => 'single',
            'related_type' => 'child_removal',
            'related_id' => (int) $record_id,
            'related_module' => 'child_removals',
            'notification_type' => 'System Update',
            'action_url' => $action_url,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $notification_id = (int) $this->db->last_id();
        $this->db->table('notification_recipients')->insert([
            'notification_id' => $notification_id,
            'user_id' => (int) $user_id,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function authenticated_parent()
    {
        $user = $this->authenticated_user(['parent']);
        if ($user) return $user;
        return null;
    }

    private function authenticated_teacher()
    {
        $user = $this->authenticated_user(['teacher']);
        if (!$user) return null;
        $teacher_access = $this->call->library('TeacherAccess', $this->db);
        if (!$teacher_access->is_active((int) $user['id'])) {
            $this->api->respond_error('Teacher account approval and invitation acceptance are required.', 403);
            return null;
        }
        return $user;
    }

    private function authenticated_admin()
    {
        return $this->authenticated_user(['admin']);
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
            $this->api->respond_error('You are not authorized to access this transfer.', 403);
            return null;
        }
        return $user;
    }

    private function can_access_transfer(array $user, array $transfer)
    {
        if ($user['role'] === 'admin') return true;
        if ($user['role'] === 'parent') {
            return (int) $user['id'] === (int) $transfer['parent_user_id'];
        }
        return $user['role'] === 'teacher'
            && in_array((int) $user['id'], [
                (int) $transfer['previous_teacher_user_id'],
                (int) $transfer['receiving_teacher_user_id'],
            ], true);
    }

    private function child_name(array $child)
    {
        return trim(implode(' ', array_filter([
            $child['first_name'] ?? '',
            $child['middle_name'] ?? '',
            $child['last_name'] ?? '',
        ])));
    }

    private function valid_id($value)
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            $this->api->respond_error('Invalid transfer request.', 400);
            return null;
        }
        return (int) $id;
    }

    private function valid_date($value)
    {
        if (!is_string($value)) return false;
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value;
    }
}
