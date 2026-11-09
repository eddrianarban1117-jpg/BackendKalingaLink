<?php

class DatabaseMigrateCommand
{
    public static $command = 'db:migrate';
    public static $description = 'Run pending application database migrations';
    public static $arguments = [];

    public function handle()
    {
        $project_root = dirname(__DIR__, 2);
        $entry_point = escapeshellarg($project_root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php');
        $php = escapeshellarg(PHP_BINARY);

        passthru($php . ' ' . $entry_point . ' cli/migrate', $exit_code);
        exit($exit_code);
    }
}