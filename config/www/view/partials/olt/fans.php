<?php
if (empty(($oltViewCapabilities ?? [])['fan'])) return;
$oltFans = is_array($oltFans ?? null) ? array_values(array_filter($oltFans, 'is_array')) : [];
?>
    <section class="panel olt-device-fans" aria-label="Fan perangkat">
    <div class="olt-fan-list">
        <?php if ($oltFans === []): ?><span class="text-muted">N/A</span><?php endif; ?>
        <?php foreach ($oltFans as $oltFan):
            $status = strtolower(trim((string)($oltFan['status'] ?? '')));
            $label = match ($status) { 'online' => 'Online', 'offline' => 'Offline', default => 'N/A' };
            $rawLevel = $oltFan['speed_level'] ?? null;
            $level = is_numeric($rawLevel) ? max(0, min(4, (int)$rawLevel)) : null;
            $fanSpinning = $status === 'online' && is_numeric($oltFan['rpm'] ?? null) && (float)$oltFan['rpm'] > 0;
            $levelClass = $status === 'online' && $level !== null && $level > 0 ? ' is-level-' . $level : '';
            $meterWidth = $status === 'online' && $level !== null ? $level * 25 : 0;
        ?>
            <div class="olt-fan-item <?= $status === 'online' ? 'is-online' : ($status === 'offline' ? 'is-offline' : '') ?><?= $fanSpinning ? ' is-spinning' : '' ?>">
                <span class="olt-fan-name"><span class="olt-fan-icon" aria-hidden="true"><img src="<?= h(asset_url('assets/img/fan.png')) ?>" alt=""></span> Fan <?= h((string)($oltFan['fan_unit_id'] ?? 'N/A')) ?></span>
                <span class="olt-fan-status <?= $status === 'online' ? 'is-online' : ($status === 'offline' ? 'is-offline' : '') ?>" aria-label="<?= h($label) ?>" title="<?= h($label) ?>"><i class="bi bi-circle-fill" aria-hidden="true"></i></span>
                <span class="olt-fan-reading">
                    <strong class="olt-fan-rpm mono"><?= h(is_numeric($oltFan['rpm'] ?? null) ? number_format((float)$oltFan['rpm'], 0, ',', '.') . ' RPM' : ' - ') ?></strong><span class="olt-fan-level">Lv <?= h((string)($rawLevel ?? ' - ')) ?></span> 
            
            </span>
                <div class="device-meter heat-meter olt-fan-meter<?= h($levelClass) ?>" aria-hidden="true"><span style="width:<?= $meterWidth ?>%"></span></div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
