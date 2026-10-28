<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';

function onu_view_read_data(int $userId, int $rowId): array
{
    return internal_api_request($userId, 'onu.view.read', ['row_id' => $rowId]);
}
