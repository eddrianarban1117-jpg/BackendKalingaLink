<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminLearningDevelopmentApiController extends Controller
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

        $year = $_GET['year'] ?? date('Y');
        if (!is_string($year) || !preg_match('/^\d{4}$/', $year)) {
            $this->api->respond_error('Choose a valid year.', 400);
            return;
        }
        $year = (int) $year;
        if ($year < 2000 || $year > 2100) {
            $this->api->respond_error('Choose a valid year.', 400);
            return;
        }
        $start = sprintf('%04d-01-01 00:00:00', $year);
        $end = sprintf('%04d-01-01 00:00:00', $year + 1);

        $programs = $this->db->raw(
            'SELECT lp.id, lp.title, lp.domain, lp.age_group, lp.status,
                    COALESCE(d.name, \'Unassigned\') AS center,
                    COUNT(DISTINCT p.id) AS progress_count,
                    COUNT(DISTINCT p.child_id) AS learner_count,
                    MAX(p.recorded_at) AS last_recorded_at
             FROM learning_programs lp
             LEFT JOIN cdcs d ON d.id = lp.cdc_id
             LEFT JOIN learning_progress p ON p.program_id = lp.id
             GROUP BY lp.id, lp.title, lp.domain, lp.age_group, lp.status, d.name
             ORDER BY lp.title, lp.id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $stats = $this->db->raw(
            'SELECT
                (SELECT COUNT(*) FROM learning_programs) AS total_programs,
                (SELECT COUNT(*) FROM learning_programs WHERE status = \'active\') AS active_programs,
                (SELECT COUNT(*) FROM learning_progress) AS progress_records,
                (SELECT COUNT(DISTINCT child_id) FROM learning_progress) AS learners'
        )->fetch(PDO::FETCH_ASSOC);

        $gender_rows = $this->db->raw(
            'SELECT LOWER(COALESCE(NULLIF(TRIM(c.gender), \'\'), \'other\')) AS gender,
                    COUNT(DISTINCT p.child_id) AS total
             FROM learning_progress p
             JOIN children c ON c.id = p.child_id
             GROUP BY gender'
        )->fetchAll(PDO::FETCH_ASSOC);
        $gender = ['male' => 0, 'female' => 0, 'other' => 0];
        foreach ($gender_rows as $row) {
            $key = in_array($row['gender'], ['male', 'female'], true)
                ? $row['gender']
                : 'other';
            $gender[$key] += (int) $row['total'];
        }

        $monthly = $this->db->raw(
            'SELECT MONTH(recorded_at) AS month, COUNT(*) AS total
             FROM learning_progress
             WHERE recorded_at >= ? AND recorded_at < ?
             GROUP BY MONTH(recorded_at)',
            [$start, $end]
        )->fetchAll(PDO::FETCH_ASSOC);
        $progress_by_month = array_fill(0, 12, 0);
        foreach ($monthly as $row) {
            $progress_by_month[(int) $row['month'] - 1] = (int) $row['total'];
        }

        $years = $this->db->raw(
            'SELECT DISTINCT YEAR(recorded_at) AS year
             FROM learning_progress
             ORDER BY year DESC'
        )->fetchAll(PDO::FETCH_COLUMN);
        $years = array_values(array_unique(array_map('intval', array_merge(
            [date('Y')],
            $years
        ))));
        rsort($years);

        $recent_progress = $this->db->raw(
            'SELECT p.id, p.skill, p.domain, p.rating, p.recorded_at,
                    c.first_name AS child_first_name, c.middle_name AS child_middle_name,
                    c.last_name AS child_last_name, lp.title AS program_title,
                    d.name AS center_name
             FROM learning_progress p
             JOIN children c ON c.id = p.child_id
             LEFT JOIN learning_programs lp ON lp.id = p.program_id
             LEFT JOIN cdcs d ON d.id = COALESCE(c.preferred_cdc_id, (
                 SELECT cl.cdc_id FROM class_students cs
                 JOIN classes cl ON cl.id = cs.class_id
                 WHERE cs.child_id = c.id ORDER BY cs.enrolled_at DESC LIMIT 1
             ))
             ORDER BY p.recorded_at DESC, p.id DESC
             LIMIT 5'
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->api->respond([
            'status' => true,
            'data' => [
                'year' => $year,
                'years' => $years,
                'stats' => array_map('intval', $stats),
                'programs' => array_map(static function ($program) {
                    return [
                        'id' => (int) $program['id'],
                        'title' => $program['title'],
                        'domain' => $program['domain'],
                        'age_group' => $program['age_group'],
                        'status' => $program['status'],
                        'center' => $program['center'],
                        'progress_count' => (int) $program['progress_count'],
                        'learner_count' => (int) $program['learner_count'],
                        'last_recorded_at' => $program['last_recorded_at'],
                    ];
                }, $programs),
                'gender' => $gender,
                'progress_by_month' => $progress_by_month,
                'recent_progress' => array_map(static function ($record) {
                    return [
                        'id' => (int) $record['id'],
                        'child' => trim(implode(' ', array_filter([
                            $record['child_first_name'],
                            $record['child_middle_name'],
                            $record['child_last_name'],
                        ]))),
                        'skill' => $record['skill'],
                        'domain' => $record['domain'],
                        'rating' => (int) $record['rating'],
                        'program' => $record['program_title'] ?: 'No linked program',
                        'center' => $record['center_name'] ?: 'Unassigned',
                        'recorded_at' => $record['recorded_at'],
                    ];
                }, $recent_progress),
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
            $this->api->respond_error('You are not authorized to view learning development records.', 403);
            return null;
        }
        return $user;
    }
}
