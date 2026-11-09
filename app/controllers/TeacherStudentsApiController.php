<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class TeacherStudentsApiController extends Controller
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
        $user = $this->authenticated_teacher();
        if (!$user) return;

        $students = $this->db->raw(
            'SELECT c.id, c.first_name, c.middle_name, c.last_name, c.nickname,
                    c.birth_date, c.gender, c.address, c.medical_notes,
                    c.psa_birth_certificate_number, c.nationality, c.place_of_birth,
                    c.blood_type, c.allergies, c.immunization_status,
                    cl.class_names,
                    CASE WHEN ea.parent_name IS NOT NULL THEN ea.parent_name ELSE parent.first_name END AS guardian_first_name,
                    CASE WHEN ea.parent_name IS NOT NULL THEN NULL ELSE parent.last_name END AS guardian_last_name,
                    COALESCE(NULLIF(ea.parent_phone, \'\'), parent.phone) AS guardian_phone,
                    parent.email AS guardian_email,
                    COALESCE(NULLIF(ea.parent_relationship, \'\'), pp.relationship) AS guardian_relationship,
                    COALESCE(NULLIF(ea.parent_address, \'\'), pp.address) AS guardian_address,
                    COALESCE(NULLIF(ea.emergency_name, \'\'), pp.emergency_name) AS emergency_name,
                    COALESCE(NULLIF(ea.emergency_relationship, \'\'), pp.emergency_relationship) AS emergency_relationship,
                    COALESCE(NULLIF(ea.emergency_phone, \'\'), pp.emergency_phone) AS emergency_phone
             FROM (
                SELECT cs.child_id,
                       GROUP_CONCAT(DISTINCT cl.name ORDER BY cl.name SEPARATOR \', \') AS class_names
                FROM class_students cs
                JOIN classes cl ON cl.id = cs.class_id
                JOIN teacher_profiles tp ON tp.cdc_id = cl.cdc_id
                WHERE tp.user_id = ? AND tp.employment_status = \'active\'
                  AND cl.status = \'active\'
                GROUP BY cs.child_id
             ) cl
             JOIN children c ON c.id = cl.child_id
             JOIN users parent ON parent.id = c.parent_user_id
             LEFT JOIN parent_profiles pp ON pp.user_id = parent.id
             LEFT JOIN (
                SELECT child_id, MAX(id) AS application_id
                FROM enrollment_applications
                WHERE status = \'approved\'
                GROUP BY child_id
             ) latest_application ON latest_application.child_id = c.id
             LEFT JOIN enrollment_applications ea ON ea.id = latest_application.application_id
             WHERE c.enrollment_status = \'enrolled\'
             ORDER BY c.last_name, c.first_name, c.id',
            [(int) $user['id']]
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->api->respond([
            'status' => true,
            'data' => $students,
        ]);
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
            $this->api->respond_error('You are not authorized to view these students.', 403);
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
