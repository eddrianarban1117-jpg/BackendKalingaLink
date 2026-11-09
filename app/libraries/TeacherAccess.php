<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class TeacherAccess
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function is_active($user_id)
    {
        $record = $this->db->raw(
            'SELECT 1
             FROM users u
             JOIN teacher_account_verification v ON v.teacher_user_id = u.id
             WHERE u.id = ? AND u.role = \'teacher\'
               AND u.is_active = 1 AND v.status = \'active\'
             LIMIT 1',
            [(int) $user_id]
        )->fetch(PDO::FETCH_ASSOC);

        return (bool) $record;
    }
}
