<?php

declare(strict_types=1);

function csrf_token(): string
{
    if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || $_SESSION['csrf'] === '') {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function redirect_invalid_session_to_login(string $message = 'Sesi login tidak valid atau telah berakhir. Silakan masuk kembali.'): never
{

    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    csrf_token();
    if ($message !== '') {
        flash('warning', $message);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Location: ' . public_page_url('login'), true, 303);
    exit;
}

function verify_csrf(): void
{
    $provided = $_POST['csrf'] ?? '';
    $stored = $_SESSION['csrf'] ?? '';
    if (is_string($provided) && is_string($stored) && $stored !== '' && hash_equals($stored, $provided)) {
        return;
    }

    if (function_exists('application_log_event')) {
        application_log_event(current_user_id() ?: null, 'Keamanan',
            mb_substr(trim((string)($_POST['action'] ?? 'unknown_post')), 0, 120),
            'error', 'Request ditolak', ['http_status' => 419], current_username() ?: null);
    }

    if (is_logged_in()) {

        redirect_invalid_session_to_login();
    }

    $isPublicLogin = !is_logged_in()
        && (string)($_POST['action'] ?? '') === 'login';
    if ($isPublicLogin) {
        redirect_invalid_session_to_login('');
    }

    http_response_code(419);
    exit('Muat ulang halaman lalu coba lagi');
}

function current_user_id(): int
{
    return is_logged_in() ? (int) $_SESSION['user_id'] : 0;
}

function current_role(): string
{
    return isset($_SESSION['role']) ? (string) $_SESSION['role'] : '';
}

function current_username(): string
{
    return isset($_SESSION['username']) ? (string) $_SESSION['username'] : '';
}

function current_display_name(): string
{
    $name = isset($_SESSION['display_name']) ? trim((string) $_SESSION['display_name']) : '';
    return $name !== '' ? $name : current_username();
}

function auth_mode_icon_class(mixed $mode): string
{
    return match (strtolower(trim((string)$mode))) {
        'undef' => 'bi-shield-exclamation',
        'pass' => 'bi-shield-check',
        'deny', 'disable', 'disabled' => 'bi-shield-x',
        default => 'bi-shield',
    };
}
