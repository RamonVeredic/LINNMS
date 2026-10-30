<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';
require_once __DIR__ . '/dashboard-view.php';

function dashboard_recent_activity_rows(array $events): array
{
    $rows = [];
    foreach ($events as $event) {
        if (!is_array($event)) continue;
        $statusKey = (string)($event['status_key'] ?? 'neutral');
        $statusClass = match ($statusKey) {
            'online' => 'success',
            'loss', 'powerdown', 'offline' => 'danger',
            'signal-warning' => 'warning',
            default => 'text-muted',
        };
        $serial = (string)($event['serial'] ?? '—');
        $powerTrend = (string)($event['new_power_trend'] ?? '');
        $event['view'] = [
            'time' => format_feed_time($event['created_at'] ?? null),
            'pon_event' => is_array($event['pon_event'] ?? null) ? $event['pon_event'] : null,
            'subject' => trim((string)($event['subject'] ?? '')),
            'serial' => $serial,
            'show_identity' => !empty($event['show_identity']),
            'show_serial' => empty($event['show_identity']) && $serial !== '—' && $serial !== '',
            'old_power' => (string)($event['old_power_display'] ?? ''),
            'new_power' => (string)($event['new_power_display'] ?? ''),
            'power_trend' => in_array($powerTrend, ['better', 'critical', 'worse', 'same'], true) ? $powerTrend : 'same',
            'detail_class' => $statusKey !== 'neutral' ? 'recent-activity-status ' . $statusClass : '',
        ];
        $rows[] = $event;
    }
    return $rows;
}

function dashboard_read_data(int $userId, array $query): array
{
    $data = internal_api_request($userId, 'dashboard.read', [
        'activity_page' => (int)($query['activity_page'] ?? 1),
        'activity_q' => trim((string)($query['activity_q'] ?? '')),
        'activity_device' => trim((string)($query['activity_device'] ?? '')),
    ]);

    $devices = is_array($data['devices'] ?? null) ? $data['devices'] : [];
    $olts = is_array($devices['olt'] ?? null) ? $devices['olt'] : [];
    $mikrotik = is_array($devices['mikrotik'] ?? null) ? $devices['mikrotik'] : [];
    $switch = is_array($devices['switch'] ?? null) ? $devices['switch'] : [];
    $scan = is_array($data['scan'] ?? null) ? $data['scan'] : [];
    $activity = is_array($data['activity'] ?? null) ? $data['activity'] : [];
    $quota = is_array($data['quota'] ?? null) ? $data['quota'] : [];
    $stats = is_array($data['stats'] ?? null) ? $data['stats'] : [];
    $activitySearch = trim((string)($activity['search'] ?? ''));
    $activityDevice = trim((string)($activity['device'] ?? ''));
    $activityDevices = [];
    foreach (array_merge($olts, $mikrotik, $switch) as $device) {
        $name = display_text($device['name'] ?? null);
        if (in_array($name, ['_', '—'], true)) continue;
        $activityDevices[$name] = [
            'name' => $name,
            'ip' => trim((string)($device['ip_address'] ?? '')),
        ];
    }
    uksort($activityDevices, 'strnatcasecmp');
    $activityUrl = static function (int $targetPage) use ($activitySearch, $activityDevice): string {
        $params = ['activity_page' => max(1, $targetPage)];
        if ($activitySearch !== '') $params['activity_q'] = $activitySearch;
        if ($activityDevice !== '') $params['activity_device'] = $activityDevice;
        return public_page_url('dashboard', $params);
    };
    $deviceRows = dashboard_device_table_rows($olts, $mikrotik, $switch, $scan);
    $activityPage = max(1, (int)($activity['page'] ?? 1));
    $activityPages = max(1, (int)($activity['pages'] ?? 1));

    return [
        'hasZte' => !empty($data['has_zte']),
        'totalDevices' => count($mikrotik) + (int)($stats['total_olts'] ?? count($olts)) + count($switch),
        'stats' => $stats,
        'olts' => $olts,
        'mikrotikDevices' => $mikrotik,
        'switchDevices' => $switch,
        'settings' => is_array($data['settings'] ?? null) ? $data['settings'] : [],
        'oltQuota' => is_array($quota['olt'] ?? null) ? $quota['olt'] : [],
        'mikrotikQuota' => is_array($quota['mikrotik'] ?? null) ? $quota['mikrotik'] : [],
        'switchQuota' => is_array($quota['switch'] ?? null) ? $quota['switch'] : [],
        'activeOltScanRows' => array_fill_keys(array_map('intval', (array)($scan['active_olt_row_ids'] ?? [])), true),
        'latestOltScanStatuses' => is_array($scan['latest_statuses'] ?? null) ? $scan['latest_statuses'] : [],
        'recentEvents' => dashboard_recent_activity_rows(is_array($activity['events'] ?? null) ? $activity['events'] : []),
        'recentActivityPage' => $activityPage,
        'recentActivityPages' => $activityPages,
        'recentActivityTotal' => (int)($activity['total'] ?? 0),
        'recentActivitySearch' => $activitySearch,
        'recentActivityDevice' => $activityDevice,
        'recentActivityDevices' => $activityDevices,
        'recentActivityUrl' => $activityUrl,
        'recentActivityEmptyText' => $activitySearch !== '' || $activityDevice !== '' ? 'Tidak ditemukan.' : 'Belum ada aktivitas terbaru',
        'deviceRows' => $deviceRows,
        'deviceRowCount' => count($deviceRows),
    ];
}
