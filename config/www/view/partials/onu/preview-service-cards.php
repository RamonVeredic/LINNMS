<?php
$onuServiceConfig = is_array($onuService ?? null) ? $onuService : [];
$onuServiceByIndex = [];
foreach ((array)($onuServiceConfig['mgmt_services'] ?? []) as $service) {
    $index = (int)($service['gemport'] ?? 0);
    if ($index > 0) $onuServiceByIndex[$index] = $service;
}
$onuServicePortsByIndex = [];
foreach ((array)($onuServiceConfig['service_ports'] ?? []) as $port) {
    $index = (int)($port['index'] ?? 0);
    if ($index > 0) $onuServicePortsByIndex[$index] = $port;
}
$onuServiceTcontByIndex = [];
foreach ((array)($onuServiceConfig['tcont'] ?? []) as $tcont) {
    $index = (int)($tcont['index'] ?? 0);
    if ($index > 0) $onuServiceTcontByIndex[$index] = $tcont;
}
$onuServiceGemportByIndex = [];
foreach ((array)($onuServiceConfig['gemport'] ?? []) as $gemport) {
    $index = (int)($gemport['index'] ?? 0);
    if ($index > 0) $onuServiceGemportByIndex[$index] = $gemport;
}
$onuServiceIndexes = array_values(array_unique(array_merge(range(1, 4), array_keys($onuServiceByIndex), array_keys($onuServicePortsByIndex))));
sort($onuServiceIndexes);
$onuServiceDisabledIndexes = array_values(array_filter($onuServiceIndexes, static fn(int $index): bool => !isset($onuServiceByIndex[$index])));
$onuServiceTemplates = (array)($onuServiceConfig['service_templates'] ?? []);
$onuServiceModesByIndex = [];
foreach ((array)($onuServiceConfig['wan_ips'] ?? []) as $wan) {
    if (!is_array($wan)) continue;
    $index = (int)($wan['index'] ?? 0);
    $wanMode = strtolower(trim((string)($wan['mode'] ?? '')));
    if ($index > 0 && in_array($wanMode, ['dhcp', 'pppoe', 'static'], true)) {
        $onuServiceModesByIndex[$index] = 'WAN-IP (' . (['dhcp' => 'DHCP', 'pppoe' => 'PPPoE', 'static' => 'Static'][$wanMode]) . ')';
    }
}
foreach ((array)($onuServiceConfig['mgmt_lines'] ?? []) as $line) {
    $line = trim((string)$line);
    if (preg_match('/^wan-ip\s+(\d+)\b/i', $line, $match) === 1 && !isset($onuServiceModesByIndex[(int)$match[1]])) {
        $onuServiceModesByIndex[(int)$match[1]] = 'WAN-IP';
    }
    if (preg_match('/^pppoe\s+(\d+)\b/i', $line, $match) === 1) {
        $onuServiceModesByIndex[(int)$match[1]] = preg_match('/\bnat\s+enable\b/i', $line) === 1 ? 'PPPoE NAT' : 'PPPoE';
    }
}
?>
<?php if (!empty($onuViewCapabilities['onu_service']) || $onuServiceByIndex !== []): ?>
<section class="panel onu-service-preview" aria-labelledby="onuServiceTitle" data-onu-service="service">
    <header class="tittle-subtitle"><h3 id="onuServiceTitle">WAN</h3></header>
    <?php if ($onuServiceConfig === []): ?>
        <p class="text-muted">Data WAN belum tersedia pada snapshot.</p>
    <?php else: ?>
    <?php if ($onuServiceDisabledIndexes !== []): ?>
    <div class="onu-service-disabled-list">
        <?php foreach ($onuServiceDisabledIndexes as $serviceIndex): ?>
            <?php $partial = isset($onuServicePortsByIndex[$serviceIndex], $onuServiceTcontByIndex[$serviceIndex], $onuServiceGemportByIndex[$serviceIndex]); ?>
            <label class="onu-service-toggle-row"><span>Service <?= (int)$serviceIndex ?></span><input type="checkbox" role="switch" data-onu-service-toggle data-onu-service-index="<?= (int)$serviceIndex ?>" data-onu-service-configured="<?= isset($onuServiceTemplates[$serviceIndex]) ? '1' : '0' ?>" data-onu-service-partial="<?= $partial ? '1' : '0' ?>" data-onu-service-name="<?= h((string)($onuServiceTcontByIndex[$serviceIndex]['name'] ?? '')) ?>" data-onu-service-vlan="<?= (int)($onuServicePortsByIndex[$serviceIndex]['vlan'] ?? 0) ?>" aria-label="Enable Service <?= (int)$serviceIndex ?>"<?= empty($onuViewCapabilities['onu_service']) ? ' disabled' : '' ?>><span class="text-muted" data-onu-service-status>Disable</span></label>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="onu-wan-grid">
        <?php foreach ($onuServiceIndexes as $serviceIndex): ?>
            <?php
            $service = $onuServiceByIndex[$serviceIndex] ?? null;
            if (!is_array($service) || $service === []) continue;
            $port = $onuServicePortsByIndex[$serviceIndex] ?? [];
            $tcont = $onuServiceTcontByIndex[$serviceIndex] ?? [];
            $gemport = $onuServiceGemportByIndex[$serviceIndex] ?? [];
            $vlan = (int)($port['vlan'] ?? $service['vlan'] ?? 0);
            $vlanLabel = $vlan > 0 ? (string)$vlan : '—';
            if ($vlan > 0 && isset($onuServiceVlanNames[$vlan])) $vlanLabel .= ' - ' . $onuServiceVlanNames[$vlan];
            $modeLabel = $onuServiceModesByIndex[$serviceIndex] ?? 'Bridge / ONU Webpage';
            ?>
            <article class="onu-wan-service" data-onu-service-index="<?= (int)$serviceIndex ?>">
                <strong>Service <?= (int)$serviceIndex ?></strong>
                <dl class="onu-wan-details">
                    <div><dt><label for="onu-service-status-<?= (int)$serviceIndex ?>">Status</label></dt><dd><span class="onu-service-toggle-row"><input id="onu-service-status-<?= (int)$serviceIndex ?>" type="checkbox" role="switch" data-onu-service-toggle data-onu-service-index="<?= (int)$serviceIndex ?>" aria-label="Status Service <?= (int)$serviceIndex ?>" checked<?= empty($onuViewCapabilities['onu_service']) ? ' disabled' : '' ?>><span class="text-muted" data-onu-service-status>Enable</span></span></dd></div>
                    <div><dt>VLAN</dt><dd><?= h($vlanLabel) ?></dd></div>
                    <div><dt>Download</dt><dd><?= h((string)($gemport['traffic_limit'] ?? '')) ?: '—' ?></dd></div>
                    <div><dt>Upload</dt><dd><?= h((string)($tcont['profile'] ?? '')) ?: '—' ?></dd></div>
                    <div><dt>Mode</dt><dd><?= h($modeLabel) ?></dd></div>
                </dl>
            </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>
