<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/format.php';

function olt_ports_with_stored_tx_power(array $ports, array $storedPorts): array
{
    $samples = [];
    foreach ($storedPorts as $stored) {
        foreach (['port_ref', 'pon'] as $field) {
            $ref = str_replace('_', '/', trim((string)($stored[$field] ?? '')));
            if ($ref !== '') $samples[$ref] = $stored;
        }
    }
    foreach ($ports as &$port) {
        $sample = null;
        foreach (['port_ref', 'pon'] as $field) {
            $ref = str_replace('_', '/', trim((string)($port[$field] ?? '')));
            if (isset($samples[$ref])) { $sample = $samples[$ref]; break; }
        }
        $olt_txPower = $sample['tx_power'] ?? null;
        $port['tx_power'] = is_numeric($olt_txPower) && is_finite((float)$olt_txPower) ? (float)$olt_txPower : null;
        $port['optics']['tx_power'] = $port['tx_power'];
        $port['tx_power_sampled'] = !empty($sample['tx_power_sampled']);
    }
    unset($port);
    return $ports;
}

function olt_ports_with_confirmed_snmp_status(array $webPorts, array $olt, array $snmpPorts = []): array
{
    if (!monitoring_row_uses_handler($olt, 'snmp_olt')) return $webPorts;

    if ($snmpPorts === []) {
        $snapshot = $olt['pon_snapshot'] ?? null;
        $snmpPorts = is_array($snapshot) ? $snapshot : [];
    }
    $snmpPorts = array_values(array_filter($snmpPorts, 'is_array'));
    $statuses = [];
    foreach ($snmpPorts as $port) {
        if (!is_array($port)) continue;
        $status = trim((string)($port['link_status'] ?? ''));
        if (!in_array($status, ['LinkUp', 'LinkDown'], true)) continue;
        foreach ([(string)($port['port_ref'] ?? ''), (string)($port['pon'] ?? '')] as $identity) {
            $identity = str_replace('/', '_', trim($identity));
            if ($identity !== '') $statuses[$identity] = $status;
        }
    }

    foreach ($webPorts as &$port) {
        if (!is_array($port)) continue;
        $confirmed = null;
        foreach ([(string)($port['port_ref'] ?? ''), (string)($port['pon'] ?? '')] as $identity) {
            $identity = str_replace('/', '_', trim($identity));
            if ($identity !== '' && isset($statuses[$identity])) {
                $confirmed = $statuses[$identity];
                break;
            }
        }
        $port['link_status'] = $confirmed ?? '';
        $port['link_status_source'] = $confirmed !== null ? 'snmp_confirmed' : 'unavailable';
    }
    unset($port);
    return $webPorts;
}

function olt_ports_with_stored_description(array $ports, array $storedPorts): array
{
    $stored = [];
    foreach ($storedPorts as $storedPort) {
        if (!is_array($storedPort)) continue;
        $description = trim((string)($storedPort['description'] ?? ''));
        if ($description === '') continue;
        foreach ([(string)($storedPort['port_ref'] ?? ''), (string)($storedPort['pon'] ?? '')] as $identity) {
            $identity = str_replace('/', '_', trim($identity));
            if ($identity !== '' && !isset($stored[$identity])) $stored[$identity] = $description;
        }
    }
    if ($stored === []) return $ports;

    foreach ($ports as &$port) {
        if (!is_array($port)) continue;
        foreach ([(string)($port['port_ref'] ?? ''), (string)($port['pon'] ?? '')] as $identity) {
            $identity = str_replace('/', '_', trim($identity));
            if ($identity !== '' && isset($stored[$identity])) {
                $port['description'] = $stored[$identity];
                break;
            }
        }
    }
    unset($port);

    return $ports;
}

function olt_pon_port_config_fields(): array
{
    return [
        'olt_port_enable' => ['label' => 'Status Port', 'options' => ['Enable', 'Disable']],
        'olt_port_autoneg' => ['label' => 'Auto Negotiation', 'options' => ['Enable', 'Disable']],
        'olt_port_speed' => ['label' => 'Kecepatan Port', 'options' => ['1000M', '100M']],
        'olt_port_duplex' => ['label' => 'Mode Duplex', 'options' => ['Full', 'Half']],
        'olt_port_flow_control' => ['label' => 'Flow Control', 'options' => ['Enable', 'Disable']],
    ];
}


