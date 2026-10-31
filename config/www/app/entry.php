<?php

declare(strict_types=1);

require_once __DIR__ . '/internal-api-client.php';
if (!function_exists('render_flash_toast')) require_once project_path('view', 'bootstrap.php');

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$operation = trim((string)($_POST['action'] ?? ''));
$requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$basePath = deployment_base_path();
if ($basePath !== '' && str_starts_with($requestPath, $basePath . '/')) $requestPath = substr($requestPath, strlen($basePath));
$requestPath = '/' . trim($requestPath, '/');
$activationUrl = public_app_url('activate');
$setupUrl = public_app_url('setup');

$entry = internal_api_request(current_user_id(), 'app.entry.read');
if (!isset($entry['mode']) || !is_array($entry['identity'] ?? null)) {
    throw new RuntimeException('Kontrak entry aplikasi tidak lengkap.');
}
$mode = (string)$entry['mode'];
if ($requestMethod === 'POST' && in_array($operation, ['access_submit', 'setup_submit'], true)) {
    try {
        verify_csrf();
        if ($operation === 'access_submit' && ($requestPath !== '/activate' || !in_array($mode, ['pending', 'fault'], true))) {
            throw new RuntimeException('Aktivasi tidak tersedia.');
        }
        if ($operation === 'setup_submit' && ($requestPath !== '/setup' || $mode !== 'ready')) {
            throw new RuntimeException('Setup akun tidak tersedia.');
        }
        $result = internal_api_request(0, 'app.entry.action', [
            'action' => $operation,
            'data' => $operation === 'access_submit'
                ? ['account' => $_POST['account'] ?? '', 'token' => $_POST['token'] ?? '']
                : ['display_name' => $_POST['display_name'] ?? '', 'password' => $_POST['password'] ?? '',
                    'password_confirm' => $_POST['password_confirm'] ?? '',
                    'email' => $_POST['email'] ?? '', 'company' => $_POST['company'] ?? ''],
        ], 60);
        if ($operation === 'access_submit') {
            unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['display_name'], $_SESSION['role']);
            session_regenerate_id(true);
            flash('success', 'Aktivasi berhasil.');
            header('Location: ' . public_app_url(), true, 303);
            exit;
        }
        $createdUser = is_array($result['user'] ?? null) ? $result['user'] : null;
        if ($createdUser === null) throw new RuntimeException('Akun yang baru dibuat belum dapat dimuat.');
        linnms_sign_in_user($createdUser);
        flash('success', 'Akun berhasil dibuat.');
        header('Location: ' . public_page_url('dashboard'), true, 303);
        exit;
    } catch (Throwable $error) {
        flash('error', $error->getMessage());
        header('Location: ' . ($operation === 'access_submit' ? $activationUrl : $setupUrl), true, 303);
        exit;
    }
}

$identity = $entry['identity'];
$appName = (string)$identity['name'];
$appVersion = (string)$identity['version'];
if (in_array($mode, ['pending', 'fault'], true)) {
    if ($requestPath !== '/activate') { header('Location: ' . $activationUrl, true, 303); exit; }
    $presetUser = '';
    $flashes = pull_flashes();
    require project_path('view', 'access.php');
    exit;
}
if ($mode === 'ended') {
    $expiredUrl = public_app_url('expired.html');
    $serviceWhatsapp = (string)($entry['service_whatsapp'] ?? '');
    if ($serviceWhatsapp !== '') $expiredUrl .= '?wa=' . rawurlencode($serviceWhatsapp);
    header('Location: ' . $expiredUrl, true, 303);
    exit;
}
if (empty($entry['profile_exists'])) {
    if ($requestPath !== '/setup') { header('Location: ' . $setupUrl, true, 303); exit; }
    $flashes = pull_flashes();
    $presetUser = mb_strtolower(trim((string)($entry['subject'] ?? '')));
    $setupContact = is_array($entry['service_contact'] ?? null) ? $entry['service_contact'] : [];
    $presetEmail = (string)($setupContact['email'] ?? '');
    $presetCompany = (string)($setupContact['company'] ?? '');
    require project_path('view', 'setup.php');
    exit;
}
if ($requestPath === '/activate' || $requestPath === '/setup') {
    header('Location: ' . public_page_url(is_logged_in() ? 'dashboard' : 'login'), true, 303);
    exit;
}
if (is_logged_in()) {
    $sessionUser = is_array($entry['session_user'] ?? null) ? $entry['session_user'] : null;
    if ($sessionUser === null || (string)($sessionUser['role'] ?? '') !== current_role()
        || (string)($sessionUser['username'] ?? '') !== current_username()) logout_user();
}
