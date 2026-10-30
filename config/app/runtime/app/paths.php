<?php

declare(strict_types=1);

if (!defined('LINNMS_ROOT')) {
    define('LINNMS_ROOT', dirname(__DIR__));
}
if (!defined('LINNMS_UI_ROOT')) {
    define('LINNMS_UI_ROOT', '/config/www');
}
if (!defined('LINNMS_VIEW_ROOT')) {
    define('LINNMS_VIEW_ROOT', LINNMS_UI_ROOT . '/view');
}

if (!defined('LINNMS_DATA_ROOT')) {
    define('LINNMS_DATA_ROOT', '/config/app/data');
}

function project_path(string ...$segments): string
{
    $first = isset($segments[0]) ? trim((string)$segments[0], '/\\') : '';
    $path = $first === 'view' ? LINNMS_UI_ROOT : LINNMS_ROOT;
    foreach ($segments as $segment) {
        $clean = trim($segment, '/\\');
        if ($clean !== '') $path .= DIRECTORY_SEPARATOR . $clean;
    }
    return $path;
}

function data_path(string ...$segments): string
{
    $path = LINNMS_DATA_ROOT;
    foreach ($segments as $segment) {
        $clean = trim($segment, '/\\');
        if ($clean !== '') $path .= DIRECTORY_SEPARATOR . $clean;
    }
    return $path;
}

function cache_path(string ...$segments): string
{
    return data_path('cache', ...$segments);
}

function lock_path(string ...$segments): string
{
    return data_path('lock', ...$segments);
}

function shared_log_path(string ...$segments): string
{
    $path = '/config/log/app';
    foreach ($segments as $segment) {
        $clean = trim($segment, '/\\');
        if ($clean !== '') $path .= DIRECTORY_SEPARATOR . $clean;
    }
    return $path;
}

function ensure_data_directory(string $directory, int $mode = 0770): string
{
    if (!is_dir($directory)) @mkdir($directory, $mode, true);
    if (is_dir($directory)) @chmod($directory, $mode);
    return $directory;
}

require_once LINNMS_ROOT . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'driver'
    . DIRECTORY_SEPARATOR . 'olt' . DIRECTORY_SEPARATOR . 'autoload.php';
