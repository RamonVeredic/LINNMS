<?php
$onuTypePon = (string)($onuTypeValues['pon_type'] ?? '');
$onuTypeLocal = (bool)($onuTypeLocal ?? false);
$onuTypeFields = (array)($onuTypeValues['gpon_values'] ?? []);
?>
<div class="olt-onu-type-fields olt-onu-type-device-fields">
    <?php if ($onuTypeIsCreate): ?>
        <label class="olt-onu-type-name-field"><span>ONU Type</span><input type="text" name="name" maxlength="64" required autocomplete="off" placeholder="Masukkan Tipe ONU" data-olt-onu-type-name></label>
    <?php endif; ?>
    <label class="olt-onu-type-pon-field"><span>Jenis PON</span>
        <?php if ($onuTypeIsCreate || $onuTypeLocal): ?>
            <select name="pon_type" required data-onu-type-pon data-select-search="Cari ..." aria-label="Jenis PON"><?php if ($onuTypePon === ''): ?><option value="" selected disabled>-</option><?php endif; ?><option value="gpon"<?= $onuTypePon === 'gpon' ? ' selected' : '' ?>>GPON</option><option value="epon"<?= $onuTypePon === 'epon' ? ' selected' : '' ?>>EPON</option></select>
        <?php else: ?>
            <input type="text" name="pon_type" value="<?= h($onuTypePon) ?>" readonly aria-label="Jenis PON">
        <?php endif; ?>
    </label>
    <?php if ($onuTypeLocal): ?>
        <?php foreach (['ethernet_port' => 'Ethernet', 'wifi_ssid' => 'Wi-Fi', 'pots_port' => 'POTS/VoIP'] as $onuTypeFeatureKey => $onuTypeFeatureLabel): ?>
            <label><span><?= h($onuTypeFeatureLabel) ?></span><input type="text" name="<?= h($onuTypeFeatureKey) ?>" value="<?= h((string)($onuTypeValues[$onuTypeFeatureKey] ?? '')) ?>" maxlength="64" placeholder="-"></label>
        <?php endforeach; ?>
    <?php else: ?>
    <div class="olt-onu-type-protocol-fields" data-onu-type-gpon <?= $onuTypePon !== 'gpon' ? 'hidden' : '' ?>>
        <?php $onuTypeIpSpec = $onuTypeManager['gpon_fields']['max_iphost'] ?? null; ?>
        <?php if ($onuTypeIpSpec !== null): ?><label class="olt-onu-type-ip-field"><span><?= h($onuTypeIpSpec['label']) ?></span><input type="number" name="max_iphost" min="<?= $onuTypeIpSpec['min'] ?>" max="<?= $onuTypeIpSpec['max'] ?>" step="1" value="<?= h((string)($onuTypeFields['max_iphost'] ?? '')) ?>" placeholder="Default" <?= $onuTypePon !== 'gpon' ? 'disabled' : '' ?>></label><?php endif; ?>
        <?php foreach ($onuTypeManager['gpon_fields'] as $onuTypeKey => $onuTypeFieldSpec): ?>
            <?php if ($onuTypeKey === 'max_iphost') continue; ?>
            <label><span><?= h($onuTypeFieldSpec['label']) ?></span><input type="number" name="<?= h($onuTypeKey) ?>" min="<?= $onuTypeFieldSpec['min'] ?>" max="<?= $onuTypeFieldSpec['max'] ?>" step="1" value="<?= h((string)($onuTypeFields[$onuTypeKey] ?? '')) ?>" placeholder="Default" <?= $onuTypePon !== 'gpon' ? 'disabled' : '' ?>></label>
        <?php endforeach; ?>
    </div>
    <div class="olt-onu-type-protocol-fields" data-onu-type-epon <?= $onuTypePon !== 'epon' ? 'hidden' : '' ?>>
        <?php foreach (['speed' => ['Speed', 'speed', ['1g', '10g-symmetric', '10g-asymmetric']], 'protect_type' => ['Proteksi', 'protect type', ['none', 'typec', 'typed']]] as $onuTypeKey => [$onuTypeLabel, $onuTypeResponse, $onuTypeOptions]): ?>
            <label><span><?= h($onuTypeLabel) ?></span><select name="<?= h($onuTypeKey) ?>" data-select-search="Cari <?= h($onuTypeLabel) ?>" aria-label="<?= h($onuTypeLabel) ?>" <?= $onuTypePon !== 'epon' ? 'disabled' : '' ?>><option value="">Default</option><?php foreach ($onuTypeOptions as $onuTypeOption): ?><option value="<?= h($onuTypeOption) ?>" <?= ($onuTypeValues[$onuTypeKey] ?? '') === $onuTypeOption ? 'selected' : '' ?>><?= h($onuTypeOption) ?></option><?php endforeach; ?></select></label>
        <?php endforeach; ?>
    </div>
    <?php if ($onuTypeIsCreate || !empty($onuTypeValues['editable'])): ?>
        <?php foreach (['eth' => 'Ethernet', 'wifi' => 'Wi-Fi', 'pots' => 'VoIP'] as $onuTypeInterfaceKind => $onuTypeLabel): ?>
            <label class="olt-onu-type-interface-field olt-onu-type-interface-<?= h($onuTypeInterfaceKind) ?>"><span><?= h($onuTypeLabel) ?></span><input type="text" name="<?= h($onuTypeInterfaceKind) ?>_interfaces" value="<?= h((string)($onuTypeValues['interface_values'][$onuTypeInterfaceKind] ?? '')) ?>" placeholder="Masukkan Interface" maxlength="4096" aria-label="Interface <?= h($onuTypeLabel) ?>"></label>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php endif; ?>
    <label class="olt-onu-type-description-field"><span>Deskripsi</span><textarea name="description" maxlength="<?= $onuTypeLocal ? 255 : 64 ?>" rows="2" placeholder="<?= $onuTypeIsCreate ? 'Masukkan Deskripsi' : '-' ?>" <?= !$onuTypeLocal && !$onuTypeIsCreate && (string)($onuTypeValues['description'] ?? '') !== '' ? 'required' : '' ?>><?= h((string)($onuTypeValues['description'] ?? '')) ?></textarea></label>
</div>
