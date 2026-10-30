<?php

declare(strict_types=1);

function add_onu_modal_view(array $modalData, string $family, string $title, string $badge, array $familyGroups): array
{
    $family = trim($family) !== '' ? trim($family) : 'external';
    $familyData = is_array($modalData[$family] ?? null) ? $modalData[$family] : [];
    $serviceOptions = is_array($familyData['service_options'] ?? null) ? $familyData['service_options'] : [];
    $onuTypeOptions = (array)($serviceOptions['onu_types'] ?? []);

    $oltOptions = [];
    foreach ($familyGroups as $group) {
        if (!is_array($group)) continue;
        $oltId = (int)($group['olt']['id'] ?? 0);
        if ($oltId < 1) continue;
        $oltName = trim((string)($group['olt']['name'] ?? 'OLT'));
        $oltIp = trim((string)($group['olt']['ip_address'] ?? ''));
        $oltOptions[$oltId] = ['id' => $oltId, 'label' => $oltName . ($oltIp !== '' ? ' — ' . $oltIp : '')];
    }

    $vlanByOlt = is_array($familyData['vlan_by_olt'] ?? null) ? $familyData['vlan_by_olt'] : [];
    $profileByOlt = is_array($familyData['profile_by_olt'] ?? null) ? $familyData['profile_by_olt'] : [];
    foreach ($profileByOlt as &$profiles) {
        if (!is_array($profiles) || !is_array($profiles['vlan_profile'] ?? null)) continue;
        foreach ($profiles['vlan_profile'] as &$profile) {
            if (!is_array($profile)) continue;
            $name = trim((string)($profile['name'] ?? ''));
            $vlan = (int)($profile['cvlan'] ?? 0);
            $profile['label'] = $name . ($vlan > 0 ? ' · VLAN ' . $vlan : '');
        }
        unset($profile);
    }
    unset($profiles);
    $deviceByOlt = is_array($familyData['device_by_olt'] ?? null) ? $familyData['device_by_olt'] : [];

    $slug = (string)preg_replace('/[^a-z0-9-]/i', '', $family);
    return [
        'addOnuModalFamily' => $family,
        'addOnuModalTitle' => trim($title) !== '' ? trim($title) : 'Registrasi ONU',
        'addOnuModalBadge' => trim($badge),
        'addOnuModalSlug' => $slug,
        'addOnuModalId' => 'addOnuRegisterModal-' . $slug,
        'addOnuModalOltOptions' => array_values(array_filter(array_map('is_array', $oltOptions))),
        'addOnuModalVlanByOlt' => $vlanByOlt,
        'addOnuModalProfileByOlt' => $profileByOlt,
        'addOnuModalDeviceByOlt' => $deviceByOlt,
        'addOnuModalVlanOptions' => array_values(array_filter(array_map('intval', (array)($serviceOptions['vlans'] ?? [])), static fn(int $v): bool => $v >= 1 && $v <= 4095)),
        'addOnuModalDownloadOptions' => array_values(array_filter(array_map('strval', (array)($serviceOptions['traffic_profiles'] ?? [])), static fn(string $v): bool => $v !== '')),
        'addOnuModalUploadOptions' => array_values(array_filter(array_map('strval', (array)($serviceOptions['tcont_profiles'] ?? [])), static fn(string $v): bool => $v !== '')),
        'addOnuModalTypeOptions' => array_values(array_filter(array_map('strval', $onuTypeOptions), static fn(string $v): bool => $v !== '')),

        'addOnuServiceOptions' => $serviceOptions,
    ];
}
