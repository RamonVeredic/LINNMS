<?php

declare(strict_types=1);

require_once LINNMS_ROOT . '/vendor/driver/Controller.php';

final class OltCapabilities
{
    public static function compute(array $definition, bool $driverAllowed, array $device = []): array
    {
        $driverClass = trim((string)($definition['driver_class'] ?? ''));
        $terminalClass = trim((string)($definition['cli_terminal_class'] ?? ''));
        $driverSupports = static fn(string $interface): bool => $driverClass !== ''
            && class_exists($driverClass)
            && is_subclass_of($driverClass, $interface);
        $terminalSupports = static fn(string $interface): bool => $terminalClass !== ''
            && class_exists($terminalClass)
            && is_subclass_of($terminalClass, $interface);
        $isSnmpOlt = ($definition['handler'] ?? '') === 'snmp_olt' && $driverSupports(OltSnmpDriver::class);
        $cliConfigured = (int)($device['hybrid_status'] ?? 0) === 1
            && !empty($definition['telnet_cli'])
            && trim((string)($device['username'] ?? '')) !== ''
            && trim((string)($device['password_enc'] ?? '')) !== '';

        $supportedOnuActions = $driverAllowed && $isSnmpOlt
            ? (new \App\Driver\Controller())->supportedOnuActions((string)($definition['driver_id'] ?? ''))
            : [];
        $onuActions = [];
        foreach ([
            'rebootOp' => 'Reboot',
            'restoreOp' => 'Restore',
            'activeOp' => 'Enable',
            'noactiveOp' => 'Disable',
            'deleteOp' => 'Hapus ONU',
        ] as $operation => $label) {
            $onuActions[] = [
                'operation' => $operation,
                'label' => $label,
                'supported' => in_array($operation, $supportedOnuActions, true)
                    && ($operation !== 'deleteOp' || $cliConfigured),
                'danger' => $operation === 'deleteOp',
            ];
        }

        return [
            'local_vlan' => true,
            'local_onu_type' => true,
            'refresh_detail' => $driverAllowed && $isSnmpOlt,
            'terminal' => $driverAllowed && $cliConfigured && $terminalSupports(OltTerminalCliClient::class),
            'pon_status' => $driverAllowed && $isSnmpOlt,
            'cards' => $driverAllowed && $driverSupports(OltCardAwareDriver::class),
            'uplink_read' => $driverAllowed && $driverSupports(OltUplinkAware::class),
            'uplink_config' => $driverAllowed && $driverSupports(OltUplinkConfigAware::class),
            'pon_config' => $driverAllowed && $driverSupports(OltPonByRefAware::class),
            'pon_modules' => $driverAllowed && ($driverSupports(OltPonModuleAware::class)
                || (($definition['pon_module_source'] ?? '') === 'cli' && $terminalSupports(OltPonModuleCliAware::class))),
            'pon_traffic_live' => $driverAllowed && ($driverSupports(OltPonTrafficAware::class)
                || (($definition['pon_traffic_source'] ?? '') === 'cli' && $terminalSupports(OltPonCliTrafficAware::class))),
            'vlan_device' => $driverAllowed && $driverSupports(OltVlanAware::class),
            'vlan_device_supported' => $driverSupports(OltVlanAware::class),
            'vlan_port_config' => $driverAllowed && $driverSupports(OltVlanPortAware::class),
            'vlan_port_config_supported' => $driverSupports(OltVlanPortAware::class),
            'onu_type_device_supported' => $driverSupports(OltOnuTypeAware::class),
            'onu_type_device' => $driverAllowed && $driverSupports(OltOnuTypeAware::class)
                && ($driverClass::WRITE_CONNECTION !== 'cli' || $cliConfigured),
            'profile_device' => $driverAllowed && $driverSupports(OltProfileAware::class),
            'profile_edit' => true,
            'fan' => $driverAllowed && $driverSupports(OltFanAware::class),
            'device_time' => $driverAllowed && $driverSupports(OltDeviceTimeAware::class),
            'onu_ethernet' => $driverAllowed && $driverSupports(OltOnuEthernetAware::class),
            'onu_port_count_includes_optical' => !empty($definition['onu_port_count_includes_optical']),
            'onu_actions' => $onuActions,
            'onu_traffic' => $driverAllowed && $driverSupports(OltOnuTrafficAware::class),
            'onu_service' => $driverAllowed && $driverSupports(OltOnuServiceAware::class),
            'onu_services_preview' => $driverAllowed && ($driverSupports(OltOnuServiceAware::class)
                || ($cliConfigured && $driverClass !== '' && class_exists($driverClass)
                    && (method_exists($driverClass, 'setOnuTr069')
                        || method_exists($driverClass, 'setOnuRemoteAccess')
                        || method_exists($driverClass, 'setOnuVeip')
                        || method_exists($driverClass, 'setOnuWifiConfig')
                        || method_exists($driverClass, 'setOnuWanIp')))),
            'onu_tr069' => $driverAllowed && $cliConfigured && $driverClass !== ''
                && class_exists($driverClass) && method_exists($driverClass, 'setOnuTr069'),
            'onu_remote_access' => $driverAllowed && $cliConfigured && $driverClass !== ''
                && class_exists($driverClass) && method_exists($driverClass, 'setOnuRemoteAccess'),
            'onu_veip' => $driverAllowed && $cliConfigured && $driverClass !== ''
                && class_exists($driverClass) && method_exists($driverClass, 'setOnuVeip'),
            'onu_wifi' => $driverAllowed && $cliConfigured && $driverClass !== ''
                && class_exists($driverClass) && method_exists($driverClass, 'setOnuWifiConfig'),
            'onu_wan_ip' => $driverAllowed && $cliConfigured && $driverClass !== ''
                && class_exists($driverClass) && method_exists($driverClass, 'setOnuWanIp'),
        ];
    }
}
