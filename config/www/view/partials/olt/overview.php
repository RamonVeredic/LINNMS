<?php

$ovData = is_array($oltView ?? null) ? $oltView : [];
$ovId = (int)($ovData['id'] ?? 0);
$ovIdentity = is_array($ovData['identity'] ?? null) ? $ovData['identity'] : [];
$ovCapabilities = is_array($ovData['capabilities'] ?? null) ? $ovData['capabilities'] : [];
$ovActions = is_array($ovData['actions'] ?? null) ? $ovData['actions'] : [];
$ovStats = is_array($ovData['stats'] ?? null) ? $ovData['stats'] : [];
$ovStatus = (string)($ovIdentity['status'] ?? '');
$ovStatusClass = $ovStatus === 'online' ? 'is-up' : ($ovStatus === 'offline' ? 'is-down' : 'is-unknown');
$ovUptime = $ovIdentity['uptime_seconds'] ?? null;
$ovTemperature = trim((string)($ovIdentity['temperature_text'] ?? ''));
if ($ovTemperature === '' || strcasecmp($ovTemperature, 'N/A') === 0) $ovTemperature = '0.00 °C';
$ovRenderActions = static function () use ($ovId, $ovActions, $ovData, $ovCapabilities, $oltDetail): void {
    ?>
    <div class="olt-actions">
        <form method="post" class="inline-form" data-tip="<?= empty($ovCapabilities['refresh_detail']) ? 'Pembaruan data tidak tersedia' : 'Perbarui data OLT ini' ?>" data-tip-pos="bottom"<?= !empty($ovActions['refresh_async']) ? ' data-olt-refresh' : '' ?>
            data-olt-ref="<?= h((string)($ovData['resource_ref'] ?? '')) ?>"
            data-olt-refresh-target="olt-pon-config">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="<?= h((string)($ovActions['refresh_action'] ?? '')) ?>">
            <input type="hidden" name="olt_row_id" value="<?= $ovId ?>">
            <button class="button secondary" type="submit" data-olt-refresh-button aria-label="Perbarui data OLT ini"<?= empty($ovCapabilities['refresh_detail']) ? ' disabled' : '' ?>><i class="bi bi-arrow-repeat" aria-hidden="true"></i></button>
        </form>
        <span data-tip="Ubah pengaturan perangkat" data-tip-pos="bottom"><a class="button secondary" href="<?= h((string)($ovActions['edit_url'] ?? '#')) ?>" aria-label="Ubah pengaturan perangkat"><i class="bi bi-pencil-square" aria-hidden="true"></i></a></span>
        <?php require __DIR__ . '/terminal/button.php'; ?>
    </div>
    <?php
};
?>
    <section class="panel olt-overview">
        <div class="olt-overview-top">
            <div class="olt-identity">
                <div class="device-detail-icon"><i class="bi bi-pc-horizontal" aria-hidden="true"></i></div>
                <div>
                    <div class="olt-title">
                        <div class="olt-title-name">
                        <h2><?= h((string)($ovIdentity['name'] ?? '')) ?></h2>
                        <?php if (trim((string)($ovIdentity['status_label'] ?? '')) !== '' && strcasecmp((string)$ovIdentity['status_label'], '-') !== 0): ?>
                        <?php endif; ?>
                        <small class="olt-temperature"><i class="bi bi-thermometer-half" aria-hidden="true"></i> <?= h($ovTemperature) ?></small>
                        </div>
                        <?php $ovRenderActions(); ?>
                    </div>
                    <p><?= h(display_model_chars($ovIdentity['model'] ?? '-', '-')) ?> · <span class="mono"><?= h((string)($ovIdentity['ip'] ?? '-')) ?></span></p>
                    <p><i class="bi bi-clock-history" aria-hidden="true"></i> <span<?= is_numeric($ovUptime) ? ' data-device-uptime-clock data-uptime-seconds="' . (int)$ovUptime . '"' : '' ?>><?= h((string)($ovIdentity['uptime_text'] ?? 'N/A')) ?></span></p>
                    <p><i class="bi bi-calendar3" aria-hidden="true"></i> <?= h((string)($ovIdentity['current_time'] ?? '-')) ?></p>
                </div>
            </div>
        </div>
        <div class="olt-summary">
            <?php foreach ($ovStats as $ovLabel => $ovValue): ?>
                <div><strong<?= $ovLabel === 'Fan' ? ' data-olt-fan-count' : '' ?>><?= h((string)$ovValue) ?></strong><span><?= h((string)$ovLabel) ?></span></div>
            <?php endforeach; ?>
        </div>
    </section>
    <?= (string)($ovData['overview_extra'] ?? '') ?>
