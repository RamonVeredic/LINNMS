<?php

declare(strict_types=1);


function device_sync_summary(
    array $olts,
    array $settings
): array {
    $methods = [];

    foreach ($olts as $olt) {
        $definition=monitoring_driver_definition((string)device_driver_type($olt));
        if (is_array($definition)) $methods[(string)($definition['method']??'')]=true;
    }

    $apiAt = trim((string)(
        $settings['last_api_sync_at'] ??
        $settings['last_sync_at'] ??
        ''
    ));
    if (count($methods)>1) {
        return [
            'label' => 'Waktu Sinkron',
            'at' => trim((string)($settings['last_sync_at']??''))?:null,
            'mode' => 'mixed',
        ];
    }

    if (isset($methods['snmp'])) {
        return [
            'label' => 'Waktu Sinkron SNMP',
            'at' => trim((string)($settings['last_sync_at']??''))?:null,
            'mode' => 'snmp',
        ];
    }

    if (isset($methods['api'])) {
        return [
            'label' => 'Waktu Sinkron API',
            'at' => $apiAt !== '' ? $apiAt : null,
            'mode' => 'api',
        ];
    }

    return [
        'label' => 'Waktu Sinkron',
        'at' => null,
        'mode' => 'none',
    ];
}

function dashboard_device_table_rows(array $olts, array $mikrotik, array $switch, array $scan): array
{
    $rows = [];
    $number = 0;
    $active = array_fill_keys(array_map('intval', (array)($scan['active_olt_row_ids'] ?? [])), true);
    $statuses = is_array($scan['latest_statuses'] ?? null) ? $scan['latest_statuses'] : [];

    foreach ($olts as $olt) {
        $number++;
        $rows[] = dashboard_olt_row($olt, $number, $active, $statuses);
    }

    foreach ([
        ['rows' => $mikrotik, 'label' => 'MikroTik', 'page' => 'view-mikrotik'],
        ['rows' => $switch, 'label' => 'Switch', 'page' => 'view-switch'],
    ] as $group) {
        foreach ($group['rows'] as $device) {
            $number++;
            $rows[] = dashboard_snmp_row($device, $number, $group);
        }
    }

    return $rows;
}

function dashboard_olt_row(array $olt, int $number, array $active, array $statuses): array
{
    $id = (int)($olt['id'] ?? 0);
    $oltId = (int)($olt['olt_id'] ?? 0);
    $activeScan = isset($active[$id]);
    $scanRow = is_array($statuses[(string)$id] ?? null) ? $statuses[(string)$id] : [];
    $scanStatus = (string)($scanRow['status'] ?? '');
    $scanError = (string)($scanRow['last_error'] ?? '');
    $hasSynced = trim((string)($olt['seen_at'] ?? '')) !== '';
    $failed = !$activeScan && $scanStatus === 'failed';
    $scanProgress = max(0, min(100, (int)($scanRow['progress'] ?? 0)));
    $scanPhase = trim((string)($scanRow['phase'] ?? ''));
    $pending = $activeScan || (!$failed && !$hasSynced && in_array((string)($olt['driver_status'] ?? ''), ['', 'Belum Sync'], true));
    $isApi = !empty($olt['is_api_driver']);
    $isSnmp = !empty($olt['is_snmp_driver']);

    $scanAction = null;
    if ($isApi) {
        $scanAction = [
            'action' => 'scan_olt',
            'field' => 'olt_id',
            'value' => $oltId,
            'disabled' => false,
            'discovery' => false,
            'title' => 'Pindai OLT',
        ];
    } elseif ($isSnmp) {
        $scanAction = [
            'action' => 'scan_snmp_olt',
            'field' => 'olt_row_id',
            'value' => $id,
            'disabled' => $activeScan,
            'discovery' => true,
            'title' => 'Jalankan Fast, Middle, dan Low Sync',
        ];
    }

    $connections = [];
    if ($isApi) {
        $connections[] = dashboard_chip('success', 'API');
    } elseif ($isSnmp) {
        $chip = dashboard_olt_chip((string)($olt['driver_status'] ?? ''), (int)($olt['snmp_fail_count'] ?? 0));
        $connections[] = dashboard_chip($chip, 'SNMP', 'Status keterjangkauan fast sync SNMP');
        if ((int)($olt['hybrid_status'] ?? 0) === 1) {
            $cliChip = dashboard_cli_chip((string)($olt['cli_status'] ?? ''), (int)($olt['cli_fail_count'] ?? 0));
            $connections[] = dashboard_chip($cliChip, 'Telnet', 'Status sesi Telnet; bukan status keterjangkauan OLT via SNMP');
        }
    }

    return [
        'number' => $number,
        'id' => $id,
        'type_label' => 'OLT',
        'type_count' => (int)($olt['total_onus'] ?? 0) . ' ONU',
        'name' => dashboard_device_text($olt['name'] ?? null),
        'address' => dashboard_device_address($olt['ip_address'] ?? null, (int)($olt['snmp_port'] ?? 0)),
        'model' => display_model_chars($olt['model'] ?? ''),
        'system_name' => trim((string)($olt['system_name'] ?? '')),
        'row_url' => public_page_url('view-olt', ['id' => $id]),
        'sync' => dashboard_sync_state($hasSynced, $pending, $failed, $activeScan, (string)($olt['seen_at'] ?? ''), $scanError, $scanProgress, $scanPhase),
        'connections' => $connections,
        'scan' => $scanAction,
        'edit_url' => public_page_url('dashboard', ['edit_device' => $id]),
        'edit_title' => 'Edit OLT',
        'edit_modal' => true,
        'delete' => dashboard_device_delete_action($id),
    ];
}

