<?php
$onuServiceLive = is_array($onuService ?? null) && $onuService !== [];

$remoteRows = [];
$veipRows = [];
$tr069Rows = [];
if ($onuServiceLive) {
    foreach ((array)($onuService['security_mgmt'] ?? []) as $securityMgmt) {
        $remoteMode = strtolower((string)($securityMgmt['mode'] ?? ''));
        $remoteState = strtolower((string)($securityMgmt['state'] ?? ''));
        $remoteRows[] = [
            (string)($securityMgmt['index'] ?? ''),
            $remoteState === 'enable' ? 'Enable' : ($remoteState === 'disable' ? 'Disable' : '—'),
            $remoteMode === 'forward' ? 'Allowed' : ($remoteMode === 'discard' ? 'Blocked' : '—'),
            strtoupper((string)($securityMgmt['ingress_type'] ?? '')) ?: '—',
            strtoupper((string)($securityMgmt['protocol'] ?? '')) ?: '—',
            (string)($securityMgmt['start_ip'] ?? '') ?: '0.0.0.0',
            (string)($securityMgmt['end_ip'] ?? '') ?: '0.0.0.0',
        ];
    }
    foreach ((array)($onuService['vlan_ports'] ?? []) as $vlanPortEntry) {
        if (!is_array($vlanPortEntry)) continue;
        $veipPortName = strtolower((string)($vlanPortEntry['port'] ?? ''));
        if (preg_match('~^veip_[1-9][0-9]*$~', $veipPortName) !== 1) continue;
        $veipIndex = (int)substr($veipPortName, 5);
        $veipState = trim((string)($onuService['veip_states'][$veipIndex] ?? ''));
        if ($veipState === '') $veipState = trim((string)($onuService['veip_states'][(string)$veipIndex] ?? ''));
        if ($veipState === '') $veipState = 'unlock';
        $veipInterface = null;
        foreach ((array)($onuService['veip_interfaces'] ?? []) as $interface) {
            if (is_array($interface) && strcasecmp((string)($interface['port'] ?? ''), $veipPortName) === 0) { $veipInterface = $interface; break; }
        }
        $veipStatus = strtolower(trim((string)($veipInterface['admin status'] ?? '')));
        if ($veipStatus === '') $veipStatus = strtolower($veipState);
        $veipStatus = match ($veipStatus) {
            'unlock', 'enable', 'enabled', 'up', 'active' => 'unlock',
            'lock', 'disable', 'disabled', 'down', 'inactive' => 'lock',
            default => '—',
        };
        $veipRows[] = [strtoupper($veipPortName), $veipStatus === 'unlock' ? 'Enable' : ($veipStatus === 'lock' ? 'Disable' : '—'),
            (string)($vlanPortEntry['mode'] ?? '') ?: '—', (string)($vlanPortEntry['def_vlan'] ?? '') ?: '—',
            implode(', ', array_map('strval', (array)($vlanPortEntry['vlans'] ?? []))) ?: '—',
            (string)($veipInterface['priority'] ?? '') ?: '—', (string)($veipInterface['iana'] ?? '') ?: '—'];
    }
    foreach ((array)($onuService['tr069_mgmt'] ?? []) as $tr069) {
        if (!is_array($tr069)) continue;
        $vlan = (int)($tr069['vlan'] ?? 0);
        $tr069State = strtolower(trim((string)($tr069['state'] ?? '')));
        $tr069Rows[] = [
            (string)($tr069['index'] ?? 1),
            $tr069State === 'unlock' ? 'Enable' : ($tr069State === 'lock' ? 'Disable' : '—'),
            trim((string)($tr069['acs'] ?? '')) ?: '—',
            trim((string)($tr069['username'] ?? '')) ?: '—',
            trim((string)($tr069['password'] ?? '')) ?: '—',
            $vlan > 0 ? (string)$vlan : 'Untag',
            $vlan > 0 ? (string)($tr069['priority'] ?? 0) : '—',
        ];
    }
}
$onuWifiRows = [];
foreach ((array)($onuService['wifi_interfaces'] ?? []) as $interface) {
    if (!is_array($interface)) continue;
    $wifiPort = strtolower((string)($interface['port'] ?? ''));
    if ($wifiPort === '') continue;
    $wifiValues = (array)($onuService['wifi_ports'][$wifiPort] ?? []);
    $vlan = null;
    foreach ((array)($onuService['vlan_ports'] ?? []) as $vlanPort) {
        if (strcasecmp((string)($vlanPort['port'] ?? ''), (string)$wifiPort) === 0) { $vlan = $vlanPort; break; }
    }
    $wifiOperate = strtolower(trim((string)($interface['operate status'] ?? '')));
    $wifiOperateLabel = match ($wifiOperate) { 'enable', 'up' => 'UP', 'disable', 'down' => 'Down', default => '—' };
    $onuWifiRows[] = [strtoupper((string)$wifiPort), $wifiOperateLabel,
        (string)($vlan['mode'] ?? '') ?: '—', (string)($vlan['def_vlan'] ?? '') ?: '—',
        implode(', ', (array)($vlan['vlans'] ?? [])) ?: '—',
        (string)($vlan['priority'] ?? $vlan['def_prio'] ?? '') ?: '—',
        (string)($wifiValues['ssid'] ?? '') ?: '—',
        (string)($interface['admin status'] ?? '') ?: '—'];
}

