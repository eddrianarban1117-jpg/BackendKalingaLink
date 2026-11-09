<?php

class create_events_and_shared_albums
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
        if (!$this->forge->table_exists('events')) {
            $this->forge->add_field([
                'id' => [
                    'type' => 'BIGINT',
                    'unsigned' => true,
                    'auto_increment' => true,
                    'null' => false,
                ],
                'title' => [
                    'type' => 'VARCHAR',
                    'constraint' => 180,
                    'null' => false,
                ],
                'description' => [
                    'type' => 'TEXT',
                    'null' => true,
                    'default' => null,
                ],
                'event_date' => [
                    'type' => 'DATE',
                    'null' => false,
                ],
                'start_time' => [
                    'type' => 'TIME',
                    'null' => true,
                    'default' => null,
                ],
                'end_time' => [
                    'type' => 'TIME',
                    'null' => true,
                    'default' => null,
                ],
                'venue' => [
                    'type' => 'VARCHAR',
                    'constraint' => 255,
                    'null' => true,
                    'default' => null,
                ],
                'cdc_id' => [
                    'type' => 'INT',
                    'unsigned' => true,
                    'null' => true,
                    'default' => null,
                ],
                'created_by' => [
                    'type' => 'INT',
                    'unsigned' => true,
                    'null' => false,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => false,
                    'default' => 'CURRENT_TIMESTAMP',
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                    'default' => null,
                ],
            ]);
            $this->forge->add_key('id', true);
            $this->forge->add_key(['cdc_id', 'event_date']);
            $this->forge->add_key(['created_by', 'event_date']);
            $this->forge->add_foreign_key('cdc_id', 'cdcs', 'id', 'SET NULL');
            $this->forge->add_foreign_key('created_by', 'users', 'id', 'RESTRICT');
            $this->forge->create_table('events');
        }

        if (!$this->forge->table_exists('event_albums')) {
            $this->forge->add_field([
                'id' => [
                    'type' => 'BIGINT',
                    'unsigned' => true,
                    'auto_increment' => true,
                    'null' => false,
                ],
                'event_id' => [
                    'type' => 'BIGINT',
                    'unsigned' => true,
                    'null' => false,
                ],
                'album_name' => [
                    'type' => 'VARCHAR',
                    'constraint' => 180,
                    'null' => false,
                ],
                'created_by' => [
                    'type' => 'INT',
                    'unsigned' => true,
                    'null' => false,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => false,
                    'default' => 'CURRENT_TIMESTAMP',
                ],
            ]);
            $this->forge->add_key('id', true);
            $this->forge->add_key('event_id', false, true);
            $this->forge->add_foreign_key('event_id', 'events', 'id', 'CASCADE');
            $this->forge->add_foreign_key('created_by', 'users', 'id', 'RESTRICT');
            $this->forge->create_table('event_albums');
        }

        if (!$this->forge->table_exists('event_photos')) {
            $this->forge->add_field([
                'id' => [
                    'type' => 'BIGINT',
                    'unsigned' => true,
                    'auto_increment' => true,
                    'null' => false,
                ],
                'album_id' => [
                    'type' => 'BIGINT',
                    'unsigned' => true,
                    'null' => false,
                ],
                'uploaded_by' => [
                    'type' => 'INT',
                    'unsigned' => true,
                    'null' => false,
                ],
                'image_path' => [
                    'type' => 'VARCHAR',
                    'constraint' => 255,
                    'null' => false,
                ],
                'mime_type' => [
                    'type' => 'VARCHAR',
                    'constraint' => 80,
                    'null' => false,
                ],
                'file_size' => [
                    'type' => 'INT',
                    'unsigned' => true,
                    'null' => false,
                ],
                'caption' => [
                    'type' => 'VARCHAR',
                    'constraint' => 500,
                    'null' => true,
                    'default' => null,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => false,
                    'default' => 'CURRENT_TIMESTAMP',
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                    'default' => null,
                ],
            ]);
            $this->forge->add_key('id', true);
            $this->forge->add_key(['album_id', 'created_at']);
            $this->forge->add_key(['uploaded_by', 'created_at']);
            $this->forge->add_foreign_key('album_id', 'event_albums', 'id', 'CASCADE');
            $this->forge->add_foreign_key('uploaded_by', 'users', 'id', 'RESTRICT');
            $this->forge->create_table('event_photos');
        }
    }

    public function down()
    {
        $this->forge->drop_table('event_photos', true);
        $this->forge->drop_table('event_albums', true);
        $this->forge->drop_table('events', true);
    }
}
