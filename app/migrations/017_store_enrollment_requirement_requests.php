<?php

class store_enrollment_requirement_requests
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
        if (!$this->forge->column_exists('enrollment_applications', 'requested_requirements')) {
            $this->forge->add_column('enrollment_applications', [
                'requested_requirements' => [
                    'type' => 'LONGTEXT',
                    'null' => true,
                    'default' => null,
                ],
            ]);
        }
        if (!$this->forge->column_exists('enrollment_applications', 'request_remarks')) {
            $this->forge->add_column('enrollment_applications', [
                'request_remarks' => [
                    'type' => 'TEXT',
                    'null' => true,
                    'default' => null,
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->forge->column_exists('enrollment_applications', 'request_remarks')) {
            $this->forge->drop_column('enrollment_applications', 'request_remarks');
        }
        if ($this->forge->column_exists('enrollment_applications', 'requested_requirements')) {
            $this->forge->drop_column('enrollment_applications', 'requested_requirements');
        }
    }
}