$onuPreviewSections = [
    ['id' => 'remote', 'title' => 'Remote Access', 'icon' => 'bi-shield-lock',
        'headers' => ['ID', 'Status', 'Mode', 'Ingress Type', 'Service List', 'Start Source IP', 'End Source IP'],
        'rows' => $remoteRows],
    ['id' => 'veip', 'title' => 'VEIP', 'icon' => 'bi-diagram-3',
        'headers' => ['ID', 'Status', 'Mode', 'Access Vlan', 'Trunk VLANs', 'Priority', 'IANA'],
        'rows' => $veipRows],
    ['id' => 'tr069', 'title' => 'TR069', 'icon' => 'bi-hdd-network',
        'headers' => ['ID', 'Status', 'ACS', 'Username', 'Password', 'Vlan', 'Priority'],
        'rows' => $tr069Rows],
    ['id' => 'wifi', 'title' => 'WiFi', 'icon' => 'bi-wifi',
        'headers' => ['Port', 'Status', 'Mode', 'Access VLAN', 'Trunk VLANs', 'Priority', 'SSID'], 'rows' => $onuWifiRows],
];
$onuRowModals = ['remote' => 'onuRemoteAccessModal', 'veip' => 'onuVeipModal', 'tr069' => 'onuTr069Modal', 'wifi' => 'onuWifiModal'];
$onuRowCapabilities = ['remote' => 'onu_remote_access', 'veip' => 'onu_veip', 'tr069' => 'onu_tr069', 'wifi' => 'onu_wifi'];
?>
<section class="panel onu-service-stack" aria-label="Layanan ONU">
    <?php foreach ($onuPreviewSections as $previewSection): ?>
        <div class="onu-service-panel" role="group" aria-labelledby="onuPreview-<?= h($previewSection['id']) ?>" data-onu-service="<?= h($previewSection['id']) ?>" data-onu-row-id="<?= (int)($onuView['id'] ?? 0) ?>">
            <div class="panel-heading onu-preview-heading">
                <div class="tittle-subtitle"><h2 id="onuPreview-<?= h($previewSection['id']) ?>"><i class="bi <?= h($previewSection['icon']) ?>" aria-hidden="true"></i> <?= h($previewSection['title']) ?></h2></div>
                <?php if ($previewSection['id'] === 'remote' && !empty($onuViewCapabilities['onu_remote_access'])): ?>
                    <button class="button secondary small" type="button" data-onu-modal-open="onuRemoteAccessModal" data-onu-remote-add><i class="bi bi-plus-lg" aria-hidden="true"></i> Tambah</button>
                <?php endif; ?>
                <?php if ($previewSection['id'] === 'tr069' && $previewSection['rows'] === [] && !empty($onuViewCapabilities['onu_tr069'])): ?>
                    <button class="button secondary small" type="button" data-onu-modal-open="onuTr069Modal" data-onu-tr069-configure><i class="bi bi-pencil" aria-hidden="true"></i> Atur</button>
                <?php endif; ?>
            </div>
            <?php $canEditRows = !empty($onuViewCapabilities[$onuRowCapabilities[$previewSection['id']] ?? '']); ?>
            <div class="table-wrap onu-service-table-wrap">
                <table class="onu-service-table" aria-labelledby="onuPreview-<?= h($previewSection['id']) ?>">
                    <thead><tr>
                        <?php foreach ($previewSection['headers'] as $previewHeader): ?><th scope="col"><?= h($previewHeader) ?></th><?php endforeach; ?>
                        <?php if ($canEditRows): ?><th scope="col" class="onu-service-actions-heading">Aksi</th><?php endif; ?>
                    </tr></thead>
                    <tbody data-onu-service-entries>
                <?php if ($previewSection['rows'] === []): ?>
                    <tr data-onu-service-empty><td class="text-muted" colspan="<?= count($previewSection['headers']) + ($canEditRows ? 1 : 0) ?>"><?= in_array($previewSection['id'], ['wifi', 'veip'], true) && ($onuService[$previewSection['id'] . '_interface_probe'] ?? '') === 'unavailable' ? 'Kosong' : 'Kosong' ?></td></tr>
                <?php endif; ?>
                <?php foreach ($previewSection['rows'] as $previewRow): ?>
                    <?php
                    $previewRowId = (string)($previewRow[0] ?? '');
                    $canEditEntry = $canEditRows && ($previewSection['id'] !== 'veip' || strcasecmp($previewRowId, 'VEIP_1') === 0)
                        && ($previewSection['id'] !== 'tr069' || $previewRowId === '1');
                    ?>
                    <tr data-onu-service-entry="<?= h($previewRowId) ?>" data-onu-entry-state="<?= h(strtolower((string)($previewSection['id'] === 'wifi' ? ($previewRow[7] ?? '') : ($previewRow[1] ?? '')))) ?>">
                            <?php foreach ($previewSection['headers'] as $cellIndex => $previewHeader): ?>
                                <?php $previewCell = (string)($previewRow[$cellIndex] ?? '—'); ?>
                                <td data-onu-field-index="<?= (int)$cellIndex ?>">
                                        <?php if (in_array($previewCell, ['UP', 'Down'], true)): ?>
                                            <span class="status <?= $previewCell === 'UP' ? 'success' : 'text-muted' ?>" data-onu-value><?= h($previewCell) ?></span>
                                        <?php else: ?>
                                            <span data-onu-value><?= h($previewCell) ?></span>
                                        <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        <?php if ($canEditRows): ?><td class="onu-service-entry-actions">
                            <?php if ($canEditEntry): ?>
                                <span class="onu-service-entry-controls">
                                <button class="button secondary small" type="button" data-onu-modal-open="<?= h($onuRowModals[$previewSection['id']]) ?>" data-onu-row-edit="<?= h($previewSection['id']) ?>" data-onu-row-id="<?= h($previewRowId) ?>" aria-label="Edit <?= h($previewSection['title']) ?> <?= h($previewRowId) ?>" title="Edit <?= h($previewSection['title']) ?> <?= h($previewRowId) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                <button class="button button-danger small" type="button" data-onu-row-delete="<?= h($previewSection['id']) ?>" data-onu-row-id="<?= h($previewRowId) ?>" aria-label="Hapus <?= h($previewSection['title']) ?> <?= h($previewRowId) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                </span>
                            <?php endif; ?>
                        </td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
