<?php

declare(strict_types=1);




function device_edit_kind(array $device): string
{
    if ((int)($device['id'] ?? 0) < 1) return '';
    $definition = monitoring_driver_definition(trim((string)device_driver_type($device)));
    if (!is_array($definition)) return '';
    $handler = (string)($definition['handler'] ?? '');
    if ($handler === 'external_api') return 'olt_api';
    if (($definition['group'] ?? '') === 'olt') {
        if ($handler === 'snmp_olt') return 'olt_snmp';
        return '';
    }
    return in_array($handler, ['mikrotik_snmp', 'switch_snmp'], true) ? 'snmp_device' : '';
}


function device_edit_driver_options(
    array $device,
    array $oltSupportFlags = [],
    array $snmpSupportFlags = []
): array {
    $kind = device_edit_kind($device);
    if ($kind === '' || $kind === 'olt_api') return [];

    $currentDriverId = trim((string)device_driver_type($device));
    $currentDefinition = monitoring_driver_definition($currentDriverId);
    if (!is_array($currentDefinition)) return [];
    $handler = (string)($currentDefinition['handler'] ?? '');
    $deviceType = (string)($currentDefinition['device_type'] ?? '');
    $isOlt = in_array($kind, ['olt_snmp', 'olt_api'], true);

    $options = [];
    foreach (($isOlt ? olt_driver_catalog() : snmp_driver_catalog()) as $driverId => $definition) {
        $driverId = (string)$driverId;
        if ((string)($definition['handler'] ?? '') !== $handler) continue;
        if ((string)($definition['device_type'] ?? '') !== $deviceType) continue;
        $isCurrent = $driverId === $currentDriverId;
        if (!$isCurrent) {
            $enabled = $isOlt
                ? olt_driver_support_enabled($oltSupportFlags, $driverId)
                : snmp_driver_support_enabled($snmpSupportFlags, $driverId);
            if (!$enabled) continue;
        }
        $options[$driverId] = [
            'label' => (string)($definition['label'] ?? $driverId),
            'method' => (string)($definition['method'] ?? 'snmp'),
            'telnet_cli' => !empty($definition['telnet_cli']),
            'default_snmp_community' => (string)($definition['default_snmp_community'] ?? 'public'),
            'current' => $isCurrent,
        ];
    }

    if ($options === []) {
        $options[$currentDriverId] = [
            'label' => (string)($currentDefinition['label'] ?? $currentDriverId),
            'method' => (string)($currentDefinition['method'] ?? 'snmp'),
            'telnet_cli' => !empty($currentDefinition['telnet_cli']),
            'default_snmp_community' => (string)($currentDefinition['default_snmp_community'] ?? 'public'),
            'current' => true,
        ];
    }

    return $options;
}


function device_edit_view_url(array $deviceEdit): string
{
    $rowId = (int)($deviceEdit['row_id'] ?? 0);
    return match ((string)($deviceEdit['kind'] ?? '')) {
        'olt_snmp', 'olt_api' => public_page_url('view-olt', ['id' => $rowId]),
        default => public_page_url(
            (string)($deviceEdit['device_type'] ?? '') === 'mikrotik' ? 'view-mikrotik' : 'view-switch',
            ['id' => $rowId]
        ),
    };
}


function device_edit_view_model(
    array $device,
    array $oltSupportFlags = [],
    array $snmpSupportFlags = []
): array {
    $kind = device_edit_kind($device);
    $driverId = trim((string)device_driver_type($device));
    $definition = monitoring_driver_definition($driverId);
    $definition = is_array($definition) ? $definition : [];
    $options = device_edit_driver_options($device, $oltSupportFlags, $snmpSupportFlags);

    $isOlt = in_array($kind, ['olt_snmp', 'olt_api'], true);
    $deviceType = (string)($device['device_type'] ?? ($isOlt ? 'olt' : ''));
    $label = $isOlt ? 'OLT' : ($deviceType === 'mikrotik' ? 'MikroTik' : 'Switch');
    $method = (string)($definition['method'] ?? 'snmp');
    $isApi = $kind === 'olt_api';
    $snmpPort = (int)($device['snmp_port'] ?? 0);
    if ($snmpPort < 1 || $snmpPort > 65535) $snmpPort = 161;
    $cliPort = (int)($device['ports'] ?? 0);
    if ($cliPort < 1 || $cliPort > 65535) $cliPort = 23;

    return [
        'kind' => $kind,
        'row_id' => (int)($device['id'] ?? 0),
        'label' => $label,
        'device_type' => $deviceType,
        'title' => 'Edit ' . $label,
        'method' => $method,
        'is_api' => $isApi,
        'show_snmp_fields' => !$isApi && $method === 'snmp',
        'show_telnet_fields' => !$isApi && !empty($definition['telnet_cli']),
        'type' => $driverId,
        'driver_label' => (string)($definition['label'] ?? ($driverId !== '' ? $driverId : 'N/A')),
        'driver_options' => $options,
        'can_change_driver' => count($options) > 1,
        'model' => trim((string)($device['model'] ?? '')),
        'has_password' => trim((string)($device['password_enc'] ?? '')) !== '',
        'form' => [
            'device_name' => (string)($device['name'] ?? ''),
            'ip_address' => (string)($device['ip_address'] ?? ''),
            'type' => $driverId,
            'snmp_port' => (string)$snmpPort,
            'community' => (string)($device['snmp_community'] ?? ''),
            'hybrid_status' => (string)($device['hybrid_status'] ?? 0),
            'ports' => (string)$cliPort,
            'username' => (string)($device['username'] ?? ''),
        ],
    ];
}
