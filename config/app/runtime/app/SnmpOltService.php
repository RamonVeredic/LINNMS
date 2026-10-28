<?php

declare(strict_types=1);

require_once __DIR__ . '/OltTerminalBrokerClient.php';
require_once dirname(__DIR__) . '/vendor/driver/Controller.php';

final class SnmpOltService
{
    private const AUTO_SYNC_INTERVAL_MINUTES = 3;
    private const MONITORING_DEADLINE_SECONDS = 250;
    private const STALE_LOCK_SECONDS = 340;
    private const PON_ADMIN_INTENT_TTL_SECONDS =200;
    private const CLI_OPTICAL_BUDGET_PER_PON_SECONDS = 20;
    private const CLI_OPTICAL_MIN_BUDGET_SECONDS = 90;
    private const CLI_OPTICAL_MAX_BUDGET_SECONDS = 420;

    private static array $oltCliSessions = [];
    private ?OltSnapshotWriteService $snapshotWriter = null;
    private ?OnuSnapshotWriteService $onuWriter = null;
    private ?OltConfigurationWriteService $configWriter = null;
    private ?TrafficSampleWriteService $sampleWriter = null;
    private const OLT_CLI_IDLE_SECONDS = 300;

    public function __construct(
        private readonly LinnmsRepository $repository,
        private readonly array $config,
        private readonly CredentialCipher $cipher,
        private readonly OltSnmpDriverFactory $driverFactory,
    ) {}

    public function create(int $userId, array $input): array
    {
        $name = trim((string)($input['olt_name'] ?? ''));
        $ip = trim((string)($input['ip_address'] ?? ''));
        $community = trim((string)($input['community'] ?? ''));
        $snmpPortRaw = trim((string)($input['snmp_port'] ?? '161'));
        $driverId = olt_driver_id_from_input($input);
        $definition = $driverId !== '' ? olt_driver_definition($driverId) : null;
        $telnetCli = is_array($definition) && !empty($definition['telnet_cli']);
        $hybridRequested = trim((string)($input['hybrid_status'] ?? ''));
        $hybridStatus = $telnetCli && $hybridRequested === '1' ? 1 : 0;
        $cliEnabled = $hybridStatus === 1;
        $cliPortRaw = trim((string)($input['ports'] ?? '23'));
        $cliUsername = $cliEnabled ? trim((string)($input['username'] ?? '')) : '';
        $cliPassword = $cliEnabled ? (string)($input['password'] ?? '') : '';
        $errors = [];
        if ($name === '') $errors[] = validation_required_message('Nama perangkat');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) $errors[] = validation_ip_message();
        if ($community === '') $errors[] = validation_required_message('Community');
        $invalidPort = !ctype_digit($snmpPortRaw) || (int)$snmpPortRaw < 1 || (int)$snmpPortRaw > 65535
            || ($cliEnabled && (!ctype_digit($cliPortRaw) || (int)$cliPortRaw < 1 || (int)$cliPortRaw > 65535));
        if ($invalidPort) $errors[] = validation_port_message();
        if ($cliEnabled && $cliUsername === '') $errors[] = validation_required_message('Username');
        if ($cliEnabled && $cliPassword === '') $errors[] = validation_required_message('Password');
        if (!is_array($definition) || (string)($definition['handler'] ?? '') !== 'snmp_olt') {
            $errors[] = 'Driver OLT SNMP belum didukung.';
        }
        if (is_array($definition) && !olt_driver_support_enabled($this->repository->getOltDriverSupportFlags(), $driverId)) {
            $errors[] = 'Driver OLT ini tidak diizinkan atau sedang dinonaktifkan.';
        }
        if ($errors !== []) return ['ok'=>false,'errors'=>$errors,'message'=>'Validasi form gagal.'];

        $snmpPort = (int)$snmpPortRaw;

        $quota = $this->repository->getUserOltQuota($userId);
        if (empty($quota['can_add'])) {
            $message = ($quota['blocked_by'] ?? '') === 'q' ? 'Batas OLT keseluruhan telah tercapai.' : 'Batas OLT akun telah tercapai.';
            return ['ok'=>false,'errors'=>[$message],'message'=>$message];
        }

