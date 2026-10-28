<?php

declare(strict_types=1);

final class AuthGuestActionService
{
    public function __construct(private LinnmsRepository $db, private RuntimeBridge $bridge)
    {
    }

    public function execute(string $action, array $data): array
    {
        if (!in_array($action, ['login', 'register_account', 'register_otp_request', 'register_otp_verify', 'availability'], true)) {
            return ['ok' => false, 'code' => 'unsupported'];
        }
        if (!$this->accessReady()) return ['ok' => false, 'code' => 'unavailable'];
        $ip = trim((string)($data['ip_address'] ?? ''));
        if ($action === 'login') return $this->login($data, $ip);
        if (!$this->registrationAllowed()) return ['ok' => false, 'code' => 'unavailable'];
        if ($action === 'availability') return ['ok' => true, 'code' => 'ok'];
        return match ($action) {
            'register_account' => $this->registerAccount($data, $ip),
            'register_otp_request' => $this->requestOtp($data, $ip),
            'register_otp_verify' => $this->verifyOtp($data, $ip),
        };
    }

    private function registrationAllowed(): bool
    {
        return $this->bridge->allowsPublicRegistration();
    }

    private function accessReady(): bool
    {
        $state = $this->bridge->state();
        if (($state['mode'] ?? '') !== 'ready') return false;
        $subject = mb_strtolower(trim((string)($state['row']['subject'] ?? '')));
        if ($subject === '') return false;
        $query = $this->db->pdo()->prepare("SELECT 1 FROM users WHERE username=:username AND role='admin' AND is_active=1 LIMIT 1");
        $query->execute(['username' => $subject]);
        return $query->fetchColumn() !== false;
    }

    private function login(array $data, string $ip): array
    {
        $result = $this->db->authenticateResult(
            trim((string)($data['username'] ?? '')),
            (string)($data['password'] ?? ''),
            $ip
        );
        $user = $result['user'] ?? null;
        if (!is_array($user)) return ['ok' => false, 'code' => ($result['status'] ?? '') === 'inactive' ? 'inactive' : 'invalid_credentials'];
        return ['ok' => true, 'code' => 'ok', 'data' => [
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'display_name' => (string)($user['display_name'] ?? ''),
            'role' => (string)($user['role'] ?? 'user'),
        ]];
    }

    private function registerAccount(array $data, string $ip): array
    {
        $username = trim((string)($data['username'] ?? ''));
        try {
            $remaining = $this->db->registrationThrottleRemaining($ip);
            if ($remaining > 0) return ['ok' => false, 'code' => 'rate_limited', 'data' => ['wait_seconds' => $remaining]];
            $guard = registration_form_guard_error($data, (int)($data['form_started_at'] ?? 0));
            if ($guard !== null) {
                $code = match ($guard) {
                    'Pendaftaran tidak dapat diproses.' => 'guard_honeypot',
                    'Sesi pendaftaran tidak ditemukan. Muat ulang halaman pendaftaran.' => 'guard_missing',
                    'Formulir terkirim terlalu cepat. Silakan periksa kembali isian Anda.' => 'guard_fast',
                    default => 'guard_expired',
                };
                $this->registrationFailure($ip, $username, 'register_account', $guard);
                return ['ok' => false, 'code' => $code];
            }
            $account = registration_account_validate($data, $this->db);
            if (empty($account['ok'])) {
                $field = (string)($account['field'] ?? '');
                $code = match ($field) {
                    'display_name' => match ((string)($account['error'] ?? '')) {
                        'Nama wajib diisi.' => 'name_required',
                        'Nama minimal 2 karakter.' => 'name_short',
                        default => 'name_long',
                    },
                    'username' => 'username_invalid',
                    'password' => 'password_weak',
                    'password_confirm' => 'password_mismatch',
                    default => 'validation',
                };
                $this->registrationFailure($ip, $username, 'register_account', (string)($account['error'] ?? 'Validasi pendaftaran gagal.'));
                return ['ok' => false, 'code' => $code, 'data' => ['field' => $field]];
            }
            $this->db->clearRegistrationFailures($ip);
            return ['ok' => true, 'code' => 'ok', 'data' => [
                'username' => (string)$account['username'],
                'display_name' => (string)$account['display_name'],
            ]];
        } catch (Throwable $error) {
            $this->registrationFailure($ip, $username, 'register_account', (string)$error);
            return ['ok' => false, 'code' => (string)$error->getCode() === '23000' ? 'username_invalid' : 'internal'];
        }
    }

