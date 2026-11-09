<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminDashboardApiController extends Controller
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
        $user = $this->authenticated_admin();
        if (!$user) return;

        $year = date('Y');
        $next_year = (string) ((int) $year + 1);
        $stats = $this->db->raw(
            'SELECT
                (SELECT COUNT(*) FROM cdcs WHERE status = \'active\') AS centers,
                (SELECT COUNT(*) FROM children WHERE enrollment_status = \'enrolled\') AS enrolled_children,
                (SELECT COUNT(*) FROM users WHERE role = \'teacher\' AND is_active = 1) AS teachers,
                (SELECT COUNT(*) FROM children WHERE enrollment_status <> \'withdrawn\') AS active_children',
        )->fetch(PDO::FETCH_ASSOC);

        $monthly_rows = $this->db->raw(
            'SELECT MONTH(submitted_at) AS month_number, COUNT(*) AS total
             FROM enrollment_applications
             WHERE submitted_at >= ? AND submitted_at < ?
             GROUP BY MONTH(submitted_at)',
            [$year . '-01-01 00:00:00', $next_year . '-01-01 00:00:00']
        )->fetchAll(PDO::FETCH_ASSOC);
        $monthly_counts = array_fill(1, 12, 0);
        foreach ($monthly_rows as $row) {
            $monthly_counts[(int) $row['month_number']] = (int) $row['total'];
        }

        $centers = $this->db->raw(
            'SELECT d.id, d.name, d.address, d.barangay, d.capacity,
                    COUNT(DISTINCT CASE WHEN c.enrollment_status = \'enrolled\' THEN c.id END) AS enrolled_children
             FROM cdcs d
             LEFT JOIN children c
                ON COALESCE(c.preferred_cdc_id, (
                    SELECT assigned_class.cdc_id
                    FROM classes assigned_class
                    WHERE assigned_class.id = c.class_id
                    LIMIT 1
                )) = d.id
             WHERE d.status = \'active\'
             GROUP BY d.id, d.name, d.address, d.barangay, d.capacity
             ORDER BY d.name, d.id
             LIMIT 8'
        )->fetchAll(PDO::FETCH_ASSOC);

        $activities = $this->db->raw(
            'SELECT activity_type, title, description, occurred_at
             FROM (
                SELECT \'enrollment\' AS activity_type,
                       \'Enrollment application received\' AS title,
                       CONCAT(c.first_name, \' \', c.last_name, \' — \', d.name) AS description,
                       a.submitted_at AS occurred_at
                FROM enrollment_applications a
                JOIN children c ON c.id = a.child_id
                JOIN cdcs d ON d.id = a.cdc_id

                UNION ALL

                SELECT \'enrollment_status\' AS activity_type,
                       CONCAT(\'Enrollment \', REPLACE(h.to_status, \'_\', \' \')) AS title,
                       CONCAT(c.first_name, \' \', c.last_name, \' — \', d.name) AS description,
                       h.created_at AS occurred_at
                FROM enrollment_status_history h
                JOIN enrollment_applications a ON a.id = h.application_id
                JOIN children c ON c.id = a.child_id
                JOIN cdcs d ON d.id = a.cdc_id

                UNION ALL

                SELECT \'attendance\' AS activity_type,
                       \'Attendance recorded\' AS title,
                       CONCAT(COUNT(*), \' attendance records for \', DATE_FORMAT(ar.attendance_date, \'%b %e, %Y\')) AS description,
                       MAX(COALESCE(ar.updated_at, ar.created_at)) AS occurred_at
                FROM attendance_records ar
                GROUP BY ar.attendance_date

                UNION ALL

                SELECT \'teacher\' AS activity_type,
                       \'Teacher account created\' AS title,
                       CONCAT(u.first_name, \' \', u.last_name) AS description,
                       u.created_at AS occurred_at
                FROM users u
                WHERE u.role = \'teacher\'

                UNION ALL

                SELECT \'child\' AS activity_type,
                       \'Child record created\' AS title,
                       CONCAT(c.first_name, \' \', c.last_name) AS description,
                       c.created_at AS occurred_at
                FROM children c
             ) recent
             WHERE occurred_at IS NOT NULL
             ORDER BY occurred_at DESC
             LIMIT 8'
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->api->respond([
            'status' => true,
            'data' => [
                'user' => [
                    'first_name' => $user['first_name'] ?? '',
                    'last_name' => $user['last_name'] ?? '',
                    'profile_photo' => $user['profile_photo'] ?? null,
                ],
                'stats' => array_map('intval', $stats),
                'monthly_enrollments' => array_map(
                    static fn($month, $count) => [
                        'month' => (int) $month,
                        'total' => (int) $count,
                    ],
                    array_keys($monthly_counts),
                    array_values($monthly_counts)
                ),
                'centers' => array_map(static function ($center) {
                    return [
                        'id' => (int) $center['id'],
                        'name' => $center['name'],
                        'location' => trim(implode(', ', array_filter([
                            $center['barangay'],
                            $center['address'],
                        ]))),
                        'children' => (int) $center['enrolled_children'],
                        'capacity' => (int) $center['capacity'],
                    ];
                }, $centers),
                'activities' => array_map(static function ($activity) {
                    return [
                        'type' => $activity['activity_type'],
                        'title' => $activity['title'],
                        'description' => $activity['description'],
                        'occurred_at' => $activity['occurred_at'],
                    ];
                }, $activities),
                'year' => (int) $year,
            ],
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
            $this->api->respond_error('You are not authorized to view the admin dashboard.', 403);
            return null;
        }
        return $user;
    }
}
