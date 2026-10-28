<?php
if (!is_array($deviceDetail)) return;
if (!isset($deviceSystem) || !is_array($deviceSystem)) {
    $deviceSystem = [];
}
if (!isset($deviceInterfaces) || !is_array($deviceInterfaces)) {
    $deviceInterfaces = [];
}
if (!isset($interfaceRates) || !is_array($interfaceRates)) {
    $interfaceRates = [];
}
if (!isset($deviceError) || !is_string($deviceError)) {
    $deviceError = '';
}
$isMikrotik = ($deviceViewKind ?? '') === 'mikrotik';

if (!$isMikrotik) {
    $deviceInterfaces = array_values(array_filter(
        $deviceInterfaces,
        static function (array $interface): bool {
            $name = trim((string)($interface['name'] ?? ''));
            return preg_match('/^(?:stackport|stacksub(?:-|$))/i', $name) !== 1;
        }
    ));
}
$deviceInterfaces = array_values(array_filter($deviceInterfaces, static function (array $interface): bool {
    $type = strtolower(trim((string)($interface['interface_type'] ?? '')));
    $name = strtolower(trim((string)($interface['name'] ?? '')));
    $isPppoe = $type === 'pppoe' || str_contains($name, 'pppoe');
    // Sesi PPPoE yang sudah tidak ada di perangkat disimpan sebagai if_index 0.
    // Jangan tampilkan sebagai sesi aktif/down di daftar saat ini.
    return !$isPppoe || (int)($interface['if_index'] ?? 0) > 0;
}));
$upInterfaces = count(array_filter($deviceInterfaces, static fn(array $row): bool => strtolower((string)($row['oper_status'] ?? '')) === 'up'));
$downInterfaces = count($deviceInterfaces) - $upInterfaces;
$activePppoeInterfaces = count(array_filter(
    $deviceInterfaces,
    static fn(array $row): bool =>
    str_contains(strtolower((string)($row['name'] ?? '')), 'pppoe')
        && strtolower((string)($row['oper_status'] ?? '')) === 'up'
));
$adminDownInterfaces = count(array_filter($deviceInterfaces, static fn(array $row): bool => strtolower((string)($row['admin_status'] ?? '')) !== 'up'));
usort($deviceInterfaces, static function (array $left, array $right): int {
    $priority = static function (array $row): int {
        if (strtolower((string)($row['admin_status'] ?? '')) !== 'up') return 2;
        $name = strtolower(trim((string)($row['name'] ?? '')));
        return ($name === 'lo' || str_contains($name, 'loopback')) ? 1 : 0;
    };
    $priorityCompare = $priority($left) <=> $priority($right);
    if ($priorityCompare !== 0) return $priorityCompare;
    return (int)($left['if_index'] ?? 0) <=> (int)($right['if_index'] ?? 0);
});
$pppoeInterfaces = array_values(array_filter($deviceInterfaces, static function (array $interface): bool {
    return strtolower((string)($interface['interface_type'] ?? '')) === 'pppoe'
        || str_contains(strtolower((string)($interface['name'] ?? '')), 'pppoe');
}));
$regularInterfaces = array_values(array_filter($deviceInterfaces, static function (array $interface): bool {
    return strtolower((string)($interface['interface_type'] ?? '')) !== 'pppoe'
        && !str_contains(strtolower((string)($interface['name'] ?? '')), 'pppoe');
}));
$model = trim((string)($deviceDetail['model'] ?? ''));
if ($model === '') $model = trim((string)($deviceSystem['model'] ?? ''));
$model = display_model_chars($model);
$cpuUsage = is_numeric($deviceSystem['cpu_usage_percent'] ?? null) ? max(0, min(100, (float)$deviceSystem['cpu_usage_percent'])) : null;
$memoryUsage = is_numeric($deviceSystem['memory_usage_percent'] ?? null) ? max(0, min(100, (float)$deviceSystem['memory_usage_percent'])) : null;
$uptimeSeconds = is_numeric($deviceSystem['uptime_seconds'] ?? null)
    ? max(0, (int)$deviceSystem['uptime_seconds'])
    : null;
