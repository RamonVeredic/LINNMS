<?php

declare(strict_types=1);

require_once __DIR__ . '/app/paths.php';
require project_path('app', 'bootstrap.php');
require_once project_path('app', 'OltTerminalBrokerClient.php');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
$respond = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $respond(['ok' => false, 'message' => 'Method tidak didukung.'], 405);
if (linnms_enforce_idle_session(false) || !is_logged_in()) $respond(['ok' => false, 'message' => 'Sesi telah berakhir.'], 401);
$csrf = (string)($_SESSION['csrf'] ?? '');
if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $respond(['ok' => false, 'message' => 'Muat ulang halaman lalu coba lagi.'], 419);
$userId = current_user_id();
$user = $db->getUser($userId);
if (!is_array($user) || (int)($user['is_active'] ?? 0) !== 1) $respond(['ok' => false, 'message' => 'Akun tidak aktif.'], 403);
$action = (string)($_POST['action'] ?? '');
if (!in_array($action, ['open', 'exchange', 'close'], true)) $respond(['ok' => false, 'message' => 'Aksi tidak valid.'], 422);
$ref = (string)($_POST['ref'] ?? '');
$rowId = public_resource_id($ref, 'olt');
$device = $rowId !== null ? $db->getEditableDevice($userId, $rowId) : null;
if (!is_array($device) || ($device['device_type'] ?? '') !== 'olt') $respond(['ok' => false, 'message' => 'OLT tidak ditemukan.'], 404);
$token = (string)($_POST['token'] ?? '');
if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) $respond(['ok' => false, 'message' => 'Sesi terminal tidak valid.'], 422);

$key = hash('sha256', implode('|', [session_id(), (string)$userId, (string)$rowId, $token]));
$driverId = (string)device_driver_type($device);
$definition = monitoring_driver_definition($driverId);
if ($action !== 'close') {
    if (!olt_driver_support_enabled($db->getOltDriverSupportFlags(), $driverId)) {
        $respond(['ok' => false, 'message' => 'Driver OLT ini tidak diizinkan atau sedang dinonaktifkan.'], 403);
    }
    if (empty($definition['telnet_cli'])) {
        $respond(['ok' => false, 'message' => 'Terminal tidak tersedia'], 422);
    }
    if ((int)($device['hybrid_status'] ?? 0) !== 1) $respond(['ok' => false, 'message' => 'Aktifkan Telnet terlebih dahulu.'], 422);
}
$data = (string)($_POST['data'] ?? '');
$decoded = base64_decode($data, true);
if ($decoded === false || strlen($decoded) > 8192) $respond(['ok' => false, 'message' => 'Input terminal terlalu panjang.'], 422);
$request = ['method' => 'terminal-' . $action, 'key' => $key, 'data' => $data, 'cols' => (int)($_POST['cols'] ?? 100), 'rows' => (int)($_POST['rows'] ?? 28)];
if ($action === 'open') {
    $username = trim((string)($device['username'] ?? ''));
    $encrypted = (string)($device['password_enc'] ?? '');
    try {
        $password = $encrypted !== '' ? $credentialCipher->decrypt($encrypted) : '';
    } catch (Throwable) {
        $respond(['ok' => false, 'message' => 'Password Telnet tidak dapat dibaca. Simpan kembali melalui Edit Perangkat.'], 422);
    }
    if ($username === '' || $password === '') $respond(['ok' => false, 'message' => 'Isi Username dan Password Telnet di Edit Perangkat terlebih dahulu.'], 422);
    
    $request['olt'] = ['driver_id' => (string)device_driver_type($device), 'device_id' => $rowId, 'user_id' => $userId, 'host' => (string)$device['ip_address'], 'port' => max(1, min(65535, (int)($device['ports'] ?? 23))), 'username' => $username, 'password' => $password];
}
session_write_close();
set_time_limit(120);
$result = OltTerminalBrokerClient::request($request);
$respond($result, !empty($result['ok']) ? 200 : 503);
