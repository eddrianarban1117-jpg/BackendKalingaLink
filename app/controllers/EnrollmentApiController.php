<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class EnrollmentApiController extends Controller
{
    private const MAX_FILE_SIZE = 10485760;
    private const MAX_FILES = 10;
    private $cdc_status;

    public function __construct()
    {
        parent::__construct();
        $this->call->library('api');
        $this->cdc_status = $this->call->library('CdcStatus', $this->db);
    }

    public function cdcs()
{
    $totalStart = microtime(true);

    $this->api->require_method('GET');

    $sql = 'SELECT d.id, d.name, d.address, d.barangay, d.capacity, d.status,
                assigned.id AS assigned_teacher_user_id,
                TRIM(CONCAT_WS(\' \', assigned.first_name, assigned.last_name)) AS assigned_teacher_name,
                CASE WHEN d.status = \'active\' AND review.title = \'Approved\'
                           AND assigned.is_active = 1 AND tp.employment_status = \'active\'
                     THEN 1 ELSE 0 END AS enrollment_available
         FROM cdcs d
         LEFT JOIN teacher_profiles tp ON tp.cdc_id = d.id
         LEFT JOIN users assigned ON assigned.id = tp.user_id AND assigned.role = \'teacher\'
         LEFT JOIN notifications review ON review.id = (
             SELECT latest.id FROM notifications latest
             WHERE latest.related_type = \'teacher_verification\'
               AND latest.related_id = assigned.id
             ORDER BY latest.id DESC LIMIT 1
         )
         WHERE BINARY TRIM(d.name) IN (
             \'Cacawan Centro Pamana CDC\',
             \'Marfrancisco CDC\',
             \'Papandayan Highway CDC\'
         )';

    if (!filter_var($_GET['include_unavailable'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        $sql .= ' AND d.status = \'active\'';
    }

    $sql .= ' ORDER BY d.name, d.id';

    // Measure SQL execution
    $stmt = $this->db->raw($sql);

    $queryLog = $this->db->get_query_log();
    $lastQuery = !empty($queryLog) ? end($queryLog) : null;

    $sqlExecuteMs = isset($lastQuery['time'])
        ? round($lastQuery['time'] * 1000, 2)
        : null;

    // Measure fetching
    $fetchStart = microtime(true);

    $centers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $fetchMs = round((microtime(true) - $fetchStart) * 1000, 2);

    // Measure data processing
    $processingStart = microtime(true);

    foreach ($centers as &$center) {
        $center['id'] = (int) $center['id'];
        $center['capacity'] = (int) $center['capacity'];
        $center['enrollment_available'] = (int) $center['enrollment_available'] === 1;

        $center['assigned_teacher_user_id'] =
            $center['assigned_teacher_user_id'] !== null
                ? (int) $center['assigned_teacher_user_id']
                : null;

        $center['availability_reason'] = $center['enrollment_available']
            ? 'Available'
            : ($center['assigned_teacher_user_id'] === null
                ? 'No approved teacher assigned'
                : ($center['status'] === 'inactive'
                    ? 'Inactive - not available for enrollment'
                    : 'Assigned teacher is not currently approved and active'));
    }

    unset($center);

    $processingMs = round(
        (microtime(true) - $processingStart) * 1000,
        2
    );

    $totalMs = round(
        (microtime(true) - $totalStart) * 1000,
        2
    );

    $this->api->respond([
        'status' => true,
        'data' => $centers,
        'diagnostics' => [
            'sql_execute_ms' => $sqlExecuteMs,
            'fetch_ms' => $fetchMs,
            'processing_ms' => $processingMs,
            'total_ms' => $totalMs,
            'records_returned' => count($centers),
        ],
    ]);
}

    public function index()
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user(['parent', 'teacher', 'admin']);
        if (!$user) return;

        $sql = 'SELECT a.id, a.application_number, a.parent_user_id, a.child_id, a.cdc_id,
                       a.assigned_teacher_user_id,
                       assigned_teacher.first_name AS assigned_teacher_first_name,
                       assigned_teacher.last_name AS assigned_teacher_last_name,
                       a.enrollment_type, a.school_year, a.parent_name, a.parent_relationship,
                       a.parent_email, a.parent_phone, a.parent_birth_date, a.parent_gender,
                       a.parent_address, pp.barangay AS parent_barangay,
                       a.emergency_name, a.emergency_relationship, a.emergency_phone,
                       a.household_income, a.preferred_language, a.referral_source, a.status,
                       a.review_note, a.documents_requested_at, a.requested_requirements,
                       a.request_remarks, a.submitted_at, a.reviewed_at,
                       a.reviewed_by,
                      COALESCE(a.child_first_name, c.first_name) AS child_first_name,
                      COALESCE(a.child_middle_name, c.middle_name) AS child_middle_name,
                      COALESCE(a.child_last_name, c.last_name) AS child_last_name,
                      c.suffix AS child_suffix,
                      COALESCE(a.child_nickname, c.nickname) AS child_nickname,
                      COALESCE(a.child_birth_date, c.birth_date) AS child_birth_date,
                      COALESCE(a.child_gender, c.gender) AS child_gender,
                      COALESCE(a.child_address, c.address) AS child_address,
                      COALESCE(a.child_psa_number, c.psa_birth_certificate_number) AS psa_birth_certificate_number,
                      COALESCE(a.child_nationality, c.nationality) AS child_nationality,
                      COALESCE(a.child_place_of_birth, c.place_of_birth) AS place_of_birth,
                      COALESCE(a.child_blood_type, c.blood_type) AS blood_type,
                      COALESCE(a.child_medical_condition, c.medical_notes) AS medical_notes,
                      COALESCE(a.child_allergies, c.allergies) AS allergies,
                      COALESCE(a.child_immunization_status, c.immunization_status) AS immunization_status,
                      c.enrolled_at,
                      cl.name AS child_class_name,
                       d.name AS cdc_name, d.address AS cdc_address,
                       d.contact_number AS cdc_contact_number,
                       reviewer.first_name AS reviewer_first_name,
                       reviewer.last_name AS reviewer_last_name,
                       reviewer.role AS reviewer_role
                FROM enrollment_applications a
                JOIN children c ON c.id = a.child_id
                LEFT JOIN parent_profiles pp ON pp.user_id = a.parent_user_id
                LEFT JOIN classes cl ON cl.id = c.class_id
                LEFT JOIN users assigned_teacher ON assigned_teacher.id = a.assigned_teacher_user_id
                LEFT JOIN users reviewer ON reviewer.id = a.reviewed_by
                LEFT JOIN cdcs d ON d.id = a.cdc_id';
        $params = [];
        if ($user['role'] === 'parent') {
            $sql .= ' WHERE a.parent_user_id = ?';
            $params[] = $user['id'];
        } elseif ($user['role'] === 'teacher') {
            $sql .= ' WHERE a.assigned_teacher_user_id = ?
                      AND EXISTS (
                          SELECT 1 FROM teacher_profiles tp
                          WHERE tp.user_id = ? AND tp.cdc_id = a.cdc_id
                            AND tp.employment_status = \'active\'
                      )';
            $params[] = (int) $user['id'];
            $params[] = (int) $user['id'];
        }
        $sql .= ' ORDER BY a.submitted_at DESC, a.id DESC';

        $applications = $this->db->raw($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($applications as &$application) {
            $application['cdc_id'] = (int) $application['cdc_id'];
            $application['assigned_teacher_user_id'] = $application['assigned_teacher_user_id'] !== null
                ? (int) $application['assigned_teacher_user_id']
                : null;
            $application['documents'] = $this->document_metadata($application['id']);
            $application['review_history'] = $this->history((int) $application['id']);
            $application['requested_requirements'] = $this->decode_requested_requirements(
                $application['requested_requirements'] ?? null
            );
        }
        unset($application);

        $this->api->respond(['status' => true, 'data' => $applications]);
    }

    public function notifications()
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user(['parent', 'teacher', 'admin']);
        if (!$user) return;

        $notifications = $this->db->raw(
            'SELECT n.id, n.title, n.message, n.related_type, n.related_id, n.created_at, r.read_at
             FROM notification_recipients r
             JOIN notifications n ON n.id = r.notification_id
             WHERE r.user_id = ? AND n.related_type IN (\'enrollment\', \'teacher_verification\')
             ORDER BY n.created_at DESC, n.id DESC
             LIMIT 100',
            [(int) $user['id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($notifications as &$notification) {
            $notification['id'] = (int) $notification['id'];
            $notification['related_id'] = $notification['related_id'] !== null
                ? (int) $notification['related_id']
                : null;
            $notification['unread'] = $notification['read_at'] === null;
        }
        unset($notification);

        $this->api->respond([
            'status' => true,
            'data' => ['notifications' => $notifications],
        ]);
    }

    public function assignment_options()
    {
        $this->api->require_method('GET');
        $admin = $this->authenticated_user(['admin']);
        if (!$admin) return;

        $teachers = $this->db->raw(
            'SELECT u.id, u.first_name, u.middle_name, u.last_name, tp.cdc_id
             FROM users u
             JOIN teacher_profiles tp ON tp.user_id = u.id
             JOIN cdcs d ON d.id = tp.cdc_id
             JOIN notifications review ON review.id = (
                 SELECT latest.id FROM notifications latest
                 WHERE latest.related_type = \'teacher_verification\'
                   AND latest.related_id = u.id ORDER BY latest.id DESC LIMIT 1
             )
             WHERE u.role = \'teacher\' AND u.is_active = 1
               AND tp.employment_status = \'active\' AND d.status = \'active\'
               AND review.title = \'Approved\'
             ORDER BY d.name, u.last_name, u.first_name, u.id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $teachers = array_map(static function ($teacher) {
            return [
                'id' => (int) $teacher['id'],
                'cdc_id' => (int) $teacher['cdc_id'],
                'name' => trim(implode(' ', array_filter([
                    $teacher['first_name'],
                    $teacher['middle_name'],
                    $teacher['last_name'],
                ]))),
            ];
        }, $teachers);

        $this->api->respond(['status' => true, 'data' => $teachers]);
    }

    public function mark_notifications_read()
    {
        $this->api->require_method('PATCH');
        $user = $this->authenticated_user(['parent', 'teacher', 'admin']);
        if (!$user) return;

        $input = $this->api->body(false);
        $ids = is_array($input) && is_array($input['ids'] ?? null)
            ? array_values(array_unique(array_filter(array_map(
                static function ($id) {
                    $value = filter_var($id, FILTER_VALIDATE_INT);
                    return $value && $value > 0 ? $value : null;
                },
                $input['ids']
            ))))
            : [];
        if (!$ids) {
            $this->api->respond(['status' => true, 'message' => 'No unread enrollment notifications.']);
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $this->db->raw(
            'UPDATE notification_recipients r
             JOIN notifications n ON n.id = r.notification_id
             SET r.read_at = ?
             WHERE r.user_id = ? AND n.related_type IN (\'enrollment\', \'teacher_verification\')
               AND r.read_at IS NULL AND r.notification_id IN (' . $placeholders . ')',
            array_merge([date('Y-m-d H:i:s'), (int) $user['id']], $ids)
        );
        $this->api->respond(['status' => true, 'message' => 'Enrollment notifications marked as read.']);
    }

    public function submit()
    {
        $this->api->require_method('POST');
        $parent = $this->authenticated_user(['parent']);
        if (!$parent) return;

        $input = $this->api->body(false);
        $parent_profile = $this->db->table('parent_profiles')
            ->where('user_id', (int) $parent['id'])
            ->get();
        $parent_name = trim(implode(' ', array_filter([
            $parent['first_name'] ?? '',
            $parent['middle_name'] ?? '',
            $parent['last_name'] ?? '',
        ])));
        $relationship = trim((string) ($parent_profile['relationship'] ?? '')) ?: 'Parent/Guardian';
        $email = strtolower((string) $parent['email']);
        $phone = (string) ($parent['phone'] ?? '');
        $address = (string) ($parent_profile['address'] ?? '');
        $child_first = $this->text($input, 'childFirstName', 100);
        $child_middle = $this->text($input, 'childMiddleName', 100);
        $child_last = $this->text($input, 'childLastName', 100);
        $child_suffix = $this->text($input, 'childSuffix', 20);
        $child_nickname = $this->text($input, 'childNickname', 100);
        $child_birth_date = $this->valid_date($input['childBirthDate'] ?? null);
        $child_gender = $this->text($input, 'childGender', 40);
        $psa_number = $this->text($input, 'psaNumber', 60);
        $nationality = $this->text($input, 'nationality', 80);
        $place_of_birth = $this->text($input, 'placeOfBirth', 180);
        $child_address = $this->text($input, 'childAddress', 255);
        $blood_type = $this->text($input, 'bloodType', 8);
        $medical_condition = $this->text($input, 'medicalCondition', 2000);
        $allergies = $this->text($input, 'allergies', 2000);
        $immunization_status = $this->text($input, 'immunizationStatus', 120);
        $emergency_name = $this->text($input, 'emergencyName', 160);
        $emergency_relationship = $this->text($input, 'emergencyRelationship', 80);
        $emergency_phone = $this->text($input, 'emergencyPhone', 30);
        $enrollment_type = $this->text($input, 'enrollmentType', 30);
        $cdc_id = filter_var($input['center'] ?? null, FILTER_VALIDATE_INT);
        $requested_child_id = null;
        if (isset($input['child_id']) && $input['child_id'] !== '') {
            $requested_child_id = filter_var($input['child_id'], FILTER_VALIDATE_INT);
            if (!$requested_child_id || $requested_child_id < 1) {
                $this->api->respond_error('Choose a valid child profile.', 400);
                return;
            }
        }

        if ($parent_name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || $child_first === '' || $child_last === '' || !$child_birth_date
            || !in_array($child_gender, ['Female', 'Male'], true)
            || !preg_match('/^[A-Za-z0-9-]{5,60}$/', $psa_number)
            || $nationality === '' || $place_of_birth === '' || $child_address === ''
            || $emergency_name === '' || $emergency_relationship === ''
            || !preg_match('/^[+0-9() -]{7,30}$/', $emergency_phone)
            || !in_array($blood_type, ['', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], true)
            || !in_array($enrollment_type, ['new', 'reenrollment'], true) || !$cdc_id) {
            $this->api->respond_error('Complete all required child, emergency contact, enrollment, and center fields with valid values.', 400);
            return;
        }
        $center = $this->db->table('cdcs')
            ->where('id', $cdc_id)
            ->where('status', 'active')
            ->get();
        if (!$center || !$this->cdc_status->is_required_name($center['name'])) {
            $this->api->respond_error('The selected Child Development Center is unavailable.', 400);
            return;
        }

        $files = $this->collect_uploads();
        if (count($files) > self::MAX_FILES) {
            $this->api->respond_error('A maximum of ten documents may be uploaded.', 400);
            return;
        }
        $uploaded_types = array_column($files, 'field');
        foreach (['birth_certificate', 'parent_id', 'parent_photo', 'barangay_clearance', 'proof_of_residence'] as $required_document) {
            if (!in_array($required_document, $uploaded_types, true)) {
                $this->api->respond_error('Upload the required document: ' . str_replace('_', ' ', $required_document) . '.', 400);
                return;
            }
        }
        if ($medical_condition !== '' && !in_array('medical_certificate', $uploaded_types, true)) {
            $this->api->respond_error('A medical certificate is required when a medical condition is reported.', 400);
            return;
        }

        $this->db->transaction();
        $moved_paths = [];
        try {
            $center = $this->db->raw(
                'SELECT id, name, status FROM cdcs WHERE id = ? FOR UPDATE',
                [(int) $cdc_id]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$center || $center['status'] !== 'active') {
                $this->db->roll_back();
                $this->api->respond_error('The selected Child Development Center is no longer available for enrollment.', 409);
                return;
            }
            $eligible_teachers = $this->db->raw(
                'SELECT u.id FROM users u
                 JOIN teacher_profiles tp ON tp.user_id = u.id
                 JOIN notifications review ON review.id = (
                     SELECT latest.id FROM notifications latest
                     WHERE latest.related_type = \'teacher_verification\'
                       AND latest.related_id = u.id ORDER BY latest.id DESC LIMIT 1
                 )
                 WHERE u.role = \'teacher\' AND u.is_active = 1
                   AND tp.employment_status = \'active\' AND tp.cdc_id = ?
                   AND review.title = \'Approved\'
                 ORDER BY u.id FOR UPDATE',
                [$cdc_id]
            )->fetchAll(PDO::FETCH_COLUMN);
            if (count($eligible_teachers) !== 1) {
                $this->db->roll_back();
                $this->api->respond_error('The selected Child Development Center has no valid assigned teacher.', 409);
                return;
            }
            $assigned_teacher_user_id = (int) $eligible_teachers[0];

            $child = $requested_child_id
                ? $this->db->raw(
                    'SELECT id FROM children WHERE id = ? AND parent_user_id = ? FOR UPDATE',
                    [$requested_child_id, $parent['id']]
                )->fetch(PDO::FETCH_ASSOC)
                : $this->db->raw(
                    'SELECT id FROM children
                     WHERE parent_user_id = ? AND (
                        (first_name = ? AND last_name = ? AND birth_date = ?)
                        OR (psa_birth_certificate_number = ? AND psa_birth_certificate_number IS NOT NULL)
                     )
                     ORDER BY (psa_birth_certificate_number = ?) DESC
                     LIMIT 1 FOR UPDATE',
                    [$parent['id'], $child_first, $child_last, $child_birth_date, $psa_number, $psa_number]
                )->fetch(PDO::FETCH_ASSOC);
            if ($requested_child_id && !$child) {
                throw new DomainException('The selected child profile was not found.');
            }
            if ($child) {
                $child_id = (int) $child['id'];
                $open_application = $this->db->raw(
                    'SELECT id FROM enrollment_applications
                     WHERE child_id = ? AND status IN (\'pending\', \'under_review\')
                     LIMIT 1 FOR UPDATE',
                    [$child_id]
                )->fetch(PDO::FETCH_ASSOC);
                if ($open_application) {
                    throw new DomainException('An application for this child is already awaiting review.');
                }
                $this->db->table('children')
                    ->where('id', $child_id)
                    ->where('parent_user_id', (int) $parent['id'])
                    ->update([
                        'first_name' => $child_first,
                        'middle_name' => $child_middle !== '' ? $child_middle : null,
                        'last_name' => $child_last,
                        'suffix' => $child_suffix !== '' ? $child_suffix : null,
                        'nickname' => $child_nickname !== '' ? $child_nickname : null,
                        'birth_date' => $child_birth_date,
                        'gender' => $child_gender,
                        'address' => $child_address,
                        'preferred_cdc_id' => $cdc_id,
                        'preferred_cdc_label' => $center['name'] ?? null,
                        'psa_birth_certificate_number' => $psa_number,
                        'nationality' => $nationality,
                        'place_of_birth' => $place_of_birth,
                        'blood_type' => $blood_type !== '' ? $blood_type : null,
                        'medical_notes' => $medical_condition !== '' ? $medical_condition : null,
                        'allergies' => $allergies !== '' ? $allergies : null,
                        'immunization_status' => $immunization_status !== '' ? $immunization_status : null,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            } else {
                $this->db->table('children')->insert([
                    'parent_user_id' => $parent['id'],
                    'preferred_cdc_id' => $cdc_id,
                    'class_id' => null,
                    'first_name' => $child_first,
                    'middle_name' => $child_middle !== '' ? $child_middle : null,
                    'last_name' => $child_last,
                    'suffix' => $child_suffix !== '' ? $child_suffix : null,
                    'nickname' => $child_nickname !== '' ? $child_nickname : null,
                    'birth_date' => $child_birth_date,
                    'gender' => $child_gender,
                    'address' => $child_address,
                    'preferred_cdc_label' => $center['name'] ?? null,
                    'psa_birth_certificate_number' => $psa_number,
                    'nationality' => $nationality,
                    'place_of_birth' => $place_of_birth,
                    'blood_type' => $blood_type !== '' ? $blood_type : null,
                    'medical_notes' => $medical_condition !== '' ? $medical_condition : null,
                    'allergies' => $allergies !== '' ? $allergies : null,
                    'immunization_status' => $immunization_status !== '' ? $immunization_status : null,
                    'enrollment_status' => 'pending',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $child_id = $this->db->last_id();
            }

            $placeholder = 'TMP-' . bin2hex(random_bytes(12));
            $this->db->table('enrollment_applications')->insert([
                'application_number' => $placeholder,
                'parent_user_id' => $parent['id'],
                'child_id' => $child_id,
                'cdc_id' => $cdc_id,
                'assigned_teacher_user_id' => $assigned_teacher_user_id,
                'child_first_name' => $child_first,
                'child_middle_name' => $child_middle !== '' ? $child_middle : null,
                'child_last_name' => $child_last,
                'child_nickname' => $child_nickname !== '' ? $child_nickname : null,
                'child_birth_date' => $child_birth_date,
                'child_gender' => $child_gender,
                'child_address' => $child_address,
                'child_psa_number' => $psa_number,
                'child_nationality' => $nationality,
                'child_place_of_birth' => $place_of_birth,
                'child_blood_type' => $blood_type !== '' ? $blood_type : null,
                'child_medical_condition' => $medical_condition !== '' ? $medical_condition : null,
                'child_allergies' => $allergies !== '' ? $allergies : null,
                'child_immunization_status' => $immunization_status !== '' ? $immunization_status : null,
                'enrollment_type' => $enrollment_type,
                'school_year' => $this->current_school_year(),
                'parent_name' => $parent_name,
                'parent_relationship' => $relationship,
                'parent_email' => $email,
                'parent_phone' => $phone,
                'parent_birth_date' => $parent_profile['birth_date'] ?? null,
                'parent_gender' => $parent_profile['gender'] ?? null,
                'parent_address' => $address,
                'emergency_name' => $emergency_name,
                'emergency_relationship' => $emergency_relationship,
                'emergency_phone' => $emergency_phone,
                'household_income' => $parent_profile['household_income'] ?? null,
                'preferred_language' => $parent_profile['preferred_language'] ?? null,
                'referral_source' => $parent_profile['referral_source'] ?? null,
                'status' => 'pending',
                'review_note' => null,
                'submitted_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $application_id = $this->db->last_id();
            $application_number = sprintf('APP-%s-%06d', date('Y'), $application_id);
            $this->db->table('enrollment_applications')
                ->where('id', $application_id)
                ->update(['application_number' => $application_number]);

            foreach ($files as $file) {
                $stored = $this->store_upload($file, $parent['id']);
                $moved_paths[] = $stored['absolute_path'];
                $this->db->table('enrollment_documents')->insert([
                    'application_id' => $application_id,
                    'document_type' => $file['field'],
                    'original_name' => $file['name'],
                    'stored_name' => $stored['stored_name'],
                    'storage_path' => $stored['relative_path'],
                    'mime_type' => $stored['mime_type'],
                    'file_size' => $file['size'],
                    'sha256' => $stored['sha256'],
                    'verification_status' => 'received',
                ]);
            }

            $this->db->table('enrollment_status_history')->insert([
                'application_id' => $application_id,
                'changed_by' => $parent['id'],
                'from_status' => null,
                'to_status' => 'pending',
                'note' => 'Application submitted by parent.',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $this->notify_user(
                (int) $parent['id'],
                'Enrollment application submitted',
                $application_number . ' was submitted successfully and is pending review.',
                'enrollment',
                $application_id
            );
            if ($assigned_teacher_user_id !== null) {
                $this->notify_user(
                    $assigned_teacher_user_id,
                    'New enrollment application assigned',
                    $application_number . ' is ready for review at ' . $center['name'] . '.',
                    'enrollment',
                    $application_id
                );
            } else {
                $reason = $eligible_teachers
                    ? 'Multiple active teachers are assigned to the selected CDC.'
                    : 'No active teacher is assigned to the selected CDC.';
                $this->notify_admins(
                    'Enrollment application awaiting teacher assignment',
                    $application_number . ' requires administrative attention. ' . $reason,
                    $application_id
                );
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            foreach ($moved_paths as $path) {
                if (is_file($path)) unlink($path);
            }
            if ($error instanceof DomainException) {
                $this->api->respond_error($error->getMessage(), 409);
                return;
            }
            throw $error;
        }

        $this->api->respond([
            'status' => true,
            'message' => 'Enrollment application submitted.',
            'data' => [
                'id' => $application_id,
                'application_number' => $application_number,
                'status' => 'pending',
                'submitted_at' => date('c'),
            ],
        ], 201);
    }

    public function upload_additional_documents($application_id)
    {
        $this->api->require_method('POST');
        $parent = $this->authenticated_user(['parent']);
        if (!$parent) return;

        $application_id = filter_var($application_id, FILTER_VALIDATE_INT);
        if (!$application_id) {
            $this->api->respond_error('Application not found.', 404);
            return;
        }
        $input = $this->api->body(false);
        if (!is_array($input)) $input = [];
        $edit_requirement_keys = [
            'enrollmentType' => 'enrollment_type',
            'childFirstName' => 'child_first_name',
            'childMiddleName' => 'child_middle_name',
            'childLastName' => 'child_last_name',
            'childSuffix' => 'child_suffix',
            'childNickname' => 'child_nickname',
            'childBirthDate' => 'child_birth_date',
            'childGender' => 'child_gender',
            'psaNumber' => 'child_psa_number',
            'nationality' => 'child_nationality',
            'placeOfBirth' => 'child_place_of_birth',
            'childAddress' => 'child_address',
            'bloodType' => 'child_blood_type',
            'medicalCondition' => 'child_medical_condition',
            'allergies' => 'child_allergies',
            'immunizationStatus' => 'child_immunization_status',
            'emergencyName' => 'emergency_name',
            'emergencyRelationship' => 'emergency_relationship',
            'emergencyPhone' => 'emergency_phone',
            'parentName' => 'parent_name',
            'parentGender' => 'parent_gender',
            'parentBirthDate' => 'parent_birth_date',
            'parentPhone' => 'parent_phone',
            'parentEmail' => 'parent_email',
            'parentRelationship' => 'parent_relationship',
            'parentAddress' => 'parent_address',
            'parentBarangay' => 'parent_barangay',
            'householdIncome' => 'household_income',
            'preferredLanguage' => 'preferred_language',
            'referralSource' => 'referral_source',
        ];
        $edit_keys = array_keys($edit_requirement_keys);
        $has_edits = (bool) array_intersect($edit_keys, array_keys($input));
        $files = $this->collect_uploads();
        if (!$files && !$has_edits) {
            $this->api->respond_error('Update requested information or upload a requested document.', 400);
            return;
        }

        $this->db->transaction();
        $moved_paths = [];
        try {
            $application = $this->db->raw(
                'SELECT a.id, a.application_number, a.parent_user_id, a.child_id, a.cdc_id,
                    a.assigned_teacher_user_id,
                    a.enrollment_type, a.status,
                    a.parent_name, a.parent_relationship, a.parent_email, a.parent_phone,
                    a.parent_birth_date, a.parent_gender, a.parent_address, a.household_income,
                    a.preferred_language, a.referral_source, a.requested_requirements,
                    COALESCE(a.child_first_name, c.first_name) AS child_first_name,
                    COALESCE(a.child_middle_name, c.middle_name) AS child_middle_name,
                    COALESCE(a.child_last_name, c.last_name) AS child_last_name,
                    c.suffix AS child_suffix,
                    COALESCE(a.child_nickname, c.nickname) AS child_nickname,
                    COALESCE(a.child_birth_date, c.birth_date) AS child_birth_date,
                    COALESCE(a.child_gender, c.gender) AS child_gender,
                    COALESCE(a.child_address, c.address) AS child_address,
                    COALESCE(a.child_psa_number, c.psa_birth_certificate_number) AS child_psa_number,
                    COALESCE(a.child_nationality, c.nationality) AS child_nationality,
                    COALESCE(a.child_place_of_birth, c.place_of_birth) AS child_place_of_birth,
                    COALESCE(a.child_blood_type, c.blood_type) AS child_blood_type,
                    COALESCE(a.child_medical_condition, c.medical_notes) AS child_medical_condition,
                    COALESCE(a.child_allergies, c.allergies) AS child_allergies,
                    COALESCE(a.child_immunization_status, c.immunization_status) AS child_immunization_status,
                    a.emergency_name, a.emergency_relationship, a.emergency_phone,
                    pp.gender AS profile_gender, pp.birth_date AS profile_birth_date,
                    pp.address AS profile_address, pp.barangay AS profile_barangay,
                    pp.household_income AS profile_household_income,
                    pp.preferred_language AS profile_preferred_language,
                    pp.referral_source AS profile_referral_source
                 FROM enrollment_applications a
                 JOIN children c ON c.id = a.child_id
                 LEFT JOIN parent_profiles pp ON pp.user_id = a.parent_user_id
                 WHERE a.id = ? AND a.parent_user_id = ?
                   AND c.parent_user_id = a.parent_user_id
                   AND a.status = \'under_review\'
                   AND a.documents_requested_at IS NOT NULL
                 FOR UPDATE',
                [$application_id, $parent['id']]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$application) {
                $this->db->roll_back();
                $this->api->respond_error('No application awaiting requested documents was found.', 404);
                return;
            }

            $requested_requirements = $this->decode_requested_requirements(
                $application['requested_requirements'] ?? null
            );
            $requested_keys = array_column($requested_requirements, 'key');
            $legacy_request = !$requested_keys;
            foreach (array_intersect(array_keys($input), $edit_keys) as $input_key) {
                if (!$legacy_request
                    && !in_array($edit_requirement_keys[$input_key], $requested_keys, true)) {
                    $this->db->roll_back();
                    $this->api->respond_error('Only information requested by the reviewer can be changed here.', 400);
                    return;
                }
            }
            foreach ($files as $file) {
                if (!$legacy_request
                    && !in_array('document_' . $file['field'], $requested_keys, true)) {
                    $this->db->roll_back();
                    $this->api->respond_error('Upload only the document types requested by the reviewer.', 400);
                    return;
                }
            }

            $corrections = [];
            if ($has_edits) {
                $posted_text = function ($key, $maximum, $fallback) use ($input) {
                    return array_key_exists($key, $input)
                        ? $this->text($input, $key, $maximum)
                        : (string) ($fallback ?? '');
                };
                $posted_date = function ($key, $fallback, $optional = false) use ($input) {
                    if (!array_key_exists($key, $input)) return $fallback;
                    return $this->valid_date($input[$key], $optional);
                };
                $child_first = $posted_text('childFirstName', 100, $application['child_first_name']);
                $child_middle = $posted_text('childMiddleName', 100, $application['child_middle_name']);
                $child_last = $posted_text('childLastName', 100, $application['child_last_name']);
                $child_suffix = $posted_text('childSuffix', 20, $application['child_suffix']);
                $child_nickname = $posted_text('childNickname', 100, $application['child_nickname']);
                $child_birth_date = $posted_date('childBirthDate', $application['child_birth_date']);
                $child_gender = $posted_text('childGender', 40, $application['child_gender']);
                $psa_number = $posted_text('psaNumber', 60, $application['child_psa_number']);
                $nationality = $posted_text('nationality', 80, $application['child_nationality']);
                $place_of_birth = $posted_text('placeOfBirth', 180, $application['child_place_of_birth']);
                $child_address = $posted_text('childAddress', 255, $application['child_address']);
                $blood_type = $posted_text('bloodType', 8, $application['child_blood_type']);
                $medical_condition = $posted_text('medicalCondition', 2000, $application['child_medical_condition']);
                $allergies = $posted_text('allergies', 2000, $application['child_allergies']);
                $immunization_status = $posted_text('immunizationStatus', 120, $application['child_immunization_status']);
                $emergency_name = $posted_text('emergencyName', 160, $application['emergency_name']);
                $emergency_relationship = $posted_text('emergencyRelationship', 80, $application['emergency_relationship']);
                $emergency_phone = $posted_text('emergencyPhone', 30, $application['emergency_phone']);
                $parent_name = $posted_text('parentName', 160, $application['parent_name']);
                $enrollment_type = $posted_text('enrollmentType', 30, $application['enrollment_type']);
                $parent_gender = $posted_text('parentGender', 40, $application['profile_gender'] ?? $application['parent_gender']);
                $parent_birth_date = $posted_date('parentBirthDate', $application['profile_birth_date'] ?? $application['parent_birth_date'], true);
                $parent_phone = $posted_text('parentPhone', 30, $application['parent_phone']);
                $parent_email = $posted_text('parentEmail', 255, $application['parent_email']);
                $parent_relationship = $posted_text('parentRelationship', 80, $application['parent_relationship']);
                $parent_address = $posted_text('parentAddress', 255, $application['profile_address'] ?? $application['parent_address']);
                $parent_barangay = $posted_text('parentBarangay', 120, $application['profile_barangay']);
                $household_income = $posted_text('householdIncome', 80, $application['profile_household_income'] ?? $application['household_income']);
                $preferred_language = $posted_text('preferredLanguage', 80, $application['profile_preferred_language'] ?? $application['preferred_language']);
                $referral_source = $posted_text('referralSource', 120, $application['profile_referral_source'] ?? $application['referral_source']);

                if ($child_first === '' || $child_last === '' || !$child_birth_date
                    || !in_array($child_gender, ['Female', 'Male'], true)
                    || !preg_match('/^[A-Za-z0-9-]{5,60}$/', $psa_number)
                    || $nationality === '' || $place_of_birth === '' || $child_address === ''
                    || $emergency_name === '' || $emergency_relationship === ''
                    || !preg_match('/^[+0-9() -]{7,30}$/', $emergency_phone)
                    || !in_array($blood_type, ['', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], true)
                    || $parent_name === ''
                    || !in_array($enrollment_type, ['new', 'reenrollment'], true)
                    || ($parent_email !== '' && !filter_var($parent_email, FILTER_VALIDATE_EMAIL))
                    || ($parent_phone !== '' && !preg_match('/^[+0-9() -]{7,30}$/', $parent_phone))
                    || (array_key_exists('parentBirthDate', $input) && $parent_birth_date === false)) {
                    $this->db->roll_back();
                    $this->api->respond_error('Complete all required child and emergency contact information with valid values.', 400);
                    return;
                }

                $corrections = [
                    'enrollment_type' => $enrollment_type,
                    'child_first_name' => $child_first,
                    'child_middle_name' => $child_middle !== '' ? $child_middle : null,
                    'child_last_name' => $child_last,
                    'child_nickname' => $child_nickname !== '' ? $child_nickname : null,
                    'child_birth_date' => $child_birth_date,
                    'child_gender' => $child_gender,
                    'child_address' => $child_address,
                    'child_psa_number' => $psa_number,
                    'child_nationality' => $nationality,
                    'child_place_of_birth' => $place_of_birth,
                    'child_blood_type' => $blood_type !== '' ? $blood_type : null,
                    'child_medical_condition' => $medical_condition !== '' ? $medical_condition : null,
                    'child_allergies' => $allergies !== '' ? $allergies : null,
                    'child_immunization_status' => $immunization_status !== '' ? $immunization_status : null,
                    'emergency_name' => $emergency_name,
                    'emergency_relationship' => $emergency_relationship,
                    'emergency_phone' => $emergency_phone,
                    'parent_name' => $parent_name,
                    'parent_relationship' => $parent_relationship,
                    'parent_email' => $parent_email,
                    'parent_phone' => $parent_phone,
                    'parent_birth_date' => $parent_birth_date ?: null,
                    'parent_gender' => $parent_gender !== '' ? $parent_gender : null,
                    'parent_address' => $parent_address,
                    'household_income' => $household_income !== '' ? $household_income : null,
                    'preferred_language' => $preferred_language !== '' ? $preferred_language : null,
                    'referral_source' => $referral_source !== '' ? $referral_source : null,
                ];
                $child = $this->db->table('children')
                    ->select('id')
                    ->where('id', (int) $application['child_id'])
                    ->where('parent_user_id', (int) $parent['id'])
                    ->get();
                if (!$child) {
                    throw new RuntimeException('The application child profile could not be verified.');
                }
                $this->db->table('children')
                    ->where('id', (int) $application['child_id'])
                    ->where('parent_user_id', (int) $parent['id'])
                    ->update([
                        'first_name' => $child_first,
                        'middle_name' => $child_middle !== '' ? $child_middle : null,
                        'last_name' => $child_last,
                        'suffix' => $child_suffix !== '' ? $child_suffix : null,
                        'nickname' => $child_nickname !== '' ? $child_nickname : null,
                        'birth_date' => $child_birth_date,
                        'gender' => $child_gender,
                        'address' => $child_address,
                        'psa_birth_certificate_number' => $psa_number,
                        'nationality' => $nationality,
                        'place_of_birth' => $place_of_birth,
                        'blood_type' => $blood_type !== '' ? $blood_type : null,
                        'medical_notes' => $medical_condition !== '' ? $medical_condition : null,
                        'allergies' => $allergies !== '' ? $allergies : null,
                        'immunization_status' => $immunization_status !== '' ? $immunization_status : null,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);

                $profile_updates = [];
                $profile_field_map = [
                    'parentGender' => ['gender', $parent_gender],
                    'parentBirthDate' => ['birth_date', $parent_birth_date ?: null],
                    'parentRelationship' => ['relationship', $parent_relationship],
                    'parentAddress' => ['address', $parent_address],
                    'parentBarangay' => ['barangay', $parent_barangay],
                    'householdIncome' => ['household_income', $household_income],
                    'preferredLanguage' => ['preferred_language', $preferred_language],
                    'referralSource' => ['referral_source', $referral_source],
                    'parentEmail' => ['contact_email', $parent_email],
                ];
                foreach ($profile_field_map as $input_key => [$column, $profile_value]) {
                    if (array_key_exists($input_key, $input)) {
                        $profile_updates[$column] = $profile_value !== '' ? $profile_value : null;
                    }
                }
                if ($profile_updates) {
                    $profile = $this->db->table('parent_profiles')
                        ->select('user_id')
                        ->where('user_id', (int) $parent['id'])
                        ->get();
                    $profile_updates['updated_at'] = date('Y-m-d H:i:s');
                    if ($profile) {
                        $this->db->table('parent_profiles')
                            ->where('user_id', (int) $parent['id'])
                            ->update($profile_updates);
                    } else {
                        $this->db->table('parent_profiles')->insert(
                            array_merge(['user_id' => (int) $parent['id']], $profile_updates)
                        );
                    }
                }
                if (array_key_exists('parentName', $input)) {
                    $name_parts = preg_split('/\s+/', trim($parent_name), -1, PREG_SPLIT_NO_EMPTY);
                    $this->db->table('users')
                        ->where('id', (int) $parent['id'])
                        ->update([
                            'first_name' => $name_parts[0] ?? '',
                            'middle_name' => count($name_parts) > 2
                                ? implode(' ', array_slice($name_parts, 1, -1))
                                : null,
                            'last_name' => count($name_parts) > 1
                                ? $name_parts[count($name_parts) - 1]
                                : '',
                        ]);
                }
                if (array_key_exists('parentPhone', $input)) {
                    $this->db->table('users')
                        ->where('id', (int) $parent['id'])
                        ->update(['phone' => $parent_phone]);
                }
            }

            $document_names = [];
            foreach ($files as $file) {
                $stored = $this->store_upload($file, $parent['id']);
                $moved_paths[] = $stored['absolute_path'];
                $this->db->table('enrollment_documents')->insert([
                    'application_id' => $application['id'],
                    'document_type' => $file['field'],
                    'original_name' => $file['name'],
                    'stored_name' => $stored['stored_name'],
                    'storage_path' => $stored['relative_path'],
                    'mime_type' => $stored['mime_type'],
                    'file_size' => $file['size'],
                    'sha256' => $stored['sha256'],
                    'verification_status' => 'received',
                ]);
                $document_names[] = str_replace('_', ' ', $file['field']);
            }

            $submitted_at = date('Y-m-d H:i:s');
            $application_updates = array_merge($corrections, [
                'status' => 'pending',
                'documents_requested_at' => null,
                'requested_requirements' => null,
                'request_remarks' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'submitted_at' => $submitted_at,
                'updated_at' => $submitted_at,
            ]);
            $this->db->table('enrollment_applications')
                ->where('id', (int) $application['id'])
                ->where('parent_user_id', (int) $parent['id'])
                ->update($application_updates);

            $this->db->table('enrollment_status_history')->insert([
                'application_id' => $application['id'],
                'changed_by' => $parent['id'],
                'from_status' => 'under_review',
                'to_status' => 'pending',
                'note' => 'Parent resubmitted requested information'
                    . ($document_names ? ' and documents: ' . implode(', ', $document_names) : '') . '.',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $resubmission_message = $application['application_number']
                . ' has updated information ready for review.';
            if (!empty($application['assigned_teacher_user_id'])) {
                $this->notify_user(
                    (int) $application['assigned_teacher_user_id'],
                    'Enrollment application resubmitted',
                    $resubmission_message,
                    'enrollment',
                    (int) $application['id']
                );
            } else {
                $this->notify_admins(
                    'Enrollment application awaiting teacher assignment',
                    $resubmission_message . ' Assign an active teacher for its CDC.',
                    (int) $application['id']
                );
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            foreach ($moved_paths as $path) {
                if (is_file($path)) unlink($path);
            }
            throw $error;
        }

        $this->api->respond([
            'status' => true,
            'message' => 'Application resubmitted for review.',
            'data' => [
                'status' => 'pending',
                'documents' => $this->document_metadata((int) $application['id']),
            ],
        ], 201);
    }

    public function assign_teacher($id)
    {
        $this->api->require_method('PATCH');
        $admin = $this->authenticated_user(['admin']);
        if (!$admin) return;

        $application_id = filter_var($id, FILTER_VALIDATE_INT);
        if (!$application_id || $application_id < 1) {
            $this->api->respond_error('Application not found.', 404);
            return;
        }
        $input = $this->api->body(false);
        $teacher_id = null;
        if (isset($input['teacher_id']) && $input['teacher_id'] !== '') {
            $teacher_id = filter_var($input['teacher_id'], FILTER_VALIDATE_INT);
            if (!$teacher_id || $teacher_id < 1) {
                $this->api->respond_error('Choose a valid active teacher.', 400);
                return;
            }
        }

        $this->db->transaction();
        try {
            $application = $this->db->raw(
                'SELECT id, application_number, cdc_id, status
                 FROM enrollment_applications WHERE id = ? FOR UPDATE',
                [$application_id]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$application) {
                $this->db->roll_back();
                $this->api->respond_error('Application not found.', 404);
                return;
            }
            if (in_array($application['status'], ['approved', 'rejected'], true)) {
                $this->db->roll_back();
                $this->api->respond_error('A final application cannot be reassigned.', 409);
                return;
            }
            $center = $this->db->raw(
                'SELECT id, status FROM cdcs WHERE id = ? FOR UPDATE',
                [(int) $application['cdc_id']]
            )->fetch(PDO::FETCH_ASSOC);
            $official_teacher = $this->db->raw(
                'SELECT u.id FROM teacher_profiles tp
                 JOIN users u ON u.id = tp.user_id
                 JOIN notifications review ON review.id = (
                     SELECT latest.id FROM notifications latest
                     WHERE latest.related_type = \'teacher_verification\'
                       AND latest.related_id = u.id ORDER BY latest.id DESC LIMIT 1
                 )
                 WHERE tp.cdc_id = ? AND tp.employment_status = \'active\'
                   AND u.role = \'teacher\' AND u.is_active = 1
                   AND review.title = \'Approved\'
                 LIMIT 1 FOR UPDATE',
                [(int) $application['cdc_id']]
            )->fetch(PDO::FETCH_ASSOC);
            if ($center && $center['status'] === 'active') {
                if (!$official_teacher) {
                    $this->db->roll_back();
                    $this->api->respond_error('The active CDC has no approved assigned teacher.', 409);
                    return;
                }
                if ($teacher_id !== null && (int) $teacher_id !== (int) $official_teacher['id']) {
                    $this->db->roll_back();
                    $this->api->respond_error('Applications must be assigned to their CDC\'s officially assigned teacher.', 409);
                    return;
                }
                $teacher_id = (int) $official_teacher['id'];
            } elseif ($teacher_id !== null) {
                $this->db->roll_back();
                $this->api->respond_error('An inactive CDC cannot receive a teacher-assigned enrollment application.', 409);
                return;
            }

            $this->db->table('enrollment_applications')
                ->where('id', $application_id)
                ->update(['assigned_teacher_user_id' => $teacher_id]);
            if ($teacher_id !== null) {
                $this->notify_user(
                    (int) $teacher_id,
                    'New enrollment application assigned',
                    $application['application_number'] . ' is ready for review.',
                    'enrollment',
                    (int) $application_id
                );
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        $this->api->respond([
            'status' => true,
            'message' => $teacher_id === null
                ? 'Application marked as awaiting teacher assignment.'
                : 'Application assigned to the selected teacher.',
            'data' => [
                'id' => (int) $application_id,
                'assigned_teacher_user_id' => $teacher_id !== null ? (int) $teacher_id : null,
            ],
        ]);
    }

    public function update_status($id)
    {
        $this->api->require_method('PATCH');
        $reviewer = $this->authenticated_user(['teacher']);
        if (!$reviewer) return;

        $application_id = filter_var($id, FILTER_VALIDATE_INT);
        if (!$application_id || $application_id < 1) {
            $this->api->respond_error('Application not found.', 404);
            return;
        }

        $reviewer_cdc = null;
        if ($reviewer['role'] === 'teacher') {
            $reviewer_cdc = $this->db->table('teacher_profiles')
                ->select('cdc_id')
                ->where('user_id', $reviewer['id'])
                ->where('employment_status', 'active')
                ->get();
            if (!$reviewer_cdc) {
                $this->api->respond_error('Teacher is not assigned to a CDC.', 403);
                return;
            }
        }

        $input = $this->api->body(false);
        $status = strtolower(trim((string) ($input['status'] ?? '')));
        $status = str_replace(' ', '_', $status);
        $note = $this->text($input, 'note', 3000);
        $request_documents = filter_var(
            $input['request_documents'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        $required_fields = [];
        if (is_array($input['required_fields'] ?? null)) {
            foreach ($input['required_fields'] as $required_field) {
                if (!is_string($required_field) || trim($required_field) === '') {
                    $this->api->respond_error('The selected requirement list is invalid.', 400);
                    return;
                }
                $required_fields[] = trim($required_field);
            }
            $required_fields = array_values(array_unique($required_fields));
        }
        $request_remarks = $this->text($input, 'remarks', 2000);
        if (!in_array($status, ['under_review', 'approved', 'rejected'], true)) {
            $this->api->respond_error('Choose Under Review, Approved, or Rejected.', 400);
            return;
        }
        if ($status === 'rejected' && $note === '') {
            $this->api->respond_error('A rejection reason is required.', 400);
            return;
        }
        if ($request_documents && $status !== 'under_review') {
            $this->api->respond_error('Additional documents can only be requested while an application is Under Review.', 400);
            return;
        }
        if ($request_documents && !$required_fields) {
            $this->api->respond_error('Select at least one field or document that needs attention.', 400);
            return;
        }

        $this->db->transaction();
        try {
            $application_sql =
                'SELECT a.id, a.application_number, a.parent_user_id, a.child_id, a.cdc_id,
                    a.school_year, a.enrollment_type, a.status, a.review_note, a.documents_requested_at,
                    a.submitted_at, a.parent_name, a.parent_relationship, a.parent_email,
                    a.parent_phone, a.parent_address, a.parent_birth_date, a.parent_gender,
                    a.household_income, a.preferred_language, a.referral_source,
                    a.emergency_name, a.emergency_relationship, a.emergency_phone,
                    COALESCE(a.child_first_name, c.first_name) AS child_first_name,
                    COALESCE(a.child_middle_name, c.middle_name) AS child_middle_name,
                    COALESCE(a.child_last_name, c.last_name) AS child_last_name,
                    c.suffix AS child_suffix,
                    COALESCE(a.child_nickname, c.nickname) AS child_nickname,
                    COALESCE(a.child_birth_date, c.birth_date) AS child_birth_date,
                    COALESCE(a.child_gender, c.gender) AS child_gender,
                    COALESCE(a.child_address, c.address) AS child_address,
                    COALESCE(a.child_psa_number, c.psa_birth_certificate_number) AS child_psa_number,
                    COALESCE(a.child_nationality, c.nationality) AS child_nationality,
                    COALESCE(a.child_place_of_birth, c.place_of_birth) AS child_place_of_birth,
                    COALESCE(a.child_blood_type, c.blood_type) AS child_blood_type,
                    COALESCE(a.child_medical_condition, c.medical_notes) AS child_medical_condition,
                    COALESCE(a.child_allergies, c.allergies) AS child_allergies,
                    COALESCE(a.child_immunization_status, c.immunization_status) AS child_immunization_status,
                    pp.gender AS parent_profile_gender, pp.birth_date AS parent_profile_birth_date,
                    pp.barangay AS parent_barangay, pp.address AS parent_profile_address,
                    pp.household_income AS parent_profile_household_income,
                    pp.preferred_language AS parent_profile_preferred_language,
                    pp.referral_source AS parent_profile_referral_source,
                    d.name AS cdc_name, cl.name AS class_name
                 FROM enrollment_applications a
                 JOIN children c ON c.id = a.child_id
                 JOIN cdcs d ON d.id = a.cdc_id
                 LEFT JOIN classes cl ON cl.id = c.class_id
                 LEFT JOIN parent_profiles pp ON pp.user_id = a.parent_user_id
                 WHERE a.id = ?';
            $application_params = [$application_id];
            if ($reviewer_cdc) {
                $application_sql .= ' AND a.cdc_id = ? AND a.assigned_teacher_user_id = ?';
                $application_params[] = (int) $reviewer_cdc['cdc_id'];
                $application_params[] = (int) $reviewer['id'];
            }
            $application = $this->db->raw(
                $application_sql . ' FOR UPDATE',
                $application_params
            )->fetch(PDO::FETCH_ASSOC);
            if (!$application) {
                $this->db->roll_back();
                $this->api->respond_error(
                    $reviewer_cdc
                        ? 'Application is not assigned to you.'
                        : 'Application not found.',
                    404
                );
                return;
            }
            if ($application['status'] === 'approved' || $application['status'] === 'rejected') {
                $this->db->roll_back();
                $this->api->respond_error('A final decision has already been recorded.', 409);
                return;
            }
            if ($application['documents_requested_at'] !== null
                && in_array($status, ['approved', 'rejected'], true)) {
                $this->db->roll_back();
                $this->api->respond_error('This application is awaiting parent corrections.', 409);
                return;
            }
            if ($request_documents && $application['documents_requested_at'] !== null) {
                $this->db->roll_back();
                $this->api->respond_error('This application is already awaiting parent corrections.', 409);
                return;
            }
            if ($request_documents) {
                $available_fields = $this->requirement_fields($application);
                foreach ($required_fields as $field_key) {
                    if (!is_string($field_key) || !array_key_exists($field_key, $available_fields)) {
                        $this->db->roll_back();
                        $this->api->respond_error('The selected requirement list contains an unsupported field.', 400);
                        return;
                    }
                }
                $selected_requirements = array_map(
                    static function ($key) use ($available_fields) {
                        return [
                            'key' => $key,
                            'label' => $available_fields[$key]['label'],
                            'value' => $available_fields[$key]['value'],
                        ];
                    },
                    $required_fields
                );
                $child_name = trim(implode(' ', array_filter([
                    $application['child_first_name'],
                    $application['child_middle_name'],
                    $application['child_last_name'],
                ])));
                $lines = array_map(
                    static function ($requirement) {
                        return '- ' . $requirement['label'] . ': ' . $requirement['value'];
                    },
                    $selected_requirements
                );
                $note = 'Hello ' . $application['parent_name'] . ",\n\n"
                    . 'We have reviewed the enrollment application for ' . $child_name . ".\n\n"
                    . "Please review, verify, or provide corrections for the following information:\n"
                    . implode("\n", $lines)
                    . ($request_remarks !== '' ? "\n\nAdditional Remarks:\n" . $request_remarks : '')
                    . "\n\nPlease log in to your KalingaLink Parent Account, open your child's enrollment application, and update the requested information.\n\n"
                    . "Thank you,\nKalingaLink – Child Development Center";
            }
            if ($application['status'] === $status
                && trim((string) $application['review_note']) === $note
                && (!$request_documents || $application['documents_requested_at'] !== null)) {
                $this->db->roll_back();
                $this->api->respond([
                    'status' => true,
                    'message' => 'Application status is unchanged.',
                    'data' => ['id' => (int) $id, 'status' => $status, 'note' => $note],
                ]);
                return;
            }

            $class = null;
            if ($status === 'approved') {
                $document_records =
                    $this->db->table('enrollment_documents')
                        ->select('document_type, verification_status')
                        ->where('application_id', $application['id'])
                        ->get_all();
                $document_types = [];
                foreach ($document_records as $document_record) {
                    if ($document_record['verification_status'] !== 'rejected') {
                        $document_types[] = $document_record['document_type'];
                    }
                }
                $required_documents = [
                    'birth_certificate',
                    'parent_id',
                    'parent_photo',
                    'barangay_clearance',
                    'proof_of_residence',
                ];
                if (trim((string) $application['child_medical_condition']) !== '') {
                    $required_documents[] = 'medical_certificate';
                }
                $missing_documents = array_diff($required_documents, $document_types);
                if ($missing_documents) {
                    $this->db->roll_back();
                    $this->api->respond_error(
                        'This application cannot be approved until the required documents are available: '
                            . implode(', ', array_map(static function ($type) {
                                return str_replace('_', ' ', $type);
                            }, $missing_documents)) . '.',
                        409
                    );
                    return;
                }

                $center = $this->db->raw(
                    'SELECT id, capacity FROM cdcs WHERE id = ? AND status = \'active\' FOR UPDATE',
                    [$application['cdc_id']]
                )->fetch(PDO::FETCH_ASSOC);
                if (!$center) {
                    $this->db->roll_back();
                    $this->api->respond_error(
                        'This application cannot be approved because its CDC is no longer active.',
                        409
                    );
                    return;
                }

                $enrolled_at_center = (int) $this->db->raw(
                    'SELECT COUNT(*) FROM children c
                     LEFT JOIN classes cl ON cl.id = c.class_id
                     WHERE c.enrollment_status = \'enrolled\'
                       AND COALESCE(c.preferred_cdc_id, cl.cdc_id) = ?
                       AND c.id <> ?',
                    [$center['id'], $application['child_id']]
                )->fetchColumn();
                if ((int) $center['capacity'] > 0 && $enrolled_at_center >= (int) $center['capacity']) {
                    $this->db->roll_back();
                    $this->api->respond_error(
                        'This CDC has reached its enrollment capacity.',
                        409
                    );
                    return;
                }

                $classes = $this->db->raw(
                    'SELECT id FROM classes
                     WHERE cdc_id = ? AND status = \'active\' AND school_year = ?
                     ORDER BY id ASC',
                    [$center['id'], $application['school_year']]
                )->fetchAll(PDO::FETCH_COLUMN);
                if (!$classes) {
                    $existing_class_count = (int) $this->db->raw(
                        'SELECT COUNT(*) FROM classes WHERE cdc_id = ? AND school_year = ?',
                        [$center['id'], $application['school_year']]
                    )->fetchColumn();
                    if ($existing_class_count === 0) {
                        $this->db->table('classes')->insert([
                            'cdc_id' => $center['id'],
                            'teacher_user_id' => null,
                            'name' => 'Enrollment ' . $application['school_year'],
                            'school_year' => $application['school_year'],
                            'capacity' => 0,
                            'status' => 'active',
                            'created_at' => date('Y-m-d H:i:s'),
                        ]);
                        $classes = [$this->db->last_id()];
                    }
                }
                foreach ($classes as $class_id) {
                    $candidate = $this->db->raw(
                        'SELECT id, capacity FROM classes WHERE id = ? FOR UPDATE',
                        [$class_id]
                    )->fetch(PDO::FETCH_ASSOC);
                    if (!$candidate) continue;
                    $enrolled_count = (int) $this->db->raw(
                        'SELECT COUNT(*) FROM class_students WHERE class_id = ? AND child_id <> ?',
                        [$candidate['id'], $application['child_id']]
                    )->fetchColumn();
                    if ((int) $candidate['capacity'] === 0 || $enrolled_count < (int) $candidate['capacity']) {
                        $class = $candidate;
                        break;
                    }
                }
                if (!$class) {
                    $this->db->roll_back();
                    $this->api->respond_error(
                        'No active class with available capacity exists at this CDC for this school year.',
                        409
                    );
                    return;
                }
            }

            $application_update = [
                'status' => $status,
                'review_note' => $note !== '' ? $note : null,
                'reviewed_by' => $reviewer['id'],
                'reviewed_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($request_documents) {
                $application_update['documents_requested_at'] = date('Y-m-d H:i:s');
                $application_update['requested_requirements'] = json_encode(
                    $selected_requirements,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                $application_update['request_remarks'] = $request_remarks !== '' ? $request_remarks : null;
            } elseif ($status === 'approved' || $status === 'rejected') {
                $application_update['documents_requested_at'] = null;
            }
            $this->db->table('enrollment_applications')
                ->where('id', $id)
                ->update($application_update);
            if ($status === 'approved') {
                if ($application['child_first_name'] !== null) {
                    $this->db->raw(
                        "UPDATE children SET first_name = ?, middle_name = ?, last_name = ?,
                         nickname = ?, birth_date = ?, gender = ?, address = ?,
                         psa_birth_certificate_number = ?, nationality = ?, place_of_birth = ?,
                         blood_type = ?, medical_notes = ?, allergies = ?, immunization_status = ?,
                         preferred_cdc_label = (SELECT name FROM cdcs WHERE id = ?),
                         enrollment_status = 'enrolled', preferred_cdc_id = ?, class_id = ?,
                         enrolled_at = COALESCE(enrolled_at, CURDATE()), updated_at = NOW()
                         WHERE id = ?",
                        [
                            $application['child_first_name'],
                            $application['child_middle_name'],
                            $application['child_last_name'],
                            $application['child_nickname'],
                            $application['child_birth_date'],
                            $application['child_gender'],
                            $application['child_address'],
                            $application['child_psa_number'],
                            $application['child_nationality'],
                            $application['child_place_of_birth'],
                            $application['child_blood_type'],
                            $application['child_medical_condition'],
                            $application['child_allergies'],
                            $application['child_immunization_status'],
                            $application['cdc_id'],
                            $application['cdc_id'],
                            $class['id'],
                            $application['child_id'],
                        ]
                    );
                } else {
                    $this->db->raw(
                        "UPDATE children SET enrollment_status = 'enrolled',
                         preferred_cdc_id = ?, class_id = ?,
                         enrolled_at = COALESCE(enrolled_at, CURDATE()), updated_at = NOW()
                         WHERE id = ?",
                        [$application['cdc_id'], $class['id'], $application['child_id']]
                    );
                }
                $this->db->raw(
                    'DELETE FROM class_students WHERE child_id = ? AND class_id <> ?',
                    [$application['child_id'], $class['id']]
                );
                $existing = $this->db->table('class_students')
                    ->select('id')
                    ->where('class_id', $class['id'])
                    ->where('child_id', $application['child_id'])
                    ->get();
                if (!$existing) {
                    $this->db->table('class_students')->insert([
                        'class_id' => $class['id'],
                        'child_id' => $application['child_id'],
                        'enrolled_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }

            $this->db->table('enrollment_status_history')->insert([
                'application_id' => $application['id'],
                'changed_by' => $reviewer['id'],
                'from_status' => $application['status'],
                'to_status' => $status,
                'note' => $note !== '' ? $note : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $review_date = date('F j, Y');
            $notification_title = $request_documents
                ? 'Enrollment Information Requires Attention'
                : ($status === 'rejected'
                    ? 'Enrollment Application Rejected'
                    : ($status === 'approved'
                        ? 'Enrollment Application Approved'
                        : 'Enrollment application update'));
            if ($request_documents) {
                $notification_message = $note;
            } elseif ($status === 'rejected') {
                $child_name = trim(implode(' ', array_filter([
                    $application['child_first_name'],
                    $application['child_middle_name'],
                    $application['child_last_name'],
                ])));
                $notification_message = 'Your enrollment application for ' . $child_name
                    . ' (' . $application['application_number'] . ') has been rejected on '
                    . $review_date . ".\n\nReason for Rejection:\n" . $note
                    . "\n\nPlease contact " . $application['cdc_name']
                    . ' for clarification or assistance.';
            } elseif ($status === 'approved') {
                $notification_message = $application['application_number']
                    . ' for ' . trim(implode(' ', array_filter([
                        $application['child_first_name'],
                        $application['child_middle_name'],
                        $application['child_last_name'],
                    ]))) . ' has been approved.';
            } else {
                $notification_message = $application['application_number']
                    . ' is now ' . str_replace('_', ' ', $status) . ($note !== '' ? ': ' . $note : '.');
            }
            $this->notify_user(
                (int) $application['parent_user_id'],
                $notification_title,
                $notification_message,
                'enrollment',
                (int) $application['id']
            );
            $this->notify_admins(
                $notification_title,
                $notification_message,
                (int) $application['id']
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        $this->api->respond([
            'status' => true,
            'message' => 'Application status updated.',
            'data' => ['id' => (int) $id, 'status' => $status, 'note' => $note],
        ]);
    }

    public function download_document($application_id, $document_id)
    {
        $user = $this->authenticated_user(['parent', 'teacher', 'admin']);
        if (!$user) return;
        $document = $this->db->raw(
            'SELECT d.original_name, d.storage_path, d.mime_type, a.parent_user_id,
                    a.cdc_id, a.assigned_teacher_user_id
             FROM enrollment_documents d JOIN enrollment_applications a ON a.id = d.application_id
             WHERE d.id = ? AND a.id = ? LIMIT 1',
            [$document_id, $application_id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$document || !$this->can_access_application($user, $document)) {
            $this->api->respond_error('Document not found.', 404);
            return;
        }

        $root = realpath(ROOT_DIR);
        $path = realpath(ROOT_DIR . $document['storage_path']);
        if (!$root || !$path || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
            $this->api->respond_error('Document file is unavailable.', 404);
            return;
        }

        header('Content-Type: ' . $document['mime_type']);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . rawurlencode($document['original_name']) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
    }

    private function authenticated_user(array $roles)
    {
        $payload = $this->api->require_jwt();
        $user_id = filter_var($payload['sub'] ?? null, FILTER_VALIDATE_INT);
        if (!$user_id) {
            $this->api->respond_error('Unauthorized.', 401);
            return null;
        }
        $this->call->model('UsersModel');
$user = $this->UsersModel->find_by_id($user_id);
        if (!$user || (int) $user['is_active'] !== 1) {
            $this->api->respond_error('Account is unavailable.', 401);
            return null;
        }
        if (!in_array($user['role'], $roles, true)) {
            $this->api->respond_error('You are not authorized to perform this action.', 403);
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

    private function can_access_application(array $user, array $application)
    {
        if ($user['role'] === 'admin') return true;
        if ($user['role'] === 'parent') return (int) $user['id'] === (int) $application['parent_user_id'];
        $teacher = $this->db->table('teacher_profiles')
            ->where('user_id', $user['id'])
            ->where('employment_status', 'active')
            ->get();
        return $teacher
            && (int) $teacher['cdc_id'] === (int) $application['cdc_id']
            && (int) ($application['assigned_teacher_user_id'] ?? 0) === (int) $user['id'];
    }

    private function document_metadata($application_id)
    {
        $documents = $this->db->table('enrollment_documents')
            ->select('id, document_type, original_name, mime_type, file_size, verification_status, uploaded_at')
            ->where('application_id', $application_id)
            ->order_by('id', 'ASC')
            ->get_all();
        foreach ($documents as &$document) {
            $document['download_url'] = '/api/enrollments/' . $application_id . '/documents/' . $document['id'];
        }
        unset($document);
        return $documents;
    }

    private function history($application_id)
    {
        return $this->db->raw(
            'SELECT h.from_status, h.to_status, h.note, h.created_at,
                    u.first_name AS reviewer_first_name, u.last_name AS reviewer_last_name,
                    u.role AS reviewer_role
             FROM enrollment_status_history h
             LEFT JOIN users u ON u.id = h.changed_by
             WHERE h.application_id = ?
             ORDER BY h.id ASC',
            [(int) $application_id]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function requirement_fields(array $application)
    {
        $value = static function ($item) {
            if ($item === null || trim((string) $item) === '') return 'Not provided';
            return (string) $item;
        };
        $birth_date = $application['child_birth_date'] ?? null;
        $age = 'Not provided';
        if ($birth_date) {
            try {
                $age = (string) (new DateTimeImmutable($birth_date))->diff(new DateTimeImmutable('today'))->y;
            } catch (Throwable $error) {
                $age = 'Not provided';
            }
        }
        $fields = [
            'child_id' => ['Child ID', 'CHD-' . str_pad((string) $application['child_id'], 6, '0', STR_PAD_LEFT)],
            'child_first_name' => ['First Name', $value($application['child_first_name'] ?? null)],
            'child_middle_name' => ['Middle Name', $value($application['child_middle_name'] ?? null)],
            'child_last_name' => ['Last Name', $value($application['child_last_name'] ?? null)],
            'child_suffix' => ['Suffix', $value($application['child_suffix'] ?? null)],
            'child_nickname' => ['Nickname', $value($application['child_nickname'] ?? null)],
            'child_gender' => ['Gender', $value($application['child_gender'] ?? null)],
            'child_birth_date' => ['Birthdate', $value($birth_date)],
            'child_age' => ['Age', $age],
            'child_address' => ['Address', $value($application['child_address'] ?? null)],
            'child_psa_number' => ['PSA Birth Certificate Number', $value($application['child_psa_number'] ?? null)],
            'child_nationality' => ['Nationality', $value($application['child_nationality'] ?? null)],
            'child_place_of_birth' => ['Place of Birth', $value($application['child_place_of_birth'] ?? null)],
            'child_blood_type' => ['Blood Type', $value($application['child_blood_type'] ?? null)],
            'child_allergies' => ['Allergies', $value($application['child_allergies'] ?? null)],
            'child_medical_condition' => ['Medical Notes', $value($application['child_medical_condition'] ?? null)],
            'child_immunization_status' => ['Immunization Status', $value($application['child_immunization_status'] ?? null)],
            'parent_name' => ['Full Name', $value($application['parent_name'] ?? null)],
            'parent_gender' => ['Gender', $value($application['parent_gender'] ?? $application['parent_profile_gender'] ?? null)],
            'parent_birth_date' => ['Birthdate', $value($application['parent_birth_date'] ?? $application['parent_profile_birth_date'] ?? null)],
            'parent_phone' => ['Contact Number', $value($application['parent_phone'] ?? null)],
            'parent_email' => ['Email Address', $value($application['parent_email'] ?? null)],
            'parent_relationship' => ['Relationship to Child', $value($application['parent_relationship'] ?? null)],
            'parent_address' => ['Address', $value($application['parent_address'] ?? $application['parent_profile_address'] ?? null)],
            'parent_barangay' => ['Barangay', $value($application['parent_barangay'] ?? null)],
            'household_income' => ['Household Income Range', $value($application['household_income'] ?? null)],
            'preferred_language' => ['Preferred Language', $value($application['preferred_language'] ?? null)],
            'referral_source' => ['How They Learned About the CDC', $value($application['referral_source'] ?? null)],
            'emergency_name' => ['Contact Person', $value($application['emergency_name'] ?? null)],
            'emergency_relationship' => ['Relationship to Child', $value($application['emergency_relationship'] ?? null)],
            'emergency_phone' => ['Phone Number', $value($application['emergency_phone'] ?? null)],
            'application_number' => ['Enrollment Application ID', $value($application['application_number'] ?? null)],
            'application_date' => ['Application Date', $value($application['submitted_at'] ?? null)],
            'cdc_name' => ['Child Development Center Selection', $value($application['cdc_name'] ?? null)],
            'assigned_cdc_class' => ['Assigned CDC / Class', $value($application['class_name'] ?? null)],
            'application_status' => ['Enrollment Status', $value($application['status'] ?? null)],
            'enrollment_type' => ['Enrollment Type', ($application['enrollment_type'] ?? '') === 'new' ? 'New Enrollment' : 'Re-enrollment'],
        ];
        $document_types = [
            'birth_certificate' => 'PSA Birth Certificate',
            'parent_id' => 'Parent/Guardian Valid ID',
            'parent_photo' => 'Parent/Guardian Photo',
            'barangay_clearance' => 'Barangay Clearance',
            'proof_of_residence' => 'Proof of Residence',
            'medical_certificate' => 'Medical Certificate',
        ];
        $documents = $this->db->table('enrollment_documents')
            ->select('document_type, original_name, verification_status')
            ->where('application_id', (int) $application['id'])
            ->get_all();
        $document_values = [];
        foreach ($documents as $document) {
            $document_values[$document['document_type']][] =
                $document['original_name'] . ' (' . $document['verification_status'] . ')';
        }
        foreach ($document_types as $type => $label) {
            $fields['document_' . $type] = [
                $label,
                isset($document_values[$type])
                    ? implode(', ', $document_values[$type])
                    : 'Not provided',
            ];
        }

        $result = [];
        foreach ($fields as $key => [$label, $current_value]) {
            $result[$key] = ['label' => $label, 'value' => $current_value];
        }
        return $result;
    }

    private function decode_requested_requirements($encoded)
    {
        if (!is_string($encoded) || $encoded === '') return [];
        $decoded = json_decode($encoded, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function collect_uploads()
    {
        $uploads = [];
        $allowed_types = [
            'birth_certificate',
            'parent_id',
            'parent_photo',
            'barangay_clearance',
            'proof_of_residence',
            'medical_certificate',
        ];
        $extensions = [
            'application/pdf' => ['pdf'],
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
        ];
        foreach ($_FILES as $field => $file) {
            if (!is_array($file) || is_array($file['name'] ?? null)
                || strpos($field, 'document_') !== 0) {
                continue;
            }
            $document_type = substr($field, strlen('document_'));
            if (!in_array($document_type, $allowed_types, true)) {
                $this->api->respond_error('An unsupported enrollment document was submitted.', 400);
                return [];
            }
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
                $this->api->respond_error('One of the uploaded documents could not be received.', 400);
                return [];
            }
            $max_size = $document_type === 'parent_photo' ? 2 * 1024 * 1024 : 5 * 1024 * 1024;
            if (($file['size'] ?? 0) <= 0 || $file['size'] > $max_size) {
                $this->api->respond_error(
                    $document_type === 'parent_photo'
                        ? 'The parent photo must be no larger than 2 MB.'
                        : 'Each enrollment document must be no larger than 5 MB.',
                    400
                );
                return [];
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
            $allowed_mimes = $document_type === 'parent_photo'
                ? ['image/jpeg', 'image/png']
                : ['application/pdf', 'image/jpeg', 'image/png'];
            if (!in_array($mime, $allowed_mimes, true)
                || !in_array($extension, $extensions[$mime] ?? [], true)) {
                $this->api->respond_error('Document file content and extension must match an accepted PDF, JPG, or PNG format.', 400);
                return [];
            }
            $uploads[] = [
                'field' => $document_type,
                'name' => basename((string) $file['name']),
                'tmp_name' => $file['tmp_name'],
                'size' => (int) $file['size'],
                'mime' => $mime,
            ];
        }
        return $uploads;
    }

    private function store_upload(array $file, $parent_id)
    {
        $extension = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ][$file['mime']];
        $directory = ROOT_DIR . 'runtime/uploads/enrollment/' . (int) $parent_id;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the private enrollment upload directory.');
        }
        $stored_name = bin2hex(random_bytes(20)) . '.' . $extension;
        $absolute_path = $directory . DIRECTORY_SEPARATOR . $stored_name;
        if (!move_uploaded_file($file['tmp_name'], $absolute_path)) {
            throw new RuntimeException('Could not store an uploaded document.');
        }
        return [
            'stored_name' => $stored_name,
            'absolute_path' => $absolute_path,
            'relative_path' => 'runtime/uploads/enrollment/' . (int) $parent_id . '/' . $stored_name,
            'mime_type' => $file['mime'],
            'sha256' => hash_file('sha256', $absolute_path),
        ];
    }

    private function notify_user($user_id, $title, $message, $related_type, $related_id)
    {
        $this->db->table('notifications')->insert([
            'title' => $title,
            'message' => $message,
            'audience' => 'single',
            'related_type' => $related_type,
            'related_id' => $related_id,
            'related_module' => 'enrollment',
            'notification_type' => 'Enrollment',
        ]);
        $this->insert_recipients($this->db->last_id(), [$user_id]);
    }

    private function notify_admins($title, $message, $related_id)
    {
        $admins = $this->db->raw(
            'SELECT id FROM users WHERE role = \'admin\' AND is_active = 1'
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!$admins) return;
        $this->db->table('notifications')->insert([
            'title' => $title,
            'message' => $message,
            'audience' => 'single',
            'related_type' => 'enrollment',
            'related_id' => $related_id,
            'related_module' => 'enrollment',
            'notification_type' => 'Enrollment',
        ]);
        $this->insert_recipients($this->db->last_id(), $admins);
    }

    private function insert_recipients($notification_id, array $user_ids)
    {
        foreach ($user_ids as $user_id) {
            $this->db->table('notification_recipients')->insert([
                'notification_id' => $notification_id,
                'user_id' => $user_id,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function current_school_year()
    {
        return date('n') >= 6 ? date('Y') . '-' . (date('Y') + 1) : (date('Y') - 1) . '-' . date('Y');
    }

    private function valid_date($value, $optional = false)
    {
        if ($optional && ($value === null || $value === '')) return null;
        if (!is_string($value)) return false;
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value && $value <= date('Y-m-d') ? $value : false;
    }

    private function text(array $input, $key, $max_length)
    {
        $value = $input[$key] ?? '';
        return is_string($value) ? mb_substr(trim($value), 0, $max_length) : '';
    }
}