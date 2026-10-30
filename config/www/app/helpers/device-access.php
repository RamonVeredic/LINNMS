<?php

declare(strict_types=1);




function device_access_permissions(): array
{
    $permissions = $GLOBALS['linnms_device_access_permissions'] ?? null;
    return is_array($permissions) ? $permissions : [];
}

function device_access_allows(string $key): bool
{
    if (trim($key) === '') return false;
    return device_access_allows_from(device_access_permissions(), $key);
}


function device_access_family(string $key): string
{
    $key = trim($key);
    if ($key === '') return '';
    if (str_starts_with($key, 'api_olt_type:') || $key === 'external_olt_api') return 'external';
    if (str_starts_with($key, 'zte_')) return 'zte';
    if (str_starts_with($key, 'hioso_') || str_starts_with($key, 'hisfocus_')) return 'hioso';
    if (str_starts_with($key, 'mikrotik_')) return 'mikrotik';
    if (str_starts_with($key, 'cisco_') || str_starts_with($key, 'switch_')) return 'switch';
    return $key;
}

function device_access_allows_from(array $permissions, string $key): bool
{
    $key = trim($key);
    if ($key === '') return false;
    
    if (array_key_exists($key, $permissions)) return (bool)$permissions[$key];
    
    $family = device_access_family($key);
    if ($family !== '' && $family !== $key && array_key_exists($family, $permissions)) {
        return (bool)$permissions[$family];
    }
    
    if (str_starts_with($key, 'api_olt_type:') && array_key_exists('external_olt_api', $permissions)) {
        return (bool)$permissions['external_olt_api'];
    }
    
    return false;
}


function device_row_permission_key(array $row): string
{
    $driverId = device_driver_type($row);
    if (($row['source'] ?? '') === 'api') {
        $type = trim((string)($row['type'] ?? ''));
        if ($type !== '') return 'api_olt_type:' . $type;
    }
    return $driverId;
}


function device_row_access_locked(array $row): bool
{
    return !device_access_allows(device_row_permission_key($row));
}


function device_access_lock_message(): string
{
    return 'Perangkat belum tersedia.';
}


