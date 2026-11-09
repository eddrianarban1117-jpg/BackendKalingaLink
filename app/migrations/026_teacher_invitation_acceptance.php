<?php

class teacher_invitation_acceptance
{
    private $lava;

    public function __construct()
    {
        $this->lava = lava_instance();
    }

    public function up()
    {
        $this->lava->db->raw(
            'CREATE TABLE IF NOT EXISTS teacher_account_verification (
                teacher_user_id INT UNSIGNED NOT NULL,
                status ENUM(
                    \'pending_review\',
                    \'under_review\',
                    \'revision_required\',
                    \'approved_awaiting_acceptance\',
                    \'rejected\',
                    \'active\'
                ) NOT NULL DEFAULT \'pending_review\',
                approved_by INT UNSIGNED NULL,
                approved_at DATETIME NULL,
                rejected_by INT UNSIGNED NULL,
                rejected_at DATETIME NULL,
                rejection_remarks TEXT NULL,
                accepted_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (teacher_user_id),
                KEY teacher_verification_status_idx (status),
                CONSTRAINT teacher_verification_user_fk
                    FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT teacher_verification_approver_fk
                    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT teacher_verification_rejector_fk
                    FOREIGN KEY (rejected_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->lava->db->raw(
            'CREATE TABLE IF NOT EXISTS teacher_account_invitations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                teacher_user_id INT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                email_status ENUM(\'pending\', \'sent\', \'failed\', \'accepted\') NOT NULL DEFAULT \'pending\',
                email_error VARCHAR(500) NULL,
                sent_at DATETIME NULL,
                accepted_at DATETIME NULL,
                invalidated_at DATETIME NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY teacher_invitation_token_hash_unique (token_hash),
                KEY teacher_invitation_current_idx (teacher_user_id, invalidated_at, expires_at),
                CONSTRAINT teacher_invitation_user_fk
                    FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT teacher_invitation_creator_fk
                    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $this->lava->db->raw(
            'INSERT IGNORE INTO teacher_account_verification (
                teacher_user_id, status, approved_by, approved_at, rejected_by,
                rejected_at, rejection_remarks, accepted_at, created_at, updated_at
            )
            SELECT u.id,
                CASE
                    WHEN review.title = \'Rejected\' THEN \'rejected\'
                    WHEN review.title = \'Revision Required\' THEN \'revision_required\'
                    WHEN review.title = \'Under Review\' THEN \'under_review\'
                    WHEN review.title = \'Approved\' AND u.is_active = 1 THEN \'active\'
                    WHEN review.title = \'Approved\' THEN \'approved_awaiting_acceptance\'
                    ELSE \'pending_review\'
                END,
                CASE WHEN review.title = \'Approved\' AND actor.role = \'admin\'
                     THEN review.actor_user_id ELSE NULL END,
                CASE WHEN review.title = \'Approved\' THEN review.created_at ELSE NULL END,
                CASE WHEN review.title = \'Rejected\' AND actor.role = \'admin\'
                     THEN review.actor_user_id ELSE NULL END,
                CASE WHEN review.title = \'Rejected\' THEN review.created_at ELSE NULL END,
                CASE WHEN review.title = \'Rejected\' THEN review.message ELSE NULL END,
                NULL,
                COALESCE(u.created_at, NOW()),
                NOW()
            FROM users u
            LEFT JOIN notifications review ON review.id = (
                SELECT n.id FROM notifications n
                WHERE n.related_type = \'teacher_verification\'
                  AND n.related_id = u.id
                ORDER BY n.id DESC LIMIT 1
            )
            LEFT JOIN users actor ON actor.id = review.actor_user_id
            WHERE u.role = \'teacher\''
        );
        $this->lava->db->raw(
            'UPDATE users u
             JOIN teacher_account_verification v ON v.teacher_user_id = u.id
             SET u.is_active = 0, u.updated_at = NOW()
             WHERE u.role = \'teacher\' AND v.status <> \'active\''
        );
    }

    public function down()
    {
        $this->lava->db->raw('DROP TABLE IF EXISTS teacher_account_invitations');
        $this->lava->db->raw('DROP TABLE IF EXISTS teacher_account_verification');
    }
}
