<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'daemon-slot.php';
if (!linnms_acquire_daemon_slot('reader', 'LINNMS_WORKER_SLOT')) exit(0);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'paths.php';
require_once project_path('src', 'helpers.php');
require_once project_path('src', 'load-repository.php');
require_once project_path('vendor', 'driver', 'olt', 'zetset', 'ApiClient.php');
require_once project_path('src', 'TelegramNotifier.php');
require_once project_path('src', 'WhatsAppGateway.php');
require_once project_path('app', 'ApiRequestLogger.php');
require_once project_path('app', 'CredentialCipher.php');
require_once project_path('vendor', 'driver', 'SnmpDeviceDriver.php');
require_once project_path('vendor', 'driver', 'SnmpDriverFactory.php');
require_once project_path('vendor', 'driver', 'TrafficService.php');
require_once project_path('app', 'SnmpDeviceService.php');
require_once project_path('app', 'MikrotikService.php');
require_once project_path('app', 'SwitchService.php');

require_once project_path('app', 'SnmpOltService.php');
require_once project_path('vendor', 'driver', 'olt', 'zetset', 'ApiClientFactory.php');
require_once project_path('src', 'SyncService.php');
require_once project_path('backend', 'services', 'SyncOrchestrator.php');
require_once project_path('backend', 'services', 'OltSyncOrchestrator.php');

