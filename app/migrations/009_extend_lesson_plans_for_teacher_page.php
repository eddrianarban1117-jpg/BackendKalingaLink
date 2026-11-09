<?php

class extend_lesson_plans_for_teacher_page
{
    private $forge;

    public function __construct()
    {
        $lava = lava_instance();
        $lava->call->dbforge();
        $this->forge = $lava->dbforge;
    }

    public function up()
    {
        $columns = [
            'topic' => [
                'type' => 'VARCHAR',
                'constraint' => 180,
                'null' => false,
                'default' => '',
            ],
            'age_group' => [
                'type' => 'VARCHAR',
                'constraint' => 80,
                'null' => false,
                'default' => '',
            ],
            'duration_minutes' => [
                'type' => 'SMALLINT',
                'unsigned' => true,
                'null' => false,
                'default' => 45,
            ],
            'objectives_json' => [
                'type' => 'JSON',
                'null' => true,
                'default' => null,
            ],
            'procedure_json' => [
                'type' => 'JSON',
                'null' => true,
                'default' => null,
            ],
            'materials_json' => [
                'type' => 'JSON',
                'null' => true,
                'default' => null,
            ],
            'is_favorite' => [
                'type' => 'TINYINT',
                'unsigned' => true,
                'null' => false,
                'default' => 0,
            ],
            'workflow_status' => [
                'type' => 'ENUM',
                'constraint' => "'draft','for_review','finalized','in_progress','upcoming'",
                'null' => false,
                'default' => 'draft',
            ],
        ];

        foreach ($columns as $column => $definition) {
            if (!$this->forge->column_exists('lesson_plans', $column)) {
                $this->forge->add_column('lesson_plans', [$column => $definition]);
            }
        }

        if (!$this->forge->column_exists('lesson_plans', 'scheduled_date')) {
            $this->forge->add_column('lesson_plans', [
                'scheduled_date' => [
                    'type' => 'DATE',
                    'null' => true,
                    'default' => null,
                ],
            ]);
        }
    }

    public function down()
    {
        foreach ([
            'topic',
            'age_group',
            'duration_minutes',
            'objectives_json',
            'procedure_json',
            'materials_json',
            'is_favorite',
            'workflow_status',
            'scheduled_date',
        ] as $column) {
            if ($this->forge->column_exists('lesson_plans', $column)) {
                $this->forge->drop_column('lesson_plans', $column);
            }
        }
    }
}
