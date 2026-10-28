<?php
$oltProfileKind = (string)($oltProfileKind ?? '');
if ($oltProfileKind === 'onu_type') {
    require __DIR__ . '/onu-type-manager.php';
    return;
}
$oltProfileGroups = is_array($oltProfileGroups ?? null) ? $oltProfileGroups : [];
$oltProfileKinds = is_array($oltProfileKinds ?? null) ? array_values(array_filter($oltProfileKinds, 'is_string')) : [];
if ($oltProfileKinds === []) $oltProfileKinds = $oltProfileKind !== '' ? [$oltProfileKind] : [];
$oltProfileEmptyIcon = trim((string)($oltProfileEmptyIcon ?? '')) ?: 'bi-inbox';
$oltProfileEmptyText = trim((string)($oltProfileEmptyText ?? '')) ?: 'Belum ada data untuk ditampilkan.';
$oltProfileShowSubtitles = (bool)($oltProfileShowSubtitles ?? true);
$oltProfileMeta = [
    'ip' => ['WAN-IP Profile', 'bi-globe'],
    'traffic' => ['Traffic Profile', 'bi-speedometer2'],
    'tcont' => ['T-CONT Profile', 'bi-diagram-2'],
    'vlan_profile' => ['VLAN Profile', 'bi-diagram-3'],
];
$oltProfileColumns = [
    'vlan_profile' => [
        ['Tag Mode', 'tag_mode', 'field'],
        ['CVLAN', 'cvlan', 'field'],
        ['Priority', 'cvlan_priority', 'field'],
    ],
    'ip' => [
        ['Gateway', 'gateway', 'field'],
        ['Primary DNS', 'primary dns', 'field'],
        ['Secondary DNS', 'secondary dns', 'field'],
    ],
    'traffic' => [
        ['SIR (kbps)', 'sir_kbps', 'num'],
        ['PIR (kbps)', 'pir_kbps', 'num'],
        ['CBS (kbytes)', 'cbs_kbytes', 'num'],
        ['PBS (kbytes)', 'pbs_kbytes', 'num'],
    ],
    'tcont' => [
        ['Type', 'type', 'text'],
        ['FBW (kbps)', 'fbw_kbps', 'num'],
        ['ABW (kbps)', 'abw_kbps', 'num'],
        ['MBW (kbps)', 'mbw_kbps', 'num'],
        ['Priority', 'priority', 'text'],
        ['Weight', 'weight', 'text'],
    ],
];
$oltProfileEditKeys = [
    'traffic' => ['name', 'sir_kbps', 'pir_kbps', 'cbs_kbytes', 'pbs_kbytes'],
    'tcont' => ['name', 'type', 'fbw_kbps', 'abw_kbps', 'mbw_kbps'],
    'vlan_profile' => ['name', 'tag_mode', 'cvlan'],
    'ip' => ['name', 'gateway', 'primary_dns', 'secondary_dns'],
];
$oltProfileEmptyKindText = [
    'traffic' => 'No Traffic Profiles Found',
    'tcont' => 'No T-CONT Profiles Found',
    'vlan_profile' => 'No VLAN Profiles Found',
    'ip' => 'No related information to show',
];
?>
<?php
$oltTabActions = [];
$oltProfileAddKinds = array_values(array_filter($oltProfileKinds, static fn($kind) => isset($oltProfileEditKeys[$kind])));
if (count($oltProfileAddKinds) > 1) {
    $oltTabActions[] = ['type' => 'profile', 'value' => $oltProfileAddKinds[0], 'label' => 'Add'];
} else {
    foreach ($oltProfileAddKinds as $oltProfileAddKind) {
        $oltTabActions[] = ['type' => 'profile', 'value' => $oltProfileAddKind, 'label' => 'Add'];
    }
}
require __DIR__ . '/../tab-actions.php';
?>
<?php foreach ($oltProfileKinds as $oltProfileKindRow):
    $oltProfileRows = array_values(array_filter((array)($oltProfileGroups[$oltProfileKindRow] ?? []), 'is_array'));
    [$oltProfileTitle, $oltProfileIcon] = $oltProfileMeta[$oltProfileKindRow] ?? ['Profil', 'bi-diagram-3'];
    $oltProfileColumnsFor = $oltProfileColumns[$oltProfileKindRow] ?? [];
    $oltProfileEditFields = $oltProfileEditKeys[$oltProfileKindRow] ?? [];
    ?>
    <?php if ($oltProfileShowSubtitles): ?>
        <div class="tittle-subtitle mb-5">
            <h3 class="olt-onu-type-group-title"><i class="bi <?= h($oltProfileIcon) ?>" aria-hidden="true"></i> <?= h($oltProfileTitle) ?></h3>
        </div>
    <?php endif; ?>
    <?php if ($oltProfileRows === []): ?>
    <div class="olt-empty"><i class="bi <?= h($oltProfileEmptyIcon) ?>" aria-hidden="true"></i><strong>-</strong><span><?= h($oltProfileEmptyKindText[$oltProfileKindRow] ?? $oltProfileEmptyText) ?></span></div>
    <?php else: ?>
    <div class="olt-profile-wrap">
        <table class="olt-profile-table olt-service-profile-table" data-profile-kind="<?= h($oltProfileKindRow) ?>">
            <colgroup>
                <col class="profile-col-name">
                <?php foreach ($oltProfileColumnsFor as $oltProfileColumn): ?>
                    <col class="<?= in_array($oltProfileColumn[1], ['type', 'priority', 'weight', 'cvlan_priority'], true) ? 'profile-col-small' : 'profile-col-value' ?>">
                <?php endforeach; ?>
                <col class="profile-col-actions">
            </colgroup>
            <thead><tr><th>Nama</th><?php foreach ($oltProfileColumnsFor as $oltProfileColumn): ?><th><?= h($oltProfileColumn[0]) ?></th><?php endforeach; ?><th>Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($oltProfileRows as $oltProfileRow): ?>
                <?php
                $oltProfileData = (array)($oltProfileRow['data'] ?? []);
                $oltProfileName = $oltProfileData['name'] ?? $oltProfileRow['name'] ?? null;
                ?>
                <tr>
                    <td data-label="Nama"><b><?= h(OltDetailViewModel::text($oltProfileName)) ?></b></td>
                    <?php foreach ($oltProfileColumnsFor as $oltProfileColumn): ?>
                        <?php
                        $oltProfileKey = $oltProfileColumn[1];
                        $oltProfileType = $oltProfileColumn[2];
                        if ($oltProfileType === 'field') {
                            $oltProfileFields = (array)($oltProfileData['fields'] ?? []);
                            $oltProfileCell = OltDetailViewModel::text($oltProfileFields[$oltProfileKey] ?? $oltProfileData[$oltProfileKey] ?? null);
                            $oltProfileCellClass = '';
                        } elseif ($oltProfileType === 'num') {
                            $oltProfileCell = OltDetailViewModel::number($oltProfileData[$oltProfileKey] ?? null);
                            $oltProfileCellClass = 'olt-profile-num';
                        } else {
                            $oltProfileCell = OltDetailViewModel::text($oltProfileData[$oltProfileKey] ?? null);
                            $oltProfileCellClass = '';
                        }
                        if ($oltProfileKindRow === 'tcont' && strcasecmp(trim($oltProfileCell), 'N/A') === 0) {
                            $oltProfileCell = '-';
                        }
                        ?>
                        <td data-label="<?= h($oltProfileColumn[0]) ?>"<?= $oltProfileCellClass !== '' ? ' class="' . h($oltProfileCellClass) . '"' : '' ?>><?= h($oltProfileCell) ?></td>
                    <?php endforeach; ?>
                        <?php
                        $oltProfileEditData = [];
                        foreach ($oltProfileEditFields as $oltProfileEditKey) {
                            $oltProfileEditData[$oltProfileEditKey] = $oltProfileData[$oltProfileEditKey] ?? ($oltProfileEditKey === 'name' ? $oltProfileName : '');
                        }
                        ?>
                        <td data-label="Aksi"><div class="olt-profile-row-actions"><button type="button" class="button secondary small" data-olt-speed-kind="<?= h($oltProfileKindRow) ?>" data-olt-speed-edit="<?= json_for_html_attribute($oltProfileEditData) ?>" aria-label="Edit profil"><i class="bi bi-pencil" aria-hidden="true"></i></button><button type="button" class="button secondary small" data-olt-profile-delete data-olt-profile-kind="<?= h($oltProfileKindRow) ?>" data-olt-profile-name="<?= h((string)$oltProfileName) ?>" aria-label="Hapus profil"><i class="bi bi-trash" aria-hidden="true"></i></button></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
<?php endforeach;