$daemonMode = filter_var((string)(getenv('LINNMS_WORKER_DAEMON') ?: '0'), FILTER_VALIDATE_BOOL);
$workerLogPath = shared_log_path('sync-worker.log');
$hadError = false;
$workerOutput = static function ($stream, string $message) use ($daemonMode, $workerLogPath, &$hadError): void {
    if ($stream !== STDERR || !str_starts_with($message, '[ERROR]')) return;
    $hadError = true;
    $entry = '[' . date('Y-m-d H:i:s P') . '] ' . rtrim($message) . PHP_EOL;
    fwrite(STDERR, $entry);
    if (!$daemonMode) return;

    $directory = dirname($workerLogPath);
    if (!is_dir($directory)) @mkdir($directory, 0770, true);
    $lockDirectory = lock_path();
    if (!is_dir($lockDirectory)) @mkdir($lockDirectory, 0770, true);
    $lock = @fopen($lockDirectory . DIRECTORY_SEPARATOR . basename($workerLogPath) . '.lock', 'c+');

    if (is_resource($lock) && flock($lock, LOCK_EX)) {
        $temporary = null;
        try {
            $existing = is_file($workerLogPath) ? (string)@file_get_contents($workerLogPath) : '';
            $combined = $entry . $existing;
            $lines = preg_split('/\\R/', rtrim($combined)) ?: [];
            $lines = array_slice($lines, 0, 3000);
            $temporary = tempnam($directory, '.worker-log.');
            if ($temporary !== false) {
                file_put_contents($temporary, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
                @chmod($temporary, 0660);
                @rename($temporary, $workerLogPath);
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

$clients = new ApiClientFactory($config, new ApiRequestLogger($db));
$credentialCipher = new CredentialCipher((string)$config['credential_key']);
$snmpOlts = new SnmpOltService($db, $config, $credentialCipher, new OltSnmpDriverFactory());
$snmpDevices = new SnmpDeviceService(
    $db,
    $config,
);
$mikrotiks = new MikrotikService($snmpDevices);
$switches = new SwitchService($snmpDevices);
$orchestrator = new SyncOrchestrator($db, $config, $clients, $snmpOlts, $mikrotiks, $switches);

if ($daemonMode) set_time_limit(0);
$workerToken = substr((gethostname() ?: 'linnms') . '-' . getmypid() . '-' . bin2hex(random_bytes(8)), 0, 64);
$nextScheduleAt = 0;
$nextLogPruneAt = 0;
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
$slot = (int)(getenv('LINNMS_WORKER_SLOT') ?: 0);
$nextSlotCheckAt = 0;
$activeJobs = [];
$draining = false;
$nextHeartbeatAt = 0;
$nextScanCheckAt = 0;
$nextExtraClaimAt = 0;
$currentJobId = 0;
$lastLeaseTouchAt = [];
$yieldAfterBatch = static function () use (&$activeJobs, &$currentJobId, &$lastLeaseTouchAt, $db, $workerToken, $workerOutput): float {
    if ($currentJobId > 0 && time() >= ($lastLeaseTouchAt[$currentJobId] ?? 0) + 30) {
        try {
            $db->touchScheduledSyncJob($currentJobId, $workerToken);
            $lastLeaseTouchAt[$currentJobId] = time();
        } catch (Throwable $heartbeatError) {
            $workerOutput(STDERR, '[ERROR] Perpanjangan lease job gagal: ' . $heartbeatError->getMessage());
        }
    }
    if ($activeJobs === []) {
        try {
            if ($db->countQueuedScheduledSyncJobs() === 0) return 0.0;
        } catch (Throwable) {
            return 0.0;
        }
    }
    $pausedAt = microtime(true);
    Fiber::suspend();
    return max(0.0, microtime(true) - $pausedAt);
};

do {
    if ($daemonMode && $slot > 0 && time() >= $nextSlotCheckAt) {
        try {
            if ($slot > $db->getDaemonCount('reader')) $draining = true;
        } catch (Throwable $slotError) {
            $workerOutput(STDERR, '[ERROR] Pemeriksaan jumlah reader gagal: ' . $slotError->getMessage());
            sleep(5);
            continue;
        }
        $nextSlotCheckAt = time() + 5;
    }
    if ($daemonMode && time() >= $nextSourceCheckAt) {
        if ($sourceChanged()) $draining = true;
        $nextSourceCheckAt = time() + 30;
    }
    if ($draining && $activeJobs === []) break;
    if (time() >= $nextAccessCheckAt) {
        $accessAllowed = linnms_worker_access_allowed($db);
        $nextAccessCheckAt = time() + 5;
    }
    if (!$accessAllowed && $activeJobs === []) {
        if (!$daemonMode) break;
        sleep(60);
        continue;
    }

    if ($accessAllowed && !$draining && time() >= $nextScanCheckAt) {
        $nextScanCheckAt = time() + 5;
        try {
            if ($orchestrator->processNextScan($workerToken)) {
                if (!$daemonMode) break;
                if ($activeJobs === []) {
                    usleep(500000);
                    continue;
                }
            }
        } catch (Throwable $scanQueueError) {
            $workerOutput(STDERR, '[ERROR] Antrean pemindaian OLT gagal: ' . $scanQueueError->getMessage());
            try {
                $db->logApplication(null, 'System', 'olt_scan_queue', 'error', (string)$scanQueueError);
            } catch (Throwable) {}
            if (!$daemonMode) break;
            if ($activeJobs === []) {
                usleep(5_000_000);
                continue;
            }
        }
    }

    if ($accessAllowed && time() >= $nextLogPruneAt) {
        try {
            $db->pruneLogHistory();
        } catch (Throwable $pruneError) {
            $workerOutput(STDERR, '[ERROR] Retensi log gagal: ' . $pruneError->getMessage());
            try {
                $db->logApplication(null, 'System', 'log_retention', 'error', (string)$pruneError);
            } catch (Throwable) {}
        }
        $nextLogPruneAt = time() + 86400;
    }
    if ($accessAllowed && time() >= $nextScheduleAt) {
        try {
            $orchestrator->scheduleDue();
        } catch (Throwable $scheduleError) {
            $workerOutput(STDERR, '[ERROR] Penjadwalan sinkronisasi gagal: ' . $scheduleError->getMessage());
        }
        try {
            $orchestrator->recoverDeadWorkers();
        } catch (Throwable $recoverError) {
            $workerOutput(STDERR, '[ERROR] Pemulihan worker mati gagal: ' . $recoverError->getMessage());
            try {
                $db->logApplication(null, 'System', 'worker_recovery', 'error', (string)$recoverError);
            } catch (Throwable) {}
        }
        $nextScheduleAt = time() + 30;
    }

    if ($daemonMode) {
        if ($activeJobs !== [] && time() >= $nextHeartbeatAt) {
            foreach ($activeJobs as $activeJob) {
                try {
                    $db->touchScheduledSyncJob((int)$activeJob['job']['id'], $workerToken);
                    $lastLeaseTouchAt[(int)$activeJob['job']['id']] = time();
                } catch (Throwable $heartbeatError) {
                    $workerOutput(STDERR, '[ERROR] Perpanjangan lease job gagal: ' . $heartbeatError->getMessage());
                }
            }
            $nextHeartbeatAt = time() + 30;
        }
        if ($accessAllowed && !$draining && count($activeJobs) < 4
            && ($activeJobs === [] || time() >= $nextExtraClaimAt)) {
            try {
                $queueLoad = $db->scheduledSyncQueueLoad();
                if ($queueLoad['users'] > 0) {
                    $nextJob = $orchestrator->claimScheduledJob($workerToken);
                    if (is_array($nextJob)) {
                        $activeJobs[] = [
                            'job' => $nextJob,
                            'fiber' => new Fiber(static function () use ($orchestrator, $nextJob, $workerToken, $workerOutput, $yieldAfterBatch): void {
                                $orchestrator->runScheduledJob($nextJob, $workerToken, $workerOutput, $yieldAfterBatch);
                            }),
                        ];
                        $nextExtraClaimAt = time() + min(5, max(2,
                            intdiv($queueLoad['devices'], max(1, $queueLoad['users']))));
                    }
                }
            } catch (Throwable $claimError) {
                $workerOutput(STDERR, '[ERROR] Klaim job terjadwal gagal: ' . $claimError->getMessage());
            }
        }
        if ($activeJobs !== []) {
            $activeJob = array_shift($activeJobs);
            $currentJobId = (int)$activeJob['job']['id'];
            try {
                $fiber = $activeJob['fiber'];
                if (!$fiber->isStarted()) $fiber->start();
                elseif (!$fiber->isTerminated()) $fiber->resume();
                if (!$fiber->isTerminated()) $activeJobs[] = $activeJob;
            } catch (Throwable $jobError) {
                $workerOutput(STDERR, '[ERROR] Job user_id=' . (int)$activeJob['job']['user_id'] . ' gagal: ' . $jobError->getMessage());
                try {
                    $db->finishScheduledSyncJob((int)$activeJob['job']['user_id'], (int)$activeJob['job']['id'], $workerToken, false, $jobError->getMessage());
                } catch (Throwable $finishError) {
                    $workerOutput(STDERR, '[ERROR] Penyelesaian job gagal: ' . $finishError->getMessage());
                }
            }
            if ($activeJob['fiber']->isTerminated()) unset($lastLeaseTouchAt[$currentJobId]);
            $currentJobId = 0;
            if (function_exists('gc_collect_cycles')) gc_collect_cycles();
            continue;
        }
        if ($draining) break;
        usleep(2_000_000);
        continue;
    }

    try {
        $job = $orchestrator->claimScheduledJob($workerToken);
    } catch (Throwable $claimError) {
        $workerOutput(STDERR, '[ERROR] Klaim job terjadwal gagal: ' . $claimError->getMessage());
        try {
            $db->logApplication(null, 'System', 'scheduled_sync_claim', 'error', (string)$claimError);
        } catch (Throwable) {}
        if (!$daemonMode) break;
        usleep(3_000_000);
        continue;
    }

    if (!is_array($job)) {
        if (!$daemonMode) {
            break;
        }
        usleep(2_000_000);
        continue;
    }

    $orchestrator->runScheduledJob($job, $workerToken, $workerOutput);

    if ($daemonMode && function_exists('gc_collect_cycles')) gc_collect_cycles();
} while ($daemonMode);
if (!$daemonMode && $hadError) exit(1);
