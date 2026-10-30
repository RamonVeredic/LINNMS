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
        'oltOnuTypeFields' => is_array($data['onu_type_fields'] ?? null) ? $data['onu_type_fields'] : [],
    ];
}

function olt_view_page_data(int $userId, array $context): array
{
    require_once LINNMS_UI_ROOT . '/view/shared/models/OltDetailViewModel.php';
    require_once __DIR__ . '/view-model.php';

    $detail = $context['oltDetail'] ?? null;
    if (!is_array($detail) || (int)($detail['id'] ?? 0) < 1) {
        throw new RuntimeException('OLT tidak ditemukan.');
    }

    $read = olt_view_read_data($userId, (int)$detail['id']);
    $definition = $read['oltViewDefinition'];
    $family = strtolower(trim((string)($definition['olt_view_family'] ?? '')));
    if (!in_array($family, ['external', 'hioso', 'zte'], true)) {
        throw new RuntimeException('Jenis tampilan OLT tidak terdaftar.');
    }

    $page = array_merge($context, $read);
    $page['oltVlanPreferAdminState'] = empty($read['oltViewCapabilities']['vlan_device']);
    $page['oltOnuTypeManager'] = OltDetailViewModel::onuTypeManager(
        (int)$detail['id'], $read['oltViewCapabilities'],
        (array)($context['oltOnuTypes'] ?? []), $read['oltOnuTypeFields']
    );
    return array_merge($page, olt_view_controller_data($family, $page));
}
