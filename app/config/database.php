<?php 
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed'); 
 
$db_host = getenv('DB_HOST') ?: '';
$db_user = getenv('DB_USER') ?: '';
$db_name = getenv('DB_NAME') ?: '';
if ($db_host === '' || $db_user === '' || $db_name === '') {
    throw new RuntimeException('DB_HOST, DB_USER, and DB_NAME must be configured in the environment.');
}

$database['main'] = array(
    'driver'    => 'mysql',
    'hostname'  => $db_host,
    'port'      => getenv('DB_PORT') ?: '',
    'username'  => $db_user,
    'password'  => getenv('DB_PASSWORD') ?: '',
    'database'  => $db_name,
    'charset'   => 'utf8mb4',
    'dbprefix'  => '',
    'ssl_ca'    => getenv('DB_SSL_CA') ?: '',
    'path'      => '' 
); 
 
?>