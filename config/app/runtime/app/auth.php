<?php

declare(strict_types=1);

function linnms_sign_in_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)($user['id'] ?? 0);
    $_SESSION['username'] = (string)($user['username'] ?? '');
    $_SESSION['display_name'] = (string)($user['display_name'] ?? '');
    $_SESSION['role'] = (string)($user['role'] ?? 'user');
    linnms_mark_session_activity();
}

function require_admin_role(): void
{
    if (!is_admin()) {
        flash('error', 'Tidak memiliki akses');
        redirect('?page=dashboard');
    }
}

function logout_user(): never
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => (string)($params['path'] ?? '/'),
            'domain' => (string)($params['domain'] ?? ''),
            'secure' => (bool)($params['secure'] ?? false),
            'httponly' => (bool)($params['httponly'] ?? true),
            'samesite' => (string)($params['samesite'] ?? 'Lax'),
        ]);
    }
    session_destroy();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Clear-Site-Data: "cache"');
    header('Location: ' . public_page_url('login'), true, 303);
    exit;
}
