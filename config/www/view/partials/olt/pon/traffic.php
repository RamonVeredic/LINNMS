<?php
$ponTrafficGraphPorts = is_array($ponTrafficGraphRows ?? null) ? $ponTrafficGraphRows : (is_array($ponTrafficRows ?? null) ? $ponTrafficRows : []);
$ponTrafficSelectedRefs = is_array($ponTrafficSelected ?? null) ? array_keys($ponTrafficSelected) : [];
$ponTrafficHasSelectedUplink = false;
foreach ($ponTrafficSelectedRefs as $ponTrafficSelectedRef) {
    $ponTrafficSelectedRef = preg_replace('/^(?:gpon|epon)(?:-olt)?[_\/]?/i', '', trim((string)$ponTrafficSelectedRef));
    if (preg_match('/^(?:gei|xgei)/i', (string)$ponTrafficSelectedRef) === 1) {
        $ponTrafficHasSelectedUplink = true;
        break;
    }
}
$ponTrafficLabelOf = static function (array $row, string $ref): string {
    $label = trim((string)($row['label'] ?? ''));
    return $label !== '' ? $label : trim($ref);
};
$ponTrafficIntervalSeconds = (int)($oltTrafficIntervalSeconds ?? 0);
$ponTrafficRanges = [180 => '3m', 1800 => '30m', 3600 => '1H', 86400 => '1d'];
$ponTrafficActiveRange = (int)($ponTrafficRange ?? 180);
if (!isset($ponTrafficRanges[$ponTrafficActiveRange])) $ponTrafficActiveRange = 180;

$ponTrafficNorm = static function (mixed $ref): string {
    $normalized = (string)preg_replace('~^(?:gpon|epon)(?:-olt)?[_/]?~i', '', trim((string)$ref));
    return str_replace('_', '/', $normalized);
};
$ponTrafficGraphByNorm = [];
foreach ($ponTrafficGraphPorts as $ponTrafficRef => $ponTrafficRow) {
    if (is_array($ponTrafficRow)) $ponTrafficGraphByNorm[$ponTrafficNorm($ponTrafficRef)] = ['ref' => (string)$ponTrafficRef, 'row' => $ponTrafficRow];
}
$ponTrafficUplinkByNorm = [];
foreach ((array)($oltUplinkPorts ?? []) as $ponTrafficUplinkRow) {
    if (!is_array($ponTrafficUplinkRow)) continue;
    $ponTrafficUplinkRef = trim((string)($ponTrafficUplinkRow['port_ref'] ?? ''));
    if ($ponTrafficUplinkRef === '') continue;
    $ponTrafficUplinkByNorm[$ponTrafficNorm($ponTrafficUplinkRef)] = ['ref' => $ponTrafficUplinkRef, 'row' => $ponTrafficUplinkRow];
}
$ponTrafficCards = [];
foreach ($ponTrafficSelectedRefs as $ponTrafficSelectedRef) {
    $normalized = $ponTrafficNorm($ponTrafficSelectedRef);
    if (isset($ponTrafficUplinkByNorm[$normalized])) {
        $item = $ponTrafficUplinkByNorm[$normalized];
        $item['row']['is_uplink'] = true;
        $ponTrafficCards[$item['ref']] = $item['row'];
        continue;
    }
    if (isset($ponTrafficGraphByNorm[$normalized])) {
        $item = $ponTrafficGraphByNorm[$normalized];
        $item['row']['is_uplink'] = false;
        $ponTrafficCards[$item['ref']] = $item['row'];
    } else {
        $ponTrafficCards[$ponTrafficSelectedRef] = [
            'label' => (string)$ponTrafficSelectedRef,
            'is_uplink' => false,
        ];
    }
}
uksort($ponTrafficCards, static function ($left, $right) use ($ponTrafficCards): int {
    $leftUplink = !empty($ponTrafficCards[$left]['is_uplink']);
    $rightUplink = !empty($ponTrafficCards[$right]['is_uplink']);
    if ($leftUplink !== $rightUplink) return $leftUplink ? -1 : 1;
    return strnatcasecmp((string)$left, (string)$right);
});
$ponRenderCard = static function (string $label, string $ref, string $role = ''): void {
    $trafficCardRef = (string)$ref;
    $trafficCardTitle = $label;
    $trafficCardRole = $role;
    $trafficCardIcon = $role === 'uplink' ? 'bi-arrow-up-right-circle' : 'bi-hdd-network';
    require project_path('pages', 'partials', 'traffic-card.php');
};
?>
<aside class="pon-traffic" data-traffic data-traffic-mode="pon" data-traffic-row-id="<?= (int)$oltDetail['id'] ?>"
>
    <div class="panel-heading"><h3 class="onu-sub-block-title"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i> Trafik</h3></div>
    <article data-pon-traffic-panel="graph">
        <div class="pon-traffic-ranges">
            <?php foreach ($ponTrafficRanges as $ponRangeSeconds => $ponRangeLabel): ?>
                <button type="button" class="pon-traffic-range <?= (int)$ponRangeSeconds === $ponTrafficActiveRange ? 'is-active' : '' ?>"
                        data-traffic-range="<?= (int)$ponRangeSeconds ?>"><?= h($ponRangeLabel) ?></button>
            <?php endforeach; ?>
        </div>
        <div class="pon-traffic-grid" data-traffic-grid>
            <?php foreach ($ponTrafficCards as $ponTrafficRef => $ponTrafficRow): ?>
                <?php $ponRenderCard($ponTrafficLabelOf($ponTrafficRow, (string)$ponTrafficRef), (string)$ponTrafficRef, !empty($ponTrafficRow['is_uplink']) ? 'uplink' : 'pon'); ?>
            <?php endforeach; ?>
        </div>
    </article>
</aside>
