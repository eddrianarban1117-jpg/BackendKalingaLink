<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class TeacherChildrenProgressApiController extends Controller
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
        $user = $this->authenticated_teacher();
        if (!$user) return;

        $month = $_GET['month'] ?? date('Y-m');
        if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $this->api->respond_error('Choose a valid progress month.', 400);
            return;
        }
        $start = $month . '-01 00:00:00';
        $end = (new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d H:i:s');
        $teacher_id = (int) $user['id'];

        $students = $this->db->raw(
            'SELECT c.id, c.first_name, c.middle_name, c.last_name, c.nickname,
                    c.birth_date, c.gender
             FROM class_students cs
             JOIN classes cl ON cl.id = cs.class_id
             JOIN teacher_profiles tp ON tp.cdc_id = cl.cdc_id
             JOIN children c ON c.id = cs.child_id
             WHERE tp.user_id = ? AND tp.employment_status = \'active\'
               AND cl.status = \'active\' AND c.enrollment_status = \'enrolled\'
             GROUP BY c.id, c.first_name, c.middle_name, c.last_name,
                      c.nickname, c.birth_date, c.gender
             ORDER BY c.last_name, c.first_name, c.id',
            [$teacher_id]
        )->fetchAll(PDO::FETCH_ASSOC);

        $records = $this->db->raw(
            'SELECT lp.id, lp.child_id, lp.domain, lp.skill, lp.rating,
                    lp.observation, lp.recorded_at
             FROM learning_progress lp
             JOIN class_students cs ON cs.child_id = lp.child_id
             JOIN classes cl ON cl.id = cs.class_id
             JOIN teacher_profiles tp ON tp.cdc_id = cl.cdc_id
             WHERE tp.user_id = ? AND tp.employment_status = \'active\'
               AND cl.status = \'active\' AND lp.teacher_user_id = ?
               AND lp.recorded_at >= ? AND lp.recorded_at < ?
             GROUP BY lp.id
             ORDER BY lp.recorded_at DESC, lp.id DESC',
            [$teacher_id, $teacher_id, $start, $end]
        )->fetchAll(PDO::FETCH_ASSOC);

        $progress_by_child = [];
        $domains = [];
        foreach ($records as $record) {
            $child_id = (int) $record['child_id'];
            $rating = (int) $record['rating'];
            if (!isset($progress_by_child[$child_id])) {
                $progress_by_child[$child_id] = [
                    'domains' => [],
                    'notes' => [],
                    'observations' => [],
                    'records' => [],
                    'ratings' => [],
                ];
            }
            $domain = $record['domain'];
            if (!isset($progress_by_child[$child_id]['domains'][$domain])) {
                $progress_by_child[$child_id]['domains'][$domain] = [
                    'ratings' => [],
                    'skills' => [],
                ];
            }
            $progress_by_child[$child_id]['domains'][$domain]['ratings'][] = $rating;
            $progress_by_child[$child_id]['domains'][$domain]['skills'][] = $record['skill'];
            $progress_by_child[$child_id]['ratings'][] = $rating;
            $domains[$domain] = true;

            $entry = [
                'id' => (int) $record['id'],
                'date' => $record['recorded_at'],
                'domain' => $domain,
                'skill' => $record['skill'],
                'rating' => $rating,
                'observation' => $record['observation'],
            ];
            if ($record['observation'] !== null && trim($record['observation']) !== '') {
                $progress_by_child[$child_id]['notes'][] = $entry;
                $progress_by_child[$child_id]['observations'][] = $entry;
            }
            $progress_by_child[$child_id]['records'][] = $entry;
        }

        $results = [];
        foreach ($students as $student) {
            $id = (int) $student['id'];
            $progress = $progress_by_child[$id] ?? [
                'domains' => [],
                'notes' => [],
                'observations' => [],
                'records' => [],
                'ratings' => [],
            ];
            $domain_results = [];
            foreach ($progress['domains'] as $domain => $data) {
                $average = array_sum($data['ratings']) / count($data['ratings']);
                $score = (int) round(min(100, max(0, $average * 20)));
                $domain_results[] = [
                    'name' => $domain,
                    'score' => $score,
                    'status' => $this->progress_status($score),
                    'record_count' => count($data['ratings']),
                    'skills' => array_values(array_unique($data['skills'])),
                ];
            }
            $overall = $progress['ratings']
                ? (int) round(min(100, max(0, array_sum($progress['ratings']) / count($progress['ratings']) * 20)))
                : 0;
            $name = trim(implode(' ', array_filter([
                $student['first_name'],
                $student['middle_name'],
                $student['last_name'],
            ])));
            $age = (new DateTimeImmutable($student['birth_date']))
                ->diff(new DateTimeImmutable('today'))->y;
            $results[] = [
                'id' => $id,
                'name' => $name,
                'age' => $age,
                'birthday' => $student['birth_date'],
                'status' => $domain_results
                    ? $this->progress_status($overall)
                    : null,
                'overall' => $overall,
                'domains' => $domain_results,
                'notes' => $progress['notes'],
                'observations' => $progress['observations'],
                'records' => $progress['records'],
            ];
        }

        $this->api->respond([
            'status' => true,
            'data' => [
                'month' => $month,
                'students' => $results,
                'domains' => array_keys($domains),
            ],
        ]);
    }

    public function create()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_teacher();
        if (!$user) return;

        $input = $this->api->body(false);
        if (!is_array($input)) {
            $this->api->respond_error('Provide progress record details.', 400);
            return;
        }
        $child_id = filter_var($input['child_id'] ?? null, FILTER_VALIDATE_INT);
        $domain = trim((string) ($input['domain'] ?? ''));
        $skill = trim((string) ($input['skill'] ?? ''));
        $rating = filter_var($input['rating'] ?? null, FILTER_VALIDATE_INT);
        $observation = trim((string) ($input['observation'] ?? ''));
        $recorded_at = $input['recorded_at'] ?? date('Y-m-d');
        if (!$child_id || $domain === '' || mb_strlen($domain) > 120
            || $skill === '' || mb_strlen($skill) > 180
            || !$rating || $rating > 5 || mb_strlen($observation) > 10000
            || !$this->valid_date($recorded_at)) {
            $this->api->respond_error('Provide a valid child, domain, skill, rating from 1 to 5, and date.', 400);
            return;
        }

        $assigned = $this->db->raw(
            'SELECT c.id, c.parent_user_id, c.first_name, c.last_name
             FROM children c
             JOIN class_students cs ON cs.child_id = c.id
             JOIN classes cl ON cl.id = cs.class_id
             JOIN teacher_profiles tp ON tp.cdc_id = cl.cdc_id
             WHERE c.id = ? AND tp.user_id = ? AND tp.employment_status = \'active\'
               AND cl.status = \'active\' AND c.enrollment_status = \'enrolled\'
             GROUP BY c.id, c.parent_user_id, c.first_name, c.last_name
             LIMIT 1',
            [(int) $child_id, (int) $user['id']]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$assigned) {
            $this->api->respond_error('This child is not enrolled in one of your active classes.', 403);
            return;
        }

        $this->db->transaction();
        try {
            $this->db->table('learning_progress')->insert([
                'child_id' => (int) $child_id,
                'teacher_user_id' => (int) $user['id'],
                'domain' => $domain,
                'skill' => $skill,
                'rating' => (int) $rating,
                'observation' => $observation !== '' ? $observation : null,
                'recorded_at' => $recorded_at . ' ' . date('H:i:s'),
            ]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        $notification_warning = null;
        if ($assigned['parent_user_id']) {
            $this->db->transaction();
            try {
                $child_name = trim($assigned['first_name'] . ' ' . $assigned['last_name']);
                $this->db->table('notifications')->insert([
                    'actor_user_id' => (int) $user['id'],
                    'title' => 'Child progress updated',
                    'message' => 'New progress was recorded for ' . $child_name
                        . ' in ' . $domain . ': ' . $skill . '.',
                    'audience' => 'single',
                    'related_type' => 'progress',
                    'related_id' => (int) $child_id,
                    'related_module' => 'children_progress',
                    'notification_type' => 'Progress',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $this->db->table('notification_recipients')->insert([
                    'notification_id' => (int) $this->db->last_id(),
                    'user_id' => (int) $assigned['parent_user_id'],
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $this->db->commit();
            } catch (Throwable $notification_error) {
                $this->db->roll_back();
                $log_message = preg_replace(
                    '/[\r\n]+/',
                    ' ',
                    $notification_error->getMessage()
                );
                error_log('[Teacher progress notification] ' . $log_message);
                $notification_warning = 'The progress record was saved, but the parent notification could not be created.';
            }
        }
        $this->api->respond([
            'status' => true,
            'message' => 'Progress record saved.',
            'warning' => $notification_warning,
        ]);
    }

    private function progress_status($score)
    {
        return $score >= 75
            ? 'On Track'
            : ($score >= 60 ? 'Needs Support' : 'Needs Attention');
    }

    private function valid_date($value)
    {
        if (!is_string($value)) return false;
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value;
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
            $this->api->respond_error('You are not authorized to manage children progress.', 403);
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
