<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminEnrollmentOverviewApiController extends Controller
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

        $school_year = $_GET['school_year'] ?? $this->current_school_year();
        if (!is_string($school_year)
            || !preg_match('/^(\d{4})-(\d{4})$/', $school_year, $year_parts)
            || (int) $year_parts[2] !== (int) $year_parts[1] + 1) {
            $this->api->respond_error('Choose a valid school year.', 400);
            return;
        }

        $start_year = (int) $year_parts[1];
        $period_start = sprintf('%04d-06-01 00:00:00', $start_year);
        $period_end = sprintf('%04d-06-01 00:00:00', $start_year + 1);

        $stats = $this->db->raw(
            'SELECT
                (SELECT COUNT(*) FROM enrollment_applications WHERE school_year = ?) AS total_applicants,
                (SELECT COUNT(*) FROM enrollment_applications WHERE school_year = ? AND status = \'pending\') AS pending_applications,
                (SELECT COUNT(*) FROM enrollment_applications WHERE school_year = ? AND status = \'rejected\') AS rejected_applications,
                (SELECT COUNT(*) FROM children WHERE enrollment_status = \'enrolled\') AS enrolled_children',
            [$school_year, $school_year, $school_year]
        )->fetch(PDO::FETCH_ASSOC);

        $monthly_applications = $this->db->raw(
            'SELECT MONTH(submitted_at) AS month_number, COUNT(*) AS total
             FROM enrollment_applications
             WHERE school_year = ? AND submitted_at >= ? AND submitted_at < ?
             GROUP BY MONTH(submitted_at)',
            [$school_year, $period_start, $period_end]
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $monthly_approvals = $this->db->raw(
            'SELECT MONTH(reviewed_at) AS month_number, COUNT(*) AS total
             FROM enrollment_applications
             WHERE school_year = ? AND status = \'approved\'
               AND reviewed_at >= ? AND reviewed_at < ?
             GROUP BY MONTH(reviewed_at)',
            [$school_year, $period_start, $period_end]
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $gender_rows = $this->db->raw(
            'SELECT gender, COUNT(*) AS total
             FROM children
             WHERE enrollment_status = \'enrolled\'
             GROUP BY gender'
        )->fetchAll(PDO::FETCH_ASSOC);
        $gender = ['male' => 0, 'female' => 0, 'other' => 0];
        foreach ($gender_rows as $row) {
            $key = strtolower(trim((string) $row['gender']));
            if ($key === 'male') {
                $gender['male'] += (int) $row['total'];
            } elseif ($key === 'female') {
                $gender['female'] += (int) $row['total'];
            } else {
                $gender['other'] += (int) $row['total'];
            }
        }

        $age_rows = $this->db->raw(
            'SELECT
                CASE
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

        $activity_rows = $this->db->raw(
            'SELECT h.to_status, h.created_at, a.application_number,
                    CONCAT(c.first_name, \' \', c.last_name) AS child_name
             FROM enrollment_status_history h
             JOIN enrollment_applications a ON a.id = h.application_id
             JOIN children c ON c.id = a.child_id
             ORDER BY h.created_at DESC, h.id DESC
             LIMIT 5'
        )->fetchAll(PDO::FETCH_ASSOC);

        $months = [];
        for ($index = 0; $index < 12; $index++) {
            $month_number = (($index + 5) % 12) + 1;
            $months[] = [
                'month' => date('M', mktime(0, 0, 0, $month_number, 1)),
                'applications' => (int) ($monthly_applications[$month_number] ?? 0),
                'approvals' => (int) ($monthly_approvals[$month_number] ?? 0),
            ];
        }

        $this->api->respond([
            'status' => true,
            'data' => [
                'school_year' => $school_year,
                'stats' => array_map('intval', $stats),
                'monthly_trend' => $months,
                'gender' => $gender,
                'age_groups' => [
                    ['label' => '0–2', 'count' => (int) ($age_rows['0-2'] ?? 0)],
                    ['label' => '3–4', 'count' => (int) ($age_rows['3-4'] ?? 0)],
                    ['label' => '5–6', 'count' => (int) ($age_rows['5-6'] ?? 0)],
                    ['label' => '7–12', 'count' => (int) ($age_rows['7-12'] ?? 0)],
                    ['label' => '13+', 'count' => (int) ($age_rows['13+'] ?? 0)],
                ],
                'activities' => array_map(static function ($activity) {
                    return [
                        'status' => $activity['to_status'],
                        'child_name' => $activity['child_name'],
                        'application_number' => $activity['application_number'],
                        'occurred_at' => $activity['created_at'],
                    ];
                }, $activity_rows),
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
            $this->api->respond_error('You are not authorized to view enrollment analytics.', 403);
            return null;
        }
        return $user;
    }

    private function current_school_year()
    {
        $year = (int) date('Y');
        return date('n') >= 6
            ? $year . '-' . ($year + 1)
            : ($year - 1) . '-' . $year;
    }
}
