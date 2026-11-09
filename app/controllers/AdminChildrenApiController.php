<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminChildrenApiController extends Controller
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
        $admin = $this->authenticated_admin();
        if (!$admin) return;

        $children = $this->db->raw(
            'SELECT c.id, c.first_name, c.middle_name, c.last_name, c.birth_date,
                    c.gender, c.enrollment_status, c.created_at,
                    COALESCE(d.name, \'Unassigned\') AS center_name,
                    TIMESTAMPDIFF(YEAR, c.birth_date, CURDATE()) AS age
             FROM children c
             LEFT JOIN classes cl ON cl.id = c.class_id
             LEFT JOIN cdcs d ON d.id = COALESCE(c.preferred_cdc_id, cl.cdc_id)
             ORDER BY c.created_at DESC, c.id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $totals = $this->db->raw(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN LOWER(gender) = \'male\' THEN 1 ELSE 0 END) AS male,
                    SUM(CASE WHEN LOWER(gender) = \'female\' THEN 1 ELSE 0 END) AS female,
                    SUM(CASE WHEN LOWER(gender) NOT IN (\'male\', \'female\') OR gender IS NULL THEN 1 ELSE 0 END) AS other,
                    SUM(CASE WHEN enrollment_status = \'pending\' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN enrollment_status = \'enrolled\' THEN 1 ELSE 0 END) AS enrolled
             FROM children'
        )->fetch(PDO::FETCH_ASSOC);

        $age_rows = $this->db->raw(
            'SELECT CASE
                        WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) <= 2 THEN \'0-2\'
                        WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) <= 4 THEN \'3-4\'
                        WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) <= 6 THEN \'5-6\'
                        WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) <= 12 THEN \'7-12\'
                        ELSE \'13+\'
                    END AS age_group,
                    COUNT(*) AS total
             FROM children
             GROUP BY age_group'
        )->fetchAll(PDO::FETCH_ASSOC);
        $age_counts = [];
        foreach ($age_rows as $row) {
            $age_counts[$row['age_group']] = (int) $row['total'];
        }

        $centers = $this->db->table('cdcs')
            ->select('id, name')
            ->order_by('name', 'ASC')
            ->get_all();

        $serialize_child = static function ($child) {
            return [
                'id' => (int) $child['id'],
                'name' => trim(implode(' ', array_filter([
                    $child['first_name'],
                    $child['middle_name'],
                    $child['last_name'],
                ]))),
                'age' => (int) $child['age'],
                'gender' => $child['gender'] ?: 'Other',
                'center' => $child['center_name'],
                'status' => $child['enrollment_status'],
                'created_at' => $child['created_at'],
            ];
        };

        $this->api->respond([
            'status' => true,
            'data' => [
                'children' => array_map($serialize_child, $children),
                'centers' => array_map(static function ($center) {
                    return [
                        'id' => (int) $center['id'],
                        'name' => $center['name'],
                    ];
                }, $centers),
                'stats' => array_map('intval', $totals),
                'age_groups' => [
                    ['label' => '0–2', 'count' => $age_counts['0-2'] ?? 0],
                    ['label' => '3–4', 'count' => $age_counts['3-4'] ?? 0],
                    ['label' => '5–6', 'count' => $age_counts['5-6'] ?? 0],
                    ['label' => '7–12', 'count' => $age_counts['7-12'] ?? 0],
                    ['label' => '13+', 'count' => $age_counts['13+'] ?? 0],
                ],
                'recent_children' => array_map(
                    $serialize_child,
                    array_slice($children, 0, 5)
                ),
            ],
        ]);
    }

    public function show($id)
    {
        $this->show_child_details($id);
    }

    public function show_application($id)
    {
        $this->api->require_method('GET');
        $reviewer = $this->authenticated_reviewer();
        if (!$reviewer) return;

        $application_id = filter_var($id, FILTER_VALIDATE_INT);
        if (!$application_id || $application_id < 1) {
            $this->api->respond_error('Enrollment application not found.', 404);
            return;
        }
        $application = $this->db->table('enrollment_applications')
            ->select('child_id, cdc_id, assigned_teacher_user_id')
            ->where('id', $application_id)
            ->get();
        if (!$application) {
            $this->api->respond_error('Enrollment application not found.', 404);
            return;
        }

        if ($reviewer['role'] === 'teacher') {
            $assignment = $this->db->table('teacher_profiles')
                ->select('user_id')
                ->where('user_id', (int) $reviewer['id'])
                ->where('cdc_id', (int) $application['cdc_id'])
                ->where('employment_status', 'active')
                ->get();
            if (!$application['cdc_id'] || !$assignment
                || (int) ($application['assigned_teacher_user_id'] ?? 0) !== (int) $reviewer['id']) {
                $this->api->respond_error('Application not found for your assigned CDC.', 404);
                return;
            }
        }

        $this->show_child_details($application['child_id'], $application_id, $reviewer);
    }

    private function show_child_details($id, $application_id = null, $reviewer = null)
    {
        $this->api->require_method('GET');
        if ($reviewer === null) {
            $reviewer = $this->authenticated_admin();
            if (!$reviewer) return;
        }

        $child_id = filter_var($id, FILTER_VALIDATE_INT);
        if (!$child_id || $child_id < 1) {
            $this->api->respond_error('Child record not found.', 404);
            return;
        }

        $child = $this->db->raw(
            'SELECT c.id, c.parent_user_id, c.first_name, c.middle_name, c.last_name,
                    c.suffix, c.nickname, c.birth_date, TIMESTAMPDIFF(YEAR, c.birth_date, CURDATE()) AS age,
                    c.gender, c.address, c.enrollment_status, c.medical_notes,
                    c.psa_birth_certificate_number, c.nationality, c.place_of_birth,
                    c.blood_type, c.allergies, c.immunization_status, c.enrolled_at,
                    c.created_at, c.preferred_cdc_id, c.class_id,
                    COALESCE(preferred.name, assigned.name, \'Unassigned\') AS center_name,
                    assigned.name AS assigned_center_name, cl.name AS class_name,
                    parent.first_name AS parent_first_name,
                    parent.middle_name AS parent_middle_name,
                    parent.last_name AS parent_last_name,
                    parent.email AS parent_account_email,
                    parent.phone AS parent_account_phone,
                    pp.birth_date AS parent_birth_date, pp.gender AS parent_gender,
                    pp.relationship AS parent_relationship, pp.address AS parent_address,
                    pp.barangay AS parent_barangay,
                    pp.contact_email AS parent_contact_email, pp.suffix AS parent_suffix,
                    pp.municipality AS parent_municipality, pp.province AS parent_province,
                    pp.postal_code AS parent_postal_code,
                    pp.emergency_name AS profile_emergency_name,
                    pp.emergency_relationship AS profile_emergency_relationship,
                    pp.emergency_phone AS profile_emergency_phone,
                    pp.household_income, pp.preferred_language, pp.referral_source
             FROM children c
             JOIN users parent ON parent.id = c.parent_user_id
             LEFT JOIN parent_profiles pp ON pp.user_id = parent.id
             LEFT JOIN classes cl ON cl.id = c.class_id
             LEFT JOIN cdcs preferred ON preferred.id = c.preferred_cdc_id
             LEFT JOIN cdcs assigned ON assigned.id = cl.cdc_id
             WHERE c.id = ? LIMIT 1',
            [$child_id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$child) {
            $this->api->respond_error('Child record not found.', 404);
            return;
        }
        $child['id'] = (int) $child['id'];
        $child['age'] = (int) $child['age'];

        $applications = $this->db->raw(
            'SELECT a.id, a.application_number, a.parent_user_id, a.child_id, a.cdc_id,
                    a.enrollment_type, a.school_year, a.parent_name, a.parent_relationship,
                    a.parent_email, a.parent_phone, a.parent_birth_date, a.parent_gender,
                    a.parent_address, a.emergency_name, a.emergency_relationship, a.emergency_phone,
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
                    c.enrolled_at, cl.name AS child_class_name,
                    pp.barangay AS parent_barangay,
                    d.name AS cdc_name, d.address AS cdc_address,
                    d.contact_number AS cdc_contact_number,
                    d.name AS assigned_cdc_name,
                    reviewer.first_name AS reviewer_first_name,
                    reviewer.last_name AS reviewer_last_name,
                    reviewer.role AS reviewer_role
             FROM enrollment_applications a
             JOIN children c ON c.id = a.child_id
             LEFT JOIN parent_profiles pp ON pp.user_id = a.parent_user_id
             LEFT JOIN classes cl ON cl.id = c.class_id
             LEFT JOIN cdcs d ON d.id = a.cdc_id
             LEFT JOIN users reviewer ON reviewer.id = a.reviewed_by
             WHERE a.child_id = ? AND a.parent_user_id = c.parent_user_id'
            . ($application_id === null ? '' : ' AND a.id = ?') . '
             ORDER BY a.submitted_at DESC, a.id DESC',
            $application_id === null ? [$child_id] : [$child_id, $application_id]
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($applications as &$application) {
            $application['id'] = (int) $application['id'];
            $application['child_id'] = (int) $application['child_id'];
            $application['requested_requirements'] = is_string($application['requested_requirements'])
                ? (json_decode($application['requested_requirements'], true) ?: [])
                : [];
            $application['documents'] = $this->document_metadata($application['id']);
            $application['review_history'] = $this->history((int) $application['id']);
        }
        unset($application);

        $this->api->respond([
            'status' => true,
            'data' => [
                'child' => $child,
                'applications' => $applications,
            ],
        ]);
    }

    private function document_metadata($application_id)
    {
        $documents = $this->db->table('enrollment_documents')
            ->select('id, document_type, original_name, mime_type, file_size, verification_status, uploaded_at')
            ->where('application_id', (int) $application_id)
            ->order_by('id', 'ASC')
            ->get_all();
        foreach ($documents as &$document) {
            $document['download_url'] = '/api/enrollments/' . (int) $application_id
                . '/documents/' . (int) $document['id'];
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

    private function authenticated_admin()
    {
        return $this->authenticated_reviewer(['admin']);
    }

    private function authenticated_reviewer($roles = ['admin', 'teacher'])
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
            $this->api->respond_error('You are not authorized to view this enrollment application.', 403);
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
}
