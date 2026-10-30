<?php

$onuPortsByNumber = [];
foreach ((array)($onuLan['ports'] ?? []) as $onuPortRow) {
    if (!is_array($onuPortRow)) continue;
    $onuPortNumber = (int)($onuPortRow['port'] ?? 0);
    if ($onuPortNumber > 0) $onuPortsByNumber[$onuPortNumber] = $onuPortRow;
}
$onuLanCount = is_numeric($portNumber ?? null)
    ? max(0, (int)$portNumber - (!empty($onuViewCapabilities['onu_port_count_includes_optical']) ? 1 : 0))
    : 0;
for ($onuPortNumber = 1; $onuPortNumber <= $onuLanCount; $onuPortNumber++) {
    $onuPortsByNumber[$onuPortNumber] ??= ['port' => $onuPortNumber];
}
ksort($onuPortsByNumber, SORT_NUMERIC);
?>
<div class="onu-ethernet-grid">
    <?php foreach ($onuPortsByNumber as $onuPortNumber => $onuPortRow): ?>
        <?php
        $onuPortLink = strtolower(trim((string)($onuPortRow['link_status'] ?? '')));
        $onuPortAdmin = strtolower(trim((string)($onuPortRow['action']['interface_status'] ?? '')));
        $onuPortClass = $onuPortAdmin === 'disable'
            ? 'is-down'
            : (in_array($onuPortLink, ['up', 'online', 'linkup'], true)
                ? 'is-up' : (in_array($onuPortLink, ['down', 'offline', 'linkdown', 'disable'], true) ? 'is-down' : 'is-unknown'));
        $onuPortSpeed = trim((string)($onuPortRow['speed'] ?? ''));
        ?>
        <div class="onu-lan-port <?= h($onuPortClass) ?>" data-onu-port="<?= (int)$onuPortNumber ?>">
            <button type="button" class="onu-lan-button" data-onu-lan-open="<?= (int)$onuPortNumber ?>" aria-haspopup="dialog" aria-controls="onu-ethernet-modal" title="LAN <?= (int)$onuPortNumber ?>">
                <span class="onu-lan-icon"><i class="bi bi-ethernet" aria-hidden="true"></i><b class="onu-lan-num" data-num="<?= (int)$onuPortNumber ?>"></b></span>
                <span><?= h($onuPortSpeed !== '' ? $onuPortSpeed : '—') ?></span>
            </button>
        </div>
    <?php endforeach; ?>
</div>
<div id="onu-ethernet-modal" class="modal olt-pon-modal onu-ethernet-modal" data-onu-ethernet-modal hidden aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="onu-ethernet-modal-title">
        <div class="onu-eth-heading modal-header">
            <h2 id="onu-ethernet-modal-title">LAN <span data-onu-lan-number></span>
            -
            <span class="onu-eth-status is-unknown" data-onu-lan-status></span></h2>
            <button class="icon-button modal-close-button" type="button" data-onu-lan-close aria-label="Tutup modal"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="onu-eth-body" data-onu-lan-detail></div>
    </div>
</div>
