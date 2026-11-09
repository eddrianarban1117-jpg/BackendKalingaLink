<?php

class create_cdc_management_schema
{
    private $_lava;
    private $forge;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
        $this->forge = $this->_lava->dbforge;
    }

    public function up()
    {
        $this->forge->modify_column('users', [
            'role' => [
                'type' => 'ENUM',
                'constraint' => "'admin','teacher','parent','moderator','user'",
                'null' => false,
                'default' => 'user',
            ],
        ]);

        $user_columns = [
            'first_name' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'last_name' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'phone' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'default' => null],
            'profile_photo' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'default' => null],
            'last_login_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ];
        foreach ($user_columns as $column => $definition) {
            if (!$this->forge->column_exists('users', $column)) {
                $this->forge->add_column('users', [$column => $definition]);
            }
        }

        $this->create_table('cdcs', [
            'id' => $this->id('INT'),
            'name' => $this->varchar(160),
            'address' => $this->varchar(255),
            'barangay' => $this->varchar(120),
            'center_head' => $this->varchar(160, true),
            'contact_number' => $this->varchar(30, true),
            'capacity' => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'status' => ['type' => 'ENUM', 'constraint' => "'active','inactive'", 'null' => false, 'default' => 'active'],
            'created_at' => $this->created_at(),
            'updated_at' => $this->updated_at(),
        ], [['name'], ['status']]);

        $this->create_table('teacher_profiles', [
            'user_id' => $this->foreign_id('INT'),
            'cdc_id' => $this->foreign_id('INT'),
            'position' => $this->varchar(120, true),
            'hire_date' => ['type' => 'DATE', 'null' => true, 'default' => null],
            'employment_status' => ['type' => 'ENUM', 'constraint' => "'active','inactive'", 'null' => false, 'default' => 'active'],
            'created_at' => $this->created_at(),
            'updated_at' => $this->updated_at(),
        ], [['user_id', true]], [
            ['user_id', 'users', 'id', 'CASCADE'],
            ['cdc_id', 'cdcs', 'id', 'RESTRICT'],
        ], ['user_id']);

        $this->create_table('parent_profiles', [
            'user_id' => $this->foreign_id('INT'),
            'birth_date' => ['type' => 'DATE', 'null' => true, 'default' => null],
            'gender' => $this->varchar(40, true),
            'relationship' => $this->varchar(80, true),
            'address' => $this->varchar(255, true),
            'barangay' => $this->varchar(120, true),
            'emergency_name' => $this->varchar(160, true),
            'emergency_relationship' => $this->varchar(80, true),
            'emergency_phone' => $this->varchar(30, true),
            'household_income' => $this->varchar(80, true),
            'preferred_language' => $this->varchar(80, true),
            'referral_source' => $this->varchar(120, true),
            'created_at' => $this->created_at(),
            'updated_at' => $this->updated_at(),
        ], [['user_id', true]], [
            ['user_id', 'users', 'id', 'CASCADE'],
        ], ['user_id']);

        $this->create_table('classes', [
            'id' => $this->id('INT'),
            'cdc_id' => $this->foreign_id('INT'),
            'teacher_user_id' => $this->foreign_id('INT', true),
            'name' => $this->varchar(120),
            'school_year' => $this->varchar(20),
            'capacity' => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'status' => ['type' => 'ENUM', 'constraint' => "'active','inactive'", 'null' => false, 'default' => 'active'],
            'created_at' => $this->created_at(),
            'updated_at' => $this->updated_at(),
        ], [['cdc_id', 'name', 'school_year', true]], [
            ['cdc_id', 'cdcs', 'id', 'CASCADE'],
            ['teacher_user_id', 'users', 'id', 'SET NULL'],
        ]);

        $this->create_table('children', [
            'id' => $this->id('BIGINT'),
            'parent_user_id' => $this->foreign_id('INT'),
            'preferred_cdc_id' => $this->foreign_id('INT', true),
            'class_id' => $this->foreign_id('INT', true),
            'first_name' => $this->varchar(100),
            'middle_name' => $this->varchar(100, true),
            'last_name' => $this->varchar(100),
            'nickname' => $this->varchar(100, true),
            'birth_date' => ['type' => 'DATE', 'null' => false],
            'gender' => $this->varchar(40),
            'address' => $this->varchar(255, true),
            'enrollment_status' => ['type' => 'ENUM', 'constraint' => "'pending','enrolled','withdrawn'", 'null' => false, 'default' => 'pending'],
            'medical_notes' => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'created_at' => $this->created_at(),
            'updated_at' => $this->updated_at(),
        ], [['parent_user_id'], ['preferred_cdc_id'], ['class_id'], ['enrollment_status']], [
            ['parent_user_id', 'users', 'id', 'RESTRICT'],
            ['preferred_cdc_id', 'cdcs', 'id', 'SET NULL'],
            ['class_id', 'classes', 'id', 'SET NULL'],
        ]);

        $this->create_table('enrollment_applications', [
            'id' => $this->id('BIGINT'),
            'application_number' => $this->varchar(40),
            'parent_user_id' => $this->foreign_id('INT'),
            'child_id' => $this->foreign_id('BIGINT'),
            'cdc_id' => $this->foreign_id('INT'),
            'enrollment_type' => ['type' => 'ENUM', 'constraint' => "'new','reenrollment'", 'null' => false, 'default' => 'new'],
            'school_year' => $this->varchar(20),
            'parent_name' => $this->varchar(160),
            'parent_relationship' => $this->varchar(80),
            'parent_email' => $this->varchar(255),
            'parent_phone' => $this->varchar(30),
            'parent_birth_date' => ['type' => 'DATE', 'null' => true, 'default' => null],
            'parent_gender' => $this->varchar(40, true),
            'parent_address' => $this->varchar(255),
            'emergency_name' => $this->varchar(160),
            'emergency_relationship' => $this->varchar(80),
            'emergency_phone' => $this->varchar(30),
            'household_income' => $this->varchar(80, true),
            'preferred_language' => $this->varchar(80, true),
            'referral_source' => $this->varchar(120, true),
            'status' => ['type' => 'ENUM', 'constraint' => "'pending','under_review','approved','rejected'", 'null' => false, 'default' => 'pending'],
            'review_note' => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'submitted_at' => ['type' => 'DATETIME', 'null' => false, 'default' => 'CURRENT_TIMESTAMP'],
            'reviewed_by' => $this->foreign_id('INT', true),
            'reviewed_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at' => $this->created_at(),
            'updated_at' => $this->updated_at(),
        ], [['application_number', true], ['parent_user_id'], ['child_id'], ['cdc_id'], ['status'], ['school_year']], [
            ['parent_user_id', 'users', 'id', 'RESTRICT'],
            ['child_id', 'children', 'id', 'RESTRICT'],
            ['cdc_id', 'cdcs', 'id', 'RESTRICT'],
            ['reviewed_by', 'users', 'id', 'SET NULL'],
        ]);

        $this->create_table('enrollment_documents', [
            'id' => $this->id('BIGINT'),
            'application_id' => $this->foreign_id('BIGINT'),
            'document_type' => $this->varchar(100),
            'original_name' => $this->varchar(255),
            'stored_name' => $this->varchar(255),
            'storage_path' => $this->varchar(500),
            'mime_type' => $this->varchar(120),
            'file_size' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'sha256' => $this->varchar(64),
            'verification_status' => ['type' => 'ENUM', 'constraint' => "'received','verified','rejected'", 'null' => false, 'default' => 'received'],
            'uploaded_at' => ['type' => 'DATETIME', 'null' => false, 'default' => 'CURRENT_TIMESTAMP'],
        ], [['application_id'], ['verification_status']], [
            ['application_id', 'enrollment_applications', 'id', 'CASCADE'],
        ]);

        $this->create_table('enrollment_status_history', [
            'id' => $this->id('BIGINT'),
            'application_id' => $this->foreign_id('BIGINT'),
            'changed_by' => $this->foreign_id('INT', true),
            'from_status' => $this->varchar(30, true),
            'to_status' => $this->varchar(30),
            'note' => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'created_at' => $this->created_at(),
        ], [['application_id'], ['changed_by']], [
            ['application_id', 'enrollment_applications', 'id', 'CASCADE'],
            ['changed_by', 'users', 'id', 'SET NULL'],
        ]);

        $this->create_table('class_students', [
            'id' => $this->id('BIGINT'),
            'class_id' => $this->foreign_id('INT'),
            'child_id' => $this->foreign_id('BIGINT'),
            'enrolled_at' => ['type' => 'DATETIME', 'null' => false, 'default' => 'CURRENT_TIMESTAMP'],
        ], [['class_id', 'child_id', true]], [
            ['class_id', 'classes', 'id', 'CASCADE'],
            ['child_id', 'children', 'id', 'CASCADE'],
        ]);

        $this->create_table('attendance_records', [
            'id' => $this->id('BIGINT'),
            'child_id' => $this->foreign_id('BIGINT'),
            'class_id' => $this->foreign_id('INT'),
            'teacher_user_id' => $this->foreign_id('INT', true),
            'attendance_date' => ['type' => 'DATE', 'null' => false],
            'status' => ['type' => 'ENUM', 'constraint' => "'present','late','absent','excused'", 'null' => false],
            'time_in' => ['type' => 'TIME', 'null' => true, 'default' => null],
            'remarks' => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'is_automatic' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
            'created_at' => $this->created_at(),
            'updated_at' => $this->updated_at(),
        ], [['child_id', 'attendance_date', true], ['class_id', 'attendance_date']], [
            ['child_id', 'children', 'id', 'CASCADE'],
            ['class_id', 'classes', 'id', 'CASCADE'],
            ['teacher_user_id', 'users', 'id', 'SET NULL'],
        ]);

        $this->create_table('teacher_attendance', [
            'id' => $this->id('BIGINT'),
            'teacher_user_id' => $this->foreign_id('INT'),
            'cdc_id' => $this->foreign_id('INT'),
            'attendance_date' => ['type' => 'DATE', 'null' => false],
            'status' => ['type' => 'ENUM', 'constraint' => "'present','late','absent','excused'", 'null' => false],
            'time_in' => ['type' => 'TIME', 'null' => true, 'default' => null],
            'remarks' => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'created_at' => $this->created_at(),
            'updated_at' => $this->updated_at(),
        ], [['teacher_user_id', 'attendance_date', true], ['cdc_id', 'attendance_date']], [
            ['teacher_user_id', 'users', 'id', 'CASCADE'],
            ['cdc_id', 'cdcs', 'id', 'RESTRICT'],
        ]);

        $this->create_table('attendance_summaries', [
            'id' => $this->id('BIGINT'),
            'class_id' => $this->foreign_id('INT'),
            'attendance_date' => ['type' => 'DATE', 'null' => false],
            'present_count' => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'late_count' => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'absent_count' => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'excused_count' => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'saved_by' => $this->foreign_id('INT', true),
            'saved_at' => ['type' => 'DATETIME', 'null' => false, 'default' => 'CURRENT_TIMESTAMP'],
        ], [['class_id', 'attendance_date', true]], [
            ['class_id', 'classes', 'id', 'CASCADE'],
            ['saved_by', 'users', 'id', 'SET NULL'],
        ]);

        $this->create_table('learning_programs', [
            'id' => $this->id('INT'),
            'cdc_id' => $this->foreign_id('INT', true),
            'title' => $this->varchar(180),
            'domain' => $this->varchar(120),
            'age_group' => $this->varchar(80),
            'description' => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'status' => ['type' => 'ENUM', 'constraint' => "'active','inactive'", 'null' => false, 'default' => 'active'],
            'created_at' => $this->created_at(),
            'updated_at' => $this->updated_at(),
        ], [['cdc_id'], ['domain']], [
            ['cdc_id', 'cdcs', 'id', 'CASCADE'],
        ]);

        $this->create_table('lesson_plans', [
            'id' => $this->id('BIGINT'),
            'teacher_user_id' => $this->foreign_id('INT'),
            'class_id' => $this->foreign_id('INT'),
            'title' => $this->varchar(180),
            'description' => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'domain' => $this->varchar(120),
            'delivery_mode' => ['type' => 'ENUM', 'constraint' => "'take_home','face_to_face'", 'null' => false],
            'file_type' => ['type' => 'ENUM', 'constraint' => "'pdf','questionnaire','docx'", 'null' => false],
            'file_path' => $this->varchar(500, true),
            'questionnaire_json' => ['type' => 'JSON', 'null' => true, 'default' => null],
            'due_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'published_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'status' => ['type' => 'ENUM', 'constraint' => "'draft','published','archived'", 'null' => false, 'default' => 'draft'],
            'created_at' => $this->created_at(),
            'updated_at' => $this->updated_at(),
        ], [['teacher_user_id'], ['class_id'], ['status']], [
            ['teacher_user_id', 'users', 'id', 'CASCADE'],
            ['class_id', 'classes', 'id', 'CASCADE'],
        ]);

        $this->create_table('activity_submissions', [
            'id' => $this->id('BIGINT'),
            'lesson_plan_id' => $this->foreign_id('BIGINT'),
            'child_id' => $this->foreign_id('BIGINT'),
            'answers_json' => ['type' => 'JSON', 'null' => true, 'default' => null],
            'file_path' => $this->varchar(500, true),
            'submitted_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'feedback' => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'score' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true, 'default' => null],
            'status' => ['type' => 'ENUM', 'constraint' => "'assigned','submitted','reviewed'", 'null' => false, 'default' => 'assigned'],
            'created_at' => $this->created_at(),
            'updated_at' => $this->updated_at(),
        ], [['lesson_plan_id', 'child_id', true], ['child_id'], ['status']], [
            ['lesson_plan_id', 'lesson_plans', 'id', 'CASCADE'],
            ['child_id', 'children', 'id', 'CASCADE'],
        ]);

        $this->create_table('learning_progress', [
            'id' => $this->id('BIGINT'),
            'child_id' => $this->foreign_id('BIGINT'),
            'program_id' => $this->foreign_id('INT', true),
            'teacher_user_id' => $this->foreign_id('INT'),
            'domain' => $this->varchar(120),
            'skill' => $this->varchar(180),
            'rating' => ['type' => 'TINYINT', 'unsigned' => true, 'null' => false],
            'observation' => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'recorded_at' => ['type' => 'DATETIME', 'null' => false, 'default' => 'CURRENT_TIMESTAMP'],
            'created_at' => $this->created_at(),
        ], [['child_id'], ['program_id'], ['teacher_user_id'], ['domain']], [
            ['child_id', 'children', 'id', 'CASCADE'],
            ['program_id', 'learning_programs', 'id', 'SET NULL'],
            ['teacher_user_id', 'users', 'id', 'RESTRICT'],
        ]);

        $this->create_table('announcements', [
            'id' => $this->id('BIGINT'),
            'author_user_id' => $this->foreign_id('INT'),
            'cdc_id' => $this->foreign_id('INT', true),
            'class_id' => $this->foreign_id('INT', true),
            'title' => $this->varchar(180),
            'message' => ['type' => 'TEXT', 'null' => false],
            'audience' => ['type' => 'ENUM', 'constraint' => "'teachers','parents','both'", 'null' => false],
            'published_at' => ['type' => 'DATETIME', 'null' => false, 'default' => 'CURRENT_TIMESTAMP'],
            'expires_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at' => $this->created_at(),
            'updated_at' => $this->updated_at(),
        ], [['author_user_id'], ['cdc_id'], ['class_id'], ['published_at']], [
            ['author_user_id', 'users', 'id', 'CASCADE'],
            ['cdc_id', 'cdcs', 'id', 'CASCADE'],
            ['class_id', 'classes', 'id', 'SET NULL'],
        ]);

        $this->create_table('notifications', [
            'id' => $this->id('BIGINT'),
            'actor_user_id' => $this->foreign_id('INT', true),
            'title' => $this->varchar(180),
            'message' => ['type' => 'TEXT', 'null' => false],
            'audience' => ['type' => 'ENUM', 'constraint' => "'teachers','parents','both','single'", 'null' => false],
            'related_type' => $this->varchar(80, true),
            'related_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at' => $this->created_at(),
        ], [['actor_user_id'], ['audience'], ['created_at']], [
            ['actor_user_id', 'users', 'id', 'SET NULL'],
        ]);

        $this->create_table('notification_recipients', [
            'id' => $this->id('BIGINT'),
            'notification_id' => $this->foreign_id('BIGINT'),
            'user_id' => $this->foreign_id('INT'),
            'read_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at' => $this->created_at(),
        ], [['notification_id', 'user_id', true], ['user_id', 'read_at']], [
            ['notification_id', 'notifications', 'id', 'CASCADE'],
            ['user_id', 'users', 'id', 'CASCADE'],
        ]);

        $this->create_table('system_settings', [
            'setting_key' => $this->varchar(100),
            'setting_value' => ['type' => 'LONGTEXT', 'null' => false],
            'updated_by' => $this->foreign_id('INT', true),
            'updated_at' => ['type' => 'DATETIME', 'null' => false, 'default' => 'CURRENT_TIMESTAMP'],
        ], [['setting_key', true]], [
            ['updated_by', 'users', 'id', 'SET NULL'],
        ], 'setting_key');

        $this->create_table('audit_logs', [
            'id' => $this->id('BIGINT'),
            'user_id' => $this->foreign_id('INT', true),
            'action' => $this->varchar(120),
            'entity_type' => $this->varchar(100),
            'entity_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'default' => null],
            'metadata_json' => ['type' => 'JSON', 'null' => true, 'default' => null],
            'ip_address' => $this->varchar(45, true),
            'created_at' => $this->created_at(),
        ], [['user_id'], ['entity_type', 'entity_id'], ['created_at']], [
            ['user_id', 'users', 'id', 'SET NULL'],
        ]);
    }

    public function down()
    {
        $tables = [
            'audit_logs', 'system_settings', 'notification_recipients', 'notifications',
            'announcements', 'learning_progress', 'activity_submissions', 'lesson_plans',
            'learning_programs', 'attendance_summaries', 'teacher_attendance',
            'attendance_records', 'class_students', 'enrollment_status_history',
            'enrollment_documents', 'enrollment_applications', 'children', 'classes',
            'parent_profiles', 'teacher_profiles', 'cdcs',
        ];
        foreach ($tables as $table) {
            $this->forge->drop_table($table);
        }

        $this->forge->modify_column('users', [
            'role' => [
                'type' => 'ENUM',
                'constraint' => "'admin','moderator','user'",
                'null' => false,
                'default' => 'user',
            ],
        ]);
        foreach (['first_name', 'last_name', 'phone', 'profile_photo', 'last_login_at'] as $column) {
            if ($this->forge->column_exists('users', $column)) {
                $this->forge->drop_column('users', $column);
            }
        }
    }

    private function create_table($name, array $fields, array $keys = [], array $foreign_keys = [], $primary_key = 'id')
    {
        if ($this->forge->table_exists($name)) {
            return;
        }

        $this->forge->add_field($fields);
        $this->forge->add_key($primary_key, true);
        foreach ($keys as $key) {
            $unique = end($key) === true;
            if ($unique) {
                array_pop($key);
            }
            $this->forge->add_key($key, false, $unique);
        }
        foreach ($foreign_keys as [$field, $table, $reference, $on_delete]) {
            $this->forge->add_foreign_key($field, $table, $reference, $on_delete);
        }
        $this->forge->create_table($name);
    }

    private function id($type)
    {
        return ['type' => $type, 'unsigned' => true, 'auto_increment' => true, 'null' => false];
    }

    private function foreign_id($type, $nullable = false)
    {
        $definition = ['type' => $type, 'unsigned' => true, 'null' => $nullable];
        if ($nullable) {
            $definition['default'] = null;
        }
        return $definition;
    }

    private function varchar($length, $nullable = false)
    {
        $definition = ['type' => 'VARCHAR', 'constraint' => $length, 'null' => $nullable];
        if ($nullable) {
            $definition['default'] = null;
        }
        return $definition;
    }

    private function created_at()
    {
        return ['type' => 'DATETIME', 'null' => false, 'default' => 'CURRENT_TIMESTAMP'];
    }

    private function updated_at()
    {
        return ['type' => 'DATETIME', 'null' => true, 'default' => null];
    }
}