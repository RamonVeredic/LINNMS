<?php

declare(strict_types=1);

final class DeviceSnapshotReadService
{
    public function __construct(private LinnmsRepository $db)
    {
    }

    public function read(int $userId, int $rowId, string $kind): array
    {
        $expectedType = match ($kind) {
            'mikrotik' => 'mikrotik',
            'switch' => 'cisco_catalyst_2960s',
            default => '',
        };
        if ($rowId < 1 || $expectedType === '') {
            return ['ok' => false, 'code' => 'validation', 'data' => []];
        }

        $device = $this->db->getSnmpDevice($userId, $rowId);
        if (!is_array($device) || (string)($device['device_type'] ?? '') !== $expectedType) {
            return ['ok' => false, 'code' => 'not_found', 'data' => []];
        }

        $system = json_decode((string)($device['system_json'] ?? ''), true);
        $interfaces = json_decode((string)($device['interfaces_json'] ?? ''), true);
        return ['ok' => true, 'code' => 'ok', 'data' => [
            'device' => $device,
            'system' => is_array($system) ? $system : [],
            'interfaces' => is_array($interfaces) ? array_values(array_filter($interfaces, 'is_array')) : [],
            'live' => false,
            'error' => '',
        ]];
    }
}
