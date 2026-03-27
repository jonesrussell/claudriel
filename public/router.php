<?php

declare(strict_types=1);

/**
 * PHP built-in server router.
 * Serves existing static files as-is; routes everything else through index.php.
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__.$uri;

if ($uri !== '/' && file_exists($file) && ! is_dir($file)) {
    return false;
}

require_once __DIR__.'/index.php';
