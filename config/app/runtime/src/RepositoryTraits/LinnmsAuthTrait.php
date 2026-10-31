<?php

declare(strict_types=1);

trait LinnmsAuthTrait
{
    public function isPublicRegistrationEnabled(): bool
    {
        $stmt = $this->pdo->query(
            "SELECT setting_value FROM app_settings WHERE setting_key='public_registration_enabled' LIMIT 1"
        );
        $value = $stmt->fetchColumn();
        return $value === false || (string)$value !== '0';
    }

    public function savePublicRegistrationEnabled(bool $enabled): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO app_settings(setting_key,setting_value) VALUES ('public_registration_enabled',:value)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
        );
        $stmt->execute(['value' => $enabled ? '1' : '0']);
    }

    public function registrationThrottleRemaining(string $ipAddress): int
    {
        $ip = $this->normalizeLoginIp($ipAddress);
        $stmt = $this->pdo->prepare(
            'SELECT failure_count,window_started_at FROM login_attempts
             WHERE username_key=:key AND ip_address=:ip LIMIT 1'
        );
        $stmt->execute(['key' => $this->registrationThrottleKey($ipAddress), 'ip' => $ip]);
        $row = $stmt->fetch();
        if (!is_array($row)) return 0;

        $startedAt = strtotime((string)($row['window_started_at'] ?? ''));
        if ($startedAt === false) return 0;
        $elapsed = time() - $startedAt;
        $window = self::REGISTRATION_THROTTLE_WINDOW_MINUTES * 60;
        if ($elapsed >= $window) return 0;
        if ((int)($row['failure_count'] ?? 0) < self::REGISTRATION_THROTTLE_LIMIT) return 0;

        return max(1, $window - $elapsed);
    }

    public function registerRegistrationFailure(string $ipAddress): void
    {
        $ip = $this->normalizeLoginIp($ipAddress);
        $key = $this->registrationThrottleKey($ipAddress);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM login_attempts WHERE updated_at<DATE_SUB(NOW(), INTERVAL 1 DAY)');
            $this->pdo->prepare(
                'INSERT IGNORE INTO login_attempts(username_key,ip_address,failure_count,window_started_at,blocked_until) '
                . 'VALUES (:key,:ip,0,NOW(),NULL)'
            )->execute(['key' => $key, 'ip' => $ip]);
            $lock = $this->pdo->prepare(
                'SELECT failure_count,window_started_at<DATE_SUB(NOW(), INTERVAL ' . self::REGISTRATION_THROTTLE_WINDOW_MINUTES . ' MINUTE) expired '
                . 'FROM login_attempts WHERE username_key=:key AND ip_address=:ip FOR UPDATE'
            );
            $lock->execute(['key' => $key, 'ip' => $ip]);
            $attempt = $lock->fetch() ?: [];
            $failures = !empty($attempt['expired']) ? 1 : (int)($attempt['failure_count'] ?? 0) + 1;
            $sql = 'UPDATE login_attempts SET failure_count=:failures,blocked_until=NULL'
                . (!empty($attempt['expired']) ? ',window_started_at=NOW()' : '')
                . ' WHERE username_key=:key AND ip_address=:ip';
            $this->pdo->prepare($sql)->execute(['failures' => $failures, 'key' => $key, 'ip' => $ip]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function clearRegistrationFailures(string $ipAddress): void
    {
        $this->pdo->prepare('DELETE FROM login_attempts WHERE username_key=:key AND ip_address=:ip')
            ->execute(['key' => $this->registrationThrottleKey($ipAddress), 'ip' => $this->normalizeLoginIp($ipAddress)]);
    }

    private function registrationThrottleKey(string $ipAddress): string
    {
        return 'register|' . $this->normalizeLoginIp($ipAddress);
    }

    public function getServiceContact(): array
    {
        $stmt = $this->pdo->query(
            "SELECT setting_key,setting_value FROM app_settings
             WHERE setting_key IN ('service_whatsapp','service_email','service_company','service_phone','service_github','service_web')"
        );
        $values = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $values[(string)$row['setting_key']] = trim((string)$row['setting_value']);
        }
        return [
            'whatsapp' => (string)($values['service_whatsapp'] ?? ''),
            'email' => (string)($values['service_email'] ?? ''),
            'company' => (string)($values['service_company'] ?? ''),
            'phone' => (string)($values['service_phone'] ?? ''),
            'github' => (string)($values['service_github'] ?? ''),
            'web' => (string)($values['service_web'] ?? ''),
        ];
    }

    public function saveActivationContact(string $email, string $company): void
    {
        $email = trim($email);
        $company = trim($company);
        if (mb_strlen($email) > 190 || ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new InvalidArgumentException('Alamat email tidak valid.');
        }
        if (mb_strlen($company) > 190) {
            throw new InvalidArgumentException('Nama perusahaan maksimal 190 karakter.');
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO app_settings(setting_key,setting_value) VALUES (:key,:value)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
        );
        $owned = !$this->pdo->inTransaction();
        if ($owned) $this->pdo->beginTransaction();
        try {
            if ($email !== '') $stmt->execute(['key' => 'service_email', 'value' => $email]);
            if ($company !== '') $stmt->execute(['key' => 'service_company', 'value' => $company]);
            if ($owned) $this->pdo->commit();
        } catch (Throwable $e) {
            if ($owned && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function saveServiceContact(string $whatsapp, string $email): void
    {
        $whatsapp = trim($whatsapp);
        $email = trim($email);
        if (mb_strlen($whatsapp) > 40 || ($whatsapp !== '' && preg_match('/^[0-9+().\-\s]+$/', $whatsapp) !== 1)) {
            throw new InvalidArgumentException('Nomor WhatsApp tidak valid.');
        }
        if (mb_strlen($email) > 190 || ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new InvalidArgumentException('Alamat email tidak valid.');
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO app_settings(setting_key,setting_value) VALUES (:key,:value)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
        );
        $this->pdo->beginTransaction();
        try {
            $stmt->execute(['key' => 'service_whatsapp', 'value' => $whatsapp]);
            $stmt->execute(['key' => 'service_email', 'value' => $email]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function ensureDefaultAdmin(string $username, string $password, string $displayName): int
    {
        if ($username === '') return 0;
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            $update = $this->pdo->prepare("UPDATE users SET role='admin', is_active=1, display_name=COALESCE(NULLIF(display_name,''),:display_name) WHERE id=:id");
            $update->execute(['display_name' => $displayName !== '' ? $displayName : 'Administrator', 'id' => $id]);
            return (int) $id;
        }

        $insert = $this->pdo->prepare("INSERT INTO users(username,password,display_name,role,is_active) VALUES (:username,:password,:display_name,'admin',1)");
        $insert->execute(['username' => $username, 'password' => $this->hashUserPassword($password), 'display_name' => $displayName !== '' ? $displayName : 'Administrator']);
        return (int) $this->pdo->lastInsertId();
    }

    public function authenticateResult(string $username, string $password, string $ipAddress = ''): array
    {
        $username = trim($username);
        $attemptKey = mb_strtolower($username);
        $ipAddress = $this->normalizeLoginIp($ipAddress);
        if ($this->loginBackoffActive($attemptKey, $ipAddress)) {
            return ['status' => 'rate_limited', 'user' => null];
        }
        $stmt = $this->pdo->prepare('SELECT id,username,password,display_name,role,is_active FROM users WHERE username=:username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            $this->registerLoginFailure($attemptKey, $ipAddress);
            return ['status' => 'invalid_credentials', 'user' => null];
        }
        if ((int)$row['is_active'] !== 1) {
            $this->registerLoginFailure($attemptKey, $ipAddress);
            return ['status' => 'inactive', 'user' => null];
        }
        if (!$this->verifyUserPassword((int)$row['id'], $password, (string)$row['password'])) {
            $this->registerLoginFailure($attemptKey, $ipAddress);
            return ['status' => 'invalid_credentials', 'user' => null];
        }
        $this->clearLoginFailures($attemptKey, $ipAddress);
        $this->pdo->prepare('UPDATE users SET last_login_at=NOW() WHERE id=:id')->execute(['id' => $row['id']]);
        return ['status' => 'success', 'user' => $row];
    }

    public function authenticate(string $username, string $password, string $ipAddress = ''): ?array
    {
        $result = $this->authenticateResult($username, $password, $ipAddress);
        return is_array($result['user'] ?? null) ? $result['user'] : null;
    }

    private function loginBackoffActive(string $usernameKey, string $ipAddress): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM login_attempts WHERE username_key=:username AND ip_address=:ip '
            . 'AND window_started_at>=DATE_SUB(NOW(), INTERVAL ' . self::LOGIN_WINDOW_MINUTES . ' MINUTE) '
            . 'AND blocked_until>NOW() LIMIT 1'
        );
        $stmt->execute(['username' => $usernameKey, 'ip' => $ipAddress]);
        return $stmt->fetchColumn() !== false;
    }

    private function registerLoginFailure(string $usernameKey, string $ipAddress): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM login_attempts WHERE updated_at<DATE_SUB(NOW(), INTERVAL 1 DAY)');
            $this->pdo->prepare(
                'INSERT IGNORE INTO login_attempts(username_key,ip_address,failure_count,window_started_at) '
                . 'VALUES (:username,:ip,0,NOW())'
            )->execute(['username' => $usernameKey, 'ip' => $ipAddress]);
            $lock = $this->pdo->prepare(
                'SELECT failure_count,window_started_at<DATE_SUB(NOW(), INTERVAL ' . self::LOGIN_WINDOW_MINUTES . ' MINUTE) expired '
                . 'FROM login_attempts WHERE username_key=:username AND ip_address=:ip FOR UPDATE'
            );
            $lock->execute(['username' => $usernameKey, 'ip' => $ipAddress]);
            $attempt = $lock->fetch() ?: [];
            $failures = !empty($attempt['expired']) ? 1 : (int)($attempt['failure_count'] ?? 0) + 1;
            $delay = $failures >= self::LOGIN_FAILURE_LIMIT
                ? min(self::LOGIN_MAX_BACKOFF_SECONDS, 2 ** min(10, $failures - self::LOGIN_FAILURE_LIMIT + 1))
                : 0;
            $sql = 'UPDATE login_attempts SET failure_count=:failures,'
                . (!empty($attempt['expired']) ? 'window_started_at=NOW(),' : '')
                . ($delay > 0 ? "blocked_until=DATE_ADD(NOW(), INTERVAL {$delay} SECOND)" : 'blocked_until=NULL')
                . ' WHERE username_key=:username AND ip_address=:ip';
            $this->pdo->prepare($sql)->execute(['failures' => $failures, 'username' => $usernameKey, 'ip' => $ipAddress]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function clearLoginFailures(string $usernameKey, string $ipAddress): void
    {
        $this->pdo->prepare('DELETE FROM login_attempts WHERE username_key=:username AND ip_address=:ip')
            ->execute(['username' => $usernameKey, 'ip' => $ipAddress]);
    }

    private function authChangeMessage(mixed $onuName, string $old, string $new): string
    {
        $name = display_onu_name($onuName);
        $old = trim($old) !== '' ? trim($old) : 'N/A';
        $new = trim($new) !== '' ? trim($new) : 'N/A';
        if (strcasecmp($old, 'Undef') === 0 && strcasecmp($new, 'Pass') === 0) {
            return 'Berhasil  Autentikasi ONU ' . $name .$old . ' ➠ ' . $new;
        }
        return 'Berhasil Mengubah' . $name . '' . $old . ' ➠ ' . $new;
    }
}