$formatBytes = static function (mixed $value): string {
    if (!is_numeric($value) || (float)$value < 0) return 'N/A';
    $bytes = (float)$value;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $unit = 0;
    while ($bytes >= 1024 && $unit < count($units) - 1) {
        $bytes /= 1024;
        $unit++;
    }
    return ($unit === 0 ? number_format($bytes, 0) : number_format($bytes, 1)) . ' ' . $units[$unit];
};
$formatRate = static function (mixed $value): string {
    if (!is_numeric($value) || (float)$value < 0) return 'N/A';
    $rate=(float)$value;
    $units=['bps','Kbps','Mbps','Gbps','Tbps'];
    $unit=0;
    while($rate>=1000&&$unit<count($units)-1){$rate/=1000;$unit++;}
    return ($rate>=100?number_format($rate,0):number_format($rate,2)).' '.$units[$unit];
};
$interfaceTypeLabel = static function (array $interface): string {
    $type = trim((string)($interface['interface_type'] ?? ''));
    if ($type !== '') return $type;
    $name = strtolower((string)($interface['name'] ?? ''));
    return match (true) {
        str_contains($name, 'pppoe') => 'PPPoE',
        str_contains($name, 'vlan') => 'VLAN',
        str_contains($name, 'bridge') => 'Bridge',
        $name === 'lo', str_contains($name, 'loopback') => 'Loopback',
        str_contains($name, 'lte') => 'LTE',
        str_contains($name, 'vpn') => 'VPN',
        default => 'Lainnya',
    };
};
$formatInterfaceSpeed = static function (mixed $value): string {
    if (!is_numeric($value) || (float)$value < 0) return 'N/A';
    $speedMbps = (float)$value;
    if ($speedMbps >= 1000) {
        $speedGbps = $speedMbps / 1000;
        return rtrim(rtrim(number_format($speedGbps, 2, '.', ''), '0'), '.') . 'G';
    }
    return rtrim(rtrim(number_format($speedMbps, 2, '.', ''), '0'), '.') . 'M';
};
$displayPppoeName = static function (mixed $value): string {
    $originalName = trim((string)$value);
    $cleanName = trim($originalName, "<> \t\n\r\0\x0B");
    $cleanName = preg_replace('/^pppoe[\s_-]*/i', '', $cleanName) ?? $cleanName;
    return trim($cleanName) !== '' ? trim($cleanName) : $originalName;
};
?>
<?php
$deviceOverviewHero = [
    'icon' => $isMikrotik ? 'bi-router' : 'bi-hdd-network',
    'name' => $deviceDetail['name'] ?? null,
    'subtitle' => (string)$deviceDetail['ip_address'] . ' · ' . $model,
    'edit_url' => public_page_url('edit-device', ['id' => (int)$deviceDetail['id']]),

    'attributes' => [
        'data-device-view' => true,
        'data-device-id' => (int)$deviceDetail['id'],
        'data-device-ref' => public_resource_token($isMikrotik ? 'mikrotik' : 'switch', (int)$deviceDetail['id']),
        'data-device-type' => (string)$deviceDetail['device_type'],
    ],
];
$deviceOverviewWarning = $deviceError !== '' ? 'Gagal memperbaharui' : '';
$deviceOverviewHealth = [
    [
        'icon' => 'bi-cpu',
        'label' => 'CPU Usage',
        'head_value' => $cpuUsage !== null ? number_format($cpuUsage, 1) . ' %' : 'N/A',
        'head_value_attributes' => ['data-device-cpu-text' => true],
        'meter' => $cpuUsage,
        'meter_attributes' => ['data-device-cpu-meter' => true],
    ],
    [
        'icon' => 'bi-memory',
        'label' => 'RAM Usage',
        'head_value' => $memoryUsage !== null ? number_format($memoryUsage, 1) . ' %' : 'N/A',
        'head_value_attributes' => ['data-device-memory-text' => true],
        'meter' => $memoryUsage,
        'meter_attributes' => ['data-device-memory-meter' => true],
        'detail' => $formatBytes($deviceSystem['memory_used_bytes'] ?? null) . ' / ' . $formatBytes($deviceSystem['memory_total_bytes'] ?? null),
        'detail_attributes' => ['data-device-memory-detail' => true],
    ],
    [
        'class' => 'uptime-card',
        'icon' => 'bi-clock-history',
        'label' => 'Uptime',
        'value' => display_text($deviceSystem['uptime_human'] ?? null),
        'value_attributes' => [
            'data-device-uptime' => true,
            'data-device-uptime-clock' => $uptimeSeconds !== null,
            'data-uptime-seconds' => $uptimeSeconds,
            'data-uptime-day-label' => $uptimeSeconds !== null ? 'hari' : null,
        ],
    ],
];
$deviceOverviewStatClass = $isMikrotik ? 'is-mikrotik' : 'is-switch';
$deviceOverviewStats = $isMikrotik
    ? [
        ['class' => 'accent-lost', 'label' => 'PPPOE', 'value' => $activePppoeInterfaces],
        ['class' => 'accent-green', 'label' => 'HOTSPOT', 'value' => is_numeric($deviceSystem['hotspot_active_users'] ?? null) ? (int)$deviceSystem['hotspot_active_users'] : 'N/A', 'value_attributes' => ['data-mikrotik-hotspot-active' => true]],
    ]
    : [
        ['class' => 'accent-teal', 'label' => 'TOTAL PORT', 'value' => count($deviceInterfaces)],
        ['class' => 'accent-green', 'label' => 'PORT UP', 'value' => $upInterfaces],
        ['class' => 'accent-down', 'label' => 'PORT DOWN', 'value' => $downInterfaces],
        ['class' => 'accent-red', 'label' => 'ADMIN DISABLED', 'value' => $adminDownInterfaces],
    ];
