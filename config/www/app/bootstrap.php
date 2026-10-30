<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';
require_once dirname(LINNMS_UI_ROOT) . '/app/config/session.php';
linnms_start_managed_session();
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/internal-api-client.php';
$bootstrapData = internal_api_request(current_user_id(), 'app.bootstrap.read');
if (!is_array($bootstrapData['driver_catalog'] ?? null)
    || !is_array($bootstrapData['device_access_permissions'] ?? null)) {
    throw new RuntimeException('Kontrak data awal aplikasi tidak lengkap.');
}
$GLOBALS['linnms_driver_catalog'] = $bootstrapData['driver_catalog'];
$GLOBALS['linnms_device_access_permissions'] = $bootstrapData['device_access_permissions'];
if (!is_array($bootstrapData['olt_type_options'] ?? null)
    || !is_array($bootstrapData['whatsapp_providers'] ?? null)
    || !is_array($bootstrapData['onu_backup_limits'] ?? null)) {
    throw new RuntimeException('Kontrak data awal aplikasi tidak lengkap.');
}
$GLOBALS['linnms_olt_type_options'] = $bootstrapData['olt_type_options'];
$GLOBALS['linnms_whatsapp_providers'] = $bootstrapData['whatsapp_providers'];
$GLOBALS['linnms_onu_backup_limits'] = $bootstrapData['onu_backup_limits'];
if (current_user_id() > 0) route_token_key();
