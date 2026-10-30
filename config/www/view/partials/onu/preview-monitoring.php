<?php
$onuTrafficRowId = (int)($onuDetail['id'] ?? ($onu_row_id ?? 0));
$onuTrafficTitle = trim((string)($onuDetail['onu_name'] ?? ''));
if ($onuTrafficTitle === '') $onuTrafficTitle = 'Trafik ONU';
?>
<section class="pon-traffic onu-preview-traffic" aria-labelledby="onuTrafficTitle" data-traffic data-traffic-mode="onu"
         data-traffic-onu-id="<?= $onuTrafficRowId ?>" data-traffic-active="<?= (int)($onuDetail['is_present'] ?? 0) === 1
             && state_key($onuDetail['onu_state'] ?? null) === 'online'
             && strtoupper(trim((string)($onuDetail['driver_status'] ?? ''))) !== 'DOWN' ? 'true' : 'false' ?>">
    <header class="tittle-subtitle"><h3 id="onuTrafficTitle"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i> Trafik</h3></header>
    <div class="pon-traffic-ranges" role="group" aria-label="Rentang trafik ONU">
        <?php foreach ([180 => '3m', 1800 => '30m', 3600 => '1H', 86400 => '1d'] as $onuTrafficRange => $onuTrafficRangeLabel): ?>
            <button type="button" class="pon-traffic-range<?= $onuTrafficRange === 180 ? ' is-active' : '' ?>" data-traffic-range="<?= (int)$onuTrafficRange ?>"><?= h($onuTrafficRangeLabel) ?></button>
        <?php endforeach; ?>
    </div>
    <div class="pon-traffic-grid" data-traffic-grid>
        <?php
        $trafficCardRef = (string)$onuTrafficRowId;
        $trafficCardTitle = $onuTrafficTitle;
        $trafficCardIcon = 'bi-router';
        $trafficCardRole = 'onu';
        require project_path('view', 'partials', 'traffic-card.php');
        ?>
    </div>
    <p class="text-muted" data-traffic-state aria-live="polite" hidden></p>
</section>
