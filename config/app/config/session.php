<?php

declare(strict_types=1);

if (!defined('LINNMS_SESSION_NAME')) {
    define('LINNMS_SESSION_NAME', 'app_v1_0_1');
}

const LINNMS_SESSION_IDLE_SECONDS = 43200;

function linnms_start_managed_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';
    if (defined('LINNMS_SESSION_NAME')) session_name((string)LINNMS_SESSION_NAME);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string)(LINNMS_SESSION_IDLE_SECONDS + 300));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function linnms_enforce_idle_session(bool $touchActivity = true): bool
{
    if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] < 1) return false;

    $now = time();
    $lastActivity = filter_var(
        $_SESSION['last_activity_at'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($lastActivity === false || $lastActivity === null) {
        $_SESSION['last_activity_at'] = $now;
        return false;
    }

    if (($now - (int)$lastActivity) < LINNMS_SESSION_IDLE_SECONDS) {
        if ($touchActivity) $_SESSION['last_activity_at'] = $now;
        return false;
    }

    $_SESSION = [];
    session_regenerate_id(true);
    return true;
}

function linnms_mark_session_activity(): void
{
    if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) {
        $_SESSION['last_activity_at'] = time();
    }
}

function linnms_session_idle_remaining(): int
{
    $lastActivity = (int)($_SESSION['last_activity_at'] ?? time());
    return max(0, LINNMS_SESSION_IDLE_SECONDS - max(0, time() - $lastActivity));
}
