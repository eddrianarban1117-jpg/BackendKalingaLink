<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminCentersApiController extends Controller
{
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
        $admin = $this->authenticated_admin();
        if (!$admin) return;

        $cdc_names = CdcStatus::REQUIRED_CDC_NAMES;
        $cdc_placeholders = implode(', ', array_fill(0, count($cdc_names), '?'));

        $stats = $this->db->raw(
            'SELECT
                (SELECT COUNT(*) FROM cdcs d
                 WHERE BINARY TRIM(d.name) IN (' . $cdc_placeholders . ')) AS total_centers,
                (SELECT COUNT(*) FROM children WHERE enrollment_status <> \'withdrawn\') AS total_children,
                (SELECT COUNT(*) FROM users u
                 JOIN teacher_profiles tp ON tp.user_id = u.id
                 JOIN notifications review ON review.id = (
                     SELECT latest.id FROM notifications latest
                     WHERE latest.related_type = \'teacher_verification\'
                       AND latest.related_id = u.id ORDER BY latest.id DESC LIMIT 1
                 )
                 WHERE u.role = \'teacher\' AND u.is_active = 1
                   AND tp.employment_status = \'active\' AND review.title = \'Approved\') AS total_teachers,
                (SELECT COUNT(*) FROM cdcs d
                 WHERE BINARY TRIM(d.name) IN (' . $cdc_placeholders . ')
                   AND EXISTS (
                     SELECT 1 FROM teacher_profiles tp
                     JOIN users u ON u.id = tp.user_id
                     JOIN notifications review ON review.id = (
                         SELECT latest.id FROM notifications latest
                         WHERE latest.related_type = \'teacher_verification\'
                           AND latest.related_id = u.id ORDER BY latest.id DESC LIMIT 1
                     )
                     WHERE tp.cdc_id = d.id AND tp.employment_status = \'active\'
                       AND u.role = \'teacher\' AND u.is_active = 1 AND review.title = \'Approved\'
                 )) AS operating_centers,
                (SELECT COUNT(*) FROM cdcs d
                 WHERE BINARY TRIM(d.name) IN (' . $cdc_placeholders . ')
                   AND NOT EXISTS (
                     SELECT 1 FROM teacher_profiles tp
                     JOIN users u ON u.id = tp.user_id
                     JOIN notifications review ON review.id = (
                         SELECT latest.id FROM notifications latest
                         WHERE latest.related_type = \'teacher_verification\'
                           AND latest.related_id = u.id ORDER BY latest.id DESC LIMIT 1
                     )
                     WHERE tp.cdc_id = d.id AND tp.employment_status = \'active\'
                       AND u.role = \'teacher\' AND u.is_active = 1 AND review.title = \'Approved\'
                 )) AS inactive_centers,
                0 AS pending_activation_centers',
            array_merge($cdc_names, $cdc_names, $cdc_names)
        )->fetch(PDO::FETCH_ASSOC);

        $centers = $this->db->raw(
            'SELECT d.id, d.name, d.address, d.barangay, d.center_head,
                    d.contact_number, d.capacity,
                    CASE WHEN assigned_review.id IS NOT NULL THEN \'active\' ELSE \'inactive\' END AS status,
                    CASE WHEN assigned_review.id IS NOT NULL
                         THEN assigned.id ELSE NULL END AS assigned_teacher_user_id,
                    CASE WHEN assigned_review.id IS NOT NULL
                         THEN TRIM(CONCAT_WS(\' \', assigned.first_name, assigned.last_name))
                         ELSE NULL END AS assigned_teacher_name,
                    (SELECT COUNT(DISTINCT c.id)
                     FROM children c
                     LEFT JOIN classes cl ON cl.id = c.class_id
                     WHERE c.enrollment_status = \'enrolled\'
                       AND COALESCE(c.preferred_cdc_id, cl.cdc_id) = d.id) AS enrolled_children,
                    (SELECT COUNT(*)
                     FROM teacher_profiles tp
                     JOIN users u ON u.id = tp.user_id
                     JOIN notifications review ON review.id = (
                         SELECT latest.id FROM notifications latest
                         WHERE latest.related_type = \'teacher_verification\'
                           AND latest.related_id = u.id ORDER BY latest.id DESC LIMIT 1
                     )
                     WHERE tp.cdc_id = d.id
                       AND tp.employment_status = \'active\'
                       AND u.role = \'teacher\' AND u.is_active = 1
                       AND review.title = \'Approved\') AS active_teachers
             FROM cdcs d
             LEFT JOIN teacher_profiles tp ON tp.cdc_id = d.id
             LEFT JOIN users assigned ON assigned.id = tp.user_id
                 AND assigned.role = \'teacher\' AND assigned.is_active = 1
                 AND tp.employment_status = \'active\'
             LEFT JOIN notifications assigned_review ON assigned_review.id = (
                 SELECT latest.id FROM notifications latest
                 WHERE latest.related_type = \'teacher_verification\'
                   AND latest.related_id = assigned.id ORDER BY latest.id DESC LIMIT 1
             ) AND assigned_review.title = \'Approved\'
             WHERE BINARY TRIM(d.name) IN (' . $cdc_placeholders . ')
             ORDER BY d.name, d.id',
            $cdc_names
        )->fetchAll(PDO::FETCH_ASSOC);

        $eligible_teachers = $this->db->raw(
            'SELECT u.id, u.first_name, u.middle_name, u.last_name, tp.cdc_id
             FROM users u
             JOIN teacher_profiles tp ON tp.user_id = u.id
             JOIN notifications review ON review.id = (
                 SELECT latest.id FROM notifications latest
                 WHERE latest.related_type = \'teacher_verification\'
                   AND latest.related_id = u.id ORDER BY latest.id DESC LIMIT 1
             )
             WHERE u.role = \'teacher\' AND u.is_active = 1
               AND tp.employment_status = \'active\' AND review.title = \'Approved\'
               AND (tp.cdc_id IS NULL OR EXISTS (
                   SELECT 1 FROM cdcs assigned_cdc
                   WHERE assigned_cdc.id = tp.cdc_id
                     AND BINARY TRIM(assigned_cdc.name) IN (' . $cdc_placeholders . ')
               ))
             ORDER BY u.last_name, u.first_name, u.id',
            $cdc_names
        )->fetchAll(PDO::FETCH_ASSOC);

        $activities = $this->db->raw(
            'SELECT activity_type, title, description, occurred_at
             FROM (
                SELECT \'enrollment\' AS activity_type,
                       \'New enrollment application\' AS title,
                       CONCAT(c.first_name, \' \', c.last_name, \' — \', d.name) AS description,
                       a.submitted_at AS occurred_at
                FROM enrollment_applications a
                JOIN children c ON c.id = a.child_id
                JOIN cdcs d ON d.id = a.cdc_id
                WHERE BINARY TRIM(d.name) IN (' . $cdc_placeholders . ')

                UNION ALL

                SELECT \'teacher\' AS activity_type,
                       \'Teacher assigned to center\' AS title,
                       CONCAT(u.first_name, \' \', u.last_name, \' — \', d.name) AS description,
                       tp.created_at AS occurred_at
                FROM teacher_profiles tp
                JOIN users u ON u.id = tp.user_id
                JOIN cdcs d ON d.id = tp.cdc_id
                WHERE u.role = \'teacher\'
                  AND BINARY TRIM(d.name) IN (' . $cdc_placeholders . ')

                UNION ALL

                SELECT \'center\' AS activity_type,
                       \'Center added\' AS title,
                       CONCAT(d.name, \' — \', COALESCE(NULLIF(d.barangay, \'\'), d.address, \'Location not provided\')) AS description,
                       d.created_at AS occurred_at
                FROM cdcs d
                WHERE BINARY TRIM(d.name) IN (' . $cdc_placeholders . ')

                UNION ALL

                SELECT \'center\' AS activity_type,
                       \'Center updated\' AS title,
                       CONCAT(d.name, \' — \', COALESCE(NULLIF(d.barangay, \'\'), d.address, \'Location not provided\')) AS description,
                       d.updated_at AS occurred_at
                FROM cdcs d
                WHERE d.updated_at IS NOT NULL
                  AND d.updated_at > d.created_at
                  AND BINARY TRIM(d.name) IN (' . $cdc_placeholders . ')
             ) recent
             WHERE occurred_at IS NOT NULL
             ORDER BY occurred_at DESC
             LIMIT 8',
            array_merge($cdc_names, $cdc_names, $cdc_names, $cdc_names)
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->api->respond([
            'status' => true,
            'data' => [
                'stats' => array_map('intval', $stats),
                'centers' => array_map(static function ($center) {
                    return [
                        'id' => (int) $center['id'],
                        'name' => $center['name'],
                        'address' => $center['address'] ?? '',
                        'barangay' => $center['barangay'] ?? '',
                        'center_head' => $center['center_head'] ?? '',
                        'contact_number' => $center['contact_number'] ?? '',
                        'capacity' => (int) $center['capacity'],
                        'status' => $center['status'],
                        'children' => (int) $center['enrolled_children'],
                        'teachers' => (int) $center['active_teachers'],
                        'assigned_teacher_user_id' => $center['assigned_teacher_user_id'] !== null
                            ? (int) $center['assigned_teacher_user_id']
                            : null,
                        'assigned_teacher_name' => $center['assigned_teacher_name'] ?: null,
                        'enrollment_available' => $center['status'] === 'active'
                            && $center['assigned_teacher_user_id'] !== null,
                    ];
                }, $centers),
                'eligible_teachers' => array_map(static function ($teacher) {
                    return [
                        'id' => (int) $teacher['id'],
                        'name' => trim(implode(' ', array_filter([
                            $teacher['first_name'],
                            $teacher['middle_name'],
                            $teacher['last_name'],
                        ]))),
                        'cdc_id' => $teacher['cdc_id'] !== null ? (int) $teacher['cdc_id'] : null,
                    ];
                }, $eligible_teachers),
                'activities' => array_map(static function ($activity) {
                    return [
                        'type' => $activity['activity_type'],
                        'title' => $activity['title'],
                        'description' => $activity['description'],
                        'occurred_at' => $activity['occurred_at'],
                    ];
                }, $activities),
            ],
        ]);
    }

    public function create()
    {
        $this->api->require_method('POST');
        $admin = $this->authenticated_admin();
        if (!$admin) return;

        $center = $this->validated_center($this->api->body(false));
        if (!$center) return;
        $canonical_name = $this->cdc_status->canonical_name($center['name']);
        if ($canonical_name === null) {
            $this->api->respond_error(
                'Only the three configured Child Development Centers can be created.',
                409
            );
            return;
        }
        $center['name'] = $canonical_name;
        $this->db->transaction();
        try {
            $existing_centers = $this->db->raw(
                'SELECT id, name FROM cdcs ORDER BY id FOR UPDATE'
            )->fetchAll(PDO::FETCH_ASSOC);
            if (count($existing_centers) >= count(CdcStatus::REQUIRED_CDC_NAMES)) {
                $this->db->roll_back();
                $this->api->respond_error('The system already has the maximum of three Child Development Centers.', 409);
                return;
            }
            if ($this->center_name_exists($center['name'])) {
                $this->db->roll_back();
                $this->api->respond_error('A center with this name already exists.', 409);
                return;
            }
            $this->db->table('cdcs')->insert(array_merge($center, [
                'status' => 'inactive',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => null,
            ]));
            $center_id = (int) $this->db->last_id();
            $this->db->commit();
        } catch (PDOException $error) {
            $this->db->roll_back();
            if ($error->getCode() === '23000') {
                $this->api->respond_error('A center with this name already exists.', 409);
                return;
            }
            throw $error;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        $this->api->respond([
            'status' => true,
            'message' => 'Center created.',
            'id' => $center_id,
        ], 201);
    }

    public function update($id)
    {
        $this->api->require_method('PUT');
        $admin = $this->authenticated_admin();
        if (!$admin) return;

        $id = filter_var($id, FILTER_VALIDATE_INT);
        if (!$id || !$this->center_exists((int) $id)) {
            $this->api->respond_error('Center not found.', 404);
            return;
        }

        $center = $this->validated_center($this->api->body(false));
        if (!$center) return;
        $current = $this->db->table('cdcs')
            ->select('name')
            ->where('id', (int) $id)
            ->get();
        if (
            !$this->cdc_status->is_required_name($current['name'] ?? '')
            || $center['name'] !== $current['name']
        ) {
            $this->api->respond_error('CDC names are fixed and cannot be changed.', 409);
            return;
        }

        try {
            $this->db->table('cdcs')
                ->where('id', (int) $id)
                ->update(array_merge($center, [
                    'updated_at' => date('Y-m-d H:i:s'),
                ]));
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                $this->api->respond_error('A center with this name already exists.', 409);
                return;
            }
            throw $error;
        }

        $this->api->respond([
            'status' => true,
            'message' => 'Center updated.',
        ]);
    }

    public function update_status($id)
    {
        $this->api->require_method('PATCH');
        if (!$this->authenticated_admin()) return;
        $this->api->respond_error('CDC status is determined by approved, active teacher assignments.', 409);
    }

    public function assign_teacher($id)
    {
        $this->api->require_method('PATCH');
        $admin = $this->authenticated_admin();
        if (!$admin) return;

        $id = filter_var($id, FILTER_VALIDATE_INT);
        $input = $this->api->body(false);
        if (!is_array($input) || !array_key_exists('teacher_id', $input)) {
            $this->api->respond_error('A teacher_id field is required; use null to unassign.', 400);
            return;
        }
        $teacher_id = isset($input['teacher_id']) && $input['teacher_id'] !== ''
            ? filter_var($input['teacher_id'], FILTER_VALIDATE_INT)
            : null;
        if (!$id || ($teacher_id !== null && (!$teacher_id || $teacher_id < 1))) {
            $this->api->respond_error('Choose a valid center and teacher.', 400);
            return;
        }

        $this->db->transaction();
        try {
            $center = $this->db->raw(
                'SELECT id, name, status FROM cdcs WHERE id = ? FOR UPDATE',
                [(int) $id]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$center) {
                $this->db->roll_back();
                $this->api->respond_error('Center not found.', 404);
                return;
            }

            $current = $this->db->raw(
                'SELECT user_id FROM teacher_profiles WHERE cdc_id = ? FOR UPDATE',
                [(int) $id]
            )->fetch(PDO::FETCH_ASSOC);
            $current_teacher_id = $current ? (int) $current['user_id'] : null;
            if ($current_teacher_id === $teacher_id) {
                $this->cdc_status->sync((int) $id);
                $this->db->commit();
                $this->api->respond([
                    'status' => true,
                    'message' => 'The teacher assignment is unchanged.',
                ]);
                return;
            }

            if ($teacher_id !== null) {
                $teacher = $this->db->raw(
                    'SELECT u.id FROM users u
                     JOIN teacher_profiles tp ON tp.user_id = u.id
                     JOIN notifications review ON review.id = (
                         SELECT latest.id FROM notifications latest
                         WHERE latest.related_type = \'teacher_verification\'
                           AND latest.related_id = u.id ORDER BY latest.id DESC LIMIT 1
                     )
                     WHERE u.id = ? AND u.role = \'teacher\' AND u.is_active = 1
                       AND tp.employment_status = \'active\' AND review.title = \'Approved\'
                     FOR UPDATE',
                    [(int) $teacher_id]
                )->fetch(PDO::FETCH_ASSOC);
                if (!$teacher) {
                    $this->db->roll_back();
                    $this->api->respond_error('Only approved, active teachers can be assigned to a CDC.', 409);
                    return;
                }
                $other_center = $this->db->raw(
                    'SELECT cdc_id FROM teacher_profiles WHERE user_id = ? FOR UPDATE',
                    [(int) $teacher_id]
                )->fetch(PDO::FETCH_ASSOC);
                if ($other_center && $other_center['cdc_id'] !== null
                    && (int) $other_center['cdc_id'] !== (int) $id) {
                    $this->db->roll_back();
                    $this->api->respond_error('This teacher is already assigned to another CDC.', 409);
                    return;
                }
            }

            if ($current_teacher_id !== null) {
                $this->db->table('teacher_profiles')
                    ->where('user_id', $current_teacher_id)
                    ->update(['cdc_id' => null, 'updated_at' => date('Y-m-d H:i:s')]);
            }
            if ($teacher_id !== null) {
                $this->db->table('teacher_profiles')
                    ->where('user_id', (int) $teacher_id)
                    ->update(['cdc_id' => (int) $id, 'updated_at' => date('Y-m-d H:i:s')]);
            }
            $this->cdc_status->sync((int) $id);

            $open_applications = $this->db->raw(
                'SELECT a.id, a.parent_user_id
                 FROM enrollment_applications a
                 JOIN children c ON c.id = a.child_id
                 WHERE a.cdc_id = ? AND a.status IN (\'pending\', \'under_review\')
                   AND c.enrollment_status <> \'enrolled\'',
                [(int) $id]
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($open_applications as $application) {
                $this->db->table('enrollment_applications')
                    ->where('id', (int) $application['id'])
                    ->update(['assigned_teacher_user_id' => $teacher_id]);
                if ($teacher_id !== null) {
                    $this->notify_user(
                        (int) $teacher_id,
                        'Enrollment application assigned',
                        'An open application for ' . $center['name'] . ' is now assigned to you.',
                        'enrollment',
                        (int) $application['id'],
                        '/teacher/enrollment'
                    );
                }
                $this->notify_user(
                    (int) $application['parent_user_id'],
                    'CDC teacher assignment updated',
                    $center['name'] . ' has updated its assigned teacher. Your application remains with this CDC.',
                    'enrollment',
                    (int) $application['id'],
                    '/parent/application-status'
                );
            }
            foreach (array_filter([$current_teacher_id, $teacher_id]) as $recipient_id) {
                $assigned_name = $teacher_id === null
                    ? 'no teacher'
                    : ($this->teacher_name((int) $teacher_id) ?: 'the assigned teacher');
                $this->notify_user(
                    (int) $recipient_id,
                    'CDC assignment updated',
                    'The teacher assignment for ' . $center['name'] . ' has changed to ' . $assigned_name . '.',
                    'cdc_assignment',
                    (int) $id,
                    '/teacher/dashboard'
                );
            }
            $this->notify_admins(
                (int) $admin['id'],
                'CDC assignment changed',
                $center['name'] . ' teacher assignment has been updated.',
                (int) $id
            );
            $this->db->commit();
        } catch (PDOException $error) {
            $this->db->roll_back();
            if ($error->getCode() === '23000') {
                $this->api->respond_error('The teacher or CDC is already assigned elsewhere.', 409);
                return;
            }
            throw $error;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        $this->api->respond([
            'status' => true,
            'message' => $teacher_id === null ? 'Teacher unassigned.' : 'Teacher assigned to the CDC.',
        ]);
    }

    public function affected_enrollments($id)
    {
        $this->api->require_method('GET');
        if (!$this->authenticated_admin()) return;
        $id = filter_var($id, FILTER_VALIDATE_INT);
        if (!$id || !$this->center_exists((int) $id)) {
            $this->api->respond_error('Center not found.', 404);
            return;
        }
        $applications = $this->db->raw(
            'SELECT a.id, a.application_number, a.status, a.submitted_at,
                    a.documents_requested_at, c.id AS child_id,
                    TRIM(CONCAT_WS(\' \', c.first_name, c.middle_name, c.last_name)) AS child_name,
                    TRIM(CONCAT_WS(\' \', parent.first_name, parent.last_name)) AS parent_name,
                    d.name AS cdc_name, a.assigned_teacher_user_id,
                    TRIM(CONCAT_WS(\' \', teacher.first_name, teacher.last_name)) AS teacher_name,
                    c.enrollment_status AS child_enrollment_status
             FROM enrollment_applications a
             JOIN children c ON c.id = a.child_id
             JOIN users parent ON parent.id = a.parent_user_id
             JOIN cdcs d ON d.id = a.cdc_id
             LEFT JOIN users teacher ON teacher.id = a.assigned_teacher_user_id
             WHERE a.cdc_id = ?
             ORDER BY a.submitted_at DESC, a.id DESC',
            [(int) $id]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($applications as &$application) {
            $application['transfer_allowed'] = in_array($application['status'], ['pending', 'under_review'], true)
                && $application['child_enrollment_status'] !== 'enrolled';
            $application['transfer_block_reason'] = $application['child_enrollment_status'] === 'enrolled'
                ? 'Already enrolled; use the separate child transfer process.'
                : ($application['status'] === 'approved'
                    ? 'Approved applications require administrative review before transfer.'
                    : (in_array($application['status'], ['rejected'], true)
                        ? 'Rejected applications are not transferred.'
                        : null));
        }
        unset($application);
        $destinations = $this->available_destinations();
        $this->api->respond([
            'status' => true,
            'data' => ['applications' => $applications, 'destinations' => $destinations],
        ]);
    }

    public function transfer_history($id)
    {
        $this->api->require_method('GET');
        if (!$this->authenticated_admin()) return;
        $id = filter_var($id, FILTER_VALIDATE_INT);
        if (!$id || !$this->center_exists((int) $id)) {
            $this->api->respond_error('Center not found.', 404);
            return;
        }
        $history = $this->db->raw(
            'SELECT t.id, t.application_id, a.application_number, t.child_id,
                    TRIM(CONCAT_WS(\' \', c.first_name, c.middle_name, c.last_name)) AS child_name,
                    t.previous_cdc_id, previous.name AS previous_cdc_name,
                    t.destination_cdc_id, destination.name AS destination_cdc_name,
                    t.previous_teacher_user_id,
                    TRIM(CONCAT_WS(\' \', old_teacher.first_name, old_teacher.last_name)) AS previous_teacher_name,
                    t.new_teacher_user_id,
                    TRIM(CONCAT_WS(\' \', new_teacher.first_name, new_teacher.last_name)) AS new_teacher_name,
                    t.reason, t.transferred_at, t.admin_user_id,
                    TRIM(CONCAT_WS(\' \', admin.first_name, admin.last_name)) AS admin_name
             FROM cdc_enrollment_transfers t
             JOIN enrollment_applications a ON a.id = t.application_id
             JOIN children c ON c.id = t.child_id
             JOIN cdcs previous ON previous.id = t.previous_cdc_id
             JOIN cdcs destination ON destination.id = t.destination_cdc_id
             LEFT JOIN users old_teacher ON old_teacher.id = t.previous_teacher_user_id
             JOIN users new_teacher ON new_teacher.id = t.new_teacher_user_id
             JOIN users admin ON admin.id = t.admin_user_id
             WHERE t.previous_cdc_id = ? OR t.destination_cdc_id = ?
             ORDER BY t.transferred_at DESC, t.id DESC',
            [(int) $id, (int) $id]
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->api->respond(['status' => true, 'data' => $history]);
    }

    public function transfer_application($application_id)
    {
        $this->api->require_method('POST');
        $admin = $this->authenticated_admin();
        if (!$admin) return;
        $application_id = filter_var($application_id, FILTER_VALIDATE_INT);
        $input = $this->api->body(false);
        $destination_id = is_array($input) ? filter_var($input['destination_cdc_id'] ?? null, FILTER_VALIDATE_INT) : false;
        $reason = is_array($input) && is_string($input['reason'] ?? null)
            ? trim(mb_substr($input['reason'], 0, 2000))
            : '';
        if (!$application_id || $application_id < 1 || !$destination_id || $destination_id < 1 || $reason === '') {
            $this->api->respond_error('Choose a destination CDC and provide a transfer reason.', 400);
            return;
        }

        $this->db->transaction();
        try {
            $application = $this->db->raw(
                'SELECT a.id, a.application_number, a.child_id, a.parent_user_id,
                        a.cdc_id, a.assigned_teacher_user_id, a.status,
                        c.enrollment_status AS child_enrollment_status,
                        old_center.name AS previous_cdc_name, old_center.status AS previous_cdc_status,
                        old_teacher.first_name AS previous_teacher_first_name,
                        old_teacher.last_name AS previous_teacher_last_name
                 FROM enrollment_applications a
                 JOIN children c ON c.id = a.child_id
                 JOIN cdcs old_center ON old_center.id = a.cdc_id
                 LEFT JOIN users old_teacher ON old_teacher.id = a.assigned_teacher_user_id
                 WHERE a.id = ? FOR UPDATE',
                [(int) $application_id]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$application) {
                $this->db->roll_back();
                $this->api->respond_error('Enrollment application not found.', 404);
                return;
            }
            if (!in_array($application['status'], ['pending', 'under_review'], true)
                || $application['child_enrollment_status'] === 'enrolled') {
                $this->db->roll_back();
                $message = $application['child_enrollment_status'] === 'enrolled'
                    ? 'Already-enrolled children must use the separate child transfer process.'
                    : ($application['status'] === 'approved'
                        ? 'Approved applications require administrative review before transfer.'
                        : 'Rejected or final applications cannot be transferred.');
                $this->api->respond_error($message, 409);
                return;
            }
            if ((int) $application['cdc_id'] === (int) $destination_id) {
                $this->db->roll_back();
                $this->api->respond_error('Choose a different destination CDC.', 400);
                return;
            }

            $centers = $this->db->raw(
                'SELECT id, name, status FROM cdcs
                 WHERE id IN (?, ?) ORDER BY id FOR UPDATE',
                [(int) $application['cdc_id'], (int) $destination_id]
            )->fetchAll(PDO::FETCH_ASSOC);
            $destination = null;
            foreach ($centers as $center) {
                if ((int) $center['id'] === (int) $destination_id) $destination = $center;
            }
            if (!$destination || $destination['status'] !== 'active') {
                $this->db->roll_back();
                $this->api->respond_error('The destination CDC is not active.', 409);
                return;
            }
            $destination_teacher = $this->assigned_teacher((int) $destination_id);
            if (!$destination_teacher) {
                $this->db->roll_back();
                $this->api->respond_error('The destination CDC does not have an approved, active assigned teacher.', 409);
                return;
            }

            $previous_teacher_id = $application['assigned_teacher_user_id'] !== null
                ? (int) $application['assigned_teacher_user_id']
                : null;
            $transferred_at = date('Y-m-d H:i:s');
            $this->db->table('enrollment_applications')
                ->where('id', (int) $application_id)
                ->update([
                    'cdc_id' => (int) $destination_id,
                    'assigned_teacher_user_id' => (int) $destination_teacher['id'],
                ]);
            $this->db->table('children')
                ->where('id', (int) $application['child_id'])
                ->where('enrollment_status', 'pending')
                ->update([
                    'preferred_cdc_id' => (int) $destination_id,
                    'preferred_cdc_label' => $destination['name'],
                    'updated_at' => $transferred_at,
                ]);
            $this->db->table('cdc_enrollment_transfers')->insert([
                'application_id' => (int) $application_id,
                'child_id' => (int) $application['child_id'],
                'previous_cdc_id' => (int) $application['cdc_id'],
                'destination_cdc_id' => (int) $destination_id,
                'previous_teacher_user_id' => $previous_teacher_id,
                'new_teacher_user_id' => (int) $destination_teacher['id'],
                'admin_user_id' => (int) $admin['id'],
                'reason' => $reason,
                'transferred_at' => $transferred_at,
            ]);

            $previous_teacher = trim(implode(' ', array_filter([
                $application['previous_teacher_first_name'] ?? '',
                $application['previous_teacher_last_name'] ?? '',
            ]))) ?: 'Unassigned';
            $new_teacher = trim(implode(' ', array_filter([
                $destination_teacher['first_name'],
                $destination_teacher['last_name'],
            ])));
            $message = 'Your application ' . $application['application_number']
                . ' was transferred from ' . $application['previous_cdc_name'] . ' to '
                . $destination['name'] . '. Previous teacher: ' . $previous_teacher
                . '. New teacher: ' . $new_teacher . '. Reason: ' . $reason
                . '. Transfer date: ' . $transferred_at . '.';
            $this->notify_user(
                (int) $application['parent_user_id'],
                'Enrollment application transferred',
                $message,
                'enrollment',
                (int) $application_id,
                '/parent/application-status'
            );
            $this->notify_user(
                (int) $destination_teacher['id'],
                'Enrollment application transferred to your CDC',
                $message,
                'enrollment',
                (int) $application_id,
                '/teacher/enrollment'
            );
            if ($previous_teacher_id !== null && $previous_teacher_id !== (int) $destination_teacher['id']) {
                $this->notify_user(
                    $previous_teacher_id,
                    'Enrollment application transferred',
                    $message,
                    'enrollment',
                    (int) $application_id,
                    '/teacher/enrollment'
                );
            }
            $this->notify_admins(
                (int) $admin['id'],
                'Enrollment application transferred',
                $message,
                (int) $application_id
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        $this->api->respond([
            'status' => true,
            'message' => 'Enrollment application transferred.',
            'data' => ['application_id' => (int) $application_id],
        ]);
    }

    private function validated_center($input)
    {
        if (!is_array($input)) {
            $this->api->respond_error('Provide valid center details.', 400);
            return null;
        }
        foreach (['name', 'address', 'barangay', 'center_head', 'contact_number'] as $field) {
            if (isset($input[$field]) && !is_string($input[$field])) {
                $this->api->respond_error('Center details must be text values.', 400);
                return null;
            }
        }
        $name = trim((string) ($input['name'] ?? ''));
        $address = trim((string) ($input['address'] ?? ''));
        $barangay = trim((string) ($input['barangay'] ?? ''));
        $center_head = trim((string) ($input['center_head'] ?? ''));
        $contact_number = trim((string) ($input['contact_number'] ?? ''));
        $capacity = filter_var($input['capacity'] ?? null, FILTER_VALIDATE_INT);
        if (
            $name === '' || $address === '' || $barangay === ''
            || strlen($name) > 160 || strlen($address) > 255
            || strlen($barangay) > 120 || strlen($center_head) > 160
            || strlen($contact_number) > 30
            || $capacity === false || $capacity < 0
        ) {
            $this->api->respond_error('Provide a center name, address, barangay, and a non-negative capacity within the allowed field lengths.', 400);
            return null;
        }
        return [
            'name' => $name,
            'address' => $address,
            'barangay' => $barangay,
            'center_head' => $center_head !== '' ? $center_head : null,
            'contact_number' => $contact_number !== '' ? $contact_number : null,
            'capacity' => $capacity,
        ];
    }

    private function center_exists($id)
    {
        return (bool) $this->db->table('cdcs')->select('id')->where('id', $id)->get();
    }

    private function center_name_exists($name, $except_id = null)
    {
        $sql = 'SELECT id FROM cdcs WHERE name = ?';
        $params = [$name];
        if ($except_id !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $except_id;
        }
        return (bool) $this->db->raw($sql, $params)->fetch(PDO::FETCH_ASSOC);
    }

    private function assigned_teacher($center_id)
    {
        return $this->db->raw(
            'SELECT u.id, u.first_name, u.last_name
             FROM teacher_profiles tp
             JOIN users u ON u.id = tp.user_id
             JOIN notifications review ON review.id = (
                 SELECT latest.id FROM notifications latest
                 WHERE latest.related_type = \'teacher_verification\'
                   AND latest.related_id = u.id ORDER BY latest.id DESC LIMIT 1
             )
             WHERE tp.cdc_id = ? AND tp.employment_status = \'active\'
               AND u.role = \'teacher\' AND u.is_active = 1 AND review.title = \'Approved\'
             LIMIT 1',
            [(int) $center_id]
        )->fetch(PDO::FETCH_ASSOC);
    }

    private function available_destinations()
    {
        return $this->db->raw(
            'SELECT d.id, d.name
             FROM cdcs d
             WHERE d.status = \'active\' AND EXISTS (
                 SELECT 1 FROM teacher_profiles tp
                 JOIN users u ON u.id = tp.user_id
                 JOIN notifications review ON review.id = (
                     SELECT latest.id FROM notifications latest
                     WHERE latest.related_type = \'teacher_verification\'
                       AND latest.related_id = u.id ORDER BY latest.id DESC LIMIT 1
                 )
                 WHERE tp.cdc_id = d.id AND tp.employment_status = \'active\'
                   AND u.role = \'teacher\' AND u.is_active = 1 AND review.title = \'Approved\'
             )
             ORDER BY d.name, d.id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function teacher_name($teacher_id)
    {
        $teacher = $this->db->raw(
            'SELECT TRIM(CONCAT_WS(\' \', first_name, last_name)) AS name
             FROM users WHERE id = ?',
            [(int) $teacher_id]
        )->fetch(PDO::FETCH_ASSOC);
        return $teacher['name'] ?? '';
    }

    private function notify_center_deactivation($center_id, $actor_id, $center_name)
    {
        $applications = $this->db->raw(
            'SELECT a.id, a.parent_user_id, a.assigned_teacher_user_id
             FROM enrollment_applications a
             JOIN children c ON c.id = a.child_id
             WHERE a.cdc_id = ? AND a.status IN (\'pending\', \'under_review\')
               AND c.enrollment_status <> \'enrolled\'',
            [(int) $center_id]
        )->fetchAll(PDO::FETCH_ASSOC);
        $recipient_ids = [];
        foreach ($applications as $application) {
            $this->notify_user(
                (int) $application['parent_user_id'],
                'CDC temporarily unavailable',
                $center_name . ' is no longer accepting enrollment applications. Your application remains available for administrative review and possible transfer.',
                'enrollment',
                (int) $application['id'],
                '/parent/application-status'
            );
            $recipient_ids[] = (int) $application['assigned_teacher_user_id'];
        }
        $recipient_ids = array_unique(array_filter($recipient_ids));
        foreach ($recipient_ids as $teacher_id) {
            $this->notify_user(
                (int) $teacher_id,
                'CDC deactivated',
                $center_name . ' has been deactivated. Its open enrollment applications may require administrative transfer.',
                'cdc_assignment',
                (int) $center_id,
                '/teacher/enrollment'
            );
        }
        $this->notify_admins(
            (int) $actor_id,
            'CDC deactivated',
            $center_name . ' is inactive. Review its open enrollment applications for transfer.',
            (int) $center_id
        );
    }

    private function notify_admins($actor_id, $title, $message, $related_id)
    {
        $admins = $this->db->raw(
            'SELECT id FROM users WHERE role = \'admin\' AND is_active = 1 AND id <> ?',
            [(int) $actor_id]
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $admin_id) {
            $this->notify_user(
                (int) $admin_id,
                $title,
                $message,
                'cdc_assignment',
                (int) $related_id,
                '/admin/centers'
            );
        }
    }

    private function notify_user($user_id, $title, $message, $related_type, $related_id, $action_url)
    {
        $this->db->table('notifications')->insert([
            'actor_user_id' => null,
            'title' => $title,
            'message' => $message,
            'audience' => 'single',
            'related_type' => $related_type,
            'related_id' => (int) $related_id,
            'related_module' => 'center_management',
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
            $this->api->respond_error('You are not authorized to manage centers.', 403);
            return null;
        }
        return $user;
    }
}
