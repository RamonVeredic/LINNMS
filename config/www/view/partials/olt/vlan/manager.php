<?php
$oltVlanRows = array_values(array_filter((array)($oltVlanRows ?? []), 'is_array'));
$oltVlanDeviceId = (int)($oltDetail['id'] ?? 0);
$oltVlanNormalizeInterface = static fn(mixed $value): string => trim((string)$value);
$oltVlanInterfaces = [];
$oltVlanInterfaceLabels = [];
$oltVlanInterfaceStatus = [];
foreach ((array)($oltPonGroups ?? []) as $oltVlanGroup) {
    if (!is_array($oltVlanGroup)) continue;
    $oltVlanStates = OltDetailViewModel::ponPortStates((array)($oltVlanGroup['ports'] ?? []), (array)($oltVlanGroup['card'] ?? []), (bool)($oltVlanPreferAdminState ?? true));
    foreach ($oltVlanStates['states'] as $oltVlanRef => $oltVlanState) {
        $oltVlanPort = (array)($oltVlanGroup['ports'][$oltVlanRef] ?? []);
        $oltVlanInterfaceRef = $oltVlanNormalizeInterface($oltVlanPort['port_ref'] ?? $oltVlanRef);
        $oltVlanInterfaceStatus[strtolower($oltVlanInterfaceRef)] = match ($oltVlanState) {
            'Up' => 'up',
            'Down' => 'down',
            'Shutdown' => 'offline',
            default => 'unknown',
        };
    }
}
foreach ((array)($oltUplinkPorts ?? []) as $oltVlanUplink) {
    if (!is_array($oltVlanUplink)) continue;
    $oltVlanUplinkRef = trim((string)($oltVlanUplink['port_ref'] ?? ''));
    if ($oltVlanUplinkRef === '') continue;
    $oltVlanLink = strtolower(trim((string)($oltVlanUplink['link_status'] ?? '')));
    $oltVlanAdmin = strtolower(trim((string)($oltVlanUplink['admin_state'] ?? $oltVlanUplink['enable'] ?? '')));
    $oltVlanInterfaceStatus[strtolower($oltVlanUplinkRef)] = $oltVlanAdmin === 'disable' ? 'offline' : match ($oltVlanLink) {
        'linkup' => 'online',
        'linkdown' => 'down',
        default => 'unknown',
    };
}
foreach ((array)($oltPonSnapshot ?? []) as $oltVlanPon) {
    if (!is_array($oltVlanPon)) continue;
    $ref = trim((string)($oltVlanPon['port_ref'] ?? ''));
    if ($ref === '') continue;
    $interface = $oltVlanNormalizeInterface($ref);
    $ponLabel = trim((string)($oltVlanPon['label'] ?? $oltVlanPon['pon'] ?? ''));
    $oltVlanInterfaces[$interface] = $ponLabel !== '' ? $ponLabel : $ref;
    $oltVlanInterfaceLabels[strtolower($interface)] = $ponLabel !== '' ? $ponLabel : $ref;
}
foreach ((array)($oltUplinkPorts ?? []) as $oltVlanUplink) {
    if (!is_array($oltVlanUplink)) continue;
    $interface = trim((string)($oltVlanUplink['port_ref'] ?? ''));
    if ($interface === '') continue;
    $uplinkLabel = trim((string)($oltVlanUplink['label'] ?? $oltVlanUplink['name'] ?? ''));
    $oltVlanInterfaces[$interface] = $uplinkLabel !== '' ? $uplinkLabel : $interface;
    $oltVlanInterfaceLabels[strtolower($interface)] = $uplinkLabel !== '' ? $uplinkLabel : $interface;
}
asort($oltVlanInterfaces, SORT_NATURAL | SORT_FLAG_CASE);
?>
<section class="olt-vlan-manager" aria-label="Manajemen VLAN">
    <?php $oltTabActions = [['type' => 'modal', 'value' => 'oltVlanModal-' . $oltVlanDeviceId, 'label' => 'Add']]; require __DIR__ . '/../tab-actions.php'; ?>
    <?php if ($oltVlanRows === []): ?>
        <div class="olt-profile-empty"><i class="bi bi-inbox" aria-hidden="true"></i><strong>Belum ada VLAN</strong><span>Belum ada VLAN untuk ditampilkan.</span></div>
    <?php else: ?>
        <div class="olt-vlan-table-head" role="group" aria-label="Header tabel VLAN"><span>VLAN ID</span><span>Nama</span><span>Aksi</span></div>
        <div class="olt-vlan-accordion-list">
            <?php foreach ($oltVlanRows as $oltVlanRow): ?>
                <?php
                $oltVlanData = (array)($oltVlanRow['data'] ?? []);
                $oltVlanId = (int)($oltVlanData['vlan_id'] ?? $oltVlanRow['name'] ?? 0);
                $oltVlanName = trim((string)($oltVlanData['vlan_name'] ?? ''));
                if ($oltVlanName === '') $oltVlanName = '—';
                ?>
                <div class="olt-vlan-accordion-item">
                    <form method="post" class="olt-vlan-delete-form">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete_olt_profile"><input type="hidden" name="olt_row_id" value="<?= $oltVlanDeviceId ?>"><input type="hidden" name="kind" value="vlan"><input type="hidden" name="vlan_id" value="<?= $oltVlanId ?>">
                        <button class="button small button-danger" type="submit" aria-label="Hapus VLAN <?= $oltVlanId ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                    </form>
                    <details name="zte-vlan-accordion">
                        <summary><span class="olt-vlan-id"><b><?= $oltVlanId ?></b></span><span class="olt-vlan-name"><?= h($oltVlanName) ?></span><span class="olt-vlan-chevron"><i class="bi bi-chevron-down" aria-hidden="true"></i></span></summary>
                        <div class="olt-vlan-membership-grid">
                            <?php $oltVlanMembersByMode = []; ?>
                            <?php foreach (['access' => 'Access', 'trunk' => 'Trunk'] as $oltVlanMode => $oltVlanModeLabel): ?>
                                <?php $oltVlanMembers = array_values(array_unique(array_filter(array_map($oltVlanNormalizeInterface, (array)($oltVlanData[$oltVlanMode] ?? [])), static fn(string $ref): bool => $ref !== ''))); ?>
                                <?php $oltVlanMembersByMode[$oltVlanMode] = $oltVlanMembers; ?>
                                <div class="olt-vlan-mode-summary"><strong><?= h($oltVlanModeLabel) ?>:</strong> <span class="olt-vlan-mode-members"><?php if ($oltVlanMembers === []): ?>—<?php else: ?><?php foreach ($oltVlanMembers as $oltVlanMember): $oltVlanMemberKey = strtolower($oltVlanMember); ?><span class="olt-vlan-chip is-<?= h($oltVlanInterfaceStatus[$oltVlanMemberKey] ?? 'unknown') ?>"><?= h($oltVlanInterfaceLabels[$oltVlanMemberKey] ?? $oltVlanMember) ?></span><?php endforeach; ?><?php endif; ?></span></div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($oltVlanInterfaces !== []): ?>
                            <div class="olt-vlan-edit-area">
                                <button type="button" class="button secondary small" data-vlan-members-edit aria-expanded="false"><i class="bi bi-pencil" aria-hidden="true"></i> Edit</button>
                                <form method="post" class="olt-vlan-members-editor" hidden>
                                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="save_olt_vlan_members"><input type="hidden" name="olt_row_id" value="<?= $oltVlanDeviceId ?>"><input type="hidden" name="vlan_id" value="<?= $oltVlanId ?>">
                                    <?php foreach (['access' => 'Access', 'trunk' => 'Trunk'] as $oltVlanMode => $oltVlanModeLabel): ?>
                                        <label class="olt-vlan-select-field"><span><?= h($oltVlanModeLabel) ?></span>
                                            <select name="<?= h($oltVlanMode) ?>_members[]" multiple data-select-search="Cari ..." data-select-label="Pilih interface <?= h($oltVlanModeLabel) ?>" aria-label="Interface <?= h($oltVlanModeLabel) ?>">
                                                <?php foreach ($oltVlanInterfaces as $oltVlanInterface => $oltVlanInterfaceLabel): ?><option value="<?= h($oltVlanInterface) ?>" <?= in_array($oltVlanInterface, $oltVlanMembersByMode[$oltVlanMode], true) ? 'selected' : '' ?>><?= h($oltVlanInterfaceLabel) ?></option><?php endforeach; ?>
                                            </select>
                                        </label>
                                    <?php endforeach; ?>
                                    <div class="olt-vlan-editor-actions"><button type="button" class="button secondary small" data-vlan-members-cancel>Batal</button><button class="button primary small" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> OK</button></div>
                                </form>
                            </div>
                        <?php endif; ?>
                    </details>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php $oltVlanModalId = 'oltVlanModal-' . $oltVlanDeviceId; $oltVlanOltRowId = $oltVlanDeviceId; require __DIR__ . '/modal.php'; ?>
