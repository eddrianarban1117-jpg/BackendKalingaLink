<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class TeacherTasksApiController extends Controller
{
    private const TASK_TYPES = [
        'Report',
        'Data Entry',
        'Media',
        'Review',
        'Document',
        'Materials',
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

        $month = $_GET['month'] ?? date('Y-m');
        if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $this->api->respond_error('Choose a valid task month.', 400);
            return;
        }
        $start = $month . '-01 00:00:00';
        $end = (new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d H:i:s');
        $rows = $this->db->raw(
            'SELECT t.id, t.title, t.description, t.task_type, t.due_at, t.status,
                    s.id AS submission_id, s.submission_text, s.submitted_at
             FROM teacher_tasks t
             LEFT JOIN (
                SELECT task_id, MAX(id) AS latest_id
                FROM teacher_task_submissions
                WHERE teacher_user_id = ?
                GROUP BY task_id
             ) latest ON latest.task_id = t.id
             LEFT JOIN teacher_task_submissions s
                ON s.id = latest.latest_id AND s.teacher_user_id = t.teacher_user_id
             WHERE t.teacher_user_id = ? AND t.due_at >= ? AND t.due_at < ?
             ORDER BY t.due_at, t.id',
            [(int) $user['id'], (int) $user['id'], $start, $end]
        )->fetchAll(PDO::FETCH_ASSOC);

        $now = date('Y-m-d H:i:s');
        $tasks = array_map(static function ($row) use ($now) {
            $status = $row['submission_id']
                ? 'submitted'
                : ($row['due_at'] < $now
                    ? 'overdue'
                    : ($row['status'] === 'in_progress'
                        ? 'in_progress'
                        : ($row['status'] === 'not_started' ? 'not_started' : 'pending')));
            return [
                'id' => (int) $row['id'],
                'title' => $row['title'],
                'description' => $row['description'] ?? '',
                'type' => $row['task_type'],
                'due_at' => $row['due_at'],
                'status' => $status,
                'submission' => $row['submission_id'] ? [
                    'id' => (int) $row['submission_id'],
                    'text' => $row['submission_text'],
                    'submitted_at' => $row['submitted_at'],
                ] : null,
            ];
        }, $rows);

        $this->api->respond([
            'status' => true,
            'data' => ['tasks' => $tasks, 'month' => $month],
        ]);
    }

    public function create()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_teacher();
        if (!$user) return;

        $input = $this->api->body(false);
        if (!is_array($input)) {
            $this->api->respond_error('Provide task details.', 400);
            return;
        }
        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $type = $input['type'] ?? '';
        $due_at = $input['due_at'] ?? '';
        if ($title === '' || mb_strlen($title) > 180
            || mb_strlen($description) > 10000
            || !in_array($type, self::TASK_TYPES, true)
            || !$this->valid_datetime($due_at)) {
            $this->api->respond_error('Provide a title, valid task type, and due date.', 400);
            return;
        }

        $this->db->table('teacher_tasks')->insert([
            'teacher_user_id' => (int) $user['id'],
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'task_type' => $type,
            'due_at' => str_replace('T', ' ', $due_at) . ':00',
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->api->respond(['status' => true, 'message' => 'Task created.']);
    }

    public function submit($id)
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_teacher();
        if (!$user) return;

        $task_id = filter_var($id, FILTER_VALIDATE_INT);
        $input = $this->api->body(false);
        $text = is_array($input) ? trim((string) ($input['text'] ?? '')) : '';
        if (!$task_id || $text === '' || mb_strlen($text) > 10000) {
            $this->api->respond_error('Provide a valid task and submission note.', 400);
            return;
        }
        $task = $this->db->raw(
            'SELECT id FROM teacher_tasks WHERE id = ? AND teacher_user_id = ?',
            [$task_id, (int) $user['id']]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$task) {
            $this->api->respond_error('Task not found.', 404);
            return;
        }

        $this->db->table('teacher_task_submissions')->insert([
            'task_id' => (int) $task_id,
            'teacher_user_id' => (int) $user['id'],
            'submission_text' => $text,
            'submitted_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->api->respond(['status' => true, 'message' => 'Task submission saved.']);
    }

    private function valid_datetime($value)
    {
        if (!is_string($value)) return false;
        $date = DateTime::createFromFormat('!Y-m-d\TH:i', $value);
        return $date && $date->format('Y-m-d\TH:i') === $value;
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
            $this->api->respond_error('You are not authorized to manage these tasks.', 403);
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
