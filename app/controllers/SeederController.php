<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class SeederController extends Controller
{
    public function run()
    {
        if (!defined('IS_CLI') || !IS_CLI) {
            http_response_code(404);
            return;
        }

        if (strtolower((string) config_item('environment')) === 'production') {
            fwrite(STDERR, "Demo seeds are disabled in production.\n");
            exit(1);
        }

        $password = (string) getenv('DEMO_SEED_PASSWORD');
        if (strlen($password) < 12) {
            fwrite(STDERR, "Set DEMO_SEED_PASSWORD to a value at least 12 characters long in .env.\n");
            exit(1);
        }
        $admin_email = trim((string) (getenv('DEMO_ADMIN_EMAIL') ?: 'admin@kalingalink.local'));
        if (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
            fwrite(STDERR, "Set DEMO_ADMIN_EMAIL to a valid email address in .env.\n");
            exit(1);
        }

        $this->call->database();
        $this->call->dbforge();
        $cdc_status = $this->call->library('CdcStatus', $this->db);
        if (!$this->dbforge->table_exists('enrollment_applications')) {
            fwrite(STDERR, "CDC tables are missing. Run php console/cli.php db:migrate first.\n");
            exit(1);
        }

        $this->db->transaction();
        try {
            $cacawan_id = $this->require_cdc('Cacawan Centro Pamana CDC');
            $marfrancisco_id = $this->require_cdc('Marfrancisco CDC');

            $admin_id = $this->ensure_demo_admin($admin_email, $password);
            $teacher_id = $this->get_or_create_user('maria.teacher', 'maria.teacher@kalingalink.local', 'teacher', 'Maria', 'Santos', $password);
            $teacher_two_id = $this->get_or_create_user('jose.teacher', 'jose.teacher@kalingalink.local', 'teacher', 'Jose', 'Reyes', $password);
            $parent_id = $this->get_or_create_user('ana.parent', 'ana.parent@kalingalink.local', 'parent', 'Ana', 'Dela Cruz', $password);
            $parent_two_id = $this->get_or_create_user('carla.parent', 'carla.parent@kalingalink.local', 'parent', 'Carla', 'Reyes', $password);

            $this->ensure_teacher_approval($teacher_id, $admin_id);
            $this->ensure_teacher_approval($teacher_two_id, $admin_id);
            $this->ensure_teacher_profile($teacher_id, $cacawan_id);
            $this->ensure_teacher_profile($teacher_two_id, $marfrancisco_id);
            $cdc_status->sync($cacawan_id);
            $cdc_status->sync($marfrancisco_id);
            $this->ensure_parent_profile($parent_id, 'Ana Dela Cruz', 'Mother', 'Cacawan, Pinamalayan');
            $this->ensure_parent_profile($parent_two_id, 'Carla Reyes', 'Mother', 'Marfrancisco, Pinamalayan');

            $class_id = $this->get_or_create_class($cacawan_id, $teacher_id, 'Nursery A', '2026-2027');
            $child_id = $this->get_or_create_child($parent_id, $cacawan_id, $class_id, 'Juan', 'Reyes', 'Dela Cruz', '2022-03-12', 'Male');
            $child_two_id = $this->get_or_create_child($parent_two_id, $marfrancisco_id, null, 'Ella', '', 'Reyes', '2021-02-10', 'Female');

            $this->ensure_application('APP-2026-0001', $parent_id, $child_id, $cacawan_id, 'Ana Dela Cruz', 'Mother', 'ana.parent@kalingalink.local', '09123456789', 'Juan Reyes Dela Cruz', 'pending');
            $this->ensure_application('APP-2026-0002', $parent_two_id, $child_two_id, $marfrancisco_id, 'Carla Reyes', 'Mother', 'carla.parent@kalingalink.local', '09264447788', 'Ella Reyes', 'approved', $admin_id);

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        echo "Development seed data is ready.\n";
        echo "Demo usernames: admin, maria.teacher, jose.teacher, ana.parent, carla.parent\n";
        echo "Demo admin email is configured with DEMO_ADMIN_EMAIL.\n";
        echo "New demo accounts use the password configured in DEMO_SEED_PASSWORD.\n";
    }

    private function require_cdc($name)
    {
        $existing = $this->db->table('cdcs')
            ->select('id')
            ->where('name', $name)
            ->get();
        if (!$existing) {
            throw new RuntimeException(
                'The required CDC is missing: ' . $name . '. Add the actual CDC record before running development seeds.'
            );
        }
        return (int) $existing['id'];
    }

    private function get_or_create_user($username, $email, $role, $first_name, $last_name, $password)
    {
        $existing = $this->db->table('users')
            ->select('id, role')
            ->where('email', $email)
            ->or_where('username', $username)
            ->get();
        if ($existing) {
            if ($existing['role'] !== $role) {
                throw new RuntimeException('A demo account identity is already used with a different role: ' . $email);
            }
            return (int) $existing['id'];
        }

        $this->db->table('users')->insert([
            'username' => $username,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'is_active' => 1,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'phone' => '09123456789',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->db->last_id();
    }

    private function ensure_demo_admin($email, $password)
    {
        $admin = $this->db->table('users')
            ->select('id, username, email, role')
            ->where('username', 'admin')
            ->get();
        $email_owner = $this->db->table('users')
            ->select('id, username, email, role')
            ->where('email', $email)
            ->get();

        if ($admin && $admin['role'] !== 'admin') {
            throw new RuntimeException('The demo admin username is already assigned to a different role.');
        }
        if ($email_owner && (!$admin || (int) $email_owner['id'] !== (int) $admin['id'])) {
            throw new RuntimeException('The configured demo admin email is already in use.');
        }

        if ($admin) {
            $this->db->table('users')
                ->where('id', $admin['id'])
                ->update([
                    'email' => $email,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                ]);
            return (int) $admin['id'];
        }

        if ($email_owner) {
            if ($email_owner['role'] !== 'admin') {
                throw new RuntimeException('The configured demo admin email is already assigned to a different role.');
            }
            $this->db->table('users')
                ->where('id', $email_owner['id'])
                ->update([
                    'username' => 'admin',
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                ]);
            return (int) $email_owner['id'];
        }

        return $this->get_or_create_user(
            'admin',
            $email,
            'admin',
            'MSWDO',
            'Administrator',
            $password
        );
    }

    private function ensure_teacher_profile($user_id, $cdc_id)
    {
        $existing = $this->db->table('teacher_profiles')
            ->select('user_id, cdc_id')
            ->where('user_id', $user_id)
            ->get();
        if ($existing) {
            if ((int) ($existing['cdc_id'] ?? 0) !== (int) $cdc_id) {
                $this->db->table('teacher_profiles')
                    ->where('user_id', (int) $user_id)
                    ->update([
                        'cdc_id' => (int) $cdc_id,
                        'employment_status' => 'active',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            }
            return;
        }
        $this->db->table('teacher_profiles')->insert([
            'user_id' => $user_id,
            'cdc_id' => $cdc_id,
            'position' => 'Child Development Worker',
            'employment_status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function ensure_teacher_approval($teacher_id, $admin_id)
    {
        $now = date('Y-m-d H:i:s');
        $this->db->raw(
            'INSERT INTO teacher_account_verification (
                teacher_user_id, status, approved_by, approved_at, accepted_at, created_at, updated_at
             ) VALUES (?, \'active\', ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                status = \'active\', approved_by = VALUES(approved_by),
                approved_at = VALUES(approved_at), accepted_at = VALUES(accepted_at),
                updated_at = VALUES(updated_at)',
            [(int) $teacher_id, (int) $admin_id, $now, $now, $now, $now]
        );
        $latest = $this->db->raw(
            'SELECT title FROM notifications
             WHERE related_type = \'teacher_verification\' AND related_id = ?
             ORDER BY id DESC LIMIT 1',
            [(int) $teacher_id]
        )->fetch(PDO::FETCH_ASSOC);
        $this->db->table('users')
            ->where('id', (int) $teacher_id)
            ->update(['is_active' => 1]);
        if (($latest['title'] ?? null) === 'Approved') return;

        $this->db->table('notifications')->insert([
            'actor_user_id' => (int) $admin_id,
            'title' => 'Approved',
            'message' => 'The development seed administrator approved this demo teacher account.',
            'audience' => 'single',
            'related_type' => 'teacher_verification',
            'related_id' => (int) $teacher_id,
            'related_module' => 'teacher_management',
            'notification_type' => 'System Update',
            'action_url' => '/teacher/dashboard',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->db->table('notification_recipients')->insert([
            'notification_id' => (int) $this->db->last_id(),
            'user_id' => (int) $teacher_id,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function ensure_parent_profile($user_id, $name, $relationship, $address)
    {
        $existing = $this->db->table('parent_profiles')
            ->select('user_id')
            ->where('user_id', $user_id)
            ->get();
        if ($existing) {
            return;
        }
        $this->db->table('parent_profiles')->insert([
            'user_id' => $user_id,
            'relationship' => $relationship,
            'address' => $address,
            'barangay' => 'San Isidro',
            'emergency_name' => 'Ramon Dela Cruz',
            'emergency_relationship' => 'Spouse',
            'emergency_phone' => '09135556677',
            'household_income' => '₱10,000–₱20,000',
            'preferred_language' => 'Filipino',
            'referral_source' => 'Barangay',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function get_or_create_class($cdc_id, $teacher_id, $name, $school_year)
    {
        $existing = $this->db->table('classes')
            ->select('id')
            ->where('cdc_id', $cdc_id)
            ->where('name', $name)
            ->where('school_year', $school_year)
            ->get();
        if ($existing) {
            return (int) $existing['id'];
        }
        $this->db->table('classes')->insert([
            'cdc_id' => $cdc_id,
            'teacher_user_id' => $teacher_id,
            'name' => $name,
            'school_year' => $school_year,
            'capacity' => 25,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->db->last_id();
    }

    private function get_or_create_child($parent_id, $cdc_id, $class_id, $first, $middle, $last, $birth_date, $gender)
    {
        $existing = $this->db->table('children')
            ->select('id')
            ->where('parent_user_id', $parent_id)
            ->where('first_name', $first)
            ->where('last_name', $last)
            ->where('birth_date', $birth_date)
            ->get();
        if ($existing) {
            return (int) $existing['id'];
        }
        $this->db->table('children')->insert([
            'parent_user_id' => $parent_id,
            'preferred_cdc_id' => $cdc_id,
            'class_id' => $class_id,
            'first_name' => $first,
            'middle_name' => $middle !== '' ? $middle : null,
            'last_name' => $last,
            'birth_date' => $birth_date,
            'gender' => $gender,
            'address' => null,
            'enrollment_status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $child_id = $this->db->last_id();
        if ($class_id !== null) {
            $this->db->table('class_students')->insert([
                'class_id' => $class_id,
                'child_id' => $child_id,
                'enrolled_at' => date('Y-m-d H:i:s'),
            ]);
        }
        return $child_id;
    }

    private function ensure_application($number, $parent_id, $child_id, $cdc_id, $parent_name, $relationship, $email, $phone, $child_name, $status, $reviewed_by = null)
    {
        $existing = $this->db->table('enrollment_applications')
            ->select('id')
            ->where('application_number', $number)
            ->get();
        if ($existing) {
            return;
        }
        $teacher = $this->db->raw(
            'SELECT tp.user_id FROM teacher_profiles tp
             JOIN users u ON u.id = tp.user_id
             JOIN notifications review ON review.id = (
                 SELECT latest.id FROM notifications latest
                 WHERE latest.related_type = \'teacher_verification\'
                   AND latest.related_id = u.id ORDER BY latest.id DESC LIMIT 1
             )
             WHERE tp.cdc_id = ? AND tp.employment_status = \'active\'
               AND u.role = \'teacher\' AND u.is_active = 1
               AND review.title = \'Approved\' LIMIT 1',
            [(int) $cdc_id]
        )->fetch(PDO::FETCH_ASSOC);
        $this->db->table('enrollment_applications')->insert([
            'application_number' => $number,
            'parent_user_id' => $parent_id,
            'child_id' => $child_id,
            'cdc_id' => $cdc_id,
            'assigned_teacher_user_id' => $teacher ? (int) $teacher['user_id'] : null,
            'enrollment_type' => 'new',
            'school_year' => '2026-2027',
            'parent_name' => $parent_name,
            'parent_relationship' => $relationship,
            'parent_email' => $email,
            'parent_phone' => $phone,
            'parent_address' => 'San Isidro, Baguio City',
            'emergency_name' => 'Ramon Dela Cruz',
            'emergency_relationship' => 'Spouse',
            'emergency_phone' => '09135556677',
            'household_income' => '₱10,000–₱20,000',
            'preferred_language' => 'Filipino',
            'referral_source' => 'Barangay',
            'status' => $status,
            'review_note' => null,
            'submitted_at' => date('Y-m-d H:i:s'),
            'reviewed_by' => $reviewed_by,
            'reviewed_at' => $reviewed_by ? date('Y-m-d H:i:s') : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $application_id = $this->db->last_id();
        $this->db->table('enrollment_status_history')->insert([
            'application_id' => $application_id,
            'changed_by' => $reviewed_by,
            'from_status' => null,
            'to_status' => $status,
            'note' => 'Demo application record for ' . $child_name,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}