</section>
<div id="onuServiceActionModal" class="modal onu-control-modal" hidden aria-hidden="true">
    <div class="modal-card onu-control-modal-card" role="dialog" aria-modal="true" aria-labelledby="onuServiceActionModalTitle">
        <div class="onu-control-modal-heading">
            <h2 id="onuServiceActionModalTitle">Konfirmasi tindakan</h2>
            <button class="icon-button" type="button" data-onu-modal-close aria-label="Tutup modal"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <p data-onu-service-action-message></p>
        <div class="onu-control-modal-actions">
            <button class="button secondary" type="button" data-onu-modal-close>Batal</button>
            <button class="button primary" type="button" data-onu-service-action-confirm>Konfirmasi</button>
        </div>
    </div>
</div>
<?php if (!empty($onuViewCapabilities['onu_service'])): ?>
<div id="onuDataServiceModal" class="modal onu-control-modal" hidden aria-hidden="true">
    <div class="modal-card onu-control-modal-card" role="dialog" aria-modal="true" aria-labelledby="onuDataServiceModalTitle">
        <div class="onu-control-modal-heading"><h2 id="onuDataServiceModalTitle">Konfigurasi awal Service</h2><button class="icon-button" type="button" data-onu-modal-close aria-label="Tutup modal"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
        <form class="onu-control-modal-form" data-onu-data-service-form data-onu-row-id="<?= (int)($onuView['id'] ?? 0) ?>">
            <input name="config_action" type="hidden" value="create">
            <input name="index" type="hidden">
            <div class="field"><label for="onuDataServiceName">Nama Service</label><input id="onuDataServiceName" name="name" maxlength="32" required></div>
            <div class="field"><label for="onuDataServiceMode">Mode VLAN</label><select id="onuDataServiceMode" name="vlan_mode"><option value="tag">Tag</option><option value="untag">Untag</option><option value="qinq">QinQ</option></select></div>
            <div class="field"><label for="onuDataServiceVlan">VLAN pelanggan</label><input id="onuDataServiceVlan" name="vlan" type="number" min="1" max="4094" required list="onuDataServiceVlanList"><datalist id="onuDataServiceVlanList"><?php foreach ($onuServiceVlans as $vlan): ?><option value="<?= (int)$vlan ?>"></option><?php endforeach; ?></datalist></div>
            <div class="field" data-onu-data-service-outer hidden><label for="onuDataServiceOuter">VLAN layanan QinQ</label><input id="onuDataServiceOuter" name="svlan" type="number" min="1" max="4094" disabled list="onuDataServiceVlanList"></div>
            <div class="field"><label for="onuDataServiceTcont">Upload · Profil T-CONT</label><select id="onuDataServiceTcont" name="tcont_profile" required><option value="">Pilih profil</option><?php foreach ($onuServiceTcontProfiles as $profile): ?><option value="<?= h((string)($profile['name'] ?? '')) ?>"><?= h((string)($profile['name'] ?? '')) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="onuDataServiceTraffic">Download · Profil Traffic</label><select id="onuDataServiceTraffic" name="traffic_profile" required><option value="">Pilih profil</option><?php foreach ($onuServiceTrafficProfiles as $profile): ?><option value="<?= h((string)($profile['name'] ?? '')) ?>"><?= h((string)($profile['name'] ?? '')) ?></option><?php endforeach; ?></select></div>
            <div class="onu-control-modal-actions"><button class="button secondary" type="button" data-onu-modal-close>Batal</button><button class="button primary" type="submit">Simpan</button></div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php if (!empty($onuViewCapabilities['onu_tr069'])): ?>
