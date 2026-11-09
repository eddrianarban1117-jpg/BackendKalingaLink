<?php

class create_lesson_plan_completions
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
        if ($this->forge->table_exists('lesson_plan_completions')) return;

        $this->forge->add_field([
            'lesson_plan_id' => [
                'type' => 'BIGINT',
                'unsigned' => true,
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
            'completed_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);
        $this->forge->add_key(['lesson_plan_id', 'teacher_user_id', 'class_id'], true);
        $this->forge->add_key(['teacher_user_id', 'class_id']);
        $this->forge->add_foreign_key('lesson_plan_id', 'lesson_plans', 'id', 'CASCADE');
        $this->forge->add_foreign_key('teacher_user_id', 'users', 'id', 'CASCADE');
        $this->forge->add_foreign_key('class_id', 'classes', 'id', 'CASCADE');
        $this->forge->create_table('lesson_plan_completions');
    }

    public function down()
    {
        $this->forge->drop_table('lesson_plan_completions', true);
    }
}