        try {
            $probeDriver = $this->newDriver($userId, $driverId, $ip, $community, 0, $snmpPort);
            $probe = $probeDriver->test();
            if (empty($probe['ok'])) throw new RuntimeException((string)($probe['message'] ?? 'SNMP OLT tidak dapat dijangkau.'));
            $modelCheck = olt_model_validate($driverId, $definition, $probeDriver->getSystemInfo());
            if (empty($modelCheck['ok'])) {
                throw new RuntimeException((string)$modelCheck['message']);
            }
            $encryptedCliPassword = $cliEnabled && $cliPassword !== '' ? $this->cipher->encrypt($cliPassword) : null;
            $rowId = $this->repository->createSnmpOlt(
                $userId,
                $name,
                $ip,
                $community,
                $driverId,
                $snmpPort,
                $hybridStatus,
                $cliEnabled ? (int)$cliPortRaw : 23,
                $cliUsername !== '' ? $cliUsername : null,
                $encryptedCliPassword,
            );
            return ['ok'=>true,'row_id'=>$rowId,'errors'=>[],
                'message'=>'OLT berhasil ditambahkan. Data akan dibaca pada sinkronisasi berikutnya.'];
        } catch (Throwable $e) {
            if (isset($rowId)) $this->repository->deleteOltSnapshotByRowId($userId, (int)$rowId, 'snmp');
            return ['ok'=>false,'errors'=>[$e->getMessage()],'message'=>'OLT gagal ditambahkan.','error'=>(string)$e];
        }
    }

    public function encryptCredential(string $plain): string
    {
        return $this->cipher->encrypt($plain);
    }

    public function update(int $userId, int $rowId, array $input): array
    {
        $olt = $this->repository->getOltByRowId($userId, $rowId, true);
        if ($olt === null || !monitoring_row_uses_handler($olt, 'snmp_olt')) {
            return ['ok'=>false,'errors'=>['OLT tidak ditemukan.'],'message'=>'OLT tidak ditemukan.'];
        }

        $name = trim((string)($input['device_name'] ?? $input['olt_name'] ?? ''));
        $ip = trim((string)($input['ip_address'] ?? ''));
        $community = trim((string)($input['community'] ?? ''));
        $snmpPortRaw = trim((string)($input['snmp_port'] ?? '161'));
        $driverId = olt_driver_id_from_input($input);
        $definition = $driverId !== '' ? olt_driver_definition($driverId) : null;
        if (!is_array($definition) || (string)($definition['handler'] ?? '') !== 'snmp_olt') {
            return ['ok'=>false,'errors'=>['Driver OLT SNMP belum didukung.'],'message'=>'Validasi form gagal.'];
        }
        if (!olt_driver_support_enabled($this->repository->getOltDriverSupportFlags(), $driverId)) {
            return ['ok'=>false,'errors'=>['Driver OLT ini tidak diizinkan atau sedang dinonaktifkan.'],'message'=>'Driver OLT ini tidak diizinkan atau sedang dinonaktifkan.'];
        }

        $telnetCli = !empty($definition['telnet_cli']);
        $hybridRequested = trim((string)($input['hybrid_status'] ?? ''));
        $hybridStatus = $telnetCli && $hybridRequested === '1' ? 1 : 0;
        $cliEnabled = $hybridStatus === 1;
        $cliPortRaw = trim((string)($input['ports'] ?? '23'));
        $cliUsername = $cliEnabled ? trim((string)($input['username'] ?? '')) : '';
        $cliPassword = $cliEnabled ? (string)($input['password'] ?? '') : '';
        $hasStoredCliPassword = trim((string)($olt['password_enc'] ?? '')) !== '';

        $errors = [];
        if ($name === '') $errors[] = validation_required_message('Nama perangkat');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) $errors[] = validation_ip_message();
        if ($community === '') $errors[] = validation_required_message('Community');
        $invalidPort = !ctype_digit($snmpPortRaw) || (int)$snmpPortRaw < 1 || (int)$snmpPortRaw > 65535
            || ($cliEnabled && (!ctype_digit($cliPortRaw) || (int)$cliPortRaw < 1 || (int)$cliPortRaw > 65535));
        if ($invalidPort) $errors[] = validation_port_message();
        if ($cliEnabled && $cliUsername === '') $errors[] = validation_required_message('Username');
        if ($cliEnabled && $cliPassword === '' && !$hasStoredCliPassword) $errors[] = validation_required_message('Password');
        if ($errors !== []) return ['ok'=>false,'errors'=>$errors,'message'=>'Validasi form gagal.'];

        $snmpPort = (int)$snmpPortRaw;
        $connectionChanged = $driverId !== trim((string)device_driver_type($olt))
            || $ip !== trim((string)($olt['ip_address'] ?? ''))
            || $snmpPort !== (int)($olt['snmp_port'] ?? 161)
            || $community !== trim((string)($olt['snmp_community'] ?? ''));
        if ($connectionChanged) {
            try {
                $probeDriver = $this->newDriver($userId, $driverId, $ip, $community, $rowId, $snmpPort);
                $probe = $probeDriver->test();
            } catch (Throwable $e) {
                return ['ok'=>false,'errors'=>['Perangkat tidak dapat dijangkau.'],'message'=>'Perangkat tidak dapat dijangkau.','error'=>(string)$e];
            }
            if (empty($probe['ok'])) {
                return [
                    'ok'=>false,
                    'errors'=>['Perangkat tidak dapat dijangkau.'],
                    'message'=>'Perangkat tidak dapat dijangkau.',
                    'error'=>(string)($probe['message'] ?? ''),
                ];
            }
            $modelCheck = olt_model_validate($driverId, $definition, $probeDriver->getSystemInfo());
            if (empty($modelCheck['ok'])) {
                return [
                    'ok'=>false,
                    'errors'=>[(string)$modelCheck['message']],
                    'message'=>(string)$modelCheck['message'],
                ];
            }
        }

        try {
            $credentials = ['hybrid_status' => $hybridStatus];
            if ($cliEnabled) {
                $credentials['ports'] = max(1, min(65535, (int)$cliPortRaw));
                $credentials['username'] = $cliUsername;
                if ($cliPassword !== '') $credentials['password_enc'] = $this->cipher->encrypt($cliPassword);
            }

            $this->configWriter()->updateConnection($userId, $rowId, [
                'type' => $driverId,
                'name' => $name,
                'ip_address' => $ip,
                'snmp_port' => $snmpPort,
            ]);
            $this->configWriter()->writeCommunity($rowId, $userId, $community);
            $this->configWriter()->writeCredentials($rowId, $userId, $credentials);
            if (!$cliEnabled && (int)($olt['hybrid_status'] ?? 0) === 1
                && !OltTerminalBrokerClient::closeDevice($olt)) {
                error_log('[LINNMS] Sesi CLI OLT gagal ditutup setelah Telnet dimatikan');
            }
            $this->configWriter()->markConfigChanged($userId, $rowId);
            $this->purgeOltLocalState($userId, $rowId);
        } catch (Throwable $e) {
            return ['ok'=>false,'errors'=>['Gagal menyimpan perubahan.'],'message'=>'Gagal menyimpan perubahan.','error'=>(string)$e];
        }

        return [
            'ok'=>true,
            'row_id'=>$rowId,
            'errors'=>[],
            'message'=>'Berhasil menyimpan perubahan',
        ];
    }

    private function driverAllowed(array $olt): bool
    {
        $driverId = trim((string)device_driver_type($olt));
        if ($driverId === '') return false;
        if (!function_exists('olt_driver_support_enabled')) return false;
        return olt_driver_support_enabled($this->repository->getOltDriverSupportFlags(), $driverId);
    }

    public function sync(int $userId, int $rowId, bool $recordChanges = true, bool $forceFullInventory = false, ?callable $reportProgress = null, ?callable $yieldAfterBatch = null): array
    {
        $olt = $this->repository->getOltByRowId($userId, $rowId);
        if ($olt === null || !monitoring_row_uses_handler($olt,'snmp_olt')) return ['ok'=>false,'message'=>'OLT tidak ditemukan.'];

        $startedAt = microtime(true);
        $timing = $this->repository->getSyncTimingSettings($userId);
        $middleMarker = cache_path('olt-middle-' . $userId . '-' . $rowId . '.stamp');
        $middleModifiedAt = is_file($middleMarker) ? @filemtime($middleMarker) : false;
        $middleDue = $middleModifiedAt === false || time() - (int)$middleModifiedAt >= $timing['middle_interval_minutes'] * 60;
        $configChangePending = !empty($this->repository->getDeviceConfigChangeState($userId, $rowId)['pending']);
        if ($configChangePending) $this->purgeOltLocalState($userId, $rowId);
        if ($configChangePending || $forceFullInventory) $middleDue = true;
        $forceFullInventory = $forceFullInventory || $configChangePending;
        $cliOpticalBudget = $this->cliOpticalBudgetSeconds($olt);
        $knownOnus = $this->repository->getOnus($userId, (int)$olt['olt_id'], false, 'snmp');
        if ($knownOnus === []) $middleDue = true;
        if (!$forceFullInventory && $this->repository->hasPendingMiddleRetry($userId, $rowId)) $middleDue = false;
        $driver = $this->driverForOlt($olt);
        $completeInventory = $forceFullInventory
            || $knownOnus === []
            || $driver->inventoryDiscoveryDue($timing['discovery_interval_minutes'] * 60);
        $discoveryRequested = $completeInventory;
        $deadlineSeconds = $completeInventory
            ? $timing['discovery_deadline_seconds']
            : $timing['fast_deadline_seconds'];
        $deadlineAt = $startedAt + $deadlineSeconds;
        $lockPath = lock_path('snmp-olt-sync-' . $userId . '-' . $rowId . '.lock');
        ensure_data_directory(lock_path());
        [$syncLock, $lockOpenError] = $this->openSyncLock($lockPath);
        if (!is_resource($syncLock)) {
            $rawError = 'File lock sinkronisasi SNMP tidak dapat dibuka: ' . $lockPath
                . '; fopen_error=' . var_export($lockOpenError, true) . '.';
            error_log($rawError);
            $this->repository->logApplication($userId, 'OLT SNMP', 'sync_lock_open', 'error', 'OLT tidak dapat dijalankan.');
            return [
                'ok'=>false,
                'monitoring_stopped'=>true,
                'message'=>'Pemantauan OLT tidak dapat dijalankan.',
                'error'=>$rawError,
            ];
        }
        $lockAcquired = flock($syncLock, LOCK_EX | LOCK_NB);
        if (!$lockAcquired && is_resource($syncLock)) {
            for ($attempt = 0; $attempt < 10; $attempt++) {
                usleep(250000);
                if (flock($syncLock, LOCK_EX | LOCK_NB)) {
                    $lockAcquired = true;
                    break;
                }
            }
        }

        if (!$lockAcquired) {
            $metadata = is_resource($syncLock) ? $this->readSyncLockMetadata($syncLock) : [];
            $lockStartedAt = (float)($metadata['started_at_epoch'] ?? 0);
            $lockAge = $lockStartedAt > 0 ? max(0, (int)floor(microtime(true) - $lockStartedAt)) : null;
            $staleThreshold = max(self::STALE_LOCK_SECONDS, $deadlineSeconds + $cliOpticalBudget + 90);
            $stale = $lockAge === null || $lockAge >= $staleThreshold;
            $terminatedOwner = null;
            if ($stale && is_resource($syncLock)) {
                $terminatedOwner = $this->terminateLegacyLockOwner($lockPath);
                if ($terminatedOwner !== null) {
                    for ($attempt = 0; $attempt < 20; $attempt++) {
                        usleep(250000);
                        if (flock($syncLock, LOCK_EX | LOCK_NB)) {
                            $lockAcquired = true;
                            break;
                        }
                    }
                }
            }

            if ($lockAcquired) {
            } else {
                if (is_resource($syncLock)) fclose($syncLock);
                $rawError = 'flock(LOCK_EX | LOCK_NB) gagal untuk ' . $lockPath
                    . '; metadata=' . var_export($metadata, true)
                    . '; lock_age_seconds=' . var_export($lockAge, true)
                    . '; owner_recovery=' . var_export($terminatedOwner, true)
                    . '; recovery_version=4; source_mtime=' . var_export(@filemtime(__FILE__), true) . '.';

                if (!$stale) {
                    return [
                        'ok'=>true,
                        'busy'=>true,
                        'in_progress'=>true,
                        'message'=>'Sync Sedang berjalan',
                        'lock_age_seconds'=>$lockAge,
                        'lock_metadata'=>$metadata,
                    ];
                }

                error_log($rawError);
                $this->repository->logApplication($userId, 'OLT SNMP', 'sync_lock', 'error', 'Sync tertunda');
                return [
                    'ok'=>false,
                    'busy'=>true,
                    'monitoring_stopped'=>true,
                    'message'=>'Gagal melakukan sync',
                    'error'=>$rawError,
                    'lock_age_seconds'=>$lockAge,
                    'lock_metadata'=>$metadata,
                ];
            }
        }

        $syncFailureLogged = false;
        $deviceReachable = null;
        $lanStatusRead = false;
        $cliLowProcess = null;
        try {
            $this->writeSyncLockMetadata($syncLock, $userId, $rowId, $startedAt);
            if ($reportProgress !== null) $reportProgress('Menghubungi OLT', 0);
            $pending = $this->loadPendingSnapshot($userId, $rowId);
            if ($pending !== null) {
                $this->applyBufferedSnapshot($userId, $rowId, $olt, $pending, $recordChanges);
                if (!$driver instanceof OltOnuEthernetFastStatusAware) {
                    $this->invalidateOnuEthernetStatuses($userId, $olt);
                }
                $this->clearPendingSnapshot($userId, $rowId);
                $this->clearConfigChangeMarker($userId, $rowId, $olt, $configChangePending);
                return ['ok'=>true,'replayed_buffer'=>true,'message'=>'Sinkronisasi tertunda telah selesai disimpan.'];
            }
            $system = $driver->test();
            $deviceReachable = !empty($system['ok']);
            if (!$deviceReachable) {
                throw new RuntimeException((string)($system['message'] ?? 'Perangkat tidak merespons SNMP.'));
            }
            if (method_exists($driver, 'beginSyncReadCycle')) $driver->beginSyncReadCycle();
            // Keterjangkauan SNMP tidak bergantung pada keberhasilan pembacaan inventaris.
            $this->snapshotWriter()->recordConnectionProbe($rowId, $userId, 'snmp', true);
            $systemSnapshot = [];
            if ($deviceReachable) {
                $this->syncPonPorts($userId, $rowId, $recordChanges, $deadlineAt, [], false, true, $driver);
                $cardTemperatures = [];
                if (method_exists($driver, 'readCardTemperatures')) {
                    try {
                        $cardTemperatures = $driver->readCardTemperatures();
                    } catch (Throwable) {
                        $cardTemperatures = [];
                    }
                }
                $this->refreshDeviceTimeSnapshot($userId, $rowId, $driver, $recordChanges);
                $this->refreshDeviceUptimeSnapshot($userId, $rowId, $driver, $recordChanges);
                if ($driver instanceof OltCliOpticalAware) {
                    $cliNotice = trim((string)$driver->cliOpticalMessage());
                    if (stripos($cliNotice, 'Telnet tidak dapat terhubung') !== false) {
                        $this->repository->logApplication($userId, 'OLT CLI', 'telnet_connection', 'warning', 'Telnet tidak dapat terhubung, silahkan hubungkan ulang.');
                    }
                }
                if ((int)($olt['hybrid_status'] ?? 0) === 1) $this->recordSecondaryConnectionStatus($userId, $olt);
                if ($completeInventory) $this->syncCardInventory($userId, $olt, $driver);
                if ($cardTemperatures !== []) $this->snapshotWriter()->writeCardTemperatures($userId, (int)$olt['id'], $cardTemperatures);
                
                
            }
            $streamBatches = $this->pipelineEnabled()
                && $driver instanceof OltOnuBatchStreamAware;
            $processedOnus = 0;
            $onBatch = null;
            if ($streamBatches || $reportProgress !== null) {
                $onBatch = function (array $batchRows) use ($userId, $rowId, $olt, $recordChanges, $streamBatches, $reportProgress, $yieldAfterBatch, &$processedOnus, &$deadlineAt): float {
                    if ($batchRows === []) return 0.0;
                    $processedOnus += count($batchRows);
                    if ($reportProgress !== null) $reportProgress('Membaca data ONU', $processedOnus);
                    if (!$streamBatches) return 0.0;
                    $this->repository->enqueuePipelineSnapshot(
                        $userId,
                        $rowId,
                        (int)($olt['olt_id'] ?? $rowId),
                        'snmp_olt',
                        (string)device_driver_type($olt),
                        [
                            'version'=>1,
                            'captured_at'=>date(DATE_ATOM),
                            'record_changes'=>$recordChanges,
                            'system'=>null,
                            'rows'=>array_values($batchRows),
                            'complete_inventory'=>false,
                            'unconfigured_complete'=>false,
                            'lan_configs'=>[],
                            '_name'=>(string)($olt['name'] ?? ''),
                            '_type'=>'OLT SNMP',
                            'partial'=>true,
                        ]
                    );
                    if ($yieldAfterBatch === null) return 0.0;
                    $paused = max(0.0, (float)$yieldAfterBatch());
                    $deadlineAt += $paused;
                    return $paused;
                };
            }
            if ($reportProgress !== null) $reportProgress('Membaca daftar dan data ONU', 0);
            $onus = $driver->getMonitoringOnus($knownOnus, $deadlineAt, $onBatch, $this->repository->getFastSyncOnuBatchSize());
            if (empty($onus['ok'])) {
                if ($deviceReachable) {
                    $this->logInternalResult($userId, $rowId, 'onu_snapshot_sync', 'error', $onus);
                }
                $syncFailureLogged = true;
                throw new RuntimeException($this->rawInternalResult($onus));
            }
            $this->snapshotWriter()->clearReadFailure($userId, $rowId);
            $processedOnus = max($processedOnus, count((array)($onus['rows'] ?? [])));
            $totalOnus = max($processedOnus, (int)($onus['total'] ?? 0));
            if ($reportProgress !== null) $reportProgress('Membaca data ONU', $processedOnus, $totalOnus);
            $cliLowProcess = $completeInventory ? $this->startParallelCliLow($userId, $rowId, $olt, $driver, (array)($onus['rows'] ?? [])) : null;
            $middleCompleted = false;
            $middleFailure = '';
            $middleRows = [];
            $middleRetryRows = [];
            $middlePonOk = true;
            if ($middleDue && method_exists($driver, 'getMiddleOnus')) {
                if ($reportProgress !== null) $reportProgress('Membaca data lanjutan ONU', $processedOnus);
                $middleDeadlineAt = microtime(true) + $timing['middle_deadline_seconds'];
                $middlePonOk = $this->syncMiddlePonPorts($userId, $olt, $driver, $middleDeadlineAt, $middleFailure);
                try {
                    $middleResult = $driver->getMiddleOnus((array)($onus['rows'] ?? []), $middleDeadlineAt);
                    foreach ((array)($middleResult['rows'] ?? []) as $middleRow) {
                        if (!is_array($middleRow)) continue;
                        $ref = (string)($middleRow['pon'] ?? '') . ':' . (int)($middleRow['onu_id'] ?? 0);
                        $middleRows[$ref] = $middleRow;
                    }
                    $emptyMiddleRows = array_values(array_filter((array)($middleResult['empty_rows'] ?? []), 'is_array'));
                    if ($emptyMiddleRows !== []) {
                        $emptyRefs = array_map(static fn(array $row): string => 'PON ' . (int)($row['pon_number'] ?? 0)
                            . ' ONU ' . (int)($row['onu_id'] ?? 0), $emptyMiddleRows);
                        $this->repository->logApplication($userId, 'OLT', 'middle_sync_empty', 'info',
                            'Middle Sync: detail kosong pada ' . implode(', ', $emptyRefs) . '; bukan kegagalan SNMP.');
                    }
                    if (!empty($middleResult['ok'])) {
                        $middleCompleted = $middlePonOk;
                    } else {
                        $middleRetryRows = array_values(array_filter((array)($middleResult['failed_rows'] ?? []), 'is_array'));
                        $middleFailure .= ($middleFailure !== '' ? '; ' : '') . 'pembacaan detail ONU gagal'
                            . ((int)($middleResult['batch_failures'] ?? 0) > 0 ? ' (' . (int)$middleResult['batch_failures'] . ' ONU)' : '');
                        if (!empty($middleResult['errors']) && is_array($middleResult['errors'])) {
                            $middleFailure .= ': ' . implode('; ', array_map('strval', $middleResult['errors']));
                        }
                    }
                } catch (Throwable $error) {
                    $middleFailure .= ($middleFailure !== '' ? '; ' : '') . $error->getMessage();
                    error_log('Middle Sync ONU failed: ' . $error->getMessage());
                }
            }
            $lanSnapshot = [];
            if ($driver instanceof OltOnuLanControlAware && $driver instanceof OltOnuEthernetFastStatusAware) {
                $knownLanDetails = [];
                foreach ($knownOnus as $knownOnu) {
                    if (!is_array($knownOnu)) continue;
                    $knownLanDetails[(string)($knownOnu['onu_index'] ?? '')] = (string)($knownOnu['detail_json'] ?? '');
                }
                $lanRows = [];
                foreach ((array)($onus['rows'] ?? []) as $onuRow) {
                    if (!is_array($onuRow)) continue;
                    $ref = (string)($onuRow['pon'] ?? '') . ':' . (int)($onuRow['onu_id'] ?? 0);
                    if (isset($middleRows[$ref]['port_count'])) {
                        $onuRow['port_count'] = $middleRows[$ref]['port_count'];
                    }
                    if ((int)($onuRow['port_count'] ?? 0) < 2 && empty($onuRow['detail_json'])) {
                        $onuRow['detail_json'] = $knownLanDetails[$ref] ?? '';
                    }
                    $lanRows[] = $onuRow;
                }
                try {
                    $lanSnapshot = $driver->readOnuEthernetStatusesSnmp($lanRows, $deadlineAt);
                } catch (Throwable $error) {
                    error_log('ONU Ethernet SNMP status sync failed: ' . $error->getMessage());
                }
                $lanStatusRead = !empty($lanSnapshot['ok']);
                if (!$lanStatusRead) $this->invalidateOnuEthernetStatuses($userId, $olt);
            }
            if ($driver instanceof OltOnuEthernetAware) {
                $fastLanRows = [];
                foreach ((array)($onus['rows'] ?? []) as $onuRow) {
                    if (!is_array($onuRow) || !empty($onuRow['missing'])) continue;
                    $ref = trim((string)($onuRow['pon'] ?? '')) . ':' . (int)($onuRow['onu_id'] ?? 0);
                    if ($ref !== ':' && (int)($onuRow['onu_id'] ?? 0) > 0) $fastLanRows[] = ['onu_index'=>$ref];
                }
                try {
                    $lanSnapshot = $driver->readOnuEthernetStatusesSnmp($fastLanRows, $deadlineAt);
                    $lanStatusRead = !empty($lanSnapshot['ok']);
                    if ($lanStatusRead) $lanSnapshot['scope_refs'] = array_column($fastLanRows, 'onu_index');
                } catch (Throwable $error) {
                    error_log('ONU Ethernet SNMP status sync failed: ' . $error->getMessage());
                    $lanStatusRead = false;
                }
            }
            $parallelLow = $cliLowProcess !== null ? $this->finishParallelCliLow($cliLowProcess) : null;
            $cliLowProcess = null;
            $parallelLowOk = $parallelLow === null || !empty($parallelLow['ok']);
            if ($reportProgress !== null) $reportProgress('Membaca layanan dan port ONU', $processedOnus);
            if ($middleDue) $this->syncMiddleHealth($userId, $rowId, $olt, $driver);
            $lowEthernet = ['ports'=>[], 'counts'=>[]];
            if ($completeInventory) {
                if ($parallelLow === null) $this->syncProfileInventory($userId, $olt, $driver);
                $systemSnapshot = $this->readSystemInfoSnapshot($driver, $olt);
                if ($parallelLow === null && $driver instanceof OltOnuEthernetAware) {
                    $lowEthernet = $this->collectOnuEthernetPorts($driver, (array)($onus['rows'] ?? []));
                } elseif ($parallelLow !== null) {
                    $lowEthernet = (array)($parallelLow['ethernet'] ?? $lowEthernet);
                }
            }
            if (!$lanStatusRead && !($driver instanceof OltOnuLanControlAware && $driver instanceof OltOnuEthernetFastStatusAware)) {
                $this->invalidateOnuEthernetStatuses($userId, $olt);
            }
            
            
            
            $lowTypeRows = [];
            $lowDetails = [];
            if ($deviceReachable && $completeInventory) {
                $lowResult = $parallelLow !== null
                    ? $parallelLow
                    : $this->syncOnuServiceConfigs($userId, $olt, $driver, 'discovery', (array)($onus['rows'] ?? []));
                $assignedTypes = (array)($lowResult['assigned_types'] ?? []);
                $lowDetails = (array)($lowResult['details'] ?? []);
                $knownSerials = [];
                foreach ($knownOnus as $knownOnu) {
                    if (!is_array($knownOnu)) continue;
                    $knownSerials[(string)($knownOnu['onu_index'] ?? '')] = strtoupper(trim((string)($knownOnu['onu_serial'] ?? '')));
                }
                foreach ($onus['rows'] as $onuRow) {
                    if (!is_array($onuRow)) continue;
                    $ref = trim((string)($onuRow['pon'] ?? '')) . ':' . (int)($onuRow['onu_id'] ?? 0);
                    $assigned = $assignedTypes[$ref] ?? null;
                    if (!is_array($assigned)) continue;
                    $serial = strtoupper(trim((string)($onuRow['serial'] ?? $middleRows[$ref]['serial'] ?? $middleRows[$ref]['mac'] ?? $knownSerials[$ref] ?? '')));
                    if ($serial !== '' && $serial !== (string)$assigned['serial']) continue;
                    $lowTypeRows[] = ['onu_index'=>$ref, 'onu_type'=>(string)$assigned['type'], 'assignment_serial'=>(string)$assigned['serial']];
                }
            }
            $stats = is_array($onus['stats'] ?? null) ? $onus['stats'] : $onus;
            $completeInventory = !empty($onus['complete_inventory']) && (int)($stats['batch_failures'] ?? 0) === 0;
            $forcedPhasesComplete = !$forceFullInventory || (
                $completeInventory
                && (!$middleDue || !method_exists($driver, 'getMiddleOnus') || $middleCompleted)
                && (!$discoveryRequested || $parallelLowOk)
            );
            $retryMiddleOnly = $forceFullInventory && $completeInventory && $middlePonOk
                && !$middleCompleted && $parallelLowOk && $middleRetryRows !== [];
            $incompletePhases = [];
            if (!$completeInventory) {
                $details = [];
                if (array_key_exists('reference_scan_complete', $stats) && !$stats['reference_scan_complete']) {
                    $details[] = 'daftar ONU gagal dibaca penuh: ' . trim((string)($stats['reference_scan_error'] ?? 'penyebab tidak dilaporkan driver'));
                }
                if ((int)($stats['read_failures'] ?? 0) > 0) $details[] = (int)$stats['read_failures'] . ' ONU gagal dibaca';
                if ((int)($stats['batch_failures'] ?? 0) > 0) $details[] = (int)$stats['batch_failures'] . ' permintaan SNMP gagal';
                if ((int)($stats['skipped_targets'] ?? 0) > 0) $details[] = (int)$stats['skipped_targets'] . ' ONU tidak menghasilkan observasi status valid';
                if ($details === []) $details[] = trim((string)($onus['message'] ?? 'hasil inventaris tidak lengkap'));
                $incompletePhases[] = 'Fast Sync: ' . implode(', ', $details);
            }
            if ($middleDue && method_exists($driver, 'getMiddleOnus') && !$middleCompleted) {
                $incompletePhases[] = 'Middle Sync: ' . ($middleFailure !== '' ? $middleFailure : 'hasil fase belum lengkap');
            }
            if ($discoveryRequested && !$parallelLowOk) {
                $incompletePhases[] = 'Low Sync: ' . trim((string)($parallelLow['message'] ?? 'pembacaan CLI gagal'));
            }
            $incompleteMessage = 'Sinkronisasi penuh belum lengkap: ' . implode('; ', $incompletePhases) . '. Akan dicoba ulang.';
            $snapshot = [
                'version'=>1,
                'captured_at'=>date(DATE_ATOM),
                'record_changes'=>$recordChanges,
                'system'=>$systemSnapshot !== [] ? $systemSnapshot : null,
                'rows'=>array_values((array)($onus['rows'] ?? [])),
                'middle_rows'=>array_values($middleRows),
                'low_type_rows'=>$lowTypeRows,
                'low_ethernet'=>$lowEthernet,
                'low_details'=>$lowDetails,
                'complete_inventory'=>$completeInventory,
                'unconfigured_complete'=>!empty($onus['unconfigured_complete']),
                'middle_completed'=>$middleCompleted,
                'low_completed'=>$discoveryRequested && $completeInventory && $parallelLowOk,
                'lan_statuses'=>$lanStatusRead && $lanSnapshot !== [] ? $lanSnapshot : [],
                'lan_configs'=>(array)($lanSnapshot['configs'] ?? []),
            ];

            if ($this->pipelineEnabled()) {
                $this->repository->markPipelineSeen($userId, $rowId);
                $snapshot['_name'] = (string)($olt['name'] ?? '');
                $snapshot['_type'] = 'OLT SNMP';
                if ($streamBatches) {
                    if ($middleRows !== []) {
                        $this->repository->enqueuePipelineSnapshot(
                            $userId, $rowId, (int)($olt['olt_id'] ?? $rowId), 'snmp_olt',
                            (string)device_driver_type($olt),
                            [
                                'version'=>1,
                                'captured_at'=>date(DATE_ATOM),
                                'record_changes'=>$recordChanges,
                                'system'=>null,
                                'middle_rows'=>array_values($middleRows),
                                'middle_only'=>true,
                                'complete_inventory'=>false,
                                'unconfigured_complete'=>false,
                                'partial'=>true,
                                '_name'=>(string)($olt['name'] ?? ''),
                                '_type'=>'OLT SNMP',
                            ]
                        );
                    }
                    $seenIndexes = [];
                    foreach ((array)($onus['rows'] ?? []) as $streamedRow) {
                        if (!is_array($streamedRow)) continue;
                        $seenIndex = trim((string)($streamedRow['pon'] ?? '')) . ':' . (int)($streamedRow['onu_id'] ?? 0);
                        if ($seenIndex !== ':' && (int)($streamedRow['onu_id'] ?? 0) > 0) $seenIndexes[$seenIndex] = true;
                    }
                    $this->repository->enqueuePipelineSnapshot(
                        $userId,
                        $rowId,
                        (int)($olt['olt_id'] ?? $rowId),
                        'snmp_olt',
                        (string)device_driver_type($olt),
                        [
                            'version'=>1,
                            'captured_at'=>date(DATE_ATOM),
                            'record_changes'=>$recordChanges,
                            'system'=>$systemSnapshot !== [] ? $systemSnapshot : null,
                            'rows'=>[],
                            'complete_inventory'=>$completeInventory,
                            'unconfigured_complete'=>!empty($onus['unconfigured_complete']),
                            'middle_completed'=>$middleCompleted,
                            'low_completed'=>$discoveryRequested && $completeInventory && $parallelLowOk,
                            'low_type_rows'=>$lowTypeRows,
                            'low_ethernet'=>$lowEthernet,
                            'low_details'=>$lowDetails,
                            'seen_indexes'=>array_keys($seenIndexes),
                            'lan_statuses'=>$lanStatusRead && $lanSnapshot !== [] ? $lanSnapshot : [],
                            'lan_configs'=>(array)($lanSnapshot['configs'] ?? []),
                            'finish_only'=>true,
                            'total_onus'=>(int)($onus['total'] ?? count((array)($onus['rows'] ?? []))),
                            '_name'=>(string)($olt['name'] ?? ''),
                            '_type'=>'OLT SNMP',
                        ]
                    );
                    $this->clearConfigChangeMarker($userId, $rowId, $olt, $configChangePending);
                    return [
                        'ok'=>$forcedPhasesComplete,
                        'queued'=>true,
                        'message'=>$forcedPhasesComplete ? 'Data OLT sedang diperbarui.' : $incompleteMessage,
                        'error'=>$forcedPhasesComplete ? '' : $incompleteMessage,
                        'total'=>(int)($onus['total'] ?? 0),
                        'retry_middle_rows'=>$retryMiddleOnly ? $middleRetryRows : [],
                    ];
                }
                $this->repository->enqueuePipelineSnapshot(
                    $userId,
                    $rowId,
                    (int)($olt['olt_id'] ?? $rowId),
                    'snmp_olt',
                    (string)device_driver_type($olt),
                    $snapshot
                );
                $this->clearConfigChangeMarker($userId, $rowId, $olt, $configChangePending);
                return [
                    'ok'=>$forcedPhasesComplete,
                    'queued'=>true,
                    'message'=>$forcedPhasesComplete ? 'Data OLT sedang diperbarui.' : $incompleteMessage,
                    'error'=>$forcedPhasesComplete ? '' : $incompleteMessage,
                    'total'=>(int)($onus['total'] ?? 0),
                    'retry_middle_rows'=>$retryMiddleOnly ? $middleRetryRows : [],
                ];
            }

            if ($reportProgress !== null) $reportProgress('Menyimpan hasil pembacaan', $processedOnus, $totalOnus);
            $this->storePendingSnapshot($userId, $rowId, $snapshot);
            $this->applyBufferedSnapshot($userId, $rowId, $olt, $snapshot, $recordChanges);
            $this->clearPendingSnapshot($userId, $rowId);
            $this->clearConfigChangeMarker($userId, $rowId, $olt, $configChangePending);

            return [
                'ok'=>$forcedPhasesComplete,
                'message'=>$forcedPhasesComplete ? (string)($onus['message'] ?? 'Data OLT berhasil diperbarui.') : $incompleteMessage,
                'error'=>$forcedPhasesComplete ? '' : $incompleteMessage,
                'total'=>(int)($onus['total'] ?? 0),
                'retry_middle_rows'=>$retryMiddleOnly ? $middleRetryRows : [],
            ];
        } catch (Throwable $e) {
            if (!$lanStatusRead && !($completeInventory && $driver instanceof OltOnuEthernetFastStatusAware)) {
                $this->invalidateOnuEthernetStatuses($userId, $olt);
            }
            $deviceDown = $deviceReachable === false;
            if (!$syncFailureLogged) {
                error_log((string)$e);
                $this->snapshotWriter()->recordReadFailure($userId, $rowId, 'OLT SNMP', (string)$e);
            }
            $newFailure = $this->snapshotWriter()->markSyncFailed(
                $userId,
                (int)$olt['olt_id'],
                $e->getMessage(),
                $recordChanges,
                $deviceDown
            );
            return [
                'ok'=>false,
                'device_down'=>$deviceDown,
                'message'=>$deviceDown
                    ? 'OLT tidak dapat dijangkau.'
                    : 'Pembaruan gagaal dilakukan',
                'error'=>$e->getMessage(),
                'new_failure'=>$newFailure,
            ];
        } finally {
            if ($cliLowProcess !== null) $this->stopParallelCliLow($cliLowProcess);
            if (method_exists($driver, 'endSyncReadCycle')) {
                try {
                    $driver->endSyncReadCycle();
                } catch (Throwable) {
                }
            }
            flock($syncLock, LOCK_UN);
            fclose($syncLock);
        }
    }

    public function retryMiddleOnus(int $userId, int $rowId, string $expectedDriverId, array $retryRows, ?callable $reportProgress = null): array
    {
        if ($expectedDriverId === '' || $retryRows === []) {
            return ['ok'=>false, 'retry_invalid'=>true, 'message'=>'Daftar ONU untuk retry tidak tersedia.'];
        }
        foreach ($retryRows as $retryRow) {
            if (!is_array($retryRow) || (int)($retryRow['pon_number'] ?? 0) < 1 || (int)($retryRow['onu_id'] ?? 0) < 1) {
                return ['ok'=>false, 'retry_invalid'=>true, 'message'=>'Daftar ONU untuk retry tidak valid.'];
            }
        }
        $olt = $this->repository->getOltByRowId($userId, $rowId);
        if (!is_array($olt) || !monitoring_row_uses_handler($olt, 'snmp_olt')
            || (string)device_driver_type($olt) !== $expectedDriverId
            || !empty($this->repository->getDeviceConfigChangeState($userId, $rowId)['pending'])
            || !method_exists($this->driverForOlt($olt), 'getMiddleOnus')) {
            return ['ok'=>false, 'retry_invalid'=>true, 'message'=>'Kondisi OLT berubah; sinkronisasi penuh diperlukan.'];
        }
        if ($this->repository->hasPendingOltPipeline($userId, $rowId)) {
            return ['ok'=>false, 'error'=>'Penulisan hasil sinkronisasi sebelumnya masih berjalan.', 'retry_middle_rows'=>$retryRows];
        }
        $lockPath = lock_path('snmp-olt-sync-' . $userId . '-' . $rowId . '.lock');
        ensure_data_directory(lock_path());
        [$syncLock, $lockError] = $this->openSyncLock($lockPath);
        if (!is_resource($syncLock)) {
            return ['ok'=>false, 'error'=>'Lock sinkronisasi tidak dapat dibuka: ' . (string)$lockError, 'retry_middle_rows'=>$retryRows];
        }
        if (!flock($syncLock, LOCK_EX | LOCK_NB)) {
            fclose($syncLock);
            return ['ok'=>false, 'error'=>'Sinkronisasi OLT lain sedang berjalan.', 'retry_middle_rows'=>$retryRows];
        }
        try {
            $driver = $this->driverForOlt($olt);
            $timing = $this->repository->getSyncTimingSettings($userId);
            if ($reportProgress !== null) $reportProgress('Mengulang detail ' . count($retryRows) . ' ONU', 0);
            $result = $driver->getMiddleOnus($retryRows, microtime(true) + $timing['middle_deadline_seconds']);
            $rows = array_values(array_filter((array)($result['rows'] ?? []), 'is_array'));
            if ($rows !== []) $this->onuWriter()->writeMiddleDetails($userId, (int)$olt['olt_id'], $rows);
            $emptyRows = array_values(array_filter((array)($result['empty_rows'] ?? []), 'is_array'));
            if ($emptyRows !== []) {
                $emptyRefs = array_map(static fn(array $row): string => 'PON ' . (int)($row['pon_number'] ?? 0)
                    . ' ONU ' . (int)($row['onu_id'] ?? 0), $emptyRows);
                $this->repository->logApplication($userId, 'OLT', 'middle_sync_empty', 'info',
                    'Middle Sync: detail kosong pada ' . implode(', ', $emptyRefs) . '; bukan kegagalan SNMP.');
            }
            $remaining = array_values(array_filter((array)($result['failed_rows'] ?? []), 'is_array'));
            if ($reportProgress !== null) $reportProgress('Detail ONU diperiksa', max(0, count($retryRows) - count($remaining)));
            if (!empty($result['ok'])) {
                @touch(cache_path('olt-middle-' . $userId . '-' . $rowId . '.stamp'));
                return ['ok'=>true, 'message'=>'Detail ONU yang gagal telah dibaca ulang.'];
            }
            $details = implode('; ', array_map('strval', (array)($result['errors'] ?? [])));
            return ['ok'=>false, 'error'=>'Middle Sync: ' . ($details !== '' ? $details : (string)($result['message'] ?? 'pembacaan detail ONU gagal')),
                'retry_middle_rows'=>$remaining !== [] ? $remaining : $retryRows];
        } catch (Throwable $error) {
            if (str_contains($error->getMessage(), 'ONU Middle belum tersedia dari Fast Sync')) {
                return ['ok'=>false, 'retry_invalid'=>true, 'error'=>$error->getMessage()];
            }
            return ['ok'=>false, 'error'=>$error->getMessage(), 'retry_middle_rows'=>$retryRows];
        } finally {
            flock($syncLock, LOCK_UN);
            fclose($syncLock);
        }
    }

    private function startParallelCliLow(int $userId, int $rowId, array $olt, OltSnmpDriver $driver, array $onuRows): ?array
    {
        if (PHP_SAPI !== 'cli' || trim((string)(getenv('LINNMS_WORKER_SLOT') ?: '')) === ''
            || !$driver instanceof OltParallelCliLowAware || !$this->cliEnabledNow($olt)
            || !function_exists('proc_open')) return null;
        $logPath = shared_log_path('sync-cli-low.log');
        ensure_data_directory(dirname($logPath));
        $pipes = [];
        $process = @proc_open(
            [PHP_BINARY, project_path('workers', 'olt-cli-low.php'), (string)$userId, (string)$rowId],
            [0=>['pipe','r'], 1=>['pipe','w'], 2=>['file',$logPath,'a']],
            $pipes
        );
        if (!is_resource($process)) return null;
        $lowRows = [];
        foreach ($onuRows as $row) {
            if (!is_array($row)) continue;
            $lowRows[] = [
                'pon'=>$row['pon'] ?? '',
                'onu_id'=>$row['onu_id'] ?? 0,
                'status'=>$row['status'] ?? '',
                'missing'=>!empty($row['missing']),
            ];
        }
        $input = json_encode($lowRows, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $length = strlen($input);
        for ($offset = 0; $offset < $length;) {
            $written = fwrite($pipes[0], substr($input, $offset));
            if ($written === false || $written === 0) break;
            $offset += $written;
        }
        fclose($pipes[0]);
        if ($offset !== $length) {
            fclose($pipes[1]);
            proc_terminate($process);
            proc_close($process);
            return null;
        }
        return ['process'=>$process, 'stdout'=>$pipes[1]];
    }

    private function finishParallelCliLow(array $task): array
    {
        $output = stream_get_contents($task['stdout']);
        fclose($task['stdout']);
        $exitCode = proc_close($task['process']);
        $result = json_decode((string)$output, true);
        if ($exitCode === 0 && is_array($result) && !empty($result['ok'])) return $result;
        error_log('Low Sync CLI subprocess failed: exit=' . $exitCode . '; output=' . substr((string)$output, 0, 300));
        return ['ok'=>false, 'message'=>trim((string)($result['message'] ?? 'Proses CLI gagal (exit ' . $exitCode . ')')),
            'assigned_types'=>[], 'ethernet'=>['ports'=>[], 'counts'=>[]], 'details'=>[]];
    }

    private function stopParallelCliLow(array $task): void
    {
        if (is_resource($task['process'] ?? null)) proc_terminate($task['process']);
        if (is_resource($task['stdout'] ?? null)) fclose($task['stdout']);
        if (is_resource($task['process'] ?? null)) proc_close($task['process']);
    }

    public function runParallelCliLow(int $userId, int $rowId, array $onuRows = []): array
    {
        $olt = $this->repository->getOltByRowId($userId, $rowId);
        if (!is_array($olt) || !$this->driverAllowed($olt) || (int)($olt['hybrid_status'] ?? 0) !== 1) {
            return ['ok'=>false, 'message'=>'OLT CLI tidak tersedia.'];
        }
        $driver = $this->driverForOlt($olt);
        if (!$driver instanceof OltParallelCliLowAware) return ['ok'=>false, 'message'=>'Driver CLI tidak tersedia.'];
        try {
            $driver->beginSyncReadCycle();
            $this->syncProfileInventory($userId, $olt, $driver);
            $ethernet = $this->collectOnuEthernetPorts($driver, $onuRows);
            $service = $this->syncOnuServiceConfigs($userId, $olt, $driver, 'discovery', $onuRows);
            return ['ok'=>true, 'assigned_types'=>$service['assigned_types'], 'ethernet'=>$ethernet, 'details'=>$service['details']];
        } catch (Throwable $error) {
            error_log('Low Sync CLI subprocess error: ' . $error->getMessage());
            return ['ok'=>false, 'message'=>$error->getMessage()];
        } finally {
            try {
                $driver->endSyncReadCycle();
            } catch (Throwable $error) {
                error_log('Low Sync CLI read cycle close failed: ' . $error->getMessage());
            }
        }
    }

    public function refreshFullOltView(int $userId, int $rowId): array
    {
        $olt = $this->repository->getOltByRowId($userId, $rowId);
        $definition = is_array($olt)
            ? monitoring_driver_definition((string)device_driver_type($olt))
            : null;
        if (!is_array($olt) || !monitoring_row_uses_handler($olt, 'snmp_olt')
            || !is_array($definition) || (string)($definition['view_refresh_path'] ?? '') !== 'full') {
            return ['ok' => false, 'message' => 'Pembaruan detail belum didukung perangkat ini.'];
        }

        $lockPath = lock_path('snmp-olt-sync-' . $userId . '-' . $rowId . '.lock');
        ensure_data_directory(lock_path());
        [$syncLock, $lockError] = $this->openSyncLock($lockPath);
        if (!is_resource($syncLock)) {
            return [
                'ok' => false,
                'message' => 'Sinkron tidak dapat dimulai. Coba lagi.',
                'error' => (string)$lockError,
            ];
        }
        if (!flock($syncLock, LOCK_EX | LOCK_NB)) {
            fclose($syncLock);
            return [
                'ok' => false,
                'busy' => true,
                'message' => 'Perangkat sedang disinkronkan. Coba lagi sebentar.',
            ];
        }

        $warnings = [];
        $updated = [];
        try {
            if ($this->repository->hasActiveOltScanRequest($userId, $rowId)
                || $this->repository->hasPendingOltPipeline($userId, $rowId)) {
                return ['ok'=>false, 'busy'=>true, 'message'=>'Perangkat sedang disinkronkan. Coba lagi sebentar.'];
            }
            $this->writeSyncLockMetadata($syncLock, $userId, $rowId, microtime(true));
            $driver = $this->driverForOlt($olt);
            $connection = $driver->test();
            if (empty($connection['ok'])) {
                $error = raw_result_error($connection, 'OltFullView');
                $this->snapshotWriter()->markSyncFailed(
                    $userId,
                    (int)$olt['olt_id'],
                    $error,
                    true,
                    true
                );
                return [
                    'ok' => false,
                    'message' => 'Perangkat tidak merespons. Coba lagi sebentar.',
                    'error' => $error,
                ];
            }

            $systemResult = $driver->getSystemInfo();
            if (!empty($systemResult['ok'])) {
                $freshSystem = array_filter(
                    $this->mapSystem($systemResult, $olt),
                    static fn($value) => $value !== null && $value !== ''
                );
                if (!array_key_exists('vendor', $freshSystem)) $freshSystem['vendor'] = null;
                if ($this->snapshotWriter()->mergeSystemFields($userId, $rowId, $freshSystem, ['vendor'])) {
                    $updated[] = 'informasi sistem';
                } else {
                    $warnings[] = 'Informasi sistem gagal disimpan.';
                }
            } else {
                $warnings[] = 'Informasi sistem tidak dapat dibaca.';
            }

            $ponResult = $this->syncPonPorts($userId, $rowId, true, null, [], true, false, $driver);
            if (!empty($ponResult['ok'])) {
                $updated[] = 'status PON, card, dan profil';
            } else {
                $warnings[] = (string)($ponResult['message'] ?? 'Data PON tidak dapat diperbarui.');
            }

            if (method_exists($driver, 'readCardTemperatures')) {
                try {
                    $temperatures = $driver->readCardTemperatures();
                    if ($temperatures !== []) $this->snapshotWriter()->writeCardTemperatures($userId, (int)$olt['id'], $temperatures);
                } catch (Throwable $error) {
                    $warnings[] = 'Suhu card tidak dapat dibaca.';
                }
            }
            if ($driver instanceof OltFanAware || method_exists($driver, 'readCardTelemetry')) {
                $this->syncMiddleHealth($userId, $rowId, $olt, $driver);
            }
            if ((int)($olt['hybrid_status'] ?? 0) === 1) $this->recordSecondaryConnectionStatus($userId, $olt);

            return [
                'ok' => $updated !== [],
                'partial' => $warnings !== [],
                'message' => $updated !== [] ? 'Sinkron berhasil.' : 'Sinkron gagal.',
                'warnings' => $warnings,
                'pon_ports' => (int)($ponResult['ports'] ?? 0),
            ];
        } catch (Throwable $error) {
            return [
                'ok' => false,
                'message' => 'Sinkron gagal.',
                'error' => (string)$error,
            ];
        } finally {
            flock($syncLock, LOCK_UN);
            fclose($syncLock);
        }
    }
    public function saveOltVlan(int $userId, int $rowId, int $vlanId, string $name): array
    {
        return $this->writeOltVlan($userId, $rowId, $vlanId, $name, false);
    }

    public function deleteOltVlan(int $userId, int $rowId, int $vlanId): array
    {
        return $this->writeOltVlan($userId, $rowId, $vlanId, '', true);
    }

    
    public function saveOltProfileEntry(int $userId, int $oltRowId, string $kind, string $name, int $vlanId = 0, array $data = []): array
    {
        $olt = $this->repository->getOltByRowId($userId, $oltRowId);
        if (!is_array($olt)) return ['ok' => false, 'message' => 'OLT tidak ditemukan.'];
        $kind = trim($kind);
        $name = trim($name);
        if (!in_array($kind, ['vlan', 'onu_type'], true)) {
            return ['ok' => false, 'message' => 'Jenis profil tidak dikenal.'];
        }
        if ($kind === 'vlan') {
            if ($vlanId < 1) $vlanId = (int)preg_replace('/\D+/', '', $name);
            if ($vlanId < 1 || $vlanId > 4095) {
                return ['ok' => false, 'message' => 'ID VLAN harus antara 1 sampai 4095.'];
            }
            if (mb_strlen($name) > 32) return ['ok' => false, 'message' => 'Nama VLAN maksimal 32 karakter.'];
            if ($this->oltDriverSupports($olt, OltVlanAware::class)) {
                $written = $this->saveOltVlan($userId, $oltRowId, $vlanId, $name);
                if (!empty($written['ok'])) $written['device_written'] = true;
                return $written;
            }
            if (!$this->configWriter()->writeVlanDefinition($userId, (int)($olt['id'] ?? 0), $vlanId, $name)) {
                return ['ok' => false, 'message' => 'VLAN gagal disimpan.'];
            }
            return ['ok' => true, 'device_written' => false, 'vlan_id' => $vlanId,
                'message' => 'VLAN ' . $vlanId . ' berhasil disimpan.'];
        }

        $supported = $this->oltDriverSupports($olt, OltOnuTypeAware::class);
        $defaultScope = $supported ? 'device' : 'local';
        $scope = (string)($data['profile_scope'] ?? $defaultScope);
        if (!in_array($scope, ['local', 'device'], true)) return ['ok' => false, 'message' => 'ONU Type tidak valid.'];
        if ($scope === 'device') {
            if (!$supported) return ['ok' => false, 'message' => 'Perubahan ONU Type belum tersedia.'];
            return $this->writeOltOnuType($userId, $olt, $name, $data, false);
        }
        $operation = (string)($data['operation'] ?? 'create');
        $pon = (string)($data['pon_type'] ?? 'gpon');
        $description = trim((string)($data['description'] ?? ''));
        if (!in_array($operation, ['create', 'edit'], true) || !in_array($pon, ['gpon', 'epon'], true)
            || mb_strlen($description) > 255) return ['ok' => false, 'message' => 'ONU Type tidak valid.'];
        $catalogData = ['pon_type' => $pon, 'description' => $description];
        foreach (['ethernet' => 'ethernet_port', 'wifi' => 'wifi_ssid', 'pots' => 'pots_port'] as $feature => $field) {
            $value = trim((string)($data[$field] ?? ''));
            if (mb_strlen($value) > 64) return ['ok' => false, 'message' => 'Nilai ONU Type terlalu panjang.'];
            $catalogData[$field] = $value;
            $catalogData[$feature . '_enabled'] = $value !== '' ? 1 : 0;
        }
        try {
            $ok = $this->configWriter()->writeOnuTypeDefinition($userId, (int)$olt['id'], $name, $catalogData, $operation);
            return ['ok' => $ok, 'device_written' => false, 'message' => $ok ? 'ONU Type tersimpan.' : 'ONU Type gagal disimpan.'];
        } catch (InvalidArgumentException $error) {
            return ['ok' => false, 'message' => $error->getMessage()];
        }

    }

    
    public function deleteOltProfileEntry(int $userId, int $oltRowId, string $kind, string $name, int $vlanId = 0, ?string $scope = null): array
    {
        $olt = $this->repository->getOltByRowId($userId, $oltRowId);
        if (!is_array($olt)) return ['ok' => false, 'message' => 'OLT tidak ditemukan.'];
        $kind = trim($kind);
        if (!in_array($kind, ['vlan', 'onu_type'], true)) {
            return ['ok' => false, 'message' => 'Jenis profil tidak dikenal.'];
        }
        if ($kind === 'vlan') {
            if ($vlanId < 1) $vlanId = (int)preg_replace('/\D+/', '', trim($name));
            if ($vlanId < 1) return ['ok' => false, 'message' => 'ID VLAN tidak valid.'];
            if ($this->oltDriverSupports($olt, OltVlanAware::class)) {
                $written = $this->deleteOltVlan($userId, $oltRowId, $vlanId);
                if (!empty($written['ok'])) $written['device_written'] = true;
                return $written;
            }
            if (!$this->configWriter()->deleteVlanDefinition($userId, (int)($olt['id'] ?? 0), $vlanId)) {
                return ['ok' => false, 'message' => 'VLAN gagal dihapus.'];
            }
            return ['ok' => true, 'device_written' => false,
                'message' => 'VLAN ' . $vlanId . ' berhasil dihapus.'];
        }

        $supported = $this->oltDriverSupports($olt, OltOnuTypeAware::class);
        $scope = $scope ?? ($supported ? 'device' : 'local');
        if (!in_array($scope, ['local', 'device'], true)) return ['ok' => false, 'message' => 'ONU Type tidak valid.'];
        if ($scope === 'device') {
            if (!$supported) return ['ok' => false, 'message' => 'Perubahan ONU Type belum tersedia.'];
            return $this->writeOltOnuType($userId, $olt, trim($name), [], true);
        }
        $ok = $this->configWriter()->deleteOnuTypeDefinition($userId, (int)$olt['id'], trim($name));
        return ['ok' => $ok, 'device_written' => false, 'message' => $ok ? 'ONU Type dihapus.' : 'ONU Type tidak ditemukan.'];

    }

    private function writeOltOnuType(int $userId, array $olt, string $name, array $data, bool $delete): array
    {
        if (!$this->driverAllowed($olt)) return ['ok' => false, 'message' => 'Pemantauan perangkat ini belum diizinkan.'];
        $rowId = (int)$olt['id'];
        ensure_data_directory(lock_path());
        [$lock] = $this->openSyncLock(lock_path('snmp-olt-sync-' . $userId . '-' . $rowId . '.lock'));
        if (!is_resource($lock)) return ['ok' => false, 'message' => 'Gagal melakukan perubahan'];
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return ['ok' => false, 'busy' => true, 'message' => 'Perangkat sedang diproses.'];
        }
        try {
            $this->writeSyncLockMetadata($lock, $userId, $rowId, microtime(true));
            $driver = $this->driverForOlt($olt);
            if (!$driver instanceof OltOnuTypeAware) return ['ok' => false, 'message' => 'Perubahan ONU Type belum tersedia.'];
            if ($driver::WRITE_CONNECTION === 'cli' && $this->cliEndpointForOlt($olt) === null) return ['ok' => false, 'message' => 'Koneksi perangkat belum tersedia.'];
            $result = $delete ? $driver->deleteOnuType($name) : $driver->writeOnuType($name, $data);
            if (is_array($result['rows'] ?? null) && (!empty($result['ok']) || !empty($result['inventory_complete']))) {
                $this->snapshotWriter()->writeProfileSnapshots($userId, $rowId, $result['rows'], ['onu_type']);
            }
            $result['device_written'] = !empty($result['ok']) && !empty($result['changed']);
            if (!empty($result['ok'])) $result['message'] = empty($result['changed']) ? 'Tidak ada perubahan.' : ($delete ? 'ONU Type dihapus.' : 'ONU Type tersimpan.');
            unset($result['raw'], $result['types'], $result['rows']);
            return $result;
        } catch (Throwable $error) {
            error_log('Gagal menulis ONU Type: ' . $error->getMessage());
            return ['ok' => false, 'message' => 'Gagal menulis ONU Type.', 'error' => (string)$error];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function writeOltVlan(int $userId, int $rowId, int $vlanId, string $name, bool $delete): array
    {
        $olt = $this->repository->getOltByRowId($userId, $rowId);
        if (!is_array($olt) || !monitoring_row_uses_handler($olt, 'snmp_olt')) {
            return ['ok' => false, 'message' => 'Tindakan ini tidak tersedia untuk perangkat ini.'];
        }
        if (!$this->driverAllowed($olt)) {
            return ['ok' => false, 'message' => 'Pemantauan perangkat ini belum diizinkan.'];
        }
        if ($vlanId < 1 || $vlanId > 4095) {
            return ['ok' => false, 'message' => 'ID VLAN harus antara 1 sampai 4095.'];
        }
        if ($delete && $vlanId === 1) {
            return ['ok' => false, 'message' => 'VLAN 1 tidak dapat dihapus.'];
        }

        ensure_data_directory(lock_path());
        [$lock] = $this->openSyncLock(lock_path('snmp-olt-sync-' . $userId . '-' . $rowId . '.lock'));
        if (!is_resource($lock)) {
            return ['ok' => false, 'message' => 'Perubahan VLAN tidak dapat dimulai. Coba lagi.'];
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return ['ok' => false, 'busy' => true, 'message' => 'Perangkat sedang diproses. Coba lagi sebentar.'];
        }

        try {
            $this->writeSyncLockMetadata($lock, $userId, $rowId, microtime(true));
            $driver = $this->driverForOlt($olt);
            if (!$driver instanceof OltVlanAware) {
                return ['ok' => false, 'message' => 'Perangkat ini belum mendukung perubahan VLAN.'];
            }
            if ($delete) {
                if (!$driver instanceof OltVlanAware) {
                    return ['ok' => false, 'message' => 'Perangkat ini belum mendukung penghapusan VLAN.'];
                }
                $result = $driver->deleteVlan($vlanId);
            } else {
                if (!$driver instanceof OltVlanAware) {
                    return ['ok' => false, 'message' => 'Perangkat ini belum mendukung penyimpanan VLAN.'];
                }
                $result = $driver->writeVlan($vlanId, $name);
            }

            if (empty($result['ok'])) {
                return [
                    'ok' => false,
                    'message' => trim((string)($result['message'] ?? '')) ?: 'Perubahan VLAN gagal.',
                    'error' => (string)($result['error'] ?? ''),
                ];
            }

            $this->syncProfileInventory($userId, $olt, $driver);
            if ((int)($olt['hybrid_status'] ?? 0) === 1) $this->recordSecondaryConnectionStatus($userId, $olt);
            $this->snapshotWriter()->markMonitoringSync($userId, (string)device_driver_type($olt));

            return [
                'ok' => true,
                'message' => $delete ? 'VLAN berhasil dihapus.' : 'VLAN berhasil disimpan.',
                'vlan_id' => $vlanId,
            ];
        } catch (Throwable $error) {
            return ['ok' => false, 'message' => 'Perubahan VLAN gagal.', 'error' => (string)$error];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function refreshBasicOltView(int $userId, int $rowId): array
    {
        $olt = $this->repository->getOltByRowId($userId, $rowId);
        $definition = is_array($olt) ? monitoring_driver_definition((string)device_driver_type($olt)) : null;
        if (!is_array($olt) || ($definition['view_refresh_path'] ?? '') !== 'basic') {
            return ['ok' => false, 'message' => 'Sinkron tidak tersedia untuk perangkat ini.'];
        }
        if (!$this->driverAllowed($olt)) return ['ok' => false, 'message' => 'Pemantauan perangkat ini belum diizinkan.'];
        ensure_data_directory(lock_path());
        [$lock] = $this->openSyncLock(lock_path('snmp-olt-sync-' . $userId . '-' . $rowId . '.lock'));
        if (!is_resource($lock)) return ['ok' => false, 'message' => 'Sinkron tidak dapat dimulai. Coba lagi.'];
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return ['ok' => false, 'busy' => true, 'message' => 'Perangkat sedang disinkronkan. Coba lagi sebentar.'];
        }
        try {
            if ($this->repository->hasActiveOltScanRequest($userId, $rowId)
                || $this->repository->hasPendingOltPipeline($userId, $rowId)) {
                return ['ok'=>false, 'busy'=>true, 'message'=>'Perangkat sedang disinkronkan. Coba lagi sebentar.'];
            }
            $this->writeSyncLockMetadata($lock, $userId, $rowId, microtime(true));
            $driver = $this->driverForOlt($olt);
            $systemResult = $driver->getSystemInfo();
            if (empty($systemResult['ok'])) {
                return ['ok' => false, 'message' => 'Perangkat tidak merespons. Coba lagi sebentar.', 'error' => raw_result_error($systemResult, 'OltBasicView')];
            }
            $freshSystem = array_filter(
                $this->mapSystem($systemResult, $olt),
                static fn($value) => $value !== null && $value !== ''
            );
            if (!array_key_exists('vendor', $freshSystem)) $freshSystem['vendor'] = null;
            $saved = $this->snapshotWriter()->mergeSystemFields($userId, $rowId, $freshSystem, ['vendor']);
            $timing = $this->repository->getSyncTimingSettings($userId);
            $pon = $this->syncPonPorts($userId, $rowId, true, microtime(true) + $timing['fast_deadline_seconds'], [], true, false, $driver);
            
            if ((int)($olt['hybrid_status'] ?? 0) === 1) $this->recordSecondaryConnectionStatus($userId, $olt);
            
            
            $warnings = [];
            if (!$saved) $warnings[] = 'Sebagian data sistem tidak tersimpan.';
            if (empty($pon['ok'])) $warnings[] = 'Sebagian data port tidak diperbarui.';
            $ok = $saved || !empty($pon['ok']);
            return ['ok' => $ok, 'partial' => $warnings !== [],
                'message' => $ok ? 'Sinkron berhasil.' : 'Sinkron gagal.', 'warnings' => $warnings, 'pon_ports' => (int)($pon['ports'] ?? 0)];
        } catch (Throwable $error) {
            return ['ok' => false, 'message' => 'Sinkron gagal.', 'error' => (string)$error];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function pipelineEnabled(): bool
    {
        return filter_var((string)(getenv('LINNMS_SYNC_PIPELINE') ?: '0'), FILTER_VALIDATE_BOOL);
    }

    public function renameOnu(int $userId, int $onuRowId, string $name, ?string $location = null): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 128) return ['ok'=>false,'message'=>'Nama ONU tidak valid.'];
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if ($onu === null || !monitoring_row_uses_handler($onu,'snmp_olt')) return ['ok'=>false,'message'=>'ONU tidak ditemukan.'];
        $parsed = onu_split_device_label($name);
        $plainName = $parsed['name'] !== '' ? $parsed['name'] : $name;
        if ($location !== null) {
            $effectiveLocation = onu_location_value($location);
        } elseif ($parsed['location'] !== '') {
            $effectiveLocation = $parsed['location'];
        } else {
            $effectiveLocation = onu_location_value($onu['onu_location'] ?? null);
        }
        $deviceLabel = onu_join_device_label($plainName, $effectiveLocation);
        [$pon, $onuId] = $this->onuCoordinates($onu);
        try {
            $driver = $this->newDriver($userId, (string)device_driver_type($onu),(string)$onu['olt_ip_address'], (string)$onu['snmp_community'], (int)$onu['olt_row_id'], (int)($onu['snmp_port'] ?? 161));
            if (!$driver instanceof OltOnuRenameAware) {
                return ['ok'=>false,'message'=>'Driver OLT ini belum mendukung edit nama ONU.'];
            }
            $result = $driver->renameOnu($pon, $onuId, $deviceLabel);
            if (!empty($result['ok'])) {
                try {
                    if (!$this->onuWriter()->writeSnmpName($userId, $onuRowId, $plainName)) {
                        $result['message'] = 'Perintah ubah nama diterima OLT, tetapi data tersimpan tidak diperbarui.';
                        $result['refreshed'] = false;
                    }
                } catch (Throwable $error) {
                    error_log('ONU name snapshot update failed: ' . $error->getMessage());
                    $result['message'] = 'Perintah ubah nama diterima OLT, tetapi data tersimpan tidak diperbarui.';
                    $result['refreshed'] = false;
                }
            }
            return $result;
        } catch (Throwable $e) {
            return ['ok'=>false,'message'=>'Nama ONU gagal diubah.','error'=>(string)$e];
        }
    }


    public function readOnuDetail(int $userId, int $onuRowId): array
    {
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if ($onu === null || !monitoring_row_uses_handler($onu, 'snmp_olt')) {
            return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];
        }
        [$pon, $onuId] = $this->onuCoordinates($onu);
        $oltRowId = (int)($onu['olt_row_id'] ?? 0);
        $olt = $oltRowId > 0 ? $this->repository->getOltByRowId($userId, $oltRowId) : null;
        try {
            $driver = is_array($olt)
                ? $this->driverForOlt($olt)
                : $this->newDriver($userId, (string)device_driver_type($onu), (string)$onu['olt_ip_address'], (string)$onu['snmp_community'], (int)$onu['olt_row_id'], (int)($onu['snmp_port'] ?? 161));
            return $driver->getOnuDetail($pon, $onuId);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Detail ONU gagal dibaca.', 'error' => (string)$e];
        }
    }

    public function prepareOnuRegistration(int $userId, int $onuRowId): array
    {
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if (!is_array($onu) || !monitoring_row_uses_handler($onu, 'snmp_olt')) {
            return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];
        }
        if (trim((string)($onu['onu_serial'] ?? '')) !== '') return ['ok' => true, 'onu' => $onu];
        try {
            $detail = $this->readOnuDetail($userId, $onuRowId);
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'Serial ONU tidak dapat dibaca dari perangkat.'];
        }
        if (empty($detail['ok'])) {
            return ['ok' => false, 'message' => 'Serial ONU tidak dapat dibaca dari perangkat.'];
        }
        $snapshot = is_array($detail['snapshot'] ?? null) ? $detail['snapshot'] : [];
        $serial = '';
        foreach ([$snapshot['serial'] ?? null, $snapshot['mac'] ?? null, $detail['serial'] ?? null, $detail['mac'] ?? null] as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '' && !in_array($candidate, ['—', '-', '_'], true)) {
                $serial = $candidate;
                break;
            }
        }
        if ($serial === '' || !$this->onuWriter()->writeSerialIfMissing($userId, $onuRowId, $serial)) {
            return ['ok' => false, 'message' => 'Serial ONU tidak dapat disimpan.'];
        }
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        return is_array($onu)
            ? ['ok' => true, 'onu' => $onu]
            : ['ok' => false, 'message' => 'ONU tidak ditemukan setelah pembacaan serial.'];
    }


    public function readUplinkDetail(int $userId, int $oltRowId, string $ref): array
    {
        $ref = trim($ref);
        if ($ref === '') return ['ok' => false, 'message' => 'Port uplink tidak valid.'];
        foreach ($this->repository->getOltPorts($userId, $oltRowId, 'uplink') as $port) {
            if (!is_array($port)) continue;
            if (strcasecmp(trim((string)($port['port_ref'] ?? '')), $ref) !== 0) continue;
            return [
                'ok' => true,
                'port_ref' => $ref,
                'config' => is_array($port['config'] ?? null) ? $port['config'] : [],
                'optics' => is_array($port['optics'] ?? null) ? $port['optics'] : [],
                'vlan' => is_array($port['vlan'] ?? null) ? $port['vlan'] : [],
            ];
        }
        return ['ok' => false, 'message' => 'Port uplink tidak ditemukan.'];
    }

    public function refreshOnuLabels(int $userId, int $oltRowId): int
    {
        $olt = $this->repository->getOltByRowId($userId, $oltRowId);
        if (!is_array($olt)) return 0;
        try {
            $driver = $this->driverForOlt($olt);
            if (!$driver instanceof OltOnuLabelAware) return 0;
            $labels = $driver->readOnuLabels();
        } catch (Throwable $error) {
            error_log('ONU label refresh failed: ' . $error->getMessage());
            return 0;
        }
        if ($labels === []) return 0;
        return $this->onuWriter()->writeLabels($userId, (int)$olt['id'], $labels);
    }

    
    public function refreshOnuEthernetPorts(int $userId, int $oltRowId): int
    {
        $olt = $this->repository->getOltByRowId($userId, $oltRowId);
        if (!is_array($olt)) return 0;
        $driver = $this->driverForOlt($olt);
        if (!$driver instanceof OltOnuEthernetAware) {
            return 0;
        }
        $stmt = $this->repository->pdo()->prepare("SELECT id,onu_index FROM onus WHERE user_id=:uid AND olt_device_id=:dev AND onu_index IS NOT NULL AND onu_index<>''");
        $stmt->execute(['uid' => $userId, 'dev' => (int)$olt['id']]);
        $snapshot = $this->collectOnuEthernetPorts($driver, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        if ($snapshot['counts'] === []) return 0;
        return $this->onuWriter()->writeEthernetPorts($userId, (int)$olt['id'], $snapshot['ports'], $snapshot['counts']);
    }

    private function collectOnuEthernetPorts(OltOnuEthernetAware $driver, array $onuRows): array
    {
        $onusPorts = [];
        $countsByIf = [];
        foreach ($onuRows as $row) {
            if (!is_array($row) || !empty($row['missing'])) continue;
            $ref = trim((string)($row['onu_index'] ?? ''));
            if ($ref === '' && isset($row['pon'], $row['onu_id'])) $ref = trim((string)$row['pon']) . ':' . (int)$row['onu_id'];
            if ($ref === '') continue;
            $result = $driver->readOnuEthernetPorts($ref);
            if (empty($result['ok'])) continue;
            $ports = (array)($result['ports'] ?? []);
            foreach ($ports as &$port) {
                if (!is_array($port)) continue;
                unset($port['link_status'], $port['link'], $port['enable']);
            }
            unset($port);
            $parts = explode('/', $ref);
            $ifKey = count($parts) >= 3 ? implode('/', array_slice($parts, 1)) : $ref;
            if ($ports !== []) $onusPorts[(string)$ifKey] = $ports;
            $countsByIf[(string)$ifKey] = count($ports) + 1;
        }
        return ['ports'=>$onusPorts, 'counts'=>$countsByIf];
    }

    public function readOnuEthernetPorts(int $userId, int $onuRowId): array
    {
        $ports = $this->repository->getOnuEthernetPorts($userId, $onuRowId);
        return [
            'ok' => true,
            'message' => $ports === [] ? 'Data port Ethernet belum tersedia.' : count($ports) . ' port Ethernet pada snapshot database.',
            'ports' => $ports,
            'source' => 'database_snapshot',
        ];
    }

    private function refreshOnuEthernetStatusesSnmp(int $userId, array $olt, OltOnuEthernetAware|OltOnuEthernetFastStatusAware $driver, array $knownOnus, ?float $deadlineAt = null): ?int
    {
        try {
            if ($knownOnus === []) {
                $this->invalidateOnuEthernetStatuses($userId, $olt);
                return null;
            }
            $result = $driver->readOnuEthernetStatusesSnmp($knownOnus, $deadlineAt);
            if (empty($result['ok'])) {
                $this->invalidateOnuEthernetStatuses($userId, $olt);
                return null;
            }
            return $this->onuWriter()->writeEthernetStatuses(
                $userId, (int)$olt['id'], (array)$result['ports'],
                (array)($result['scope_refs'] ?? array_column($knownOnus, 'onu_index')),
                !empty($result['dynamic_ports'])
            );
        } catch (Throwable $error) {
            error_log('ONU Ethernet SNMP status sync failed: ' . $error->getMessage());
            $this->invalidateOnuEthernetStatuses($userId, $olt);
            return null;
        }
    }

    private function invalidateOnuEthernetStatuses(int $userId, array $olt): void
    {
        try {
            $driver = $this->driverForOlt($olt);
            if (!$driver instanceof OltOnuEthernetAware && !$driver instanceof OltOnuEthernetFastStatusAware) return;
            $this->onuWriter()->writeEthernetStatuses($userId, (int)$olt['id'], []);
        } catch (Throwable $error) {
            error_log('ONU Ethernet status invalidation failed: ' . $error->getMessage());
        }
    }

    private function saveOnuActionSnapshot(int $userId, array $onu, array $olt, callable $change): bool
    {
        $deviceId = (int)($olt['id'] ?? 0);
        $ref = (string)($onu['onu_index'] ?? '');
        if ($deviceId < 1 || $ref === '') return false;
        try {
            $row = $this->repository->getOnuServiceConfig($userId, $deviceId, $ref);
            $config = is_array($row['config'] ?? null) ? $row['config'] : [];
            $change($config);
            return $this->onuWriter()->writeServiceConfigs($userId, $deviceId, [$ref => $config], 'action') > 0;
        } catch (Throwable $error) {
            error_log('ONU action snapshot update failed: ' . $error->getMessage());
            return false;
        }
    }

    private static function replaceOnuConfigRow(array &$config, string $key, string $idKey, int $index, ?array $value): void
    {
        $rows = array_values(array_filter((array)($config[$key] ?? []), static fn($row): bool =>
            !is_array($row) || (int)($row[$idKey] ?? 0) !== $index));
        if ($value !== null) $rows[] = $value;
        $config[$key] = $rows;
    }

    public function updateOnuLanField(int $userId, int $onuRowId, int $port, string $field, string $value): array
    {
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if (!is_array($onu)) return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];
        $olt = $this->repository->getOltByRowId($userId, (int)($onu['olt_row_id'] ?? 0));
        if (!is_array($olt) || !$this->driverAllowed($olt)) {
            return ['ok' => false, 'message' => 'Pengaturan LAN tidak didukung perangkat ini.'];
        }
        try {
            $driver = $this->driverForOlt($olt);
        } catch (Throwable $error) {
            return ['ok' => false, 'message' => 'Driver port LAN belum tersedia.'];
        }
        if ($driver instanceof OltOnuEthernetAware) {
            return $this->updateOnuEthernetField($userId, $onuRowId, $port, $field, $value);
        }
        if ($driver instanceof OltOnuLanControlAware) {
            return $this->updateOnuLanControl($userId, $onuRowId, $port, $field, $value);
        }
        return ['ok' => false, 'message' => 'Pengaturan port LAN tidak didukung perangkat ini.'];
    }

    public function updateOnuEthernetField(int $userId, int $onuRowId, int $port, string $field, string $value): array
    {
        if ($field === 'settings') {
            $settings = json_decode($value, true);
            if (!is_array($settings) || $settings === [] || count($settings) > 2
                || array_diff(array_keys($settings), ['vlan', 'dhcp']) !== []) {
                return ['ok' => false, 'message' => 'Pengaturan LAN tidak valid.'];
            }
            foreach ($settings as $key => $setting) {
                if (!is_string($setting)) return ['ok' => false, 'message' => 'Pengaturan port LAN tidak valid.'];
                if ($key === 'dhcp' && !in_array($setting, ['no-ctrl', 'from-internet', 'from-onu', 'forbidden'], true)) return ['ok' => false, 'message' => 'DHCP Access tidak valid.'];
                if ($key === 'vlan') {
                    if (preg_match('/^(access|lan|trunk|hybrid):(\d{1,4}):([\d,]*)$/D', $setting, $parts) !== 1) return ['ok' => false, 'message' => 'Mode port tidak valid.'];
                    $mode = $parts[1]; $default = (int)$parts[2];
                    $ids = $parts[3] === '' ? [] : explode(',', $parts[3]);
                    if ((in_array($mode, ['access', 'hybrid'], true) && ($default < 1 || $default > 4094))
                        || (in_array($mode, ['lan', 'trunk'], true) && $default !== 0)
                        || (in_array($mode, ['access', 'lan'], true) && $ids !== [])
                        || (in_array($mode, ['trunk', 'hybrid'], true) && $ids === [])
                        || count($ids) !== count(array_unique($ids))) return ['ok' => false, 'message' => 'VLAN port tidak valid.'];
                    foreach ($ids as $id) if (!ctype_digit($id) || (int)$id < 1 || (int)$id > 4094) return ['ok' => false, 'message' => 'VLAN port tidak valid.'];
                }
            }
            $result = []; $refreshed = true;
            foreach (['vlan', 'dhcp'] as $key) {
                if (!isset($settings[$key])) continue;
                if ($key === 'dhcp' && ($settings['vlan'] ?? '') === 'lan:0:' && $settings[$key] === 'no-ctrl') continue;
                $result = $this->updateOnuEthernetField($userId, $onuRowId, $port, $key, $settings[$key]);
                if (empty($result['ok'])) return $result;
                $refreshed = $refreshed && !empty($result['refreshed']);
            }
            $result['refreshed'] = $refreshed;
            return $result;
        }

        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if (!is_array($onu)) return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];
        $olt = $this->repository->getOltByRowId($userId, (int)($onu['olt_row_id'] ?? 0));
        if (!is_array($olt)) return ['ok' => false, 'message' => 'OLT tidak ditemukan.'];

        try {
            $driver = $this->driverForOlt($olt);
            if (!$driver instanceof OltOnuEthernetAware) return ['ok' => false, 'message' => 'Pengaturan port LAN belum didukung driver ini.'];
            $result = $driver->setOnuEthernetField((string)$onu['onu_index'], $port, $field, $value);
            if (empty($result['ok'])) return ['ok' => false, 'message' => (string)($result['message'] ?? 'Pengaturan port LAN ditolak perangkat.')];
            if (in_array($field, ['vlan', 'dhcp'], true)) {
                $updated = true;
            } else {
                $updated = $this->onuWriter()->writeEthernetField($userId, $onuRowId, $port, $field, $value);
            }
            if ($field === 'enable' && !$updated) {
                return ['ok' => false, 'message' => 'Perangkat menerima perintah, tetapi status interface gagal disimpan ke database.'];
            }
            if ($field === 'vlan') {
                $vlanPort = (array)($result['vlan_port'] ?? []);
                $dhcp = $result['dhcp'] ?? null;
                $ids = array_values(array_map('strval', (array)($vlanPort['vlans'] ?? [])));
                $vlanParts = [];
                $defaultVlan = (int)($vlanPort['def_vlan'] ?? 0);
                if ($defaultVlan > 0) $vlanParts[] = $defaultVlan . ' access';
                foreach ($ids as $id) $vlanParts[] = $id . ' trunk';
                $change = [
                    'mode' => (string)($vlanPort['mode'] ?? ''),
                    'vlan_mode' => implode(',', $vlanParts),
                ];
                if ($dhcp !== null) $change['dhcp'] = (string)$dhcp;
                $updated = $this->onuWriter()->writeEthernetField($userId, $onuRowId, $port, 'change', json_encode($change, JSON_UNESCAPED_SLASHES));
            } elseif ($field === 'dhcp') {
                $updated = $this->onuWriter()->writeEthernetField($userId, $onuRowId, $port, 'change', json_encode(['dhcp' => $value], JSON_UNESCAPED_SLASHES));
            }
            if (in_array($field, ['vlan', 'dhcp'], true) && !$updated) {
                return ['ok' => false, 'message' => 'Perangkat menerima perintah, tetapi konfigurasi gagal disimpan ke snapshot database.'];
            }
            $message = in_array($field, ['enable', 'autoneg'], true)
                ? 'Berhasil ' . ($value === 'enable' ? 'enable' : 'disable') . ' ' . ($field === 'autoneg' ? 'autoneg' : 'LAN')
                : 'Pengaturan LAN dikirim ke perangkat.';
            return [
                'ok' => true,
                'message' => $message,
                'refreshed' => $updated,
            ];
        } catch (Throwable $error) {
            error_log('ONU Ethernet update failed: ' . $error->getMessage());
            return ['ok' => false, 'message' => 'Pengaturan port LAN gagal dikirim.'];
        }
    }

    public function updateOnuLanControl(int $userId, int $onuRowId, int $port, string $field, string $value): array
    {
        if (!in_array($field, ['enable', 'autoneg'], true) || !in_array($value, ['enable', 'disable'], true)) {
            return ['ok' => false, 'message' => 'Pengaturan LAN tidak valid.'];
        }
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if (!is_array($onu)) return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];
        $detail = json_decode((string)($onu['detail_json'] ?? ''), true);
        $portCount = is_array($detail) ? (int)($detail['port_count'] ?? 0) : 0;
        if ($port < 1 || $port >= $portCount) return ['ok' => false, 'message' => 'LAN tidak valid.'];
        $olt = $this->repository->getOltByRowId($userId, (int)($onu['olt_row_id'] ?? 0));
        if (!is_array($olt)) return ['ok' => false, 'message' => 'OLT tidak ditemukan.'];
        if (!$this->driverAllowed($olt)) {
            return ['ok' => false, 'message' => 'Pengaturan LAN tidak didukung perangkat ini.'];
        }
        try {
            $driver = $this->driverForOlt($olt);
            if (!$driver instanceof OltOnuLanControlAware) return ['ok' => false, 'message' => 'Driver LAN tidak tersedia.'];
            $current = $driver->readOnuLanControlBytes((string)$onu['onu_index'], $port);
            if (empty($current['ok'])) {
                return ['ok' => false, 'message' => (string)($current['message'] ?? 'Status LAN tidak dapat dibaca dari perangkat.')];
            }
            $controlBytes = strtoupper((string)($current['control_bytes'] ?? ''));
            if (preg_match('/^[0-9A-F]{14}$/D', $controlBytes) !== 1) {
                return ['ok' => false, 'message' => 'Nilai konfigurasi LAN tidak valid.'];
            }
            $result = $driver->setOnuLanControl((string)$onu['onu_index'], $port, $field, $value, $controlBytes);
            if (empty($result['ok'])) return $result;
            if (!$this->onuWriter()->writeLanControlSnapshot($userId, $onuRowId, $port, $field, $value, (string)($result['control_bytes'] ?? ''))) {
                return ['ok' => false, 'message' => 'Perangkat menerima perintah, tetapi data LAN tidak diperbarui.'];
            }
            return ['ok' => true, 'message' => 'Berhasil ' . ($value === 'enable' ? 'enable' : 'disable') . ' ' . ($field === 'autoneg' ? 'autoneg' : 'LAN')];
        } catch (Throwable $error) {
            error_log('ONU LAN control failed: ' . $error->getMessage());
            return ['ok' => false, 'message' => 'Pengaturan LAN gagal.'];
        }
    }

    public function readOnuTr069(int $userId, int $onuRowId): array
    {
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if (!is_array($onu)) return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];
        $config = $this->repository->getOnuServiceConfig($userId, (int)($onu['olt_device_id'] ?? 0), (string)($onu['onu_index'] ?? ''));
        $rows = (array)($config['config']['tr069_mgmt'] ?? []);
        $row = reset($rows);
        if (!is_array($row)) return ['ok' => false, 'message' => 'Snapshot TR069 belum tersedia.'];
        return ['ok' => true, 'tr069' => $row, 'source' => 'database_snapshot'];
    }

    public function readOnuRemoteInterfaces(int $userId, int $onuRowId, string $kind): array
    {
        if (!in_array($kind, ['wifi', 'veip'], true)) return ['ok' => false, 'message' => 'Jenis antarmuka tidak valid.', 'ports' => []];
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if (!is_array($onu)) return ['ok' => false, 'message' => 'ONU tidak ditemukan.', 'ports' => []];
        $snapshot = $this->repository->getOnuServiceConfig($userId, (int)($onu['olt_device_id'] ?? 0), (string)($onu['onu_index'] ?? ''));
        $config = (array)($snapshot['config'] ?? []);
        $ports = array_values((array)($config[$kind . '_interfaces'] ?? []));
        return ['ok' => true, 'ports' => $ports, 'source' => 'database_snapshot'];
    }


    public function updateOnuInterfaceState(int $userId, int $onuRowId, string $kind, string $port, string $state): array
    {
        if (!in_array($kind, ['wifi', 'veip'], true)) return ['ok' => false, 'message' => 'Jenis antarmuka tidak valid.'];
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if (!is_array($onu)) return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];
        $olt = $this->repository->getOltByRowId($userId, (int)($onu['olt_row_id'] ?? 0));
        if (!is_array($olt) || !$this->driverAllowed($olt)) {
            return ['ok' => false, 'message' => 'Antarmuka belum didukung perangkat ini.'];
        }
        try {
            $driver = $this->driverForOlt($olt);
            if (!method_exists($driver, 'setOnuInterfaceState')) return ['ok' => false, 'message' => 'Driver belum mendukung perubahan status.'];
            $result = $driver->setOnuInterfaceState((string)$onu['onu_index'], $kind, $port, $state);
            if (empty($result['ok'])) return ['ok' => false, 'message' => (string)($result['message'] ?? 'Perubahan ditolak ONU.')];
            $commanded = (array)($result['port'] ?? []);
            $refreshed = $this->saveOnuActionSnapshot($userId, $onu, $olt, static function (array &$config) use ($kind, $port, $state, $commanded): void {
                $key = $kind . '_interfaces';
                $rows = array_values((array)($config[$key] ?? []));
                $found = false;
                foreach ($rows as &$entry) {
                    if (strcasecmp((string)($entry['port'] ?? ''), $port) !== 0) continue;
                    $entry = array_replace((array)$entry, $commanded, ['port' => $port, 'admin status' => $state]);
                    $found = true;
                    break;
                }
                unset($entry);
                if (!$found) $rows[] = array_replace($commanded, ['port' => $port, 'admin status' => $state]);
                $config[$key] = $rows;
            });
            return ['ok' => true, 'message' => $refreshed ? 'Perintah status diterima OLT dan snapshot diperbarui.' : 'Perintah status diterima OLT, tetapi snapshot tidak diperbarui.',
                'port' => array_replace($commanded, ['port' => $port, 'admin status' => $state]), 'refreshed' => $refreshed];
        } catch (Throwable $error) {
            error_log('ONU interface status update failed: ' . $error->getMessage());
            return ['ok' => false, 'message' => 'Status antarmuka gagal diubah.'];
        }
    }

    public function clearOnuServiceConfig(int $userId, int $onuRowId, string $kind, string $port): array
    {
        if ($kind === 'tr069' && $port === '1') return $this->updateOnuTr069($userId, $onuRowId, ['state' => 'lock', 'acs' => '', 'auth_action' => 'disable', 'username' => '', 'password' => '', 'vlan' => 0, 'priority' => 0]);
        if (!in_array($kind, ['wifi', 'veip'], true)) return ['ok' => false, 'message' => 'Jenis layanan tidak valid.'];
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if (!$onu) return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];
        $olt = $this->repository->getOltByRowId($userId, (int)($onu['olt_row_id'] ?? 0));
        if (!$olt) return ['ok' => false, 'message' => 'OLT tidak ditemukan.'];
        $port = strtolower($port); $ref = (string)$onu['onu_index'];
        try {
            $driver = $this->driverForOlt($olt);
            if (!method_exists($driver, 'clearOnuInterfaceConfig')) return ['ok' => false, 'message' => 'Hapus konfigurasi belum didukung perangkat.'];
            $snapshot = $this->repository->getOnuServiceConfig($userId, (int)$olt['id'], $ref);
            $config = (array)($snapshot['config'] ?? []); $current = ['ssid' => $config['wifi_ports'][$port]['ssid'] ?? ''];
            foreach ((array)($config['vlan_ports'] ?? []) as $entry) if (strcasecmp((string)($entry['port'] ?? ''), $port) === 0) $current['vlan_port'] = $entry;
            $result = $driver->clearOnuInterfaceConfig($ref, $kind, $port, $current);
            if (empty($result['ok'])) return $result;
            $saved = $this->saveOnuActionSnapshot($userId, $onu, $olt, static function (array &$config) use ($kind, $port): void {
                $config['vlan_ports'] = array_values(array_filter((array)($config['vlan_ports'] ?? []), static fn($row): bool => strcasecmp((string)($row['port'] ?? ''), $port) !== 0));
                foreach ((array)($config[$kind . '_interfaces'] ?? []) as $index => $entry) {
                    if (strcasecmp((string)($entry['port'] ?? ''), $port) !== 0) continue;
                    $config[$kind . '_interfaces'][$index]['admin status'] = 'lock';
                    $config[$kind . '_interfaces'][$index]['operate status'] = '';
                }
                if ($kind === 'wifi') {
                    $config['wifi_ports'][$port] = ['ssid' => '']; $config['wifi_states'][$port] = 'lock'; unset($config['wifi_config'][$port]);
                } else $config['veip_states'][(int)substr($port, 5)] = 'lock';
            });
            return ['ok' => true, 'message' => 'Konfigurasi ' . strtoupper($kind) . ' dihapus.', 'refreshed' => $saved,
                $kind => ['port' => $port, 'state' => 'lock', 'mode' => '', 'default_vlan' => 0, 'def_vlan' => 0, 'vlans' => [], 'ssid' => '']];
        } catch (Throwable $error) {
            error_log('ONU configuration removal failed: ' . $error->getMessage());
            return ['ok' => false, 'message' => 'Konfigurasi gagal dihapus.'];
        }
    }

    public function updateOnuTr069(int $userId, int $onuRowId, array $input): array
    {
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if (!is_array($onu)) return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];
        $olt = $this->repository->getOltByRowId($userId, (int)($onu['olt_row_id'] ?? 0));
        if (!is_array($olt) || !$this->driverAllowed($olt)) {
            return ['ok' => false, 'message' => 'TR069 belum tersedia untuk perangkat ini.'];
        }
        try {
            $driver = $this->driverForOlt($olt);
            if (!method_exists($driver, 'setOnuTr069')) return ['ok' => false, 'message' => 'Pengaturan TR069 belum didukung driver.'];
            $ref = (string)($onu['onu_index'] ?? '');
            try {
                $snapshot = $this->repository->getOnuServiceConfig($userId, (int)($onu['olt_device_id'] ?? 0), $ref);
            } catch (Throwable $error) {
                error_log('ONU TR069 snapshot read failed: ' . $error->getMessage());
                $snapshot = [];
            }
            $tr069Rows = (array)($snapshot['config']['tr069_mgmt'] ?? []);
            $current = reset($tr069Rows);
            $input['current'] = is_array($current) ? $current : [];
            if (!empty($input['status_only'])) {
                $input['acs'] = (string)($current['acs'] ?? ''); $input['vlan'] = (int)($current['vlan'] ?? 0);
                $input['priority'] = (int)($current['priority'] ?? 0); $input['auth_action'] = 'keep';
            }
            $result = $driver->setOnuTr069($ref, $input);
            if (empty($result['ok'])) return ['ok' => false, 'message' => (string)($result['message'] ?? 'Pengaturan TR069 ditolak perangkat.')];
            if (($input['auth_action'] ?? 'keep') === 'keep') $result['tr069'] = array_replace($input['current'], $result['tr069']);
            else { $result['tr069']['username'] = (string)($input['username'] ?? ''); $result['tr069']['password'] = (string)($input['password'] ?? ''); }
            $refreshed = $this->saveOnuActionSnapshot($userId, $onu, $olt, static function (array &$config) use ($result): void {
                $rows = array_values((array)($config['tr069_mgmt'] ?? []));
                $index = (int)($result['tr069']['index'] ?? 1);
                $found = false;
                foreach ($rows as &$row) {
                    if ((int)($row['index'] ?? 0) !== $index) continue;
                    $row = (array)$result['tr069'];
                    $found = true;
                    break;
                }
                unset($row);
                if (!$found) $rows[] = (array)$result['tr069'];
                $config['tr069_mgmt'] = $rows;
            });
            return ['ok' => true, 'message' => $refreshed
                ? 'Pengaturan TR069 diterima perangkat dan snapshot diperbarui.'
                : 'Pengaturan TR069 diterima perangkat, tetapi snapshot tidak diperbarui.',
                'tr069' => $result['tr069'], 'refreshed' => $refreshed];
        } catch (Throwable $error) {
            error_log('ONU TR069 update failed: ' . $error->getMessage());
            return ['ok' => false, 'message' => 'Pengaturan TR069 gagal dikirim.'];
        }
    }

    public function updateOnuRemoteAccess(int $userId, int $onuRowId, array $input): array
    {
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if (!is_array($onu)) return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];
        $olt = $this->repository->getOltByRowId($userId, (int)($onu['olt_row_id'] ?? 0));
        if (!is_array($olt) || !$this->driverAllowed($olt)) {
            return ['ok' => false, 'message' => 'Remote Access belum tersedia untuk perangkat ini.'];
        }
        try {
            $driver = $this->driverForOlt($olt);
            if (!method_exists($driver, 'setOnuRemoteAccess')) return ['ok' => false, 'message' => 'Pengaturan Remote Access belum didukung driver.'];
            $ref = (string)($onu['onu_index'] ?? '');
            try {
                $saved = $this->repository->getOnuServiceConfig($userId, (int)$olt['id'], $ref);
            } catch (Throwable $error) {
                error_log('ONU Remote Access snapshot read failed: ' . $error->getMessage());
                $saved = [];
            }
            $current = null;
            foreach ((array)($saved['config']['security_mgmt'] ?? []) as $entry) {
                if (is_array($entry) && (int)($entry['index'] ?? 0) === (int)($input['index'] ?? 0)) {
                    $current = $entry;
                    break;
                }
            }
            $input['current'] = $current;
            $result = $driver->setOnuRemoteAccess($ref, $input);
            if (empty($result['ok'])) return ['ok' => false, 'message' => (string)($result['message'] ?? 'Pengaturan ditolak perangkat.')];
            $refreshed = $this->saveOnuActionSnapshot($userId, $onu, $olt, static function (array &$config) use ($input, $result): void {
                self::replaceOnuConfigRow($config, 'security_mgmt', 'index', (int)$input['index'], is_array($result['access'] ?? null) ? $result['access'] : null);
            });
            return ['ok' => true, 'message' => $refreshed ? 'Remote Access diterima perangkat dan snapshot diperbarui.' : 'Remote Access diterima perangkat, tetapi snapshot tidak diperbarui.', 'access' => $result['access'], 'refreshed' => $refreshed];
        } catch (Throwable $error) {
            error_log('ONU Remote Access update failed: ' . $error->getMessage());
            return ['ok' => false, 'message' => 'Remote Access gagal diubah.'];
        }
    }

    public function updateOnuVeip(int $userId, int $onuRowId, array $input): array
    {
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if (!is_array($onu)) return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];
        $olt = $this->repository->getOltByRowId($userId, (int)($onu['olt_row_id'] ?? 0));
        if (!is_array($olt) || !$this->driverAllowed($olt)) {
            return ['ok' => false, 'message' => 'VEIP belum tersedia untuk perangkat ini.'];
        }
        try {
            $driver = $this->driverForOlt($olt);
            if (!method_exists($driver, 'setOnuVeip')) return ['ok' => false, 'message' => 'Pengaturan VEIP belum didukung driver.'];
            $ref = (string)($onu['onu_index'] ?? '');
            $result = $driver->setOnuVeip($ref, $input);
            if (empty($result['ok'])) return ['ok' => false, 'message' => (string)($result['message'] ?? 'Pengaturan ditolak perangkat.')];
            $refreshed = $this->saveOnuActionSnapshot($userId, $onu, $olt, static function (array &$config) use ($result): void {
                $veip = (array)$result['veip'];
                $config['vlan_ports'] = array_values(array_filter((array)($config['vlan_ports'] ?? []), static fn($row): bool => (string)($row['port'] ?? '') !== 'veip_1'));
                $config['vlan_ports'][] = $veip;
                $config['veip_states'][1] = (string)($veip['state'] ?? '');
            });
            return ['ok' => true, 'message' => $refreshed ? 'VEIP diterima perangkat dan snapshot diperbarui.' : 'VEIP diterima perangkat, tetapi snapshot tidak diperbarui.', 'veip' => $result['veip'], 'refreshed' => $refreshed];
        } catch (Throwable $error) {
            error_log('ONU VEIP update failed: ' . $error->getMessage());
            return ['ok' => false, 'message' => 'VEIP gagal diubah.'];
        }
    }

    public function updateOnuWanIp(int $userId, int $onuRowId, array $input): array
    {
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if (!is_array($onu)) return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];
        $olt = $this->repository->getOltByRowId($userId, (int)($onu['olt_row_id'] ?? 0));
        if (!is_array($olt) || !$this->driverAllowed($olt)) {
            return ['ok' => false, 'message' => 'WAN IP belum tersedia untuk perangkat ini.'];
        }
        $mode = (string)($input['mode'] ?? '');
        $allowed = $this->oltServiceOptions($userId, (int)$olt['id']);
        if ($mode !== 'delete' && !isset($allowed['vlan_profiles'][(string)($input['vlan_profile'] ?? '')])) {
            return ['ok' => false, 'message' => 'VLAN Profile WAN tidak tersedia di OLT.'];
        }
        if ($mode === 'static' && !in_array((string)($input['ip_profile'] ?? ''), $allowed['ip_profiles'], true)) {
            return ['ok' => false, 'message' => 'IP Profile WAN tidak tersedia di OLT.'];
        }
        try {
            $driver = $this->driverForOlt($olt);
            if (!method_exists($driver, 'setOnuWanIp')) return ['ok' => false, 'message' => 'Pengaturan WAN IP belum didukung driver.'];
            $ref = (string)($onu['onu_index'] ?? '');
            $result = $driver->setOnuWanIp($ref, $input);
            if (empty($result['ok'])) return ['ok' => false, 'message' => (string)($result['message'] ?? 'Pengaturan ditolak perangkat.')];
            $refreshed = $this->saveOnuActionSnapshot($userId, $onu, $olt, static function (array &$config) use ($input, $result): void {
                self::replaceOnuConfigRow($config, 'wan_ips', 'index', (int)$input['index'], is_array($result['wan'] ?? null) ? $result['wan'] : null);
            });
            return ['ok' => true, 'message' => $refreshed ? 'WAN IP diterima perangkat dan snapshot diperbarui.' : 'WAN IP diterima perangkat, tetapi snapshot tidak diperbarui.', 'wan' => $result['wan'], 'refreshed' => $refreshed];
        } catch (Throwable $error) {
            error_log('ONU WAN IP update failed: ' . $error->getMessage());
            return ['ok' => false, 'message' => 'WAN IP gagal diubah.'];
        }
    }

    public function updateOnuWifiSsid(int $userId, int $onuRowId, int $port, string $ssid): array
    {
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if (!is_array($onu)) return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];
        $olt = $this->repository->getOltByRowId($userId, (int)($onu['olt_row_id'] ?? 0));
        if (!is_array($olt)) return ['ok' => false, 'message' => 'OLT tidak ditemukan.'];
        try {
            $driver = $this->driverForOlt($olt);
            if (!method_exists($driver, 'setOnuWifiSsid')) return ['ok' => false, 'message' => 'Pengaturan SSID belum didukung driver ini.'];
            $result = $driver->setOnuWifiSsid((string)($onu['onu_index'] ?? ''), $port, $ssid);
            if (empty($result['ok'])) return ['ok' => false, 'message' => (string)($result['message'] ?? 'SSID ditolak perangkat.')];
            $saved = $this->saveOnuActionSnapshot($userId, $onu, $olt, static function (array &$config) use ($port, $ssid): void {
                $config['wifi_ports']['wifi_0/' . $port]['ssid'] = $ssid;
            });
            return ['ok' => true, 'message' => $saved ? 'SSID diterima perangkat dan snapshot diperbarui.' : 'SSID diterima perangkat, tetapi snapshot tidak diperbarui.'];
        } catch (Throwable $error) {
            error_log('ONU SSID update failed: ' . $error->getMessage());
            return ['ok' => false, 'message' => 'SSID gagal dikirim.'];
        }
    }

    public function updateOnuWifiConfig(int $userId, int $onuRowId, int $port, array $input): array
    {
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if (!is_array($onu)) return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];
        $olt = $this->repository->getOltByRowId($userId, (int)($onu['olt_row_id'] ?? 0));
        if (!is_array($olt) || !$this->driverAllowed($olt)) {
            return ['ok' => false, 'message' => 'WiFi belum tersedia untuk perangkat ini.'];
        }
        try {
            $driver = $this->driverForOlt($olt);
            if (!method_exists($driver, 'setOnuWifiConfig')) return ['ok' => false, 'message' => 'Pengaturan WiFi belum didukung driver.'];
            $ref = (string)($onu['onu_index'] ?? '');
            $result = $driver->setOnuWifiConfig($ref, $port, $input);
            if (empty($result['ok'])) return ['ok' => false, 'message' => (string)($result['message'] ?? 'Pengaturan ditolak perangkat.')];
            $refreshed = $this->saveOnuActionSnapshot($userId, $onu, $olt, static function (array &$config) use ($result): void {
                $wifi = (array)$result['wifi'];
                $name = (string)($wifi['port'] ?? '');
                if ($name === '') return;
                $config['wifi_ports'][$name]['ssid'] = (string)($wifi['ssid'] ?? '');
                $config['wifi_states'][$name] = (string)($wifi['state'] ?? '');
                $interfaces = array_values((array)($config['wifi_interfaces'] ?? []));
                $found = false;
                foreach ($interfaces as &$interface) {
                    if (strcasecmp((string)($interface['port'] ?? ''), $name) !== 0) continue;
                    $interface['admin status'] = (string)($wifi['state'] ?? '');
                    $found = true;
                    break;
                }
                unset($interface);
                if (!$found) $interfaces[] = ['port' => $name, 'admin status' => (string)($wifi['state'] ?? '')];
                $config['wifi_interfaces'] = $interfaces;
                $config['vlan_ports'] = array_values(array_filter((array)($config['vlan_ports'] ?? []), static fn($row): bool => (string)($row['port'] ?? '') !== $name));
                $config['vlan_ports'][] = ['port' => $name, 'mode' => (string)($wifi['mode'] ?? ''), 'def_vlan' => (int)($wifi['default_vlan'] ?? 0), 'vlans' => (array)($wifi['vlans'] ?? [])];
            });
            return ['ok' => true, 'message' => $refreshed ? 'WiFi diterima perangkat dan snapshot diperbarui.' : 'WiFi diterima perangkat, tetapi snapshot tidak diperbarui.', 'wifi' => $result['wifi'], 'refreshed' => $refreshed];
        } catch (Throwable $error) {
            error_log('ONU WiFi update failed: ' . $error->getMessage());
            return ['ok' => false, 'message' => 'WiFi gagal diubah.'];
        }
    }

    public function updateUplinkConfiguration(int $userId, int $oltRowId, string $ref, array $config, array $vlan): array
    {
        $olt = $this->repository->getOltByRowId($userId, $oltRowId);
        if (!is_array($olt) || trim($ref) === '') return ['ok' => false, 'message' => 'Port uplink tidak ditemukan.'];
        try {
            $driver = $this->driverForOlt($olt);
            if (!$driver instanceof OltUplinkConfigAware) return ['ok' => false, 'message' => 'Konfigurasi uplink belum didukung driver ini.'];
            foreach (['admin_state','flow_control','negotiation','speed','duplex','description'] as $field) {
                if (!array_key_exists($field, $config)) continue;
                $result = $driver->setUplinkField($ref, $field, (string)$config[$field]);
                if (empty($result['ok'])) return $result;
            }
            $vlanMode = strtolower(trim((string)($vlan['mode'] ?? '')));
            if (in_array($vlanMode, ['hybrid', 'access', 'trunk'], true)) {
                $result = $driver->writeUplinkVlan($ref, $vlanMode, (string)($vlan['tagged_vlan'] ?? ''), (string)($vlan['untagged_vlan'] ?? ''), (int)($vlan['pvid'] ?? 0));
                if (empty($result['ok'])) return $result;
            }
            $merged = ['config' => $config, 'vlan' => $vlan];
            $ports = $this->repository->getOltPorts($userId, $oltRowId, 'uplink');
            foreach ($ports as &$port) if (is_array($port) && strcasecmp((string)($port['port_ref'] ?? ''), $ref) === 0) $port = array_replace($port, $merged);
            unset($port);
            $this->snapshotWriter()->writeUplinkPorts($userId, $oltRowId, $ports);
            return ['ok' => true, 'message' => 'Konfigurasi uplink berhasil disimpan.', 'detail' => $merged];
        } catch (Throwable $error) {
            error_log('Uplink configuration failed: ' . $error->getMessage());
            return ['ok' => false, 'message' => 'Konfigurasi uplink gagal disimpan.'];
        }
    }

    public function updateVlanPortMembership(int $userId, int $oltRowId, int $vlanId, string $ref, string $mode, bool $enabled): array
    {
        $olt = $this->repository->getOltByRowId($userId, $oltRowId);
        $ref = trim($ref);
        $mode = strtolower(trim($mode));
        if (!is_array($olt) || $vlanId < 1 || $vlanId > 4095 || !in_array($mode, ['access', 'trunk'], true)) {
            return ['ok' => false, 'message' => 'Perubahan anggota VLAN tidak valid.'];
        }
        if (!$this->driverAllowed($olt)) return ['ok' => false, 'message' => 'Pemantauan perangkat ini belum diizinkan.'];

        if (!$this->oltDriverSupports($olt, OltVlanPortAware::class)) {
            return ['ok' => false, 'message' => 'Konfigurasi VLAN interface belum tersedia.'];
        }
        $knownVlan = false;
        foreach ($this->repository->getOltVlans($userId, $oltRowId) as $profile) {
            $profileData = is_array($profile['data'] ?? null) ? $profile['data'] : [];
            if ((int)($profileData['vlan_id'] ?? $profile['name'] ?? 0) === $vlanId) {
                $knownVlan = true;
                break;
            }
        }
        if (!$knownVlan) return ['ok' => false, 'message' => 'VLAN tidak ditemukan pada daftar perangkat.'];
        $allowedRefs = [];
        foreach ($this->repository->getOltPonSnapshot($userId, $oltRowId) as $port) {
            $ponRef = trim((string)($port['port_ref'] ?? ''));
            if ($ponRef !== '') $allowedRefs[strtolower($ponRef)] = true;
        }
        foreach ($this->repository->getOltPorts($userId, $oltRowId, 'uplink') as $port) {
            $uplinkRef = trim((string)($port['port_ref'] ?? ''));
            if ($uplinkRef !== '') $allowedRefs[strtolower($uplinkRef)] = true;
        }
        if (!isset($allowedRefs[strtolower($ref)])) return ['ok' => false, 'message' => 'Interface tidak terdaftar pada OLT ini.'];

        ensure_data_directory(lock_path());
        [$lock] = $this->openSyncLock(lock_path('snmp-olt-sync-' . $userId . '-' . $oltRowId . '.lock'));
        if (!is_resource($lock)) return ['ok' => false, 'message' => 'Perubahan VLAN tidak dapat dimulai. Coba lagi.'];
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return ['ok' => false, 'busy' => true, 'message' => 'Perangkat sedang diproses. Coba lagi sebentar.'];
        }

        try {
            $this->writeSyncLockMetadata($lock, $userId, $oltRowId, microtime(true));
            $driver = $this->driverForOlt($olt);
            if (!$driver instanceof OltVlanPortAware) return ['ok' => false, 'message' => 'Driver belum mendukung konfigurasi VLAN interface.'];
            $current = $driver->readVlanPortConfig($ref);
            if (empty($current['ok'])) return ['ok' => false, 'message' => 'Konfigurasi VLAN interface tidak dapat dibaca sebelum perubahan.', 'error' => (string)($current['message'] ?? '')];

            $tagged = olt_vlan_ids((string)($current['tagged_vlan'] ?? ''));
            $untagged = olt_vlan_ids((string)($current['untagged_vlan'] ?? ''));
            if ($mode === 'access') {
                $otherAccessVlans = array_values(array_filter($untagged, static fn(int $id): bool => $id !== $vlanId));
                if ($enabled && $otherAccessVlans !== []) {
                    return ['ok' => false, 'message' => 'Interface sudah memiliki VLAN Access lain. Lepas VLAN tersebut lebih dulu.'];
                }
                if ($enabled && !in_array($vlanId, $untagged, true)) $untagged[] = $vlanId;
                if (!$enabled) $untagged = array_values(array_filter($untagged, static fn(int $id): bool => $id !== $vlanId));
            } else {
                if ($enabled && !in_array($vlanId, $tagged, true)) $tagged[] = $vlanId;
                if (!$enabled) $tagged = array_values(array_filter($tagged, static fn(int $id): bool => $id !== $vlanId));
            }
            sort($tagged);
            sort($untagged);
            $nextMode = $tagged !== [] && $untagged !== [] ? 'hybrid' : ($tagged !== [] ? 'trunk' : ($untagged !== [] ? 'access' : 'hybrid'));
            $pvid = (int)($current['pvid'] ?? 0);
            if ($mode === 'access' && $enabled) $pvid = $vlanId;
            elseif ($mode === 'access' && !$enabled && $pvid === $vlanId) $pvid = $untagged[0] ?? 1;
            $result = $driver->writeVlanPortConfig($ref, $nextMode, implode(',', $tagged), implode(',', $untagged), $pvid);
            if (empty($result['ok'])) return ['ok' => false, 'message' => trim((string)($result['message'] ?? '')) ?: 'Perubahan VLAN interface gagal.'];
            $verified = is_array($result['vlan'] ?? null) ? $result['vlan'] : [];
            if (empty($verified['ok'])) return ['ok' => false, 'message' => 'Perangkat belum mengonfirmasi perubahan VLAN interface.'];
            $verifiedList = olt_vlan_ids((string)($verified[$mode === 'access' ? 'untagged_vlan' : 'tagged_vlan'] ?? ''));
            if (in_array($vlanId, $verifiedList, true) !== $enabled) {
                return ['ok' => false, 'message' => 'Perangkat belum mengonfirmasi perubahan VLAN interface.'];
            }

            $profileRows = $this->repository->getOltVlans($userId, $oltRowId);
            foreach ($profileRows as &$profileRow) {
                $profileData = is_array($profileRow['data'] ?? null) ? $profileRow['data'] : [];
                if ((int)($profileData['vlan_id'] ?? $profileRow['name'] ?? 0) !== $vlanId) continue;
                $members = array_values(array_unique(array_filter(array_map('strval', (array)($profileData[$mode] ?? [])), static fn(string $member): bool => trim($member) !== '')));
                $members = $enabled
                    ? array_values(array_unique([...$members, $ref]))
                    : array_values(array_filter($members, static fn(string $member): bool => strcasecmp($member, $ref) !== 0));
                sort($members, SORT_NATURAL | SORT_FLAG_CASE);
                $profileData[$mode] = $members;
                $profileRow['data'] = $profileData;
            }
            unset($profileRow);
            $this->snapshotWriter()->writeVlanCatalog($userId, $oltRowId, $profileRows);
            return ['ok' => true, 'message' => 'Konfigurasi VLAN ' . $mode . ' pada ' . $ref . ' berhasil diterapkan ke perangkat.'];
        } catch (Throwable $error) {
            error_log('VLAN interface update failed: ' . $error->getMessage());
            return ['ok' => false, 'message' => 'Perubahan VLAN interface gagal diterapkan.'];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function validateOltVlanMembershipTarget(int $userId, int $oltRowId, int $vlanId): array
    {
        $olt = $this->repository->getOltByRowId($userId, $oltRowId);
        if (!is_array($olt) || $vlanId < 1 || $vlanId > 4095) {
            return ['ok' => false, 'message' => 'Perubahan anggota VLAN tidak valid.'];
        }
        if (!$this->driverAllowed($olt)) return ['ok' => false, 'message' => 'Pemantauan perangkat ini belum diizinkan.'];
        if (!$this->oltDriverSupports($olt, OltVlanPortAware::class)) {
            return ['ok' => false, 'message' => 'Konfigurasi VLAN interface belum tersedia.'];
        }
        foreach ($this->repository->getOltVlans($userId, $oltRowId) as $profile) {
            $data = is_array($profile['data'] ?? null) ? $profile['data'] : [];
            if ((int)($data['vlan_id'] ?? $profile['name'] ?? 0) === $vlanId) return ['ok' => true];
        }
        return ['ok' => false, 'message' => 'VLAN tidak ditemukan pada daftar perangkat.'];
    }

    public function saveOltProfile(int $userId, int $oltRowId, string $kind, array $data): array
    {
        $olt = $this->repository->getOltByRowId($userId, $oltRowId);
        if (!is_array($olt)) return ['ok' => false, 'message' => 'OLT tidak ditemukan.'];
        $kind = trim($kind);
        $profileFields = [
            'traffic' => ['sir_kbps', 'pir_kbps', 'cbs_kbytes', 'pbs_kbytes'],
            'tcont' => ['type', 'fbw_kbps', 'abw_kbps', 'mbw_kbps'],
            'vlan_profile' => ['tag_mode', 'cvlan'],
            'ip' => ['gateway', 'primary_dns', 'secondary_dns'],
        ];
        if (!isset($profileFields[$kind])) return ['ok' => false, 'message' => 'Jenis profil tidak dikenal.'];
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 64) return ['ok' => false, 'message' => 'Nama profil tidak valid.'];
        $fields = $profileFields[$kind];
        $entry = ['name' => $name];
        foreach ($fields as $field) $entry[$field] = trim((string)($data[$field] ?? ''));
        try {
            $driver = $this->driverForOlt($olt);
            if ($driver instanceof OltProfileAware) {
                $result = $driver->saveProfile($kind, $data);
                if (empty($result['ok'])) return $result;
                $snapshot = $driver->profileInventory();
                $inventoryRows = !empty($snapshot['ok'])
                    ? array_values(array_filter((array)($snapshot['rows'] ?? []), 'is_array'))
                    : [];
                $rows = $inventoryRows;
                $existingRows = $this->repository->getOltProfileSnapshots($userId, (int)$olt['id']);
                foreach ($existingRows as $existingRow) {
                    $alreadyInInventory = false;
                    foreach ($inventoryRows as $inventoryRow) {
                        if (($inventoryRow['kind'] ?? '') === ($existingRow['kind'] ?? '')
                            && strcasecmp((string)($inventoryRow['name'] ?? ''), (string)($existingRow['name'] ?? '')) === 0) {
                            $alreadyInInventory = true;
                            break;
                        }
                    }
                    if (!$alreadyInInventory) $rows[] = $existingRow;
                }
                $inventoryHasSavedProfile = false;
                foreach ($inventoryRows as $inventoryRow) {
                    if (($inventoryRow['kind'] ?? '') === $kind && strcasecmp((string)($inventoryRow['name'] ?? ''), $name) === 0) {
                        $inventoryHasSavedProfile = true;
                        break;
                    }
                }
                $savedProfileIndex = null;
                foreach ($rows as $index => $row) {
                    if (($row['kind'] ?? '') === $kind && strcasecmp((string)($row['name'] ?? ''), $name) === 0) {
                        $savedProfileIndex = $index;
                        break;
                    }
                }
                if ($savedProfileIndex === null) {
                    $rows[] = ['kind' => $kind, 'name' => $name, 'data' => $entry];
                } elseif (!$inventoryHasSavedProfile) {
                    $rows[$savedProfileIndex]['data'] = $entry;
                }
                $kinds = !empty($snapshot['ok']) ? (array)($snapshot['kinds'] ?? []) : [];
                $kinds[] = $kind;
                $this->snapshotWriter()->writeProfileSnapshots($userId, (int)$olt['id'], $rows, array_values(array_unique($kinds)));
                return ['ok' => true, 'message' => 'Profil berhasil disimpan.', 'rows' => $rows];
            }
            $saved = $this->configWriter()->writeLocalProfile($userId, (int)$olt['id'], $kind, $name, $entry);
            return $saved
                ? ['ok' => true, 'message' => 'Profil berhasil disimpan.']
                : ['ok' => false, 'message' => 'Profil gagal disimpan.'];
        } catch (Throwable $error) {
            error_log('Save OLT profile failed: ' . $error->getMessage());
            return ['ok' => false, 'message' => 'Profil gagal disimpan.'];
        }
    }

    public function deleteOltProfile(int $userId, int $oltRowId, string $kind, string $name): array
    {
        $olt = $this->repository->getOltByRowId($userId, $oltRowId);
        if (!is_array($olt)) return ['ok' => false, 'message' => 'OLT tidak ditemukan.'];
        try {
            $driver = $this->driverForOlt($olt);
            if ($driver instanceof OltProfileAware) {
                $result = $driver->deleteProfile($kind, $name);
                if (empty($result['ok'])) return $result;
                $snapshot = $driver->profileInventory();
                if (!empty($snapshot['ok'])) {
                    $this->snapshotWriter()->writeProfileSnapshots($userId, (int)$olt['id'], (array)($snapshot['rows'] ?? []), (array)($snapshot['kinds'] ?? []));
                }
                return ['ok' => true, 'message' => 'Profil berhasil dihapus.', 'rows' => (array)($snapshot['rows'] ?? [])];
            }
            $deleted = $this->configWriter()->deleteLocalProfile($userId, (int)$olt['id'], $kind, $name);
            return $deleted
                ? ['ok' => true, 'message' => 'Profil berhasil dihapus.']
                : ['ok' => false, 'message' => 'Profil gagal dihapus.'];
        } catch (Throwable $error) {
            error_log('Delete OLT profile failed: ' . $error->getMessage());
            return ['ok' => false, 'message' => 'Profil gagal dihapus.'];
        }
    }

    public function readPonTraffic(int $userId, int $oltRowId): array
    {
        $olt = $this->repository->getOltByRowId($userId, $oltRowId);
        if ($olt === null || !monitoring_row_uses_handler($olt, 'snmp_olt')) return [];
        $selected = $this->repository->getDeviceTrafficInterfaces($userId, (int)$olt['id']);
        if ($selected === []) return [];
        try {
            $driver = $this->driverForOlt($olt);
            
            
            $rates = $driver instanceof OltPonTrafficAware
                ? $driver->readPonTrafficBps(2, false, $selected)
                : $this->repository->getOltPonCliTrafficLive((int)$olt['id']);
            return $this->filterPonTrafficSelected($userId, (int)$olt['id'], $rates);
        } catch (Throwable $e) {
            return [];
        }
    }

    public function readPonTrafficLivePayload(int $userId, int $oltRowId): array
    {
        $olt = $this->repository->getOltByRowId($userId, $oltRowId);
        if (!is_array($olt) || !monitoring_row_uses_handler($olt, 'snmp_olt')) return [];
        if ($this->repository->getDeviceTrafficInterfaces($userId, (int)$olt['id']) === []) {
            return ['live' => [], 'series' => [], 'average' => [], 'roles' => [], 'updated_at' => 0];
        }
        $definition = monitoring_driver_definition((string)device_driver_type($olt));
        if (($definition['pon_traffic_source'] ?? '') === 'cli') {
            $this->repository->touchOltPonCliTrafficDemand();
            $rates = $this->repository->getOltPonCliTrafficLive((int)$olt['id']);
            $live = $this->filterPonTrafficSelected($userId, (int)$olt['id'], $rates);
            $this->recordPonTrafficSamples($userId, (int)$olt['id'], $live);
            $history = $this->repository->getOltPonTrafficSeries($userId, (int)$olt['id'], 86400);
            return [
                'live' => $live,
                'series' => $history['series'] ?? [],
                'average' => $history['average'] ?? [],
                'roles' => $this->ponTrafficRoles($userId, (int)$olt['id'], (array)($history['series'] ?? [])),
                'updated_at' => $this->repository->getOltPonCliTrafficLiveUpdatedAt((int)$olt['id']),
                'freshness_seconds' => 360,
                'poll_seconds' => 6,
            ];
        }
        $live = $this->readPonTraffic($userId, $oltRowId);
        $this->recordPonTrafficSamples($userId, (int)$olt['id'], $live);
        $history = $this->repository->getOltPonTrafficSeries($userId, (int)$olt['id'], 86400);
        return [
            'live' => $live,
            'series' => $history['series'] ?? [],
            'average' => $history['average'] ?? [],
            'roles' => $this->ponTrafficRoles($userId, (int)$olt['id'], (array)($history['series'] ?? [])),
            'freshness_seconds' => 30,
            'poll_seconds' => 3,
        ];
    }

    private function ponTrafficRoles(int $userId, int $deviceId, array $series): array
    {
        $uplinkRefs = [];
        try {
            foreach ($this->repository->getOltPorts($userId, $deviceId, 'uplink') as $row) {
                $ref = trim((string)($row['port_ref'] ?? ''));
                if ($ref === '') continue;
                $uplinkRefs[$this->normalizePonRef($ref)] = true;
            }
        } catch (Throwable) {
        }
        $roles = [];
        foreach (array_keys($series) as $ref) {
            $roles[(string)$ref] = isset($uplinkRefs[$this->normalizePonRef((string)$ref)]) ? 'uplink' : 'pon';
        }
        return $roles;
    }

    private function normalizePonRef(string $ref): string
    {
        $ref = (string)preg_replace('~^(?:gpon|epon)(?:-olt)?[_/]?~i', '', trim($ref));
        return str_replace('_', '/', $ref);
    }

    private function recordPonTrafficSamples(int $userId, int $deviceId, array $live): void
    {
        $samples = [];
        foreach ($live as $ref => $row) {
            if (!is_array($row) || !is_numeric($row['rx_bps'] ?? null) || !is_numeric($row['tx_bps'] ?? null)) continue;
            $samples[(string)$ref] = [
                'rx_bps' => max(0.0, (float)$row['rx_bps']),
                'tx_bps' => max(0.0, (float)$row['tx_bps']),
            ];
        }
        if ($samples === []) return;
        try {
            $this->sampleWriter()->writeOltPon(
                $userId,
                $deviceId,
                $samples,
                date('Y-m-d H:i:s')
            );
        } catch (Throwable $error) {
            error_log('PON graph sample failed: ' . $error->getMessage());
        }
    }

    
    private function filterPonTrafficSelected(int $userId, int $deviceId, array $rates): array
    {
        if ($rates === []) return [];
        $selected = $this->repository->getDeviceTrafficInterfaces($userId, $deviceId);
        if ($selected === []) return [];
        $norm = static function (string $ref): string {
            $ref = (string)preg_replace('~^(?:gpon|epon)(?:-olt)?[_/]?~i', '', trim($ref));
            return str_replace('_', '/', $ref);
        };
        $wanted = [];
        foreach (array_keys($selected) as $ref) $wanted[$norm((string)$ref)] = true;
        $out = [];
        foreach ($rates as $ref => $row) {
            if (!isset($wanted[$norm((string)$ref)])) continue;
            $out[$ref] = $row;
        }
        return $out;
    }


    public function updateOnuAuthMode(int $userId, int $onuRowId, string $authMode): array
    {
        $authMode = trim($authMode);
        if (!in_array($authMode, ['Undef', 'Pass', 'Deny', 'uncfg'], true)) return ['ok'=>false,'message'=>'Status autentikasi tidak valid.'];

        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if ($onu === null || !monitoring_row_uses_handler($onu,'snmp_olt')) return ['ok'=>false,'message'=>'ONU tidak ditemukan.'];
        [$pon, $onuId] = $this->onuCoordinates($onu);
        try {
            $oltRowId = (int)($onu['olt_row_id'] ?? 0);
            $olt = $oltRowId > 0 ? $this->repository->getOltByRowId($userId, $oltRowId) : null;
            $driver = is_array($olt)
                ? $this->driverForOlt($olt)
                : $this->newDriver($userId,
                    (string)device_driver_type($onu),
                    (string)$onu['olt_ip_address'],
                    (string)$onu['snmp_community'],
                    (int)$onu['olt_row_id'],
                    (int)($onu['snmp_port'] ?? 161)
                );
            $snmpResult = $driver->setOnuAuthMode($pon, $onuId, $authMode);
        } catch (Throwable $e) {
            $snmpResult = [
                'ok'=>false,
                'message'=>'Status autentikasi gagal diubah.',
                'error'=>(string)$e,
            ];
        }

        if (empty($snmpResult['ok'])) {
            return $snmpResult;
        }

        try {
            if (!$this->onuWriter()->writeAuthMode($userId, $onuRowId, $authMode)) {
                $snmpResult['message'] = 'Perintah Auth Mode diterima OLT, tetapi data tersimpan tidak diperbarui.';
                $snmpResult['refreshed'] = false;
            }
        } catch (Throwable $error) {
            error_log('ONU auth mode snapshot update failed: ' . $error->getMessage());
            $snmpResult['message'] = 'Perintah Auth Mode diterima OLT, tetapi data tersimpan tidak diperbarui.';
            $snmpResult['refreshed'] = false;
        }
        return $snmpResult;
    }

    public function operateOnu(int $userId, int $onuRowId, string $operation): array
    {
        if (!in_array($operation, ['rebootOp', 'activeOp', 'noactiveOp', 'restoreOp'], true)) {
            return ['ok'=>false,'message'=>'Operasi ONU tidak valid.'];
        }
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if ($onu === null || !monitoring_row_uses_handler($onu,'snmp_olt')) return ['ok'=>false,'message'=>'ONU tidak ditemukan.'];

        [$pon, $onuId] = $this->onuCoordinates($onu);
        try {
            $oltRowId = (int)($onu['olt_row_id'] ?? 0);
            $olt = $oltRowId > 0 ? $this->repository->getOltByRowId($userId, $oltRowId) : null;
            $driver = is_array($olt)
                ? $this->driverForOlt($olt)
                : $this->newDriver($userId, (string)device_driver_type($onu),(string)$onu['olt_ip_address'], (string)$onu['snmp_community'], (int)$onu['olt_row_id'], (int)($onu['snmp_port'] ?? 161));
            return $driver->operateOnu($pon, $onuId, $operation);
        } catch (Throwable $e) {
            return ['ok'=>false,'message'=>'Operasi ONU gagal dijalankan.','error'=>(string)$e];
        }
    }

    public function deleteOnu(int $userId, int $onuRowId): array
    {
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if ($onu === null || !monitoring_row_uses_handler($onu, 'snmp_olt')) {
            return ['ok'=>false,'message'=>'ONU tidak ditemukan.'];
        }
        $oltRowId = (int)($onu['olt_row_id'] ?? 0);
        $olt = $oltRowId > 0 ? $this->repository->getOltByRowId($userId, $oltRowId) : null;
        $definition = is_array($olt) ? monitoring_driver_definition((string)device_driver_type($olt)) : null;
        $clientClass = is_array($definition) ? (string)($definition['cli_terminal_class'] ?? '') : '';
        if (!is_array($olt) || !$this->driverAllowed($olt) || $clientClass === ''
            || !is_subclass_of($clientClass, OltOnuDeleteCliAware::class)) {
            return ['ok'=>false,'message'=>'Penghapusan ONU belum didukung untuk perangkat ini.'];
        }
        if (!preg_match('/^\d+\/(\d+)\/(\d+):([1-9]\d*)$/', (string)($onu['onu_index'] ?? ''), $match)
            || (int)$match[1] < 1 || (int)$match[2] < 1) {
            return ['ok'=>false,'message'=>'Indeks ONU tidak valid.'];
        }
        $endpoint = $this->cliEndpointForOlt($olt);
        if ($endpoint === null) {
            return ['ok'=>false,'message'=>'Kredensial CLI tidak siap untuk menghapus ONU.'];
        }

        $client = null;
        try {
            $client = (new \App\Driver\Controller())->createCli((string)device_driver_type($olt), $endpoint);
            if (!$client instanceof OltOnuDeleteCliAware) {
                return ['ok'=>false,'message'=>'Penghapusan ONU belum didukung untuk perangkat ini.'];
            }
            $result = $client->deleteOnu((int)$match[1], (int)$match[2], (int)$match[3], (string)($onu['onu_serial'] ?? ''));
            if (empty($result['ok'])) return $result;

            if (!empty($result['present_after'])) {
                if (!$this->onuWriter()->writeAuthMode($userId, $onuRowId, 'Undef')) {
                    return ['ok'=>false,'message'=>'OLT menghapus registrasi ONU, tetapi snapshot aplikasi gagal diperbarui.'];
                }
                $profile = $this->onuWriter()->clearRegistrationProfile($userId, $onuRowId);
                if (empty($profile['ok'])) {
                    return ['ok'=>false,'message'=>'OLT menghapus registrasi ONU, tetapi profil pada snapshot aplikasi gagal dikosongkan.'];
                }
            } elseif (!$this->onuWriter()->deleteSnapshot($userId, $onuRowId)) {
                return ['ok'=>false,'message'=>'OLT menghapus ONU, tetapi snapshot aplikasi gagal dihapus.'];
            }

            return $result;
        } catch (Throwable $e) {
            return ['ok'=>false,'message'=>'Penghapusan ONU gagal dijalankan.','error'=>(string)$e];
        } finally {
            if (is_object($client) && method_exists($client, 'close')) $client->close();
        }
    }

    public function readSystem(int $userId, int $oltRowId): array
    {
        $olt = $this->repository->getOltByRowId($userId, $oltRowId);
        if ($olt === null || !monitoring_row_uses_handler($olt,'snmp_olt')) return [];

        try {
            $system = $this->driverForOlt($olt)->getSystemInfo();
            $mapped = !empty($system['ok']) ? $this->mapSystem($system, $olt) : [];
        } catch (Throwable) {
            $mapped = [];
        }
        return $mapped;
    }

    public function readPonModules(int $userId, int $oltRowId): array
    {
        $olt = $this->repository->getOltByRowId($userId, $oltRowId);
        if ($olt === null || !monitoring_row_uses_handler($olt, 'snmp_olt')) return [];
        $modules = [];
        foreach ($this->repository->getOltPonSnapshot($userId, (int)$olt['id']) as $port) {
            $optics = is_array($port['optics'] ?? null) ? $port['optics'] : [];
            $modules[] = array_merge($optics, [
                'ok' => true,
                'pon' => (int)($port['pon_number'] ?? 0),
                'port_ref' => (string)($port['port_ref'] ?? ''),
                'link_status' => $port['link_status'] ?? null,
                'enable' => $port['admin_state'] ?? null,
                'flow_control' => $port['flow_control'] ?? null,
                'description' => $port['description'] ?? '',
                'tx_power' => $port['tx_power'] ?? ($optics['tx_power'] ?? null),
            ]);
        }
        return $modules;
    }

    private function confirmedPonViewState(int $userId, array $olt): array
    {
        $oltDeviceId = (int)($olt['id'] ?? 0);
        if ($oltDeviceId < 1) return [];
        $ports = $this->repository->getOltPonSnapshot($userId, $oltDeviceId);
        if ($ports === []) return [];

        $state = [];
        foreach ($ports as $port) {
            if (!is_array($port)) continue;
            $ponText = trim((string)($port['port_ref'] ?? $port['pon'] ?? ''));
            if ($ponText === '') continue;
            $optics = is_array($port['optics'] ?? null) ? $port['optics'] : [];
            $entry = [
                'link_status' => trim((string)($port['link_status'] ?? '')) ?: null,
                'tx_power' => is_numeric($port['tx_power'] ?? null) ? (float)$port['tx_power'] : null,
                'temperature' => is_numeric($optics['temperature'] ?? null) ? (float)$optics['temperature'] : null,
                'voltage' => is_numeric($optics['voltage'] ?? null) ? (float)$optics['voltage'] : null,
                'current' => is_numeric($optics['current'] ?? null) ? (float)$optics['current'] : null,
            ];

            $ref = self::normalizePonPath($ponText);
            if ($ref !== '') $state[$ref] = $entry;

            if (preg_match('/(\d+)\s*$/', str_replace('_', '/', $ponText), $match) === 1) {
                $number = (int)$match[1];
                if ($number > 0 && !isset($state['pon:' . $number])) $state['pon:' . $number] = $entry;
            }
        }
        return $state;
    }

    public function syncPonPorts(
        int $userId,
        int $oltRowId,
        bool $recordChanges = true,
        ?float $deadlineAt = null,
        array $verifiedEnable = [],
        bool $includeModuleDescription = false,
        bool $fastOnly = false,
        ?OltSnmpDriver $syncDriver = null
    ): array
    {
        $olt = $this->repository->getOltByRowId($userId, $oltRowId);
        if ($olt === null || !monitoring_row_uses_handler($olt, 'snmp_olt')) {
            return ['ok'=>false,'message'=>'OLT tidak ditemukan.'];
        }

        $driver = $syncDriver ?? $this->driverForOlt($olt);
        
        
        $oltDefinition = monitoring_driver_definition((string)device_driver_type($olt));
        $ponModuleSource = (string)($oltDefinition['pon_module_source'] ?? 'snmp');
        if ($ponModuleSource === 'cli' && $this->cliEndpointForOlt($olt) === null) {
            $ponModuleSource = 'snmp';
        }
        $ports = [];
        $readFailureLogged = false;
        $skippedByDeadline = 0;
        $storedIntents = $this->ponEnableIntents($userId, (int)$olt['olt_id']);
        $revertedPons = [];
        foreach ($this->ponPortTargets($driver, $olt) as $ponTarget) {
            $pon = (int)$ponTarget['number'];
            $targetRef = (string)$ponTarget['ref'];
            $targetLabel = $targetRef !== '' ? $targetRef : (string)$pon;
            try {
                $this->assertBeforeDeadline($deadlineAt, 'pembacaan status link PON ' . $targetLabel);
                if ($fastOnly && $targetRef !== '' && method_exists($driver, 'getFastPonPortStatusByRef')) {
                    $status = $driver->getFastPonPortStatusByRef($targetRef);
                } elseif ($fastOnly && method_exists($driver, 'getFastPonPortStatus')) {
                    $status = $driver->getFastPonPortStatus($pon);
                } else {
                    $status = $targetRef !== ''
                        ? $driver->getPonPortStatusByRef($targetRef)
                        : $driver->getPonPortStatus($pon);
                }
            } catch (Throwable $error) {
                if ($this->deadlineReached($deadlineAt)) {
                    $skippedByDeadline++;
                    break;
                }
                if (!$readFailureLogged) {
                    error_log((string)$error);
                    $readFailureLogged = true;
                }
                $this->snapshotWriter()->recordReadFailure(
                    $userId,
                    $oltRowId,
                    'OLT SNMP',
                    'Pembacaan status PON ' . $targetLabel . ' gagal: ' . $error->getMessage(),
                    'olt|' . $oltRowId . '|pon|' . $targetLabel
                );
                continue;
            }
            if (empty($status['ok']) || !is_string($status['link_status'] ?? null)) continue;
            $this->snapshotWriter()->clearReadFailure($userId, $oltRowId, 'olt|' . $oltRowId . '|pon|' . $targetLabel);
            
            
            $module = [];
            $moduleProbed = false;
            if ($driver instanceof OltPonModuleAware && $ponModuleSource !== 'cli') {
                try {
                    $module = $targetRef !== ''
                        ? (array)$driver->getPonModuleInfoByRef($targetRef)
                        : (array)$driver->getPonModuleInfo($pon);
                    $moduleProbed = true;
                    if ($includeModuleDescription && $targetRef !== '' && $driver instanceof OltPonModuleAware) {
                        $description = (array)$driver->readPonModuleDescriptionByRef($targetRef);
                        if (!empty($description['ok'])) {
                            $module = array_merge($module, $description);
                        }
                    } elseif ($includeModuleDescription && $driver instanceof OltPonModuleAware) {
                        $description = (array)$driver->readPonModuleDescription($pon);
                        if (!empty($description['ok'])) {
                            $module['vendor'] = (string)($description['vendor'] ?? '');
                            $module['module_type'] = (string)($description['module_type'] ?? '');
                            $module['wavelength'] = $description['wavelength'] ?? null;
                        }
                    }
                } catch (Throwable) {
                    $module = [];
                }
            }
            if ($includeModuleDescription && $driver instanceof OltPonFlowAware) {
                $flow = $driver->readPonFlowControl($pon);
                if (!empty($flow['ok'])) $status['flow_control'] = $flow['flow_control'];
            }
            $optics = $this->ponOpticsPayload($module);
            $portRef = trim((string)($status['port_ref'] ?? ''));
            $ponLabel = trim((string)($status['pon_label'] ?? ''));
            $portEntry = [
                'port_ref' => $portRef !== '' ? $portRef : ($pon > 0 ? '0/1_' . $pon : $targetRef),
                'pon' => $ponLabel !== '' ? $ponLabel : ($pon > 0 ? '0/1/' . $pon : $targetRef),
                'pon_number' => $pon,
                'port_type' => 'PON',
                'link_status' => (string)$status['link_status'],
            ];
            if (is_string($status['description'] ?? null)) {
                $portEntry['description'] = $status['description'];
            }
            if (is_string($status['flow_control'] ?? null)) $portEntry['flow_control'] = $status['flow_control'];
            if (!$includeModuleDescription && !$fastOnly) {
                foreach (['autoneg', 'speed', 'duplex'] as $detailField) {
                    if (is_string($status[$detailField] ?? null) && trim((string)$status[$detailField]) !== '') {
                        $portEntry[$detailField] = trim((string)$status[$detailField]);
                    }
                }
            }
            $deviceAdmin = is_string($status['enable'] ?? null) ? trim((string)$status['enable']) : '';
            if ($deviceAdmin !== '') {
                $portEntry['enable'] = $deviceAdmin;
            }
            $verifiedAdmin = trim((string)($verifiedEnable[$pon] ?? ''));
            if ($verifiedAdmin !== '') {

                $portEntry['enable'] = $verifiedAdmin;
                $portEntry['enable_intent'] = $verifiedAdmin;
                $portEntry['enable_intent_at'] = date('Y-m-d H:i:s');
            } else {
                $intent = $storedIntents[$portEntry['port_ref']] ?? ($storedIntents['pon:' . $pon] ?? null);
                if (is_array($intent) && $deviceAdmin !== '') {
                    $intentAt = (int)strtotime((string)($intent['at'] ?? ''));
                    $intentAge = time() - $intentAt;
                    if ($intentAt > 0 && $intentAge >= 0 && $intentAge <= self::PON_ADMIN_INTENT_TTL_SECONDS) {
                        if (strcasecmp((string)$intent['intent'], $deviceAdmin) === 0) {
                            $portEntry['enable_intent'] = (string)$intent['intent'];
                            $portEntry['enable_intent_at'] = (string)$intent['at'];
                        } else {
                            $revertedPons[] = $pon;
                            try {
                                $this->repository->logApplication(
                                    $userId,
                                    'OLT SNMP',
                                    'pon_admin_revert_' . $pon,
                                    'warning',
                                    'Status Port PON' . $pon . ' kembali ' . $deviceAdmin
                                        . ' di perangkat setelah perubahan pengguna ke ' . (string)$intent['intent'] . '.'
                                );
                            } catch (Throwable) {
                            }
                        }
                    }
                }
            }
            $portEntry['optics'] = $optics;
            $portEntry['tx_power'] = $optics['tx_power'];
            
            
            
            $portEntry['tx_power_sampled'] = $moduleProbed
                || (!empty($module['ok']) && array_key_exists('tx_power', $module));
            $ports[] = $portEntry;
        }

        if ($ports === []) {
            return ['ok'=>false,'message'=>'Status link PON tidak dapat dibaca.'];
        }

        
        if ($ponModuleSource === 'cli') {
            $this->mergeCliPonModuleInfo($olt, $ports);
        }

        
        
        $moduleSource = $ponModuleSource;
        try {
            $events = $this->snapshotWriter()->writePonSnapshot(
                $userId,
                (int)$olt['olt_id'],
                $ports,
                $recordChanges,
                $moduleSource,
                $fastOnly
            );
        } catch (Throwable) {
            return ['ok'=>false,'message'=>'Status link PON tidak dapat disimpan.'];
        }
        
        if ($includeModuleDescription && !$fastOnly) {
            $this->syncCardInventory($userId, $olt, $driver);
            $this->syncProfileInventory($userId, $olt, $driver);
        }
        if (!$fastOnly) $this->syncUplinkPortInventory($userId, $olt, $driver, $includeModuleDescription);

        return [
            'ok'=>true,
            'message'=>count($ports).' status link PON diperbarui.'
                . ($revertedPons === []
                    ? ''
                    : ' ' . count($revertedPons) . ' status admin PON dikembalikan perangkat ('
                        . implode(', ', array_map(static fn(int $number): string => 'PON' . $number, $revertedPons)) . ').'),
            'events'=>count($events),
            'ports'=>count($ports),
            'skipped_by_deadline'=>$skippedByDeadline,
            'reverted'=>$revertedPons,
        ];
    }

    
    private function syncMiddlePonPorts(int $userId, array $olt, OltSnmpDriver $driver, ?float $deadlineAt, string &$failureReason): bool
    {
        $storedByRef = [];
        $storedByNumber = [];
        foreach ($this->repository->getOltPons($userId, (int)$olt['id']) as $port) {
            if (!is_array($port)) continue;
            $portRef = trim((string)($port['port_ref'] ?? ''));
            if ($portRef !== '') {
                $storedByRef[$portRef] = $port;
                $storedByRef[(string)preg_replace('/^gpon_/i', '', $portRef)] = $port;
            }
            $number = (int)($port['pon_number'] ?? 0);
            if ($number > 0 && $portRef !== '') $storedByNumber[$number] = $port;
        }
        $rows = [];
        $attempted = 0;
        foreach ($this->ponPortTargets($driver, $olt) as $target) {
            if ($deadlineAt !== null && microtime(true) >= $deadlineAt) {
                $failureReason = 'batas waktu pembacaan port PON terlampaui';
                return false;
            }
            $number = (int)$target['number'];
            $ref = (string)$target['ref'];
            $stored = $ref !== '' ? ($storedByRef[$ref] ?? null) : ($storedByNumber[$number] ?? null);
            if (!is_array($stored)) continue;
            $attempted++;
            try {
                if ($ref !== '' && method_exists($driver, 'getMiddlePonPortStatusByRef')) {
                    $status = $driver->getMiddlePonPortStatusByRef($ref);
                } elseif (method_exists($driver, 'getMiddlePonPortStatus')) {
                    $status = $driver->getMiddlePonPortStatus($number);
                } else {
                    continue;
                }
            } catch (Throwable $error) {
                $failureReason = 'PON ' . $ref . ': ' . $error->getMessage();
                return false;
            }
            if (empty($status['ok'])) {
                $reason = trim((string)($status['error'] ?? $status['message'] ?? 'permintaan SNMP gagal'));
                if ($reason !== '' && preg_match('/no such|noSuch|tidak ada objek|OID tidak tersedia/i', $reason) === 1) continue;
                $failureReason = 'PON ' . $ref . ': ' . $reason;
                return false;
            }
            $row = ['port_ref'=>(string)$stored['port_ref']];
            foreach (['autoneg', 'speed', 'duplex', 'flow_control', 'description'] as $field) {
                if (is_string($status[$field] ?? null)) $row[$field] = $status[$field];
            }
            if (is_string($status['enable'] ?? null)) $row['admin_state'] = $status['enable'];
            if (count($row) === 1) continue;
            $rows[] = $row;
        }
        if ($attempted === 0 || $rows === []) return true;
        $this->snapshotWriter()->writePonMiddleSettings($userId, (int)$olt['id'], $rows);
        $this->syncUplinkPortInventory($userId, $olt, $driver, false);
        return true;
    }

    private function cliEnabledNow(array $olt): bool
    {
        if ((int)($olt['hybrid_status'] ?? 0) !== 1) return false;
        $userId = (int)($olt['user_id'] ?? 0);
        $rowId = (int)($olt['id'] ?? 0);
        if ($userId < 1 || $rowId < 1) return false;
        $current = $this->repository->getOltByRowId($userId, $rowId);
        return is_array($current) && (int)($current['hybrid_status'] ?? 0) === 1;
    }

    private function cliEndpointForOlt(array $olt): ?array
    {
        if (!$this->cliEnabledNow($olt)) return null;
        $definition = monitoring_driver_definition((string)device_driver_type($olt));
        if (!is_array($definition) || ($definition['pon_module_source'] ?? '') !== 'cli') return null;

        $host = trim((string)($olt['ip_address'] ?? ''));
        $username = trim((string)($olt['username'] ?? ''));
        $encrypted = trim((string)($olt['password_enc'] ?? ''));
        if ($host === '' || $username === '' || $encrypted === '') return null;

        try {
            $password = $this->cipher->decrypt($encrypted);
        } catch (Throwable) {
            return null;
        }
        if (trim($password) === '') return null;

        return [
            'driver_id' => (string)device_driver_type($olt),
            'device_id' => (int)($olt['id'] ?? 0),
            'user_id' => (int)($olt['user_id'] ?? 0),
            'host' => $host,
            'port' => max(1, min(65535, (int)($olt['ports'] ?? 23))),
            'username' => $username,
            'password' => $password,
            'timeout_seconds' => 15,
            'read_wait_ms' => 300,
        ];
    }

    
    private static function oltCliSignature(array $config): string
    {
        return hash('sha256', implode('|', [
            (string)($config['host'] ?? ''),
            (string)($config['port'] ?? ''),
            (string)($config['username'] ?? ''),
            (string)($config['password'] ?? ''),
        ]));
    }

    private function oltCliSession(int $deviceId, array $config): ?OltTerminalCliClient
    {
        if ($deviceId < 1) return null;
        $signature = self::oltCliSignature($config);
        $now = microtime(true);
        $entry = self::$oltCliSessions[$deviceId] ?? null;
        if (is_array($entry)
            && ($entry['signature'] ?? '') === $signature
            && ($entry['client'] ?? null) instanceof OltTerminalCliClient
            && ($now - (float)($entry['at'] ?? 0)) < self::OLT_CLI_IDLE_SECONDS) {
            
            $existing = $entry['client'];
            if (!empty($existing->probeConnection()['ok'])) {
                self::$oltCliSessions[$deviceId]['at'] = $now;
                return $existing;
            }
        }
        $this->dropOltCliSession($deviceId);

        $definition = monitoring_driver_definition((string)($config['driver_id'] ?? ''));
        $clientClass = trim((string)($definition['cli_terminal_class'] ?? ''));
        if ($clientClass === '' || !class_exists($clientClass)
            || !is_subclass_of($clientClass, OltPonModuleCliAware::class)) return null;
        try {
            $session = (new \App\Driver\Controller())->createCli((string)($config['driver_id'] ?? ''), $config);
        } catch (Throwable) {
            return null;
        }
        if (!$session instanceof OltTerminalCliClient || !$session instanceof OltPonModuleCliAware) {
            return null;
        }
        $connect = $session->connectForTerminal();
        if (empty($connect['ok'])) { $session->close(); return null; }
        self::$oltCliSessions[$deviceId] = ['signature' => $signature, 'at' => microtime(true), 'client' => $session];
        return $session;
    }

    private function dropOltCliSession(int $deviceId): void
    {
        $entry = self::$oltCliSessions[$deviceId] ?? null;
        if (is_array($entry) && ($entry['client'] ?? null) instanceof OltTerminalCliClient) $entry['client']->close();
        unset(self::$oltCliSessions[$deviceId]);
    }

    
    private static function cliPonModuleRef(string $ref, int $ponNumber = 0): string
    {
        $ref = str_replace('_', '/', trim($ref));
        if (preg_match('/(\d+)\s*\/\s*(\d+)\s*\/\s*(\d+)/', $ref, $m) === 1) {
            return (int)$m[1] . '/' . (int)$m[2] . '/' . (int)$m[3];
        }
        if ($ponNumber > 0) return '0/1/' . $ponNumber;
        return '';
    }

    
    private function mergeCliPonModuleInfo(array $olt, array &$ports): void
    {
        $config = $this->cliEndpointForOlt($olt);
        if ($config === null) return;

        $refs = [];
        foreach ($ports as $index => $port) {
            if (!is_array($port)) continue;
            $ref = self::cliPonModuleRef(
                (string)($port['pon'] ?? $port['port_ref'] ?? ''),
                (int)($port['pon_number'] ?? 0)
            );
            if ($ref === '') continue;
            $refs[$ref] = $index;
        }
        if ($refs === []) return;

        $response = OltTerminalBrokerClient::request([
            'method' => 'read-pon-module',
            'olt' => $config,
            'refs' => array_keys($refs),
        ]);
        if (empty($response['ok'])) {
            $message = (string)($response['message'] ?? 'Broker CLI tidak tersedia.');
            error_log('OLT CLI PON module read: ' . $message);
            $this->repository->logApplication((int)$olt['user_id'], 'OLT CLI', 'pon_module_read', 'error', $message);
            return;
        }
        $modules = is_array($response['modules'] ?? null) ? $response['modules'] : [];
        $merged = OltOpticalValues::applyPonModuleValues($ports, $modules, $refs);
        $ports = $merged['ports'];
        $failed = $merged['failed'];

        if ($failed !== []) {
            $this->repository->logApplication((int)$olt['user_id'], 'OLT CLI', 'pon_module_read', 'error',
                'TX Power modul PON tidak terbaca pada ' . implode(', ', $failed) . '.');
        }

    }


    private function ponEnableIntents(int $userId, int $oltDeviceId): array
    {
        $intents = [];
        if ($userId < 1 || $oltDeviceId < 1) return $intents;
        try {
            $stored = $this->repository->getOltPonSnapshot($userId, $oltDeviceId);
        } catch (Throwable) {
            return $intents;
        }

        foreach ($stored as $port) {
            if (!is_array($port)) continue;
            $intent = trim((string)($port['enable_intent'] ?? ''));
            $at = trim((string)($port['enable_intent_at'] ?? ''));
            if ($intent === '' || $at === '') continue;

            $entry = ['intent' => $intent, 'at' => $at];
            $ref = trim((string)($port['port_ref'] ?? ''));
            if ($ref !== '') $intents[$ref] = $entry;
            $ponText = trim((string)($port['pon'] ?? $ref));
            if ($ponText !== '' && preg_match('/(?:_|\/)(\d+)$/', $ponText, $match) === 1) {
                $intents['pon:' . (int)$match[1]] = $entry;
            }
        }

        return $intents;
    }

    private function syncUplinkPortInventory(int $userId, array $olt, object $driver, bool $includeDetail = false): void
    {
        if (!$driver instanceof OltUplinkAware) return;
        try {
            $ports = (array)$driver->readUplinkPorts();
            if ($ports === []) {
                $ports = $this->repository->getOltPorts($userId, (int)($olt['id'] ?? 0), 'uplink');
            }
            if ($ports === []) return;
            if ($includeDetail && method_exists($driver, 'readUplinkPortDetail')) {
                $enriched = [];
                foreach ($ports as $uplinkPort) {
                    if (!is_array($uplinkPort)) { $enriched[] = $uplinkPort; continue; }
                    $uplinkRef = trim((string)($uplinkPort['port_ref'] ?? ''));
                    if ($uplinkRef !== '') {
                        try {
                            $uplinkDetail = $driver->readUplinkPortDetail($uplinkRef);
                            if (is_array($uplinkDetail['config'] ?? null)) $uplinkPort['config'] = $uplinkDetail['config'];
                            if (is_array($uplinkDetail['optics'] ?? null)) $uplinkPort['optics'] = $uplinkDetail['optics'];
                            if (is_array($uplinkDetail['vlan'] ?? null)) $uplinkPort['vlan'] = $uplinkDetail['vlan'];
                        } catch (Throwable) {
                        }
                    }
                    $enriched[] = $uplinkPort;
                }
                $ports = $enriched;
            }
            $this->snapshotWriter()->writeUplinkPorts($userId, (int)($olt['id'] ?? 0), $ports);
        } catch (Throwable $error) {
            error_log((string)$error);
        }
    }

    private static function mergePonOpticalSamples(array $ports, array $samples): array
    {
        $byRef = [];
        foreach ($samples as $sample) {
            if (!is_array($sample)) continue;
            $ref = str_replace('_', '/', trim((string)($sample['port_ref'] ?? $sample['pon'] ?? '')));
            if ($ref !== '') $byRef[$ref] = $sample;
        }
        foreach ($ports as &$port) {
            $ref = str_replace('_', '/', trim((string)($port['port_ref'] ?? $port['pon'] ?? '')));
            $sample = $byRef[$ref] ?? null;
            if (!is_array($sample)) continue;
            $optics = is_array($sample['optics'] ?? null) ? $sample['optics'] : [];
            $sampled = $sample['tx_power_sampled'] ?? (array_key_exists('tx_power', $sample) || array_key_exists('tx_power', $optics));
            if (!$sampled) continue;
            $value = array_key_exists('tx_power', $sample) ? $sample['tx_power'] : ($optics['tx_power'] ?? null);
            $olt_txPower = is_numeric($value) && is_finite((float)$value) ? (float)$value : null;
            $port['tx_power'] = $olt_txPower;
            $port['optics']['tx_power'] = $olt_txPower;
            $port['tx_power_sampled'] = true;
        }
        unset($port);
        return $ports;
    }

    private function ponOpticsPayload(array $module): array
    {
        $metric = static function (mixed $value): ?float {
            if (!is_numeric($value)) return null;
            $number = (float)$value;
            return is_finite($number) ? $number : null;
        };

        $optics = [
            'module_type' => is_string($module['module_type'] ?? null) ? trim((string)$module['module_type']) : '',
            'vendor' => is_string($module['vendor'] ?? null) ? trim((string)$module['vendor']) : '',
            'vendor_pn' => is_string($module['vendor_pn'] ?? null) ? trim((string)$module['vendor_pn']) : '',
            'vendor_sn' => is_string($module['vendor_sn'] ?? null) ? trim((string)$module['vendor_sn']) : '',
            'class' => is_string($module['class'] ?? null) ? trim((string)$module['class']) : '',
            'connector' => is_string($module['connector'] ?? null) ? trim((string)$module['connector']) : '',
            'trans_distance' => is_string($module['trans_distance'] ?? null) ? trim((string)$module['trans_distance']) : '',
            'version' => is_string($module['version'] ?? null) ? trim($module['version']) : '',
            'production_date' => is_string($module['production_date'] ?? null) ? trim($module['production_date']) : '',
            'wavelength' => $metric($module['wavelength'] ?? null),
            'temperature' => $metric($module['temperature'] ?? null),
            'voltage' => $metric($module['voltage'] ?? null),
            'current' => $metric($module['current'] ?? null),
            'tx_power' => $metric($module['tx_power'] ?? null),
        ];
        foreach (['temperature', 'voltage', 'current', 'tx_power', 'wavelength'] as $field) {
            if (array_key_exists($field . '_raw', $module)) $optics[$field . '_raw'] = $module[$field . '_raw'];
        }
        if (array_key_exists('device_raw', $module)) $optics['device_raw'] = $module['device_raw'];
        return $optics;
    }

    private function deadlineReached(?float $deadlineAt): bool
    {
        return $deadlineAt !== null && microtime(true) >= $deadlineAt;
    }

    public function updatePonPort(int $userId, int $oltRowId, int $pon, array $fields): array
    {
        return ['ok'=>false,'unused'=>true,'message'=>'Konfigurasi PON tidak tersedia untuk perangkat ini.'];
    }

    public function updatePonPortDescription(
        int $userId,
        int $oltRowId,
        int $pon,
        string $description,
        bool $clear = false,
        string $portRef = ''
    ): array
    {
        $olt = $this->repository->getOltByRowId($userId, $oltRowId);
        if ($olt === null || !monitoring_row_uses_handler($olt, 'snmp_olt')) {
            return ['ok'=>false,'message'=>'OLT tidak ditemukan.'];
        }

        $definition = olt_driver_definition((string)device_driver_type($olt));
        if (!is_array($definition) || empty($definition['pon_description'])) {
            return ['ok'=>false,'message'=>'Perangkat ini belum mendukung keterangan PON.'];
        }

        $pon = max(1, min(64, $pon));

        $description = $clear ? '' : trim($description);
        if (mb_strlen($description) > 128) {
            return ['ok'=>false,'message'=>'Keterangan PON maksimal 128 karakter.'];
        }

        try {
            $descriptionDriver = $this->driverForOlt($olt);
            $result = $portRef !== '' && $descriptionDriver instanceof OltPonByRefAware
                ? $descriptionDriver->setPonPortConfigByRef($portRef, ['olt_port_description' => $description])
                : $descriptionDriver->setPonPortConfig($pon, ['olt_port_description' => $description]);
        } catch (Throwable $e) {
            return ['ok'=>false,'message'=>'Keterangan PON gagal disimpan.','error'=>(string)$e];
        }
        if (empty($result['ok'])) {
            return $result;
        }


        $stored = ['ok' => false, 'changed' => false, 'previous' => '', 'port_ref' => '', 'event' => null];
        try {
            $stored = $this->configWriter()->commitPonDescription(
                $userId,
                (int)$olt['id'],
                (int)$olt['olt_id'],
                'snmp',
                $portRef,
                $pon,
                $description,
                $this->repository->getUserDisplayName($userId)
            );
        } catch (Throwable $storeError) {
            $stored = ['ok' => false, 'changed' => false, 'previous' => '', 'port_ref' => '', 'event' => null];
        }

        if (empty($stored['ok'])) {
            return ['ok' => false, 'partial' => true, 'message' => 'Keterangan PON terkirim, tetapi tidak tersimpan di aplikasi.'];
        }

        return [
            'ok'=>true,
            'pon'=>$pon,
            'description'=>$description,
            'port_ref'=>(string)($stored['port_ref'] ?? '') !== '' ? (string)$stored['port_ref'] : $portRef,
            'stored'=>!empty($stored['ok']),
            'changed'=>!empty($stored['changed']),
            'event_id'=>(int)($stored['event']['id'] ?? 0),
            'written_at'=>date('Y-m-d H:i:s'),
            'message'=>$description === ''
                ? 'Keterangan PON ' . $pon . ' dihapus.'
                : 'Keterangan PON ' . $pon . ' "' . $description . '" tersimpan.',
        ];
    }

    public function updatePonModal(
        int $userId,
        int $oltRowId,
        int $pon,
        string $portRef,
        string $enable,
        string $previousEnable,
        string $description,
        string $previousDescription
    ): array {
        $olt = $this->repository->getOltByRowId($userId, $oltRowId);
        $definition = is_array($olt)
            ? monitoring_driver_definition((string)device_driver_type($olt))
            : null;
        if (!is_array($olt) || !is_array($definition) || !monitoring_row_uses_handler($olt, 'snmp_olt')) {
            return ['ok' => false, 'message' => 'Konfigurasi modal ini belum didukung perangkat.'];
        }

        $enable = trim($enable);
        $previousEnable = trim($previousEnable);
        $description = trim($description);
        $previousDescription = trim($previousDescription);
        if ($enable !== '' && !in_array($enable, ['Enable', 'Disable'], true)) {
            return ['ok' => false, 'message' => 'Status interface PON tidak valid.'];
        }
        if (mb_strlen($description) > 128) {
            return ['ok' => false, 'message' => 'Keterangan PON maksimal 128 karakter.'];
        }

        $changed = [];
        $statusMessage = '';
        if ($enable !== '' && strcasecmp($enable, $previousEnable) !== 0) {
            $statusResult = $this->updatePonPortConfig($userId, $oltRowId, $pon, 'olt_port_enable', $enable, $portRef);
            if (empty($statusResult['ok'])) return $statusResult;
            $changed[] = 'status interface';
            $statusMessage = strcasecmp($enable, 'Disable') === 0 ? 'PON berhasil dimatikan.' : 'PON berhasil dihidupkan.';
        }
        if ($description !== $previousDescription) {
            if (!empty($definition['pon_description'])) {
                $descriptionResult = $this->updatePonPortDescription(
                    $userId,
                    $oltRowId,
                    $pon,
                    $description,
                    $description === '',
                    $portRef
                );
            } else {
                try {
                    $descriptionResult = $this->configWriter()->writePonDescription(
                        $userId,
                        (int)$olt['id'],
                        $portRef,
                        $pon,
                        $description,
                        (string)($olt['source'] ?? 'snmp')
                    );
                } catch (Throwable) {
                    $descriptionResult = ['ok' => false, 'message' => 'Keterangan PON gagal disimpan.'];
                }
            }
            if (empty($descriptionResult['ok'])) {
                return [
                    'ok' => false,
                    'partial' => $changed !== [] || !empty($descriptionResult['partial']),
                    'message' => $changed !== []
                        ? 'Status interface tersimpan, tetapi keterangan PON gagal diperbarui.'
                        : (string)($descriptionResult['message'] ?? 'Keterangan PON gagal diperbarui.'),
                    'error' => $descriptionResult['error'] ?? null,
                ];
            }
            $changed[] = 'keterangan';
        }

        return [
            'ok' => true,
            'changed' => $changed !== [],
            'message' => $statusMessage !== ''
                ? $statusMessage
                : ($changed === []
                    ? 'Tidak ada perubahan konfigurasi PON.'
                    : 'Perubahan ' . implode(' dan ', $changed) . ' PON berhasil disimpan.'),
        ];
    }

    public function updatePonPortConfig(
        int $userId,
        int $oltRowId,
        int $pon,
        string $field,
        string $value,
        string $portRef = ''
    ): array {
        $result = ['ok' => false, 'message' => 'OLT tidak ditemukan.', 'field' => $field, 'value' => $value];
        $olt = $this->repository->getOltByRowId($userId, $oltRowId);
        if ($olt === null || !monitoring_row_uses_handler($olt, 'snmp_olt')) return $result;

        $definition = olt_driver_definition((string)device_driver_type($olt));
        $supported = is_array($definition) ? (array)($definition['pon_port_config'] ?? []) : [];
        if (!in_array($field, $supported, true)) {
            return [
                'ok' => false,
                'unsupported' => true,
                'message' => 'Perangkat ini belum mendukung perubahan kolom ini lewat SNMP.',
                'field' => $field,
                'value' => $value,
            ];
        }

        $pon = max(1, min(64, $pon));
        try {
            $driver = $this->driverForOlt($olt);
            $write = $portRef !== '' && $driver instanceof OltPonByRefAware
                ? $driver->setPonPortConfigByRef($portRef, [$field => $value])
                : $driver->setPonPortConfig($pon, [$field => $value]);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Perubahan PON gagal dikirim.',
                'error' => (string)$e,
                'field' => $field,
                'value' => $value,
            ];
        }
        if (empty($write['ok'])) {
            return [
                'ok' => false,
                'unsupported' => !empty($write['unused']),
                'message' => (string)($write['message'] ?? 'Perubahan PON gagal disimpan.'),
                'field' => $field,
                'value' => $value,
            ];
        }

        if ($field === 'olt_port_flow_control') {
            if (!$this->configWriter()->writePonFlowControl($userId, (int)$olt['id'], $portRef, $pon, $value)) {
                return ['ok' => false, 'message' => 'Flow Control terkirim, tetapi tidak tersimpan.', 'field' => $field, 'value' => $value];
            }
        }
        if (in_array($field, ['olt_port_enable', 'enable'], true)) {
            if (!$this->configWriter()->writePonAdminState($userId, (int)$olt['id'], $portRef, $pon, $value)) {
                return ['ok' => false, 'partial' => true, 'message' => 'Status PON terkirim, tetapi tidak tersimpan.', 'field' => $field, 'value' => $value];
            }
        }

        $payload = [
            'ok' => true,
            'message' => (string)($write['message'] ?? 'Status PON tersimpan.'),
            'field' => $field,
            'value' => $value,
        ];
        foreach (['oid', 'device_value'] as $detailKey) {
            if (array_key_exists($detailKey, $write)) $payload[$detailKey] = $write[$detailKey];
        }
        return $payload;
    }

    private function snapshotWriter(): OltSnapshotWriteService
    {
        if ($this->snapshotWriter === null) {
            require_once project_path('backend', 'services', 'OltSnapshotWriteService.php');
            $this->snapshotWriter = new OltSnapshotWriteService($this->repository);
        }
        return $this->snapshotWriter;
    }

    private function onuWriter(): OnuSnapshotWriteService
    {
        if ($this->onuWriter === null) {
            require_once project_path('backend', 'services', 'OnuSnapshotWriteService.php');
            $this->onuWriter = new OnuSnapshotWriteService($this->repository);
        }
        return $this->onuWriter;
    }

    private function configWriter(): OltConfigurationWriteService
    {
        if ($this->configWriter === null) {
            require_once project_path('backend', 'services', 'OltConfigurationWriteService.php');
            $this->configWriter = new OltConfigurationWriteService($this->repository);
        }
        return $this->configWriter;
    }

    private function sampleWriter(): TrafficSampleWriteService
    {
        if ($this->sampleWriter === null) {
            require_once project_path('backend', 'services', 'TrafficSampleWriteService.php');
            $this->sampleWriter = new TrafficSampleWriteService($this->repository);
        }
        return $this->sampleWriter;
    }

    private function applyBufferedSnapshot(
        int $userId,
        int $rowId,
        array $olt,
        array $snapshot,
        bool $recordChanges
    ): void {
        $result = $this->snapshotWriter()->writeBufferedSnapshot(
            $userId,
            $rowId,
            $olt,
            $snapshot,
            $recordChanges
        );
        if (!empty($result['middle_completed'])) {
            @touch(cache_path('olt-middle-' . $userId . '-' . $rowId . '.stamp'));
        }
        if (!empty($result['inventory_discovered'])) {
            $this->driverForOlt($olt)->markInventoryDiscovery();
        }
        if (!empty($result['mark_monitoring'])) {
            $this->snapshotWriter()->markMonitoringSync($userId, (string)device_driver_type($olt));
        }
    }

    private function storePendingSnapshot(int $userId, int $rowId, array $snapshot): void
    {
        $path = $this->pendingSnapshotPath($userId, $rowId);
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Penyimpanan sementara tidak dapat dibuat.');
        }
        $json = json_encode(
            $snapshot,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        $temporary = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(6));
        if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
            $failure = error_get_last();
            @unlink($temporary);
            throw new RuntimeException(
                'Data sementara gagal disimpan'
                . (is_array($failure) && trim((string)($failure['message'] ?? '')) !== ''
                    ? ': ' . trim((string)$failure['message'])
                    : '.')
            );
        }
        if (!@rename($temporary, $path)) {
            $failure = error_get_last();
            @unlink($temporary);
            throw new RuntimeException(
                'Data sementara gagal dipindahkan'
                . (is_array($failure) && trim((string)($failure['message'] ?? '')) !== ''
                    ? ': ' . trim((string)$failure['message'])
                    : '.')
            );
        }
        @chmod($path, 0660);
    }

    private function loadPendingSnapshot(int $userId, int $rowId): ?array
    {
        $path = $this->pendingSnapshotPath($userId, $rowId);
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('Data sementara tidak dapat dibaca.');
        }
        $snapshot = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot) || (int)($snapshot['version'] ?? 0) !== 1) {
            throw new RuntimeException('Format data sementara tidak valid.');
        }
        return $snapshot;
    }

    private function clearPendingSnapshot(int $userId, int $rowId): void
    {
        $path = $this->pendingSnapshotPath($userId, $rowId);
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('Data sementara tidak dapat dibersihkan.');
        }
    }

    private function pendingSnapshotPath(int $userId, int $rowId): string
    {
        $directory = ensure_data_directory(cache_path());

        return $directory . DIRECTORY_SEPARATOR . 'snmp-olt-' . $userId . '-' . $rowId . '.json';
    }

    private function onuCoordinates(array $onu): array
    {
        $ponText = trim((string)($onu['physical'] ?? ''));
        $ponParts = array_values(array_filter(explode('/', $ponText), 'strlen'));
        $pon = (int)($ponParts[count($ponParts)-1] ?? 0);
        $indexParts = explode(':', (string)($onu['onu_index'] ?? ''));
        $onuId = (int)end($indexParts);
        if ($pon < 1 || $onuId < 1) throw new RuntimeException('Index PON/ONU tidak valid.');
        return [$pon,$onuId];
    }

    private function ponPortTargets(OltSnmpDriver $driver, array $olt): array
    {
        if ($driver instanceof OltCardAwareDriver) {
            $targets = [];
            foreach ($driver->listPonPorts() as $port) {
                $ref = trim((string)($port['ref'] ?? ''));
                if ($ref === '') continue;
                $targets[] = ['ref' => $ref, 'number' => max(0, (int)($port['port'] ?? 0))];
            }
            return $targets;
        }

        $targets = [];
        $count = $driver instanceof OltPonCountAware ? max(1, $driver->ponPortCount()) : $this->ponLoopCount($olt);
        for ($pon = 1; $pon <= $count; $pon++) {
            $targets[] = ['ref' => '', 'number' => $pon];
        }
        return $targets;
    }

    public static function normalizePonPath(string $path): string
    {
        $clean = str_replace('_', '/', trim((string)preg_replace('/^gpon[\s_-]*(?:olt)?[\s_-]*/i', '', trim($path))));
        if (preg_match('/(\d+)\s*\/\s*(\d+)\s*\/\s*(\d+)/', $clean, $m) !== 1) return '';
        return (int)$m[1] . '/' . (int)$m[2] . '/' . (int)$m[3];
    }

    private function syncCardInventory(int $userId, array $olt, OltSnmpDriver $driver): void
    {
        if (!$driver instanceof OltCardAwareDriver) return;
        $oltDeviceId = (int)($olt['id'] ?? 0);
        if ($oltDeviceId < 1) return;

        try {
            $cards = $driver->cardInventory();
            if ($cards === []) return;
            $this->snapshotWriter()->writeCards($userId, $oltDeviceId, $cards);
        } catch (Throwable $error) {
            error_log((string)$error);
        }
    }

    private function ponLoopCount(array $olt): int
    {
        $definition = olt_driver_definition((string)device_driver_type($olt));
        return max(1, (int)(is_array($definition) ? ($definition['pon_fixed_ports'] ?? 2) : 2));
    }
    public function sampleOltPonTraffic(int $userId, int $oltRowId, int $intervalSeconds = 180): int
    {
        $olt = $this->repository->getOltByRowId($userId, $oltRowId);
        if (!is_array($olt) || !monitoring_row_uses_handler($olt, 'snmp_olt')) return 0;
        if (!$this->driverAllowed($olt)) return 0;
        $oltDeviceId = (int)($olt['id'] ?? 0);
        if ($oltDeviceId < 1) return 0;
        $dipilih = $this->repository->getDeviceTrafficInterfaces($userId, $oltDeviceId);
        if ($dipilih === []) return 0;

        try {
            $driver = $this->driverForOlt($olt);
        } catch (Throwable $error) {
            return 0;
        }
        if (!$driver instanceof OltPonTrafficAware) return 0;

        try {
            $samples = $driver->readPonTrafficBps($intervalSeconds, true, $dipilih);
            if (!is_array($samples) || $samples === []) return 0;

            $tanpaPrefix = static fn(string $ref): string => (string)preg_replace('/^[a-z]+_/i', '', $ref);
            $samples = array_filter(
                $samples,
                static function ($row, $ref) use ($dipilih, $tanpaPrefix): bool {
                    $ref = (string)$ref;
                    if (isset($dipilih[$ref])) return true;
                    foreach ($dipilih as $wanted => $index) {
                        if ($tanpaPrefix((string)$wanted) === $tanpaPrefix($ref)) return true;
                    }
                    return false;
                },
                ARRAY_FILTER_USE_BOTH
            );
            if ($samples === []) return 0;

            $written = $this->sampleWriter()->writeOltPon($userId, $oltDeviceId, $samples, date('Y-m-d H:i:00'));
            $this->sampleWriter()->pruneOltPon($userId, $oltDeviceId);
            return (int)$written;
        } catch (Throwable $error) {
            error_log((string)$error);
            return 0;
        }
    }

    private function syncProfileInventory(int $userId, array $olt, OltSnmpDriver $driver): void
    {
        if (!$driver instanceof OltProfileAware) return;
        $oltDeviceId = (int)($olt['id'] ?? 0);
        if ($oltDeviceId < 1) return;

        try {
            $result = $driver->profileInventory();
            if (empty($result['ok'])) return;
            $this->snapshotWriter()->writeProfileSnapshots(
                $userId,
                $oltDeviceId,
                (array)($result['rows'] ?? []),
                (array)($result['kinds'] ?? [])
            );
        } catch (Throwable $error) {
            error_log((string)$error);
        }
    }

    private function refreshDeviceTimeSnapshot(int $userId, int $rowId, OltSnmpDriver $driver, bool $recordChanges): void
    {
        if (!$driver instanceof OltDeviceTimeAware) return;
        try {
            $deviceTime = trim($driver->readDeviceTime());
        } catch (Throwable) {
            return;
        }
        if ($deviceTime === '') return;
        $snapshot = $this->repository->getOltSystemSnapshot($userId, $rowId);
        if (trim((string)($snapshot['current_time'] ?? '')) === $deviceTime) return;
        $this->snapshotWriter()->mergeSystemFields($userId, $rowId, ['current_time'=>$deviceTime], [], 'fast');
    }

    private function refreshDeviceUptimeSnapshot(int $userId, int $rowId, OltSnmpDriver $driver, bool $recordChanges): void
    {
        if (!method_exists($driver, 'readDeviceUptime')) return;
        try {
            $runtimeSeconds = $this->uptimeSeconds($driver->readDeviceUptime());
        } catch (Throwable) {
            return;
        }
        if ($runtimeSeconds === null) return;
        $snapshot = $this->repository->getOltSystemSnapshot($userId, $rowId);
        if (($snapshot['runtime_seconds'] ?? null) === $runtimeSeconds) return;
        $this->snapshotWriter()->mergeSystemFields($userId, $rowId, ['runtime_seconds'=>$runtimeSeconds], [], 'fast');
    }

    private function syncMiddleHealth(int $userId, int $rowId, array $olt, OltSnmpDriver $driver): void
    {
        $system = [];
        if (method_exists($driver, 'readMiddleSystemHealth')) {
            try {
                $health = $driver->readMiddleSystemHealth();
                foreach (['cpu_percent', 'memory_percent'] as $field) {
                    if (is_numeric($health[$field] ?? null)) $system[$field] = (float)$health[$field];
                }
            } catch (Throwable $error) {
                error_log('Middle system health sync failed: ' . $error->getMessage());
            }
        }
        if ($driver instanceof OltFanAware) {
            try {
                $fan = $driver->readFanSnapshot();
                if ($fan !== []) {
                    if (is_array($fan['fans'] ?? null)) $system['fans'] = array_values($fan['fans']);
                    if (isset($fan['fanboard_status'])) $system['fanboard_status'] = $fan['fanboard_status'];
                    if (is_numeric($fan['environment_temperature'] ?? null)) {
                        $system['environment_temperature'] = (float)$fan['environment_temperature'];
                    }
                }
            } catch (Throwable $error) {
                error_log('Middle fan sync failed: ' . $error->getMessage());
            }
        }
        if ($system !== []) $this->snapshotWriter()->mergeSystemFields($userId, $rowId, $system, [], 'middle');
        if (method_exists($driver, 'readCardTelemetry')) {
            try {
                $telemetry = $driver->readCardTelemetry(false);
                if (is_array($telemetry) && $telemetry !== []) {
                    $this->snapshotWriter()->writeCardHealth($userId, (int)$olt['id'], $telemetry);
                }
            } catch (Throwable $error) {
                error_log('Middle card health sync failed: ' . $error->getMessage());
            }
        }
    }

    private function readSystemInfoSnapshot(OltSnmpDriver $driver, array $olt): array
    {
        try {
            $info = $driver->getSystemInfo(false, false);
        } catch (Throwable) {
            return [];
        }
        $data = is_array($info['data'] ?? null) ? $info['data'] : [];
        if ($data === []) return [];

        $snapshot = [];
        $fields = [
            'model' => ['model'],
            'vendor' => ['vendor'],
            'software_version' => ['software_version'],
            'revision' => ['revision'],
            'mac' => ['mac'],
            'serial' => ['serial'],
            'system_name' => ['system_name', 'name'],
            'object_id' => ['object_id'],
            'contact' => ['contact'],
            'location' => ['location'],
        ];
        foreach ($fields as $target => $keys) {
            foreach ($keys as $key) {
                $value = trim((string)($data[$key] ?? ''));
                if ($value === '') continue;
                $snapshot[$target] = $value;
                break;
            }
        }

        foreach (['temperature'] as $healthField) {
            if (isset($data[$healthField]) && is_numeric($data[$healthField])) {
                $snapshot[$healthField] = (float)$data[$healthField];
            }
        }

        if ($snapshot !== [] && !array_key_exists('vendor', $snapshot)) $snapshot['vendor'] = null;
        $ipAddress = trim((string)($olt['ip_address'] ?? ''));
        if ($ipAddress !== '') $snapshot['ip_address'] = $ipAddress;
         if ($snapshot === []) return [];
        $snapshot['source'] = 'SNMP';

        return $snapshot;
    }

    private function driverForOlt(array $olt): OltSnmpDriver
    {
        $community = trim((string)($olt['snmp_community'] ?? ''));
        if ($community === '') throw new RuntimeException('Community OLT belum tersedia.');
        return $this->newDriver((int)$olt['user_id'],
            (string)device_driver_type($olt),
            (string)$olt['ip_address'],
            $community,
            (int)$olt['id'],
            (int)($olt['snmp_port'] ?? 161),
            $this->cliOpticalConfig($olt)
        );
    }

    private function oltDriverSupports(array $olt, string $interface): bool
    {
        $definition = monitoring_driver_definition((string)device_driver_type($olt));
        $class = is_array($definition) ? trim((string)($definition['driver_class'] ?? '')) : '';
        return $class !== '' && class_exists($class) && is_subclass_of($class, $interface);
    }

    private function cliOpticalConfig(array $olt): ?array
    {
        if (!$this->cliEnabledNow($olt)) return null;
        $definition = monitoring_driver_definition((string)device_driver_type($olt));
        if (!is_array($definition) || empty($definition['telnet_cli'])) return null;

        $host = trim((string)($olt['ip_address'] ?? ''));
        $username = trim((string)($olt['username'] ?? ''));
        $encrypted = trim((string)($olt['password_enc'] ?? ''));
        if ($host === '' || $username === '' || $encrypted === '') return null;

        try {
            $password = $this->cipher->decrypt($encrypted);
        } catch (Throwable) {
            return null;
        }
        if (trim($password) === '') return null;

        return [
            'host' => $host,
            'port' => max(1, min(65535, (int)($olt['ports'] ?? 23))),
            'username' => $username,
            'password' => $password,
            'budget_seconds' => $this->cliOpticalBudgetSeconds($olt),
        ];
    }

    private function recordSecondaryConnectionStatus(int $userId, array $olt): void
    {
        $deviceId = (int)($olt['id'] ?? 0);
        if ($deviceId < 1) return;
        $definition = monitoring_driver_definition((string)device_driver_type($olt));
        $telnetEnabled = is_array($definition) && !empty($definition['telnet_cli']);
        $hasCliCreds = trim((string)($olt['username'] ?? '')) !== ''
            && trim((string)($olt['password_enc'] ?? '')) !== '';
        if (!$hasCliCreds || !$telnetEnabled) {
            $this->snapshotWriter()->markConnectionStatus($deviceId, $userId, 'cli', '');
            return;
        }

        
        
        try {
            $password = $this->cipher->decrypt((string)$olt['password_enc']);
        } catch (Throwable) {
            $this->snapshotWriter()->markConnectionStatus($deviceId, $userId, 'cli', '');
            return;
        }

        try {
            $reader = (new \App\Driver\Controller())->createCli((string)device_driver_type($olt), [
                'host' => (string)$olt['ip_address'],
                'port' => (int)($olt['ports'] ?? 23),
                'username' => (string)$olt['username'],
                'password' => $password,
                'timeout_seconds' => 20,
                'read_wait_ms' => 500,
            ]);
        } catch (Throwable) {
            $this->snapshotWriter()->markConnectionStatus($deviceId, $userId, 'cli', '');
            return;
        }
        if (!$reader instanceof OltTerminalCliClient) {
            $this->snapshotWriter()->markConnectionStatus($deviceId, $userId, 'cli', '');
            return;
        }
        try {
            try {
                $result = $reader->probeConnection();
            } catch (Throwable $error) {
                $result = ['ok'=>false,'message'=>$error->getMessage()];
            }
            $connected = !empty($result['ok']) || !empty($result['busy']);
            $reason = trim((string)($result['message'] ?? ''));
            $this->snapshotWriter()->recordConnectionProbe(
                $deviceId,
                $userId,
                'cli',
                $connected,
                $reason !== '' ? $reason : 'Koneksi Telnet tidak merespons.'
            );
            if (!$connected) {
                $name = trim((string)($olt['name'] ?? '')) ?: 'OLT #' . $deviceId;
                try {
                    $this->repository->logApplication(
                        $userId,
                        'OLT CLI',
                        'telnet_connection',
                        'error',
                        'Koneksi Telnet ' . $name . ' gagal: ' . ($reason !== '' ? $reason : 'perangkat tidak merespons.')
                    );
                } catch (Throwable $logError) {
                    error_log((string)$logError);
                }
            }
        } finally {
            $reader->close();
        }
    }

    private function cliOpticalBudgetSeconds(array $olt): int
    {
        if ((int)($olt['hybrid_status'] ?? 0) !== 1) return 0;
        $definition = monitoring_driver_definition((string)device_driver_type($olt));
        if (!is_array($definition) || empty($definition['telnet_cli'])) return 0;
        $pons = 0;
        $userId = (int)($olt['user_id'] ?? 0);
        $oltDeviceId = (int)($olt['id'] ?? 0);
        if ($userId > 0 && $oltDeviceId > 0) {
            try {
                $pons = count($this->repository->getOltPons($userId, $oltDeviceId, false));
            } catch (Throwable) {
                $pons = 0;
            }
        }
        if ($pons < 1) return self::CLI_OPTICAL_MAX_BUDGET_SECONDS;

        return max(
            self::CLI_OPTICAL_MIN_BUDGET_SECONDS,
            min(self::CLI_OPTICAL_MAX_BUDGET_SECONDS, $pons * self::CLI_OPTICAL_BUDGET_PER_PON_SECONDS)
        );
    }

    private function purgeOltLocalState(int $userId, int $rowId): void
    {
        $driverCache = cache_path('snmp-olt-' . max(0, $rowId) . '.json');
        if (is_file($driverCache)) @unlink($driverCache);
        $pendingSnapshot = $this->pendingSnapshotPath($userId, $rowId);
        if (is_file($pendingSnapshot)) @unlink($pendingSnapshot);
    }

    private function clearConfigChangeMarker(int $userId, int $rowId, array $olt, bool $pending): void
    {
        if (!$pending) return;
        $this->configWriter()->clearConfigChanged($userId, $rowId);
        $this->repository->logApplication(
            $userId,
            'OLT SNMP',
            'config_reload',
            'info',
            trim((string)($olt['name'] ?? '')) . ': setelan terbaru sudah dimuat ulang.'
        );
    }

    private function newDriver(int $userId, string $driverId,string $ip, string $community, int $rowId, int $snmpPort = 161, ?array $cliOptical = null): OltSnmpDriver
    {
        $settings = (array)($this->config['snmp_devices'] ?? []);
        $timeoutMs = $this->repository->getSyncTimingSettings($userId)['snmp_request_timeout_ms'];
        if ($timeoutMs > 0) $settings['timeout_us'] = $timeoutMs * 1000;
        if ($cliOptical !== null) $settings['cli_optical'] = $cliOptical;
        return $this->driverFactory->create($driverId,$ip,$community,$rowId,$snmpPort,$settings);
    }

    private function oltWithCliDriver(int $userId, int $oltRowId, string $unsupportedMessage): array
    {
        $olt = $this->repository->getOltByRowId($userId, $oltRowId);
        if (!is_array($olt) || !monitoring_row_uses_handler($olt, 'snmp_olt')) {
            return ['ok' => false, 'message' => 'OLT SNMP tidak ditemukan.'];
        }
        if (!$this->driverAllowed($olt)) {
            return ['ok' => false, 'message' => 'Driver OLT ini tidak diizinkan atau sedang dinonaktifkan.'];
        }
        $definition = monitoring_driver_definition((string)device_driver_type($olt));
        if (!is_array($definition) || empty($definition['telnet_cli'])) {
            return ['ok' => false, 'message' => 'Driver OLT ini belum mendukung koneksi Telnet.'];
        }
        if ((int)($olt['hybrid_status'] ?? 0) !== 1) {
            return ['ok' => false, 'message' => 'Koneksi Telnet (CLI) belum diaktifkan pada OLT ini.'];
        }
        $driver = $this->driverForOlt($olt);
        if (!$driver instanceof OltCliOpticalAware) {
            return ['ok' => false, 'message' => $unsupportedMessage];
        }
        if ($driver instanceof OltPonPathHintsAware) {
            $driver->setPonPathHints($this->ponPathHints($userId, $olt));
        }
        return ['ok' => true, 'olt' => $olt, 'driver' => $driver];
    }

    private function ponPathHints(int $userId, array $olt): array
    {
        $hints = [];
        foreach ($this->repository->getOltPons($userId, (int)($olt['id'] ?? 0)) as $pon) {
            $path = trim((string)($pon['label'] ?? ''));
            if ($path === '') $path = trim((string)($pon['port_ref'] ?? ''));
            $ref = self::normalizePonPath($path);
            if ($ref === '') continue;
            $hints[$ref] = $ref;
        }
        return $hints;
    }

    public function testCliOptical(int $userId, int $oltRowId): array
    {
        
        
        $oltRow = $this->repository->getOltByRowId($userId, $oltRowId);
        if (!is_array($oltRow) || !$this->driverAllowed($oltRow)) {
            return ['ok' => false, 'message' => 'Driver OLT ini tidak diizinkan atau sedang dinonaktifkan.'];
        }
        if (is_array($oltRow)) {
            $oltRowDefinition = monitoring_driver_definition((string)device_driver_type($oltRow));
            if (($oltRowDefinition['pon_module_source'] ?? '') === 'cli'
                && (int)($oltRow['hybrid_status'] ?? 0) === 1) {
                $endpoint = $this->cliEndpointForOlt($oltRow);
                $terminalClass = trim((string)($oltRowDefinition['cli_terminal_class'] ?? ''));
                $cliResult = ['ok' => false, 'message' => 'Klien CLI driver belum tersedia.'];
                if ($endpoint !== null && $terminalClass !== '' && class_exists($terminalClass)
                    && is_subclass_of($terminalClass, OltPonModuleCliAware::class)) {
                    try {
                        $client = (new \App\Driver\Controller())->createCli((string)device_driver_type($oltRow), $endpoint);
                        if (!$client instanceof OltPonModuleCliAware) {
                            throw new RuntimeException('Driver CLI tidak mendukung modul PON.');
                        }
                        $connected = $client->connectForTerminal();
                        $cliResult = !empty($connected['ok'])
                            ? ['ok' => true, 'message' => 'Terminal driver berhasil terhubung.']
                            : ['ok' => false, 'message' => (string)($connected['message'] ?? 'Koneksi CLI gagal.')];
                    } catch (Throwable $error) {
                        $cliResult = ['ok' => false, 'message' => $error->getMessage()];
                    } finally {
                        if (isset($client) && is_object($client) && method_exists($client, 'close')) $client->close();
                    }
                }
                $this->repository->logApplication(
                    $userId,
                    'OLT CLI',
                    'test_connection',
                    !empty($cliResult['ok']) ? 'success' : 'error',
                    (string)($cliResult['message'] ?? '')
                );
                return $cliResult;
            }
        }

        $context = $this->oltWithCliDriver($userId, $oltRowId, 'Driver ini belum menyediakan pengujian CLI.');
        if (empty($context['ok'])) return $context;

        $olt = (array)$context['olt'];
        $driver = $context['driver'];

        $targets = [];
        if ($driver instanceof OltCardAwareDriver) {
            foreach ($driver->listPonPorts() as $port) {
                $ref = trim((string)($port['ref'] ?? ''));
                if ($ref === '') continue;
                $targets[$ref] = true;
                if (count($targets) >= 8) break;
            }
        }
        if ($targets === []) {
            foreach ($this->repository->getOltPons($userId, (int)($olt['id'] ?? 0)) as $pon) {
                $number = (int)($pon['pon_number'] ?? 0);
                if ($number > 0) $targets[$number] = true;
            }
        }
        if ($targets === []) {
            for ($pon = 1; $pon <= 8; $pon++) $targets[$pon] = true;
        }

        $result = $driver->probeCliOptical(array_keys($targets));
        $this->repository->logApplication(
            $userId,
            'OLT CLI',
            'test_connection',
            !empty($result['ok']) ? 'success' : 'error',
            (string)($result['message'] ?? '')
        );
        return $result;
    }

    
    public function refreshOltOptical(int $userId, int $oltRowId): array
    {
        $context = $this->oltWithCliDriver($userId, $oltRowId, 'Driver ini belum menyediakan pembaca daya optik CLI.');
        if (empty($context['ok'])) return $context;

        $olt = (array)$context['olt'];
        $driver = $context['driver'];
        if (!$driver instanceof OltCliOpticalAware) {
            return ['ok' => false, 'message' => 'Driver ini belum menyediakan pembaca daya optik CLI.'];
        }

        $rows = $this->onuRowsFromStorage($this->repository->getOnus($userId, (int)($olt['olt_id'] ?? 0), true, 'snmp'));
        if ($rows === []) {
            return ['ok' => false, 'message' => 'Belum ada ONU tersimpan yang bisa dibaca daya optiknya.'];
        }

        $targets = [];
        foreach ($rows as $row) {
            $ref = self::normalizePonPath((string)($row['pon'] ?? ''));
            if ($ref !== '') {
                $targets[$ref] = true;
                continue;
            }
            $ponNumber = (int)($row['pon_number'] ?? 0);
            if ($ponNumber > 0) $targets[$ponNumber] = true;
        }

        $optical = [];
        $failed = [];
        foreach (array_keys($targets) as $target) {
            $pon = !is_int($target) && $driver instanceof OltCliOpticalAware
                ? $driver->readPonOpticalByRef((string)$target)
                : $driver->readPonOptical(is_int($target) ? $target : OltPonReference::numberFromPath((string)$target));
            if (empty($pon['ok']) || (array)($pon['onus'] ?? []) === []) {
                $failed[] = 'PON ' . $target . ': ' . (string)($pon['message'] ?? 'tidak terbaca');
                continue;
            }
            $optical[$target] = (array)$pon['onus'];
        }
        if ($optical === []) {
            return [
                'ok' => false,
                'message' => 'Daya optik ONU tidak terbaca lewat CLI. ' . implode(' | ', array_slice($failed, 0, 3)),
                'failed' => $failed,
            ];
        }

        $merged = OltOpticalValues::applyValues($rows, $optical);
        $merged = array_values(array_filter($merged, static fn(array $row): bool => (string)($row['optical_source'] ?? '') === 'cli'));
        $updated = count($merged);
        if ($updated === 0) {
            return ['ok' => false, 'message' => 'Perangkat tidak melaporkan nilai daya optik untuk ONU yang tersimpan.'];
        }

        $this->onuWriter()->replaceOnus($userId, (int)($olt['olt_id'] ?? 0), $merged, true, false, false, 'optical_action');

        $message = 'Daya optik ' . $updated . ' ONU diperbarui lewat CLI pada PON ' . implode(',', array_keys($optical)) . '.';
        if ($failed !== []) $message .= ' Sebagian PON gagal: ' . implode(' | ', array_slice($failed, 0, 3)) . '.';
        $this->repository->logApplication($userId, 'OLT CLI', 'refresh_optical', 'success', $message);

        return [
            'ok' => true,
            'message' => $message,
            'updated' => $updated,
            'pons' => array_keys($optical),
            'failed' => $failed,
        ];
    }

    
    private function oltServiceOptions(int $userId, int $oltDeviceId): array
    {
        $out = ['vlans' => [], 'tcont' => [], 'traffic' => [], 'vlan_profiles' => [], 'ip_profiles' => []];
        if ($oltDeviceId < 1) return $out;
        foreach ($this->repository->getOltProfileSnapshots($userId, $oltDeviceId) as $row) {
            $kind = (string)($row['kind'] ?? '');
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') continue;
            if ($kind === 'tcont') $out['tcont'][] = $name;
            elseif ($kind === 'traffic') $out['traffic'][] = $name;
            elseif ($kind === 'vlan_profile') $out['vlan_profiles'][$name] = (array)($row['data'] ?? []);
            elseif ($kind === 'ip') $out['ip_profiles'][] = $name;
        }
        $out['vlans'] = $this->repository->getOltVlanIds($userId, $oltDeviceId);
        return $out;
    }

    
    
    public function onuSupportsFullRegistration(int $userId, int $onuRowId): bool
    {
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if (!is_array($onu) || !monitoring_row_uses_handler($onu, 'snmp_olt')) return false;
        $oltRowId = (int)($onu['olt_row_id'] ?? 0);
        $olt = $oltRowId > 0 ? $this->repository->getOltByRowId($userId, $oltRowId) : null;
        try {
            $driver = is_array($olt)
                ? $this->driverForOlt($olt)
                : $this->newDriver($userId, (string)device_driver_type($onu), (string)$onu['olt_ip_address'], (string)$onu['snmp_community'], (int)$onu['olt_row_id'], (int)($onu['snmp_port'] ?? 161));
        } catch (Throwable $e) {
            return false;
        }
        return $driver instanceof OltOnuProvisionAware;
    }

    public function onuSupportsRename(int $userId, int $onuRowId): bool
    {
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if (!is_array($onu) || !monitoring_row_uses_handler($onu, 'snmp_olt')) return false;
        $olt = $this->repository->getOltByRowId($userId, (int)($onu['olt_row_id'] ?? 0));
        if (!is_array($olt)) return false;
        try {
            return $this->driverForOlt($olt) instanceof OltOnuRenameAware;
        } catch (Throwable) {
            return false;
        }
    }

    public function updateOnuType(int $userId, int $onuRowId, string $type): array
    {
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if (!is_array($onu)) return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];
        $olt = $this->repository->getOltByRowId($userId, (int)($onu['olt_row_id'] ?? 0));
        if (!is_array($olt)) return ['ok' => false, 'message' => 'OLT tidak ditemukan.'];
        if (!in_array($type, $this->repository->getAvailableOnuTypes($userId, (int)$olt['id']), true)) {
            return ['ok' => false, 'message' => 'ONU Type tidak tersedia.'];
        }
        $endpoint = $this->cliEndpointForOlt($olt);
        if ($endpoint === null) return ['ok' => false, 'message' => 'Koneksi perangkat belum tersedia.'];
        $client = $this->oltCliSession((int)$olt['id'], $endpoint);
        if (!$client instanceof OltOnuTypeAssignCliAware) return ['ok' => false, 'message' => 'Perubahan ONU Type belum tersedia.'];
        $parts = explode(':', (string)$onu['onu_index'], 2);
        try {
            $result = $client->setOnuType(str_replace('_', '/', (string)($parts[0] ?? '')), (int)($parts[1] ?? 0), $type, (string)($onu['onu_serial'] ?? ''));
        } catch (Throwable $e) {
            error_log((string)$e);
            return ['ok' => false, 'message' => 'ONU Type gagal diubah.'];
        } finally {
            $this->dropOltCliSession((int)$olt['id']);
        }
        if (empty($result['ok'])) return $result;
        try {
            $saved = $this->onuWriter()->writeType($userId, $onuRowId, (string)$result['type']);
        } catch (Throwable $error) {
            error_log('ONU type snapshot update failed: ' . $error->getMessage());
            $saved = false;
        }
        return ['ok' => true, 'refreshed' => $saved, 'message' => $saved
            ? 'Perintah ONU Type diterima OLT dan data tersimpan.'
            : 'Perintah ONU Type diterima OLT, tetapi data tersimpan tidak diperbarui.'];
    }

    public function preRegisterOnuFull(int $userId, int $oltDeviceId, string $pon, array $options): array
    {
        $olt = $this->repository->getOltByRowId($userId, $oltDeviceId);
        $definition = is_array($olt) ? monitoring_driver_definition((string)device_driver_type($olt)) : null;
        if (!is_array($olt) || (string)($olt['source'] ?? '') !== 'snmp'
            || !in_array('batch_provision', (array)($definition['onu_registration_ui'] ?? []), true)
            || !$this->oltDriverSupports($olt, OltOnuProvisionAware::class)
            || preg_match('~^\d+/\d+/\d+$~D', $pon) !== 1) {
            return ['ok' => false, 'message' => 'OLT atau PON pra-registrasi tidak valid.'];
        }
        $ponExists = false;
        $ponId = null;
        foreach ($this->repository->getOltPons($userId, $oltDeviceId) as $port) {
            if (preg_match('~(?:gpon-olt_)?(\d+/\d+/\d+)$~i', (string)($port['port_ref'] ?? ''), $match) === 1 && $match[1] === $pon) {
                $ponExists = true;
                $ponId = (int)($port['id'] ?? 0) ?: null;
                break;
            }
        }
        if (!$ponExists) return ['ok' => false, 'message' => 'PON tidak tersedia pada OLT.'];
        $serial = strtoupper(trim((string)($options['serial'] ?? '')));
        if (preg_match('/^[A-Z0-9]{12,16}$/D', $serial) !== 1) return ['ok' => false, 'message' => 'Serial ONU tidak valid.'];
        $services = (array)($options['services'] ?? []);
        if ($services === [] || count($services) > 4) return ['ok' => false, 'message' => 'Pra-registrasi memerlukan satu sampai empat Service.'];
        $allowed = $this->oltServiceOptions($userId, $oltDeviceId);
        $types = [];
        foreach ($this->repository->getOltOnuTypes($userId, $oltDeviceId) as $profile) {
            $types[] = (string)($profile['name'] ?? '');
        }
        $type = trim((string)($options['type'] ?? ''));
        if ($type === '' || ($types !== [] && !in_array($type, $types, true))) {
            return ['ok' => false, 'message' => 'ONU Type harus tersedia pada OLT.'];
        }
        foreach ($services as $service) {
            if (!is_array($service)) return ['ok' => false, 'message' => 'Service tidak valid.'];
            $vlan = (int)($service['vlan'] ?? 0);
            $outer = (int)($service['svlan'] ?? 0);
            $tcont = trim((string)($service['tcont_profile'] ?? ''));
            $traffic = trim((string)($service['traffic_profile'] ?? ''));
            if ($vlan < 1 || $vlan > 4094 || $tcont === '' || $traffic === ''
                || ($allowed['vlans'] !== [] && !in_array($vlan, $allowed['vlans'], true))
                || ($outer > 0 && $allowed['vlans'] !== [] && !in_array($outer, $allowed['vlans'], true))
                || ($allowed['tcont'] !== [] && !in_array($tcont, $allowed['tcont'], true))
                || ($allowed['traffic'] !== [] && !in_array($traffic, $allowed['traffic'], true))) {
                return ['ok' => false, 'message' => 'VLAN atau profil Service belum tersedia pada OLT.'];
            }
        }
        foreach ($services as $position => $service) {
            $method = (string)($service['wan_method'] ?? ($position === 0 ? ($options['wan_method'] ?? 'ont') : 'ont'));
            if ($method === 'ont') continue;
            $profileName = trim((string)($service['wan_vlan_profile'] ?? ($position === 0 ? ($options['wan_vlan_profile'] ?? '') : '')));
            $profile = $allowed['vlan_profiles'][$profileName] ?? null;
            if (!is_array($profile) || (int)($profile['cvlan'] ?? 0) !== (int)($service['vlan'] ?? 0)) {
                return ['ok' => false, 'message' => 'VLAN Profile WAN Service ' . (int)($service['number'] ?? ($position + 1)) . ' tidak sesuai.'];
            }
            $ipProfile = (string)($service['wan_ip_profile'] ?? ($position === 0 ? ($options['wan_ip_profile'] ?? '') : ''));
            if ($method === 'omci-static' && !in_array($ipProfile, $allowed['ip_profiles'], true)) {
                return ['ok' => false, 'message' => 'IP Profile WAN Static tidak tersedia pada OLT.'];
            }
        }
        try {
            $driver = $this->driverForOlt($olt);
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'Driver OLT tidak siap.'];
        }
        if (!$driver instanceof OltOnuProvisionAware || !method_exists($driver, 'readAvailableOnuIds')
            || !method_exists($driver, 'readOnuSerialAssignment')) {
            return ['ok' => false, 'message' => 'Driver belum mendukung pra-registrasi.'];
        }
        $available = $driver->readAvailableOnuIds($pon);
        if (empty($available['ok'])) return $available;
        $ids = (array)($available['ids'] ?? []);
        $mode = (string)($options['onu_id_mode'] ?? 'auto');
        if (!in_array($mode, ['auto', 'custom'], true)) return ['ok' => false, 'message' => 'Mode ONU ID tidak valid.'];
        $onuId = $mode === 'custom' ? (int)($options['onu_id_custom'] ?? 0) : (int)($ids[0] ?? 0);
        if ($onuId < 1 || !in_array($onuId, $ids, true)) return ['ok' => false, 'message' => 'ONU ID pilihan tidak tersedia pada PON.'];
        $ref = $pon . ':' . $onuId;
        $collision = $this->repository->pdo()->prepare('SELECT id FROM onus WHERE user_id=:uid AND olt_device_id=:dev AND onu_index=:idx LIMIT 1');
        $collision->execute(['uid' => $userId, 'dev' => $oltDeviceId, 'idx' => $ref]);
        if ($collision->fetchColumn()) return ['ok' => false, 'message' => 'ONU ID sudah tercatat pada aplikasi.'];
        $parsedName = onu_split_device_label((string)($options['name'] ?? ''));
        $name = $parsedName['name'] !== '' ? $parsedName['name'] : trim((string)($options['name'] ?? ''));
        $location = onu_location_value($options['location'] ?? null);
        $options['name'] = onu_join_device_label($name, $location);
        unset($options['location']);
        $result = $driver->registerOnuFull($pon, $onuId, $options);
        if (empty($result['ok'])) return $result;
        $assignment = $driver->readOnuSerialAssignment($serial, $ref);
        if (empty($assignment['ok']) || (string)($assignment['ref'] ?? '') !== $ref) {
            return ['ok' => false, 'message' => 'Registrasi tidak terverifikasi. Periksa ONU sebelum mengulang.'];
        }
        try {
            $observed = $driver instanceof OltOnuServiceAware
                ? $driver->readOnuServiceConfigTargeted($pon, $onuId, (array)($result['mgmt_lines'] ?? [])) : [];
        } catch (\Throwable) {
            $observed = [];
        }
        $actual = is_array($observed['config'] ?? null) ? $observed['config'] : [];
        $result['verified'] = $this->registeredOnuConfigMatches($actual, $services, $options);
        if (!$result['verified']) {
            return ['ok' => false, 'message' => 'Perintah dikirim, tetapi konfigurasi Service tidak terverifikasi. Data tidak disimpan; periksa OLT sebelum mengulang.'];
        }
        try {
            $rowId = $this->onuWriter()->writeProvisioned($userId, $oltDeviceId, $ref, $serial, $name, $location, $ponId);
        } catch (\Throwable $error) {
            return ['ok' => false, 'message' => 'Registrasi tidak tersimpan. Periksa ONU sebelum mengulang.'];
        }
        $result['onu_row_id'] = $rowId;
        $result['mgmt_lines'] = (array)($result['mgmt_lines'] ?? []);
        $result['observed_config'] = $actual;
        return $result;
    }

    public function registerOnuFull(int $userId, int $onuRowId, array $options): array
    {
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if (!is_array($onu) || !monitoring_row_uses_handler($onu, 'snmp_olt')) {
            return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];
        }
        $olt = $this->repository->getOltByRowId($userId, (int)($onu['olt_row_id'] ?? 0));
        if (!is_array($olt)) return ['ok' => false, 'message' => 'OLT tidak ditemukan.'];
        [$pon, $onuId] = $this->onuCoordinates($onu);

        try {
            $driver = $this->driverForOlt($olt);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Driver OLT tidak siap.'];
        }
        if (!$driver instanceof OltOnuProvisionAware) {
            return ['ok' => false, 'message' => 'Driver OLT ini belum mendukung registrasi satu batch.'];
        }

        $idMode = (string)($options['onu_id_mode'] ?? 'auto');
        $targetOnuId = $onuId;
        if (!in_array($idMode, ['auto', 'custom'], true)) return ['ok' => false, 'message' => 'Mode ONU ID tidak valid.'];
        if (!method_exists($driver, 'readAvailableOnuIds')) return ['ok' => false, 'message' => 'Driver tidak dapat memeriksa ONU ID pada OLT.'];
        $available = $driver->readAvailableOnuIds($pon);
        if (empty($available['ok'])) return ['ok' => false, 'message' => 'Daftar ONU ID kosong tidak terbaca pada OLT.'];
        $freeIds = (array)($available['ids'] ?? []);
        if ($idMode === 'auto' && !in_array($onuId, $freeIds, true)) {
            $assignment = method_exists($driver, 'readOnuSerialAssignment')
                ? $driver->readOnuSerialAssignment(strtoupper(trim((string)($options['serial'] ?? $onu['onu_serial'] ?? ''))), $pon . ':' . $onuId)
                : ['ok' => false];
            if (empty($assignment['ok'])) return ['ok' => false, 'message' => 'Status ONU pada OLT tidak dapat dipastikan.'];
            $assignedRef = (string)($assignment['ref'] ?? '');
            if ($assignedRef === '') {
                $targetOnuId = (int)($freeIds[0] ?? 0);
                if ($targetOnuId < 1) return ['ok' => false, 'message' => 'Tidak ada ONU ID kosong pada PON.'];
            }
        }
        if ($idMode === 'custom') {
            $targetOnuId = (int)($options['onu_id_custom'] ?? 0);
            if ($targetOnuId < 1 || $targetOnuId > 128) return ['ok' => false, 'message' => 'ONU ID wajib di antara 1 dan 128.'];
        }
        $oldRef = trim((string)($onu['onu_index'] ?? ''));
        $targetRef = $pon . ':' . $targetOnuId;
        if ($targetOnuId !== $onuId) {
            if (!in_array($targetOnuId, $freeIds, true)) {
                return ['ok' => false, 'message' => 'ONU ID pilihan tidak kosong pada OLT.'];
            }
            $collision = $this->repository->pdo()->prepare('SELECT id FROM onus WHERE user_id=:uid AND olt_device_id=:dev AND onu_index=:idx LIMIT 1');
            $collision->execute(['uid' => $userId, 'dev' => (int)$olt['id'], 'idx' => $targetRef]);
            if ($collision->fetchColumn()) return ['ok' => false, 'message' => 'ONU ID pilihan sudah tercatat pada aplikasi.'];
        }

        $allowed = $this->oltServiceOptions($userId, (int)($olt['id'] ?? 0));
        $vlan = (int)($options['vlan'] ?? 0);
        $tcontProfile = trim((string)($options['tcont_profile'] ?? ''));
        $trafficProfile = trim((string)($options['traffic_profile'] ?? ''));
        $services = is_array($options['services'] ?? null) && $options['services'] !== []
            ? $options['services'] : [[
                'index' => (int)($options['index'] ?? 2),
                'vlan' => $vlan,
                'tcont_profile' => $tcontProfile,
                'traffic_profile' => $trafficProfile,
            ]];
        foreach ($services as $service) {
            if (!is_array($service)) return ['ok' => false, 'message' => 'Data Service tidak valid.'];
            $serviceVlan = (int)($service['vlan'] ?? 0);
            $serviceTcont = trim((string)($service['tcont_profile'] ?? ''));
            $serviceTraffic = trim((string)($service['traffic_profile'] ?? ''));
            $outerVlan = (int)($service['svlan'] ?? 0);
            if ($serviceVlan < 1 || $serviceVlan > 4094 || $serviceTcont === '' || $serviceTraffic === '') {
                return ['ok' => false, 'message' => 'VLAN dan profil Service wajib valid.'];
            }
            if ((string)($service['vlan_mode'] ?? '1') === '3' && ($outerVlan < 1 || $outerVlan > 4094)) {
                return ['ok' => false, 'message' => 'VLAN layanan QinQ wajib valid.'];
            }
            if ((string)($service['vlan_mode'] ?? '1') !== '3'
                && $allowed['vlans'] !== [] && !in_array($serviceVlan, $allowed['vlans'], true)) {
                return ['ok' => false, 'message' => 'VLAN ' . $serviceVlan . ' tidak ada di perangkat.'];
            }
            if ($outerVlan > 0 && $allowed['vlans'] !== [] && !in_array($outerVlan, $allowed['vlans'], true)) {
                return ['ok' => false, 'message' => 'VLAN layanan ' . $outerVlan . ' tidak ada di perangkat.'];
            }
            if ($allowed['tcont'] !== [] && !in_array($serviceTcont, $allowed['tcont'], true)) {
                return ['ok' => false, 'message' => 'Profil T-CONT ' . $serviceTcont . ' tidak ada di perangkat.'];
            }
            if ($allowed['traffic'] !== [] && !in_array($serviceTraffic, $allowed['traffic'], true)) {
                return ['ok' => false, 'message' => 'Profil traffic ' . $serviceTraffic . ' tidak ada di perangkat.'];
            }
        }
        foreach ($services as $position => $service) {
            $wanMethod = (string)($service['wan_method'] ?? ($position === 0 ? ($options['wan_method'] ?? 'ont') : 'ont'));
            if ($wanMethod === 'ont') continue;
            $profileName = trim((string)($service['wan_vlan_profile'] ?? ($position === 0 ? ($options['wan_vlan_profile'] ?? '') : '')));
            $profile = $allowed['vlan_profiles'][$profileName] ?? null;
            if (!is_array($profile) || (int)($profile['cvlan'] ?? 0) !== (int)($service['vlan'] ?? 0)) {
                return ['ok' => false, 'message' => 'VLAN Profile WAN Service ' . (int)($service['number'] ?? ($position + 1)) . ' tidak sesuai di OLT.'];
            }
            $ipProfile = (string)($service['wan_ip_profile'] ?? ($position === 0 ? ($options['wan_ip_profile'] ?? '') : ''));
            if ($wanMethod === 'omci-static' && !in_array($ipProfile, $allowed['ip_profiles'], true)) {
                return ['ok' => false, 'message' => 'IP Profile WAN Static tidak tersedia di OLT.'];
            }
        }
        $options['services'] = $services;

        $options['vlan'] = $vlan;
        $options['tcont_profile'] = $tcontProfile;
        $options['traffic_profile'] = $trafficProfile;
        $options['type'] = trim((string)($options['type'] ?? '')) !== ''
            ? trim((string)$options['type'])
            : (trim((string)($onu['onu_type'] ?? '')) !== '' ? trim((string)$onu['onu_type']) : 'ALL');
        $options['serial'] = strtoupper(trim((string)($options['serial'] ?? ($onu['onu_serial'] ?? ''))));
        $parsedName = onu_split_device_label((string)($options['name'] ?? ($onu['onu_name'] ?? '')));
        $plainName = $parsedName['name'] !== '' ? $parsedName['name'] : trim((string)($options['name'] ?? ($onu['onu_name'] ?? '')));
        $labelLocation = array_key_exists('location', $options)
            ? onu_location_value($options['location'])
            : ($parsedName['location'] !== '' ? $parsedName['location'] : onu_location_value($onu['onu_location'] ?? null));
        $options['name'] = onu_join_device_label($plainName, $labelLocation);
        unset($options['location']);

        $result = $driver->registerOnuFull($pon, $targetOnuId, $options);
        if (!empty($result['ok'])) {
            try {
                $observed = $driver instanceof OltOnuServiceAware
                    ? $driver->readOnuServiceConfigTargeted($pon, $targetOnuId, (array)($result['mgmt_lines'] ?? [])) : [];
            } catch (\Throwable) {
                $observed = [];
            }
            $actual = is_array($observed['config'] ?? null) ? $observed['config'] : [];
            $result['verified'] = $this->registeredOnuConfigMatches($actual, $services, $options);
            if (!$result['verified']) {
                return ['ok' => false, 'message' => 'Perintah dikirim, tetapi konfigurasi Service tidak terverifikasi. Data tidak disimpan; periksa OLT sebelum mengulang.'];
            }
            if ($targetRef !== $oldRef && !$this->onuWriter()->moveIndex($userId, $onuRowId, (int)$olt['id'], $oldRef, $targetRef)) {
                return ['ok' => false, 'message' => 'Perangkat menerima registrasi, tetapi ONU ID aplikasi tidak dapat dipindahkan.'];
            }
            $this->onuWriter()->writeAuthMode($userId, $onuRowId, 'Pass');
            $result['mgmt_lines'] = (array)($result['mgmt_lines'] ?? []);
            $result['observed_config'] = $actual;
        }
        return $result;
    }

    private function registeredOnuConfigMatches(array $actual, array $services, array $options): bool
    {
        if ($actual === [] || count((array)($actual['service_ports'] ?? [])) !== count($services)) return false;
        $ports = [];
        foreach ((array)$actual['service_ports'] as $port) {
            if (is_array($port)) $ports[(int)($port['index'] ?? 0)] = $port;
        }
        foreach ($services as $service) {
            $index = (int)($service['index'] ?? 0);
            $vlan = (int)($service['vlan'] ?? 0);
            $mode = (string)($service['vlan_mode'] ?? '1');
            $port = $ports[$index] ?? null;
            if (!is_array($port) || (int)($port['vlan'] ?? 0) !== $vlan
                || (int)($port['svlan'] ?? 0) !== ($mode === '3' ? (int)($service['svlan'] ?? 0) : 0)
                || ($mode === '2' && ($port['user_vlan'] ?? null) !== 'untagged')) return false;
            if ((string)($service['type'] ?? 'internet') === 'iptv'
                && (!isset($actual['igmp_vports'][$index]) || !in_array($vlan, (array)($actual['mvlans'] ?? []), true))) return false;
        }
        $hasVeip = (bool)array_filter((array)($actual['vlan_ports'] ?? []),
            static fn($row): bool => is_array($row) && (string)($row['port'] ?? '') === 'veip_1');
        if ($hasVeip !== (!array_key_exists('veip', $options) || !empty($options['veip']))) return false;
        $wanById = [];
        foreach ((array)($actual['wan_ips'] ?? []) as $wan) if (is_array($wan)) $wanById[(int)($wan['index'] ?? 0)] = $wan;
        foreach ($services as $position => $service) {
            $number = (int)($service['index'] ?? ($position + 1));
            $method = (string)($service['wan_method'] ?? ($position === 0 ? ($options['wan_method'] ?? 'ont') : 'ont'));
            $wan = (array)($wanById[$number] ?? []);
            if ($method === 'ont' && $wan !== []) return false;
            if ($method !== 'ont' && ((string)($wan['mode'] ?? '') !== match ($method) { 'omci-pppoe' => 'pppoe', 'omci-static' => 'static', default => 'dhcp' }
                || (string)($wan['vlan_profile'] ?? '') !== (string)($service['wan_vlan_profile'] ?? ($position === 0 ? ($options['wan_vlan_profile'] ?? '') : '')))) return false;
            if ($method === 'omci-static' && ((string)($wan['ip_profile'] ?? '') !== (string)($service['wan_ip_profile'] ?? ($position === 0 ? ($options['wan_ip_profile'] ?? '') : ''))
                || (string)($wan['ip_address'] ?? '') !== (string)($service['wan_ip_address'] ?? ($position === 0 ? ($options['wan_ip_address'] ?? '') : ''))
                || (string)($wan['mask'] ?? '') !== (string)($service['wan_mask'] ?? ($position === 0 ? ($options['wan_mask'] ?? '') : '')))) return false;
        }
        $tr069 = is_array($options['tr069'] ?? null) ? $options['tr069'] : [];
        $tr069Actual = (array)($actual['tr069_mgmt'][1] ?? []);
        if ($tr069 !== [] && ((string)($tr069Actual['state'] ?? '') !== 'unlock'
            || (string)($tr069Actual['acs'] ?? '') !== (string)($tr069['acs'] ?? '')
            || ((string)($tr069['acs'] ?? '') !== ''
                && (int)($tr069Actual['vlan'] ?? 0) !== (int)($tr069['vlan'] ?? 0)))) return false;
        return $tr069 !== [] || $tr069Actual === [];
    }

    public function provisionOnuService(int $userId, int $onuRowId, array $options): array
    {
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if (!is_array($onu) || !monitoring_row_uses_handler($onu, 'snmp_olt')) {
            return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];
        }
        $olt = $this->repository->getOltByRowId($userId, (int)($onu['olt_row_id'] ?? 0));
        if (!is_array($olt)) return ['ok' => false, 'message' => 'OLT tidak ditemukan.'];
        [$pon, $onuId] = $this->onuCoordinates($onu);

        try {
            $driver = $this->driverForOlt($olt);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Driver OLT tidak siap.'];
        }
        if (!$driver instanceof OltOnuProvisionAware) {
            return ['ok' => false, 'message' => 'Driver OLT ini belum mendukung penulisan layanan.'];
        }

        
        $allowed = $this->oltServiceOptions($userId, (int)($olt['id'] ?? 0));
        $vlan = (int)($options['vlan'] ?? 0);
        $tcontProfile = trim((string)($options['tcont_profile'] ?? ''));
        $trafficProfile = trim((string)($options['traffic_profile'] ?? ''));
        if ($allowed['vlans'] !== [] && !in_array($vlan, $allowed['vlans'], true)) {
            return ['ok' => false, 'message' => 'VLAN ' . $vlan . ' tidak ada di perangkat.'];
        }
        if ($allowed['tcont'] !== [] && !in_array($tcontProfile, $allowed['tcont'], true)) {
            return ['ok' => false, 'message' => 'Profil T-CONT ' . $tcontProfile . ' tidak ada di perangkat.'];
        }
        if ($allowed['traffic'] !== [] && !in_array($trafficProfile, $allowed['traffic'], true)) {
            return ['ok' => false, 'message' => 'Profil traffic ' . $trafficProfile . ' tidak ada di perangkat.'];
        }

        $options['vlan'] = $vlan;
        $options['tcont_profile'] = $tcontProfile;
        $options['traffic_profile'] = $trafficProfile;
        $options['name'] = trim((string)($options['name'] ?? ($onu['onu_name'] ?? '')));

        
        $currentService = $this->repository->getOnuServiceConfig($userId, (int)($olt['id'] ?? 0), (string)($onu['onu_index'] ?? ''));
        $options['current'] = is_array($currentService['config'] ?? null) ? (array)$currentService['config'] : [];

        $result = $driver->provisionOnuService($pon, $onuId, $options);
        
        if (!empty($result['ok']) && empty($result['skipped'])) {
            $result['refresh'] = $this->refreshOnuConfig($userId, $onuRowId, (array)($result['mgmt_lines'] ?? []));
        }
        return $result;
    }

    public function changeOnuDataService(int $userId, int $onuRowId, array $input): array
    {
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if (!is_array($onu) || !monitoring_row_uses_handler($onu, 'snmp_olt')) return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];
        $olt = $this->repository->getOltByRowId($userId, (int)($onu['olt_row_id'] ?? 0));
        if (!is_array($olt)) return ['ok' => false, 'message' => 'OLT tidak ditemukan.'];
        try { $driver = $this->driverForOlt($olt); }
        catch (\Throwable) { return ['ok' => false, 'message' => 'Driver OLT tidak siap.']; }
        if (!method_exists($driver, 'changeOnuDataService')) return ['ok' => false, 'message' => 'Driver tidak mendukung aksi Service.'];
        $action = (string)($input['action'] ?? '');
        $index = (int)($input['index'] ?? 0);
        if (!in_array($action, ['create', 'update', 'delete', 'enable', 'disable'], true) || $index < 1 || $index > 32) {
            return ['ok' => false, 'message' => 'Aksi atau index Service tidak valid.'];
        }
        $previous = $this->repository->getOnuServiceConfig($userId, (int)$olt['id'], (string)$onu['onu_index']);
        $templates = (array)($previous['config']['service_templates'] ?? []);
        if ($action === 'enable') {
            $template = $templates[$index] ?? null;
            if (is_array($template)) $input = array_merge($input, $template);
            elseif (trim((string)($input['name'] ?? '')) === '' || (int)($input['vlan'] ?? 0) < 1) {
                return ['ok' => false, 'message' => 'Service belum memiliki konfigurasi tersimpan untuk Enable langsung.'];
            }
        }
        if (in_array($action, ['create', 'update'], true)) {
            $allowed = $this->oltServiceOptions($userId, (int)$olt['id']);
            $vlan = (int)($input['vlan'] ?? 0);
            $outer = (int)($input['svlan'] ?? 0);
            $mode = (string)($input['vlan_mode'] ?? 'tag');
            $tcont = trim((string)($input['tcont_profile'] ?? ''));
            $traffic = trim((string)($input['traffic_profile'] ?? ''));
            if ($vlan < 1 || $vlan > 4094 || !in_array($mode, ['tag', 'untag', 'qinq'], true)
                || ($mode === 'qinq' && ($outer < 1 || $outer > 4094))
                || ($allowed['vlans'] !== [] && !in_array($mode === 'qinq' ? $outer : $vlan, $allowed['vlans'], true))
                || ($allowed['tcont'] !== [] && !in_array($tcont, $allowed['tcont'], true))
                || ($allowed['traffic'] !== [] && !in_array($traffic, $allowed['traffic'], true))) {
                return ['ok' => false, 'message' => 'VLAN atau profil Service tidak sesuai dengan OLT.'];
            }
        }
        $result = $driver->changeOnuDataService((string)$onu['onu_index'], $input);
        if (!empty($result['ok']) && is_array($result['config'] ?? null)) {
            if ($action === 'disable' && is_array($result['template'] ?? null)) $templates[$index] = $result['template'];
            if ($templates !== []) $result['config']['service_templates'] = $templates;
            try {
                $result['refreshed'] = $this->onuWriter()->writeServiceConfigs($userId, (int)$olt['id'], [(string)$onu['onu_index'] => $result['config']], 'action') > 0;
            } catch (Throwable $error) {
                error_log('ONU Service snapshot update failed: ' . $error->getMessage());
                $result['refreshed'] = false;
            }
            if (!$result['refreshed']) $result['message'] = 'Perangkat menerima perubahan, tetapi snapshot Service tidak tersimpan.';
        }
        unset($result['config'], $result['raw'], $result['template']);
        return $result;
    }

    
    public function refreshRegisteredOnu(int $userId, int $onuRowId, array $mgmtLines = [], array $observedConfig = []): array
    {
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if (!is_array($onu)) return ['ok' => false, 'message' => 'ONU terdaftar, tetapi baris aplikasi belum ditemukan.'];
        $olt = $this->repository->getOltByRowId($userId, (int)($onu['olt_row_id'] ?? 0));
        if (!is_array($olt)) return ['ok' => false, 'message' => 'ONU terdaftar, tetapi OLT aplikasi belum ditemukan.'];
        $ref = trim((string)($onu['onu_index'] ?? ''));
        try {
            [$pon, $onuId] = $this->onuCoordinates($onu);
            $driver = $this->driverForOlt($olt);
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'ONU terdaftar, tetapi pembacaan lanjutannya tidak dapat dimulai.'];
        }
        $saved = 0;

        try {
            $detail = $driver->getOnuDetail($pon, $onuId);
            $snapshot = $detail['snapshot'] ?? null;
            if (!empty($detail['ok']) && is_array($snapshot)) {
                $this->onuWriter()->replaceOnus($userId, (int)$olt['olt_id'], [$snapshot], true, false, false, 'registration_action');
                $saved++;
            }
        } catch (Throwable $error) {
            $this->repository->logApplication($userId, 'OLT SNMP', 'registration_refresh', 'warning', $error->getMessage());
        }

        if ($driver instanceof OltOnuEthernetAware && $ref !== '') {
            try {
                $portsResult = $driver->readOnuEthernetPorts($ref);
                if (!empty($portsResult['ok'])) {
                    $ports = array_values((array)($portsResult['ports'] ?? []));
                    foreach ($ports as &$port) {
                        if (is_array($port)) unset($port['link_status'], $port['link'], $port['enable']);
                    }
                    unset($port);
                    $parts = explode('/', $ref);
                    $ifKey = count($parts) >= 3 ? implode('/', array_slice($parts, 1)) : $ref;
                    $saved += $this->onuWriter()->writeEthernetPorts(
                        $userId,
                        (int)$olt['id'],
                        $ports !== [] ? [$ifKey => $ports] : [],
                        [$ifKey => count($ports) + 1]
                    );
                }
                $status = $driver->readOnuEthernetStatusesSnmp([['onu_index' => $ref]]);
                if (!empty($status['ok'])) {
                    $saved += $this->onuWriter()->writeEthernetStatuses(
                        $userId,
                        (int)$olt['id'],
                        (array)($status['ports'] ?? []),
                        [$ref],
                        !empty($status['dynamic_ports'])
                    );
                }
            } catch (Throwable $error) {
                $this->repository->logApplication($userId, 'OLT SNMP', 'registration_lan_refresh', 'warning', $error->getMessage());
            }
        }

        if ($driver instanceof OltOnuEthernetFastStatusAware && !$driver instanceof OltOnuEthernetAware && $ref !== '') {
            try {
                $fresh = $this->repository->getOnuByRowId($userId, $onuRowId);
                $status = $driver->readOnuEthernetStatusesSnmp([is_array($fresh) ? $fresh : $onu]);
                if (!empty($status['ok'])) {
                    $saved += $this->onuWriter()->writeEthernetStatuses(
                        $userId, (int)$olt['id'], (array)($status['ports'] ?? []), [$ref], !empty($status['dynamic_ports'])
                    );
                    if (!empty($status['configs'])) {
                        $saved += $this->onuWriter()->writeEthernetConfigurations(
                            $userId, (int)$olt['id'], (array)$status['configs']
                        );
                    }
                }
            } catch (Throwable $error) {
                $this->repository->logApplication($userId, 'OLT SNMP', 'registration_lan_refresh', 'warning', $error->getMessage());
            }
        }

        $service = $driver instanceof OltOnuServiceAware
            ? $this->refreshOnuConfig($userId, $onuRowId, $mgmtLines, true, $observedConfig)
            : ['saved' => 0];
        $saved += (int)($service['saved'] ?? 0);
        return [
            'ok' => $saved > 0,
            'saved' => $saved,
            'message' => $saved > 0
                ? 'Data ONU ' . $ref . ' dibaca setelah registrasi dan disimpan.'
                : 'ONU terdaftar, tetapi data lanjutannya belum dilaporkan perangkat.',
        ];
    }

    public function refreshOnuConfig(int $userId, int $onuRowId, array $mgmtLines = [], bool $targetedOnly = false, array $observedConfig = []): array
    {
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if (!is_array($onu) || !monitoring_row_uses_handler($onu, 'snmp_olt')) {
            return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];
        }
        $olt = $this->repository->getOltByRowId($userId, (int)($onu['olt_row_id'] ?? 0));
        if (!is_array($olt)) return ['ok' => false, 'message' => 'OLT tidak ditemukan.'];
        $deviceId = (int)($olt['id'] ?? 0);
        $onuIndex = trim((string)($onu['onu_index'] ?? ''));
        if ($deviceId < 1 || $onuIndex === '') return ['ok' => false, 'message' => 'Data ONU tidak lengkap.'];

        try {
            $driver = $this->driverForOlt($olt);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Driver OLT tidak siap.'];
        }

        $saved = 0;
        $serviceDone = false;

        
        
        [$pon, $onuId] = $this->onuCoordinates($onu);
        $useTargeted = $driver instanceof OltOnuServiceAware;
        if ($useTargeted) {
            
            
            if ($mgmtLines === []) {
                $previous = $this->repository->getOnuServiceConfig($userId, $deviceId, $onuIndex);
                $mgmtLines = (array)($previous['config']['mgmt_lines'] ?? []);
            }
            
            if ($mgmtLines === [] && !$targetedOnly) $useTargeted = false;
        }
        if ($useTargeted) {
            try {
                $targeted = $observedConfig !== []
                    ? ['ok' => true, 'config' => $observedConfig]
                    : $driver->readOnuServiceConfigTargeted($pon, $onuId, $mgmtLines);
                $config = is_array($targeted['config'] ?? null) ? (array)$targeted['config'] : [];
                if (!empty($targeted['ok']) && ($config['service_lines'] ?? []) !== []) {
                    $this->attachOnuRemoteInterfaces($driver, $onuIndex, $config);
                    if (method_exists($driver, 'readOnuTr069')) {
                        $tr069 = $driver->readOnuTr069($onuIndex);
                        if (!empty($tr069['ok']) && is_array($tr069['tr069'] ?? null)) $config['tr069_mgmt'] = [$tr069['tr069']];
                    }
                    $this->attachOnuRemoteAccess($driver, $onuIndex, $config);
                    $saved += $this->onuWriter()->writeServiceConfigs($userId, $deviceId, [$onuIndex => $config], 'action');
                    $serviceDone = true;
                }
            } catch (\Throwable $e) {
                $this->repository->logApplication($userId, 'OLT CLI', 'refresh_onu_config', 'warning', 'Blok layanan per-ONU tidak terbaca: ' . $e->getMessage());
            }
        }

        if (!$serviceDone && !$targetedOnly && $driver instanceof OltOnuServiceAware) {
            try {
                $result = $driver->readAllOnuServiceConfigs();
                $all = (array)($result['configs'] ?? []);
                if (is_array($all[$onuIndex] ?? null)) {
                    $this->attachOnuRemoteInterfaces($driver, $onuIndex, $all[$onuIndex]);
                    if (method_exists($driver, 'readOnuTr069')) {
                        $tr069 = $driver->readOnuTr069($onuIndex);
                        if (!empty($tr069['ok']) && is_array($tr069['tr069'] ?? null)) $all[$onuIndex]['tr069_mgmt'] = [$tr069['tr069']];
                    }
                    $this->attachOnuRemoteAccess($driver, $onuIndex, $all[$onuIndex]);
                    $saved += $this->onuWriter()->writeServiceConfigs($userId, $deviceId, [$onuIndex => $all[$onuIndex]], 'action');
                }
            } catch (\Throwable $e) {
                $this->repository->logApplication($userId, 'OLT CLI', 'refresh_onu_config', 'warning', 'Konfigurasi layanan ONU tidak terbaca: ' . $e->getMessage());
            }
        }
        if ($driver instanceof OltOnuServiceAware) {
            try {
                $detail = $driver->readAllOnuDetailInfo([$onuIndex]);
                if (!empty($detail['ok']) && !empty($detail['details'])) {
                    $saved += $this->onuWriter()->writeDetails($userId, $deviceId, (array)$detail['details']);
                }
            } catch (\Throwable $e) {
                $this->repository->logApplication($userId, 'OLT CLI', 'refresh_onu_config', 'warning', 'Detail ONU tidak terbaca: ' . $e->getMessage());
            }
        }

        return [
            'ok' => $saved > 0,
            'saved' => $saved,
            'message' => $saved > 0
                ? 'Konfigurasi ONU ' . $onuIndex . ' dibaca dari perangkat dan langsung disimpan.'
                : 'Perangkat belum melaporkan konfigurasi untuk ONU ' . $onuIndex . '.',
        ];
    }

    
    private function attachOnuRemoteAccess(object $driver, string $ref, array &$config): void
    {
        if (!method_exists($driver, 'readOnuRemoteAccess')) return;
        $entries = (array)($config['security_mgmt'] ?? []);
        foreach ($entries as &$entry) {
            if (!is_array($entry) || (int)($entry['index'] ?? 0) < 1) continue;
            $read = $driver->readOnuRemoteAccess($ref, (int)$entry['index']);
            if (!empty($read['ok']) && !empty($read['exists']) && is_array($read['access'] ?? null)) {
                $entry = $read['access'];
            }
        }
        unset($entry);
        $config['security_mgmt'] = $entries;
    }

    private function attachOnuRemoteInterfaces(object $driver, string $ref, array &$config): void
    {
        if (!method_exists($driver, 'readOnuRemoteInterfaces')) return;
        foreach (['wifi', 'veip'] as $kind) {
            try {
                $result = $driver->readOnuRemoteInterfaces($ref, $kind);
                $config[$kind . '_interface_probe'] = !empty($result['ok']) ? 'reported' : 'unavailable';
                $config[$kind . '_interfaces'] = !empty($result['ok']) ? array_values((array)($result['ports'] ?? [])) : [];
            } catch (\Throwable $error) {
                $config[$kind . '_interface_probe'] = 'unavailable';
                $config[$kind . '_interfaces'] = [];
            }
        }
    }

    private function attachOnuTr069(object $driver, string $ref, array &$config): void
    {
        if (!method_exists($driver, 'readOnuTr069')) return;
        try {
            $result = $driver->readOnuTr069($ref);
            if (!empty($result['ok']) && is_array($result['tr069'] ?? null)) {
                $config['tr069_mgmt'] = [$result['tr069']];
            }
        } catch (Throwable $error) {
            error_log('ONU TR069 read failed: ' . $error->getMessage());
        }
    }

    private function syncOnuServiceConfigs(int $userId, array $olt, object $driver, string $scope, array $onuRows = []): array
    {
        $deviceId = (int)($olt['id'] ?? 0);
        if ($deviceId < 1 || !$driver instanceof OltOnuServiceAware) return ['assigned_types'=>[], 'details'=>[]];
        try {
            $result = $driver->readAllOnuServiceConfigs();
        } catch (\Throwable $e) {
            $this->repository->logApplication($userId, 'OLT CLI', 'sync_onu_service', 'warning', 'Konfigurasi layanan ONU tidak terbaca: ' . $e->getMessage());
            return ['assigned_types'=>[], 'details'=>[]];
        }
        if (empty($result['ok']) || empty($result['configs'])) {
            $this->repository->logApplication($userId, 'OLT CLI', 'sync_onu_service', 'warning', 'Discovery layanan ONU kosong: ' . (string)($result['message'] ?? 'tanpa pesan'));
        }
        $assignedTypes = [];
        foreach ((array)$result['configs'] as $ref => $config) {
            $type = trim((string)($config['onu_type'] ?? ''));
            $serial = strtoupper(trim((string)($config['assignment_serial'] ?? '')));
            if ($type !== '' && $serial !== '') $assignedTypes[(string)$ref] = ['type' => $type, 'serial' => $serial];
        }
        $serviceConfigs = array_filter((array)$result['configs'], static fn(mixed $config): bool => is_array($config)
            && ((array)($config['service_lines'] ?? []) !== [] || (array)($config['mgmt_lines'] ?? []) !== []));
        $onlineRefs = [];
        $knownRefs = [];
        foreach ($this->repository->getOnus($userId, (int)($olt['olt_id'] ?? 0), true, 'snmp') as $onu) {
            $knownRefs[] = (string)($onu['onu_index'] ?? '');
            if (strtolower((string)($onu['onu_state'] ?? '')) === 'online') $onlineRefs[(string)($onu['onu_index'] ?? '')] = true;
        }
        foreach ($onuRows as $onu) {
            if (!is_array($onu) || !empty($onu['missing'])) continue;
            $ref = trim((string)($onu['onu_index'] ?? ''));
            if ($ref === '' && isset($onu['pon'], $onu['onu_id'])) $ref = trim((string)$onu['pon']) . ':' . (int)$onu['onu_id'];
            if ($ref === '') continue;
            $knownRefs[] = $ref;
            if (strtolower((string)($onu['status'] ?? $onu['onu_state'] ?? '')) === 'online') $onlineRefs[$ref] = true;
        }
        foreach ($serviceConfigs as $ref => &$config) {
            if (isset($onlineRefs[(string)$ref])) {
                $this->attachOnuRemoteInterfaces($driver, (string)$ref, $config);
                $this->attachOnuTr069($driver, (string)$ref, $config);
            }
        }
        unset($config);
        try {
            $this->onuWriter()->writeServiceConfigs($userId, $deviceId, $serviceConfigs, $scope);
        } catch (\Throwable $e) {
            $this->repository->logApplication($userId, 'OLT CLI', 'sync_onu_service', 'warning', 'Konfigurasi layanan ONU gagal disimpan: ' . $e->getMessage());
        }
        
        $details = [];
        if ($driver instanceof OltOnuServiceAware) {
            try {
                $detailRefs = array_values(array_unique(array_merge(array_keys((array)($result['configs'] ?? [])), $knownRefs)));
                $detail = $driver->readAllOnuDetailInfo($detailRefs);
                if (!empty($detail['ok']) && !empty($detail['details'])) $details = (array)$detail['details'];
            } catch (\Throwable $e) {
                $this->repository->logApplication($userId, 'OLT CLI', 'sync_onu_detail', 'warning', 'Detail ONU tidak terbaca: ' . $e->getMessage());
            }
        }
        return ['assigned_types'=>$assignedTypes, 'details'=>$details];
    }

    public function refreshOnuOptical(int $userId, int $onuRowId): array
    {
        $onu = $this->repository->getOnuByRowId($userId, $onuRowId);
        if (!is_array($onu)) return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];

        $index = trim((string)($onu['onu_index'] ?? ''));
        $parts = explode(':', $index, 2);
        $ponPath = trim((string)($parts[0] ?? ''));
        $onuId = trim((string)($parts[1] ?? ''));
        if ($ponPath === '' || !ctype_digit($onuId)) {
            return ['ok' => false, 'message' => 'Indeks ONU tidak dikenali.'];
        }

        $context = $this->oltWithCliDriver($userId, (int)($onu['olt_row_id'] ?? 0), 'OLT ini belum mendukung pembacaan daya optik lewat CLI.');
        if (empty($context['ok'])) return $context;

        $olt = (array)$context['olt'];
        $driver = $context['driver'];
        if (!$driver instanceof OltCliOpticalAware) {
            return ['ok' => false, 'message' => 'Driver ini belum menyediakan pembaca daya optik CLI.'];
        }

        $ponRef = self::normalizePonPath($ponPath);
        if ($ponRef === '') $ponRef = $ponPath;
        $optical = $driver instanceof OltCliOpticalAware
            ? $driver->readOnuOpticalByRef($ponRef, (int)$onuId)
            : $driver->readOnuOptical(OltPonReference::numberFromPath($ponPath), (int)$onuId);
        if (empty($optical['ok'])) {
            $this->repository->logApplication($userId, 'OLT CLI', 'refresh_onu_optical', 'error', (string)($optical['message'] ?? ''));
            return $optical;
        }

        $merged = OltOpticalValues::applyValues(
            $this->onuRowsFromStorage([$onu]),
            [$ponRef => [(int)$onuId => $optical]]
        );
        $this->onuWriter()->replaceOnus($userId, (int)($olt['olt_id'] ?? 0), $merged, true, false, false, 'optical_action');
        $this->repository->logApplication(
            $userId,
            'OLT CLI',
            'refresh_onu_optical',
            'success',
            'Daya optik ONU ' . $index . ' diperbarui lewat CLI.'
        );

        return [
            'ok' => true,
            'message' => 'Daya optik ONU ' . $index . ' diperbarui lewat CLI.',
            'rx_power' => onu_rx_power_number($optical['rx_power'] ?? null),
            'tx_power' => $optical['tx_power'] ?? null,
            'olt_rx_power' => $optical['olt_rx_power'] ?? null,
        ];
    }

    private function onuRowsFromStorage(array $storedOnus): array
    {
        $rows = [];
        foreach ($storedOnus as $onu) {
            if (!is_array($onu)) continue;
            $index = trim((string)($onu['onu_index'] ?? ''));
            $parts = explode(':', $index, 2);
            $ponPath = trim((string)($parts[0] ?? ''));
            $onuId = trim((string)($parts[1] ?? ''));
            if ($ponPath === '' || !ctype_digit($onuId)) continue;

            $rows[] = [
                'pon' => $ponPath,
                'pon_number' => OltPonReference::numberFromPath($ponPath),
                'onu_id' => (int)$onuId,
                'name' => onu_join_device_label((string)($onu['onu_name'] ?? ''), (string)($onu['onu_location'] ?? '')),
                'status' => (string)($onu['onu_state'] ?? ''),
                'auth_mode' => (string)($onu['auth_mode'] ?? ''),
                'serial' => (string)($onu['onu_serial'] ?? ''),
                'rx_power' => $onu['rx_power'] ?? null,
                'tx_power' => $onu['tx_power'] ?? null,
                'temperature' => $onu['temperature'] ?? null,
                'distance' => $onu['distance'] ?? null,
            ];
        }
        return $rows;
    }

    private function writeSyncLockMetadata($lock, int $userId, int $rowId, float $startedAt): void
    {
        $metadata = json_encode([
            'pid'=>getmypid(),
            'user_id'=>$userId,
            'olt_row_id'=>$rowId,
            'started_at'=>date(DATE_ATOM, (int)$startedAt),
            'started_at_epoch'=>$startedAt,
            'sapi'=>PHP_SAPI,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($metadata)) throw new RuntimeException('Metadata lock sinkronisasi SNMP tidak dapat dibuat.');
        if (!rewind($lock) || !ftruncate($lock, 0)) {
            throw new RuntimeException('File lock sinkronisasi SNMP tidak dapat disiapkan.');
        }
        $written = fwrite($lock, $metadata);
        if ($written !== strlen($metadata) || !fflush($lock)) {
            throw new RuntimeException('Kunci sinkronisasi tidak dapat disimpan.');
        }
    }

    private function openSyncLock(string $path): array
    {
        $lastError = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $lock = @fopen($path, 'r+');
            if (is_resource($lock)) {
                @chmod($path, 0666);
                return [$lock, null];
            }
            $failure = error_get_last();
            $lastError = is_array($failure) ? trim((string)($failure['message'] ?? '')) : null;

            clearstatcache(true, $path);
            if (!file_exists($path)) {
                $previousUmask = umask(0000);
                try {
                    $lock = @fopen($path, 'x+');
                } finally {
                    umask($previousUmask);
                }
                if (is_resource($lock)) {
                    @chmod($path, 0666);
                    return [$lock, null];
                }
                $failure = error_get_last();
                $lastError = is_array($failure) ? trim((string)($failure['message'] ?? '')) : $lastError;
            }

             usleep(10000);
        }

        return [false, $lastError !== '' ? $lastError : null];
    }

    private function readSyncLockMetadata($lock): array
    {
        @rewind($lock);
        $raw = @stream_get_contents($lock);
        if (!is_string($raw) || trim($raw) === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : ['raw'=>$raw];
    }

    private function terminateLegacyLockOwner(string $lockPath): ?string
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_kill')) return null;

        $selfPid = getmypid();
        foreach (glob('/proc/[0-9]*/fd/*') ?: [] as $descriptor) {
            $target = @readlink($descriptor);
            if (!is_string($target) || $target !== $lockPath) continue;
            if (preg_match('~^/proc/(\d+)/fd/\d+$~', $descriptor, $match) !== 1) continue;
            $pid = (int)$match[1];
            if ($pid < 2 || $pid === $selfPid) continue;

            $rawCommand = @file_get_contents('/proc/' . $pid . '/cmdline');
            $command = is_string($rawCommand) ? str_replace("\0", ' ', trim($rawCommand)) : '';
            if ($command === '' || !str_contains(strtolower($command), 'php')) continue;

            $signal = defined('SIGTERM') ? SIGTERM : 15;
            $terminated = @posix_kill($pid, $signal);
            return 'posix_kill(pid=' . $pid . ', signal=' . $signal . ')=' . var_export($terminated, true)
                . '; cmdline=' . $command . '; lock=' . $lockPath . '.';
        }
        return null;
    }

    private function rawInternalResult(array $result): string
    {
        foreach (['error','diagnostic'] as $field) {
            if (is_scalar($result[$field] ?? null) && (string)$result[$field] !== '') {
                return (string)$result[$field];
            }
        }
        return var_export($result, true);
    }

    private function logInternalResult(int $userId, int $rowId, string $action, string $level, array $result): void
    {
        error_log('[OLT SNMP] ' . $action . ': ' . $this->rawInternalResult($result));
        $message = trim((string)($result['message'] ?? '')) !== ''
            ? (string)$result['message']
            : 'Pembacaan data OLT gagal.';
        if (in_array(strtolower($level), ['error', 'critical'], true)) {
            $this->snapshotWriter()->recordReadFailure($userId, $rowId, 'OLT SNMP', $message);
        }
    }

    private function assertBeforeDeadline(?float $deadlineAt, string $operation): void
    {
        if ($deadlineAt !== null && microtime(true) >= $deadlineAt) {
            throw new RuntimeException('Batas waktu pemantauan SNMP terlampaui saat ' . $operation . '.');
        }
    }

    private function mapSystem(array $result, array $olt): array
    {
        $data = (array)($result['data'] ?? []);
        return ['system_name'=>$data['name'] ?? null,
            'object_id'=>$data['object_id'] ?? null,'uptime'=>$data['uptime'] ?? null,
            'runtime_seconds'=>$this->uptimeSeconds($data['uptime'] ?? null),
            'contact'=>$data['contact'] ?? null,'location'=>$data['location'] ?? null,
            
            'vendor'=>$data['vendor'] ?? null,
            'software_version'=>$data['software_version'] ?? null,
            'model'=>$data['model'] ?? null,
            'serial'=>$data['serial'] ?? null,
            'cpu_percent'=>is_numeric($data['cpu_percent'] ?? null) ? (float)$data['cpu_percent'] : null,
            'memory_percent'=>is_numeric($data['memory_percent'] ?? null) ? (float)$data['memory_percent'] : null,
            'temperature'=>is_numeric($data['temperature'] ?? null) ? (float)$data['temperature'] : null,
            'mac'=>$data['mac'] ?? null,'current_time'=>$data['current_time'] ?? null,
            'ip_address'=>$olt['ip_address'] ?? null,'snmp_version'=>'v2c'];
    }

    private function uptimeSeconds(mixed $value): ?int
    {
        $text = trim((string)$value);
        if ($text === '') return null;
        if (preg_match('/\((\d+)\)/', $text, $match) === 1) return (int)floor(((int)$match[1]) / 100);
        return ctype_digit($text) ? (int)floor(((int)$text) / 100) : null;
    }
}
