<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class MigrationController extends Controller
{
    public function run()
    {
        if (!defined('IS_CLI') || !IS_CLI) {
            http_response_code(404);
            return;
        }

        $this->call->library('migration');
        $this->migration->migrate();
    }
}