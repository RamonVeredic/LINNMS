<?php

declare(strict_types=1);

final class OnuSnapshotWriteService
{
    public function __construct(private LinnmsRepository $db)
    {
    }

    public function writeMiddleDetails(int $userId, int $oltDeviceId, array $rows): void
    {
        if ($userId < 1 || $oltDeviceId < 1 || $rows === []) return;
        $this->db->updateSnmpOnuMiddleDetails($userId, $oltDeviceId, $rows);
    }

    public function writeTypes(int $userId, int $oltDeviceId, array $rows): void
    {
        if ($userId < 1 || $oltDeviceId < 1 || $rows === []) return;
        $this->db->updateSnmpOnuTypesFromCli($userId, $oltDeviceId, $rows);
    }

    public function writeEthernetPorts(int $userId, int $oltDeviceId, array $ports, array $counts = []): int
    {
        if ($userId < 1 || $oltDeviceId < 1 || ($ports === [] && $counts === [])) return 0;
        return $this->db->saveOnuEthernetPorts($userId, $oltDeviceId, $ports, $counts);
    }

    public function writeEthernetStatuses(
        int $userId,
        int $oltDeviceId,
        array $ports,
        ?array $scopeRefs = null,
        bool $dynamicPorts = false,
        array $configs = []
    ): int {
        if ($userId < 1 || $oltDeviceId < 1) return 0;
        return $this->db->updateOnuEthernetPortStatuses(
            $userId,
            $oltDeviceId,
            $ports,
            $scopeRefs,
            $dynamicPorts,
            $configs
        );
    }

    public function writeEthernetConfigurations(int $userId, int $oltDeviceId, array $configs): int
    {
        if ($userId < 1 || $oltDeviceId < 1 || $configs === []) return 0;
        return $this->db->updateOnuEthernetPortConfigurations($userId, $oltDeviceId, $configs);
    }

    public function writeServiceConfigs(int $userId, int $oltDeviceId, array $configs, string $source = 'sync'): int
    {
        if ($userId < 1 || $oltDeviceId < 1 || $configs === []) return 0;
        return $this->db->saveOnuServiceConfigs($userId, $oltDeviceId, $configs, $source);
    }

    public function writeDetails(int $userId, int $oltDeviceId, array $details): int
    {
        if ($userId < 1 || $oltDeviceId < 1 || $details === []) return 0;
        return $this->db->saveOnuDetailSnapshots($userId, $oltDeviceId, $details);
    }

    public function replaceOnus(
        int $userId,
        int $oltDeviceId,
        array $rows,
        bool $recordChanges,
        bool $completeInventory,
        bool $unconfiguredComplete,
        string $source,
        array $seenIndexes = []
    ): array {
        if ($userId < 1 || $oltDeviceId < 1) {
            throw new InvalidArgumentException('Sasaran snapshot ONU tidak valid.');
        }
        return $this->db->replaceSnmpOnus(
            $userId,
            $oltDeviceId,
            $rows,
            $recordChanges,
            $completeInventory,
            $unconfiguredComplete,
            $source,
            $seenIndexes
        );
    }

    public function writeLowSnapshot(int $userId, array $olt, array $snapshot): void
    {
        $deviceId = (int)($olt['id'] ?? 0);
        if ($userId < 1 || $deviceId < 1) return;
        $ethernet = (array)($snapshot['low_ethernet'] ?? []);
        if (!empty($ethernet['counts'])) {
            $this->db->saveOnuEthernetPorts(
                $userId,
                $deviceId,
                (array)($ethernet['ports'] ?? []),
                (array)$ethernet['counts']
            );
        }
        if (!empty($snapshot['low_details'])) {
            $this->db->saveOnuDetailSnapshots($userId, $deviceId, (array)$snapshot['low_details']);
        }
    }

    public function writeSnmpName(int $userId, int $onuRowId, string $name): bool
    {
        if ($userId < 1 || $onuRowId < 1) return false;
        return $this->db->updateSnmpOnuName($userId, $onuRowId, $name);
    }

    public function writeName(int $userId, int $onuRowId, string $name): bool
    {
        if ($userId < 1 || $onuRowId < 1) return false;
        return $this->db->updateOnuName($userId, $onuRowId, $name);
    }

