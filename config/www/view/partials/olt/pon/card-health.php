<?php
$oltHealthCard = is_array($oltHealthCard ?? null) ? $oltHealthCard : [];
$oltHealthFallback = is_array($oltHealthFallback ?? null) ? $oltHealthFallback : [];
$oltHealthSlot = (int)($oltHealthSlot ?? 0);
$oltHealthTelemetry = !empty($oltHealthTelemetry);
$oltHealthStatic = !empty($oltHealthStatic);
$oltHealthFormatter = $oltHealthFormatter ?? static fn($value, string $unit): string => OltDetailViewModel::number($value, $unit);
?>
<div class="olt-card-health<?= $oltHealthStatic ? ' olt-card-health-static' : '' ?>">
    <?php foreach (['Temperature' => ['temperature', ' °C', 'temp'], 'CPU Usage' => ['cpu_percent', '%', 'cpu'], 'Memory Usage' => ['memory_percent', '%', 'memory']] as $oltHealthLabel => [$oltHealthField, $oltHealthUnit, $oltHealthKey]):
        $oltHealthValue = $oltHealthCard[$oltHealthField] ?? $oltHealthFallback[$oltHealthField] ?? null;
        $oltHealthTemperatureMissing = $oltHealthField === 'temperature' && !is_numeric($oltHealthValue);
        if ($oltHealthTemperatureMissing) $oltHealthValue = 0.0;
        $oltHealthNumeric = is_numeric($oltHealthValue) ? max(0, min(100, (float)$oltHealthValue)) : null;
        $oltHealthMeterLevel = $oltHealthNumeric === null || $oltHealthNumeric <= 0 ? 0
            : ($oltHealthNumeric <= 25 ? 1 : ($oltHealthNumeric <= 50 ? 2 : ($oltHealthNumeric <= 75 ? 3 : 4)));
    ?>
        <div>
            <span><?php if ($oltHealthField === 'temperature'): ?><i class="bi bi-thermometer-half" aria-hidden="true"></i> <?php endif; ?><?= h($oltHealthLabel) ?></span>
            <strong<?= $oltHealthTelemetry ? ' data-olt-telemetry="' . h($oltHealthKey) . '"' : '' ?><?= $oltHealthSlot > 0 ? ' data-olt-card-' . h($oltHealthKey) . '="' . $oltHealthSlot . '"' : '' ?>><?= h($oltHealthTemperatureMissing ? '0.00 °C' : $oltHealthFormatter($oltHealthValue, $oltHealthUnit)) ?></strong>
            <?php if ($oltHealthUnit === '%'): ?>
                <div class="device-meter heat-meter<?= $oltHealthMeterLevel > 0 ? ' is-level-' . $oltHealthMeterLevel : '' ?>"><span<?= $oltHealthTelemetry ? ' data-olt-telemetry-meter="' . h($oltHealthKey) . '"' : '' ?><?= $oltHealthSlot > 0 ? ' data-olt-card-' . h($oltHealthKey) . '-meter="' . $oltHealthSlot . '"' : '' ?> style="width:<?= $oltHealthNumeric ?? 0 ?>%"></span></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
