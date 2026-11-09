<?php

class extend_teacher_profile_details
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
        $columns = [
            'birth_date' => ['type' => 'DATE', 'null' => true, 'default' => null],
            'gender' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'default' => null],
            'marital_status' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'default' => null],
            'nationality' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true, 'default' => null],
            'education' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'certification' => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'years_of_service' => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true, 'default' => null],
            'home_address' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'emergency_name' => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true, 'default' => null],
            'emergency_relationship' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true, 'default' => null],
            'emergency_phone' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'default' => null],
            'skills' => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'languages' => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'working_schedule' => ['type' => 'TEXT', 'null' => true, 'default' => null],
        ];

        foreach ($columns as $column => $definition) {
            if (!$this->forge->column_exists('teacher_profiles', $column)) {
                $this->forge->add_column('teacher_profiles', [$column => $definition]);
            }
        }
    }

    public function down()
    {
        $columns = [
            'birth_date',
            'gender',
            'marital_status',
            'nationality',
            'education',
            'certification',
            'years_of_service',
            'home_address',
            'emergency_name',
            'emergency_relationship',
            'emergency_phone',
            'skills',
            'languages',
            'working_schedule',
        ];

        foreach ($columns as $column) {
            if ($this->forge->column_exists('teacher_profiles', $column)) {
                $this->forge->drop_column('teacher_profiles', $column);
            }
        }
    }
}
