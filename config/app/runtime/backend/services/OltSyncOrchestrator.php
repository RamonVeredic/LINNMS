<?php

declare(strict_types=1);

final class OltSyncOrchestrator
{
    private ?OltSnapshotWriteService $snapshotWriter = null;

    public function __construct(private LinnmsRepository $db, private SnmpOltService $service)
    {
    }

    public function syncManual(int $userId, int $rowId): array
    {
        $olt = $this->db->getOltByRowId($userId, $rowId);
        if (!is_array($olt) || !monitoring_row_uses_handler($olt, 'snmp_olt')) {
            return ['ok' => false, 'message' => 'OLT tidak ditemukan.'];
        }
        return $this->service->sync($userId, $rowId);
    }

    public function refreshView(int $userId, int $rowId): array
    {
        $olt = $this->db->getOltByRowId($userId, $rowId);
        if (!is_array($olt) || !monitoring_row_uses_handler($olt, 'snmp_olt')) {
            return ['ok' => false, 'message' => 'Sinkron tidak tersedia untuk perangkat ini.'];
        }
        $definition = monitoring_driver_definition((string)device_driver_type($olt));
        return match ((string)($definition['view_refresh_path'] ?? '')) {
            'basic' => $this->service->refreshBasicOltView($userId, $rowId),
            'full' => $this->service->refreshFullOltView($userId, $rowId),
            default => ['ok' => false, 'message' => 'Perangkat ini belum mendukung pembaruan detail.'],
        };
    }

    public function refreshUnconfiguredOnus(int $userId, int $rowId): array
    {
        $olt = $this->db->getOltByRowId($userId, $rowId);
        $definition = is_array($olt)
            ? monitoring_driver_definition((string)device_driver_type($olt)) : null;
        if (!is_array($olt) || !monitoring_row_uses_handler($olt, 'snmp_olt')
            || empty($definition['unconfigured_refresh'])) {
            return ['ok' => false, 'message' => 'Discovery ONU belum didukung perangkat ini.'];
        }
        $result = $this->service->sync($userId, $rowId, true, true);
        if (!empty($result['ok'])) {
            $result['message'] = 'Discovery ONU belum terkonfigurasi berhasil diperbarui.';
        }
        return $result;
    }

    public function syncDue(int $userId, ?string $driverName = null, ?callable $yieldAfterBatch = null): array
    {
        $success = 0;
        $failed = 0;
        $down = 0;
        $errors = [];
        foreach ($this->db->getDueSnmpOlts($userId) as $olt) {
            if ($driverName !== null && olt_monitoring_driver($olt) !== $driverName) continue;
            try {
                if (in_array(strtoupper(trim((string)($olt['driver_status'] ?? ''))), ['DOWN', 'WARN'], true)) {
                    $probe = $this->service->probeOfflineOlt($userId, (int)$olt['id']);
                    if (empty($probe['reachable'])) {
                        if (!empty($probe['configuration_error'])) {
                            $failed++;
                            $errors[] = [
                                'olt_id' => (int)$olt['id'],
                                'olt_name' => (string)($olt['name'] ?? ''),
                                'driver_id' => (string)device_driver_type($olt),
                            ] + $probe;
                        } elseif (!empty($probe['device_down'])) {
                            $down++;
                        }
                        if ($yieldAfterBatch !== null) $yieldAfterBatch();
                        continue;
                    }
                }
                $result = $this->service->sync($userId, (int)$olt['id'], true, false, null, $yieldAfterBatch);
            } catch (Throwable $error) {
                error_log((string)$error);
                try {
                    $this->snapshotWriter()->markSyncFailed($userId,
                        (int)($olt['olt_id'] ?? $olt['id'] ?? 0), $error->getMessage(), false, false);
                } catch (Throwable $statusError) {
                    error_log((string)$statusError);
                }
                $result = [
                    'ok' => false,
                    'device_down' => false,
                    'message' => 'Pembaruan gagal.',
                    'error' => (string)$error,
                    'new_failure' => false,
                ];
            }
            if (!empty($result['snmp_unavailable'])) {
                if ($yieldAfterBatch !== null) $yieldAfterBatch();
                continue;
            }
            if (!empty($result['ok'])) {
                $success++;
            } else {
                $failed++;
                if (!empty($result['device_down'])) $down++;
                $errors[] = [
                    'olt_id' => (int)($olt['id'] ?? 0),
                    'olt_name' => (string)($olt['name'] ?? ''),
                    'ip_address' => (string)($olt['ip_address'] ?? ''),
                    'driver_id' => (string)device_driver_type($olt),
                ] + $result;
            }
            if ($yieldAfterBatch !== null) $yieldAfterBatch();
        }
        return ['ok' => $failed === 0, 'success' => $success, 'failed' => $failed,
            'down' => $down, 'errors' => $errors];
    }

