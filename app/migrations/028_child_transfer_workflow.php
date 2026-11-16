<?php

class child_transfer_workflow
{
    private $lava;

    public function __construct()
    {
        $this->lava = lava_instance();
    }

    public function up()
    {
        $this->lava->db->raw(
            'CREATE TABLE IF NOT EXISTS child_transfer_requests (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                reference VARCHAR(32) NOT NULL,
                child_id BIGINT UNSIGNED NOT NULL,
                parent_user_id INT UNSIGNED NOT NULL,
                previous_cdc_id INT UNSIGNED NOT NULL,
                destination_cdc_id INT UNSIGNED NOT NULL,
                previous_teacher_user_id INT UNSIGNED NULL,
                receiving_teacher_user_id INT UNSIGNED NOT NULL,
                reason VARCHAR(80) NOT NULL,
                other_reason VARCHAR(500) NULL,
                new_address VARCHAR(255) NULL,
                new_barangay VARCHAR(120) NULL,
                new_municipality VARCHAR(120) NULL,
                requested_date DATE NOT NULL,
                remarks TEXT NULL,
                status ENUM(
                    \'pending\', \'awaiting_receiving_cdc\', \'completed\',
                    \'declined_receiving_cdc\', \'rejected_current_teacher\',
                    \'cancelled_by_parent\'
                ) NOT NULL DEFAULT \'pending\',
                previous_teacher_remarks TEXT NULL,
                receiving_teacher_remarks TEXT NULL,
                source_dismissed_at DATETIME NULL,
                current_teacher_approved_at DATETIME NULL,
                receiving_teacher_accepted_at DATETIME NULL,
                completed_at DATETIME NULL,
                cancelled_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY child_transfer_reference_unique (reference),
                KEY child_transfer_parent_idx (parent_user_id, child_id, created_at),
                KEY child_transfer_source_idx (previous_cdc_id, status, source_dismissed_at),
                KEY child_transfer_destination_idx (destination_cdc_id, status),
                KEY child_transfer_child_status_idx (child_id, status),
                CONSTRAINT child_transfer_child_fk FOREIGN KEY (child_id)
                    REFERENCES children(id) ON DELETE RESTRICT,
                CONSTRAINT child_transfer_parent_fk FOREIGN KEY (parent_user_id)
                    REFERENCES users(id) ON DELETE RESTRICT,
                CONSTRAINT child_transfer_previous_cdc_fk FOREIGN KEY (previous_cdc_id)
                    REFERENCES cdcs(id) ON DELETE RESTRICT,
                CONSTRAINT child_transfer_destination_cdc_fk FOREIGN KEY (destination_cdc_id)
                    REFERENCES cdcs(id) ON DELETE RESTRICT,
                CONSTRAINT child_transfer_previous_teacher_fk FOREIGN KEY (previous_teacher_user_id)
                    REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT child_transfer_receiving_teacher_fk FOREIGN KEY (receiving_teacher_user_id)
                    REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->lava->db->raw(
            'CREATE TABLE IF NOT EXISTS child_transfer_documents (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                transfer_id BIGINT UNSIGNED NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                storage_path VARCHAR(500) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                file_size BIGINT UNSIGNED NOT NULL,
                sha256 CHAR(64) NOT NULL,
                uploaded_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY child_transfer_document_request_idx (transfer_id, id),
                CONSTRAINT child_transfer_document_request_fk FOREIGN KEY (transfer_id)
                    REFERENCES child_transfer_requests(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->lava->db->raw(
            'CREATE TABLE IF NOT EXISTS child_transfer_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                transfer_id BIGINT UNSIGNED NOT NULL,
                actor_user_id INT UNSIGNED NULL,
                event_type VARCHAR(60) NOT NULL,
                remarks TEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY child_transfer_event_history_idx (transfer_id, id),
                CONSTRAINT child_transfer_event_request_fk FOREIGN KEY (transfer_id)
                    REFERENCES child_transfer_requests(id) ON DELETE CASCADE,
                CONSTRAINT child_transfer_event_actor_fk FOREIGN KEY (actor_user_id)
                    REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down()
    {
        $this->lava->db->raw('DROP TABLE IF EXISTS child_transfer_events');
        $this->lava->db->raw('DROP TABLE IF EXISTS child_transfer_documents');
        $this->lava->db->raw('DROP TABLE IF EXISTS child_transfer_requests');
    }
}
