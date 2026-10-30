<?php
$oltPonExternalDescription = trim((string)($ponDescription ?? ''));
$oltPonExternalTxPower = is_numeric($ponTxPower ?? null) ? format_power($ponTxPower) : 'N/A';
?>
<div id="<?= h($ponModalId) ?>" class="modal olt-pon-modal" data-olt-pon-modal hidden aria-hidden="true">
    <div class="modal-card olt-pon-modal-card" role="dialog" aria-modal="true" aria-labelledby="<?= h($ponModalId) ?>-title">
        <div class="olt-pon-modal-heading modal-header">
            <div class="olt-pon-modal-title">
                <h2 id="<?= h($ponModalId) ?>-title"><?= h($ponCardTitle) ?></h2>
                <span class="olt-pon-link <?= h($stateClass) ?>"><i class="bi bi-circle-fill" aria-hidden="true"></i> <?= h($state) ?></span>
            </div>
            <button class="icon-button modal-close-button" type="button" data-olt-pon-modal-close aria-label="Tutup modal"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="olt-pon-modal-section">
            <dl class="olt-optical-details">
                <div><dt>Port</dt><dd><?= h($ponDisplayLabel ?? $ponRef) ?></dd></div>
                <div><dt>Status</dt><dd><?= h($state) ?></dd></div>
                <div><dt>Keterangan</dt><dd><?= h($oltPonExternalDescription !== '' ? $oltPonExternalDescription : '-') ?></dd></div>
                <div><dt>TX Power</dt><dd><?= h($oltPonExternalTxPower) ?></dd></div>
            </dl>
        </div>
    </div>
</div>
