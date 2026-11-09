<?php

class Create_learning_activities_table
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
        if ($this->forge->table_exists('learning_activities')) {
            return;
        }

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
            'class_id' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => false,
            ],
            'title' => [
                'type' => 'VARCHAR',
                'constraint' => 180,
                'null' => false,
            ],
            'domain' => [
                'type' => 'VARCHAR',
                'constraint' => 120,
                'null' => false,
            ],
            'age_group' => [
                'type' => 'VARCHAR',
                'constraint' => 80,
                'null' => false,
            ],
            'start_date' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'end_date' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'duration_minutes' => [
                'type' => 'SMALLINT',
                'unsigned' => true,
                'null' => false,
            ],
            'objective' => [
                'type' => 'TEXT',
                'null' => true,
                'default' => null,
            ],
            'materials_json' => [
                'type' => 'JSON',
                'null' => false,
            ],
            'instructions_json' => [
                'type' => 'JSON',
                'null' => false,
            ],
            'observations' => [
                'type' => 'TEXT',
                'null' => true,
                'default' => null,
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
        $this->forge->add_key(['teacher_user_id', 'class_id']);
        $this->forge->add_key(['start_date', 'end_date']);
        $this->forge->add_foreign_key('teacher_user_id', 'users', 'id', 'CASCADE');
        $this->forge->add_foreign_key('class_id', 'classes', 'id', 'CASCADE');
        $this->forge->create_table('learning_activities');
    }

    public function down()
    {
        $this->forge->drop_table('learning_activities');
    }
}
