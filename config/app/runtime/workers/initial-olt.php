<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'daemon-slot.php';
if (!linnms_acquire_daemon_slot('initial-reader', 'LINNMS_INITIAL_SLOT')) exit(0);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'paths.php';
require_once project_path('src', 'helpers.php');
require_once project_path('src', 'load-repository.php');
require_once project_path('app', 'CredentialCipher.php');
require_once project_path('app', 'SnmpOltService.php');
require_once project_path('backend', 'services', 'OltSyncOrchestrator.php');
require_once __DIR__ . DIRECTORY_SEPARATOR . 'access-check.php';

$config = require project_path('config', 'defaults.php');
$db = new LinnmsRepository((array)$config['database']);
if (!linnms_worker_access_allowed($db)) exit(0);

$token = substr((gethostname() ?: 'linnms') . '-initial-' . getmypid() . '-' . bin2hex(random_bytes(8)), 0, 64);
$service = new SnmpOltService($db, $config, new CredentialCipher((string)$config['credential_key']), new OltSnmpDriverFactory());
(new OltSyncOrchestrator($db, $service))->processInitialScan($token);
