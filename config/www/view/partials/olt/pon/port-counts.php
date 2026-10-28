<?php
$ponSummaryTotalText = (string)($ponSummaryTotalText ?? 'N/A');
$ponSummaryCounts = is_array($ponSummaryCounts ?? null) ? $ponSummaryCounts : [];
$ponSummaryIncomplete = !empty($ponSummaryIncomplete);
?>
<div class="olt-port-counts">
    <div><span>Total PONs</span><strong><?= h($ponSummaryTotalText) ?></strong></div>
    <?php foreach (['Up', 'Down', 'Shutdown'] as $ponSummaryLabel): ?>
        <div><span><?= h($ponSummaryLabel) ?></span><strong><?= h($ponSummaryIncomplete ? 'N/A' : (string)($ponSummaryCounts[$ponSummaryLabel] ?? 0)) ?></strong></div>
    <?php endforeach; ?>
</div>
