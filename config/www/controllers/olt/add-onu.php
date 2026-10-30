<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';
require_once __DIR__ . '/add-onu-view.php';

function add_onu_read_data(int $userId): array
{
    return internal_api_request($userId, 'onu.add.read');
}

function add_onu_page_model(int $userId): array
{
    $read = add_onu_read_data($userId);
    $families = is_array($read['families'] ?? null) ? $read['families'] : [];
    $familyGroups = is_array($read['family_groups'] ?? null) ? $read['family_groups'] : [];
    $modalData = is_array($read['modal_data'] ?? null) ? $read['modal_data'] : [];
    $modalViews = [];
    foreach ($families as $family) {
        $family = in_array($family, ['hiosofamily', 'ztefamily'], true) ? $family : 'external';
        $badge = match ($family) {
            'hiosofamily' => 'Hioso',
            'ztefamily' => 'ZTE',
            default => '',
        };
        $modalViews[] = add_onu_modal_view($modalData, $family, 'Registrasi ONU', $badge,
            (array)($familyGroups[$family] ?? []));
    }
    return [
        'pending_onus' => is_array($read['pending_onus'] ?? null) ? $read['pending_onus'] : [],
        'groups' => is_array($read['groups'] ?? null) ? $read['groups'] : [],
        'modal_views' => $modalViews,
    ];
}
