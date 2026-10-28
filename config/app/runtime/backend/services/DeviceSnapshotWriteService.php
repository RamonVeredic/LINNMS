<?php

declare(strict_types=1);

final class DeviceSnapshotWriteService
{
    public function __construct(private LinnmsRepository $db)
    {
    }

    public function write(int $userId, int $rowId, array $system, array $interfaces): array
    {
        $device = $this->target($userId, $rowId);
        if ($system === []) {
            throw new InvalidArgumentException('Snapshot sistem perangkat kosong.');
        }
        $interfaces = array_values(array_filter($interfaces, 'is_array'));
        if ($interfaces === []) {
            throw new InvalidArgumentException('Snapshot interface perangkat kosong.');
        }

        return $this->db->updateSnmpDeviceSnapshot(
            $userId,
            (int)$device['id'],
            $system,
            $interfaces
        );
    }

    public function markUnreachable(int $userId, int $rowId, string $message): ?array
    {
        if ($userId < 1 || $rowId < 1) return null;
        return $this->db->markSnmpDeviceError($userId, $rowId, $message);
    }

    public function recordReadFailure(int $userId, int $rowId, string $message): void
    {
        if ($userId < 1 || $rowId < 1) return;
        $this->db->recordReadFailure($userId, 'Perangkat', $message,
            ['scope' => 'device|' . $rowId]);
    }

    public function clearReadFailure(int $userId, int $rowId): void
    {
        if ($userId < 1 || $rowId < 1) return;
        $this->db->clearReadFailure($userId, 'device|' . $rowId);
    }

    private function target(int $userId, int $rowId): array
    {
        if ($userId < 1 || $rowId < 1) {
            throw new InvalidArgumentException('Sasaran snapshot perangkat tidak valid.');
        }
        $device = $this->db->getSnmpDevice($userId, $rowId);
        if (!is_array($device)) {
            throw new RuntimeException('Perangkat tidak ditemukan.');
        }
        return $device;
    }
}
