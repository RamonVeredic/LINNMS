<?php

declare(strict_types=1);

function olt_type_options(): array
{
    return [
        'zteGpon' => 'ZTE Cxxx',
        'zte6xxGpon' => 'ZTE 6xx GPON',
        'zteEpon' => 'ZTE EPON',
        'hsgqGpon' => 'HSGQ GPON',
        'hsgqEpon' => 'HSGQ EPON',
        'vsolGpon' => 'VSOL GPON',
        'vsolEpon' => 'VSOL EPON',
        'cdataGpon' => 'C-Data GPON',
        'cdataEpon' => 'C-Data EPON',
        'fiberhomeGpon' => 'FiberHome GPON',
    ];
}

function selectable_api_olt_type_options(): array
{
    $options = olt_type_options();
    unset($options['zteGpon']);
    return $options;
}

function monitoring_driver_catalog(): array
{
    static $catalog = null;
    if (is_array($catalog)) return $catalog;
    $requestedCatalog = $GLOBALS['linnms_driver_catalog'] ?? null;
    if (defined('LINNMS_PUBLIC_REQUEST')) {
        if (!is_array($requestedCatalog)) throw new RuntimeException('Katalog perangkat dari API belum tersedia.');
        return $catalog = $requestedCatalog;
    }
    $path = dirname(LINNMS_ROOT) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'monitoring-drivers.php';
    $loaded = require $path;
    if (!is_array($loaded)) throw new RuntimeException('Registry driver pemantauan tidak valid.');
    foreach ($loaded as $driverKey => $driverDef) {
        if (!is_array($driverDef)) throw new RuntimeException('Definisi driver tidak valid: ' . (string)$driverKey);
        if (($driverDef['handler'] ?? '') === 'snmp_olt') {
            $driverClass = trim((string)($driverDef['driver_class'] ?? ''));
            if ($driverClass === '' || !class_exists($driverClass) || !is_subclass_of($driverClass, 'OltSnmpDriver')) {
                throw new RuntimeException('Implementasi driver OLT tidak memenuhi OltSnmpDriver: ' . (string)$driverKey);
            }
            if (!empty($driverDef['telnet_cli'])) {
                $terminalClass = trim((string)($driverDef['cli_terminal_class'] ?? ''));
                if ($terminalClass === '' || !class_exists($terminalClass)
                    || !is_subclass_of($terminalClass, OltTerminalCliClient::class)) {
                    throw new RuntimeException('Klien terminal driver tidak memenuhi OltTerminalCliClient: ' . (string)$driverKey);
                }
            }
            if (($driverDef['pon_traffic_source'] ?? '') === 'cli') {
                $trafficClientClass = trim((string)($driverDef['cli_terminal_class'] ?? ''));
                if ($trafficClientClass === '' || !class_exists($trafficClientClass)
                    || !is_subclass_of($trafficClientClass, OltPonCliTrafficAware::class)) {
                    throw new RuntimeException('Klien CLI tidak memenuhi OltPonCliTrafficAware: ' . (string)$driverKey);
                }
            }
            if (($driverDef['pon_module_source'] ?? '') === 'cli') {
                $moduleClientClass = trim((string)($driverDef['cli_terminal_class'] ?? ''));
                if ($moduleClientClass === '' || !class_exists($moduleClientClass)
                    || !is_subclass_of($moduleClientClass, OltPonModuleCliAware::class)) {
                    throw new RuntimeException('Klien CLI tidak memenuhi OltPonModuleCliAware: ' . (string)$driverKey);
                }
            }
            $registrationFields = $driverDef['onu_registration_ui'] ?? [];
            if (!is_array($registrationFields)
                || array_diff($registrationFields, ['onu_id', 'onu_type', 'vlan', 'wan', 'veip', 'tr069', 'batch_provision']) !== []) {
                throw new RuntimeException('Capability registrasi ONU tidak valid: ' . (string)$driverKey);
            }
        }
    }
    uasort($loaded, static fn(array $a, array $b): int => (int)($a['scan_order'] ?? 0) <=> (int)($b['scan_order'] ?? 0));
    return $catalog = $loaded;
}

function monitoring_driver_definition(string $driverId): ?array
{
    $definition = monitoring_driver_catalog()[$driverId] ?? null;
    return is_array($definition) ? ['driver_id'=>$driverId] + $definition : null;
}

function onu_registration_capabilities_for_driver(string $driverId): array
{
    // Tanpa deklarasi pada driver, hanya identitas dasar ONU yang ditampilkan.
    // batch_provision tetap diverifikasi lewat OltOnuProvisionAware.
    $allowed = ['onu_id', 'onu_type', 'vlan', 'wan', 'veip', 'tr069', 'batch_provision'];
    $definition = monitoring_driver_definition($driverId);
    $declared = is_array($definition['onu_registration_ui'] ?? null)
        ? $definition['onu_registration_ui'] : [];
    return array_fill_keys(array_values(array_intersect($allowed, $declared)), true);
}

function olt_driver_catalog(): array
{
    return array_filter(monitoring_driver_catalog(), static fn(array $definition): bool =>
        ($definition['group'] ?? '') === 'olt' && !empty($definition['selectable']));
}

function olt_driver_definition(string $driverId): ?array
{
    $definition = monitoring_driver_definition($driverId);
    return is_array($definition) && ($definition['group'] ?? '') === 'olt' && !empty($definition['selectable']) ? $definition : null;
}

function olt_driver_support_setting_key(string $driverId): string
{
    $safe = preg_replace('/[^A-Za-z0-9_]/', '_', $driverId) ?? '';
    return 'olt_support_driver_' . $safe;
}

