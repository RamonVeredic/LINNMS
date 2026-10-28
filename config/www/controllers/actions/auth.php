<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';

function linnms_auth_guest_request(string $action, array $data = []): array
{
    $data['ip_address'] = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    try {
        return internal_api_request(0, 'auth.guest', ['action' => $action, 'data' => $data], 45);
    } catch (Throwable $error) {
        error_log('[LINNMS] Auth guest API: ' . $error->getMessage());
        return ['ok' => false, 'code' => 'internal'];
    }
}

function linnms_registration_async_request(): bool
{
    return strcasecmp((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
}

function linnms_registration_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function linnms_registration_result_message(string $code, int $waitSeconds = 0): string
{
    return match ($code) {
        'unavailable' => 'Pendaftaran tidak tersedia.',
        'rate_limited' => 'Terlalu banyak percobaan pendaftaran dari jaringan ini. Coba lagi dalam '
            . (int)ceil(max(1, $waitSeconds) / 60) . ' menit.',
        'guard_honeypot' => 'Pendaftaran tidak dapat diproses.',
        'guard_missing' => 'Sesi pendaftaran tidak ditemukan. Muat ulang halaman pendaftaran.',
        'guard_fast' => 'Formulir terkirim terlalu cepat. Silakan periksa kembali isian Anda.',
        'guard_expired' => 'Sesi pendaftaran kedaluwarsa. Muat ulang halaman pendaftaran.',
        'name_required' => 'Nama wajib diisi.',
        'name_short' => 'Nama minimal 2 karakter.',
        'name_long' => 'Nama maksimal 120 karakter.',
        'username_invalid' => 'Username tidak dapat digunakan.',
        'password_weak' => 'Password tidak kuat.',
        'password_mismatch' => 'Konfirmasi password tidak sesuai.',
        'phone_invalid' => 'Nomor WhatsApp tidak valid.',
        'session_incomplete' => 'Sesi pendaftaran tidak lengkap.',
        'code_invalid' => 'Masukkan ' . LinnmsRepository::WHATSAPP_OTP_LENGTH . ' angka kode OTP.',
        default => 'Pendaftaran gagal. Silakan coba lagi.',
    };
}

function handle_auth_action(string $action): bool
{
    if (!in_array($action, ['login', 'register_account', 'register_otp_request',
        'register_otp_cancel', 'register_otp_verify', 'logout'], true)) return false;

    if ($action === 'logout') {
        $GLOBALS['LINNMS_ACTION_LOG'] = [
            'area' => 'LINNMS APP',
            'level' => 'success',
            'message' => 'Pengguna berhasil keluar dari aplikasi.',
        ];
        logout_user();
    }

    if ($action === 'login') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $_SESSION['old_login_username'] = $username;
        if ($username === '' || $password === '') {
            flash('error', 'Username atau password salah');
            redirect('?page=login');
        }
        $result = linnms_auth_guest_request($action, ['username' => $username, 'password' => $password]);
        $code = (string)linnms_action_reason($result, 'internal');
        if ($code === 'inactive') {
            flash('warning', 'Akun Anda sedang dinonaktifkan. Silakan hubungi administrator.');
            redirect('?page=login');
        }
        if (empty($result['ok'])) {
            flash('error', 'Username atau password salah');
            redirect('?page=login');
        }
        $user = is_array($result['data'] ?? null) ? $result['data'] : [];
        unset($_SESSION['old_login_username']);
        linnms_sign_in_user($user);
        redirect('?page=dashboard');
    }

    if ($action === 'register_otp_cancel') {
        unset($_SESSION['register_pending'], $_SESSION['old_register'], $_SESSION['register_validation']);
        $_SESSION['register_form_started_at'] = time() - LinnmsRepository::REGISTRATION_MIN_FILL_SECONDS;
        if (linnms_registration_async_request()) linnms_registration_reply(['ok' => true, 'step' => 'form']);
        redirect('?page=register');
    }

    if (in_array($action, ['register_otp_request', 'register_otp_verify'], true)) {
        $availability = linnms_auth_guest_request('availability');
        if (empty($availability['ok'])) {
            unset($_SESSION['old_register'], $_SESSION['register_pending']);
            if (linnms_registration_async_request()) linnms_registration_reply(['ok' => false, 'message' => 'Pendaftaran tidak tersedia.'], 403);
            redirect('?page=login');
        }
    }

    if ($action === 'register_account') {
        $displayName = registration_sanitize_display_name($_POST['display_name'] ?? '');
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $result = linnms_auth_guest_request($action, [
            'display_name' => $displayName,
            'username' => $username,
            'password' => $password,
            'password_confirm' => (string)($_POST['password_confirm'] ?? ''),
            'website' => (string)($_POST['website'] ?? ''),
            'form_started_at' => (int)($_SESSION['register_form_started_at'] ?? 0),
        ]);
        $code = (string)linnms_action_reason($result, 'internal');
        if ($code === 'unavailable') {
            unset($_SESSION['old_register'], $_SESSION['register_pending']);
            if (linnms_registration_async_request()) linnms_registration_reply(['ok' => false, 'message' => 'Pendaftaran tidak tersedia.'], 403);
            redirect('?page=login');
        }
        if (!empty($result['ok'])) {
            $account = is_array($result['data'] ?? null) ? $result['data'] : [];
            $username = (string)($account['username'] ?? $username);
            $displayName = (string)($account['display_name'] ?? $displayName);
            unset($_SESSION['old_register'], $_SESSION['register_validation']);
            $_SESSION['register_form_started_at'] = time() - LinnmsRepository::REGISTRATION_MIN_FILL_SECONDS;
            $_SESSION['register_pending'] = [
                'username' => $username,
                'display_name' => $displayName,
                'password' => $password,
                'phone' => '',
                'created_at' => time(),
            ];
            if (linnms_registration_async_request()) linnms_registration_reply(['ok' => true, 'step' => 'wa', 'username' => $username]);
            redirect('?page=register&step=wa');
        }
        $_SESSION['old_register'] = ['username' => $username, 'display_name' => $displayName];
        $field = (string)($result['data']['field'] ?? '');
        if ($code === 'username_invalid') $field = 'username';
        $message = linnms_registration_result_message($code, (int)($result['data']['wait_seconds'] ?? 0));
        if (in_array($field, ['display_name', 'username', 'password', 'password_confirm'], true)) {
            $_SESSION['register_validation'] = ['field' => $field, 'message' => $message];
        }
        if (linnms_registration_async_request()) linnms_registration_reply(['ok' => false, 'message' => $message, 'field' => $field], 422);
        flash('error', $message);
        redirect('?page=register');
    }

    if ($action === 'register_otp_request') {
        $pending = is_array($_SESSION['register_pending'] ?? null) ? $_SESSION['register_pending'] : null;
        if ($pending === null) {
            if (linnms_registration_async_request()) linnms_registration_reply(['ok' => false, 'message' => 'Silahkan lengapi formul.'], 422);
            flash('warning', 'Silahkan lengapi formulir');
            redirect('?page=register');
        }
        $username = (string)($pending['username'] ?? '');
        $displayName = (string)($pending['display_name'] ?? '');
        $phoneInput = trim((string)($_POST['phone_number'] ?? ''));
        if ($phoneInput === '') $phoneInput = (string)($pending['phone'] ?? '');
        $normalized = normalize_phone_number($phoneInput);
        $cooldown = is_array($_SESSION['register_otp_cooldown'] ?? null) ? $_SESSION['register_otp_cooldown'] : [];
        $cooldownWait = (string)($cooldown['phone'] ?? '') === $normalized
            ? max(0, (int)($cooldown['until'] ?? 0) - time()) : 0;
        if ($cooldownWait > 0) {
            $message = 'Terlalu banyak permintaan kode untuk nomor ini.';
            if (linnms_registration_async_request()) linnms_registration_reply([
                'ok' => false, 'message' => $message, 'rate_limited' => true, 'wait_seconds' => $cooldownWait,
            ], 429);
            flash('warning', $message);
            redirect('?page=register&step=' . ((int)($pending['otp_challenge'] ?? 0) > 0 ? 'otp' : 'wa'));
        }
        if ($phoneInput === (string)($pending['phone'] ?? '')
            && (int)($pending['otp_challenge'] ?? 0) > 0
            && (int)($pending['otp_requested_at'] ?? 0) + 60 > time()) {
            if (linnms_registration_async_request()) linnms_registration_reply([
                'ok' => true, 'step' => 'otp', 'phone_masked' => mask_phone_number((string)$pending['phone']),
                'wait_seconds' => (int)$pending['otp_requested_at'] + 60 - time(),
            ]);
            redirect('?page=register&step=otp');
        }
        if (preg_match('/^62[0-9]{8,15}$/', $normalized) !== 1) {
            $message = 'Nomor WhatsApp tidak valid.';
            $_SESSION['old_register'] = ['username' => $username, 'display_name' => $displayName, 'phone_number' => $phoneInput];
            $_SESSION['register_validation'] = ['field' => 'phone_number', 'message' => $message];
            if (linnms_registration_async_request()) linnms_registration_reply(['ok' => false, 'message' => $message, 'field' => 'phone_number', 'rate_limited' => false, 'wait_seconds' => 0], 422);
            flash('error', $message);
            redirect('?page=register&step=wa');
        }
        $result = linnms_auth_guest_request($action, [
            'username' => $username,
            'password' => (string)($pending['password'] ?? ''),
            'phone' => $phoneInput,
        ]);
        $code = (string)linnms_action_reason($result, 'internal');
        if ($code === 'unavailable') {
            unset($_SESSION['old_register'], $_SESSION['register_pending']);
            if (linnms_registration_async_request()) linnms_registration_reply(['ok' => false, 'message' => 'Pendaftaran tidak tersedia.'], 403);
            redirect('?page=login');
        }
        if (!empty($result['ok'])) {
            $otp = is_array($result['data'] ?? null) ? $result['data'] : [];
            $phone = (string)($otp['phone'] ?? '');
            $rateWait = max(0, (int)($otp['rate_wait'] ?? 0));
            if ($rateWait > 0) $_SESSION['register_otp_cooldown'] = ['phone' => normalize_phone_number($phone), 'until' => time() + $rateWait];
            $_SESSION['register_pending'] = [
                'username' => $username,
                'display_name' => $displayName,
                'password' => (string)($pending['password'] ?? ''),
                'phone' => $phone,
                'otp_challenge' => (int)($otp['challenge'] ?? 0),
                'otp_requested_at' => time(),
            ];
            unset($_SESSION['old_register'], $_SESSION['register_validation']);
            if (linnms_registration_async_request()) linnms_registration_reply([
                'ok' => true, 'step' => 'otp', 'phone_masked' => mask_phone_number($phone),
                'rate_limited' => $rateWait > 0, 'wait_seconds' => max(60, $rateWait),
            ]);
            redirect('?page=register&step=otp');
        }
        $wait = max(0, (int)($result['data']['wait_seconds'] ?? 0));
        $rateLimited = $code === 'rate_limited';
        if ($rateLimited && $wait > 0) $_SESSION['register_otp_cooldown'] = ['phone' => $normalized, 'until' => time() + $wait];
        if ($code === 'session_incomplete') unset($_SESSION['register_pending']);
        $_SESSION['old_register'] = ['username' => $username, 'display_name' => $displayName, 'phone_number' => $phoneInput];
        $message = $code === 'phone_invalid' ? 'Nomor WhatsApp tidak valid.'
            : ($rateLimited ? 'Terlalu banyak permintaan kode untuk nomor ini.'
                : ($code === 'session_incomplete' ? 'Sesi pendaftaran tidak lengkap.' : 'Kode OTP gagal dikirim. Silakan coba lagi.'));
        if ($code === 'phone_invalid') $_SESSION['register_validation'] = ['field' => 'phone_number', 'message' => $message];
        if (linnms_registration_async_request()) linnms_registration_reply([
            'ok' => false, 'message' => $message, 'field' => $code === 'phone_invalid' ? 'phone_number' : '',
            'rate_limited' => $rateLimited, 'wait_seconds' => $wait,
        ], $rateLimited ? 429 : 422);
        flash('error', $message);
        redirect(is_array($_SESSION['register_pending'] ?? null) ? '?page=register&step=wa' : '?page=register');
    }

    $pending = is_array($_SESSION['register_pending'] ?? null) ? $_SESSION['register_pending'] : null;
    if ($pending === null) {
        if (linnms_registration_async_request()) linnms_registration_reply([
            'ok' => false, 'message' => 'Sesi pendaftaran tidak ditemukan.', 'step' => 'form',
        ], 422);
        flash('warning', 'Sesi pendaftaran tidak ditemukan. Isi kembali formulir pendaftaran.');
        redirect('?page=register');
    }
    $codeInput = trim((string)($_POST['otp_code'] ?? ''));
    if (preg_match('/^[0-9]{' . LinnmsRepository::WHATSAPP_OTP_LENGTH . '}$/', $codeInput) !== 1) {
        $message = linnms_registration_result_message('code_invalid');
        $_SESSION['register_validation'] = ['field' => 'otp_code', 'message' => $message];
        if (linnms_registration_async_request()) linnms_registration_reply(['ok' => false, 'message' => $message, 'field' => 'otp_code'], 422);
        flash('error', $message);
        redirect('?page=register&step=otp');
    }
    $result = linnms_auth_guest_request($action, [
        'username' => (string)($pending['username'] ?? ''),
        'display_name' => (string)($pending['display_name'] ?? ''),
        'password' => (string)($pending['password'] ?? ''),
        'phone' => (string)($pending['phone'] ?? ''),
        'challenge' => (int)($pending['otp_challenge'] ?? 0),
        'code' => $codeInput,
    ]);
    $code = (string)linnms_action_reason($result, 'internal');
    if ($code === 'unavailable') {
        unset($_SESSION['old_register'], $_SESSION['register_pending']);
        if (linnms_registration_async_request()) linnms_registration_reply(['ok' => false, 'message' => 'Pendaftaran tidak tersedia.'], 403);
        redirect('?page=login');
    }
    if (!empty($result['ok'])) {
        unset($_SESSION['register_pending'], $_SESSION['old_register'], $_SESSION['register_validation']);
        $_SESSION['registration_completed'] = true;
        if (linnms_registration_async_request()) linnms_registration_reply(['ok' => true, 'redirect' => public_page_url('login')]);
        redirect('?page=login');
    }
    $message = $code === 'username_invalid' ? 'Username tidak dapat digunakan.'
        : ($code === 'code_invalid' ? linnms_registration_result_message('code_invalid')
            : 'Pendaftaran gagal. Silakan coba lagi.');
    if ($code === 'username_invalid') {
        $_SESSION['old_register'] = [
            'username' => (string)($pending['username'] ?? ''),
            'display_name' => (string)($pending['display_name'] ?? ''),
        ];
        $_SESSION['register_validation'] = ['field' => 'username', 'message' => $message];
        unset($_SESSION['register_pending']);
        if (linnms_registration_async_request()) linnms_registration_reply([
            'ok' => false, 'message' => $message, 'field' => 'username', 'step' => 'form',
        ], 422);
        redirect('?page=register');
    }
    $_SESSION['register_validation'] = ['field' => 'otp_code', 'message' => $message];
    if (linnms_registration_async_request()) linnms_registration_reply(['ok' => false, 'message' => $message, 'field' => 'otp_code'], 422);
    flash('error', $message);
    redirect('?page=register&step=otp');
}