    public function processInitialScan(string $token): bool
    {
        $request = $this->db->claimOltScanRequest($token, true);
        if (!is_array($request)) return false;

        $userId = (int)$request['user_id'];
        $rowId = (int)$request['olt_row_id'];
        $requestId = (int)$request['id'];
        $ok = false;
        $error = '';
        $cancelled = false;
        $retryRows = [];
        $retryDriverId = '';
        $targetedRetry = false;
        $driverDisallowed = false;
        $result = [];

        try {
            $olt = $this->db->getOltByRowId($userId, $rowId);
            if (!is_array($olt) || !monitoring_row_uses_handler($olt, 'snmp_olt')) {
                $cancelled = true;
                $error = 'OLT sudah tidak terdaftar untuk sinkronisasi SNMP.';
            }
            if (!$cancelled && $this->db->hasPendingOltPipeline($userId, $rowId)) {
                throw new RuntimeException('Penulisan sinkronisasi sebelumnya masih berjalan.');
            }
            if (!$cancelled) {
                $pipelineMarker = (int)$this->db->pdo()->query('SELECT COALESCE(MAX(id),0) FROM sync_pipeline')->fetchColumn();
                $reportProgress = function (string $phase, int $processedOnus, int $totalOnus = 0) use ($requestId, $token): void {
                    $this->db->updateOltScanProgress($requestId, $token, $phase, $processedOnus, $totalOnus);
                };
                $retryPayload = json_decode((string)($request['retry_middle_json'] ?? ''), true);
                $retryDriverId = (string)device_driver_type($olt);
                $savedRows = is_array($retryPayload['rows'] ?? null) ? $retryPayload['rows'] : [];
                $probe = in_array(strtoupper(trim((string)($olt['driver_status'] ?? ''))), ['DOWN', 'WARN'], true)
                    ? $this->service->probeOfflineOlt($userId, $rowId) : ['reachable' => true];
                $result = empty($probe['reachable'])
                    ? ['ok' => false, 'device_down' => !empty($probe['device_down']),
                        'snmp_unavailable' => !empty($probe['snmp_unavailable']),
                        'message' => 'Menunggu koneksi SNMP OLT.']
                    : ($savedRows !== []
                        ? $this->service->retryMiddleOnus($userId, $rowId,
                            (string)($retryPayload['driver_id'] ?? ''), $savedRows, $reportProgress)
                        : ['retry_invalid' => true]);
                if (!empty($result['retry_invalid'])) {
                    $result = $this->service->sync($userId, $rowId,
                        (int)$request['record_changes'] === 1, true, $reportProgress);
                } else {
                    $targetedRetry = true;
                }
                if (!empty($result['replayed_buffer'])) {
                    $result = $this->service->sync($userId, $rowId,
                        (int)$request['record_changes'] === 1, true, $reportProgress);
                }
                $retryRows = array_values(array_filter((array)($result['retry_middle_rows'] ?? []), 'is_array'));
                $ok = !empty($result['ok']);
                $error = trim((string)($result['error'] ?? $result['message'] ?? ''));
                $driverDisallowed = !empty($result['driver_disabled']) || $error === 'Izin dibatasi';
                if ($driverDisallowed) {
                    $ok = false;
                    $cancelled = true;
                    $error = 'Izin driver belum diaktifkan.';
                    $retryRows = [];
                }
                if (!$ok && !$driverDisallowed && $error === 'OLT tidak ditemukan.') {
                    $current = $this->db->getOltByRowId($userId, $rowId);
                    if (!is_array($current) || !monitoring_row_uses_handler($current, 'snmp_olt')) {
                        $cancelled = true;
                        $error = 'OLT sudah tidak terdaftar untuk sinkronisasi SNMP.';
                    }
                }
                if (!$driverDisallowed && !empty($result['queued'])) {
                    $this->db->markOltScanWriting($requestId, $token);
                    $deadline = time() + 1200;
                    while ($this->db->hasPendingOltPipeline($userId, $rowId) && time() < $deadline) sleep(2);
                    if ($this->db->hasPendingOltPipeline($userId, $rowId)) {
                        $ok = false;
                        $error = 'Penulisan hasil sinkronisasi awal belum selesai.';
                        $retryRows = [];
                    } elseif ($ok) {
                        $failed = $this->db->pdo()->prepare(
                            "SELECT last_error FROM sync_pipeline WHERE user_id=:uid AND olt_row_id=:row
                             AND handler='snmp_olt' AND id>:marker AND status='failed' ORDER BY id DESC LIMIT 1"
                        );
                        $failed->execute(['uid' => $userId, 'row' => $rowId, 'marker' => $pipelineMarker]);
                        $failure = $failed->fetchColumn();
                        if ($failure !== false) {
                            $ok = false;
                            $error = trim((string)$failure) ?: 'Penulisan hasil sinkronisasi awal gagal.';
                            $retryRows = [];
                        }
                    }
                }
            }
        } catch (Throwable $failure) {
            $ok = false;
            $error = $failure->getMessage();
            $retryRows = [];
        }

        $this->db->finishOltScanRequest($requestId, $token, $ok, $ok ? '' : $error,
            $cancelled, $retryRows, $retryDriverId);
        if (empty($result['device_down']) && empty($result['snmp_unavailable'])) $this->db->logApplication($userId, 'OLT',
            (int)$request['record_changes'] === 1 ? 'full_scan' : 'initial_sync',
            $cancelled ? 'warning' : ($ok ? 'success' : 'error'),
            $cancelled ? 'Permintaan sinkronisasi dibatalkan: ' . $error
                : ($ok ? ($targetedRetry ? 'Pembacaan ulang detail ONU selesai.' : 'Pembacaan penuh OLT selesai.')
                    : ($targetedRetry ? 'Pembacaan ulang detail ONU gagal: ' : 'Pembacaan penuh OLT tidak lengkap: ') . $error)
        );
        return true;
    }

    private function snapshotWriter(): OltSnapshotWriteService
    {
        if ($this->snapshotWriter === null) {
            require_once __DIR__ . '/OltSnapshotWriteService.php';
            $this->snapshotWriter = new OltSnapshotWriteService($this->db);
        }
        return $this->snapshotWriter;
    }
}