function olt_driver_support_enabled(array $flags, string $driverId): bool
{
    
    if (!device_access_allows($driverId)) return false;
    $key = olt_driver_support_setting_key($driverId);
    if (!array_key_exists($key, $flags)) return true;
    return (string)$flags[$key] !== '0';
}

function snmp_driver_catalog(): array
{
    return array_filter(monitoring_driver_catalog(), static fn(array $definition): bool =>
        in_array((string)($definition['group'] ?? ''), ['router','switch'], true) && !empty($definition['selectable']));
}

function device_driver_type(array $device): string
{
    if (($device['source'] ?? '') === 'api') return 'external_olt_api';
    return trim((string)($device['type'] ?? ''));
}

function monitoring_device_is_zte(array $device): bool
{
    if (str_contains(strtolower(trim((string)($device['type'] ?? ''))), 'zte')) return true;
    $definition = monitoring_driver_definition(device_driver_type($device));
    return is_array($definition) && strtolower((string)($definition['ui_family'] ?? '')) === 'ztefamily';
}

function olt_monitoring_driver(array $olt): string
{
    $driverId = device_driver_type($olt);
    $definition = monitoring_driver_definition($driverId);
    return is_array($definition) && ($definition['group'] ?? '') === 'olt' ? $driverId : '';
}

function snmp_monitoring_driver(array $device): string
{
    $driverId = device_driver_type($device);
    $definition = monitoring_driver_definition($driverId);
    return is_array($definition) && in_array((string)($definition['group'] ?? ''), ['router','switch'], true) ? $driverId : '';
}

function snmp_driver_support_setting_key(string $driverId): string
{
    $safe = preg_replace('/[^A-Za-z0-9_]/', '_', $driverId) ?? '';
    return 'snmp_support_driver_' . $safe;
}

function snmp_driver_support_enabled(array $flags, string $driverId): bool
{
    
    if (!device_access_allows($driverId)) return false;
    $key = snmp_driver_support_setting_key($driverId);
    if (!array_key_exists($key, $flags)) return true;
    return (string)$flags[$key] !== '0';
}






function zte_service_name_for_vlan(int $vlan): string
{
    return 'VLAN' . str_pad((string)max(0, $vlan), 4, '0', STR_PAD_LEFT);
}


function olt_family_file_prefix(string $family): string
{
    static $map = ['ztefamily' => 'zte', 'hiosofamily' => 'hioso', 'external' => 'external'];
    $family = trim($family);
    if (isset($map[$family])) return $map[$family];
    $stripped = (string)preg_replace('/family$/i', '', $family);
    return $stripped !== '' ? $stripped : 'external';
}


function olt_service_options_from_snapshots($db, int $userId, array $groups, array $base = []): array
{
    $found = [];
    foreach ($groups as $group) {
        if (!is_array($group)) continue;
        $deviceId = (int)($group['olt']['id'] ?? 0);
        if ($deviceId < 1 || !is_object($db) || !method_exists($db, 'getOltProfileSnapshots')) continue;
        foreach ((array)$db->getOltProfileSnapshots($userId, $deviceId) as $profile) {
            if (!is_array($profile)) continue;
            $kind = (string)($profile['kind'] ?? '');
            $name = trim((string)($profile['name'] ?? ''));
            if ($name === '') continue;
            if ($kind === 'tcont') $found['tcont_profiles'][] = $name;
            elseif ($kind === 'traffic') $found['traffic_profiles'][] = $name;

        }
        $typeRows = method_exists($db, 'getOltOnuTypes') ? $db->getOltOnuTypes($userId, $deviceId) : [];
        foreach ($typeRows as $typeRow) {
            $typeName = trim((string)($typeRow['name'] ?? ''));
            if ($typeName !== '') $found['onu_types'][] = $typeName;
        }
        if (method_exists($db, 'getOltVlanIds')) {
            foreach ((array)$db->getOltVlanIds($userId, $deviceId) as $id) {
                $id = (int)$id;
                if ($id >= 1 && $id <= 4095) $found['vlans'][] = $id;
            }
        }
    }
    $base['onu_types'] = [];
    foreach ($found as $key => $values) {
        $values = array_values(array_unique((array)$values));
        if ($values !== []) $base[$key] = $values;
    }
    return $base;
}


function olt_pon_interface_label(array $ponRow, array $oltRow = [], string $driverId = ''): string
{
    
    $reported = trim((string)($ponRow['interface'] ?? $ponRow['interface_name'] ?? $ponRow['if_name'] ?? ''));
    if ($reported === '' && is_array($ponRow['payload'] ?? null)) {
        $reported = trim((string)($ponRow['payload']['interface'] ?? $ponRow['payload']['interface_name'] ?? ''));
    }
    if ($reported !== '') return $reported;

    
    $prefix = trim((string)($oltRow['pon_interface_prefix'] ?? ''));
    if ($prefix === '') {
        $driverId = trim($driverId) !== '' ? trim($driverId) : trim((string)device_driver_type($oltRow));
        $definition = monitoring_driver_definition($driverId);
        $prefix = is_array($definition) ? trim((string)($definition['pon_interface_prefix'] ?? '')) : '';
    }

    $ref = trim((string)($ponRow['label'] ?? $ponRow['pon'] ?? $ponRow['port_ref'] ?? ''));
    if ($ref === '') return '';
    return $prefix !== '' ? $prefix . '_' . $ref : $ref;
}

function olt_page_family(string $driverId): string
{
    $definition = monitoring_driver_definition(trim($driverId));
    $family = is_array($definition) ? trim((string)($definition['ui_family'] ?? '')) : '';
    return preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $family) === 1 ? $family : 'external';
}
