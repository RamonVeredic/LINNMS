<?php
$onuTrafficRowId = (int)($onuDetail['id'] ?? ($onu_row_id ?? 0));
$onuTrafficTitle = trim((string)($onuDetail['onu_name'] ?? ''));
if ($onuTrafficTitle === '') $onuTrafficTitle = 'Trafik ONU';
?>
<section class="pon-traffic onu-preview-traffic" aria-labelledby="onuTrafficTitle" data-traffic data-traffic-mode="onu"
         data-traffic-onu-id="<?= $onuTrafficRowId ?>">
    <div class="panel-heading onu-preview-heading">
        <div class="tittle-subtitle"><h2 id="onuTrafficTitle" class="onu-sub-block-title"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i> Trafik</h2></div>
    </div>
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
        require project_path('pages', 'partials', 'traffic-card.php');
        ?>
    </div>
    <p class="text-muted" data-traffic-state aria-live="polite" hidden></p>
</section>
