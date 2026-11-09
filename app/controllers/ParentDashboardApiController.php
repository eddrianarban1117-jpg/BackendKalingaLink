<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ParentDashboardApiController extends Controller
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
        $payload = $this->api->require_jwt();
        $user_id = filter_var($payload['sub'] ?? null, FILTER_VALIDATE_INT);
        if (!$user_id) {
            $this->api->respond_error('Unauthorized.', 401);
            return;
        }

        $user = $this->UsersModel->find_by_id($user_id);
        if (!$user || (int) $user['is_active'] !== 1 || $user['role'] !== 'parent') {
            $this->api->respond_error('Parent account is unavailable.', 401);
            return;
        }

        $children = $this->db->raw(
            'SELECT c.id, c.first_name, c.middle_name, c.last_name, c.nickname,
                    c.birth_date, c.gender, c.enrollment_status, cl.name AS class_name
             FROM children c
             LEFT JOIN classes cl ON cl.id = c.class_id
             WHERE c.parent_user_id = ?
             ORDER BY c.last_name, c.first_name, c.id',
            [(int) $user_id]
        )->fetchAll(PDO::FETCH_ASSOC);
        $child_ids = array_map(static fn($child) => (int) $child['id'], $children);
        $activities = [];
        $attendance = [];
        $progress = [];
        $upcoming_activity_count = 0;

        if ($child_ids) {
            $placeholders = implode(',', array_fill(0, count($child_ids), '?'));
            $activities = $this->db->raw(
                'SELECT ap.id, ap.title, ap.due_at, ap.published_at,
                        c.id AS child_id,
                        TRIM(CONCAT(c.first_name, \' \', COALESCE(CONCAT(c.middle_name, \' \'), \'\'), c.last_name)) AS child_name,
                        COALESCE(submission.status, \'assigned\') AS submission_status
                 FROM lesson_plans ap
                 JOIN class_students cs ON cs.class_id = ap.class_id
                 JOIN children c ON c.id = cs.child_id
                 LEFT JOIN activity_submissions submission
                   ON submission.lesson_plan_id = ap.id AND submission.child_id = c.id
                 WHERE c.id IN (' . $placeholders . ')
                   AND ap.status = \'published\' AND ap.published_at IS NOT NULL
                 ORDER BY COALESCE(ap.due_at, ap.published_at) DESC, ap.id DESC
                 LIMIT 8',
                $child_ids
            )->fetchAll(PDO::FETCH_ASSOC);

            $upcoming = $this->db->raw(
                'SELECT COUNT(DISTINCT ap.id) AS total
                 FROM lesson_plans ap
                 JOIN class_students cs ON cs.class_id = ap.class_id
                 JOIN children c ON c.id = cs.child_id
                 WHERE c.id IN (' . $placeholders . ')
                   AND ap.status = \'published\' AND ap.published_at IS NOT NULL
                   AND ap.due_at >= NOW()',
                $child_ids
            )->fetch(PDO::FETCH_ASSOC);
            $upcoming_activity_count = (int) ($upcoming['total'] ?? 0);

            $attendance = $this->db->raw(
                'SELECT c.id AS child_id,
                        TRIM(CONCAT(c.first_name, \' \', COALESCE(CONCAT(c.middle_name, \' \'), \'\'), c.last_name)) AS child_name,
                        COUNT(ar.id) AS record_count,
                        SUM(ar.status = \'present\') AS present_count,
                        MIN(ar.attendance_date) AS period_start,
                        MAX(ar.attendance_date) AS period_end
                 FROM children c
                 JOIN attendance_records ar ON ar.child_id = c.id
                 WHERE c.id IN (' . $placeholders . ')
                   AND ar.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                 GROUP BY c.id, c.first_name, c.middle_name, c.last_name
                 ORDER BY period_end DESC, c.last_name, c.first_name',
                $child_ids
            )->fetchAll(PDO::FETCH_ASSOC);

            $progress = $this->db->raw(
                'SELECT c.id AS child_id,
                        TRIM(CONCAT(c.first_name, \' \', COALESCE(CONCAT(c.middle_name, \' \'), \'\'), c.last_name)) AS child_name,
                        ROUND(AVG(lp.rating) * 20) AS score,
                        COUNT(lp.id) AS record_count
                 FROM children c
                 LEFT JOIN learning_progress lp ON lp.child_id = c.id
                 WHERE c.id IN (' . $placeholders . ')
                 GROUP BY c.id, c.first_name, c.middle_name, c.last_name
                 ORDER BY c.last_name, c.first_name',
                $child_ids
            )->fetchAll(PDO::FETCH_ASSOC);
        }

        $applications = $this->db->raw(
            'SELECT COUNT(*) AS total
             FROM enrollment_applications
             WHERE parent_user_id = ?',
            [(int) $user_id]
        )->fetch(PDO::FETCH_ASSOC);

        foreach ($children as &$child) {
            $child['id'] = (int) $child['id'];
        }
        unset($child);
        foreach ($activities as &$activity) {
            $activity['id'] = (int) $activity['id'];
            $activity['child_id'] = (int) $activity['child_id'];
        }
        unset($activity);
        foreach ($attendance as &$record) {
            $record['child_id'] = (int) $record['child_id'];
            $record['record_count'] = (int) $record['record_count'];
            $record['present_count'] = (int) $record['present_count'];
        }
        unset($record);
        foreach ($progress as &$record) {
            $record['child_id'] = (int) $record['child_id'];
            $record['score'] = $record['score'] === null ? null : (int) $record['score'];
            $record['record_count'] = (int) $record['record_count'];
        }
        unset($record);

        $this->api->respond([
            'status' => true,
            'data' => [
                'children' => $children,
                'activities' => $activities,
                'upcoming_activity_count' => $upcoming_activity_count,
                'attendance' => $attendance,
                'progress' => $progress,
                'enrollment_count' => (int) ($applications['total'] ?? 0),
            ],
        ]);
    }
}
