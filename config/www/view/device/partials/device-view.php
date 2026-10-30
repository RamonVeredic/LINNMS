<?php
$deviceViewKind = $page === 'view-mikrotik' ? 'mikrotik' : 'switch';
$expectedDeviceType = $deviceViewKind === 'mikrotik' ? 'mikrotik' : 'cisco_catalyst_2960s';
if (!is_array($deviceDetail ?? null) || (string)($deviceDetail['device_type'] ?? '') !== $expectedDeviceType) return;
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

$formatVlans = static function (mixed $value): string {
    if (!is_array($value)) return 'N/A';
    $vlans = array_values(array_unique(array_filter(array_map('intval', $value), static fn(int $vlan): bool => $vlan >= 1 && $vlan <= 4094)));
    sort($vlans, SORT_NUMERIC);
    if ($vlans === []) return 'N/A';
    $ranges = [];
    $start = $previous = $vlans[0];
    foreach (array_slice($vlans, 1) as $vlan) {
        if ($vlan === $previous + 1) {
            $previous = $vlan;
            continue;
        }
        $ranges[] = $start === $previous ? (string)$start : $start . '-' . $previous;
        $start = $previous = $vlan;
    }
    $ranges[] = $start === $previous ? (string)$start : $start . '-' . $previous;
    return implode(', ', $ranges);
};

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
    'edit_url' => '',

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
                                    <?php
                                    $switchportMode = strtolower(trim((string)($interface['switchport_mode'] ?? '')));
                                    $switchportModeLabel = in_array($switchportMode, ['access', 'trunk'], true) ? strtoupper($switchportMode) : 'N/A';
                                    $switchVlans = is_array($interface['active_vlans'] ?? null)
                                        ? array_values(array_unique(array_filter(array_map('intval', $interface['active_vlans']), static fn(int $vlan): bool => $vlan > 1 && $vlan <= 4094)))
                                        : [];
                                    sort($switchVlans, SORT_NUMERIC);
                                    $visibleSwitchVlans = array_slice($switchVlans, 0, 5);
                                    $visibleSwitchVlansText = $visibleSwitchVlans !== [] ? implode(', ', $visibleSwitchVlans) : 'N/A';
                                    $hasMoreSwitchVlans = count($switchVlans) > count($visibleSwitchVlans);
                                    $nativeVlan = (int)($interface['native_vlan'] ?? 0);
                                    ?>
                                    <span class="switch-interface-name">
                                        <?= h($interfaceName) ?>
                                        <small class="switch-vlan-summary">
                                            <span class="switch-vlan-values" data-vlan-preview>VLAN: <?= h($visibleSwitchVlansText) ?><?php if ($switchportMode === 'trunk' && $nativeVlan > 1): ?> &middot; Native <?= $nativeVlan ?><?php endif; ?></span>
                                            <?php if ($hasMoreSwitchVlans): ?>
                                                <span class="switch-vlan-values" data-vlan-all hidden>VLAN: <?= h($formatVlans($switchVlans)) ?><?php if ($switchportMode === 'trunk' && $nativeVlan > 1): ?> &middot; Native <?= $nativeVlan ?><?php endif; ?></span>
                                                <button class="switch-vlan-toggle" type="button" data-vlan-toggle aria-expanded="false">Lihat semua VLAN</button>
                                            <?php endif; ?>
                                        </small>
                                    </span>
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
                            <td class="interface-record-cell">
                                <label class="pon-catat-switch" title="Simpan riwayat trafik interface ini">
                                    <input type="checkbox" role="switch" data-traffic-record
                                           data-device-id="<?= (int)($deviceDetail['id'] ?? 0) ?>"
                                           data-if-ref="<?= h($interfaceName) ?>"
                                           data-csrf="<?= h(csrf_token()) ?>"<?= isset($deviceTrafficSelected[$interfaceName]) ? ' checked' : '' ?>>
                                </label>
                            </td>
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
<?php
$deviceTrafficHistoryRefs = array_keys($deviceTrafficSelected);
?>
<section class="pon-traffic device-traffic-history" data-traffic data-traffic-mode="device"
         data-traffic-device-id="<?= (int)$deviceDetail['id'] ?>">
    <div class="panel-heading">
        <div>
            <h2><i class="bi bi-graph-up-arrow" aria-hidden="true"></i> Riwayat Trafik</h2>
            <p>Data dari interface yang dicatat. Grafik menampilkan 12 titik terakhir saat halaman dibuka.</p>
        </div>
    </div>
        <div class="notice" data-device-traffic-empty<?= $deviceTrafficHistoryRefs !== [] ? ' hidden' : '' ?>><i class="bi bi-info-circle" aria-hidden="true"></i> Belum ada interface yang dicatat. Aktifkan kolom <strong>Catat</strong> pada interface untuk mulai menyimpan riwayat trafik.</div>
        <div class="pon-traffic-ranges" role="group" aria-label="Rentang trafik"<?= $deviceTrafficHistoryRefs === [] ? ' hidden' : '' ?>>
            <?php foreach ([180 => '3m', 1800 => '30m', 3600 => '1H', 86400 => '1d'] as $deviceTrafficRange => $deviceTrafficRangeLabel): ?>
                <button type="button" class="pon-traffic-range<?= $deviceTrafficRange === 180 ? ' is-active' : '' ?>" data-traffic-range="<?= (int)$deviceTrafficRange ?>"><?= h($deviceTrafficRangeLabel) ?></button>
            <?php endforeach; ?>
        </div>
        <div class="pon-traffic-grid" data-traffic-grid<?= $deviceTrafficHistoryRefs === [] ? ' hidden' : '' ?>>
            <?php foreach ($deviceTrafficHistoryRefs as $deviceTrafficHistoryRef): ?>
                <?php
                $trafficCardRef = (string)$deviceTrafficHistoryRef;
                $trafficCardTitle = (string)$deviceTrafficHistoryRef;
                $trafficCardIcon = 'bi-ethernet';
                $trafficCardRole = 'device';
                require project_path('view', 'partials', 'traffic-card.php');
                ?>
            <?php endforeach; ?>
        </div>
        <p class="text-muted" data-traffic-state aria-live="polite" hidden></p>
</section>
<?php if (!$isMikrotik): ?>
    <section class="panel">
        <form method="post" data-device-delete-form>
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_device">
            <input type="hidden" name="device_id" value="<?= (int)$deviceDetail['id'] ?>">
            <button class="button button-danger"><i class="bi bi-trash3"></i> Hapus</button>
        </form>
    </section>
<?php endif; ?>
