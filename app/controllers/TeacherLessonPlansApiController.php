<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class TeacherLessonPlansApiController extends Controller
{
    private const STATUSES = [
        'draft',
        'for_review',
        'finalized',
        'in_progress',
        'upcoming',
    ];

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
            'SELECT lp.id, lp.class_id, cl.name AS class_name, lp.title, lp.description,
                    lp.topic, lp.age_group, lp.domain, lp.scheduled_date, lp.due_at,
                    lp.duration_minutes, lp.objectives_json, lp.procedure_json,
                    lp.materials_json, lp.is_favorite, lp.workflow_status, lp.status,
                    lp.questionnaire_json, lp.created_at, lpc.completed_at
             FROM lesson_plans lp
             JOIN classes cl ON cl.id = lp.class_id
             JOIN teacher_profiles tp ON tp.cdc_id = cl.cdc_id
             LEFT JOIN lesson_plan_completions lpc
               ON lpc.lesson_plan_id = lp.id
              AND lpc.teacher_user_id = lp.teacher_user_id
              AND lpc.class_id = lp.class_id
             WHERE lp.teacher_user_id = ? AND tp.user_id = ?
               AND tp.employment_status = \'active\' AND cl.status = \'active\'
             ORDER BY COALESCE(lp.scheduled_date, DATE(lp.due_at)) DESC, lp.id DESC',
            [$teacher_id, $teacher_id]
        )->fetchAll(PDO::FETCH_ASSOC);

        $lessons = [];
        $material_usage = [];
        foreach ($rows as $row) {
            $legacy = json_decode($row['questionnaire_json'] ?? '', true);
            $objectives = $this->json_list($row['objectives_json']);
            $procedure = $this->json_list($row['procedure_json']);
            $materials = $this->json_list($row['materials_json']);
            if (!$objectives && is_array($legacy)) {
                $objectives = $this->json_list($legacy['objectives'] ?? []);
            }
            if (!$procedure && is_array($legacy)) {
                $procedure = $this->json_list($legacy['procedure'] ?? []);
            }
            if (!$materials && is_array($legacy)) {
                $materials = $this->json_list($legacy['materials'] ?? []);
            }

            $seen_materials = [];
            foreach ($materials as $material) {
                $key = mb_strtolower($material);
                if (isset($seen_materials[$key])) continue;
                $seen_materials[$key] = true;
                if (!isset($material_usage[$key])) {
                    $material_usage[$key] = ['title' => $material, 'count' => 0];
                }
                $material_usage[$key]['count']++;
            }

            $date = $row['scheduled_date']
                ?: ($row['due_at'] ? substr($row['due_at'], 0, 10) : null);
            $workflow_status = $row['workflow_status'];
            if ($workflow_status === 'draft') {
                if ($row['status'] === 'archived') {
                    $workflow_status = 'finalized';
                } elseif ($row['status'] === 'published') {
                    $workflow_status = $date && $date > date('Y-m-d')
                        ? 'upcoming'
                        : ($date ? 'finalized' : 'in_progress');
                }
            }
            $lessons[] = [
                'id' => (int) $row['id'],
                'class_id' => (int) $row['class_id'],
                'class_name' => $row['class_name'],
                'title' => $row['title'],
                'topic' => $row['topic'] ?: '',
                'age_group' => $row['age_group'] ?: '',
                'domain' => $row['domain'],
                'date' => $date,
                'duration_minutes' => (int) $row['duration_minutes'],
                'status' => $workflow_status,
                'objectives' => $objectives,
                'procedure' => $procedure,
                'materials' => $materials,
                'favorite' => (bool) $row['is_favorite'],
                'completed' => $row['completed_at'] !== null,
                'completed_at' => $row['completed_at'],
            ];
        }

        $material_usage = array_values($material_usage);
        usort($material_usage, static function ($a, $b) {
            return strcasecmp($a['title'], $b['title']);
        });

        $this->api->respond([
            'status' => true,
            'data' => [
                'lessons' => $lessons,
                'classes' => array_map(static function ($class) {
                    return ['id' => (int) $class['id'], 'name' => $class['name']];
                }, $classes),
                'materials' => $material_usage,
            ],
        ]);
    }

    public function create()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_teacher();
        if (!$user) return;

        $input = $this->validated_input($this->api->body(false));
        if (!$input || !$this->authorized_class((int) $user['id'], $input['class_id'])) return;

        $values = $this->database_values($input, (int) $user['id']);
        $this->db->transaction();
        try {
            $this->db->table('lesson_plans')->insert($values);
            $plan_id = (int) $this->db->last_id();
            if ($plan_id && $values['status'] === 'published') {
                $this->notify_class_parents($plan_id, $input['class_id'], (int) $user['id']);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        $this->api->respond(['status' => true, 'message' => 'Lesson plan created.']);
    }

    public function update($id)
    {
        $this->api->require_method('PUT');
        $user = $this->authenticated_teacher();
        if (!$user) return;

        $plan_id = filter_var($id, FILTER_VALIDATE_INT);
        if (!$plan_id) {
            $this->api->respond_error('A valid lesson plan is required.', 400);
            return;
        }
        $input = $this->validated_input($this->api->body(false));
        if (!$input || !$this->authorized_class((int) $user['id'], $input['class_id'])) return;

        $teacher_id = (int) $user['id'];
        $this->db->transaction();
        try {
            $existing = $this->db->raw(
                'SELECT id, class_id, status FROM lesson_plans
                 WHERE id = ? AND teacher_user_id = ? FOR UPDATE',
                [$plan_id, $teacher_id]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                $this->db->roll_back();
                $this->api->respond_error('Lesson plan not found.', 404);
                return;
            }

            $values = $this->database_values($input, $teacher_id);
            unset($values['teacher_user_id']);
            $this->db->table('lesson_plans')
                ->where('id', $plan_id)
                ->where('teacher_user_id', $teacher_id)
                ->update($values);
            if ($values['status'] !== 'published'
                || (int) $existing['class_id'] !== $input['class_id']
                || $input['date'] > date('Y-m-d')) {
                $this->db->raw(
                    'DELETE FROM lesson_plan_completions
                     WHERE lesson_plan_id = ? AND teacher_user_id = ?',
                    [$plan_id, $teacher_id]
                );
            }
            if ($existing['status'] !== 'published' && $values['status'] === 'published') {
                $this->notify_class_parents($plan_id, $input['class_id'], $teacher_id);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        $this->api->respond(['status' => true, 'message' => 'Lesson plan updated.']);
    }

    public function completion($id)
    {
        $this->api->require_method('PATCH');
        $user = $this->authenticated_teacher();
        if (!$user) return;

        $plan_id = filter_var($id, FILTER_VALIDATE_INT);
        $input = $this->api->body(false);
        if (!$plan_id || !is_array($input) || !isset($input['completed'])
            || !is_bool($input['completed'])) {
            $this->api->respond_error('Provide a valid lesson plan and completion value.', 400);
            return;
        }

        $teacher_id = (int) $user['id'];
        $plan = $this->db->raw(
            'SELECT lp.id, lp.class_id, lp.status,
                    COALESCE(lp.scheduled_date, DATE(lp.due_at)) AS lesson_date
             FROM lesson_plans lp
             JOIN classes cl ON cl.id = lp.class_id
             JOIN teacher_profiles tp ON tp.cdc_id = cl.cdc_id
             WHERE lp.id = ? AND lp.teacher_user_id = ? AND tp.user_id = ?
               AND tp.employment_status = \'active\' AND cl.status = \'active\'',
            [$plan_id, $teacher_id, $teacher_id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            $this->api->respond_error('Lesson plan not found.', 404);
            return;
        }
        if ($plan['status'] !== 'published' || $plan['lesson_date'] > date('Y-m-d')) {
            $this->api->respond_error('Only published lessons scheduled for today or earlier can be marked complete.', 400);
            return;
        }

        if ($input['completed']) {
            $now = date('Y-m-d H:i:s');
            $this->db->raw(
                'INSERT INTO lesson_plan_completions
                    (lesson_plan_id, teacher_user_id, class_id, completed_at)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE completed_at = VALUES(completed_at)',
                [$plan_id, $teacher_id, (int) $plan['class_id'], $now]
            );
        } else {
            $this->db->raw(
                'DELETE FROM lesson_plan_completions
                 WHERE lesson_plan_id = ? AND teacher_user_id = ? AND class_id = ?',
                [$plan_id, $teacher_id, (int) $plan['class_id']]
            );
        }

        $this->api->respond([
            'status' => true,
            'message' => $input['completed'] ? 'Lesson marked complete.' : 'Lesson marked as not complete.',
        ]);
    }

    public function favorite($id)
    {
        $this->api->require_method('PATCH');
        $user = $this->authenticated_teacher();
        if (!$user) return;

        $plan_id = filter_var($id, FILTER_VALIDATE_INT);
        $input = $this->api->body(false);
        if (!$plan_id || !is_array($input) || !array_key_exists('favorite', $input)
            || !is_bool($input['favorite'])) {
            $this->api->respond_error('Provide a valid lesson plan and favorite value.', 400);
            return;
        }
        $existing = $this->db->raw(
            'SELECT id FROM lesson_plans WHERE id = ? AND teacher_user_id = ?',
            [$plan_id, (int) $user['id']]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            $this->api->respond_error('Lesson plan not found.', 404);
            return;
        }

        $this->db->table('lesson_plans')
            ->where('id', $plan_id)
            ->where('teacher_user_id', (int) $user['id'])
            ->update(['is_favorite' => $input['favorite'] ? 1 : 0]);
        $this->api->respond(['status' => true, 'message' => 'Lesson plan favorite updated.']);
    }

    private function validated_input($input)
    {
        if (!is_array($input)) {
            $this->api->respond_error('Provide lesson plan details.', 400);
            return null;
        }
        $class_id = filter_var($input['class_id'] ?? null, FILTER_VALIDATE_INT);
        $title = trim((string) ($input['title'] ?? ''));
        $topic = trim((string) ($input['topic'] ?? ''));
        $age_group = trim((string) ($input['age_group'] ?? ''));
        $domain = trim((string) ($input['domain'] ?? ''));
        $duration = filter_var($input['duration_minutes'] ?? null, FILTER_VALIDATE_INT);
        $date = $input['date'] ?? null;
        $status = $input['status'] ?? 'draft';
        $objectives = $input['objectives'] ?? [];
        $procedure = $input['procedure'] ?? [];
        $materials = $input['materials'] ?? [];

        if (!$class_id || $title === '' || mb_strlen($title) > 180
            || $topic === '' || mb_strlen($topic) > 180
            || $age_group === '' || mb_strlen($age_group) > 80
            || $domain === '' || mb_strlen($domain) > 120
            || !$duration || $duration > 600
            || !$this->valid_date($date)
            || !in_array($status, self::STATUSES, true)
            || !$this->valid_text_list($objectives, 30, 1000)
            || !$this->valid_text_list($procedure, 50, 1000)
            || !$this->valid_text_list($materials, 30, 500)) {
            $this->api->respond_error('Complete all required lesson plan fields with valid values.', 400);
            return null;
        }

        return [
            'class_id' => (int) $class_id,
            'title' => $title,
            'topic' => $topic,
            'age_group' => $age_group,
            'domain' => $domain,
            'date' => $date,
            'duration_minutes' => (int) $duration,
            'status' => $status,
            'objectives' => array_values(array_map('trim', $objectives)),
            'procedure' => array_values(array_map('trim', $procedure)),
            'materials' => array_values(array_map('trim', $materials)),
        ];
    }

    private function database_values($input, $teacher_id)
    {
        $published = in_array($input['status'], ['finalized', 'in_progress', 'upcoming'], true);
        return [
            'teacher_user_id' => $teacher_id,
            'class_id' => $input['class_id'],
            'title' => $input['title'],
            'description' => null,
            'domain' => $input['domain'],
            'delivery_mode' => 'face_to_face',
            'file_type' => 'questionnaire',
            'questionnaire_json' => null,
            'due_at' => $input['date'] . ' 23:59:59',
            'published_at' => $published ? date('Y-m-d H:i:s') : null,
            'status' => $published ? 'published' : 'draft',
            'topic' => $input['topic'],
            'age_group' => $input['age_group'],
            'scheduled_date' => $input['date'],
            'duration_minutes' => $input['duration_minutes'],
            'objectives_json' => json_encode($input['objectives'], JSON_UNESCAPED_UNICODE),
            'procedure_json' => json_encode($input['procedure'], JSON_UNESCAPED_UNICODE),
            'materials_json' => json_encode($input['materials'], JSON_UNESCAPED_UNICODE),
            'workflow_status' => $input['status'],
        ];
    }

    private function notify_class_parents($plan_id, $class_id, $teacher_id)
    {
        $existing = $this->db->raw(
            'SELECT id FROM notifications
             WHERE related_type = \'lesson_plan\' AND related_id = ?
             LIMIT 1',
            [$plan_id]
        )->fetch(PDO::FETCH_ASSOC);
        if ($existing) return;

        $parents = $this->db->raw(
            'SELECT DISTINCT ch.parent_user_id
             FROM class_students cs
             JOIN children ch ON ch.id = cs.child_id
             JOIN users u ON u.id = ch.parent_user_id
             WHERE cs.class_id = ? AND ch.enrollment_status = \'enrolled\'
               AND u.role = \'parent\' AND u.is_active = 1',
            [$class_id]
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!$parents) return;

        $lesson = $this->db->raw(
            'SELECT lp.title, cl.name AS class_name
             FROM lesson_plans lp
             JOIN classes cl ON cl.id = lp.class_id
             WHERE lp.id = ? AND lp.teacher_user_id = ? AND lp.class_id = ?',
            [$plan_id, $teacher_id, $class_id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$lesson) return;

        $this->db->table('notifications')->insert([
            'actor_user_id' => $teacher_id,
            'title' => mb_substr('New lesson plan: ' . $lesson['title'], 0, 180),
            'message' => 'A new lesson plan is available for ' . $lesson['class_name'] . '.',
            'audience' => 'parents',
            'related_type' => 'lesson_plan',
            'related_id' => $plan_id,
            'related_module' => 'lesson_plans',
            'notification_type' => 'Learning Activity',
            'action_url' => '/parent/activities',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $notification_id = (int) $this->db->last_id();
        foreach ($parents as $parent_id) {
            $this->db->table('notification_recipients')->insert([
                'notification_id' => $notification_id,
                'user_id' => (int) $parent_id,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function valid_text_list($value, $limit, $max_length)
    {
        if (!is_array($value) || count($value) > $limit) return false;
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '' || mb_strlen(trim($item)) > $max_length) {
                return false;
            }
        }
        return true;
    }

    private function json_list($value)
    {
        if (is_array($value)) return array_values(array_filter($value, 'is_string'));
        if (!is_string($value) || $value === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
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
            $this->api->respond_error('You are not authorized to manage lesson plans.', 403);
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