$deviceOverviewDetails = [
    ['label' => 'System Name', 'value' => display_text($deviceSystem['identity'] ?? null)],
    ['label' => 'Model', 'value' => $model, 'attributes' => ['data-device-model' => true]],
    ['label' => 'Vendor', 'value' => display_text($deviceSystem['vendor'] ?? null)],
    ['label' => 'Software', 'value' => display_text($deviceSystem['version'] ?? $deviceSystem['software_version'] ?? null)],
    ['label' => 'Serial Number', 'value' => display_text($deviceSystem['serial'] ?? null), 'class' => 'mono'],
    ['label' => 'MAC', 'value' => display_text($deviceSystem['mac'] ?? null), 'class' => 'mono'],
    ['label' => 'IP', 'value' => display_text($deviceSystem['host'] ?? $deviceDetail['ip_address'] ?? null), 'class' => 'mono'],
    ['label' => 'Device Time', 'value' => display_text($deviceSystem['current_time'] ?? null)],
    ['label' => 'Location', 'value' => display_text($deviceSystem['location'] ?? null)],
];
require __DIR__ . '/device-overview.php';
?>

<div class="device-interface-table-grid">
    <section class="table-panel" data-interface-list>
        <div class="table-toolbar">
            <div><strong class="dashboard-table-title"><?= $isMikrotik ? 'Interface MikroTik' : 'Port Switch' ?></strong></div>
        </div>
        <div class="table-wrap">
            <table class="<?= !$isMikrotik ? 'switch-interface-table' : '' ?>">
                <colgroup>
                    <col class="interface-name-column">
                    <col class="interface-type-column">
                    <col class="interface-rate-column">
                    <col class="interface-rate-column">
                    <col class="interface-record-column">
                </colgroup>
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Type</th><th>RX</th>
                        <th>TX</th>
                        <th>Catat</th>
                    </tr>
                </thead>
                <tbody data-device-interface-rows data-interface-list-rows>
                    <?php foreach ($regularInterfaces as $interface):
                        $adminDisabled = strtolower((string)($interface['admin_status'] ?? '')) !== 'up';
                        $interfaceName = (string)($interface['name'] ?? '');
                        $ifIndex = (int)($interface['if_index'] ?? 0);
                        $isInterfaceUp = strtolower((string)($interface['oper_status'] ?? '')) === 'up';
                        $interfaceRate=is_array($interfaceRates[(string)$ifIndex]??null)?$interfaceRates[(string)$ifIndex]:[];
                    ?>
                        <tr class="<?= $adminDisabled ? 'is-admin-disabled' : '' ?>" data-interface-list-row data-interface-rate-row data-if-index="<?= $ifIndex ?>" <?= $adminDisabled ? ' aria-disabled="true"' : '' ?>>
                            <td>
                                <?php if (!$isMikrotik): ?>
                                    <?php require __DIR__ . '/interface-name.php'; ?>
                                <?php else: ?>
                                    <span><?= h($interfaceName) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span<?= !$isMikrotik ? ' class="switch-interface-type"' : '' ?>>
                                    <span class="interface-type-with-status">
                                        <span class="status-dot <?= $isInterfaceUp ? 'success' : 'danger' ?>" role="img" aria-label="<?= $isInterfaceUp ? 'Up' : 'Down' ?>"></span>
                                        <span><?= h($interfaceTypeLabel($interface)) ?></span>
                                        <small class="interface-speed-inline"><?= h($formatInterfaceSpeed($interface['speed_mbps'] ?? null)) ?></small>
                                    </span>
                                    <?php if (!$isMikrotik): ?><small class="switchport-mode-text"><?= h($switchportModeLabel) ?></small><?php endif; ?>
                                </span>
                            </td>
                            <td class="mono interface-rate-value is-rx" data-interface-rx><?= h($formatRate($interfaceRate['rx_bps']??null)) ?></td>
                            <td class="mono interface-rate-value is-tx" data-interface-tx><?= h($formatRate($interfaceRate['tx_bps']??null)) ?></td>
                            <?php require __DIR__ . '/device-traffic-record-toggle.php'; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="interface-list-pagination" data-interface-pagination><small data-interface-page-info></small>
            <div><button type="button" class="icon-button" data-interface-page-prev aria-label="Halaman sebelumnya"><i class="bi bi-chevron-left"></i></button><span data-interface-page-number></span><button type="button" class="icon-button" data-interface-page-next aria-label="Halaman berikutnya"><i class="bi bi-chevron-right"></i></button></div>
        </div>
    </section>

    <?php if ($isMikrotik) require __DIR__ . '/pppoe-table.php'; ?>
</div>
<?php require __DIR__ . '/device-traffic-history.php'; ?>
<?php if (!$isMikrotik) require __DIR__ . '/delete-action.php'; ?>
