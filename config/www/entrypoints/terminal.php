<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/paths.php';
require_once LINNMS_UI_ROOT . '/app/bootstrap.php';
require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';

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
$rowId = public_resource_id((string)($_POST['ref'] ?? ''), 'olt');
if ($rowId === null) $respond(['ok' => false, 'message' => 'OLT tidak ditemukan.'], 404);
$request = [
    'action' => (string)($_POST['action'] ?? ''),
    'row_id' => $rowId,
    'token' => (string)($_POST['token'] ?? ''),
    'data' => (string)($_POST['data'] ?? ''),
    'cols' => (int)($_POST['cols'] ?? 100),
    'rows' => (int)($_POST['rows'] ?? 28),
    'session_hash' => hash('sha256', session_id()),
];
session_write_close();
try {
    $response = internal_api_request($userId, 'olt.terminal.action', $request, 125);
    $respond((array)($response['payload'] ?? ['ok' => false, 'message' => 'Terminal tidak tersedia.']),
        (int)($response['status'] ?? 503));
} catch (Throwable $error) {
    error_log('[LINNMS] Terminal API: ' . $error->getMessage());
    $respond(['ok' => false, 'message' => 'Terminal tidak tersedia.'], 503);
}
