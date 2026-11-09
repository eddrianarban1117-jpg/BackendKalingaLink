<?php

class persist_complete_account_details
{
    private $forge;

    public function __construct()
    {
        $lava = lava_instance();
        $lava->call->dbforge();
        $this->forge = $lava->dbforge;
    }

    public function up()
    {
        if (!$this->forge->column_exists('users', 'middle_name')) {
            $this->forge->add_column('users', [
                'middle_name' => [
                    'type' => 'VARCHAR',
                    'constraint' => 100,
                    'null' => true,
                    'default' => null,
                ],
            ]);
        }

        if (!$this->forge->column_exists('parent_profiles', 'street')) {
            $this->forge->add_column('parent_profiles', [
                'street' => [
                    'type' => 'VARCHAR',
                    'constraint' => 160,
                    'null' => true,
                    'default' => null,
                ],
            ]);
        }

        if (!$this->forge->table_exists('user_settings')) {
            $this->forge->add_field([
                'id' => [
                    'type' => 'INT',
                    'unsigned' => true,
                    'auto_increment' => true,
                    'null' => false,
                ],
                'user_id' => [
                    'type' => 'INT',
                    'unsigned' => true,
                    'null' => false,
                ],
                'setting_key' => [
                    'type' => 'VARCHAR',
                    'constraint' => 100,
                    'null' => false,
                ],
                'setting_value' => [
                    'type' => 'LONGTEXT',
                    'null' => false,
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => false,
                    'default' => 'CURRENT_TIMESTAMP',
                ],
            ]);
            $this->forge->add_key('id', true);
            $this->forge->add_key(['user_id', 'setting_key'], false, true);
            $this->forge->add_foreign_key('user_id', 'users', 'id', 'CASCADE');
            $this->forge->create_table('user_settings');
        }
    }

    public function down()
    {
    }
}