    private function requestOtp(array $data, string $ip): array
    {
        $username = trim((string)($data['username'] ?? ''));
        $phone = normalize_phone_number((string)($data['phone'] ?? ''));
        if (preg_match('/^62[0-9]{8,15}$/', $phone) !== 1) {
            $this->log('register_otp_request_validation', 'Validasi', 'Nomor WhatsApp tidak valid.', $username, $ip);
            return ['ok' => false, 'code' => 'phone_invalid'];
        }
        if ($username === '' || (string)($data['password'] ?? '') === '') {
            $this->log('register_otp_request_validation', 'Validasi', 'Sesi pendaftaran tidak lengkap.', $username, $ip);
            return ['ok' => false, 'code' => 'session_incomplete'];
        }
        try {
            $otp = $this->bridge->registrationOtpRequest($phone);
            $verifiedPhone = (string)($otp['phone'] ?? '');
            $challenge = (int)($otp['challenge'] ?? 0);
            if ($verifiedPhone === '' || $challenge < 1) throw new RuntimeException('Respons OTP tidak valid.');
            return ['ok' => true, 'code' => 'ok', 'data' => [
                'phone' => $verifiedPhone,
                'challenge' => $challenge,
                'rate_wait' => max(0, (int)($otp['rate_limit_retry_after_seconds'] ?? 0)),
            ]];
        } catch (RemoteFault $error) {
            $this->log('register_otp_request', 'LINNMS APP', (string)$error, $username, $ip);
            if ($error->remoteCode === 'otp_rate_limited' && $error->retryAfterSeconds > 0) {
                return ['ok' => false, 'code' => 'rate_limited', 'data' => ['wait_seconds' => $error->retryAfterSeconds]];
            }
            return ['ok' => false, 'code' => 'otp_failed'];
        } catch (Throwable $error) {
            $this->log('register_otp_request', 'LINNMS APP', (string)$error, $username, $ip);
            return ['ok' => false, 'code' => 'internal'];
        }
    }

    private function verifyOtp(array $data, string $ip): array
    {
        $username = trim((string)($data['username'] ?? ''));
        $code = trim((string)($data['code'] ?? ''));
        if (preg_match('/^[0-9]{' . LinnmsRepository::WHATSAPP_OTP_LENGTH . '}$/', $code) !== 1) {
            $this->log('register_otp_verify_validation', 'Validasi', 'Kode OTP tidak valid.', $username, $ip);
            return ['ok' => false, 'code' => 'code_invalid'];
        }
        try {
            $verified = $this->bridge->registrationOtpVerify(
                (string)($data['phone'] ?? ''),
                (int)($data['challenge'] ?? 0),
                $code
            );
            if (empty($verified['verified'])) throw new RuntimeException('Respons verifikasi OTP tidak valid.');
            $newUserId = $this->db->createUser(
                $username,
                (string)($data['password'] ?? ''),
                (string)($data['display_name'] ?? ''),
                LinnmsRepository::DEFAULT_OLT_LIMIT
            );
            $this->db->setUserPhoneNumber($newUserId, (string)($data['phone'] ?? ''));
            return ['ok' => true, 'code' => 'ok'];
        } catch (Throwable $error) {
            $this->log('register_otp_verify', 'LINNMS APP', (string)$error, $username, $ip);
            if ((string)$error->getCode() === '23000') return ['ok' => false, 'code' => 'username_invalid'];
            if ($error instanceof RemoteFault) {
                return ['ok' => false, 'code' => $error->remoteCode === 'otp_rate_limited' ? 'rate_limited' : 'otp_invalid'];
            }
            return ['ok' => false, 'code' => $error instanceof InvalidArgumentException ? 'validation' : 'internal'];
        }
    }

    private function registrationFailure(string $ip, string $username, string $action, string $message): void
    {
        try {
            $this->db->registerRegistrationFailure($ip);
        } catch (Throwable $error) {
            error_log('[LINNMS] Registration throttle: ' . $error->getMessage());
        }
        $this->log($action, 'Validasi', $message, $username, $ip);
    }

    private function log(string $action, string $area, string $message, string $username, string $ip): void
    {
        try {
            $this->db->logApplication(null, $area, $action, 'error', $message, [],
                $username !== '' ? $username : null, $ip !== '' ? $ip : null);
        } catch (Throwable $error) {
            error_log('[LINNMS] Registration log: ' . $error->getMessage());
        }
    }
}
