<?php

$deviceOverviewHero ??= [];
$deviceOverviewHealth ??= [];
$deviceOverviewStats ??= [];
$deviceOverviewDetails ??= [];
$deviceOverviewWarning ??= '';
$deviceOverviewStatClass ??= '';

$deviceOverviewAttributes = static function (array $attributes): string {
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
<?php
$deviceOverviewEditUrl = trim((string)($deviceOverviewHero['edit_url'] ?? ''));
$deviceOverviewActions = trim((string)($deviceOverviewHero['actions'] ?? ''));
?>
<section class="panel device-detail-hero"<?= $deviceOverviewAttributes((array)($deviceOverviewHero['attributes'] ?? [])) ?>>
    <div class="device-detail-hero-main">
        <div class="device-detail-icon"><i class="bi <?= h((string)($deviceOverviewHero['icon'] ?? 'bi-hdd-network')) ?>" aria-hidden="true"></i></div>
        <div class="device-detail-title-copy">
            <h2><?= h(display_text($deviceOverviewHero['name'] ?? null)) ?></h2>
            <p><?= h(display_text($deviceOverviewHero['subtitle'] ?? null)) ?></p>
        </div>
    </div>
    <?php if ($deviceOverviewEditUrl !== '' || $deviceOverviewActions !== ''): ?>
        <div class="device-detail-hero-actions">
            <?= $deviceOverviewActions ?>
            <?php if ($deviceOverviewEditUrl !== ''): ?><a class="button secondary" href="<?= h($deviceOverviewEditUrl) ?>"><i class="bi bi-pencil-square" aria-hidden="true"></i> <span class="device-detail-hero-action-label">Edit Perangkat</span></a><?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<?php if (trim((string)$deviceOverviewWarning) !== ''): ?>
    <div class="notice device-system-warning"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> <?= h((string)$deviceOverviewWarning) ?></div>
<?php endif; ?>

<div class="device-view-grid">
    <div class="device-view-main-column">
        <section class="panel device-health-panel">
            <div class="panel-heading"><div><h2>System Informasi</h2></div></div>
            <div class="device-health-grid">
                <?php foreach ($deviceOverviewHealth as $health): ?>
                    <?php
                    if (!is_array($health)) continue;
                    $meter = is_numeric($health['meter'] ?? null)
                        ? max(0, min(100, (float)$health['meter']))
                        : null;
                    ?>
                    <div class="device-health-card <?= h((string)($health['class'] ?? '')) ?>">
                        <div class="device-health-head">
                            <span><i class="bi <?= h((string)($health['icon'] ?? 'bi-activity')) ?>" aria-hidden="true"></i> <?= h((string)($health['label'] ?? '')) ?></span>
                            <?php if (array_key_exists('head_value', $health)): ?>
                                <span<?= $deviceOverviewAttributes((array)($health['head_value_attributes'] ?? [])) ?>><?= h((string)$health['head_value']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (array_key_exists('meter', $health)): ?>
                            <div class="device-meter"><span<?= $deviceOverviewAttributes((array)($health['meter_attributes'] ?? [])) ?> style="width:<?= h((string)($meter ?? 0)) ?>%"></span></div>
                        <?php endif; ?>
                        <?php if (array_key_exists('value', $health)): ?>
                            <span class="<?= h((string)($health['value_class'] ?? 'device-uptime-value')) ?>"<?= $deviceOverviewAttributes((array)($health['value_attributes'] ?? [])) ?>><?= h((string)$health['value']) ?></span>
                        <?php endif; ?>
                        <?php if (array_key_exists('detail', $health)): ?>
                            <small<?= $deviceOverviewAttributes((array)($health['detail_attributes'] ?? [])) ?>><?= h((string)$health['detail']) ?></small>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="device-stat-grid device-stat-grid-inline <?= h((string)$deviceOverviewStatClass) ?>">
                <?php foreach ($deviceOverviewStats as $stat): ?>
                    <?php if (!is_array($stat)) continue; ?>
                    <div class="stat-card <?= h((string)($stat['class'] ?? '')) ?>">
                        <span><?= h((string)($stat['label'] ?? '')) ?></span>
                        <strong<?= $deviceOverviewAttributes((array)($stat['value_attributes'] ?? [])) ?>><?= h((string)($stat['value'] ?? 'N/A')) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <?php
    $deviceDetailPanelId = '';
    require __DIR__ . '/device-detail-panel.php';
    ?>
</div>
<?php
unset(
    $deviceOverviewHero,
    $deviceOverviewHealth,
    $deviceOverviewStats,
    $deviceOverviewDetails,
    $deviceOverviewWarning,
    $deviceOverviewStatClass,
    $deviceOverviewAttributes
);
?>
