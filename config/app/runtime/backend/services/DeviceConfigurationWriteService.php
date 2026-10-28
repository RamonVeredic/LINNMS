<?php

declare(strict_types=1);

final class DeviceConfigurationWriteService
{
    public function __construct(private LinnmsRepository $db)
    {
    }

    public function updateConnection(int $userId, int $rowId, array $data): bool
    {
        if ($userId < 1 || $rowId < 1 || $data === []) return false;
        return $this->db->updateDeviceConnection($userId, $rowId, $data);
    }

    public function writeCommunity(int $rowId, int $userId, string $community): void
    {
        if ($rowId < 1 || $userId < 1) return;
        $this->db->saveDeviceCommunity($rowId, $userId, $community);
    }
}
