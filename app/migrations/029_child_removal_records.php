<?php

class child_removal_records
{
    private $lava;

    public function __construct()
    {
        $this->lava = lava_instance();
    }

    public function up()
    {
        $this->lava->db->raw(
            'CREATE TABLE IF NOT EXISTS child_removal_records (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                child_id BIGINT UNSIGNED NOT NULL,
                parent_user_id INT UNSIGNED NOT NULL,
                actor_user_id INT UNSIGNED NULL,
                outcome ENUM(\'transfer\', \'dropped\', \'deceased\', \'other\') NOT NULL,
                reason_details VARCHAR(500) NULL,
                parent_confirmation ENUM(\'yes\', \'no\') NOT NULL,
                notes TEXT NULL,
                previous_class_id INT UNSIGNED NULL,
                previous_cdc_id INT UNSIGNED NULL,
                previous_teacher_user_id INT UNSIGNED NULL,
                destination_class_id INT UNSIGNED NULL,
                destination_cdc_id INT UNSIGNED NULL,
                destination_teacher_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY child_removal_child_idx (child_id, created_at),
                KEY child_removal_outcome_idx (outcome, created_at),
                CONSTRAINT child_removal_child_fk FOREIGN KEY (child_id)
                    REFERENCES children(id) ON DELETE RESTRICT,
                CONSTRAINT child_removal_parent_fk FOREIGN KEY (parent_user_id)
                    REFERENCES users(id) ON DELETE RESTRICT,
                CONSTRAINT child_removal_actor_fk FOREIGN KEY (actor_user_id)
                    REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT child_removal_previous_class_fk FOREIGN KEY (previous_class_id)
                    REFERENCES classes(id) ON DELETE SET NULL,
                CONSTRAINT child_removal_previous_cdc_fk FOREIGN KEY (previous_cdc_id)
                    REFERENCES cdcs(id) ON DELETE SET NULL,
                CONSTRAINT child_removal_previous_teacher_fk FOREIGN KEY (previous_teacher_user_id)
                    REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT child_removal_destination_class_fk FOREIGN KEY (destination_class_id)
                    REFERENCES classes(id) ON DELETE SET NULL,
                CONSTRAINT child_removal_destination_cdc_fk FOREIGN KEY (destination_cdc_id)
                    REFERENCES cdcs(id) ON DELETE SET NULL,
                CONSTRAINT child_removal_destination_teacher_fk FOREIGN KEY (destination_teacher_user_id)
                    REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down()
    {
        $this->lava->db->raw('DROP TABLE IF EXISTS child_removal_records');
    }
}
