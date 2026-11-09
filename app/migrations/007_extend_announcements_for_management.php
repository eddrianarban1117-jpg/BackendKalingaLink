<?php

class extend_announcements_for_management
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
        $columns = [
            'category' => [
                'type' => 'VARCHAR',
                'constraint' => 40,
                'null' => false,
                'default' => 'General',
            ],
            'status' => [
                'type' => 'ENUM',
                'constraint' => "'draft','published','scheduled'",
                'null' => false,
                'default' => 'published',
            ],
            'attachment_path' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
                'default' => null,
            ],
            'attachment_name' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
                'default' => null,
            ],
            'attachment_mime' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
                'default' => null,
            ],
            'attachment_size' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => true,
                'default' => null,
            ],
        ];

        foreach ($columns as $column => $definition) {
            if (!$this->forge->column_exists('announcements', $column)) {
                $this->forge->add_column('announcements', [$column => $definition]);
            }
        }
    }

    public function down()
    {
        foreach ([
            'category',
            'status',
            'attachment_path',
            'attachment_name',
            'attachment_mime',
            'attachment_size',
        ] as $column) {
            if ($this->forge->column_exists('announcements', $column)) {
                $this->forge->drop_column('announcements', $column);
            }
        }
    }
}
