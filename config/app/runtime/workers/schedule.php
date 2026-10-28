<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'paths.php';
require_once project_path('src', 'helpers.php');
require_once project_path('src', 'load-repository.php');
require_once project_path('backend', 'services', 'SyncOrchestrator.php');

$config = require project_path('config', 'defaults.php');
$db = new LinnmsRepository((array)$config['database']);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'access-check.php';
if (!linnms_worker_access_allowed($db)) exit(0);
linnms_register_php_error_logging($db);
SyncOrchestrator::scheduleDueFor($db);
