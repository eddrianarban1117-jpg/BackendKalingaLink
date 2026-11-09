<?php

class add_enrollment_child_snapshots
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
            'child_first_name' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'child_middle_name' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'child_last_name' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'child_nickname' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'child_birth_date' => ['type' => 'DATE', 'null' => true, 'default' => null],
            'child_gender' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'default' => null],
            'child_address' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'child_psa_number' => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true, 'default' => null],
            'child_nationality' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true, 'default' => null],
            'child_place_of_birth' => ['type' => 'VARCHAR', 'constraint' => 180, 'null' => true, 'default' => null],
            'child_blood_type' => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true, 'default' => null],
            'child_medical_condition' => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'child_allergies' => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'child_immunization_status' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'default' => null],
        ];
        foreach ($columns as $column => $definition) {
            if (!$this->forge->column_exists('enrollment_applications', $column)) {
                $this->forge->add_column('enrollment_applications', [$column => $definition]);
            }
        }
    }

    public function down()
    {
        foreach ([
            'child_first_name',
            'child_middle_name',
            'child_last_name',
            'child_nickname',
            'child_birth_date',
            'child_gender',
            'child_address',
            'child_psa_number',
            'child_nationality',
            'child_place_of_birth',
            'child_blood_type',
            'child_medical_condition',
            'child_allergies',
            'child_immunization_status',
        ] as $column) {
            if ($this->forge->column_exists('enrollment_applications', $column)) {
                $this->forge->drop_column('enrollment_applications', $column);
            }
        }
    }
}
