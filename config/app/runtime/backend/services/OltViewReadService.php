<?php

declare(strict_types=1);

final class OltViewReadService
{
    public function __construct(private LinnmsRepository $db)
    {
    }

    public function read(int $userId, int $rowId): array
    {
        if ($userId < 1 || $rowId < 1) throw new InvalidArgumentException('Identitas OLT tidak valid.');

        $olt = $this->db->getOltByRowId($userId, $rowId);
        if (!is_array($olt)) throw new InvalidArgumentException('OLT tidak ditemukan.');

        $driverId = device_driver_type($olt);
        $definition = monitoring_driver_definition($driverId) ?? [];
        $driverAllowed = olt_driver_support_enabled($this->db->getOltDriverSupportFlags(), $driverId);
        $capabilities = OltDetailViewModel::capabilities($definition, $driverAllowed, $olt);
        if (($definition['handler'] ?? '') === 'external_api') {
            $capabilities['refresh_detail'] = true;
        }

        return [
            'definition' => [
                'handler' => (string)($definition['handler'] ?? ''),
                'implementation' => (string)($definition['implementation'] ?? ''),
                'olt_view_template' => (string)($definition['olt_view_template'] ?? ''),
            ],
            'capabilities' => $capabilities,
            'optic_source_supported' => !empty($definition['telnet_cli']),
            'vlans' => $this->db->getOltVlans($userId, $rowId),
            'uplink_ports' => $this->db->getOltPorts($userId, $rowId, 'uplink'),
            'traffic_interfaces' => $this->db->getDeviceTrafficInterfaces($userId, $rowId),
            'traffic_interval_seconds' => $this->db->getOltGraphIntervalMinutes() * 60,
        ];
    }
}
