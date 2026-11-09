<?php

class preserve_events_after_teacher_deletion
{
    private $lava;

    public function __construct()
    {
        $this->lava = lava_instance();
    }

    public function up()
    {
        $owners = [
            'events' => 'created_by',
            'event_albums' => 'created_by',
            'event_photos' => 'uploaded_by',
        ];

        foreach ($owners as $table => $column) {
            $table_exists = $this->lava->db->raw(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?',
                [$table]
            )->fetchColumn();
            if (!$table_exists) {
                throw new RuntimeException('Required event ownership table is missing: ' . $table);
            }

            $column_info = $this->lava->db->raw(
                'SELECT is_nullable FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $column]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$column_info) {
                throw new RuntimeException('Required event ownership column is missing: ' . $table . '.' . $column);
            }
            $column_info = array_change_key_case($column_info, CASE_LOWER);

            $foreign_key = $this->lava->db->raw(
                'SELECT kcu.constraint_name, rc.delete_rule
                 FROM information_schema.key_column_usage kcu
                 JOIN information_schema.referential_constraints rc
                   ON rc.constraint_schema = kcu.constraint_schema
                  AND rc.constraint_name = kcu.constraint_name
                  AND rc.table_name = kcu.table_name
                 WHERE kcu.constraint_schema = DATABASE()
                   AND kcu.table_name = ? AND kcu.column_name = ?
                   AND kcu.referenced_table_name = \'users\'
                   AND kcu.referenced_column_name = \'id\'
                 LIMIT 1',
                [$table, $column]
            )->fetch(PDO::FETCH_ASSOC);
            if ($foreign_key) {
                $foreign_key = array_change_key_case($foreign_key, CASE_LOWER);
            }
            if ($column_info['is_nullable'] !== 'YES') {
                $this->lava->db->raw(
                    'ALTER TABLE `' . $table . '` MODIFY `' . $column . '` INT UNSIGNED NULL DEFAULT NULL'
                );
            }

            if ($foreign_key && strtoupper($foreign_key['delete_rule']) !== 'SET NULL') {
                $constraint_name = str_replace('`', '``', $foreign_key['constraint_name']);
                $this->lava->db->raw(
                    'ALTER TABLE `' . $table . '` DROP FOREIGN KEY `' . $constraint_name . '`'
                );
                $foreign_key = null;
            }

            if (!$foreign_key) {
                $constraint_name = $table . '_' . $column . '_user_set_null_fk';
                $this->lava->db->raw(
                    'ALTER TABLE `' . $table . '` ADD CONSTRAINT `' . $constraint_name . '`
                     FOREIGN KEY (`' . $column . '`) REFERENCES `users` (`id`) ON DELETE SET NULL'
                );
            }
        }
    }

    public function down()
    {
        $owners = [
            'events' => 'created_by',
            'event_albums' => 'created_by',
            'event_photos' => 'uploaded_by',
        ];

        foreach ($owners as $table => $column) {
            $null_count = $this->lava->db->raw(
                'SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $column . '` IS NULL'
            )->fetchColumn();
            if ((int) $null_count > 0) {
                throw new RuntimeException(
                    'Cannot restore required event ownership after teacher accounts have been deleted.'
                );
            }
        }

        foreach ($owners as $table => $column) {
            $foreign_key = $this->lava->db->raw(
                'SELECT kcu.constraint_name
                 FROM information_schema.key_column_usage kcu
                 WHERE kcu.constraint_schema = DATABASE()
                   AND kcu.table_name = ? AND kcu.column_name = ?
                   AND kcu.referenced_table_name = \'users\'
                   AND kcu.referenced_column_name = \'id\'
                 LIMIT 1',
                [$table, $column]
            )->fetch(PDO::FETCH_ASSOC);
            if ($foreign_key) {
                $foreign_key = array_change_key_case($foreign_key, CASE_LOWER);
                $constraint_name = str_replace('`', '``', $foreign_key['constraint_name']);
                $this->lava->db->raw(
                    'ALTER TABLE `' . $table . '` DROP FOREIGN KEY `' . $constraint_name . '`'
                );
            }
            $this->lava->db->raw(
                'ALTER TABLE `' . $table . '` MODIFY `' . $column . '` INT UNSIGNED NOT NULL'
            );
            $this->lava->db->raw(
                'ALTER TABLE `' . $table . '` ADD CONSTRAINT `' . $table . '_' . $column . '_user_restrict_fk`
                 FOREIGN KEY (`' . $column . '`) REFERENCES `users` (`id`) ON DELETE RESTRICT'
            );
        }
    }
}
