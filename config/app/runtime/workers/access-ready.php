<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'paths.php';
require_once project_path('src', 'helpers.php');
require_once project_path('src', 'load-repository.php');

try {
    $config = require project_path('config', 'defaults.php');
    $db = new LinnmsRepository((array)$config['database']);
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'access-check.php';
    $role = (string)($argv[1] ?? '');
    if ($role !== '') {
        $slot = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT);
        if ($slot === false || $slot < 1 || $slot > $db->getDaemonCount($role)) exit(3);
    }
    if (!linnms_worker_access_allowed($db)) exit(3);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[worker] Pemeriksaan kesiapan gagal: ' . $error->getMessage() . PHP_EOL);
    exit(4);
}
