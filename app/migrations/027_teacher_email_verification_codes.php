<?php

class teacher_email_verification_codes
{
    private $lava;

    public function __construct()
    {
        $this->lava = lava_instance();
    }

    public function up()
    {
        $this->lava->db->raw(
            'CREATE TABLE IF NOT EXISTS teacher_email_verifications (
                email VARCHAR(255) NOT NULL,
                code_hash VARCHAR(255) NOT NULL,
                challenge_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                verified_at DATETIME NULL,
                consumed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (email),
                KEY teacher_email_verification_expiry_idx (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public function down()
    {
        $this->lava->db->raw('DROP TABLE IF EXISTS teacher_email_verifications');
    }
}
