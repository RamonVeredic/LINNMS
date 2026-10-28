<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'daemon-slot.php';
if (!linnms_acquire_daemon_slot('sampler', 'LINNMS_SAMPLER_SLOT')) exit(0);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'paths.php';
require_once project_path('src', 'helpers.php');
require_once project_path('src', 'load-repository.php');
require_once project_path('app', 'CredentialCipher.php');
require_once project_path('app', 'OltTerminalBrokerClient.php');
require_once project_path('app', 'OnuTrafficLiveStore.php');
require_once project_path('app', 'OltPonCliTrafficPoller.php');
require_once project_path('backend', 'services', 'TrafficSampleWriteService.php');
require_once project_path('backend', 'services', 'OnuTrafficDriverReader.php');
require_once project_path('backend', 'services', 'TrafficSamplerService.php');

$daemonMode = filter_var((string)(getenv('LINNMS_SAMPLER_DAEMON') ?: '0'), FILTER_VALIDATE_BOOL);
$logPath = shared_log_path('traffic-sampler.log');
$hadError = false;
$output = static function ($stream, string $message) use ($daemonMode, $logPath, &$hadError): void {
    if ($stream !== STDERR || !str_starts_with($message, '[ERROR]')) return;
    $hadError = true;
    $entry = '[' . date('Y-m-d H:i:s P') . '] ' . rtrim($message) . PHP_EOL;
    fwrite(STDERR, $entry);
    if (!$daemonMode) return;
    $directory = dirname($logPath);
    if (!is_dir($directory)) @mkdir($directory, 0770, true);
    $lock = @fopen($logPath . '.lock', 'c+');
    if (is_resource($lock) && flock($lock, LOCK_EX)) {
        $existing = is_file($logPath) ? (string)@file_get_contents($logPath) : '';
        $lines = array_slice(preg_split('/\\R/', rtrim($entry . $existing)) ?: [], 0, 3000);
        @file_put_contents($logPath, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
        flock($lock, LOCK_UN);
        fclose($lock);
    } elseif (is_resource($lock)) {
        fclose($lock);
    }
};
$reportError = static function (string $message) use ($output): void {
    $output(STDERR, '[ERROR] ' . $message);
};

$config = require project_path('config', 'defaults.php');
$db = new LinnmsRepository((array)$config['database']);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'access-check.php';
if (!linnms_worker_access_allowed($db)) exit(0);
linnms_register_php_error_logging($db);

$credentialCipher = new CredentialCipher((string)$config['credential_key']);
$sampler = new TrafficSamplerService(
    $db,
    new OnuTrafficDriverReader($db, $config),
    new OltPonCliTrafficPoller($db, $credentialCipher),
    new OnuTrafficLiveStore(),
    new TrafficSampleWriteService($db)
);

if ($daemonMode) set_time_limit(0);
$onuInterval = max(1, (int)(getenv('LINNMS_ONU_TRAFFIC_SECONDS') ?: 3));
$oltInterval = max(2, (int)(getenv('LINNMS_OLT_CLI_TRAFFIC_SECONDS') ?: 6));
$nextOnuAt = 0;
$nextOltAt = 0;
$nextAccessCheckAt = 0;
$accessAllowed = true;
$loadedSourceFiles = [];
$sourceRoots = array_unique(array_filter([LINNMS_ROOT, realpath(LINNMS_ROOT)]));
$sourceChanged = static function () use (&$loadedSourceFiles, $sourceRoots): bool {
    foreach (get_included_files() as $file) {
        if (!array_filter($sourceRoots, static fn(string $root): bool => str_starts_with($file, $root . DIRECTORY_SEPARATOR))) continue;
        clearstatcache(true, $file);
        $mtime = @filemtime($file);
        $size = @filesize($file);
        if ($mtime === false || $size === false) return isset($loadedSourceFiles[$file]);
        $signature = $mtime . ':' . $size;
        if (isset($loadedSourceFiles[$file]) && $loadedSourceFiles[$file] !== $signature) return true;
        $loadedSourceFiles[$file] = $signature;
    }
    return false;
};
$sourceChanged();
$nextSourceCheckAt = time() + 30;

do {
    if ($daemonMode && time() >= $nextSourceCheckAt) {
        if ($sourceChanged()) {
            break;
        }
        $nextSourceCheckAt = time() + 30;
    }
    if (time() >= $nextAccessCheckAt) {
        $accessAllowed = linnms_worker_access_allowed($db);
        $nextAccessCheckAt = time() + 5;
    }
    if (!$accessAllowed) {
        if (!$daemonMode) break;
        sleep(60);
        continue;
    }

    if (time() >= $nextOnuAt) {
        $nextOnuAt = time() + $onuInterval;
        try {
            $sampler->sampleOnuLive($reportError);
        } catch (Throwable $error) {
            $reportError('ONU live traffic sample failed: ' . $error->getMessage());
        }
    }
    if (time() >= $nextOltAt) {
        $nextOltAt = time() + $oltInterval;
        try {
            $sampler->sampleOltPonCli($reportError);
        } catch (Throwable $error) {
            $reportError('OLT PON CLI traffic sample failed: ' . $error->getMessage());
        }
    }

    if (!$daemonMode) break;
    usleep(500000);
} while ($daemonMode);
if (!$daemonMode && $hadError) exit(1);
