<?php

class teacher_verification_review_snapshots
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
        if (!$this->forge->column_exists('notifications', 'review_snapshot')) {
            $this->forge->add_column('notifications', [
                'review_snapshot' => [
                    'type' => 'LONGTEXT',
                    'null' => true,
                    'default' => null,
                ],
            ]);
        }
    }

    public function down()
    {
    }
}
