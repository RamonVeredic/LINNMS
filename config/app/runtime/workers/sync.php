<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . DIRECTORY_SEPARATOR . 'schedule.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'worker.php';
