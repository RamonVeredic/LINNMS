<?php

ob_start();
$oltFans = $oltFanRows;
require __DIR__ . '/fans.php';
$oltFanBlock = (string)ob_get_clean();

$oltView = [
    'slug' => $oltViewFamily,
    'label' => match ($oltViewFamily) {
        'zte' => 'ZTE',
        'external' => 'External',
        default => 'Hioso',
    },
    'id' => (int)($oltDetail['id'] ?? 0),
    'resource_ref' => public_resource_token('olt', (int)($oltDetail['id'] ?? 0)),
    'identity' => [
        'name' => $oltText($oltDetail['name'] ?? null),
        'status' => $oltStatus,
        'status_label' => $oltStatusLabel,
        'model' => $oltModel,
        'ip' => $oltText($oltDetail['ip_address'] ?? null),
        'temperature_text' => $oltIdentityModel['temperature_text'],
        'uptime_seconds' => $oltUptime,
        'uptime_text' => $oltUptime !== null ? olt_runtime_text(['runtime_seconds' => $oltUptime]) : 'N/A',
        'current_time' => $oltText($oltSystem['current_time'] ?? null),
    ],
    'actions' => [
        'refresh_action' => $oltRefreshAction,
        'refresh_async' => $oltRefreshAsync,
        'edit_url' => $oltViewFamily === 'external'
            ? public_page_url('edit-api-olt', ['id' => (int)($oltDetail['id'] ?? 0)])
            : public_page_url('edit-device', ['id' => (int)($oltDetail['id'] ?? 0)]),
    ],
    'actions_placement' => $oltViewFamily === 'zte' ? 'before' : 'inside',
    'overview_extra' => $oltFanBlock,
    'stats' => $oltStats,
    'tabs' => $oltTabs,
    'capabilities' => $oltViewCapabilities,
];
?>
<div class="olt-view" data-olt-view<?= $oltViewFamily === 'hioso' ? ' data-olt-implementation="' . h((string)($oltViewDefinition['implementation'] ?? '')) . '" data-olt-olt-ref="' . h(public_resource_token('olt', (int)($oltDetail['id'] ?? 0))) . '"' : '' ?>>
    <?php require __DIR__ . '/overview.php'; ?>
    <section class="panel olt-workspace">
        <?php require __DIR__ . '/tabs.php'; ?>
        <?php require __DIR__ . '/pon/panel.php'; ?>
        <div id="olt-panel-traffic" role="tabpanel" aria-labelledby="olt-tab-traffic" data-olt-panel="traffic" hidden>
            <?php
            $ponTrafficRows = $oltTrafficGraphRows;
            $ponTrafficGraphRows = $oltTrafficGraphRows;
            $ponTrafficSelected = $oltTrafficSelected;
            $ponTrafficRange = $oltTrafficActiveRange;
            $ponTrafficAverages = $oltTrafficAverages;
            $ponTrafficInitialSeries = is_array($oltTrafficHistory['series'] ?? null) ? $oltTrafficHistory['series'] : [];
            require __DIR__ . '/pon/traffic.php';
            ?>
        </div>
        <div id="olt-panel-vlans" role="tabpanel" aria-labelledby="olt-tab-vlans" data-olt-panel="vlans" hidden>
            <?php require __DIR__ . '/vlan/manager.php'; ?>
        </div>
        <div id="olt-panel-onu-types" role="tabpanel" aria-labelledby="olt-tab-onu-types" data-olt-panel="onu-types" hidden>
            <?php require __DIR__ . '/profile/onu-type-manager.php'; ?>
        </div>
        <div id="olt-panel-wan" role="tabpanel" aria-labelledby="olt-tab-wan" data-olt-panel="wan" hidden>
            <?php $oltProfileKinds = ['vlan_profile', 'ip']; $oltProfileShowSubtitles = true; $oltProfileEmptyIcon = $oltTabs['wan'][1]; $oltProfileEmptyText = 'No related information to show.'; $oltRowId = (int)($oltDetail['id'] ?? 0); require __DIR__ . '/profile/panel.php'; ?>
        </div>
        <div id="olt-panel-speed" role="tabpanel" aria-labelledby="olt-tab-speed" data-olt-panel="speed" hidden>
            <?php $oltProfileKinds = ['traffic', 'tcont']; $oltProfileShowSubtitles = true; $oltProfileEmptyIcon = $oltTabs['speed'][1]; $oltProfileEmptyText = 'Perangkat belum memiliki profil kecepatan.'; $oltRowId = (int)($oltDetail['id'] ?? 0); require __DIR__ . '/profile/panel.php'; ?>
        </div>
        <?php $oltRowId = (int)($oltDetail['id'] ?? 0); require __DIR__ . '/profile/speed-modal.php'; ?>
        <div id="olt-panel-system" role="tabpanel" aria-labelledby="olt-tab-system" data-olt-panel="system" hidden>
            <?php require __DIR__ . '/system-info.php'; ?>
        </div>
        <?php if ($oltViewFamily === 'zte'): ?>
            <?php $oltUplinkModalId = 'oltUplinkModal'; require __DIR__ . '/uplink/modal.php'; ?>
        <?php endif; ?>
    </section>
    <?php require __DIR__ . '/terminal/modal.php'; ?>
</div>
