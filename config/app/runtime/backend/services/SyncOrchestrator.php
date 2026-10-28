<?php

declare(strict_types=1);

final class SyncOrchestrator
{
    public function __construct(
        private LinnmsRepository $db,
        private array $config,
        private ApiClientFactory $clients,
        private SnmpOltService $snmpOlts,
        private MikrotikService $mikrotiks,
        private SwitchService $switches
    ) {
    }

    public static function scheduleDueFor(LinnmsRepository $db): void
    {
        $db->autoApproveExpiredApiOltFirstScans();
        $db->enqueueScheduledSyncJobs(60, 900);
    }

    public function scheduleDue(): void
    {
        self::scheduleDueFor($this->db);
    }

    public function recoverDeadWorkers(): void
    {
        $this->db->recoverDeadSyncWorkers();
        $this->db->recoverDeadOltScanWorkers();
    }

    public function claimScheduledJob(string $workerToken): ?array
    {
        return $this->db->claimScheduledSyncJob($workerToken);
    }

    private function syncErrorSummary(array $result): string
    {
        $detail = trim(raw_result_error($result, 'SnmpOltService::sync()'));
        $detail = preg_split('/\R(?:Stack trace:|#\d+\s)/', $detail, 2)[0] ?? $detail;
        $detail = preg_split('/\s+in\s+\/[^\r\n]+(?:\s+on line\s+\d+)?$/', $detail, 2)[0] ?? $detail;
        $detail = preg_replace('/^(?:(?:PHP\s+)?(?:Fatal\s+)?(?:Error|Exception):\s*)+/i', '', $detail) ?? $detail;
        $detail = trim($detail, " \t\n\r\0\x0B.:;");
        return $detail !== '' ? $detail : 'penyebab gagal tidak tersedia';
    }

    public function processNextScan(string $workerToken): bool
    {
        $db = $this->db;
        $snmpOlts = $this->snmpOlts;
        $request = $db->claimOltScanRequest($workerToken);
        if (!is_array($request)) return false;

        $uid = (int)$request['user_id'];
        $scanRowId = (int)$request['olt_row_id'];
        $requestId = (int)$request['id'];

        $olt = $db->getOltByRowId($uid, $scanRowId);
        if (!is_array($olt)) {
            $db->finishOltScanRequest($requestId, $workerToken, false, 'OLT sudah tidak terdaftar untuk sinkronisasi SNMP.', true);
            return true;
        }

        $oltLabel = trim((string)($olt['name'] ?? ''));
        if ($oltLabel === '') $oltLabel = trim((string)($olt['ip_address'] ?? ''));
        if ($oltLabel === '') $oltLabel = 'OLT #' . $scanRowId;

        try {
            $reportProgress = static function (string $phase, int $processedOnus, int $totalOnus = 0) use ($db, $requestId, $workerToken): void {
                $db->updateOltScanProgress($requestId, $workerToken, $phase, $processedOnus, $totalOnus);
            };
            $retryPayload = json_decode((string)($request['retry_middle_json'] ?? ''), true);
            $savedRows = is_array($retryPayload['rows'] ?? null) ? $retryPayload['rows'] : [];
            if ($savedRows !== []) {
                $failedPipeline = $db->pdo()->prepare(
                    "SELECT 1 FROM sync_pipeline WHERE user_id=:uid AND olt_row_id=:row AND handler='snmp_olt'
                     AND status='failed' AND created_at>=:requested LIMIT 1"
                );
                $failedPipeline->execute(['uid'=>$uid, 'row'=>$scanRowId, 'requested'=>(string)$request['requested_at']]);
                if ($failedPipeline->fetchColumn() !== false) $savedRows = [];
            }
            $result = $savedRows !== []
                ? $snmpOlts->retryMiddleOnus($uid, $scanRowId, (string)($retryPayload['driver_id'] ?? ''), $savedRows, $reportProgress)
                : ['retry_invalid'=>true];
            if (!empty($result['retry_invalid'])) {
                $result = $snmpOlts->sync($uid, (int)$olt['id'], true, true, $reportProgress);
            }
            $retryRows = array_values(array_filter((array)($result['retry_middle_rows'] ?? []), 'is_array'));
            $ok = !empty($result['ok']);
            $message = $ok ? (string)($result['message'] ?? 'Pemindaian OLT selesai.')
                : raw_result_error($result, 'OltScan');
            if (!empty($result['queued'])) $db->markOltScanWriting($requestId, $workerToken);
            $db->finishOltScanRequest(
                $requestId,
                $workerToken,
                $ok,
                $ok ? '' : raw_result_error($result, 'OltScan'),
                false,
                $retryRows,
                (string)device_driver_type($olt)
            );
            $db->logApplication(
                $uid,
                'OLT',
                'scan_olt',
                $ok ? 'success' : 'error',
                $oltLabel . ': ' . $message
            );
        } catch (Throwable $e) {
            $db->finishOltScanRequest($requestId, $workerToken, false, (string)$e);
            try {
                $db->logApplication(
                    $uid,
                    'OLT',
                    'scan_olt',
                    'error',
                    $oltLabel . ': pemindaian OLT gagal: ' . $e->getMessage()
                );
            } catch (Throwable) {}
        }
        return true;
    }

