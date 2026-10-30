<?php

$onuView = is_array($onuView ?? null) ? $onuView : [];
if (empty($onuView['ok'])) {
    ?>
    <section class="panel">
        <header class="tittle-subtitle">
            <div>
                <h2>ONU tidak ditemukan</h2>
                <p>Data ONU tidak tersedia.</p>
            </div>
        </header>
    </section>
    <?php
    return;
}
extract($onuView);

$onuTemperatureClass = !is_numeric($temp ?? null) || (float)$temp === 0.0
    ? 'temperature-unknown'
    : ((float)$temp >= 50 ? 'temperature-hot' : ((float)$temp >= 40 ? 'temperature-warn' : 'temperature-ok'));

?>

<?php if ($rebootPending): ?>
    <section class="panel reboot-panel">
        <header class="tittle-subtitle">
            <div>
                <h3><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Reboot</h3>
                <p>Menunggu ONU kembali aktif.</p>
            </div>
            <span class="status warning"><?= (int)$rebootElapsed ?> detik</span>
        </header>
    </section>
<?php endif; ?>

<div class="onu-view">
<section class="panel onu-detail-hero">
    <div class="onu-detail-hero-main">
        <div class="onu-detail-icon">
            <i class="bi bi-router" aria-hidden="true"></i>
        </div>
        <div>
            <h2><?= h($name) ?></h2>
                <div class="text-muted onu-detail-state <?= h($displayStateClass) ?>"> 
                    
                       <?php if ($rebootPending): ?>Rebooting<?php else: ?>
                           <span class="onu-olt-name"><?= h($oltName) ?></span>
                          
                           —
                          <span class="status-dot <?= h($displayDotClass) ?>" aria-hidden="true"></span>

                           <span class="onu-operational-state"><?= h($operationalStateLabel) ?></span><?php if ($onuIsProblem): ?>
                           <?php endif; ?>
                       <?php endif; ?>
                </div>
                <?php if ($onuIsProblem && trim((string)$onuProblemDescription) !== ''): ?>
                    <small class="onu-problem-description"><?= h((string)$onuProblemDescription) ?></small>
                <?php endif; ?>
        </div>
    </div>
    <div class="onu-detail-hero-meta">
        <button class="button secondary" type="button" data-onu-modal-open="onuActionModal" <?= $rebootPending ? 'disabled' : '' ?>>
            <i class="bi bi-sliders" aria-hidden="true"></i><span class="btn-text">Tindakan</span>
        </button>
    </div>
</section>