function dashboard_snmp_row(array $device, int $number, array $group): array
{
    $id = (int)($device['id'] ?? 0);
    $hasSynced = trim((string)($device['seen_at'] ?? '')) !== '';
    $pending = !$hasSynced && in_array((string)($device['driver_status'] ?? ''), ['', 'Belum Sync'], true);
    $active = !empty($device['is_active']);
    $connections = [
        dashboard_chip($active ? 'success' : 'danger', 'SNMP'),
    ];

    return [
        'number' => $number,
        'id' => $id,
        'type_label' => (string)$group['label'],
        'type_count' => (int)($device['interface_count'] ?? 0) . ' Interface',
        'name' => dashboard_device_text($device['name'] ?? null),
        'address' => dashboard_device_address($device['ip_address'] ?? null, (int)($device['snmp_port'] ?? 0)),
        'model' => display_model_chars($device['model'] ?? ''),
        'system_name' => trim((string)($device['system_name'] ?? '')),
        'row_url' => public_page_url((string)$group['page'], ['id' => $id]),
        'sync' => dashboard_sync_state($hasSynced, $pending, false, false, (string)($device['seen_at'] ?? ''), ''),
        'connections' => $connections,
        'scan' => null,
        'edit_url' => public_page_url('dashboard', ['edit_device' => $id]),
        'edit_title' => 'Edit ' . (string)($group['label'] ?? 'Perangkat'),
        'edit_modal' => true,
        'delete' => dashboard_device_delete_action($id),
    ];
}

function dashboard_device_delete_action(int $id): array
{
    return [
        'action' => 'delete_device',
        'field' => 'device_id',
        'value' => $id,
        'title' => 'Hapus',
    ];
}

function dashboard_sync_state(bool $hasSynced, bool $pending, bool $failed, bool $activeScan, string $seenAt, string $lastError, int $progress = 0, string $phase = ''): array
{
    return [
        'pending' => $pending,
        'active' => $activeScan,
        'failed' => $failed,
        'error' => $failed ? ($lastError !== '' ? $lastError : 'Sinkronisasi gagal') : '',
        'has_synced' => $hasSynced,
        'title' => $failed ? ($lastError !== '' ? $lastError : 'Sinkronisasi gagal') : 'Terakhir sinkronisasi',
        'time' => $hasSynced ? dashboard_sync_time($seenAt) : '',
        'aria' => $pending ? ($activeScan ? 'Sinkronisasi OLT sedang berjalan' : 'Menunggu sinkronisasi pertama') : '',
        'progress' => max(0, min(100, $progress)),
        'phase' => $phase,
        'spinner_label' => $activeScan ? 'Sinkronisasi...' : 'Menunggu...',
    ];
}

function dashboard_chip(string $chip, string $label, string $title = ''): array
{
    return [
        'class' => $chip === 'unknown' ? 'neutral' : $chip,
        'state' => $chip,
        'label' => $label,
        'title' => $title,
    ];
}

function dashboard_olt_chip(string $status, int $failures): string
{
    if ($failures >= 3 || in_array($status, ['DOWN', 'ERROR'], true)) return 'danger';
    if ($failures === 2) return 'warning';
    if ($failures === 1) return 'neutral';
    if ($status === 'WARN') return 'warning';
    if ($status === 'OK') return 'success';
    return 'unknown';
}

function dashboard_cli_chip(string $status, int $failures): string
{
    if ($failures >= 3 || $status === 'DOWN') return 'danger';
    if ($failures === 2) return 'warning';
    if ($failures === 1) return 'neutral';
    if ($status === 'WARN') return 'warning';
    if ($status === 'OK') return 'success';
    return 'unknown';
}

function dashboard_device_text(mixed $value, string $fallback = '—'): string
{
    if (!is_scalar($value)) return $fallback;
    $text = trim((string)$value);
    return $text === '' || $text === '_' ? $fallback : $text;
}

function dashboard_device_address(mixed $address, int $port): string
{
    $text = dashboard_device_text($address);
    if ($text !== 'N/A' && $text !== '—' && $port > 0) $text .= ':' . $port;
    return $text;
}

function dashboard_sync_time(string $date): string
{
    $timestamp = strtotime($date);
    return $timestamp === false ? $date : date('d/m/Y H:i:s', $timestamp);
}