<div id="onuTr069Modal" class="modal onu-control-modal onu-ethernet-modal" hidden aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="onuTr069ModalTitle">
        <div class="onu-eth-heading"><h2 id="onuTr069ModalTitle">TR069</h2><button class="icon-button" type="button" data-onu-modal-close aria-label="Tutup modal"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
        <form class="onu-eth-config-form" data-onu-service-form="tr069" data-onu-row-id="<?= (int)($onuView['id'] ?? 0) ?>">
            <input id="onuTr069State" name="state" type="hidden" value="lock">
            <div class="onu-eth-body">
                <div class="device-system-info-list onu-eth-info-list">
                    <div class="field"><label class="label" for="onuTr069ModalStatus">Status</label><span class="device-info-value"><span class="onu-service-toggle-row"><input id="onuTr069ModalStatus" type="checkbox" role="switch" data-onu-service-status><span data-onu-service-status-label>Disable</span></span></span></div>
                    <div class="field"><label class="label" for="onuTr069Acs">Alamat ACS <span class="text-muted">(opsional)</span></label><input id="onuTr069Acs" name="acs" maxlength="255" autocomplete="off" placeholder="https://acs.example.com"></div>
<div class="field"><label class="label" for="onuTr069Auth">Autentikasi ACS</label><select id="onuTr069Auth" name="auth_action"><option value="keep">Pertahankan</option><option value="disable">Tanpa autentikasi</option><option value="basic">Basic</option></select></div>
<div class="field" data-onu-tr069-credential hidden><label class="label" for="onuTr069Username">Username ACS</label><input id="onuTr069Username" name="username" maxlength="64" autocomplete="off" disabled></div>
<div class="field" data-onu-tr069-credential hidden><label class="label" for="onuTr069Password">Password ACS</label><input id="onuTr069Password" name="password" type="password" maxlength="64" autocomplete="new-password" disabled></div>
<div class="field"><label class="label" for="onuTr069Vlan">VLAN TR069 <span class="text-muted">(kosong = untag)</span></label><input id="onuTr069Vlan" name="vlan" type="number" min="1" max="4094" inputmode="numeric"></div>
<div class="field"><label class="label" for="onuTr069Priority">Prioritas</label><select id="onuTr069Priority" name="priority"><?php for ($priority = 0; $priority <= 7; $priority++): ?><option value="<?= $priority ?>"><?= $priority ?></option><?php endfor; ?></select></div>
                </div>
                <div class="onu-eth-actions"><button class="button primary" type="submit">Simpan</button></div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php if (!empty($onuViewCapabilities['onu_remote_access'])): ?>
