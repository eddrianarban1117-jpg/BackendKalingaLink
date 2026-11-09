<?php

class notify_unassigned_enrollments
{
    private $lava;

    public function __construct()
    {
        $this->lava = lava_instance();
    }

    public function up()
    {
        $this->lava->db->raw(
            'INSERT INTO notifications
                (title, message, audience, related_type, related_id, related_module, notification_type, created_at)
             SELECT
                \'Enrollment application awaiting teacher assignment\',
                CONCAT(a.application_number, \' requires an active teacher assignment for its CDC.\'),
                \'single\', \'enrollment\', a.id, \'enrollment\', \'Enrollment\', NOW()
             FROM enrollment_applications a
             WHERE a.assigned_teacher_user_id IS NULL
               AND a.status IN (\'pending\', \'under_review\')
               AND EXISTS (
                   SELECT 1 FROM users u WHERE u.role = \'admin\' AND u.is_active = 1
               )
               AND NOT EXISTS (
                   SELECT 1 FROM notifications existing
                   WHERE existing.related_type = \'enrollment\'
                     AND existing.related_id = a.id
                     AND existing.title = \'Enrollment application awaiting teacher assignment\'
               )'
        );
        $this->lava->db->raw(
            'INSERT INTO notification_recipients (notification_id, user_id, created_at)
             SELECT n.id, u.id, NOW()
             FROM notifications n
             JOIN enrollment_applications a ON a.id = n.related_id
             JOIN users u ON u.role = \'admin\' AND u.is_active = 1
             WHERE n.related_type = \'enrollment\'
               AND n.title = \'Enrollment application awaiting teacher assignment\'
               AND a.assigned_teacher_user_id IS NULL
               AND a.status IN (\'pending\', \'under_review\')
               AND NOT EXISTS (
                   SELECT 1 FROM notification_recipients existing
                   WHERE existing.notification_id = n.id AND existing.user_id = u.id
               )'
        );
    }

    public function down()
    {
    }
}
