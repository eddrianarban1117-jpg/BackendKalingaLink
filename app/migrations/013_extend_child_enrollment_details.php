<?php

class extend_child_enrollment_details
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
            'psa_birth_certificate_number' => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true, 'default' => null],
            'nationality' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true, 'default' => null],
            'place_of_birth' => ['type' => 'VARCHAR', 'constraint' => 180, 'null' => true, 'default' => null],
            'blood_type' => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true, 'default' => null],
            'allergies' => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'immunization_status' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'default' => null],
            'enrolled_at' => ['type' => 'DATE', 'null' => true, 'default' => null],
        ];
        foreach ($columns as $column => $definition) {
            if (!$this->forge->column_exists('children', $column)) {
                $this->forge->add_column('children', [$column => $definition]);
            }
        }
    }

    public function down()
    {
        foreach ([
            'psa_birth_certificate_number',
            'nationality',
            'place_of_birth',
            'blood_type',
            'allergies',
            'immunization_status',
            'enrolled_at',
        ] as $column) {
            if ($this->forge->column_exists('children', $column)) {
                $this->forge->drop_column('children', $column);
            }
        }
    }
}
