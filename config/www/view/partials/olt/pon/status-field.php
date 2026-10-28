<?php $ponStatusControl = OltDetailViewModel::ponSwitchState($ponConfigValues['olt_port_enable'] ?? null); ?>
<div class="olt-field field-switch">
    <span>Interface Status</span>
    <label class="pon-status-switch"><input type="checkbox" role="switch" data-pon-status-switch form="<?= h($ponModalId) ?>-status-form" data-form-id="<?= h($ponModalId) ?>-status-form"<?= $ponStatusControl['checked_attribute'] ?>><span data-pon-status-label><?= h($ponStatusControl['label']) ?></span></label>
</div>
<div class="olt-field field-switch">
    <span>Interface Graph</span>
    <label class="pon-catat-switch"><input type="checkbox" role="switch" aria-label="Interface Graph <?= h($ponDisplayLabel ?? $ponRef) ?>" data-traffic-record data-device-id="<?= (int)$oltDetail['id'] ?>" data-if-ref="<?= h($ponRef) ?>" data-csrf="<?= h(csrf_token()) ?>"<?= isset($oltTrafficSelected[$ponRef]) ? ' checked' : '' ?>><span data-pon-catat-label><?= isset($oltTrafficSelected[$ponRef]) ? 'Enable' : 'Disable' ?></span></label>
</div>
