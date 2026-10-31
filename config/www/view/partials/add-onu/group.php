<?php
$addOnuFamily = is_string($addOnuFamily ?? null) ? $addOnuFamily : 'external';
?>
<?php
$olt = $group['olt'];
$groupOnus = $group['onus'];
$oltName = (string)($olt['name'] ?? 'OLT');
$oltIp = trim((string)($olt['ip_address'] ?? ''));
?>
<details class="add-onu-olt-group">
    <summary class="add-onu-olt-head">
        <span class="add-onu-olt-icon" aria-hidden="true">
            <i class="bi bi-pc-horizontal"></i>
        </span>
        <span class="add-onu-olt-copy">
            <strong><?= h($oltName) ?><?= !empty($group['can_refresh_unconfigured']) ? ' · Uncfg' : '' ?></strong>
            <small><?= h($oltIp !== '' ? $oltIp : 'IP tidak tersedia') ?></small>
        </span>
        <span class="add-onu-olt-tail">
            <span class="add-onu-count" data-add-onu-count><?= count($groupOnus) ?> ONU</span>
            <i class="bi bi-chevron-down add-onu-accordion-chevron" aria-hidden="true"></i>
        </span>
    </summary>

    <div class="add-onu-olt-content">
        <?php if (!empty($group['can_refresh_unconfigured'])): ?>
            <div class="add-onu-group-actions">
                <button class="button secondary" type="button"
                    data-add-onu-unconfigured-refresh data-olt-row-id="<?= (int)($olt['id'] ?? 0) ?>"
                    data-csrf="<?= h(csrf_token()) ?>">
                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i><span>Refresh Uncfg</span>
                </button>
            </div>
        <?php endif; ?>
        <div class="add-onu-list">
            <div class="onu-search-status" data-add-onu-list-loading role="status" aria-label="Mencari ONU" hidden><span class="onu-loading-badge"><i class="bi bi-arrow-repeat sync-loading-spinner" aria-hidden="true"></i></span></div>
            <?php foreach ($groupOnus as $index => $onu): ?>
                <?php
                $rawName = trim((string)($onu['onu_name'] ?? ''));
                $onuName = $rawName !== '' ? display_onu_name($rawName) : 'Onu baru';
                $serial = display_onu_serial($onu['onu_serial'] ?? null);
                $onuIndex = trim((string)($onu['onu_index'] ?? ''));
                $pon = onu_pon_label($onu['physical'] ?? null, $onu['onu_index'] ?? null);
                $viewUrl = public_page_url('view-onu', ['id' => (int)($onu['id'] ?? 0)]);
                ?>
                <div class="add-onu-row">
                    <span class="add-onu-sequence"><?= $index + 1 ?></span>

                    <div class="add-onu-device">
                        <div class="add-onu-device-copy">
                            <div class="add-onu-device-heading">
                                <div class="add-onu-device-title">
                                    <strong><?= h($onuName) ?></strong>
                                    <?php if ($onuIndex !== ''): ?>
                                        <span class="mono add-onu-index pl-3"><i class="bi bi-diagram-3" aria-hidden="true"></i> <?= h($onuIndex) ?></span>
                                    <?php endif; ?>
                                </div>

                            </div>
                            <div class="add-onu-meta">
                                <span class="serial-copy"><span class="mono uppercase" data-serial-value><i class="bi bi-upc-scan" aria-hidden="true"></i> <?= h($serial) ?></span><span class="serial-copy-action text-muted"><button class="icon-button" type="button" data-copy-serial="<?= h($serial) ?>" aria-label="Salin serial <?= h($serial) ?>"><i class="bi bi-clipboard" aria-hidden="true"></i></button></span></span>

                            </div>
                        </div>
                    </div>


                    <div class="add-onu-actions">
                        <button
                            class="button primary"
                            type="button" 
                            data-add-onu-register
                            data-onu-row-id="<?= (int)($onu['id'] ?? 0) ?>"
                            data-onu-name="<?= h($rawName) ?>"
                            data-onu-location="<?= h(onu_location_value(display_onu_location($onu['onu_location'] ?? null, $onu['onu_name'] ?? null))) ?>"
                            data-onu-type="<?= h((string)($onu['onu_type'] ?? '')) ?>"
                            data-onu-serial="<?= h($serial) ?>"
                            data-onu-index="<?= h($onuIndex) ?>"
                            data-onu-pon="<?= h($pon) ?>"
                            data-onu-olt="<?= h($oltName) ?>"
                            data-onu-driver-id="<?= h((string)device_driver_type($olt)) ?>"
                            data-onu-family="<?= h($addOnuFamily) ?>"
                            data-onu-olt-id="<?= (int)($olt['id'] ?? 0) ?>">
                            <i class="bi  bi-lightning-fill" aria-hidden="true"></i>Add
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</details>