<?php

declare(strict_types=1);

define('LINNMS_UI_ROOT', dirname(__DIR__));
define('LINNMS_VIEW_ROOT', LINNMS_UI_ROOT . '/view');

function project_path(string ...$segments): string
{
    if (($segments[0] ?? '') !== 'view') {
        throw new InvalidArgumentException('Path tampilan tidak valid.');
    }
    $path = LINNMS_UI_ROOT;
    foreach ($segments as $segment) {
        $clean = trim($segment, '/\\');
        if ($clean !== '') $path .= DIRECTORY_SEPARATOR . $clean;
    }
    return $path;
}
