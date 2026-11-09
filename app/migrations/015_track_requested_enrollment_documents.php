<?php

class track_requested_enrollment_documents
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
        if (!$this->forge->column_exists('enrollment_applications', 'documents_requested_at')) {
            $this->forge->add_column('enrollment_applications', [
                'documents_requested_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                    'default' => null,
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->forge->column_exists('enrollment_applications', 'documents_requested_at')) {
            $this->forge->drop_column('enrollment_applications', 'documents_requested_at');
        }
    }
}
