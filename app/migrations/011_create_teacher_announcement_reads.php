<?php

class create_teacher_announcement_reads
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
        if ($this->forge->table_exists('teacher_announcement_reads')) return;

        $this->forge->add_field([
            'announcement_id' => [
                'type' => 'BIGINT',
                'unsigned' => true,
                'null' => false,
            ],
            'teacher_user_id' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => false,
            ],
            'read_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'default' => null,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
                'default' => 'CURRENT_TIMESTAMP',
            ],
        ]);
        $this->forge->add_key(['announcement_id', 'teacher_user_id'], true);
        $this->forge->add_key(['teacher_user_id', 'read_at']);
        $this->forge->add_foreign_key('announcement_id', 'announcements', 'id', 'CASCADE');
        $this->forge->add_foreign_key('teacher_user_id', 'users', 'id', 'CASCADE');
        $this->forge->create_table('teacher_announcement_reads');
    }

    public function down()
    {
        $this->forge->drop_table('teacher_announcement_reads', true);
    }
}
