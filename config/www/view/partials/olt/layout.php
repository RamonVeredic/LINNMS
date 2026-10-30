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
        'edit_url' => '',
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
        <?php
        $tabData = is_array($oltView ?? null) ? $oltView : [];
        $tabList = is_array($tabData['tabs'] ?? null) ? $tabData['tabs'] : [];
        $tabLines = [
            'onu-types' => ['ONU', 'Type'],
            'wan' => ['WAN-IP', 'Profil'],
            'speed' => ['Speed', 'Profil'],
        ];
        ?>
        <nav class="olt-tabs" role="tablist" aria-label="Informasi OLT">
            <?php foreach ($tabList as $tabKey => [$tabLabel, $tabIcon]): ?>
                <button type="button" role="tab" id="olt-tab-<?= h((string)$tabKey) ?>" aria-controls="olt-panel-<?= h((string)$tabKey) ?>" aria-label="<?= h((string)$tabLabel) ?>" aria-selected="<?= $tabKey === 'pon' ? 'true' : 'false' ?>" tabindex="<?= $tabKey === 'pon' ? '0' : '-1' ?>" data-olt-tab="<?= h((string)$tabKey) ?>"><i class="bi <?= h((string)$tabIcon) ?>" aria-hidden="true"></i><span class="olt-tab-label"><?php if (isset($tabLines[$tabKey])): ?><span><?= h($tabLines[$tabKey][0]) ?></span><span><?= h($tabLines[$tabKey][1]) ?></span><?php else: ?><?= h((string)$tabLabel) ?><?php endif; ?></span></button>
            <?php endforeach; ?>
        </nav>
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
