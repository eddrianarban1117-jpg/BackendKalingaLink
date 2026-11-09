<?php

class sync_cdc_status_with_approved_teachers
{
    private $lava;

    public function __construct()
    {
        $this->lava = lava_instance();
        $this->lava->call->library('CdcStatus', $this->lava->db);
    }

    public function up()
    {
        $allowed_names = CdcStatus::REQUIRED_CDC_NAMES;
        $placeholders = implode(', ', array_fill(0, count($allowed_names), '?'));
        $unexpected = $this->lava->db->raw(
            'SELECT name FROM cdcs
             WHERE BINARY TRIM(name) NOT IN (' . $placeholders . ')
             ORDER BY name',
            $allowed_names
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($unexpected) {
            throw new RuntimeException(
                'CDC status migration stopped because unsupported CDC records exist: '
                . implode(', ', $unexpected)
                . '. Resolve their applications and records before migrating; no CDC data was deleted.'
            );
        }

        $check = $this->lava->db->raw(
            'SELECT constraint_name FROM information_schema.check_constraints
             WHERE constraint_schema = DATABASE()
               AND constraint_name = \'cdcs_required_name_chk\' LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!$check) {
            $this->lava->db->raw(
                'ALTER TABLE cdcs
                 ADD CONSTRAINT cdcs_required_name_chk
                 CHECK (BINARY name IN (
                     \'Cacawan Centro Pamana CDC\',
                     \'Marfrancisco CDC\',
                     \'Papandayan Highway CDC\'
                 ))'
            );
        }

        $this->lava->db->raw(
            'UPDATE cdcs d
             SET d.status = CASE WHEN EXISTS (
                 SELECT 1
                 FROM teacher_profiles tp
                 JOIN users u ON u.id = tp.user_id
                 JOIN notifications review ON review.id = (
                     SELECT latest.id FROM notifications latest
                     WHERE latest.related_type = \'teacher_verification\'
                       AND latest.related_id = u.id
                     ORDER BY latest.id DESC LIMIT 1
                 )
                 WHERE tp.cdc_id = d.id
                   AND tp.employment_status = \'active\'
                   AND u.role = \'teacher\'
                   AND u.is_active = 1
                   AND review.title = \'Approved\'
             ) THEN \'active\' ELSE \'inactive\' END'
        );
        $this->lava->db->raw(
            'ALTER TABLE cdcs
             MODIFY status ENUM(\'active\', \'inactive\')
             NOT NULL DEFAULT \'inactive\''
        );
    }

    public function down()
    {
        $check = $this->lava->db->raw(
            'SELECT constraint_name FROM information_schema.check_constraints
             WHERE constraint_schema = DATABASE()
               AND constraint_name = \'cdcs_required_name_chk\' LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if ($check) {
            $this->lava->db->raw(
                'ALTER TABLE cdcs DROP CHECK cdcs_required_name_chk'
            );
        }
        $this->lava->db->raw(
            'ALTER TABLE cdcs
             MODIFY status ENUM(\'pending_activation\', \'active\', \'inactive\')
             NOT NULL DEFAULT \'pending_activation\''
        );
    }
}
