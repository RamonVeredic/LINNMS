<?php

declare(strict_types=1);

function device_add_view_model(array $pageShell): array
{
    $oltQuota = (array)($pageShell['olt_quota'] ?? []);
    $mikrotikQuota = (array)($pageShell['mikrotik_quota'] ?? []);
    $switchQuota = (array)($pageShell['switch_quota'] ?? []);
    $oltDriverSupportFlags = (array)($pageShell['olt_driver_flags'] ?? []);
    $apiOltTypeSupportFlags = (array)($pageShell['api_olt_type_flags'] ?? []);
    $snmpDriverSupportFlags = (array)($pageShell['snmp_driver_flags'] ?? []);
    $userHasExternalApiKey = !empty($pageShell['external_api_available']);
    $deviceDriverCatalog = monitoring_driver_catalog();
    $apiOltTypeOptions = selectable_api_olt_type_options();

    $deviceAddErrors = is_array($_SESSION['device_add_errors'] ?? null) ? $_SESSION['device_add_errors'] : [];
    unset($_SESSION['device_add_errors']);
    $deviceAddOldSnmp = is_array($_SESSION['old_snmp_device'] ?? null) ? $_SESSION['old_snmp_device'] : [];
    $deviceAddOldOlt = is_array($_SESSION['old_olt'] ?? null) ? $_SESSION['old_olt'] : [];
    unset($_SESSION['old_snmp_device'], $_SESSION['old_olt']);
    $deviceAddOld = $deviceAddOldSnmp !== [] ? $deviceAddOldSnmp : $deviceAddOldOlt;
    $deviceAddSelectedDriverId = trim((string)($deviceAddOld['driver_id'] ?? ''));
    if ($deviceAddSelectedDriverId === 'external_olt_api'
        && array_key_exists((string)($deviceAddOld['olt_type'] ?? ''), $apiOltTypeOptions)) {
        $deviceAddSelectedDriverId = 'api_olt_type:' . (string)$deviceAddOld['olt_type'];
    }
    $deviceAddSelectedDefinition = monitoring_driver_definition($deviceAddSelectedDriverId);
    $deviceAddCommunityDefault = trim((string)($deviceAddSelectedDefinition['default_snmp_community'] ?? 'public'));
    if ($deviceAddCommunityDefault === '') $deviceAddCommunityDefault = 'public';
    $deviceAddQuotaByGroup = [
        'olt' => !empty($oltQuota['can_add']),
        'router' => !empty($mikrotikQuota['can_add']),
        'switch' => !empty($switchQuota['can_add']),
    ];
    $deviceAddGroups = [];
    foreach ($deviceAddQuotaByGroup as $group => $quotaAllowed) {
        $deviceAddGroups[$group] = ['label' => strtoupper($group), 'rows' => []];
    }
    $deviceAddUnavailable = [];
    foreach ($deviceDriverCatalog as $driverId => $definition) {
        $group = (string)($definition['group'] ?? '');
        if (!array_key_exists($group, $deviceAddQuotaByGroup)) continue;
        if ((string)($definition['handler'] ?? '') === 'external_api') {
            foreach ($apiOltTypeOptions as $apiType => $apiLabel) {
                $key = 'api_olt_type:' . $apiType;
                $enabled = $userHasExternalApiKey && api_olt_type_support_enabled($apiOltTypeSupportFlags, (string)$apiType);
                $row = [
                    'key' => $key,
                    'definition' => ['label' => $apiLabel, 'group' => $group, 'method' => 'api'],
                    'enabled' => $enabled,
                    'quota_allowed' => $deviceAddQuotaByGroup[$group],
                    'access_locked' => !device_access_allows($key),
                ];
                if ($enabled && !$row['access_locked']) $deviceAddGroups[$group]['rows'][] = $row;
                else $deviceAddUnavailable[] = $row;
            }
        }
        if (empty($definition['selectable'])) continue;
        $enabled = $group === 'olt'
            ? olt_driver_support_enabled($oltDriverSupportFlags, (string)$driverId)
            : snmp_driver_support_enabled($snmpDriverSupportFlags, (string)$driverId);
        $row = [
            'key' => (string)$driverId,
            'definition' => $definition,
            'enabled' => $enabled,
            'quota_allowed' => $deviceAddQuotaByGroup[$group],
            'access_locked' => !device_access_allows((string)$driverId),
        ];
        if ($enabled && !$row['access_locked']) $deviceAddGroups[$group]['rows'][] = $row;
        else $deviceAddUnavailable[] = $row;
    }
    $deviceAddSortByLabel = static function (array $left, array $right): int {
        $comparison = strnatcasecmp(
            (string)($left['definition']['label'] ?? ''),
            (string)($right['definition']['label'] ?? '')
        );
        return $comparison !== 0 ? $comparison : strcmp((string)$left['key'], (string)$right['key']);
    };
    foreach ($deviceAddGroups as &$deviceAddGroup) {
        usort($deviceAddGroup['rows'], $deviceAddSortByLabel);
    }
    unset($deviceAddGroup);
    usort($deviceAddUnavailable, $deviceAddSortByLabel);

    return [
        'deviceAddErrors' => $deviceAddErrors,
        'deviceAddOld' => $deviceAddOld,
        'deviceAddSelectedDriverId' => $deviceAddSelectedDriverId,
        'deviceAddCommunityDefault' => $deviceAddCommunityDefault,
        'deviceAddGroups' => $deviceAddGroups,
        'deviceAddUnavailable' => $deviceAddUnavailable,
    ];
}
