<?php

declare(strict_types=1);

final class OltConfigurationWriteService
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

    public function writeCredentials(int $rowId, int $userId, array $credentials): void
    {
        if ($rowId < 1 || $userId < 1) return;
        $this->db->saveDeviceCredentials($rowId, $userId, $credentials);
    }

    public function markConfigChanged(int $userId, int $rowId): void
    {
        if ($userId < 1 || $rowId < 1) return;
        $this->db->markDeviceConfigChanged($userId, $rowId);
    }

    public function clearConfigChanged(int $userId, int $rowId): void
    {
        if ($userId < 1 || $rowId < 1) return;
        $this->db->clearDeviceConfigChanged($userId, $rowId);
    }

    public function writeVlanDefinition(int $userId, int $oltDeviceId, int $vlanId, string $name): bool
    {
        if ($userId < 1 || $oltDeviceId < 1) return false;
        return $this->db->saveOltVlanLocalDefinition($userId, $oltDeviceId, $vlanId, $name);
    }

    public function deleteVlanDefinition(int $userId, int $oltDeviceId, int $vlanId): bool
    {
        if ($userId < 1 || $oltDeviceId < 1) return false;
        return $this->db->deleteOltVlanLocalDefinition($userId, $oltDeviceId, $vlanId);
    }

    public function writeOnuTypeDefinition(
        int $userId,
        int $oltDeviceId,
        string $name,
        array $data,
        string $operation
    ): bool {
        if ($userId < 1 || $oltDeviceId < 1 || trim($name) === '') return false;
        return $this->db->saveOltOnuTypeLocalDefinition($userId, $oltDeviceId, $name, $data, $operation);
    }

    public function deleteOnuTypeDefinition(int $userId, int $oltDeviceId, string $name): bool
    {
        if ($userId < 1 || $oltDeviceId < 1 || trim($name) === '') return false;
        return $this->db->deleteOltOnuTypeLocalDefinition($userId, $oltDeviceId, $name);
    }

    public function writeLocalProfile(
        int $userId,
        int $oltDeviceId,
        string $kind,
        string $name,
        array $data
    ): bool {
        if ($userId < 1 || $oltDeviceId < 1) return false;
        return $this->db->saveOltLocalProfile($userId, $oltDeviceId, $kind, $name, $data);
    }

    public function deleteLocalProfile(int $userId, int $oltDeviceId, string $kind, string $name): bool
    {
        if ($userId < 1 || $oltDeviceId < 1) return false;
        return $this->db->deleteOltLocalProfile($userId, $oltDeviceId, $kind, $name);
    }

    public function commitPonDescription(
        int $userId,
        int $oltDeviceId,
        int $oltLocalId,
        string $source,
        string $portRef,
        int $ponNumber,
        string $description,
        string $actor = ''
    ): array {
        if ($userId < 1 || $oltDeviceId < 1) {
            return ['ok' => false, 'changed' => false, 'previous' => '', 'port_ref' => '', 'event' => null];
        }
        return $this->db->commitOltPonDescription(
            $userId,
            $oltDeviceId,
            $oltLocalId,
            $source,
            $portRef,
            $ponNumber,
            $description,
            $actor
        );
    }

    public function writePonDescription(
        int $userId,
        int $oltDeviceId,
        string $portRef,
        int $ponNumber,
        string $description,
        string $source = 'snmp'
    ): array {
        if ($userId < 1 || $oltDeviceId < 1) {
            return ['ok' => false, 'changed' => false, 'previous' => '', 'port_ref' => ''];
        }
        return $this->db->saveOltPonDescriptionLocal(
            $userId,
            $oltDeviceId,
            $portRef,
            $ponNumber,
            $description,
            $source
        );
    }

    public function writePonFlowControl(
        int $userId,
        int $oltDeviceId,
        string $portRef,
        int $ponNumber,
        string $value
    ): bool {
        if ($userId < 1 || $oltDeviceId < 1) return false;
        return $this->db->updateOltPonFlowControl($userId, $oltDeviceId, $portRef, $ponNumber, $value);
    }

    public function writePonAdminState(
        int $userId,
        int $oltDeviceId,
        string $portRef,
        int $ponNumber,
        string $value
    ): bool {
        if ($userId < 1 || $oltDeviceId < 1) return false;
        return $this->db->updateOltPonAdminState($userId, $oltDeviceId, $portRef, $ponNumber, $value);
    }

    public function writeCardLabel(int $userId, int $oltDeviceId, string $cardKey, string $label): bool
    {
        if ($userId < 1 || $oltDeviceId < 1) return false;
        return $this->db->saveOltCardLabel($userId, $oltDeviceId, $cardKey, $label);
    }

    public function writeCliCredentials(
        int $userId,
        int $oltId,
        int $hybridStatus,
        int $port,
        ?string $username,
        ?string $passwordEnc = null
    ): bool {
        if ($userId < 1 || $oltId < 1) return false;
        return $this->db->saveSnmpOltCliCredentials(
            $userId,
            $oltId,
            $hybridStatus,
            $port,
            $username,
            $passwordEnc
        );
    }

    public function writeApiConnection(
        int $userId,
        int $rowId,
        string $name,
        string $ip,
        int $snmpPort
    ): bool {
        if ($userId < 1 || $rowId < 1) return false;
        return $this->db->updateApiOltConnectionSnapshot($userId, $rowId, $name, $ip, $snmpPort);
    }

    public function markApiFirstScanStarted(int $userId, int $oltId): void
    {
        if ($userId < 1 || $oltId < 1) return;
        $this->db->markApiOltFirstScanStarted($userId, $oltId);
    }
}