function olt_runtime_text(array $system): string
{
    $runtime = trim((string)($system['runtime_clean'] ?? ''));
    if ($runtime !== '') return $runtime;

    $seconds = $system['runtime_seconds'] ?? null;
    if (!is_numeric($seconds)) return 'N/A';

    $seconds = max(0, (int)$seconds);
    $days = intdiv($seconds, 86400);
    $seconds %= 86400;
    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;
    $minutes = intdiv($seconds, 60);
    $seconds %= 60;

    return sprintf(
        '%dd %02d:%02d:%02d',
        $days,
        $hours,
        $minutes,
        $seconds
    );
}

function olt_native_log_text(array $log): string
{
    $name = trim((string)($log['olt_name'] ?? 'OLT')) ?: 'OLT';
    $line = trim((string)($log['message'] ?? ''));
    $line = str_replace(' | ', ' ', $line);
    $line = preg_replace('/\s+/u', ' ', $line) ?? $line;
    $time = trim((string)($log['log_time_text'] ?? ''));
    if ($time !== '' && mb_stripos($line, $time) === 0) {
        $line = trim(mb_substr($line, mb_strlen($time)));
        $line = preg_replace('/^[\s|:;\-–—]+/u', '', $line) ?? $line;
    }
    return trim($name . ' ' . $line);
}

function olt_view_optical_rows(array $module, mixed $txPower, bool $txSupported, bool $compact): array
{
    $optics = is_array($module['optics'] ?? null) ? $module['optics'] : [];
    $field = static function (string $key) use ($module, $optics): mixed {
        $value = $module[$key] ?? ($optics[$key] ?? null);
        if ($value === null) $value = $module[$key . '_raw'] ?? ($optics[$key . '_raw'] ?? null);
        return $value === '' ? null : $value;
    };
    $metric = static function (mixed $value, string $unit, int $decimals = 3): array {
        if ($value === null || $value === '' || (is_string($value) && in_array(strtolower(trim($value)), ['na', 'n/a', '_'], true))) {
            return ['value' => '—', 'class' => 'mono'];
        }
        if (!is_numeric($value) || !is_finite((float)$value)) return ['value' => (string)$value, 'class' => 'mono'];
        $formatted = number_format((float)$value, $decimals, '.', '');
        if ($decimals > 0) $formatted = rtrim(rtrim($formatted, '0'), '.');
        return ['value' => $formatted . ' ' . $unit, 'class' => 'mono'];
    };
    $text = static function (mixed $value): array {
        $value = trim((string)($value ?? ''));
        return [
            'value' => $value === '' || in_array(strtolower($value), ['na', 'n/a', '_'], true) ? '—' : $value,
            'class' => 'mono',
        ];
    };
    $unsupported = ['value' => 'N/A', 'class' => 'text-muted'];
    $rows = $compact ? [
        'Temperature' => $metric($field('temperature'), '°C'),
        'TX Power' => $txSupported ? $metric($txPower, 'dBm') : $unsupported,
        'Supply Voltage' => $metric($field('voltage'), 'V'),
        'Current' => $txSupported ? $metric($field('current'), 'mA') : $unsupported,
    ] : [
        'Vendor Name' => $text($field('vendor')),
        'Vendor PN' => $text($field('vendor_pn')),
        'Vendor SN' => $text($field('vendor_sn')),
        'Class' => $text($field('class')),
        'Module Type' => $text($field('module_type')),
        'Connector' => $text($field('connector')),
        'Temperature' => $metric($field('temperature'), '°C'),
        'Supply Voltage' => $metric($field('voltage'), 'V'),
        'TX Power' => $txSupported ? $metric($txPower, 'dBm') : $unsupported,
        'RX Power' => $metric($field('rx_power'), 'dBm'),
        'TX Bias Current' => $txSupported ? $metric($field('current'), 'mA') : $unsupported,
        'Wavelength' => $metric($field('wavelength'), 'NM', 0),
        'Trans Distance' => $text($field('trans_distance')),
    ];
    $result = [];
    foreach ($rows as $label => $row) {
        $result[] = ['label' => $label, 'value' => $row['value'], 'class' => $row['class']];
    }
    return $result;
}

