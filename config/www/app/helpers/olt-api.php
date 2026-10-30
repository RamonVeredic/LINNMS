<?php

declare(strict_types=1);

function api_olt_type_support_setting_key(string $oltType): string
{
    $safe = preg_replace('/[^A-Za-z0-9_]/', '_', trim($oltType)) ?? '';
    return 'olt_support_api_type_' . $safe;
}

function api_olt_type_support_enabled(array $flags, string $oltType): bool
{
    
    if (!device_access_allows('api_olt_type:' . trim($oltType))) return false;
    $key = api_olt_type_support_setting_key($oltType);
    return array_key_exists($key, $flags) && (string)$flags[$key] === '1';
}
