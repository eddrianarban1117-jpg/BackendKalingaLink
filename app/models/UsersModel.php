<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class UsersModel extends Model
{
    protected $table = 'users';

    public function find_for_login($identifier)
    {
        $user = $this->db->table($this->table)
            ->select('id, username, email, password, role, is_active, first_name, middle_name, last_name, phone, profile_photo, last_login_at')
            ->where('email', $identifier)
            ->or_where('username', $identifier)
            ->get();
        return $this->enforce_teacher_approval($user);
    }

    public function find_by_id($id)
    {
        $user = $this->db->table($this->table)
            ->select('id, username, email, role, is_active, first_name, middle_name, last_name, phone, profile_photo')
            ->where('id', $id)
            ->get();
        return $this->enforce_teacher_approval($user);
    }

    public function find_by_email($email)
    {
        $user = $this->db->table($this->table)
            ->select('id, username, email, role, is_active, first_name, middle_name, last_name, phone, profile_photo, last_login_at')
            ->where('email', $email)
            ->get();
        return $this->enforce_teacher_approval($user);
    }

    public function find_by_identity($email, $username)
    {
        return $this->db->table($this->table)
            ->select('id, email, username')
            ->where('email', $email)
            ->or_where('username', $username)
            ->get();
    }

    public function update_last_login($id)
    {
        return $this->db->table($this->table)
            ->where('id', $id)
            ->update(['last_login_at' => date('Y-m-d H:i:s')]);
    }

    private function enforce_teacher_approval($user)
    {
        if (!$user || $user['role'] !== 'teacher') return $user;

        $review = $this->db->raw(
            'SELECT n.title FROM notifications n
             JOIN teacher_profiles tp ON tp.user_id = n.related_id
             WHERE n.related_type = \'teacher_verification\' AND n.related_id = ?
               AND tp.employment_status = \'active\'
             ORDER BY n.id DESC LIMIT 1',
            [(int) $user['id']]
        )->fetch(PDO::FETCH_ASSOC);
        if (($review['title'] ?? null) !== 'Approved') {
            $user['is_active'] = 0;
        }
        return $user;
    }
}