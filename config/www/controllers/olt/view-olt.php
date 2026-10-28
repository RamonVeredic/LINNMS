<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';

function olt_view_read_data(int $userId, int $rowId): array
{
    $data = internal_api_request($userId, 'olt.view.read', ['row_id' => $rowId]);
    return [
        'oltViewDefinition' => is_array($data['definition'] ?? null) ? $data['definition'] : [],
        'oltViewCapabilities' => is_array($data['capabilities'] ?? null) ? $data['capabilities'] : [],
        'oltOpticSourceSupported' => (bool)($data['optic_source_supported'] ?? false),
        'oltVlanRows' => is_array($data['vlans'] ?? null) ? $data['vlans'] : [],
        'oltUplinkPorts' => is_array($data['uplink_ports'] ?? null) ? $data['uplink_ports'] : [],
        'oltTrafficSelected' => is_array($data['traffic_interfaces'] ?? null) ? $data['traffic_interfaces'] : [],
        'oltTrafficIntervalSeconds' => (int)($data['traffic_interval_seconds'] ?? 0),
    ];
}
