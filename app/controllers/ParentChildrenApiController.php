<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ParentChildrenApiController extends Controller
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
        $parent = $this->authenticated_parent();
        if (!$parent) return;

        $children = $this->db->raw(
                'SELECT c.id, c.first_name, c.middle_name, c.last_name, c.suffix, c.nickname,
                    c.birth_date, c.gender, c.enrollment_status,
                    c.psa_birth_certificate_number, c.nationality, c.place_of_birth,
                    c.address, c.enrolled_at, c.blood_type, c.allergies,
                    c.medical_notes, c.immunization_status, c.residence_barangay,
                    c.preferred_cdc_label, a.id AS application_id,
                    a.status AS latest_application_status,
                    CASE
                        WHEN c.enrollment_status = \'enrolled\' THEN \'enrolled\'
                        WHEN a.status IN (\'pending\', \'under_review\', \'rejected\') THEN a.status
                        ELSE c.enrollment_status
                    END AS application_status,
                    a.enrollment_type, a.emergency_name, a.emergency_relationship,
                    a.emergency_phone,
                    COALESCE(pp.emergency_name, \'\') AS profile_emergency_name,
                    COALESCE(pp.emergency_relationship, \'\') AS profile_emergency_relationship,
                    COALESCE(pp.emergency_phone, \'\') AS profile_emergency_phone,
                    COALESCE(pp.address, \'\') AS parent_address,
                    COALESCE(cdc.name, \'\') AS center_name,
                    COALESCE(cl.name, \'\') AS class_name,
                    TRIM(CONCAT(COALESCE(teacher.first_name, \'\'), \' \',
                         COALESCE(teacher.last_name, \'\'))) AS teacher_name,
                    TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \',
                         COALESCE(u.middle_name, \'\'), \' \',
                         COALESCE(u.last_name, \'\'))) AS parent_name,
                    COALESCE(pp.relationship, \'\') AS parent_relationship,
                    COALESCE(u.phone, \'\') AS parent_phone,
                    COALESCE(u.email, \'\') AS parent_email
             FROM children c
             JOIN users u ON u.id = c.parent_user_id
             LEFT JOIN parent_profiles pp ON pp.user_id = u.id
             LEFT JOIN cdcs cdc ON cdc.id = c.preferred_cdc_id
             LEFT JOIN classes cl ON cl.id = c.class_id
             LEFT JOIN users teacher ON teacher.id = cl.teacher_user_id
             LEFT JOIN enrollment_applications a ON a.id = (
                 SELECT latest.id
                 FROM enrollment_applications latest
                 WHERE latest.child_id = c.id AND latest.parent_user_id = c.parent_user_id
                 ORDER BY latest.submitted_at DESC, latest.id DESC
                 LIMIT 1
             )
             WHERE c.parent_user_id = ?
             ORDER BY c.last_name, c.first_name, c.id',
            [(int) $parent['id']]
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($children as &$child) {
            $child['id'] = (int) $child['id'];
            $child['application_id'] = $child['application_id'] !== null
                ? (int) $child['application_id']
                : null;
            $child['emergency_name'] = $child['emergency_name'] ?: $child['profile_emergency_name'];
            $child['emergency_relationship'] = $child['emergency_relationship'] ?: $child['profile_emergency_relationship'];
            $child['emergency_phone'] = $child['emergency_phone'] ?: $child['profile_emergency_phone'];
            unset(
                $child['profile_emergency_name'],
                $child['profile_emergency_relationship'],
                $child['profile_emergency_phone']
            );
        }
        unset($child);

        $this->api->respond(['status' => true, 'data' => $children]);
    }

    public function update($id)
    {
        $this->api->require_method('PUT');
        $parent = $this->authenticated_parent();
        if (!$parent) return;
        $child_id = filter_var($id, FILTER_VALIDATE_INT);
        if (!$child_id || $child_id < 1) {
            $this->api->respond_error('Invalid child profile.', 400);
            return;
        }

        $input = $this->api->body(false);
        if (!is_array($input)) {
            $this->api->respond_error('Submit child profile updates as a JSON object.', 400);
            return;
        }
        $fields = [
            'first_name' => ['key' => 'firstName', 'max' => 100, 'required' => true],
            'middle_name' => ['key' => 'middleName', 'max' => 100, 'required' => false],
            'last_name' => ['key' => 'lastName', 'max' => 100, 'required' => true],
            'nickname' => ['key' => 'nickname', 'max' => 100, 'required' => false],
            'birth_date' => ['key' => 'birthDate', 'max' => 10, 'required' => true],
            'gender' => ['key' => 'gender', 'max' => 40, 'required' => true],
            'address' => ['key' => 'address', 'max' => 255, 'required' => false],
            'psa_birth_certificate_number' => ['key' => 'certificate', 'max' => 60, 'required' => false],
            'nationality' => ['key' => 'nationality', 'max' => 80, 'required' => false],
            'place_of_birth' => ['key' => 'placeOfBirth', 'max' => 180, 'required' => false],
            'blood_type' => ['key' => 'bloodType', 'max' => 8, 'required' => false],
            'allergies' => ['key' => 'allergies', 'max' => 2000, 'required' => false],
            'medical_notes' => ['key' => 'medicalConditions', 'max' => 2000, 'required' => false],
            'immunization_status' => ['key' => 'immunization', 'max' => 120, 'required' => false],
        ];
        $updates = [];
        foreach ($fields as $column => $definition) {
            $value = $input[$definition['key']] ?? '';
            if (!is_string($value)) {
                $this->api->respond_error('Child profile fields must be text values.', 400);
                return;
            }
            $value = trim($value);
            if ($definition['required'] && $value === '') {
                $this->api->respond_error('Complete all required child personal information fields.', 400);
                return;
            }
            if (mb_strlen($value) > $definition['max']) {
                $this->api->respond_error('A child profile field exceeds its allowed length.', 400);
                return;
            }
            $updates[$column] = $value !== '' ? $value : null;
        }

        if (!in_array($updates['gender'], ['Female', 'Male'], true)) {
            $this->api->respond_error('Choose a valid child gender.', 400);
            return;
        }
        $birth_date = DateTime::createFromFormat('!Y-m-d', (string) $updates['birth_date']);
        if (!$birth_date || $birth_date->format('Y-m-d') !== $updates['birth_date']
            || $updates['birth_date'] > date('Y-m-d')) {
            $this->api->respond_error('Enter a valid birthdate that is not in the future.', 400);
            return;
        }
        if ($updates['blood_type'] !== null
            && !in_array($updates['blood_type'], ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], true)) {
            $this->api->respond_error('Choose a valid blood type.', 400);
            return;
        }

        $child = $this->db->table('children')
            ->select('id')
            ->where('id', $child_id)
            ->where('parent_user_id', (int) $parent['id'])
            ->get();
        if (!$child) {
            $this->api->respond_error('Child profile not found.', 404);
            return;
        }

        $updates['updated_at'] = date('Y-m-d H:i:s');
        $this->db->table('children')
            ->where('id', $child_id)
            ->where('parent_user_id', (int) $parent['id'])
            ->update($updates);

        $this->api->respond([
            'status' => true,
            'message' => 'Child profile updated.',
        ]);
    }

    private function authenticated_parent()
    {
        $payload = $this->api->require_jwt();
        $user_id = filter_var($payload['sub'] ?? null, FILTER_VALIDATE_INT);
        if (!$user_id) {
            $this->api->respond_error('Unauthorized.', 401);
            return null;
        }
        $user = $this->UsersModel->find_by_id($user_id);
        if (!$user || (int) $user['is_active'] !== 1 || $user['role'] !== 'parent') {
            $this->api->respond_error('Parent account is unavailable.', 401);
            return null;
        }
        return $user;
    }
}
