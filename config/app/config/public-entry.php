<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
header_remove('X-Powered-By');

define('LINNMS_SESSION_NAME', 'linnms_v1');

$requestedEntry = strtolower(pathinfo((string)($_SERVER['SCRIPT_FILENAME'] ?? ''), PATHINFO_FILENAME));
$applicationRoot = '/opt/linnms/runtime';
$expiredDeadlineFile = '/config/app/data/core/expired-delete-at';
if (!is_dir($applicationRoot) || (is_file($expiredDeadlineFile) && !is_dir($applicationRoot . '/vendor/driver'))) {
    $scriptPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $expiredBase = '';
    $publicMarker = strpos($scriptPath, '/public/');
    if ($publicMarker !== false) {
        $expiredBase = rtrim(substr($scriptPath, 0, $publicMarker), '/');
    } elseif (preg_match('~^(.+)/(?:index|live)\.php$~i', $scriptPath, $baseMatch) === 1) {
        $expiredBase = rtrim((string)$baseMatch[1], '/');
    }
    $expiredPage = $expiredBase . '/expired.html';
    $contactNumber = trim((string)@file_get_contents('/config/app/data/core/expired-whatsapp'));
    if (preg_match('/^[0-9]{8,16}$/', $contactNumber) === 1) $expiredPage .= '?wa=' . $contactNumber;
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Location: ' . $expiredPage, true, 303);
    exit;
}

if ($requestedEntry === 'live') {
    require $applicationRoot . DIRECTORY_SEPARATOR . 'live.php';
    exit;
}

if ($requestedEntry === 'data') {
    require $applicationRoot . DIRECTORY_SEPARATOR . 'data.php';
    exit;
}

if ($requestedEntry === 'onu-list') {
    require $applicationRoot . DIRECTORY_SEPARATOR . 'onu-list.php';
    exit;
}

if (in_array($requestedEntry, ['connection-test', 'terminal'], true)) {
    require $applicationRoot . DIRECTORY_SEPARATOR . $requestedEntry . '.php';
    exit;
}

require_once $applicationRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'paths.php';
require project_path('app', 'runtime.php');
