<?php

class extend_account_profiles
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
            'parent_profiles' => [
                'contact_email' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
                'suffix' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'default' => null],
                'municipality' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'default' => null],
                'province' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'default' => null],
                'postal_code' => ['type' => 'VARCHAR', 'constraint' => 12, 'null' => true, 'default' => null],
            ],
            'teacher_profiles' => [
                'employee_number' => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true, 'default' => null],
                'contact_email' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            ],
            'children' => [
                'suffix' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'default' => null],
                'residence_barangay' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'default' => null],
                'preferred_cdc_label' => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true, 'default' => null],
            ],
        ];

        foreach ($columns as $table => $table_columns) {
            foreach ($table_columns as $column => $definition) {
                if (!$this->forge->column_exists($table, $column)) {
                    $this->forge->add_column($table, [$column => $definition]);
                }
            }
        }
    }

    public function down()
    {
        $columns = [
            'parent_profiles' => ['contact_email', 'suffix', 'municipality', 'province', 'postal_code'],
            'teacher_profiles' => ['employee_number', 'contact_email'],
            'children' => ['suffix', 'residence_barangay', 'preferred_cdc_label'],
        ];

        foreach ($columns as $table => $table_columns) {
            foreach ($table_columns as $column) {
                if ($this->forge->column_exists($table, $column)) {
                    $this->forge->drop_column($table, $column);
                }
            }
        }
    }
}