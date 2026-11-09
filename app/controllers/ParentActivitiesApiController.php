<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ParentActivitiesApiController extends Controller
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

        $activities = $this->db->raw(
            'SELECT lp.id, lp.title, lp.description, lp.topic, lp.domain,
                    lp.age_group, lp.scheduled_date, lp.due_at, lp.published_at,
                    lp.objectives_json, lp.procedure_json, lp.materials_json,
                    c.id AS child_id, c.first_name, c.middle_name, c.last_name,
                    c.birth_date,
                    COALESCE(submission.status, \'assigned\') AS submission_status,
                    submission.submitted_at, submission.score, submission.feedback
             FROM lesson_plans lp
             JOIN class_students cs ON cs.class_id = lp.class_id
             JOIN children c ON c.id = cs.child_id
             LEFT JOIN activity_submissions submission
               ON submission.lesson_plan_id = lp.id AND submission.child_id = c.id
             WHERE c.parent_user_id = ? AND lp.status = \'published\'
               AND lp.published_at IS NOT NULL
             ORDER BY COALESCE(lp.due_at, lp.published_at) DESC, lp.id DESC,
                      c.last_name, c.first_name',
            [(int) $user_id]
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($activities as &$activity) {
            $activity['id'] = (int) $activity['id'];
            $activity['child_id'] = (int) $activity['child_id'];
            $activity['score'] = $activity['score'] === null ? null : (float) $activity['score'];
            foreach (['objectives_json', 'procedure_json', 'materials_json'] as $field) {
                $decoded = json_decode($activity[$field] ?? '[]', true);
                $activity[str_replace('_json', '', $field)] = is_array($decoded)
                    ? array_values(array_filter($decoded, 'is_string'))
                    : [];
                unset($activity[$field]);
            }
            $activity['child_name'] = trim(implode(' ', array_filter([
                $activity['first_name'],
                $activity['middle_name'],
                $activity['last_name'],
            ])));
            unset($activity['first_name'], $activity['middle_name'], $activity['last_name']);
        }
        unset($activity);

        $this->api->respond([
            'status' => true,
            'data' => $activities,
        ]);
    }
}
