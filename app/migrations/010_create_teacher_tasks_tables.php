<?php

class create_teacher_tasks_tables
{
    private $lava;
    private $forge;

    public function __construct()
    {
        $this->lava = lava_instance();
        $this->lava->call->dbforge();
        $this->forge = $this->lava->dbforge;
    }

    public function up()
    {
        if (!$this->forge->table_exists('teacher_tasks')) {
            $this->forge->add_field([
                'id' => [
                    'type' => 'BIGINT',
                    'unsigned' => true,
                    'auto_increment' => true,
                    'null' => false,
                ],
                'teacher_user_id' => [
                    'type' => 'INT',
                    'unsigned' => true,
                    'null' => false,
                ],
                'title' => [
                    'type' => 'VARCHAR',
                    'constraint' => 180,
                    'null' => false,
                ],
                'description' => [
                    'type' => 'TEXT',
                    'null' => true,
                    'default' => null,
                ],
                'task_type' => [
                    'type' => 'VARCHAR',
                    'constraint' => 40,
                    'null' => false,
                ],
                'due_at' => [
                    'type' => 'DATETIME',
                    'null' => false,
                ],
                'status' => [
                    'type' => 'ENUM',
                    'constraint' => "'not_started','pending','in_progress'",
                    'null' => false,
                    'default' => 'pending',
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => false,
                    'default' => 'CURRENT_TIMESTAMP',
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                    'default' => null,
                ],
            ]);
            $this->forge->add_key('id', true);
            $this->forge->add_key(['teacher_user_id', 'due_at']);
            $this->forge->add_foreign_key('teacher_user_id', 'users', 'id', 'CASCADE');
            $this->forge->create_table('teacher_tasks');
        }

        if (!$this->forge->table_exists('teacher_task_submissions')) {
            $this->forge->add_field([
                'id' => [
                    'type' => 'BIGINT',
                    'unsigned' => true,
                    'auto_increment' => true,
                    'null' => false,
                ],
                'task_id' => [
                    'type' => 'BIGINT',
                    'unsigned' => true,
                    'null' => false,
                ],
                'teacher_user_id' => [
                    'type' => 'INT',
                    'unsigned' => true,
                    'null' => false,
                ],
                'submission_text' => [
                    'type' => 'TEXT',
                    'null' => true,
                    'default' => null,
                ],
                'submitted_at' => [
                    'type' => 'DATETIME',
                    'null' => false,
                    'default' => 'CURRENT_TIMESTAMP',
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => false,
                    'default' => 'CURRENT_TIMESTAMP',
                ],
            ]);
            $this->forge->add_key('id', true);
            $this->forge->add_key(['task_id', 'submitted_at']);
            $this->forge->add_foreign_key('task_id', 'teacher_tasks', 'id', 'CASCADE');
            $this->forge->add_foreign_key('teacher_user_id', 'users', 'id', 'CASCADE');
            $this->forge->create_table('teacher_task_submissions');
        }
    }

    public function down()
    {
        $this->forge->drop_table('teacher_task_submissions', true);
        $this->forge->drop_table('teacher_tasks', true);
    }
}
