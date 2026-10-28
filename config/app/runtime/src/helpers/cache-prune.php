<?php

declare(strict_types=1);

function cache_prune_policy(): array
{
    return [
        'dead' => ['.pon-traffic-history.json'],
        'dead_grace_seconds' => 0,
        'orphan_grace_seconds' => 86400,
        'stale_seconds' => 14 * 86400,
        'junk_prefixes' => ['tmp-', '.tmp'],
    ];
}

function cache_prune_owner_id(string $name): int
{
    if (preg_match('/-cache-[a-z]+-(\d+)\.json/', $name, $m) === 1) return (int)$m[1];
    if (preg_match('/^snmp-olt-(\d+)\.json/', $name, $m) === 1) return (int)$m[1];
    if (preg_match('/^web-config-(\d+)-(\d+)\.json/', $name, $m) === 1) return (int)$m[2];
    if (preg_match('/^snmp-device-(\d+)-(\d+)/', $name, $m) === 1) return (int)$m[2];
    if (preg_match('/^olt-middle-\d+-(\d+)\.stamp$/D', $name, $m) === 1) return (int)$m[1];
    return 0;
}

function cache_forget_device(int $deviceRowId, ?int $userId = null): int
{
    if ($deviceRowId < 1) return 0;
    $directory = cache_path();
    if (!is_dir($directory)) return 0;

    $removed = 0;
    foreach (@scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (!is_file($path)) continue;
        if (cache_prune_owner_id($entry) !== $deviceRowId) continue;
        if (@unlink($path)) $removed++;
    }

    if ($userId !== null && $userId > 0) {
        $middleMarker = cache_path('olt-middle-' . $userId . '-' . $deviceRowId . '.stamp');
        if (is_file($middleMarker) && @unlink($middleMarker)) $removed++;

        $trafficDirectory = cache_path('snmp-device-' . $userId . '-' . $deviceRowId);
        if (is_dir($trafficDirectory)) {
            foreach (@scandir($trafficDirectory) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $path = $trafficDirectory . DIRECTORY_SEPARATOR . $entry;
                if (is_file($path) && @unlink($path)) $removed++;
            }
            @rmdir($trafficDirectory);
        }
    }

    $ponTraffic = cache_path('olt-pon-cli-traffic', 'device-' . $deviceRowId . '.json');
    if (is_file($ponTraffic) && @unlink($ponTraffic)) $removed++;

    return $removed;
}

function cache_forget_onus(int $userId, array $onuRowIds): int
{
    if ($userId < 1) return 0;
    $removed = 0;
    foreach ($onuRowIds as $onuRowId) {
        $onuRowId = (int)$onuRowId;
        if ($onuRowId < 1) continue;
        $path = cache_path('onu-traffic-live', $userId . '-' . $onuRowId . '.json');
        if (is_file($path) && @unlink($path)) $removed++;
    }
    return $removed;
}

function cache_prune(array $existingDeviceIds, ?int $now = null): array
{
    $directory = cache_path();
    if (!is_dir($directory)) return ['removed' => [], 'freed' => 0];

    $policy = cache_prune_policy();
    $now = $now ?? time();
    $devices = [];
    foreach ($existingDeviceIds as $id) {
        $id = (int)$id;
        if ($id > 0) $devices[$id] = true;
    }

    $removed = [];
    $freed = 0;
    $entries = @scandir($directory) ?: [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path) && preg_match('/^snmp-device-\d+-(\d+)$/D', $entry, $match) === 1) {
            $ownerId = (int)$match[1];
            $age = max(0, $now - (int)@filemtime($path));
            if ($ownerId > 0 && !isset($devices[$ownerId]) && $age >= (int)$policy['orphan_grace_seconds']) {
                $bytes = 0;
                foreach (@scandir($path) ?: [] as $child) {
                    if ($child === '.' || $child === '..') continue;
                    $childPath = $path . DIRECTORY_SEPARATOR . $child;
                    if (!is_file($childPath)) continue;
                    $size = (int)@filesize($childPath);
                    if (@unlink($childPath)) $bytes += $size;
                }
                if (@rmdir($path)) {
                    $removed[] = ['file' => $entry, 'reason' => 'yatim', 'bytes' => $bytes];
                }
                $freed += $bytes;
            }
            continue;
        }
        if (!is_file($path)) continue;

        $age = max(0, $now - (int)@filemtime($path));
        $reason = '';

        foreach ($policy['junk_prefixes'] as $prefix) {
            if (str_starts_with($entry, $prefix)) {
                $reason = 'sampah';
                break;
            }
        }

        if ($reason === '') {
            foreach ($policy['dead'] as $suffix) {
                if (str_ends_with($entry, $suffix) && $age >= (int)$policy['dead_grace_seconds']) {
                    $reason = 'usang';
                    break;
                }
            }
        }

        if ($reason === '') {
            $ownerId = cache_prune_owner_id($entry);
            if ($ownerId > 0 && !isset($devices[$ownerId]) && $age >= (int)$policy['orphan_grace_seconds']) {
                $reason = 'yatim';
            }
        }

        if ($reason === '' && $age >= (int)$policy['stale_seconds']) $reason = 'menganggur';
        if ($reason === '') continue;

        $size = (int)@filesize($path);
        if (@unlink($path)) {
            $freed += $size;
            $removed[] = ['file' => $entry, 'reason' => $reason, 'bytes' => $size];
        }
    }

    return ['removed' => $removed, 'freed' => $freed];
}
