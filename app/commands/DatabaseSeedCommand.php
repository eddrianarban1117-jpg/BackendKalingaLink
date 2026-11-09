<?php

class DatabaseSeedCommand
{
    public static $command = 'db:seed';
    public static $description = 'Create development demo accounts and CDC records';
    public static $arguments = [];

    public function handle()
    {
        $project_root = dirname(__DIR__, 2);
        $entry_point = escapeshellarg($project_root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php');
        $php = escapeshellarg(PHP_BINARY);

        passthru($php . ' ' . $entry_point . ' cli/seed', $exit_code);
        exit($exit_code);
    }
}