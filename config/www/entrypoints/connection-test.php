<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/paths.php';

try {
    require LINNMS_UI_ROOT . '/app/bootstrap.php';
    require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';
} catch (Throwable $error) {
    error_log('[LINNMS] Bootstrap tes koneksi gagal: ' . $error->getMessage());
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Komponen tes koneksi tidak dapat dimuat.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$respond = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(['ok' => false, 'message' => 'Method tidak didukung.'], 405);
}

if (linnms_enforce_idle_session(false) || !is_logged_in()) {
    $respond(['ok' => false, 'message' => 'Sesi aplikasi telah berakhir.'], 401);
}

$providedCsrf = (string)($_POST['csrf'] ?? '');
$storedCsrf = (string)($_SESSION['csrf'] ?? '');
if ($storedCsrf === '' || $providedCsrf === '' || !hash_equals($storedCsrf, $providedCsrf)) {
    $respond(['ok' => false, 'message' => 'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.'], 419);
}

$userId = current_user_id();
$sessionRead = internal_api_request($userId, 'session.user.read');
$user = is_array($sessionRead['user'] ?? null) ? $sessionRead['user'] : null;
if (!is_array($user) || (int)($user['is_active'] ?? 0) !== 1) {
    $respond(['ok' => false, 'message' => 'Akun tidak aktif.'], 403);
}

require_once LINNMS_UI_ROOT . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'connection-test.php';
$response = connection_test_result($userId, $_POST);
$respond((array)$response['payload'], (int)$response['status']);