<div id="onuRemoteAccessModal" class="modal onu-control-modal onu-ethernet-modal" hidden aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="onuRemoteAccessModalTitle">
        <div class="onu-eth-heading"><h2 id="onuRemoteAccessModalTitle">Remote Access</h2><button class="icon-button" type="button" data-onu-modal-close aria-label="Tutup modal"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
        <form class="onu-eth-config-form" data-onu-service-form="remote" data-onu-row-id="<?= (int)($onuView['id'] ?? 0) ?>">
            <input name="config_action" type="hidden" value="create"><input name="state" type="hidden" value="enable">
            <div class="onu-eth-body">
                <div class="device-system-info-list onu-eth-info-list">
                    <div class="field"><label class="label" for="onuRemoteAccessModalStatus">Status</label><span class="device-info-value"><span class="onu-service-toggle-row"><input id="onuRemoteAccessModalStatus" type="checkbox" role="switch" data-onu-service-status><span data-onu-service-status-label>Disable</span></span></span></div>
                    <div class="field"><label class="label" for="onuRemoteIndex">Remote ID</label><span class="device-info-value"><input id="onuRemoteIndex" name="index" type="number" min="1" max="65535" required></span></div>
<div class="field"><label class="label" for="onuRemoteMode">Mode</label><span class="device-info-value"><select id="onuRemoteMode" name="mode" required><option value="">Pilih</option><option value="forward">Allowed</option><option value="discard">Blocked</option></select></span></div>
<div class="field"><label class="label" for="onuRemoteIngress">Ingress Type</label><span class="device-info-value"><select id="onuRemoteIngress" name="ingress_type" required><option value="">Pilih</option><option value="wan">WAN</option><option value="lan">LAN</option></select></span></div>
<div class="field"><label class="label" for="onuRemoteProtocols">Service List</label><span class="device-info-value"><input id="onuRemoteProtocols" name="protocols" placeholder="web https snmp" required></span></div>
<div class="field"><label class="label" for="onuRemoteStartIp">Start Source IP</label><span class="device-info-value"><input id="onuRemoteStartIp" name="start_ip" inputmode="decimal" placeholder="0.0.0.0"></span></div>
<div class="field"><label class="label" for="onuRemoteEndIp">End Source IP</label><span class="device-info-value"><input id="onuRemoteEndIp" name="end_ip" inputmode="decimal" placeholder="0.0.0.0"></span></div>
                </div>
                <div class="onu-eth-actions"><button class="button primary" type="submit">Simpan</button></div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php if (!empty($onuViewCapabilities['onu_veip'])): ?>
<div id="onuVeipModal" class="modal onu-control-modal onu-ethernet-modal" hidden aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="onuVeipModalTitle">
        <div class="onu-eth-heading"><h2 id="onuVeipModalTitle">VEIP</h2><button class="icon-button" type="button" data-onu-modal-close aria-label="Tutup modal"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
        <form class="onu-eth-config-form" data-onu-service-form="veip" data-onu-row-id="<?= (int)($onuView['id'] ?? 0) ?>">
            <input id="onuVeipState" name="state" type="hidden" value="lock">
            <div class="onu-eth-body">
                <div class="device-system-info-list onu-eth-info-list">
                    <div class="field"><label class="label" for="onuVeipModalStatus">Status</label><span class="device-info-value"><span class="onu-service-toggle-row"><input id="onuVeipModalStatus" type="checkbox" role="switch" data-onu-service-status><span data-onu-service-status-label>Disable</span></span></span></div>
                    <div class="field"><label class="label" for="onuVeipMode">Mode</label><select id="onuVeipMode" name="mode"><option value="">—</option><option value="access">Access</option><option value="trunk">Trunk</option><option value="hybrid">Hybrid</option></select></div>
