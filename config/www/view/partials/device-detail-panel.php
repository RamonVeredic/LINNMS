<?php
$deviceDetailItems = is_array($deviceOverviewDetails ?? null) ? $deviceOverviewDetails : [];
$deviceDetailPanelId = trim((string)($deviceDetailPanelId ?? ''));
$deviceDetailAttributes = static function (array $attributes): string {
    $output = '';
    foreach ($attributes as $name => $value) {
        if (!is_string($name) || preg_match('/^(?:data|aria)-[a-z0-9_-]+$/', $name) !== 1 || $value === null || $value === false) {
            continue;
        }
        $output .= $value === true ? ' ' . $name : ' ' . $name . '="' . h((string)$value) . '"';
    }
    return $output;
};
?>
<section class="panel device-system-info-panel"<?= $deviceDetailPanelId !== '' ? ' id="' . h($deviceDetailPanelId) . '"' : '' ?>>
    <div class="panel-heading"><h2>Detail perangkat</h2></div>
    <div class="device-system-info-list">
        <?php foreach ($deviceDetailItems as $detail): ?>
            <?php if (!is_array($detail)) continue; ?>
            <div>
                <span><?= h((string)($detail['label'] ?? '')) ?></span>
                <span class="device-info-value <?= h((string)($detail['class'] ?? '')) ?>"<?= $deviceDetailAttributes((array)($detail['attributes'] ?? [])) ?>><?= h((string)($detail['value'] ?? 'N/A')) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</section>
