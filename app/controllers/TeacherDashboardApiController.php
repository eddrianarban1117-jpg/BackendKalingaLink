<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class TeacherDashboardApiController extends Controller
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
        $user = $this->authenticated_user();
        if (!$user) return;

        $teacher = $this->db->raw(
            'SELECT cdc_id FROM teacher_profiles WHERE user_id = ? AND employment_status = \'active\'',
            [(int) $user['id']]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$teacher) {
            $this->api->respond_error('The teacher is not assigned to an active Child Development Center.', 403);
            return;
        }

        $cdc_id = (int) $teacher['cdc_id'];
        $user_id = (int) $user['id'];
        $today = date('Y-m-d');
        $week_start = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
        $week_end = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
        $now = date('Y-m-d H:i:s');
        $total_students = (int) $this->db->raw(
            'SELECT COUNT(DISTINCT cs.child_id) AS total
             FROM class_students cs
             JOIN children c ON c.id = cs.child_id
             JOIN classes cl ON cl.id = cs.class_id
             WHERE cl.cdc_id = ? AND cl.status = \'active\'
               AND c.enrollment_status = \'enrolled\'',
            [$cdc_id]
        )->fetch(PDO::FETCH_ASSOC)['total'];
        $today_attendance = $this->db->raw(
            'SELECT
                SUM(ar.status = \'present\') AS present,
                SUM(ar.status IN (\'present\', \'late\')) AS attended,
                COUNT(*) AS recorded
             FROM attendance_records ar
             JOIN classes cl ON cl.id = ar.class_id
             WHERE cl.cdc_id = ? AND cl.status = \'active\' AND ar.attendance_date = ?',
            [$cdc_id, $today]
        )->fetch(PDO::FETCH_ASSOC);
        $learning_activities = (int) $this->db->raw(
            'SELECT COUNT(*) AS total
             FROM lesson_plans
             WHERE teacher_user_id = ? AND created_at >= ? AND created_at < ?',
            [$user_id, $week_start, $week_end]
        )->fetch(PDO::FETCH_ASSOC)['total'];
        $pending_submissions = (int) $this->db->raw(
            'SELECT COUNT(*) AS total
             FROM activity_submissions s
             JOIN lesson_plans lp ON lp.id = s.lesson_plan_id
             WHERE lp.teacher_user_id = ? AND s.status = \'assigned\'',
            [$user_id]
        )->fetch(PDO::FETCH_ASSOC)['total'];
        $unread_notifications = (int) $this->db->raw(
            'SELECT COUNT(*) AS total
             FROM notification_recipients r
             JOIN notifications n ON n.id = r.notification_id
             WHERE r.user_id = ? AND r.read_at IS NULL
               AND (n.related_type IS NULL OR n.related_type <> \'event\')',
            [$user_id]
        )->fetch(PDO::FETCH_ASSOC)['total'];
        $weekly_attendance = $this->db->raw(
            'SELECT ar.attendance_date,
                    SUM(ar.status IN (\'present\', \'late\')) AS present,
                    SUM(ar.status = \'absent\') AS absent
             FROM attendance_records ar
             JOIN classes cl ON cl.id = ar.class_id
             WHERE cl.cdc_id = ? AND cl.status = \'active\'
               AND ar.attendance_date BETWEEN ? AND ?
             GROUP BY ar.attendance_date
             ORDER BY ar.attendance_date',
            [
                $cdc_id,
                (new DateTimeImmutable($today))->modify('-6 days')->format('Y-m-d'),
                $today,
            ]
        )->fetchAll(PDO::FETCH_ASSOC);
        $recent_students = $this->db->raw(
            'SELECT c.id, c.first_name, c.middle_name, c.last_name, c.birth_date,
                    cl.name AS class_name, ar.status AS attendance_status, cs.enrolled_at
             FROM class_students cs
             JOIN children c ON c.id = cs.child_id
             JOIN classes cl ON cl.id = cs.class_id
             LEFT JOIN attendance_records ar
                ON ar.child_id = c.id AND ar.class_id = cl.id AND ar.attendance_date = ?
             WHERE cl.cdc_id = ? AND cl.status = \'active\'
               AND c.enrollment_status = \'enrolled\'
             ORDER BY cs.enrolled_at DESC, c.last_name, c.first_name
             LIMIT 5',
            [$today, $cdc_id]
        )->fetchAll(PDO::FETCH_ASSOC);
        $upcoming_activities = $this->db->raw(
            'SELECT lp.id, lp.title, lp.due_at, cl.name AS class_name
             FROM lesson_plans lp
             JOIN classes cl ON cl.id = lp.class_id
             WHERE lp.teacher_user_id = ? AND cl.cdc_id = ?
               AND lp.status = \'published\' AND lp.due_at >= ?
             ORDER BY lp.due_at, lp.id
             LIMIT 4',
            [$user_id, $cdc_id, $now]
        )->fetchAll(PDO::FETCH_ASSOC);

        $present_today = (int) ($today_attendance['present'] ?? 0);
        $recorded_today = (int) ($today_attendance['recorded'] ?? 0);
        $attended_today = (int) ($today_attendance['attended'] ?? 0);

        $this->api->respond([
            'status' => true,
            'data' => [
                'total_students' => $total_students,
                'present_today' => $present_today,
                'attendance_rate' => $total_students
                    ? (int) round(($attended_today / $total_students) * 100)
                    : 0,
                'recorded_today' => $recorded_today,
                'learning_activities' => $learning_activities,
                'pending_submissions' => $pending_submissions,
                'unread_notifications' => $unread_notifications,
                'weekly_attendance' => $weekly_attendance,
                'recent_students' => $recent_students,
                'upcoming_activities' => $upcoming_activities,
            ],
        ]);
    }

    private function authenticated_user()
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
            $this->api->respond_error('You are not authorized to perform this action.', 403);
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
