<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';

function add_onu_read_data(int $userId): array
{
    return internal_api_request($userId, 'onu.add.read');
}
