<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'paths.php';
require_once project_path('src', 'helpers.php');
require_once project_path('src', 'load-repository.php');
require_once project_path('app', 'CredentialCipher.php');
require_once project_path('app', 'SnmpOltService.php');

$onuRows = json_decode((string)stream_get_contents(STDIN), true);
$userId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
$rowId = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT);
if (!$userId || !$rowId) exit(1);

try {
    $config = require project_path('config', 'defaults.php');
    $repository = new LinnmsRepository((array)$config['database']);
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'access-check.php';
    if (!linnms_worker_access_allowed($repository)) exit(1);
    $bridge = linnms_worker_bridge($repository);
    $service = new SnmpOltService(
        $repository,
        $config,
        new CredentialCipher((string)$config['credential_key']),
        new OltSnmpDriverFactory()
    );
    $result = $service->runParallelCliLow((int)$userId, (int)$rowId, is_array($onuRows) ? $onuRows : []);
    echo json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    exit(!empty($result['ok']) ? 0 : 1);
} catch (Throwable $error) {
    error_log('Low Sync CLI worker failed: ' . $error->getMessage());
    exit(1);
}
