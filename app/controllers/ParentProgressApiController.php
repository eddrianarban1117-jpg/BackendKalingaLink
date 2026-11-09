<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ParentProgressApiController extends Controller
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

        $current_year = (int) date('Y');
        $current_school_year_start = (int) date('n') >= 6 ? $current_year : $current_year - 1;
        $school_year = $_GET['school_year'] ?? ($current_school_year_start . '-' . ($current_school_year_start + 1));
        if (!is_string($school_year)
            || !preg_match('/^(\d{4})-(\d{4})$/', $school_year, $school_year_match)
            || (int) $school_year_match[2] !== (int) $school_year_match[1] + 1) {
            $this->api->respond_error('Choose a valid school year.', 400);
            return;
        }
        $start = $school_year_match[1] . '-06-01 00:00:00';
        $end = ((int) $school_year_match[1] + 1) . '-06-01 00:00:00';
        $parent_id = (int) $parent['id'];

        $students = $this->db->raw(
            'SELECT c.id, c.first_name, c.middle_name, c.last_name, c.nickname,
                    c.birth_date, c.gender, cl.name AS class_name
             FROM children c
             LEFT JOIN classes cl ON cl.id = c.class_id
             WHERE c.parent_user_id = ?
             ORDER BY c.last_name, c.first_name, c.id',
            [$parent_id]
        )->fetchAll(PDO::FETCH_ASSOC);
        $child_ids = array_map(static function ($student) {
            return (int) $student['id'];
        }, $students);

        $records = [];
        if ($child_ids) {
            $placeholders = implode(',', array_fill(0, count($child_ids), '?'));
            $records = $this->db->raw(
                'SELECT lp.id, lp.child_id, lp.domain, lp.skill, lp.rating,
                        lp.observation, lp.recorded_at
                 FROM learning_progress lp
                 JOIN children c ON c.id = lp.child_id
                 WHERE c.parent_user_id = ?
                   AND lp.child_id IN (' . $placeholders . ')
                   AND lp.recorded_at >= ? AND lp.recorded_at < ?
                 ORDER BY lp.recorded_at DESC, lp.id DESC',
                array_merge([$parent_id], $child_ids, [$start, $end])
            )->fetchAll(PDO::FETCH_ASSOC);
        }

        $progress_by_child = [];
        $school_years = [$school_year];
        foreach ($records as $record) {
            $child_id = (int) $record['child_id'];
            $rating = (int) $record['rating'];
            if (!isset($progress_by_child[$child_id])) {
                $progress_by_child[$child_id] = ['domains' => [], 'ratings' => [], 'records' => []];
            }
            $domain = $record['domain'];
            if (!isset($progress_by_child[$child_id]['domains'][$domain])) {
                $progress_by_child[$child_id]['domains'][$domain] = ['ratings' => [], 'record_count' => 0];
            }
            $progress_by_child[$child_id]['domains'][$domain]['ratings'][] = $rating;
            $progress_by_child[$child_id]['domains'][$domain]['record_count']++;
            $progress_by_child[$child_id]['ratings'][] = $rating;
            $progress_by_child[$child_id]['records'][] = [
                'id' => (int) $record['id'],
                'date' => $record['recorded_at'],
                'domain' => $domain,
                'skill' => $record['skill'],
                'rating' => $rating,
                'observation' => $record['observation'] ?? '',
            ];
        }

        $results = [];
        foreach ($students as $student) {
            $id = (int) $student['id'];
            $progress = $progress_by_child[$id] ?? ['domains' => [], 'ratings' => [], 'records' => []];
            $domain_results = [];
            foreach ($progress['domains'] as $domain => $data) {
                $score = (int) round(min(100, max(0, array_sum($data['ratings']) / count($data['ratings']) * 20)));
                $domain_results[] = [
                    'name' => $domain,
                    'score' => $score,
                    'status' => $this->progress_status($score),
                    'record_count' => $data['record_count'],
                ];
            }
            $overall = $progress['ratings']
                ? (int) round(min(100, max(0, array_sum($progress['ratings']) / count($progress['ratings']) * 20)))
                : null;
            $birth_date = new DateTimeImmutable($student['birth_date']);
            $results[] = [
                'id' => $id,
                'name' => trim(implode(' ', array_filter([
                    $student['first_name'],
                    $student['middle_name'],
                    $student['last_name'],
                ]))),
                'age' => $birth_date->diff(new DateTimeImmutable('today'))->y,
                'birth_date' => $student['birth_date'],
                'gender' => $student['gender'],
                'class_name' => $student['class_name'] ?? '',
                'overall' => $overall,
                'domains' => $domain_results,
                'records' => $progress['records'],
            ];
        }

        $available_years = $this->db->raw(
            'SELECT DISTINCT CASE
                WHEN MONTH(lp.recorded_at) >= 6
                    THEN CONCAT(YEAR(lp.recorded_at), \'-\', YEAR(lp.recorded_at) + 1)
                ELSE CONCAT(YEAR(lp.recorded_at) - 1, \'-\', YEAR(lp.recorded_at))
             END AS school_year
             FROM learning_progress lp
             JOIN children c ON c.id = lp.child_id
             WHERE c.parent_user_id = ?
             ORDER BY school_year DESC',
            [$parent_id]
        )->fetchAll(PDO::FETCH_COLUMN);
        $school_years = array_values(array_unique(array_merge($school_years, $available_years)));
        rsort($school_years, SORT_STRING);

        $this->api->respond([
            'status' => true,
            'data' => [
                'school_year' => $school_year,
                'school_years' => $school_years,
                'students' => $results,
            ],
        ]);
    }

    private function progress_status($score)
    {
        return $score >= 75
            ? 'On Track'
            : ($score >= 60 ? 'Needs Support' : 'Needs Attention');
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
        if (!$user || (int) $user['is_active'] !== 1) {
            $this->api->respond_error('Parent account is unavailable.', 401);
            return null;
        }
        if ($user['role'] !== 'parent') {
            $this->api->respond_error('You are not authorized to view parent progress.', 403);
            return null;
        }
        return $user;
    }
}
