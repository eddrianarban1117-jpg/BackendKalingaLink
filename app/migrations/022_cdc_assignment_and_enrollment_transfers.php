<?php

class cdc_assignment_and_enrollment_transfers
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
        if (!$this->forge->column_exists('teacher_profiles', 'requested_cdc_id')) {
            $this->forge->add_column('teacher_profiles', [
                'requested_cdc_id' => [
                    'type' => 'INT',
                    'unsigned' => true,
                    'null' => true,
                    'default' => null,
                ],
            ]);
            $this->lava->db->raw(
                'ALTER TABLE teacher_profiles
                 ADD INDEX teacher_requested_cdc_idx (requested_cdc_id),
                 ADD CONSTRAINT teacher_requested_cdc_fk
                 FOREIGN KEY (requested_cdc_id) REFERENCES cdcs(id) ON DELETE SET NULL'
            );
        }

        $this->lava->db->raw(
            'ALTER TABLE teacher_profiles
             MODIFY cdc_id INT UNSIGNED NULL DEFAULT NULL'
        );

        $this->lava->db->raw(
            'UPDATE teacher_profiles tp
             LEFT JOIN users u ON u.id = tp.user_id
             LEFT JOIN notifications review ON review.id = (
                 SELECT latest.id FROM notifications latest
                 WHERE latest.related_type = \'teacher_verification\'
                   AND latest.related_id = tp.user_id
                 ORDER BY latest.id DESC LIMIT 1
             )
             SET tp.requested_cdc_id = COALESCE(tp.requested_cdc_id, tp.cdc_id),
                 tp.cdc_id = NULL
             WHERE tp.cdc_id IS NOT NULL
               AND (u.is_active <> 1 OR u.role <> \'teacher\'
                    OR tp.employment_status <> \'active\'
                    OR COALESCE(review.title, \'\') <> \'Approved\')'
        );

        $this->lava->db->raw(
            'UPDATE teacher_profiles tp
             JOIN (
                 SELECT cdc_id FROM teacher_profiles
                 WHERE cdc_id IS NOT NULL
                 GROUP BY cdc_id HAVING COUNT(*) > 1
             ) duplicates ON duplicates.cdc_id = tp.cdc_id
             SET tp.requested_cdc_id = COALESCE(tp.requested_cdc_id, tp.cdc_id),
                 tp.cdc_id = NULL'
        );

        $this->lava->db->raw(
            'UPDATE users u
             JOIN teacher_profiles tp ON tp.user_id = u.id
             LEFT JOIN notifications review ON review.id = (
                 SELECT latest.id FROM notifications latest
                 WHERE latest.related_type = \'teacher_verification\'
                   AND latest.related_id = u.id
                 ORDER BY latest.id DESC LIMIT 1
             )
             SET u.is_active = 0, u.updated_at = NOW()
             WHERE u.role = \'teacher\'
               AND (tp.employment_status <> \'active\'
                    OR COALESCE(review.title, \'\') <> \'Approved\')'
        );

        $assignment_index = $this->lava->db->raw(
            'SELECT index_name FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = \'teacher_profiles\'
               AND index_name = ? LIMIT 1',
            ['teacher_cdc_assignment_unique']
        )->fetch(PDO::FETCH_ASSOC);
        if (!$assignment_index) {
            $this->lava->db->raw(
                'ALTER TABLE teacher_profiles
                 ADD UNIQUE INDEX teacher_cdc_assignment_unique (cdc_id)'
            );
        }

        $this->lava->db->raw(
            'UPDATE enrollment_applications a
             LEFT JOIN users assigned ON assigned.id = a.assigned_teacher_user_id
             LEFT JOIN teacher_profiles tp ON tp.user_id = assigned.id
             LEFT JOIN notifications review ON review.id = (
                 SELECT latest.id FROM notifications latest
                 WHERE latest.related_type = \'teacher_verification\'
                   AND latest.related_id = assigned.id
                 ORDER BY latest.id DESC LIMIT 1
             )
             SET a.assigned_teacher_user_id = NULL
             WHERE a.assigned_teacher_user_id IS NOT NULL
               AND (assigned.role <> \'teacher\' OR assigned.is_active <> 1
                    OR tp.cdc_id <> a.cdc_id OR tp.employment_status <> \'active\'
                    OR COALESCE(review.title, \'\') <> \'Approved\')'
        );
        $this->lava->db->raw(
            'UPDATE enrollment_applications a
             JOIN teacher_profiles tp ON tp.cdc_id = a.cdc_id
             JOIN users assigned ON assigned.id = tp.user_id
             JOIN notifications review ON review.id = (
                 SELECT latest.id FROM notifications latest
                 WHERE latest.related_type = \'teacher_verification\'
                   AND latest.related_id = assigned.id
                 ORDER BY latest.id DESC LIMIT 1
             )
             SET a.assigned_teacher_user_id = assigned.id
             WHERE a.assigned_teacher_user_id IS NULL
               AND assigned.role = \'teacher\' AND assigned.is_active = 1
               AND tp.employment_status = \'active\' AND review.title = \'Approved\''
        );

        $this->lava->db->raw(
            'ALTER TABLE cdcs
             MODIFY status ENUM(\'pending_activation\', \'active\', \'inactive\')
             NOT NULL DEFAULT \'pending_activation\''
        );
        $this->lava->db->raw(
            'UPDATE cdcs d
             SET d.status = \'pending_activation\'
             WHERE d.status = \'active\'
               AND NOT EXISTS (
                   SELECT 1
                   FROM teacher_profiles tp
                   JOIN users u ON u.id = tp.user_id
                   JOIN notifications review ON review.id = (
                       SELECT latest.id FROM notifications latest
                       WHERE latest.related_type = \'teacher_verification\'
                         AND latest.related_id = tp.user_id
                       ORDER BY latest.id DESC LIMIT 1
                   )
                   WHERE tp.cdc_id = d.id AND tp.employment_status = \'active\'
                     AND u.role = \'teacher\' AND u.is_active = 1
                     AND review.title = \'Approved\'
               )'
        );
        $this->lava->db->raw(
            'CREATE TABLE IF NOT EXISTS cdc_enrollment_transfers (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                application_id BIGINT UNSIGNED NOT NULL,
                child_id BIGINT UNSIGNED NOT NULL,
                previous_cdc_id INT UNSIGNED NOT NULL,
                destination_cdc_id INT UNSIGNED NOT NULL,
                previous_teacher_user_id INT UNSIGNED NULL DEFAULT NULL,
                new_teacher_user_id INT UNSIGNED NOT NULL,
                admin_user_id INT UNSIGNED NOT NULL,
                reason TEXT NOT NULL,
                transferred_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY cdc_transfer_application_idx (application_id),
                KEY cdc_transfer_child_idx (child_id),
                KEY cdc_transfer_previous_cdc_idx (previous_cdc_id),
                KEY cdc_transfer_destination_cdc_idx (destination_cdc_id),
                CONSTRAINT cdc_transfer_application_fk FOREIGN KEY (application_id)
                    REFERENCES enrollment_applications(id) ON DELETE RESTRICT,
                CONSTRAINT cdc_transfer_child_fk FOREIGN KEY (child_id)
                    REFERENCES children(id) ON DELETE RESTRICT,
                CONSTRAINT cdc_transfer_previous_cdc_fk FOREIGN KEY (previous_cdc_id)
                    REFERENCES cdcs(id) ON DELETE RESTRICT,
                CONSTRAINT cdc_transfer_destination_cdc_fk FOREIGN KEY (destination_cdc_id)
                    REFERENCES cdcs(id) ON DELETE RESTRICT,
                CONSTRAINT cdc_transfer_previous_teacher_fk FOREIGN KEY (previous_teacher_user_id)
                    REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT cdc_transfer_new_teacher_fk FOREIGN KEY (new_teacher_user_id)
                    REFERENCES users(id) ON DELETE RESTRICT,
                CONSTRAINT cdc_transfer_admin_fk FOREIGN KEY (admin_user_id)
                    REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->lava->db->raw(
            'CREATE TABLE IF NOT EXISTS teacher_verification_documents (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                teacher_user_id INT UNSIGNED NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                storage_path VARCHAR(500) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                file_size BIGINT UNSIGNED NOT NULL,
                sha256 CHAR(64) NOT NULL,
                uploaded_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY teacher_verification_document_user_idx (teacher_user_id, uploaded_at),
                CONSTRAINT teacher_verification_document_user_fk FOREIGN KEY (teacher_user_id)
                    REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down()
    {
    }
}
