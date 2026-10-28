<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';

function onu_list_read_data(int $userId, array $filters, int $page, bool $includeLookups = false): array
{
    $data = internal_api_request($userId, 'onu.list.read', [
        'filters' => $filters,
        'page' => $page,
        'include_lookups' => $includeLookups,
    ]);
    $data['hasZte'] = !empty($data['has_zte']);
    return $data;
}
