<?php
declare(strict_types=1);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'paths.php';
require_once dirname(LINNMS_ROOT) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'session.php';
linnms_start_managed_session();

require_once project_path('src', 'helpers.php');
require_once project_path('app', 'CredentialCipher.php');
require_once LINNMS_UI_ROOT . '/controllers/live.php';

$liveConfig = require project_path('config', 'defaults.php');
$credentialCipher = new CredentialCipher((string)$liveConfig['credential_key']);

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

$respondError = static function (string $code) use ($respond): never {
    $response = live_error_result($code);
    $respond($response['payload'], $response['status']);
};

if (linnms_enforce_idle_session(false)) {
    $respondError('unauthorized');
}

if (!is_logged_in()) {
    $respondError('unauthorized');
}

$userId = current_user_id();
$type = trim((string)($_GET['type'] ?? ''));


$directSnmpTypes = ['device-traffic', 'device-interface-rates'];
if (in_array($type, $directSnmpTypes, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $respondError('method_not_allowed');
    }

    $resource = public_resource_identity((string)($_GET['ref'] ?? ''));
    $contextGroup = (string)($resource['type'] ?? '');
    $rowId = (int)($resource['id'] ?? 0);
    $context = $rowId > 0
        ? ($_SESSION['linnms_direct_realtime'][$contextGroup][(string)$rowId] ?? null)
        : null;
    $validContext = is_array($context)
        && (int)($context['user_id'] ?? 0) === $userId
        && (int)($context['row_id'] ?? 0) === $rowId
        && (int)($context['expires_at'] ?? 0) >= time();
    session_write_close();

    if (!$validContext) {
        $respondError('conflict');
    }

    $response = live_direct_result($userId, $type, $context, $_GET);
    $respond($response['payload'], $response['status']);
}

$deviceActionTypes = [
    'olt-uplink-save', 'olt-pon-status-save', 'olt-pon-flow-control-save',
    'olt-profile-save', 'olt-profile-delete', 'onu-interface-state-save',
    'onu-service-config-delete', 'onu-tr069-save', 'onu-remote-access-save',
    'onu-veip-save', 'onu-wan-ip-save', 'onu-data-service-save',
    'onu-ethernet-save', 'onu-wifi-ssid-save', 'onu-wifi-config-save',
    'onu-unconfigured-refresh', 'start-olt-discovery', 'onu-action',
    'olt-view-refresh', 'olt-port-update',
];
if (in_array($type, $deviceActionTypes, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $respondError('method_not_allowed');
    verify_csrf();
    $input = $_POST;
    if (in_array($type, ['olt-view-refresh', 'olt-port-update'], true)) {
        $input['olt_row_id'] = public_resource_id((string)($_GET['ref'] ?? ''), 'olt') ?? 0;
    }
    $inputError = live_device_action_input_error($type, $input);
    if ($inputError !== null) $respond(['ok' => false, 'message' => $inputError], 422);
    session_write_close();
    if (in_array($type, ['onu-action', 'olt-view-refresh'], true)) @set_time_limit(0);
    $response = live_device_action_result($userId, $type, $input);
    $respond($response['payload'], $response['status']);
}

$trafficTypes = [
    'pon-traffic', 'pon-traffic-live', 'onu-traffic', 'onu-traffic-live',
    'device-traffic-history', 'device-traffic-record',
];
if (in_array($type, $trafficTypes, true)) {
    $expectedMethod = $type === 'device-traffic-record' ? 'POST' : 'GET';
    if ($_SERVER['REQUEST_METHOD'] !== $expectedMethod) {
        $respondError('method_not_allowed');
    }
    if ($expectedMethod === 'POST') verify_csrf();
    $input = $expectedMethod === 'POST' ? $_POST : $_GET;
    $inputError = live_traffic_input_error($type, $input);
    if ($inputError !== null) $respond(['ok' => false, 'message' => $inputError], 422);
    session_write_close();
    $response = live_traffic_result($userId, $type, $input);
    $respond($response['payload'], $response['status']);
}

$respondError('unsupported');
