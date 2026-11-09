<?php

class assign_enrollment_applications
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
        if (!$this->forge->column_exists('enrollment_applications', 'assigned_teacher_user_id')) {
            $this->forge->add_column('enrollment_applications', [
                'assigned_teacher_user_id' => [
                    'type' => 'INT',
                    'unsigned' => true,
                    'null' => true,
                    'default' => null,
                ],
            ]);
            $this->lava->db->raw(
                'ALTER TABLE enrollment_applications
                 ADD INDEX enrollment_assigned_teacher_idx (assigned_teacher_user_id),
                 ADD CONSTRAINT enrollment_assigned_teacher_fk
                 FOREIGN KEY (assigned_teacher_user_id) REFERENCES users(id) ON DELETE SET NULL'
            );
        }

        $this->lava->db->raw(
            'UPDATE enrollment_applications a
             JOIN (
                 SELECT tp.cdc_id, MIN(tp.user_id) AS user_id
                 FROM teacher_profiles tp
                 JOIN users u ON u.id = tp.user_id
                 WHERE tp.employment_status = \'active\'
                   AND u.role = \'teacher\'
                   AND u.is_active = 1
                 GROUP BY tp.cdc_id
                 HAVING COUNT(*) = 1
             ) assigned ON assigned.cdc_id = a.cdc_id
             SET a.assigned_teacher_user_id = assigned.user_id
             WHERE a.assigned_teacher_user_id IS NULL'
        );
    }

    public function down()
    {
    }
}
