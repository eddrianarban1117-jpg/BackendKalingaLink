<?php

class receive_transfer_acceptance
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
        if (!$this->forge->column_exists('child_transfer_requests', 'teacher_reported_parent_confirmation')) {
            $this->forge->add_column('child_transfer_requests', [
                'teacher_reported_parent_confirmation' => [
                    'type' => 'ENUM',
                    'constraint' => "'yes','no'",
                    'null' => true,
                    'default' => null,
                ],
            ]);
        }

        $this->lava->db->raw(
            'INSERT INTO notifications (
                actor_user_id, title, message, audience, related_type, related_id,
                related_module, notification_type, action_url, created_at
            )
            SELECT t.previous_teacher_user_id,
                   \'Incoming transferee awaiting acknowledgement\',
                   \'A child was transferred to your CDC before receiving-teacher acceptance was added. Please review and acknowledge the student now listed on your roster.\',
                   \'single\', \'child_transfer\', t.id, \'child_transfers\',
                   \'System Update\', \'/teacher/students\', NOW()
            FROM child_transfer_requests t
            WHERE t.status = \'completed\'
              AND t.receiving_teacher_accepted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1
                  FROM notifications n
                  JOIN notification_recipients nr ON nr.notification_id = n.id
                  WHERE n.related_type = \'child_transfer\'
                    AND n.related_id = t.id
                    AND n.title = \'Incoming transferee awaiting acknowledgement\'
                    AND nr.user_id = t.receiving_teacher_user_id
              )'
        );
        $this->lava->db->raw(
            'INSERT INTO notification_recipients (notification_id, user_id, created_at)
            SELECT n.id, t.receiving_teacher_user_id, NOW()
            FROM child_transfer_requests t
            JOIN notifications n
              ON n.related_type = \'child_transfer\'
             AND n.related_id = t.id
             AND n.title = \'Incoming transferee awaiting acknowledgement\'
            WHERE t.status = \'completed\'
              AND t.receiving_teacher_accepted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1
                  FROM notification_recipients nr
                  WHERE nr.notification_id = n.id
                    AND nr.user_id = t.receiving_teacher_user_id
              )'
        );
    }

    public function down()
    {
        if ($this->forge->column_exists('child_transfer_requests', 'teacher_reported_parent_confirmation')) {
            $this->forge->drop_column('child_transfer_requests', 'teacher_reported_parent_confirmation');
        }
    }
}
