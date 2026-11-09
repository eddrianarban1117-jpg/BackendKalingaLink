<?php

$document_root = realpath(__DIR__);
$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$requested_file = realpath(
    $document_root . DIRECTORY_SEPARATOR .
    ltrim(str_replace('/', DIRECTORY_SEPARATOR, $request_path), DIRECTORY_SEPARATOR)
);

if (
    $requested_file !== false &&
    stripos($requested_file, $document_root . DIRECTORY_SEPARATOR) === 0 &&
    is_file($requested_file)
) {
    return false;
}

require $document_root . DIRECTORY_SEPARATOR . 'index.php';
