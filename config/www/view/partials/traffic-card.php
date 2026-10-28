<?php
$trafficCardRef = (string)($trafficCardRef ?? '');
$trafficCardTitle = trim((string)($trafficCardTitle ?? ''));
if ($trafficCardTitle === '') $trafficCardTitle = $trafficCardRef;
$trafficCardIcon = trim((string)($trafficCardIcon ?? ''));
if ($trafficCardIcon === '') $trafficCardIcon = 'bi-hdd-network';
$trafficCardRole = trim((string)($trafficCardRole ?? ''));
?>
<article class="pon-traffic-card" data-traffic-card data-traffic-ref="<?= h($trafficCardRef) ?>" data-traffic-role="<?= h($trafficCardRole) ?>">
    <header class="pon-traffic-card-head">
        <span class="traffic-card-title"><i class="bi <?= h($trafficCardIcon) ?>" aria-hidden="true"></i> <span class="mono"><?= h($trafficCardTitle) ?></span></span>
        <span class="pon-traffic-values">
            <small class="pon-traffic-rx"><i class="bi bi-cloud-download" aria-hidden="true"></i> <b data-traffic-rx>—</b></small>
            <small class="pon-traffic-tx"><i class="bi bi-cloud-upload" aria-hidden="true"></i> <b data-traffic-tx>—</b></small>
        </span>
    </header>
    <div class="pon-traffic-canvas-wrap">
        <span class="pon-traffic-loading" data-traffic-loading><span class="onu-loading-badge"><i class="bi bi-arrow-repeat sync-loading-spinner" aria-hidden="true"></i></span></span>
        <canvas class="pon-traffic-canvas" data-traffic-canvas height="150"></canvas>
        <div class="traffic-tooltip" data-traffic-tooltip hidden aria-hidden="true">
            <span data-traffic-tooltip-time></span>
            <span><i class="bi bi-cloud-download" aria-hidden="true"></i><span data-traffic-tooltip-rx></span></span>
            <span><i class="bi bi-cloud-upload" aria-hidden="true"></i><span data-traffic-tooltip-tx></span></span>
        </div>
    </div>
</article>
