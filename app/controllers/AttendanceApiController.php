<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AttendanceApiController extends Controller
{
    private const STATUSES = ['present', 'late', 'absent', 'excused'];

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
        $user = $this->authenticated_user(['admin', 'teacher', 'parent']);
        if (!$user) return;

        $start_date = $_GET['from'] ?? $_GET['date'] ?? date('Y-m-d');
        $end_date = $_GET['to'] ?? $_GET['date'] ?? $start_date;
        if (!$this->valid_date($start_date) || !$this->valid_date($end_date) || $start_date > $end_date) {
            $this->api->respond_error('Provide a valid attendance date range.', 400);
            return;
        }

        if ($user['role'] === 'parent' || $user['role'] === 'admin') {
            $scope = $user['role'] === 'parent' ? ' WHERE c.parent_user_id = ? AND' : ' WHERE';
            $params = $user['role'] === 'parent'
                ? [(int) $user['id'], $start_date, $end_date]
                : [$start_date, $end_date];
            $records = $this->db->raw(
                'SELECT ar.id, ar.child_id, ar.class_id, ar.attendance_date, ar.status,
                        ar.time_in, ar.remarks, c.first_name, c.middle_name, c.last_name, c.birth_date,
                        c.nickname, cl.name AS class_name, d.name AS cdc_name
                 FROM attendance_records ar
                 JOIN children c ON c.id = ar.child_id
                 JOIN classes cl ON cl.id = ar.class_id
                 JOIN cdcs d ON d.id = cl.cdc_id
                 ' . $scope . ' ar.attendance_date BETWEEN ? AND ?
                 ORDER BY ar.attendance_date DESC, c.last_name, c.first_name',
                $params
            )->fetchAll(PDO::FETCH_ASSOC);
            $this->api->respond(['status' => true, 'data' => $records]);
            return;
        }

        $sql = 'SELECT c.id AS child_id, cl.id AS class_id, c.first_name, c.middle_name,
                       c.last_name, c.nickname, c.birth_date, c.gender,
                       cl.name AS class_name, d.name AS cdc_name,
                       ar.id AS attendance_id, ar.status, ar.time_in, ar.remarks
                FROM (
                    SELECT child_id, class_id FROM class_students
                    UNION
                    SELECT id AS child_id, class_id
                    FROM children
                    WHERE class_id IS NOT NULL AND enrollment_status = \'enrolled\'
                ) roster
                JOIN children c ON c.id = roster.child_id
                JOIN classes cl ON cl.id = roster.class_id
                JOIN cdcs d ON d.id = cl.cdc_id
                LEFT JOIN attendance_records ar
                    ON ar.child_id = c.id AND ar.class_id = cl.id AND ar.attendance_date = ?
                WHERE c.enrollment_status = \'enrolled\' AND cl.status = \'active\'';
        $params = [$start_date];
        if ($user['role'] === 'teacher') {
            $sql .= ' AND EXISTS (
                          SELECT 1 FROM teacher_profiles tp
                          WHERE tp.cdc_id = cl.cdc_id AND tp.user_id = ?
                            AND tp.employment_status = \'active\'
                      )';
            $params[] = (int) $user['id'];
        }
        $sql .= ' ORDER BY cl.name, c.last_name, c.first_name';
        $records = $this->db->raw($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
        $this->api->respond(['status' => true, 'data' => $records]);
    }

    public function save()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user(['admin', 'teacher']);
        if (!$user) return;

        $input = $this->api->body(false);
        $date = $input['attendance_date'] ?? null;
        $entries = $input['entries'] ?? null;
        if (!$this->valid_date($date) || !is_array($entries) || !$entries || count($entries) > 200) {
            $this->api->respond_error('Provide a valid date and between one and 200 attendance entries.', 400);
            return;
        }

        $normalized = [];
        $seen = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                $this->api->respond_error('Each attendance entry must be an object.', 400);
                return;
            }
            $child_id = filter_var($entry['child_id'] ?? null, FILTER_VALIDATE_INT);
            $class_id = filter_var($entry['class_id'] ?? null, FILTER_VALIDATE_INT);
            $status = $entry['status'] ?? null;
            $remarks = $entry['remarks'] ?? '';
            $time_in = $entry['time_in'] ?? null;
            $valid_time = $time_in === null || $time_in === ''
                || (is_string($time_in) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $time_in));
            if (!$child_id || !$class_id || !is_string($status) || !in_array($status, self::STATUSES, true)
                || !is_string($remarks) || mb_strlen($remarks) > 2000
                || !$valid_time) {
                $this->api->respond_error('One or more attendance entries have invalid values.', 400);
                return;
            }
            $key = (string) $child_id;
            if (isset($seen[$key])) {
                $this->api->respond_error('A child can only have one attendance record per day.', 400);
                return;
            }
            if ($status === 'late' && ($time_in === null || $time_in === '')) {
                $this->api->respond_error('Enter the arrival time for every late student.', 400);
                return;
            }
            if (in_array($status, ['late', 'excused'], true) && trim($remarks) === '') {
                $this->api->respond_error(
                    $status === 'late'
                        ? 'Enter a note explaining why the student was late.'
                        : 'Enter a note explaining why the student is excused.',
                    400
                );
                return;
            }
            $seen[$key] = true;
            $normalized[] = [
                'child_id' => (int) $child_id,
                'class_id' => (int) $class_id,
                'status' => $status,
                'remarks' => trim($remarks) !== '' ? trim($remarks) : null,
                'time_in' => $time_in === '' ? null : $time_in,
            ];
        }

        $teacher_cdc_id = null;
        if ($user['role'] === 'teacher') {
            $teacher = $this->db->table('teacher_profiles')
                ->select('cdc_id')
                ->where('user_id', (int) $user['id'])
                ->get();
            if (!$teacher) {
                $this->api->respond_error('The teacher is not assigned to a Child Development Center.', 403);
                return;
            }
            $teacher_cdc_id = (int) $teacher['cdc_id'];
        }

        $this->db->transaction();
        try {
            foreach ($normalized as $entry) {
                $sql = 'SELECT cl.id
                        FROM classes cl
                        JOIN children c ON c.id = ?
                        WHERE cl.id = ? AND cl.status = \'active\'
                          AND c.enrollment_status = \'enrolled\'
                          AND (
                              c.class_id = cl.id
                              OR EXISTS (
                                  SELECT 1 FROM class_students cs
                                  WHERE cs.child_id = c.id AND cs.class_id = cl.id
                              )
                          )';
                $params = [$entry['child_id'], $entry['class_id']];
                if ($teacher_cdc_id !== null) {
                    $sql .= ' AND cl.cdc_id = ?';
                    $params[] = $teacher_cdc_id;
                }
                if (!$this->db->raw($sql, $params)->fetch(PDO::FETCH_ASSOC)) {
                    throw new InvalidArgumentException('A child is not enrolled in a class you are authorized to manage.');
                }

                $previous = $this->db->raw(
                    'SELECT id
                     FROM attendance_records
                     WHERE child_id = ? AND attendance_date = ?
                     LIMIT 1 FOR UPDATE',
                    [$entry['child_id'], $date]
                )->fetch(PDO::FETCH_ASSOC);
                if ($previous) {
                    throw new DomainException(
                        'Attendance has already been saved for this student on the selected date.'
                    );
                }

                $this->db->raw(
                    'INSERT INTO attendance_records
                        (child_id, class_id, teacher_user_id, attendance_date, status, time_in, remarks, is_automatic, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())',
                    [
                        $entry['child_id'],
                        $entry['class_id'],
                        $user['role'] === 'teacher' ? (int) $user['id'] : null,
                        $date,
                        $entry['status'],
                        $entry['time_in'],
                        $entry['remarks'],
                    ]
                );
                $child = $this->db->raw(
                    'SELECT parent_user_id, first_name, last_name
                     FROM children WHERE id = ?',
                    [$entry['child_id']]
                )->fetch(PDO::FETCH_ASSOC);
                if ($child && $child['parent_user_id']) {
                    $child_name = trim($child['first_name'] . ' ' . $child['last_name']);
                    $this->db->table('notifications')->insert([
                        'actor_user_id' => (int) $user['id'],
                        'title' => 'Attendance recorded',
                        'message' => 'Attendance for ' . $child_name . ' on ' . $date
                            . ' was marked ' . $entry['status'] . '.',
                        'audience' => 'single',
                        'related_type' => 'attendance',
                        'related_id' => $entry['child_id'],
                        'related_module' => 'attendance',
                        'notification_type' => 'Attendance',
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    $notification_id = (int) $this->db->last_id();
                    if ($notification_id < 1) {
                        throw new RuntimeException('The attendance notification could not be recorded.');
                    }
                    $this->db->table('notification_recipients')->insert([
                        'notification_id' => $notification_id,
                        'user_id' => (int) $child['parent_user_id'],
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            if ($error instanceof DomainException) {
                $this->api->respond_error($error->getMessage(), 409);
                return;
            }
            if ($error instanceof InvalidArgumentException) {
                $this->api->respond_error($error->getMessage(), 403);
                return;
            }
            throw $error;
        }

        $this->api->respond([
            'status' => true,
            'message' => 'Attendance saved.',
            'count' => count($normalized),
        ]);
    }

    private function authenticated_user(array $roles)
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
        if (!in_array($user['role'], $roles, true)) {
            $this->api->respond_error('You are not authorized to perform this action.', 403);
            return null;
        }
        if ($user['role'] === 'teacher') {
            $teacher_access = $this->call->library('TeacherAccess', $this->db);
            if (!$teacher_access->is_active((int) $user['id'])) {
                $this->api->respond_error('Teacher account approval and invitation acceptance are required.', 403);
                return null;
            }
        }
        return $user;
    }

    private function valid_date($value)
    {
        if (!is_string($value)) return false;
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value && $value <= date('Y-m-d');
    }
}
