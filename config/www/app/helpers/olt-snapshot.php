<?php

declare(strict_types=1);

function olt_system_snapshot(array $olt): array
{
    $systemJson = trim((string)($olt['system_json'] ?? ''));
    if ($systemJson !== '') {
        $decoded = json_decode($systemJson, true);
        if (is_array($decoded)) return $decoded;
    }

    $rawJson = trim((string)($olt['raw_json'] ?? ''));
    if ($rawJson === '') return [];

    $raw = json_decode($rawJson, true);
    if (!is_array($raw)) return [];

    $aliases = [
        'model' => ['model', 'device_model', 'olt_model', 'hardware_model'],
        'software_version' => [
            'software_version', 'firmware_version', 'firmware', 'sw_version'
        ],
        'revision' => ['revision', 'release', 'build', 'hardware_version'],
        'mac' => ['mac', 'mac_address', 'system_mac'],
        'serial' => ['serial', 'serial_number', 'sn'],
        'runtime_clean' => ['uptime_text', 'runtime_text', 'uptime', 'runtime'],
        'runtime_seconds' => ['uptime_seconds', 'runtime_seconds'],
        'cpu_percent' => ['cpu_percent', 'cpu_usage', 'cpu_load'],
        'memory_percent' => [
            'memory_percent', 'memory_usage_percent', 'ram_usage', 'dram_usage'
        ],
        'current_time' => ['current_time', 'device_time', 'system_time'],
        'system_name' => ['system_name', 'hostname'],
        'location' => ['location', 'system_location'],
    ];

    $out = [];
    foreach ($aliases as $target => $keys) {
        $value = olt_find_snapshot_value($raw, $keys);
        if ($value === null || $value === '') continue;

        if (in_array($target, ['cpu_percent', 'memory_percent'], true)) {
            $percent = olt_normalize_percent($value);
            if ($percent !== null) $out[$target] = $percent;
            continue;
        }

        if ($target === 'runtime_seconds') {
            if (is_numeric($value) && (float)$value >= 0) {
                $out[$target] = (int)round((float)$value);
            }
            continue;
        }

        $out[$target] = is_scalar($value)
            ? trim((string)$value)
            : $value;
    }

    $out['source'] = 'SNMP';

    return $out;
}

function olt_find_snapshot_value(
    array $data,
    array $keys
): mixed {
    $wanted = array_fill_keys(
        array_map(
            static fn(string $key): string => strtolower($key),
            $keys
        ),
        true
    );

    $queue = [$data];
    while ($queue !== []) {
        $node = array_shift($queue);
        if (!is_array($node)) continue;

        foreach ($node as $key => $value) {
            if (
                is_string($key) &&
                isset($wanted[strtolower($key)]) &&
                (is_scalar($value) || $value === null)
            ) {
                return $value;
            }

            if (is_array($value)) {
                $queue[] = $value;
            }
        }
    }

    return null;
}

function olt_normalize_percent(mixed $value): ?float
{
    if (is_numeric($value)) {
        $number = (float)$value;
    } elseif (
        is_string($value) &&
        preg_match('/-?\d+(?:\.\d+)?/', $value, $match)
    ) {
        $number = (float)$match[0];
    } else {
        return null;
    }

    if ($number < 0 || $number > 100) return null;
    return $number;
}