    public function runScheduledJob(array $job, string $workerToken, callable $workerOutput, ?callable $yieldAfterBatch = null): void
    {
        $db = $this->db;
        $config = $this->config;
        $clients = $this->clients;
        $snmpOlts = $this->snmpOlts;
        $mikrotiks = $this->mikrotiks;
        $switches = $this->switches;
        $jobId = (int)$job['id'];
        $uid = (int)$job['user_id'];
        $username = (string)($job['username'] ?? ('user-' . $uid));
        $issues = 0;
        $parts = [];

        try {
            $db->recordOverdueOnuProblemEvents($uid);
        } catch (Throwable $activityError) {
            $workerOutput(STDERR, '[ERROR] ' . $username . ': maintenance Aktivitas Terbaru gagal: ' . $activityError->getMessage());
            try {
                $db->logApplication($uid, 'System', 'activity_maintenance', 'error', (string)$activityError);
            } catch (Throwable) {}
        }

        try {
            $bridge = new RuntimeBridge($db->pdo(), (array)(require '/config/app/config/service.php'));
            $GLOBALS['bridge'] = $bridge;
            $service = new SyncService($config, $db, new TelegramNotifier(), $clients, $bridge);

            $groupParts = ['olt'=>[], 'router'=>[], 'switch'=>[]];
            $userDrivers = $db->getUserDriverCounts($uid);

            $cachePruneMarker = cache_path('cache-prune.stamp');
            if (!is_file($cachePruneMarker) || (time() - (int)@filemtime($cachePruneMarker)) > 3600) {
                @touch($cachePruneMarker);
                try {
                    $cacheDeviceIds = [];
                    foreach ($db->pdo()->query('SELECT id FROM devices') as $cacheDeviceRow) $cacheDeviceIds[] = (int)$cacheDeviceRow['id'];
                    cache_prune($cacheDeviceIds);
                } catch (Throwable $cacheError) {
                    $workerOutput(STDERR, '[ERROR] ' . $username . ': pembersihan cache gagal: ' . $cacheError->getMessage());
                    try {
                        $db->logApplication($uid, 'System', 'cache_prune', 'error', (string)$cacheError);
                    } catch (Throwable) {}
                }
            }

            foreach (monitoring_driver_catalog() as $driverId=>$definition) {
                $handler=(string)($definition['handler']??'');
                $group=(string)($definition['group']??'');
                $label=(string)($definition['label']??$driverId);
                if (!array_key_exists($group,$groupParts)) continue;
                if (!isset($userDrivers[$driverId])) continue;

                if ($handler==='external_api') {
                    if ($clients->forUser($uid) === null) continue;
                    $driverResult=$service->syncUser($uid,true,$yieldAfterBatch);
                    if (empty($driverResult['ok'])) $issues++;
                    $groupParts[$group][]=$label.': '.(int)($driverResult['olts']??0).', '.(int)($driverResult['failures']??0).' gagal';
                    continue;
                }

                if ($handler==='snmp_olt') {
                    $driverResult=(new OltSyncOrchestrator($db, $snmpOlts))->syncDue($uid,(string)$driverId,$yieldAfterBatch);
                    $down=(int)($driverResult['down']??0);
                    $errors=max(0,(int)$driverResult['failed']-$down);

                    foreach ((array)($driverResult['errors']??[]) as $failedSync) {
                        if (!is_array($failedSync)) continue;
                        $deviceDown=!empty($failedSync['device_down']);
                        if ($deviceDown) continue;
                        $identity=trim((string)($failedSync['olt_name']??''));
                        $ip=trim((string)($failedSync['ip_address']??''));
                        if ($identity==='') $identity=$ip!=='' ? $ip : (string)$label;

                        $detail=$this->syncErrorSummary($failedSync);
                        $db->logApplication(
                            $uid,
                            $label,
                            'sync_snmp_olt',
                            'error',
                            'Sinkronisasi '.$identity.' gagal: '.$detail.'.'
                        );
                    }
                    $groupParts[$group][]=$label.': '.(int)$driverResult['success'].', '.$down.' down, '.$errors.' error';
                    $issues+=$errors;
                    continue;
                }

                if ($handler==='mikrotik_snmp' || $handler==='switch_snmp') {
                    $driverResult=$handler==='mikrotik_snmp' ? $mikrotiks->syncDriver($uid,$driverId,$yieldAfterBatch) : $switches->syncDriver($uid,$driverId,$yieldAfterBatch);
                    $stats=(array)($driverResult['by_driver'][$driverId]??[]);
                    $groupParts[$group][]=$label.': '.(int)($stats['online']??0).', '.(int)($stats['changes']??0).' perubahan interface, '.(int)($stats['failed']??0).' gagal';
                    $issues+=(int)$driverResult['failed'];
                    continue;
                }

                throw new RuntimeException('Handler driver tidak dikenal: '.$driverId.' -> '.$handler);
            }

            foreach (['olt'=>'OLT','router'=>'Router','switch'=>'Switch'] as $group=>$label) {
                if ($groupParts[$group] === []) continue;
                $parts[]=$label.' ['.implode(' | ',$groupParts[$group]).']';
            }

            $service->sendPendingTelegramChanges($uid);

            if ($issues > 0) $workerOutput(STDERR, '[ERROR] ' . $username . ': ' . implode(' | ', $parts));
            $db->finishScheduledSyncJob($uid, $jobId, $workerToken, true, '');
        } catch (Throwable $e) {
            $workerOutput(STDERR, '[ERROR] ' . $username . ': ' . $e->getMessage());
            $db->finishScheduledSyncJob($uid, $jobId, $workerToken, false, $e->getMessage());
            try {
                $db->logApplication(
                    $uid,
                    'System',
                    'scheduled_sync_worker',
                    'error',
                    (string)$e
                );
            } catch (Throwable) {}
        }
    }
}
