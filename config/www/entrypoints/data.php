<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/paths.php';
require_once LINNMS_UI_ROOT . '/app/bootstrap.php';
require_once project_path('view', 'bootstrap.php');
require_once LINNMS_UI_ROOT . '/controllers/data.php';
require_once LINNMS_UI_ROOT . '/controllers/device-forms.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$respond = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
};

$type = trim((string)($_GET['type'] ?? ''));
$respondError = static function (string $code) use ($respond, $type): never {
    $response = data_error_result($code, $type);
    $respond($response['payload'], $response['status']);
};

if (linnms_enforce_idle_session(false)) {
    $respondError('unauthorized');
}

if (!is_logged_in()) {
    $respondError('unauthorized');
}

$userId = current_user_id();

if ($type === 'device-add-form') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') $respondError('method_not_allowed');
    $response = device_add_form_result($userId);
    $respond($response['payload'], $response['status']);
}

if ($type === 'device-edit-form') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') $respondError('method_not_allowed');
    $response = device_edit_form_result($userId, (int)($_GET['id'] ?? 0));
    $respond($response['payload'], $response['status']);
}

if ($type === 'device-connection-status') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') $respondError('method_not_allowed');
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))), static fn(int $id): bool => $id > 0)));
    $ids = array_slice($ids, 0, 100);
    session_write_close();
    $response = data_snapshot_read_result($userId, ['type' => $type, 'ids' => $ids]);
    $respond($response['payload'], $response['status']);
}

if ($type === 'dashboard-detail') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $respondError('method_not_allowed');
    }
    session_write_close();
    $response = dashboard_detail_result($userId, $_GET);
    $respond($response['payload'], $response['status']);
}

if ($type === 'whatsapp-credential-fields') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $respondError('method_not_allowed');
    }

    session_write_close();
    $response = whatsapp_fields_read_result($userId, trim((string)($_GET['provider'] ?? '')));
    $respond($response['payload'], $response['status']);
}


if ($type === 'recent-activity') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $respondError('method_not_allowed');
    }
    session_write_close();
    $response = recent_activity_result($userId, $_GET);
    $respond($response['payload'], $response['status']);
}

if ($type === 'onu-ethernet-port' || $type === 'zte-onu-ethernet') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $respondError('method_not_allowed');
    }
    $onuRowId = (int)($_GET['onu_row_id'] ?? 0);
    if ($onuRowId < 1) {
        $respondError('validation');
    }
    if ($type === 'onu-ethernet-port') {
        $portNumber = (int)($_GET['port'] ?? 0);
        if ($portNumber < 1) $respondError('port_invalid');
        session_write_close();
        $response = data_snapshot_read_result($userId, ['type' => $type, 'onu_row_id' => $onuRowId, 'port' => $portNumber]);
        $respond($response['payload'], $response['status']);
    }
    session_write_close();
    $response = onu_snapshot_detail_result($userId, $type, $onuRowId);
    $respond($response['payload'], $response['status']);
}

if ($type === 'zte-onu-tr069') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') $respondError('method_not_allowed');
    $onuRowId = (int)($_GET['onu_row_id'] ?? 0);
    if ($onuRowId < 1) $respondError('validation');
    session_write_close();
    $response = onu_snapshot_detail_result($userId, $type, $onuRowId);
    $respond($response['payload'], $response['status']);
}

if ($type === 'zte-onu-remote-interface') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') $respondError('method_not_allowed');
    $onuRowId = (int)($_GET['onu_row_id'] ?? 0);
    $kind = trim((string)($_GET['kind'] ?? ''));
    if ($onuRowId < 1 || !in_array($kind, ['wifi', 'veip'], true)) $respondError('validation');
    session_write_close();
    $response = onu_snapshot_detail_result($userId, $type, $onuRowId, $kind);
    $respond($response['payload'], $response['status']);
}

if ($type === 'olt-scan-status') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $respondError('method_not_allowed');
    }
    session_write_close();
    $scanRowId = (int)($_GET['olt_row_id'] ?? 0);
    if ($scanRowId < 1) {
        $respondError('validation');
    }
    $response = data_snapshot_read_result($userId, ['type' => $type, 'olt_row_id' => $scanRowId]);
    $respond($response['payload'], $response['status']);
}

$respondError('unsupported');
