<?php
$formatVlans = static function (mixed $value): string {
    if (!is_array($value)) return 'N/A';
    $vlans = array_values(array_unique(array_filter(array_map('intval', $value), static fn(int $vlan): bool => $vlan >= 1 && $vlan <= 4094)));
    sort($vlans, SORT_NUMERIC);
    if ($vlans === []) return 'N/A';
    $ranges = [];
    $start = $previous = $vlans[0];
    foreach (array_slice($vlans, 1) as $vlan) {
        if ($vlan === $previous + 1) {
            $previous = $vlan;
            continue;
        }
        $ranges[] = $start === $previous ? (string)$start : $start . '-' . $previous;
        $start = $previous = $vlan;
    }
    $ranges[] = $start === $previous ? (string)$start : $start . '-' . $previous;
    return implode(', ', $ranges);
};
