<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminReportsApiController extends Controller
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

        $stats = $this->db->raw(
            'SELECT
                (SELECT COUNT(*) FROM children) AS total_children,
                (SELECT COUNT(*) FROM users WHERE role = \'teacher\' AND is_active = 1) AS total_teachers,
                (SELECT COUNT(*) FROM cdcs) AS total_centers,
                (SELECT COUNT(*) FROM children WHERE enrollment_status = \'enrolled\') AS total_enrollments'
        )->fetch(PDO::FETCH_ASSOC);

        $center_rows = $this->db->raw(
            'SELECT d.id, d.name, d.status,
                    COUNT(DISTINCT CASE WHEN c.enrollment_status = \'enrolled\' THEN c.id END) AS enrolled
             FROM cdcs d
             LEFT JOIN children c ON COALESCE(c.preferred_cdc_id, (
                 SELECT assigned_class.cdc_id
                 FROM classes assigned_class
                 WHERE assigned_class.id = c.class_id
                 LIMIT 1
             )) = d.id
             GROUP BY d.id, d.name, d.status
             ORDER BY enrolled DESC, d.name, d.id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $gender_rows = $this->db->raw(
            'SELECT LOWER(COALESCE(NULLIF(TRIM(gender), \'\'), \'other\')) AS gender,
                    COUNT(*) AS total
             FROM children
             WHERE enrollment_status = \'enrolled\'
             GROUP BY gender'
        )->fetchAll(PDO::FETCH_ASSOC);
        $gender = ['male' => 0, 'female' => 0, 'other' => 0];
        foreach ($gender_rows as $row) {
            $key = in_array($row['gender'], ['male', 'female'], true)
                ? $row['gender']
                : 'other';
            $gender[$key] += (int) $row['total'];
        }

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
             WHERE enrollment_status = \'enrolled\'
             GROUP BY age_group'
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $period_start = (new DateTimeImmutable('first day of this month'))
            ->modify('-11 months')->setTime(0, 0);
        $period_end = $period_start->modify('+12 months');
        $applications = $this->db->raw(
            'SELECT DATE_FORMAT(submitted_at, \'%Y-%m\') AS period, COUNT(*) AS total
             FROM enrollment_applications
             WHERE submitted_at >= ? AND submitted_at < ?
             GROUP BY DATE_FORMAT(submitted_at, \'%Y-%m\')',
            [$period_start->format('Y-m-d H:i:s'), $period_end->format('Y-m-d H:i:s')]
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $approvals = $this->db->raw(
            'SELECT DATE_FORMAT(reviewed_at, \'%Y-%m\') AS period, COUNT(*) AS total
             FROM enrollment_applications
             WHERE status = \'approved\' AND reviewed_at >= ? AND reviewed_at < ?
             GROUP BY DATE_FORMAT(reviewed_at, \'%Y-%m\')',
            [$period_start->format('Y-m-d H:i:s'), $period_end->format('Y-m-d H:i:s')]
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $trend = [];
        for ($index = 0; $index < 12; $index++) {
            $month = $period_start->modify("+{$index} months");
            $key = $month->format('Y-m');
            $trend[] = [
                'month' => $month->format('M'),
                'applications' => (int) ($applications[$key] ?? 0),
                'approvals' => (int) ($approvals[$key] ?? 0),
            ];
        }

        $centers = array_map(static function ($center) {
            return [
                'id' => (int) $center['id'],
                'name' => $center['name'],
                'status' => $center['status'],
                'enrolled' => (int) $center['enrolled'],
            ];
        }, $center_rows);
        $total_enrolled = (int) $stats['total_enrollments'];
        $ages = [];
        foreach (['0-2' => '0–2 years', '3-4' => '3–4 years', '5-6' => '5–6 years', '7-12' => '7–12 years', '13+' => '13+ years'] as $key => $label) {
            $ages[] = ['label' => $label, 'count' => (int) ($age_rows[$key] ?? 0)];
        }

        $this->api->respond([
            'status' => true,
            'data' => [
                'stats' => array_map('intval', $stats),
                'trend' => $trend,
                'centers' => $centers,
                'age_groups' => $ages,
                'gender' => $gender,
                'center_status' => [
                    'active' => count(array_filter($centers, static fn($center) => $center['status'] === 'active')),
                    'inactive' => count(array_filter($centers, static fn($center) => $center['status'] === 'inactive')),
                ],
                'generated_at' => date(DATE_ATOM),
                'generated_by' => $admin['first_name'] . ' ' . $admin['last_name'],
                'enrollment_total' => $total_enrolled,
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
            $this->api->respond_error('You are not authorized to view reports.', 403);
            return null;
        }
        return $user;
    }
}
