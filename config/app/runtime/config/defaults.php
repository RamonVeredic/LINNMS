<?php

declare(strict_types=1);

require_once __DIR__ . '/config_default.php';

$database = require '/config/app/config/database.php';

return [
    'auth_header' => LinnmsDefaults::AUTH_HEADER,
    'timeout' => LinnmsDefaults::API_TIMEOUT_SECONDS,
    'verify_ssl' => LinnmsDefaults::API_VERIFY_SSL,
    'credential_key' => getenv('LINNMS_CREDENTIAL_KEY') ?: LinnmsDefaults::CREDENTIAL_KEY_FALLBACK,
    'database' => $database,

    'default_admin' => LinnmsDefaults::DEFAULT_ADMIN,

    'endpoints' => LinnmsDefaults::API_ENDPOINTS,

    'sync' => [
        'signal_change_threshold_dbm' => LinnmsDefaults::SIGNAL_CHANGE_THRESHOLD_DBM,
        'telegram_chunk_size' => LinnmsDefaults::TELEGRAM_CHUNK_SIZE,
    ],
    'snmp_devices' => [
        'timeout_us' => LinnmsDefaults::SNMP_DEVICE_TIMEOUT_US,
        'retries' => LinnmsDefaults::SNMP_DEVICE_RETRIES,
    ],
];
