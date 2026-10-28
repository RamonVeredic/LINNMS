<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'daemon-slot.php';
if (!linnms_acquire_daemon_slot('writer', 'LINNMS_WRITER_SLOT')) exit(0);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'paths.php';
require_once project_path('src', 'helpers.php');
require_once project_path('src', 'load-repository.php');
require_once project_path('backend', 'services', 'SyncPipelineWriteService.php');

$daemonMode = filter_var((string)(getenv('LINNMS_WRITER_DAEMON') ?: '0'), FILTER_VALIDATE_BOOL);
$writerLogPath = shared_log_path('sync-writer.log');
$hadError = false;
$writerOutput = static function ($stream, string $message) use ($daemonMode, $writerLogPath, &$hadError): void {
    if ($stream !== STDERR || !str_starts_with($message, '[ERROR]')) return;
    $hadError = true;
    $entry = '[' . date('Y-m-d H:i:s P') . '] ' . rtrim($message) . PHP_EOL;
    fwrite(STDERR, $entry);
    if (!$daemonMode) return;

    $directory = dirname($writerLogPath);
    if (!is_dir($directory)) @mkdir($directory, 0770, true);
    $lockDirectory = lock_path();
    if (!is_dir($lockDirectory)) @mkdir($lockDirectory, 0770, true);
    $lock = @fopen($lockDirectory . DIRECTORY_SEPARATOR . basename($writerLogPath) . '.lock', 'c+');

    if (is_resource($lock) && flock($lock, LOCK_EX)) {
        $temporary = null;
        try {
            $existing = is_file($writerLogPath) ? (string)@file_get_contents($writerLogPath) : '';
            $combined = $entry . $existing;
            $lines = preg_split('/\\R/', rtrim($combined)) ?: [];
            $lines = array_slice($lines, 0, 3000);
            $temporary = tempnam($directory, '.worker-log.');
            if ($temporary !== false) {
                file_put_contents($temporary, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
                @chmod($temporary, 0660);
                @rename($temporary, $writerLogPath);
                $temporary = null;
            }
        } finally {
            if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    } elseif (is_resource($lock)) {
        fclose($lock);
    }
};

$config = require project_path('config', 'defaults.php');
$db = new LinnmsRepository((array)$config['database']);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'access-check.php';
if (!linnms_worker_access_allowed($db)) exit(0);
linnms_register_php_error_logging($db);

$pipelineWriter = new SyncPipelineWriteService($db);

if ($daemonMode) set_time_limit(0);
$workerToken = substr((gethostname() ?: 'linnms') . '-writer-' . getmypid() . '-' . bin2hex(random_bytes(8)), 0, 64);

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
$slot = (int)(getenv('LINNMS_WRITER_SLOT') ?: 0);
$nextSlotCheckAt = 0;

do {
    if ($daemonMode && $slot > 0 && time() >= $nextSlotCheckAt) {
        try {
            if ($slot > $db->getDaemonCount('writer')) break;
        } catch (Throwable $slotError) {
            $writerOutput(STDERR, '[ERROR] Pemeriksaan jumlah writer gagal: ' . $slotError->getMessage());
            sleep(5);
            continue;
        }
        $nextSlotCheckAt = time() + 5;
    }
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

    try {
        $row = $db->claimPipelineSnapshot($workerToken);
    } catch (Throwable $claimError) {
        $writerOutput(STDERR, '[ERROR] Klaim snapshot gagal: ' . $claimError->getMessage());
        if (!$daemonMode) break;
        sleep(3);
        continue;
    }
    if (!is_array($row)) {
        if (!$daemonMode) {
            break;
        }
        usleep(2_000_000);
        continue;
    }

    $id = (int)$row['id'];
    $userId = (int)$row['user_id'];
    $rowId = (int)$row['olt_row_id'];
    $handler = (string)$row['handler'];
    $payload = json_decode((string)$row['payload'], true);

    if (!is_array($payload)) {
        $writerOutput(STDERR, '[ERROR] job=' . $id . ': Payload snapshot tidak valid.');
        $db->finishPipelineSnapshot($id, false, 'Payload snapshot tidak valid.', false);
        continue;
    }

    try {
        $result = $pipelineWriter->write($userId, $rowId, $handler, $payload);
        if (!empty($result['ok'])) {
            $db->finishPipelineSnapshot($id, true);
        } else {
            $errorMessage = (string)($result['message'] ?? 'Penulisan pipeline gagal.');
            $retryable = array_key_exists('retryable', $result)
                ? (bool)$result['retryable']
                : $errorMessage !== 'OLT tidak ditemukan.';
            $name = trim((string)($payload['_name'] ?? ''));
            $writerOutput(STDERR, '[ERROR] dev="' . $name . '" user_id=' . $userId . ' job=' . $id . ': ' . $errorMessage);
            $db->finishPipelineSnapshot($id, false, $errorMessage, $retryable);
        }
    } catch (Throwable $e) {
        $name = is_array($payload) ? trim((string)($payload['_name'] ?? '')) : '';
        $writerOutput(STDERR, '[ERROR] dev="' . $name . '" user_id=' . $userId . ' job=' . $id . ': ' . $e->getMessage());
        $db->finishPipelineSnapshot($id, false, (string)$e);
        try {
            $db->logApplication($userId, 'System', 'pipeline_writer', 'error', (string)$e);
        } catch (Throwable) {}
    }

    if ($daemonMode && function_exists('gc_collect_cycles')) gc_collect_cycles();
} while ($daemonMode);
if (!$daemonMode && $hadError) exit(1);
