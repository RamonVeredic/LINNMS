<?php

$tabData = is_array($oltView ?? null) ? $oltView : [];
$tabList = is_array($tabData['tabs'] ?? null) ? $tabData['tabs'] : [];
$tabLines = [
    'onu-types' => ['ONU', 'Type'],
    'wan' => ['WAN-IP', 'Profil'],
    'speed' => ['Speed', 'Profil'],
];
?>
        <nav class="olt-tabs" role="tablist" aria-label="Informasi OLT">
            <?php foreach ($tabList as $tabKey => [$tabLabel, $tabIcon]): ?>
                <button type="button" role="tab" id="olt-tab-<?= h((string)$tabKey) ?>" aria-controls="olt-panel-<?= h((string)$tabKey) ?>" aria-label="<?= h((string)$tabLabel) ?>" aria-selected="<?= $tabKey === 'pon' ? 'true' : 'false' ?>" tabindex="<?= $tabKey === 'pon' ? '0' : '-1' ?>" data-olt-tab="<?= h((string)$tabKey) ?>"><i class="bi <?= h((string)$tabIcon) ?>" aria-hidden="true"></i><span class="olt-tab-label"><?php if (isset($tabLines[$tabKey])): ?><span><?= h($tabLines[$tabKey][0]) ?></span><span><?= h($tabLines[$tabKey][1]) ?></span><?php else: ?><?= h((string)$tabLabel) ?><?php endif; ?></span></button>
            <?php endforeach; ?>
        </nav>
