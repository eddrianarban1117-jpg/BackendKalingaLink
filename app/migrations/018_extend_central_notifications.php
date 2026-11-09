<?php

class extend_central_notifications
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
        $columns = [
            'notification_type' => [
                'type' => 'VARCHAR',
                'constraint' => 80,
                'null' => false,
                'default' => 'System Update',
            ],
            'related_module' => [
                'type' => 'VARCHAR',
                'constraint' => 80,
                'null' => true,
                'default' => null,
            ],
            'action_url' => [
                'type' => 'VARCHAR',
                'constraint' => 500,
                'null' => true,
                'default' => null,
            ],
            'recipient_criteria' => [
                'type' => 'LONGTEXT',
                'null' => true,
                'default' => null,
            ],
        ];

        foreach ($columns as $column => $definition) {
            if (!$this->forge->column_exists('notifications', $column)) {
                $this->forge->add_column('notifications', [$column => $definition]);
            }
        }

        if (!$this->forge->table_exists('notification_requests')) {
            $this->forge->add_field([
                'actor_user_id' => [
                    'type' => 'INT',
                    'unsigned' => true,
                    'null' => false,
                ],
                'request_key' => [
                    'type' => 'VARCHAR',
                    'constraint' => 64,
                    'null' => false,
                ],
                'notification_id' => [
                    'type' => 'BIGINT',
                    'unsigned' => true,
                    'null' => false,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => false,
                    'default' => 'CURRENT_TIMESTAMP',
                ],
            ]);
            $this->forge->add_key(['actor_user_id', 'request_key'], true);
            $this->forge->add_key('notification_id', false, true);
            $this->forge->add_foreign_key('actor_user_id', 'users', 'id', 'CASCADE');
            $this->forge->add_foreign_key('notification_id', 'notifications', 'id', 'CASCADE');
            $this->forge->create_table('notification_requests');
        }
    }

    public function down()
    {
    }
}