<div class="field"><label class="label" for="onuVeipDefault">VLAN Access</label><select id="onuVeipDefault" name="default_vlan" data-select-checkbox data-select-menu-class="onu-select-menu" data-select-search="Cari VLAN"><?php foreach ((array)($onuView['oltVlanIds'] ?? []) as $vlanId): ?><option value="<?= (int)$vlanId ?>"><?= (int)$vlanId ?></option><?php endforeach; ?></select></div>
<div class="field"><label class="label" for="onuVeipVlans">VLAN Trunk</label><select id="onuVeipVlans" name="vlans" multiple data-select-checkbox data-select-menu-class="onu-select-menu" data-select-search="Cari VLAN"><?php foreach ((array)($onuView['oltVlanIds'] ?? []) as $vlanId): ?><option value="<?= (int)$vlanId ?>"><?= (int)$vlanId ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="onu-eth-actions"><button class="button primary" type="submit">Simpan</button></div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php if (!empty($onuViewCapabilities['onu_wifi'])): ?>
<div id="onuWifiModal" class="modal onu-control-modal onu-ethernet-modal" hidden aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="onuWifiModalTitle">
        <div class="onu-eth-heading"><h2 id="onuWifiModalTitle">WiFi</h2><button class="icon-button" type="button" data-onu-modal-close aria-label="Tutup modal"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
        <form class="onu-eth-config-form" data-onu-service-form="wifi" data-onu-row-id="<?= (int)($onuView['id'] ?? 0) ?>">
            <input id="onuWifiState" name="state" type="hidden" value="lock">
            <div class="onu-eth-body">
                <div class="device-system-info-list onu-eth-info-list">
                    <div class="field"><label class="label" for="onuWifiModalStatus">Status</label><span class="device-info-value"><span class="onu-service-toggle-row"><input id="onuWifiModalStatus" type="checkbox" role="switch" data-onu-service-status><span data-onu-service-status-label>Disable</span></span></span></div>
                    <div class="field"><label class="label" for="onuWifiPort">Port WiFi</label><select id="onuWifiPort" name="port" required></select></div>
<div class="field"><label class="label" for="onuWifiSsid">Nama SSID</label><input id="onuWifiSsid" name="ssid" maxlength="32" autocomplete="off"></div>
<div class="field"><label class="label" for="onuWifiMode">Mode</label><select id="onuWifiMode" name="mode"><option value="">—</option><option value="access">Access</option><option value="trunk">Trunk</option><option value="hybrid">Hybrid</option></select></div>
<div class="field"><label class="label" for="onuWifiDefault">VLAN Access</label><select id="onuWifiDefault" name="default_vlan" data-select-checkbox data-select-menu-class="onu-select-menu" data-select-search="Cari VLAN"><?php foreach ((array)($onuView['oltVlanIds'] ?? []) as $vlanId): ?><option value="<?= (int)$vlanId ?>"><?= (int)$vlanId ?></option><?php endforeach; ?></select></div>
<div class="field"><label class="label" for="onuWifiVlans">VLAN Trunk</label><select id="onuWifiVlans" name="vlans" multiple data-select-checkbox data-select-menu-class="onu-select-menu" data-select-search="Cari VLAN"><?php foreach ((array)($onuView['oltVlanIds'] ?? []) as $vlanId): ?><option value="<?= (int)$vlanId ?>"><?= (int)$vlanId ?></option><?php endforeach; ?></select></div>
<div class="field"><label class="label" for="onuWifiSecurity">Keamanan</label><select id="onuWifiSecurity" name="security"><option value="keep">Pertahankan</option><option value="open">Open</option><option value="wpa">WPA PSK</option></select></div>
<div class="field" data-onu-wifi-security hidden><label class="label" for="onuWifiAuth">Autentikasi</label><select id="onuWifiAuth" name="auth"><option value="wpa2-psk">WPA2 PSK</option><option value="wpa-wpa2-psk">WPA/WPA2 PSK</option></select></div>
<div class="field" data-onu-wifi-security hidden><label class="label" for="onuWifiEncryption">Enkripsi</label><select id="onuWifiEncryption" name="encryption"><option value="aes">AES</option><option value="tkip-aes">TKIP/AES</option></select></div>
<div class="field" data-onu-wifi-security hidden><label class="label" for="onuWifiKey">Kata sandi baru</label><input id="onuWifiKey" name="key" type="password" minlength="8" maxlength="63" autocomplete="new-password"></div>
                </div>
                <div class="onu-eth-actions"><button class="button primary" type="submit">Simpan</button></div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
