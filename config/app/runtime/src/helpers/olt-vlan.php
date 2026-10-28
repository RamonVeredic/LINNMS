<?php

declare(strict_types=1);

function olt_vlan_ids(string $list): array
{
    $ids = [];
    foreach (preg_split('/[\s,]+/', trim($list)) ?: [] as $token) {
        $token = trim($token);
        if ($token === '') continue;
        if (preg_match('/^(\d+)-(\d+)$/', $token, $range) === 1) {
            $from = max(1, (int)$range[1]);
            $to = min(4095, (int)$range[2]);
            if ($to < $from) [$from, $to] = [$to, $from];
            for ($id = $from; $id <= $to && count($ids) < 4096; $id++) $ids[$id] = true;
            continue;
        }
        if (preg_match('/^\d+$/', $token) !== 1) continue;
        $id = (int)$token;
        if ($id >= 1 && $id <= 4095) $ids[$id] = true;
    }
    $out = array_map('intval', array_keys($ids));
    sort($out);
    return $out;
}
