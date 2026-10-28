<?php
$switchportMode = strtolower(trim((string)($interface['switchport_mode'] ?? '')));
$switchportModeLabel = in_array($switchportMode, ['access', 'trunk'], true) ? strtoupper($switchportMode) : 'N/A';
$switchVlans = is_array($interface['active_vlans'] ?? null)
    ? array_values(array_unique(array_filter(array_map('intval', $interface['active_vlans']), static fn(int $vlan): bool => $vlan > 1 && $vlan <= 4094)))
    : [];
sort($switchVlans, SORT_NUMERIC);
$visibleSwitchVlans = array_slice($switchVlans, 0, 5);
$visibleSwitchVlansText = $visibleSwitchVlans !== [] ? implode(', ', $visibleSwitchVlans) : 'N/A';
$hasMoreSwitchVlans = count($switchVlans) > count($visibleSwitchVlans);
$nativeVlan = (int)($interface['native_vlan'] ?? 0);
?>
<span class="switch-interface-name">
    <?= h($interfaceName) ?>
    <small class="switch-vlan-summary">
        <span class="switch-vlan-values" data-vlan-preview>VLAN: <?= h($visibleSwitchVlansText) ?><?php if ($switchportMode === 'trunk' && $nativeVlan > 1): ?> &middot; Native <?= $nativeVlan ?><?php endif; ?></span>
        <?php if ($hasMoreSwitchVlans): ?>
            <span class="switch-vlan-values" data-vlan-all hidden>VLAN: <?= h($formatVlans($switchVlans)) ?><?php if ($switchportMode === 'trunk' && $nativeVlan > 1): ?> &middot; Native <?= $nativeVlan ?><?php endif; ?></span>
            <button class="switch-vlan-toggle" type="button" data-vlan-toggle aria-expanded="false">Lihat semua VLAN</button>
        <?php endif; ?>
    </small>
</span>
