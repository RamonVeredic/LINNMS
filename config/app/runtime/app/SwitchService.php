<?php

declare(strict_types=1);

final class SwitchService
{
    private const DEVICE_TYPE = 'cisco_catalyst_2960s';

    public function __construct(private readonly SnmpDeviceService $snmp) {}

    public function create(int $userId, string $driverId, array $input): array
    {
        $definition = monitoring_driver_definition($driverId);
        if (!is_array($definition) || (string)($definition['handler'] ?? '') !== 'switch_snmp') {
            throw new InvalidArgumentException('Driver switch tidak valid.');
        }
        return $this->snmp->create($userId, $driverId, $input);
    }

    public function update(int $userId, int $rowId, string $driverId, array $input): array
    {
        $definition = monitoring_driver_definition($driverId);
        if (!is_array($definition) || (string)($definition['handler'] ?? '') !== 'switch_snmp') {
            throw new InvalidArgumentException('Driver switch tidak valid.');
        }
        return $this->snmp->update($userId, $rowId, $driverId, $input);
    }

    public function inspect(int $userId, int $rowId, bool $refresh = true): array
    {
        return $this->snmp->inspect($userId, $rowId, self::DEVICE_TYPE, $refresh);
    }

    public function interfaceRates(int $userId, int $rowId, array $ifIndexes): array
    {
        return $this->snmp->interfaceRates($userId, $rowId, $ifIndexes, self::DEVICE_TYPE);
    }

    public function syncDriver(int $userId, string $driverId, ?callable $yieldAfterDevice = null): array
    {
        return $this->snmp->syncAll($userId, $driverId, $yieldAfterDevice);
    }

    public function delete(int $userId, int $rowId): bool
    {
        return $this->snmp->deleteByType($userId, $rowId, self::DEVICE_TYPE);
    }
}