<div class="onu-detail-layout">
        <section class="panel onu-info-panel">
            <header class="tittle-subtitle"><h3>Informasi</h3></header>
            <form method="post" data-onu-edit-form>
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_onu_control">
                <input type="hidden" name="onu_row_id" value="<?= (int)$onuView['id'] ?>"><input type="hidden" name="olt_device_id" value="<?= (int)($onuView['olt_device_id'] ?? 0) ?>"><input type="hidden" name="driver_id" value="<?= h((string)device_driver_type($onuView)) ?>">
                <div class="onu-info-list">

                    <div>
                        <div class="onu-info-label-row"><span class="label">Nama</span><button class="onu-field-edit-button" type="button" data-onu-field-edit aria-label="Edit nama ONU" title="Edit nama ONU" <?= $rebootPending ? 'disabled' : '' ?>><i class="bi bi-pencil" aria-hidden="true"></i></button><button class="onu-field-edit-button" type="button" data-onu-field-cancel style="display:none" aria-label="Batal edit" title="Batal edit"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
                        <div class="value">
                            <div class="onu-field-edit-shell onu-inline-edit-shell">
                                <input class="onu-inline-edit-control" name="onu_name" maxlength="30" required value="<?= h($name === '—' ? '' : $name) ?>" placeholder="—" data-onu-edit-control disabled aria-label="Nama ONU">
                            </div>
                        </div>
                    </div>
                    <div>
                        <div class="onu-info-label-row"><span class="label">Lokasi</span><button class="onu-field-edit-button" type="button" data-onu-field-edit aria-label="Edit lokasi ONU" title="Edit lokasi ONU" <?= $rebootPending ? 'disabled' : '' ?>><i class="bi bi-pencil" aria-hidden="true"></i></button><button class="onu-field-edit-button" type="button" data-onu-field-cancel style="display:none" aria-label="Batal edit" title="Batal edit"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
                        <div class="value"><span class="onu-field-edit-shell onu-inline-edit-shell"><input class="onu-inline-edit-control" name="onu_location" maxlength="255" value="<?= h(onu_location_value($location)) ?>" placeholder="—" data-onu-edit-control disabled aria-label="Lokasi ONU"></span></div>
                    </div>
                    <div>
                        <div class="onu-info-label-row"><span class="label">Register</span><button class="onu-field-edit-button" type="button" data-onu-field-edit aria-label="Edit status register" title="Edit status register" <?= $rebootPending || $authMode === 'uncfg' ? 'disabled' : '' ?>><i class="bi bi-pencil" aria-hidden="true"></i></button><button class="onu-field-edit-button" type="button" data-onu-field-cancel style="display:none" aria-label="Batal edit" title="Batal edit"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
                        <div class="value">
                            <span class="onu-field-edit-shell onu-field-edit-select onu-inline-edit-shell"><select class="onu-inline-edit-control auth-<?= h($authIndicatorClass) ?>" name="auth_mode" required data-onu-edit-control disabled aria-label="Status register ONU"><?php if ($authMode === 'uncfg'): ?><option value="uncfg" selected disabled><?= h($registerLabels['uncfg'] ?? 'Uncfg') ?></option><?php endif; ?><?php if ($authMode === '—'): ?><option value="" selected disabled>—</option><?php endif; ?><?php foreach (['Undef', 'Pass', 'Deny'] as $mode): ?><option value="<?= h($mode) ?>" <?= strcasecmp($authMode, $mode) === 0 ? 'selected' : '' ?>><?= h($registerLabels[$mode]) ?></option><?php endforeach; ?></select></span>
                        </div>
                    </div>

                    <div>
                        <div class="onu-info-label-row"><span class="label">Serial ONU</span><?php if (!in_array($authMode, ['Undef', 'uncfg'], true)): ?><button class="onu-field-edit-button" type="button" data-onu-field-edit aria-label="Ganti Serial ONU" title="Ganti Serial ONU" <?= $rebootPending ? 'disabled' : '' ?>><i class="bi bi-pencil" aria-hidden="true"></i></button><button class="onu-field-edit-button" type="button" data-onu-field-cancel style="display:none" aria-label="Batal ganti Serial ONU" title="Batal ganti Serial ONU"><i class="bi bi-x-lg" aria-hidden="true"></i></button><?php endif; ?></div>
                        <div class="value">
                            <?php if (!in_array($authMode, ['Undef', 'uncfg'], true)): ?>
                                <span class="onu-field-edit-shell onu-field-edit-select onu-inline-edit-shell"><select class="onu-inline-edit-control" name="replacement_onu_row_id" data-select-checkbox data-select-menu-class="onu-select-menu" data-onu-edit-control data-onu-serial-replacement data-select-search="Cari serial..." data-select-icon="bi bi-upc-scan" required disabled aria-label="Serial ONU"><option value="" selected disabled><?= h($serial) ?></option><optgroup label="Serial pengganti"><?php foreach ($replacementOnus as $replacementOnu): ?><?php $replacementSerial = display_onu_serial($replacementOnu['onu_serial'] ?? null); $replacementIndex = trim((string)($replacementOnu['onu_index'] ?? '')); ?><option value="<?= (int)$replacementOnu['id'] ?>"><?= h($replacementSerial . ($replacementIndex !== '' ? ' · ' . $replacementIndex : '')) ?></option><?php endforeach; ?></optgroup></select></span>
                            <?php else: ?><span class="onu-info-data mono"><?= h($serial) ?></span><?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <div class="onu-info-label-row"><span class="label">ONU Type</span><?php if ($onuTypeChoices !== []): ?><button class="onu-field-edit-button" type="button" data-onu-field-edit aria-label="Edit ONU Type" title="Edit ONU Type"<?= $rebootPending ? ' disabled' : '' ?>><i class="bi bi-pencil" aria-hidden="true"></i></button><button class="onu-field-edit-button" type="button" data-onu-field-cancel style="display:none" aria-label="Batal edit ONU Type" title="Batal edit ONU Type"><i class="bi bi-x-lg" aria-hidden="true"></i></button><?php endif; ?></div>
                        <div class="value">
                            <?php if ($onuTypeChoices !== []): ?>
                                <span class="onu-field-edit-shell onu-field-edit-select onu-inline-edit-shell">
                                    <select class="onu-inline-edit-control" name="onu_type" required data-select-checkbox data-select-menu-class="onu-select-menu" data-onu-edit-control data-select-search="Cari ONU Type..." disabled aria-label="ONU Type">
                                        <?php if (!in_array($onuType, $onuTypeChoices, true)): ?><option value="" selected disabled><?= h($onuType) ?></option><?php endif; ?>
                                        <?php foreach ($onuTypeChoices as $choice): ?><option value="<?= h($choice) ?>"<?= $choice === $onuType ? ' selected' : '' ?>><?= h($choice) ?></option><?php endforeach; ?>
                                    </select>
                                </span>
                            <?php else: ?><span class="onu-info-data"><?= h($onuType) ?></span><?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <span class="label">Aktual ONU</span>
                        <div class="value">
                            <span class="onu-info-data"><?= h($equipmentId) ?></span>
                        </div>
                    </div>
                </div>
            </form>
        </section>

        <?php require __DIR__ . '/preview-history.php'; ?>

        <div class="onu-port-traffic-group">
            <section class="panel onu-port-panel">
            <header class="tittle-subtitle"><h3>Port</h3></header>
            <div class="onu-port-layout" data-onu-ethernet data-onu-row-id="<?= (int)($onuView['id'] ?? 0) ?>">
                <div class="optical-card optic-<?= h($optic['class']) ?>">

                    <div class="optical-icon"><i class="bi bi-broadcast" aria-hidden="true"></i></div>
                    <span class="optical-value"><?= h(onu_rx_power_display($rx)) ?></span>
                </div>
                <?php if (!empty($onuViewCapabilities['onu_port_count_includes_optical']) || !empty($onuViewCapabilities['onu_ethernet'])): ?>
                    <div data-onu-ethernet-body>
                        <?php require __DIR__ . '/ports-counted.php'; ?>
                    </div>
                <?php endif; ?>
            </div>
            </section>

            <?php if (!empty($onuViewCapabilities['onu_traffic'])): ?>
            <?php require __DIR__ . '/preview-monitoring.php'; ?>
            <?php endif; ?>
        </div>

        <?php require __DIR__ . '/preview-service-cards.php'; ?>

        <?php if (!empty($onuViewCapabilities['onu_services_preview'])) require __DIR__ . '/preview-services.php'; ?>

        <section class="panel onu-detail-panel">
            <header class="tittle-subtitle"><h3>Detail Onu</h3></header>
            <div class="detail-list onu-detail-list">
                <div>
                    <span class="label">Index ONU</span>
                    <span class="value mono"><?= h($valueDetail['index']['value']) ?></span></div>
                <div>
                    <span class="label">Suhu Modul</span>
                    <span class="value <?= h($onuTemperatureClass) ?>"><i class="bi bi-thermometer-half" aria-hidden="true"></i>  <?= h($valueDetail['temperature']['value']) ?></span></div>
                <div>
                    <span class="label"><?= $valueDetail['olt_rx_power']['state'] !== 'unsupported' ? 'RxPwr / RxOlt' : 'RxPwr' ?></span>
                    <span class="value"><?= h($rxPairDisplay) ?></span>
                </div>
                <div>
                    <span class="label">TxPwr</span>
                    <span class="value"><?= h($valueDetail['tx_power']['value']) ?></span>
                </div>
               <div>
                    <span class="label">Distance</span>
                    <span class="value"><?= h($valueDetail['distance']['value']) ?></span>
                </div>
                <div>
                    <span class="label">Port</span>
                    <span class="value"><?= h($valueDetail['port_count']['value']) ?></span>
                </div>
                <div>
                    <span class="label">Up Time</span>
                    <span class="value"><?= h($valueDetail['running_time']['value']) ?></span>
                </div>
                <div>
                    <span class="label">Regist Time</span>
                    <span class="value"><?= h($valueDetail['register_time']['value']) ?></span>
                </div>
                <div>
                    <span class="label">Deregist Time</span>
                    <span class="value"><?= h($valueDetail['deregister_time']['value']) ?></span>
                </div>
                <div>
                    <span class="label">Deregist Count</span>
                    <span class="value"><?= h($valueDetail['deregister_count']['value']) ?></span>
                </div>

            </div>
        </section>