    public function writeLocation(int $userId, int $onuRowId, ?string $location): array
    {
        if ($userId < 1 || $onuRowId < 1) return ['ok' => false];
        return $this->db->updateOnuLocation($userId, $onuRowId, $location);
    }

    public function writeLabels(int $userId, int $oltDeviceId, array $labels): int
    {
        if ($userId < 1 || $oltDeviceId < 1) return 0;
        return $this->db->updateSnmpOnuLabels($userId, $oltDeviceId, $labels);
    }

    public function writeSerialIfMissing(int $userId, int $onuRowId, string $serial): bool
    {
        if ($userId < 1 || $onuRowId < 1 || trim($serial) === '') return false;
        return $this->db->saveOnuSerialIfMissing($userId, $onuRowId, $serial);
    }

    public function writeAuthMode(int $userId, int $onuRowId, string $authMode): bool
    {
        if ($userId < 1 || $onuRowId < 1) return false;
        return $this->db->updateSnmpOnuAuthMode($userId, $onuRowId, $authMode);
    }

    public function writeType(int $userId, int $onuRowId, string $type): bool
    {
        if ($userId < 1 || $onuRowId < 1) return false;
        return $this->db->updateOnuType($userId, $onuRowId, $type);
    }

    public function writeProvisioned(
        int $userId,
        int $oltDeviceId,
        string $ref,
        string $serial,
        string $name,
        string $location,
        ?int $ponId = null
    ): int {
        if ($userId < 1 || $oltDeviceId < 1 || trim($ref) === '') return 0;
        return $this->db->saveProvisionedOnu($userId, $oltDeviceId, $ref, $serial, $name, $location, $ponId);
    }

    public function writeRegistrationProfile(
        int $userId,
        int $onuRowId,
        ?string $name,
        ?string $location,
        ?string $onuType
    ): array {
        if ($userId < 1 || $onuRowId < 1) return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];
        return $this->db->saveOnuRegistrationProfile($userId, $onuRowId, $name, $location, $onuType);
    }

    public function clearRegistrationProfile(int $userId, int $onuRowId): array
    {
        if ($userId < 1 || $onuRowId < 1) return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];
        return $this->db->clearOnuRegistrationProfile($userId, $onuRowId);
    }

    public function transferRegistrationProfile(int $userId, int $fromRowId, int $toRowId): array
    {
        if ($userId < 1 || $fromRowId < 1 || $toRowId < 1) {
            return ['ok' => false, 'message' => 'ONU pengganti tidak valid.'];
        }
        return $this->db->transferOnuRegistrationProfile($userId, $fromRowId, $toRowId);
    }

    public function deleteSnapshot(int $userId, int $onuRowId): bool
    {
        if ($userId < 1 || $onuRowId < 1) return false;
        return $this->db->deleteSnmpOnuSnapshot($userId, $onuRowId);
    }

    public function writeEthernetField(
        int $userId,
        int $onuRowId,
        string $portId,
        string $field,
        string $value
    ): bool {
        if ($userId < 1 || $onuRowId < 1) return false;
        return $this->db->updateOnuEthernetPortField($userId, $onuRowId, $portId, $field, $value);
    }

    public function writeLanControlSnapshot(
        int $userId,
        int $onuRowId,
        string $portId,
        string $field,
        string $value,
        string $controlBytes
    ): bool {
        if ($userId < 1 || $onuRowId < 1) return false;
        return $this->db->updateOnuLanControlSnapshot(
            $userId,
            $onuRowId,
            $portId,
            $field,
            $value,
            $controlBytes
        );
    }

    public function restoreBackupRows(int $userId, array $updates): int
    {
        if ($userId < 1 || $updates === []) return 0;
        return $this->db->restoreOnuBackupRows($userId, $updates);
    }

    public function moveIndex(
        int $userId,
        int $onuRowId,
        int $oltDeviceId,
        string $oldIndex,
        string $newIndex
    ): bool {
        if ($userId < 1 || $onuRowId < 1 || $oltDeviceId < 1) return false;
        return $this->db->moveOnuIndex($userId, $onuRowId, $oltDeviceId, $oldIndex, $newIndex);
    }
}