function olt_view_controller_data(string $family, array $context): array
{
    $family = in_array($family, ['zte', 'external'], true) ? $family : 'hioso';
    $oltDetail = is_array($context['oltDetail'] ?? null) ? $context['oltDetail'] : [];
    $oltSystem = is_array($context['oltSystem'] ?? null) ? $context['oltSystem'] : [];
    $oltCards = is_array($context['oltCards'] ?? null) ? $context['oltCards'] : [];
    $oltPonSnapshot = is_array($context['oltPonSnapshot'] ?? null) ? $context['oltPonSnapshot'] : [];
    $oltPonModules = is_array($context['oltPonModules'] ?? null) ? $context['oltPonModules'] : [];
    $oltPonOpticalSnapshot = is_array($context['oltPonOpticalSnapshot'] ?? null) ? $context['oltPonOpticalSnapshot'] : [];
    $oltOnuStats = is_array($context['oltOnuStats'] ?? null) ? $context['oltOnuStats'] : [];
    $oltProfileSnapshots = is_array($context['oltProfileSnapshots'] ?? null) ? $context['oltProfileSnapshots'] : [];
    $oltPonTraffic = is_array($context['oltPonTraffic'] ?? null) ? $context['oltPonTraffic'] : [];
    $oltPonTrafficHistory = is_array($context['oltPonTrafficHistory'] ?? null) ? $context['oltPonTrafficHistory'] : [];
    $oltTrafficRange = (int)($context['oltTrafficRange'] ?? 180);
    $oltPonFocusNumber = $context['oltPonFocusNumber'] ?? null;
    $oltViewDefinition = is_array($context['oltViewDefinition'] ?? null) ? $context['oltViewDefinition'] : [];
    $oltViewCapabilities = is_array($context['oltViewCapabilities'] ?? null) ? $context['oltViewCapabilities'] : [];
    $oltUplinkPorts = is_array($context['oltUplinkPorts'] ?? null) ? $context['oltUplinkPorts'] : [];
    $oltTrafficSelected = is_array($context['oltTrafficSelected'] ?? null) ? $context['oltTrafficSelected'] : [];
    $oltOpticSourceSupported = (bool)($context['oltOpticSourceSupported'] ?? false);

    $oltViewFamily = $family;
    $oltRefreshAction = 'refresh_olt_view';
    $oltRefreshAsync = $family !== 'external';
    $oltLive = (int)($oltDetail['hybrid_status'] ?? 0) === 1;

    $oltText = static fn($value): string => OltDetailViewModel::text($value);
    $oltNumber = static fn($value, string $unit = ''): string => OltDetailViewModel::number($value, $unit);
    $oltTemperatureText = static fn($value): string => OltDetailViewModel::temperatureText($value);
    $oltPonHealthFormatter = static function ($value, string $unit) use ($oltNumber, $oltTemperatureText): string {
        return $unit === ' °C' ? $oltTemperatureText($value) : $oltNumber($value, $unit);
    };
    $oltSystemHealth = [
        'temperature' => is_numeric($oltSystem['temperature'] ?? null) ? (float)$oltSystem['temperature'] : 0.0,
        'cpu_percent' => is_numeric($oltSystem['cpu_percent'] ?? null) ? (float)$oltSystem['cpu_percent'] : null,
        'memory_percent' => is_numeric($oltSystem['memory_percent'] ?? null) ? (float)$oltSystem['memory_percent'] : null,
    ];
    $oltRef = static function (string $ref): string {
        $ref = preg_replace('~^(?:gpon|epon)(?:-olt)?[_/]?~i', '', trim($ref));
        return str_replace('_', '/', $ref);
    };
    $oltSlot = static function (string $ref) use ($oltRef): int {
        return preg_match('~^\d+/(\d+)/\d+$~', $oltRef($ref), $match) ? (int)$match[1] : 0;
    };

    if ($family === 'hioso' && $oltUplinkPorts !== []) {
        $oltMappedPorts = [];
        foreach ($oltUplinkPorts as $oltNetPort) {
            if (!is_array($oltNetPort)) continue;
            $oltMappedPorts[] = [
                'name' => (string)($oltNetPort['port_ref'] ?? ''),
                'status' => (string)($oltNetPort['link_status'] ?? ''),
                'enable' => (string)($oltNetPort['enable'] ?? ''),
                'speed_mbps' => (string)($oltNetPort['speed_mbps'] ?? ''),
                'port_number' => (int)($oltNetPort['port_number'] ?? 0),
            ];
        }
        if ($oltMappedPorts !== []) {
            $oltCards[] = [
                'slot_number' => 0,
                'role' => 'uplink',
                'model' => 'Network',
                'label' => 'Port System',
                'name' => 'Port System',
                'ports_json' => json_encode($oltMappedPorts, JSON_UNESCAPED_SLASHES),
                'port_count' => count($oltMappedPorts),
                'is_present' => 1,
            ];
        }
    }

    $oltPonCards = array_values(array_filter((array)$oltCards, 'is_array'));
    $oltPonViewModel = OltDetailViewModel::ponGroups($oltPonCards, $oltPonSnapshot, $oltPonModules, $oltPonOpticalSnapshot);
    $oltGroups = $oltPonViewModel['groups'];
    $oltPonGroups = $oltGroups;
    $oltTx = $oltPonViewModel['tx'];
    $oltPonOpticalRows = [];
    if ($family === 'zte' || $family === 'hioso') {
        foreach ($oltPonGroups as $oltPonGroup) {
            foreach ((array)($oltPonGroup['ports'] ?? []) as $oltPonRef => $oltPonPort) {
                if (!is_array($oltPonPort)) continue;
                $oltPonTxPower = is_numeric($oltPonPort['tx_power'] ?? null)
                    ? (float)$oltPonPort['tx_power']
                    : (is_numeric($oltTx[$oltPonRef] ?? null) ? (float)$oltTx[$oltPonRef] : null);
                $oltPonOpticalRows[$oltPonRef] = olt_view_optical_rows(
                    $oltPonPort,
                    $oltPonTxPower,
                    $family === 'zte' || $oltOpticSourceSupported,
                    $family === 'hioso'
                );
            }
        }
    }
    $oltIdentityModel = OltDetailViewModel::identity($oltDetail, $oltSystem);
    $oltStatus = $oltIdentityModel['status'];
    $oltStatusLabel = $oltIdentityModel['status_label'];
    $oltUptime = $oltIdentityModel['uptime'];
    $oltModel = display_model_chars($oltIdentityModel['model']);
    $oltStats = OltDetailViewModel::stats($oltPonCards, $oltSystem, $oltOnuStats);
    if ($family === 'hioso') {
        unset($oltStats['Card Uplink'], $oltStats['Card PON'], $oltStats['Fan']);
    }
    if (!monitoring_device_is_zte($oltDetail)) unset($oltStats['DyingGasp']);

    $oltFanRows = array_values(array_filter((array)($oltSystem['fans'] ?? []), 'is_array'));
    $oltPonTrafficRows = $oltPonTraffic;
    $oltFormatBps = static fn(mixed $bps): string => format_bps($bps);
    $oltTrafficHistory = $oltPonTrafficHistory;
    $oltTrafficAverages = is_array($oltTrafficHistory['average'] ?? null) ? $oltTrafficHistory['average'] : [];
    $oltTrafficActiveRange = $oltTrafficRange;
    $oltTrafficViewModel = OltDetailViewModel::trafficRows($oltGroups, $oltPonTrafficRows, $oltTrafficSelected);
    $oltTrafficRows = $oltTrafficViewModel['rows'];
    $oltTrafficGraphRows = $oltTrafficViewModel['graph_rows'];

    $oltProfileViewModel = OltDetailViewModel::profiles($oltProfileSnapshots);
    $oltProfileGroups = $oltProfileViewModel['groups'];
    $oltProfileSeenAt = $oltProfileViewModel['seen_at'];
    $oltRowId = (int)($oltDetail['id'] ?? 0);

    $oltTabs = [
        'pon' => ['Cards', ' bi-pc-horizontal'],
        'traffic' => ['Trafik', 'bi-graph-up-arrow'],
        'vlans' => ['VLANs', 'bi-diagram-3'],
        'onu-types' => ['ONU Type', 'bi-router'],
        'wan' => ['WAN-IP Profil', 'bi-globe'],
        'speed' => ['Speed Profil', 'bi-speedometer2'],
        'system' => ['System', 'bi-cpu'],
    ];

    $oltUplinkBySlot = [];
    $oltUplinkState = [];
    $oltVlanPorts = static fn(array $refs): string => '';
    $oltUplinkPortDetails = [];
    if ($family === 'zte') {
        foreach ($oltUplinkPorts as $oltUplinkRow) {
            if (!is_array($oltUplinkRow)) continue;
            $oltUplinkSlot = (int)($oltUplinkRow['slot'] ?? 0);
            $oltUplinkBySlot[$oltUplinkSlot][] = $oltUplinkRow + [
                'name' => (string)($oltUplinkRow['port_ref'] ?? ''),
                'status' => (string)($oltUplinkRow['link_status'] ?? ''),
            ];
        }
        foreach ($oltUplinkPorts as $oltUplinkRow) {
            if (!is_array($oltUplinkRow)) continue;
            $oltUplinkRef = trim((string)($oltUplinkRow['port_ref'] ?? ''));
            if ($oltUplinkRef === '') continue;
            $oltUplinkOper = strtolower(trim((string)($oltUplinkRow['oper_state'] ?? '')));
            if (!in_array($oltUplinkOper, ['online', 'offline', 'down'], true)) {
                $oltUplinkOper = strcasecmp(trim((string)($oltUplinkRow['link_status'] ?? '')), 'LinkUp') === 0 ? 'online' : 'down';
            }
            $oltUplinkState[$oltUplinkRef] = $oltUplinkOper;
        }
        $oltVlanPorts = static function (array $refs) use ($oltUplinkState): string {
            $html = '';
            foreach ($refs as $ref) {
                $ref = trim((string)$ref);
                if ($ref === '') continue;
                $state = $oltUplinkState[$ref] ?? 'unknown';
                $html .= '<span class="olt-vlan-chip is-' . $state . '">' . h($ref) . '</span>';
            }
            return $html;
        };
        foreach ($oltUplinkPorts as $oltUplinkPortRow) {
            if (!is_array($oltUplinkPortRow)) continue;
            $oltUplinkPortRef = trim((string)($oltUplinkPortRow['port_ref'] ?? ''));
            if ($oltUplinkPortRef === '') continue;
            $oltUplinkPortDetails[$oltUplinkPortRef] = [
                'config' => is_array($oltUplinkPortRow['config'] ?? null) ? $oltUplinkPortRow['config'] : [],
                'optics' => is_array($oltUplinkPortRow['optics'] ?? null) ? $oltUplinkPortRow['optics'] : [],
                'vlan' => is_array($oltUplinkPortRow['vlan'] ?? null) ? $oltUplinkPortRow['vlan'] : [],
                'graph_enabled' => isset($oltTrafficSelected[$oltUplinkPortRef]),
            ];
        }
    }

    return compact(
        'oltViewFamily',
        'oltDetail',
        'oltSystem',
        'oltViewDefinition',
        'oltViewCapabilities',
        'oltUplinkPorts',
        'oltUplinkBySlot',
        'oltUplinkState',
        'oltVlanPorts',
        'oltUplinkPortDetails',
        'oltText',
        'oltNumber',
        'oltTemperatureText',
        'oltPonHealthFormatter',
        'oltSystemHealth',
        'oltRef',
        'oltSlot',
        'oltPonCards',
        'oltGroups',
        'oltPonGroups',
        'oltTx',
        'oltPonOpticalRows',
        'oltIdentityModel',
        'oltStatus',
        'oltStatusLabel',
        'oltUptime',
        'oltModel',
        'oltStats',
        'oltFanRows',
        'oltPonTrafficRows',
        'oltFormatBps',
        'oltTrafficHistory',
        'oltTrafficAverages',
        'oltTrafficActiveRange',
        'oltTrafficSelected',
        'oltTrafficRows',
        'oltTrafficGraphRows',
        'oltRefreshAction',
        'oltRefreshAsync',
        'oltProfileViewModel',
        'oltProfileGroups',
        'oltProfileSeenAt',
        'oltRowId',
        'oltTabs',
        'oltLive',
        'oltPonFocusNumber'
    );
}
