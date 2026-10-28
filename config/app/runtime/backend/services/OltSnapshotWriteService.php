<?php

declare(strict_types=1);

final class OltSnapshotWriteService
{
    public function __construct(
        private LinnmsRepository $db,
        private ?OnuSnapshotWriteService $onuWriter = null
    ) {
    }

    public function writeBufferedSnapshot(
        int $userId,
        int $rowId,
        array $olt,
        array $snapshot,
        bool $recordChanges
    ): array {
        $this->assertTarget($userId, $rowId, $olt);
        $oltId = (int)($olt['olt_id'] ?? 0);
        $deviceId = (int)($olt['id'] ?? 0);
        if ($oltId < 1 || $deviceId < 1) {
            throw new InvalidArgumentException('Identitas OLT tidak lengkap.');
        }

        $recordChanges = array_key_exists('record_changes', $snapshot)
            ? (bool)$snapshot['record_changes']
            : $recordChanges;
        $flags = ['middle_completed' => false, 'inventory_discovered' => false, 'mark_monitoring' => false];

        if (!empty($snapshot['middle_only'])) {
            $this->onus()->writeMiddleDetails(
                $userId,
                $oltId,
                (array)($snapshot['middle_rows'] ?? [])
            );
            return $flags;
        }

        if (!empty($snapshot['finish_only'])) {
            if (is_array($snapshot['system'] ?? null)) {
                $this->mergeSystemFields($userId, $rowId, $snapshot['system'], ['vendor'], 'low');
            }
            $seenIndexes = array_values(array_filter(array_map(
                static fn(mixed $value): string => trim((string)$value),
                (array)($snapshot['seen_indexes'] ?? [])
            ), static fn(string $value): bool => $value !== ''));
            $completeInventory = !empty($snapshot['complete_inventory']);
            $unconfiguredComplete = !empty($snapshot['unconfigured_complete']);
            if ($seenIndexes !== [] && ($completeInventory || $unconfiguredComplete)) {
                $this->onus()->replaceOnus(
                    $userId,
                    $oltId,
                    [],
                    $recordChanges,
                    $completeInventory,
                    $unconfiguredComplete,
                    'stream_finish',
                    $seenIndexes
                );
            } else {
                $totalOnus = (int)($snapshot['total_onus'] ?? 0);
                if ($totalOnus > 0) {
                    $this->db->markDeviceOnline($deviceId, $userId, 'OK', $totalOnus);
                }
            }
            if (!empty($snapshot['lan_statuses']['ok'])) {
                $lanStatuses = $snapshot['lan_statuses'];
                $this->onus()->writeEthernetStatuses(
                    $userId,
                    $deviceId,
                    (array)($lanStatuses['ports'] ?? []),
                    (array)($lanStatuses['scope_refs'] ?? []),
                    !empty($lanStatuses['dynamic_ports']),
                    (array)($snapshot['lan_configs'] ?? [])
                );
            }
            if (!empty($snapshot['low_type_rows'])) {
                $this->onus()->writeTypes($userId, $deviceId, (array)$snapshot['low_type_rows']);
            }
            $this->onus()->writeLowSnapshot($userId, $olt, $snapshot);
            $flags['middle_completed'] = !empty($snapshot['middle_completed']);
            $flags['inventory_discovered'] = !empty($snapshot['low_completed']);
            $flags['mark_monitoring'] = true;
            return $flags;
        }

        if (is_array($snapshot['system'] ?? null)) {
            $this->mergeSystemFields($userId, $rowId, $snapshot['system'], ['vendor'], 'low');
        }
        $this->onus()->replaceOnus(
            $userId,
            $oltId,
            (array)($snapshot['rows'] ?? []),
            $recordChanges,
            !empty($snapshot['complete_inventory']),
            !empty($snapshot['unconfigured_complete']),
            'fast_sync'
        );
        if (!empty($snapshot['middle_rows'])) {
            $this->onus()->writeMiddleDetails(
                $userId,
                $oltId,
                (array)$snapshot['middle_rows']
            );
        }
        if (!empty($snapshot['low_type_rows'])) {
            $this->onus()->writeTypes($userId, $deviceId, (array)$snapshot['low_type_rows']);
        }
        $this->onus()->writeLowSnapshot($userId, $olt, $snapshot);
        if (!empty($snapshot['lan_statuses']['ok'])) {
            $lanStatuses = $snapshot['lan_statuses'];
            $this->onus()->writeEthernetStatuses(
                $userId,
                $deviceId,
                (array)($lanStatuses['ports'] ?? []),
                (array)($lanStatuses['scope_refs'] ?? []),
                !empty($lanStatuses['dynamic_ports']),
                (array)($snapshot['lan_configs'] ?? [])
            );
        }
        $flags['middle_completed'] = !empty($snapshot['middle_completed']);
        $flags['inventory_discovered'] = !empty($snapshot['low_completed']);
        $flags['mark_monitoring'] = true;
        return $flags;
    }

    public function mergeSystemFields(
        int $userId,
        int $rowId,
        array $system,
        array $excluded = [],
        string $phase = 'low'
    ): bool {
        if ($userId < 1 || $rowId < 1) {
            throw new InvalidArgumentException('Sasaran snapshot OLT tidak valid.');
        }
        return $this->db->mergeOltSystemFields($userId, $rowId, $system, $excluded, $phase);
    }