</div>



</div>

<?php if ($authMode !== 'Undef'): ?>
<div id="onuSerialModal" class="modal onu-control-modal" hidden aria-hidden="true">
    <div class="modal-card onu-control-modal-card" role="dialog" aria-modal="true" aria-labelledby="onuSerialModalTitle">
        <div class="onu-control-modal-heading modal-header">
            <div>
                <h2 id="onuSerialModalTitle">Serial ONU</h2>
             </div>
            <button class="icon-button modal-close-button" type="button" data-onu-modal-close aria-label="Tutup modal"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>

        <form method="post" class="onu-control-modal-form" onsubmit="return confirm('Ganti Serial ONU ini? Serial lama akan kembali menjadi belum terdaftar.');">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="replace_onu_registration">
            <input type="hidden" name="onu_row_id" value="<?= (int)$onuView['id'] ?>"><input type="hidden" name="olt_device_id" value="<?= (int)($onuView['olt_device_id'] ?? 0) ?>"><input type="hidden" name="driver_id" value="<?= h((string)device_driver_type($onuView)) ?>">

            <div class="field">
                <label for="onuCurrentSerial">Serial Saat Ini</label>
                <input id="onuCurrentSerial" class="mono" value="<?= h($serial) ?>" readonly aria-readonly="true">
            </div>
            <div class="field">
                <label for="onuReplacementSerial">Serial Pengganti</label>
                <select id="onuReplacementSerial" name="replacement_onu_row_id" data-select-label="Pilih serial belum terdaftar" data-select-icon="bi bi-upc-scan" data-select-search="Cari ..." required>
                    <option value="" selected disabled><?= $replacementOnus === [] ? 'Tidak ada Serial yang belum terdaftar' : 'Pilih Serial belum terdaftar' ?></option>
                    <?php foreach ($replacementOnus as $replacementOnu): ?>
                        <?php
                        $replacementSerial = display_onu_serial($replacementOnu['onu_serial'] ?? null);
                        $replacementIndex = trim((string)($replacementOnu['onu_index'] ?? ''));
                        ?>
                        <option value="<?= (int)$replacementOnu['id'] ?>"><?= h($replacementSerial . ($replacementIndex !== '' ? ' · ' . $replacementIndex : '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="onu-control-modal-actions">
                <button class="button secondary" type="button" data-onu-modal-close>Batal</button>
                <button class="button primary" type="submit" <?= $rebootPending ? 'disabled' : '' ?>><i class="bi bi-arrow-left-right" aria-hidden="true"></i>Ganti Serial</button>
            </div>
        </form>

        <form method="post" class="onu-control-modal-form" data-device-delete-form>
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="unregister_onu">
            <input type="hidden" name="onu_row_id" value="<?= (int)$onuView['id'] ?>"><input type="hidden" name="olt_device_id" value="<?= (int)($onuView['olt_device_id'] ?? 0) ?>"><input type="hidden" name="driver_id" value="<?= h((string)device_driver_type($onuView)) ?>">
            <div class="onu-control-modal-actions">
                <button class="button button-danger" type="submit" <?= $rebootPending ? 'disabled' : '' ?>><i class="bi bi-trash" aria-hidden="true"></i></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div id="onuConfirmModal" class="modal onu-control-modal" hidden aria-hidden="true">
    <div class="modal-card onu-control-modal-card" role="dialog" aria-modal="true" aria-labelledby="onuConfirmModalTitle">
        <div class="onu-control-modal-heading modal-header">
            <div>
                <h2 id="onuConfirmModalTitle">Konfirmasi ONU</h2>
            </div>
            <button class="icon-button modal-close-button" type="button" data-onu-modal-close aria-label="Tutup modal"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <form method="post" class="onu-control-modal-form">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_onu_control">
            <input type="hidden" name="onu_row_id" value="<?= (int)$onuView['id'] ?>"><input type="hidden" name="olt_device_id" value="<?= (int)($onuView['olt_device_id'] ?? 0) ?>"><input type="hidden" name="driver_id" value="<?= h((string)device_driver_type($onuView)) ?>">
            <div class="field"><label for="onuConfirmName">Nama ONU</label><input id="onuConfirmName" name="onu_name" maxlength="30" required value="<?= h($name === '—' ? '' : $name) ?>" <?= $rebootPending ? 'disabled' : '' ?>></div>
            <div class="field"><label for="onuConfirmLocation">Alamat / Lokasi</label><input id="onuConfirmLocation" name="onu_location" maxlength="255" value="<?= h(onu_location_value($location)) ?>" placeholder="—" <?= $rebootPending ? 'disabled' : '' ?>></div>
            <div class="onu-control-modal-actions">
                <button class="button secondary" type="button" data-onu-modal-close>Batal</button>
                <button class="button primary" <?= $rebootPending ? 'disabled' : '' ?>><i class="bi bi-floppy" aria-hidden="true"></i>Konfirmasi</button>
            </div>
        </form>
    </div>
</div>

<div id="onuActionModal" class="modal onu-control-modal" hidden aria-hidden="true">
    <div class="modal-card onu-control-modal-card" role="dialog" aria-modal="true" aria-labelledby="onuActionModalTitle">
        <div class="onu-control-modal-heading modal-header">
            <div><h2 id="onuActionModalTitle">Tindakan</h2></div>
            <button class="icon-button modal-close-button" type="button" data-onu-modal-close aria-label="Tutup modal"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <form method="post" class="onu-control-modal-form" data-onu-action-form onsubmit="const operation=event.submitter?.value;if(!operation)return false;this.elements.action.value=operation==='deleteOp'?'delete_onu':'onu_operation';return operation==='deleteOp'||confirm('Jalankan '+event.submitter.textContent.trim()+' pada ONU ini?');">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="onu_operation">
            <input type="hidden" name="onu_row_id" value="<?= (int)$onuView['id'] ?>"><input type="hidden" name="olt_device_id" value="<?= (int)($onuView['olt_device_id'] ?? 0) ?>"><input type="hidden" name="driver_id" value="<?= h((string)device_driver_type($onuView)) ?>">
            <div class="onu-control-modal-actions">
                <?php foreach ((array)($onuViewCapabilities['onu_actions'] ?? []) as $onuAction): ?>
                    <?php $supported = !empty($onuAction['supported']); $danger = !empty($onuAction['danger']); ?>
                    <button class="button <?= $danger ? 'button-danger' : 'secondary' ?><?= $supported ? '' : ' is-pending-support' ?>" type="submit" name="operation" value="<?= h((string)$onuAction['operation']) ?>"<?= $danger ? ' data-device-delete-trigger' : '' ?><?= $rebootPending || !$supported ? ' disabled' : '' ?><?= $supported ? '' : ' title="Belum didukung driver ini"' ?>><?= h((string)$onuAction['label']) ?></button>
                <?php endforeach; ?>
                <button class="button secondary" type="button" data-onu-modal-close>Batal</button>
            </div>
            <?php if (array_filter((array)($onuViewCapabilities['onu_actions'] ?? []), static fn(array $action): bool => empty($action['supported'])) !== []): ?>
                <p class="onu-action-pending-note">Tombol kuning belum didukung driver ini</p>
            <?php endif; ?>
        </form>
        </form>
    </div>
</div>

<?php if ($warnings !== []): ?>
<section class="panel">
    <header class="tittle-subtitle"><h3>Informasi ONU</h3></header>
    <div class="notice"><i class="bi bi-info-circle" aria-hidden="true"></i> Sebagian informasi ONU tidak dapat ditampilkan.</div>
</section>
<?php endif; ?>
