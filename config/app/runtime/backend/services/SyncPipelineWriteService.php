<?php

declare(strict_types=1);

final class SyncPipelineWriteService
{
    private ?OltSnapshotWriteService $oltWriter = null;
    private ?DeviceSnapshotWriteService $deviceWriter = null;

    public function __construct(private LinnmsRepository $db)
    {
    }

    public function write(int $userId, int $rowId, string $handler, array $payload): array
    {
        if ($userId < 1 || $rowId < 1) {
            return ['ok' => false, 'message' => 'Identitas sasaran pipeline tidak valid.'];
        }
        return match ($handler) {
            'snmp_olt' => $this->writeOlt($userId, $rowId, $payload),
            'mikrotik_snmp', 'switch_snmp' => $this->writeDevice($userId, $rowId, $handler, $payload),
            default => ['ok' => false, 'message' => 'Handler belum didukung: ' . $handler, 'retryable' => false],
        };
    }

    private function writeOlt(int $userId, int $rowId, array $payload): array
    {
        $olt = $this->db->getOltByRowId($userId, $rowId);
        if (!is_array($olt) || !monitoring_row_uses_handler($olt, 'snmp_olt')) {
            return ['ok' => false, 'message' => 'OLT tidak ditemukan.', 'retryable' => false];
        }
        $recordChanges = array_key_exists('record_changes', $payload)
            ? (bool)$payload['record_changes']
            : true;
        $result = $this->oltWriter()->writeBufferedSnapshot($userId, $rowId, $olt, $payload, $recordChanges);
        if (!empty($result['middle_completed'])) {
            @touch(cache_path('olt-middle-' . $userId . '-' . $rowId . '.stamp'));
        }
        if (!empty($result['inventory_discovered'])) {
            $this->markInventoryDiscovery($olt);
        }
        if (!empty($result['mark_monitoring'])) {
            $this->oltWriter()->markMonitoringSync($userId, (string)device_driver_type($olt));
        }
        return ['ok' => true];
    }

    private function writeDevice(int $userId, int $rowId, string $handler, array $payload): array
    {
        $device = $this->db->getSnmpDevice($userId, $rowId);
        if (!is_array($device)) {
            return ['ok' => false, 'message' => 'Perangkat tidak ditemukan.', 'retryable' => false];
        }
        $definition = monitoring_driver_definition(device_driver_type($device));
        if (!is_array($definition) || (string)($definition['handler'] ?? '') !== $handler) {
            return ['ok' => false, 'message' => 'Identitas perangkat pipeline tidak sesuai.', 'retryable' => false];
        }
        $stored = $this->deviceWriter()->write(
            $userId,
            $rowId,
            (array)($payload['system'] ?? []),
            (array)($payload['interfaces'] ?? [])
        );
        return ['ok' => true, 'events' => count((array)($stored['events'] ?? []))];
    }

    private function markInventoryDiscovery(array $olt): void
    {
        $definition = monitoring_driver_definition((string)device_driver_type($olt));
        $family = is_array($definition) ? trim((string)($definition['vendor'] ?? '')) : '';
        if ($family === '') $family = 'olt';
        @touch(cache_path($family . '-cache-snmp-' . (int)($olt['id'] ?? 0) . '.json'));
    }

    private function oltWriter(): OltSnapshotWriteService
    {
        if ($this->oltWriter === null) {
            require_once __DIR__ . '/OltSnapshotWriteService.php';
            $this->oltWriter = new OltSnapshotWriteService($this->db);
        }
        return $this->oltWriter;
    }

    private function deviceWriter(): DeviceSnapshotWriteService
    {
        if ($this->deviceWriter === null) {
            require_once __DIR__ . '/DeviceSnapshotWriteService.php';
            $this->deviceWriter = new DeviceSnapshotWriteService($this->db);
        }
        return $this->deviceWriter;
    }
}
