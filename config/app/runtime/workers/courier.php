<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'daemon-slot.php';
if (!linnms_acquire_daemon_slot('courier', 'LINNMS_COURIER_SLOT')) exit(0);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'paths.php';
require_once project_path('src', 'helpers.php');
require_once project_path('src', 'load-repository.php');

$config = require project_path('config', 'defaults.php');
$db = new LinnmsRepository((array)$config['database']);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'access-check.php';
$daemonMode = filter_var((string)(getenv('LINNMS_COURIER_DAEMON') ?: '0'), FILTER_VALIDATE_BOOL);
if ($daemonMode) set_time_limit(0);

do {
    if (!linnms_worker_access_allowed($db)) {
        if (!$daemonMode) break;
        sleep(5);
        continue;
    }
    try {
        $dispatched = $db->dispatchCourierSnapshot();
    } catch (Throwable $error) {
        error_log('Kurir sinkronisasi gagal: ' . $error->getMessage());
        if (!$daemonMode) break;
        sleep(2);
        continue;
    }
    if (!$dispatched) {
        if (!$daemonMode) break;
        usleep(250000);
    }
} while ($daemonMode);
