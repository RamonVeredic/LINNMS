<?php

declare(strict_types=1);





function olt_model_pattern(array $definition): string
{
    $models = is_array($definition['models'] ?? null) ? (array)$definition['models'] : [];
    if ($models === []) return '';
    $parts = array_map(static fn(string $m): string => preg_quote($m, '/'), $models);
    return '/' . implode('|', $parts) . '/i';
}


function olt_model_validate(string $driverId, array $definition, array $systemInfo): array
{
    $expected = olt_model_pattern($definition);
    $detected = strtoupper(trim((string)(($systemInfo['data'] ?? [])['model'] ?? '')));
    if ($expected === '') {
        return ['ok' => true, 'message' => '', 'expected' => '', 'detected' => $detected];
    }
    if ($detected !== '' && preg_match($expected, $detected) === 1) {
        return ['ok' => true, 'message' => '', 'expected' => $expected, 'detected' => $detected];
    }
    return [
        'ok' => false,
        'message' => 'Tipe perangkat tidak sesuai.',
        'expected' => $expected,
        'detected' => $detected,
    ];
}
