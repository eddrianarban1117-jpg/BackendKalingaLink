<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class TeacherLearningActivitiesApiController extends Controller
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
        $user = $this->authenticated_teacher();
        if (!$user) return;

        $teacher_id = (int) $user['id'];
        $classes = $this->db->raw(
            'SELECT cl.id, cl.name
             FROM classes cl
             JOIN teacher_profiles tp ON tp.cdc_id = cl.cdc_id
             WHERE tp.user_id = ? AND tp.employment_status = \'active\'
               AND cl.status = \'active\'
             ORDER BY cl.name, cl.id',
            [$teacher_id]
        )->fetchAll(PDO::FETCH_ASSOC);
        $rows = $this->db->raw(
            'SELECT la.id, la.class_id, cl.name AS class_name, la.title, la.domain,
                    la.age_group, la.start_date, la.end_date, la.duration_minutes,
                    la.objective, la.materials_json, la.instructions_json,
                    la.observations, la.created_at, la.updated_at,
                    GROUP_CONCAT(
                        DISTINCT CONCAT(c.first_name, \' \', c.last_name)
                        ORDER BY c.last_name, c.first_name SEPARATOR \',\'
                    ) AS student_names
             FROM learning_activities la
             JOIN classes cl ON cl.id = la.class_id
             JOIN teacher_profiles tp ON tp.cdc_id = cl.cdc_id
             LEFT JOIN class_students cs ON cs.class_id = cl.id
             LEFT JOIN children c ON c.id = cs.child_id
                 AND c.enrollment_status = \'enrolled\'
             WHERE la.teacher_user_id = ? AND tp.user_id = ?
               AND tp.employment_status = \'active\' AND cl.status = \'active\'
             GROUP BY la.id
             ORDER BY la.start_date DESC, la.id DESC',
            [$teacher_id, $teacher_id]
        )->fetchAll(PDO::FETCH_ASSOC);

        $today = date('Y-m-d');
        $activities = array_map(function ($row) use ($today) {
            $row['id'] = (int) $row['id'];
            $row['class_id'] = (int) $row['class_id'];
            $row['duration_minutes'] = (int) $row['duration_minutes'];
            $row['materials'] = json_decode($row['materials_json'], true) ?: [];
            $row['instructions'] = json_decode($row['instructions_json'], true) ?: [];
            unset($row['materials_json'], $row['instructions_json']);
            $row['students'] = $row['student_names'] === null || $row['student_names'] === ''
                ? []
                : explode(',', $row['student_names']);
            unset($row['student_names']);
            $row['status'] = $today < $row['start_date']
                ? 'upcoming'
                : ($today <= $row['end_date'] ? 'ongoing' : 'completed');
            return $row;
        }, $rows);

        $this->api->respond([
            'status' => true,
            'data' => [
                'activities' => $activities,
                'classes' => array_map(static function ($class) {
                    return [
                        'id' => (int) $class['id'],
                        'name' => $class['name'],
                    ];
                }, $classes),
            ],
        ]);
    }

    public function create()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_teacher();
        if (!$user) return;

        $input = $this->validated_input($this->api->body(false));
        if (!$input) return;
        if (!$this->authorized_class((int) $user['id'], $input['class_id'])) return;

        $this->db->table('learning_activities')->insert([
            'teacher_user_id' => (int) $user['id'],
            'class_id' => $input['class_id'],
            'title' => $input['title'],
            'domain' => $input['domain'],
            'age_group' => $input['age_group'],
            'start_date' => $input['start_date'],
            'end_date' => $input['end_date'],
            'duration_minutes' => $input['duration_minutes'],
            'objective' => $input['objective'],
            'materials_json' => json_encode($input['materials'], JSON_UNESCAPED_UNICODE),
            'instructions_json' => json_encode($input['instructions'], JSON_UNESCAPED_UNICODE),
            'observations' => $input['observations'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->api->respond([
            'status' => true,
            'message' => 'Learning activity created.',
        ]);
    }

    public function update($id)
    {
        $this->api->require_method('PUT');
        $user = $this->authenticated_teacher();
        if (!$user) return;

        $activity_id = filter_var($id, FILTER_VALIDATE_INT);
        if (!$activity_id) {
            $this->api->respond_error('A valid learning activity is required.', 400);
            return;
        }
        $input = $this->validated_input($this->api->body(false));
        if (!$input) return;
        if (!$this->authorized_class((int) $user['id'], $input['class_id'])) return;

        $existing = $this->db->raw(
            'SELECT id FROM learning_activities WHERE id = ? AND teacher_user_id = ?',
            [$activity_id, (int) $user['id']]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            $this->api->respond_error('Learning activity not found.', 404);
            return;
        }

        $this->db->table('learning_activities')
            ->where('id', $activity_id)
            ->where('teacher_user_id', (int) $user['id'])
            ->update([
                'class_id' => $input['class_id'],
                'title' => $input['title'],
                'domain' => $input['domain'],
                'age_group' => $input['age_group'],
                'start_date' => $input['start_date'],
                'end_date' => $input['end_date'],
                'duration_minutes' => $input['duration_minutes'],
                'objective' => $input['objective'],
                'materials_json' => json_encode($input['materials'], JSON_UNESCAPED_UNICODE),
                'instructions_json' => json_encode($input['instructions'], JSON_UNESCAPED_UNICODE),
                'observations' => $input['observations'],
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        $this->api->respond([
            'status' => true,
            'message' => 'Learning activity updated.',
        ]);
    }

    private function validated_input($input)
    {
        if (!is_array($input)) {
            $this->api->respond_error('Provide learning activity details.', 400);
            return null;
        }
        $class_id = filter_var($input['class_id'] ?? null, FILTER_VALIDATE_INT);
        $title = trim((string) ($input['title'] ?? ''));
        $domain = trim((string) ($input['domain'] ?? ''));
        $age_group = trim((string) ($input['age_group'] ?? ''));
        $duration = filter_var($input['duration_minutes'] ?? null, FILTER_VALIDATE_INT);
        $start_date = $input['start_date'] ?? null;
        $end_date = $input['end_date'] ?? null;
        $objective = trim((string) ($input['objective'] ?? ''));
        $observations = trim((string) ($input['observations'] ?? ''));
        $materials = $input['materials'] ?? [];
        $instructions = $input['instructions'] ?? [];

        if (!$class_id || $title === '' || mb_strlen($title) > 180
            || $domain === '' || mb_strlen($domain) > 120
            || $age_group === '' || mb_strlen($age_group) > 80
            || !$duration || $duration > 600
            || !$this->valid_date($start_date) || !$this->valid_date($end_date)
            || $start_date > $end_date
            || mb_strlen($objective) > 10000 || mb_strlen($observations) > 10000
            || !$this->valid_text_list($materials, 30, 500)
            || !$this->valid_text_list($instructions, 50, 1000)) {
            $this->api->respond_error('Complete all activity fields with valid values.', 400);
            return null;
        }

        return [
            'class_id' => (int) $class_id,
            'title' => $title,
            'domain' => $domain,
            'age_group' => $age_group,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'duration_minutes' => (int) $duration,
            'objective' => $objective !== '' ? $objective : null,
            'materials' => array_values(array_map('trim', $materials)),
            'instructions' => array_values(array_map('trim', $instructions)),
            'observations' => $observations !== '' ? $observations : null,
        ];
    }

    private function valid_text_list($value, $limit, $max_length)
    {
        if (!is_array($value) || count($value) > $limit) return false;
        foreach ($value as $item) {
            if (!is_string($item) || mb_strlen(trim($item)) > $max_length) return false;
        }
        return true;
    }

    private function valid_date($value)
    {
        if (!is_string($value)) return false;
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value;
    }

    private function authorized_class($teacher_id, $class_id)
    {
        $class = $this->db->raw(
            'SELECT cl.id
             FROM classes cl
             JOIN teacher_profiles tp ON tp.cdc_id = cl.cdc_id
             WHERE cl.id = ? AND tp.user_id = ?
               AND tp.employment_status = \'active\' AND cl.status = \'active\'',
            [$class_id, $teacher_id]
        )->fetch(PDO::FETCH_ASSOC);
        if ($class) return true;
        $this->api->respond_error('Choose an active class at your assigned center.', 403);
        return false;
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
            $this->api->respond_error('You are not authorized to manage learning activities.', 403);
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