    public function writeDriverExternalOltInventory(int $userId, array $items, bool $recordChanges = true): array
    {
        if ($userId < 1) return [];
        return $this->db->syncOlts($userId, $items, $recordChanges);
    }

    public function writeDriverExternalOnuInventory(
        int $userId,
        int $oltId,
        array $items,
        float $signalThreshold = 1.0,
        bool $recordChanges = true
    ): array {
        if ($userId < 1 || $oltId < 1) return [];
        return $this->db->syncOnusForOlt($userId, $oltId, $items, $signalThreshold, $recordChanges);
    }

    public function writeCardTemperatures(int $userId, int $oltDeviceId, array $temperatures): int
    {
        if ($userId < 1 || $oltDeviceId < 1 || $temperatures === []) return 0;
        return $this->db->updateOltCardTemperatures($userId, $oltDeviceId, $temperatures);
    }

    public function writeCardHealth(int $userId, int $oltDeviceId, array $health): int
    {
        if ($userId < 1 || $oltDeviceId < 1 || $health === []) return 0;
        return $this->db->updateOltCardHealth($userId, $oltDeviceId, $health);
    }

    public function writeCards(int $userId, int $oltDeviceId, array $cards): int
    {
        if ($userId < 1 || $oltDeviceId < 1 || $cards === []) return 0;
        return $this->db->saveOltCards($userId, $oltDeviceId, $cards);
    }

    public function writePonSnapshot(
        int $userId,
        int $oltDeviceId,
        array $ports,
        bool $recordChanges = true,
        string $source = 'snmp',
        bool $fastOnly = false
    ): array {
        if ($userId < 1 || $oltDeviceId < 1 || $ports === []) return [];
        return $this->db->updateOltPonSnapshot($userId, $oltDeviceId, $ports, $recordChanges, $source, $fastOnly);
    }

    public function writePonMiddleSettings(int $userId, int $oltDeviceId, array $ports): int
    {
        if ($userId < 1 || $oltDeviceId < 1 || $ports === []) return 0;
        return $this->db->updateOltPonMiddleSettings($userId, $oltDeviceId, $ports);
    }

    public function writeUplinkPorts(int $userId, int $oltDeviceId, array $ports): void
    {
        if ($userId < 1 || $oltDeviceId < 1) return;
        $this->db->saveOltPorts($userId, $oltDeviceId, 'uplink', $ports);
    }

    public function writeVlanCatalog(int $userId, int $oltDeviceId, array $rows): int
    {
        if ($userId < 1 || $oltDeviceId < 1) return 0;
        return $this->db->saveOltVlanCatalogRows($userId, $oltDeviceId, $rows);
    }

    public function writeProfileSnapshots(int $userId, int $oltDeviceId, array $rows, array $kinds = []): int
    {
        if ($userId < 1 || $oltDeviceId < 1) return 0;
        if ($rows === [] && $kinds === []) return 0;
        return $this->db->saveOltProfileSnapshots($userId, $oltDeviceId, $rows, $kinds);
    }

    public function markSyncFailed(
        int $userId,
        int $oltId,
        string $error,
        bool $recordChanges = true,
        bool $deviceDown = false
    ): bool {
        if ($userId < 1 || $oltId < 1) return false;
        return $this->db->markSnmpOltSyncFailed($userId, $oltId, $error, $recordChanges, $deviceDown);
    }

    public function recordReadFailure(int $userId, int $rowId, string $source, string $message, string $scope = ''): void
    {
        if ($userId < 1 || $rowId < 1) return;
        $this->db->recordReadFailure($userId, $source, $message,
            ['scope' => $scope !== '' ? $scope : 'olt|' . $rowId]);
    }

    public function clearReadFailure(int $userId, int $rowId, string $scope = ''): void
    {
        if ($userId < 1 || $rowId < 1) return;
        $this->db->clearReadFailure($userId, $scope !== '' ? $scope : 'olt|' . $rowId);
    }

    public function recordConnectionProbe(int $oltDeviceId, int $userId, string $kind, bool $ok, string $reason = ''): void
    {
        if ($oltDeviceId < 1 || $userId < 1) return;
        $this->db->recordConnectionProbe($oltDeviceId, $userId, $kind, $ok, $reason);
    }

    public function markConnectionStatus(int $oltDeviceId, int $userId, string $kind, string $status): void
    {
        if ($oltDeviceId < 1 || $userId < 1) return;
        $this->db->markDeviceConnectionStatus($oltDeviceId, $userId, $kind, $status);
    }

    public function markMonitoringSync(int $userId, string $driverId): void
    {
        if ($userId < 1 || trim($driverId) === '') return;
        $this->db->markMonitoringSync($userId, $driverId);
    }

    private function onus(): OnuSnapshotWriteService
    {
        if ($this->onuWriter === null) {
            require_once __DIR__ . '/OnuSnapshotWriteService.php';
            $this->onuWriter = new OnuSnapshotWriteService($this->db);
        }
        return $this->onuWriter;
    }

    private function assertTarget(int $userId, int $rowId, array $olt): void
    {
        if ($userId < 1 || $rowId < 1) {
            throw new InvalidArgumentException('Sasaran snapshot OLT tidak valid.');
        }
        if ((int)($olt['id'] ?? 0) < 1 || (int)($olt['olt_id'] ?? 0) < 1) {
            throw new RuntimeException('OLT tidak ditemukan.');
        }
    }
}
