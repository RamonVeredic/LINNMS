<?php
$ponCatatTerpilih = is_array($oltTrafficSelected ?? null) ? $oltTrafficSelected : [];
?>
<div id="<?= h($ponModalId) ?>" class="modal olt-pon-modal" hidden aria-hidden="true" data-olt-pon-modal<?= $ponIsFocused ? ' data-olt-pon-modal-focused' : '' ?>>
    <div class="modal-card olt-pon-modal-card" role="dialog" aria-modal="true" aria-labelledby="<?= h($ponModalId) ?>-title">
        <div class="olt-pon-modal-heading modal-header">
            <div class="olt-pon-modal-title">
                <h2 id="<?= h($ponModalId) ?>-title"><?= h($ponCardTitle) ?></h2>
                <span class="olt-pon-link <?= h($stateClass) ?>"><i class="bi bi-circle-fill" aria-hidden="true"></i> <?= h($ponDisplayLabel ?? $ponRef ?? $ref) ?></span>
            </div>
            <button class="icon-button modal-close-button" type="button" data-olt-pon-modal-close aria-label="Tutup modal"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>

        <?php require __DIR__ . '/status-form.php'; ?>

        <div class="olt-pon-modal-form">
            <section class="olt-pon-modal-section">
                <h3><i class="bi bi-gear" aria-hidden="true"></i> PON</h3>
                <div class="olt-pon-status-row">
                    <?php require __DIR__ . '/status-field.php'; ?>
                </div>
                <form method="post" class="pon-description-form" data-olt-pon-edit-form data-pon-description-form>
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_olt_pon_modal">
                    <input type="hidden" name="olt_row_id" value="<?= (int)$oltDetail['id'] ?>">
                    <input type="hidden" name="olt_port_number" value="<?= $ponNumber > 0 ? $ponNumber : 1 ?>">
                    <input type="hidden" name="olt_port_ref" value="<?= h($ponRef) ?>">
                    <input type="hidden" name="olt_port_previous_description" value="<?= h($ponDescription) ?>">
                    <div class="olt-field field-input">
                        <div class="onu-info-label-row">
                            <label for="<?= h($ponModalId) ?>-description">Description</label>
                            <button class="onu-field-edit-button" type="button" data-olt-pon-edit data-edit-label="deskripsi" aria-label="Edit deskripsi" title="Edit deskripsi"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                            <button class="onu-field-edit-button" type="button" data-olt-pon-cancel aria-label="Batal edit deskripsi" title="Batal edit deskripsi" hidden><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                        </div>
                        <div>
                            <span data-olt-pon-edit-display><?= h($ponDescription !== '' ? $ponDescription : '—') ?></span>
                            <input type="text" id="<?= h($ponModalId) ?>-description" name="olt_port_description" maxlength="128" value="<?= h($ponDescription) ?>" autocomplete="off" hidden disabled data-olt-pon-edit-control>
                            <small><span data-olt-description-count><?= function_exists('mb_strlen') ? mb_strlen($ponDescription) : strlen($ponDescription) ?></span>/128 karakter</small>
                        </div>
                    </div>
                </form>
            </section>

            <section class="olt-pon-modal-section">
                <h3><i class="bi bi-activity" aria-hidden="true"></i> Optical Module SFP</h3>
                <dl class="olt-optical-details">
                    <?php foreach ($ponOpticalRows as $oltOpticalRow): ?>
                        <div><dt><?= h($oltOpticalRow['label']) ?></dt><dd class="<?= h($oltOpticalRow['class']) ?>"><?= h($oltOpticalRow['value']) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            </section>
        </div>
    </div>
</div>
