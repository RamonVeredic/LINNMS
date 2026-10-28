<?php

declare(strict_types=1);

trait LinnmsSettingTrait
{
    private function ensureAppSettings(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $insert = $this->pdo->prepare(
            "INSERT IGNORE INTO app_settings(setting_key,setting_value)
             VALUES (:key,:value)"
        );

        $insert->execute([
            'key' => 'service_whatsapp',
            'value' => '',
        ]);
        $insert->execute([
            'key' => 'service_email',
            'value' => '',
        ]);
        $insert->execute([
            'key' => 'public_registration_enabled',
            'value' => '1',
        ]);
        $insert->execute([
            'key' => 'whatsapp_gateway_enabled',
            'value' => '0',
        ]);
        $insert->execute([
            'key' => 'whatsapp_gateway_token',
            'value' => '',
        ]);
        $insert->execute([
            'key' => 'whatsapp_gateway_sender',
            'value' => '',
        ]);
        $insert->execute([
            'key' => 'whatsapp_gateway_connected_number',
            'value' => '',
        ]);
        $insert->execute([
            'key' => 'whatsapp_gateway_test_target',
            'value' => '',
        ]);
        $insert->execute([
            'key' => 'whatsapp_gateway_last_status',
            'value' => '',
        ]);
        $insert->execute([
            'key' => 'whatsapp_gateway_last_test',
            'value' => '',
        ]);
        $insert->execute([
            'key' => 'telegram_bot_enabled',
            'value' => '0',
        ]);
        $insert->execute([
            'key' => 'whatsapp_notification_service_enabled',
            'value' => '0',
        ]);
        $insert->execute([
            'key' => 'onu_missing_scan_limit',
            'value' => (string)self::DEFAULT_ONU_MISSING_SCAN_LIMIT,
        ]);
        $insert->execute([
            'key' => 'olt_graph_interval_minutes',
            'value' => (string)self::DEFAULT_OLT_GRAPH_INTERVAL_MINUTES,
        ]);
        foreach (['reader' => self::DEFAULT_READER_COUNT, 'writer' => self::DEFAULT_WRITER_COUNT] as $role => $count) {
            $insert->execute(['key' => $role . '_count', 'value' => (string)$count]);
        }
        $insert->execute(['key' => 'fast_sync_onu_batch_size', 'value' => (string)self::DEFAULT_FAST_SYNC_ONU_BATCH_SIZE]);
    }

    public function getFastSyncOnuBatchSize(): int
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=:key LIMIT 1');
        $stmt->execute(['key' => 'fast_sync_onu_batch_size']);
        $value = filter_var($stmt->fetchColumn(), FILTER_VALIDATE_INT);
        return $value === false ? self::DEFAULT_FAST_SYNC_ONU_BATCH_SIZE
            : max(self::MIN_FAST_SYNC_ONU_BATCH_SIZE, min(self::MAX_FAST_SYNC_ONU_BATCH_SIZE, $value));
    }

    public function saveFastSyncOnuBatchSize(int $size): void
    {
        if ($size < self::MIN_FAST_SYNC_ONU_BATCH_SIZE || $size > self::MAX_FAST_SYNC_ONU_BATCH_SIZE) {
            throw new InvalidArgumentException('Jumlah ONU per batch harus antara 1 dan 100.');
        }
        $this->saveAppSetting('fast_sync_onu_batch_size', (string)$size);
    }

    public function getDaemonCount(string $role): int
    {
        if (!in_array($role, ['reader', 'writer'], true)) throw new InvalidArgumentException('Peran worker tidak valid.');
        $default = $role === 'reader' ? self::DEFAULT_READER_COUNT : self::DEFAULT_WRITER_COUNT;
        $max = $role === 'reader' ? self::MAX_READER_COUNT : self::MAX_WRITER_COUNT;
        $stmt = $this->pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=:key LIMIT 1');
        $stmt->execute(['key' => $role . '_count']);
        $value = $stmt->fetchColumn();
        $count = filter_var($value, FILTER_VALIDATE_INT);
        return $count === false ? $default : max(1, min($max, $count));
    }

    public function saveDaemonCount(string $role, int $count): void
    {
        if (!in_array($role, ['reader', 'writer'], true)) throw new InvalidArgumentException('Peran worker tidak valid.');
        $max = $role === 'reader' ? self::MAX_READER_COUNT : self::MAX_WRITER_COUNT;
        if ($count < 1 || $count > $max) throw new InvalidArgumentException('Jumlah worker di luar rentang.');
        $this->saveAppSetting($role . '_count', (string)$count);
    }

    private function appSettingValue(string $key): ?string
    {
        if (array_key_exists($key, $this->appSettingMemory)) return $this->appSettingMemory[$key];
        $stmt = $this->pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=:key LIMIT 1');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();
        $this->appSettingMemory[$key] = $value === false ? null : (string)$value;
        return $this->appSettingMemory[$key];
    }

    public function getOltGraphIntervalMinutes(): int
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=:key LIMIT 1');
        $stmt->execute(['key' => 'olt_graph_interval_minutes']);
        $value = $stmt->fetchColumn();
        $minutes = is_numeric($value) ? (int)$value : self::DEFAULT_OLT_GRAPH_INTERVAL_MINUTES;
        return max(self::MIN_OLT_GRAPH_INTERVAL_MINUTES, min(self::MAX_OLT_GRAPH_INTERVAL_MINUTES, $minutes));
    }

    public function saveOltGraphIntervalMinutes(int $minutes): void
    {
        if ($minutes < self::MIN_OLT_GRAPH_INTERVAL_MINUTES || $minutes > self::MAX_OLT_GRAPH_INTERVAL_MINUTES) {
            throw new InvalidArgumentException('Interval sampel trafik OLT harus antara 1 dan 1440 menit.');
        }
        $this->saveAppSetting('olt_graph_interval_minutes', (string)$minutes);
    }

    private function saveAppSetting(string $key, string $value): void
    {
        $this->pdo->prepare(
            "INSERT INTO app_settings(setting_key,setting_value)
             VALUES (:key,:value)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
        )->execute(['key' => $key, 'value' => $value]);
        $this->appSettingMemory[$key] = $value;
    }

    private function saveUserSetting(int $userId, string $key, string $value): void
    {
        if ($userId < 1) throw new InvalidArgumentException('Pengguna tidak valid.');
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_settings(user_id,setting_key,setting_value) VALUES (:uid,:key,:value) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)'
        );
        $stmt->execute(['uid' => $userId, 'key' => $key, 'value' => $value]);
    }

    private function saveUserSettings(int $userId, array $values): void
    {
        if ($userId < 1) throw new InvalidArgumentException('Pengguna tidak valid.');
        $owned = !$this->pdo->inTransaction();
        if ($owned) $this->pdo->beginTransaction();
        try {
            foreach ($values as $key => $value) $this->saveUserSetting($userId, (string)$key, (string)($value ?? ''));
            if ($owned) $this->pdo->commit();
        } catch (Throwable $e) {
            if ($owned && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function appSettingOrDefault(string $key, string $default): string
    {
        $value = $this->appSettingValue($key);
        if ($value !== null) return $value;
        $this->saveAppSetting($key, $default);
        return $default;
    }

    private function appIdentityCacheFile(): string
    {
        return cache_path('app_identity.json');
    }

    private function readAppIdentityCache(): ?array
    {
        $file = $this->appIdentityCacheFile();

        if (!is_file($file)) return null;

        $mtime = @filemtime($file);
        if (
            $mtime === false ||
            (time() - (int)$mtime) > self::APP_IDENTITY_CACHE_TTL
        ) {
            return null;
        }

        $json = @file_get_contents($file);
        if (!is_string($json) || $json === '') return null;

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) return null;

        $name = trim((string)($decoded['name'] ?? ''));
        $version = trim((string)($decoded['version'] ?? ''));

        if ($name === '' || $version === '') return null;

        return [
            'name' => $name,
            'version' => $this->normalizeAppVersion($version),
        ];
    }

    private function writeAppIdentityCache(array $identity): void
    {
        $file = $this->appIdentityCacheFile();
        $dir = dirname($file);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $payload = json_encode(
            [
                'name' => $identity['name'],
                'version' => $identity['version'],
                'cached_at' => date(DATE_ATOM),
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (!is_string($payload)) return;

        $tmp = $file . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(3));
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            @unlink($tmp);
            return;
        }

        if (!@rename($tmp, $file)) {
            @unlink($file);
            @rename($tmp, $file);
        }
        @chmod($file, 0664);
    }

    private function normalizeAppVersion(string $version): string
    {
        $version = preg_replace(
            '/^v\.?/i',
            '',
            trim($version)
        ) ?? trim($version);

        $version = preg_replace(
            '/[^0-9A-Za-z._-]/',
            '',
            $version
        ) ?? $version;

        return $version;
    }

    private function readAppIdentityFromDatabase(): array
    {
        $stmt = $this->pdo->query(
            "SELECT setting_key,setting_value
             FROM app_settings
             WHERE setting_key IN ('app_name','app_version')"
        );

        $values = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $values[(string)$row['setting_key']] =
                trim((string)$row['setting_value']);
        }

        $name = trim((string)($values['app_name'] ?? ''));
        $version = $this->normalizeAppVersion(
            (string)($values['app_version'] ?? '')
        );

        return [
            'name' => $name !== '' ? $name : LinnmsDefaults::APP_IDENTITY['name'],
            'version' => $version !== '' ? $version : LinnmsDefaults::APP_IDENTITY['version'],
        ];
    }

    public function getAppIdentity(): array
    {
        if ($this->appIdentityMemory !== null) {
            return $this->appIdentityMemory;
        }

        $cached = $this->readAppIdentityCache();
        if ($cached !== null) {
            $this->appIdentityMemory = $cached;
            return $cached;
        }

        $identity = $this->readAppIdentityFromDatabase();
        $this->appIdentityMemory = $identity;
        $this->writeAppIdentityCache($identity);

        return $identity;
    }

    public function getAppName(): string
    {
        return $this->getAppIdentity()['name'];
    }

    public function updateProfile(int $userId, string $displayName, string $currentPassword, string $newPassword = '', ?string $email = null, ?string $company = null, ?string $phoneNumber = null): array
    {
        $displayName = trim($displayName);
        if ($displayName === '' || mb_strlen($displayName) > 60) {
            throw new InvalidArgumentException('Nama wajib diisi dan maksimal 60 karakter.');
        }
        $email = $email !== null ? trim($email) : null;
        $company = $company !== null ? trim($company) : null;
        $phoneNumber = $phoneNumber !== null ? trim($phoneNumber) : null;
        if ($email !== null && $email !== '' && (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            throw new InvalidArgumentException('Email tidak valid.');
        }
        if ($company !== null && mb_strlen($company) > 190) throw new InvalidArgumentException('Deskripsi maksimal 190 karakter.');
        if ($phoneNumber !== null && mb_strlen($phoneNumber) > 24) throw new InvalidArgumentException('Kontak maksimal 24 karakter.');
        if ($currentPassword === '') throw new InvalidArgumentException('Password saat ini wajib diisi.');
        if ($newPassword !== '' && mb_strlen($newPassword) < 6) throw new InvalidArgumentException('Password baru minimal 6 karakter.');

        $stmt = $this->pdo->prepare('SELECT password FROM users WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $storedPassword = $stmt->fetchColumn();
        if (!is_string($storedPassword) || !$this->verifyUserPassword($userId, $currentPassword, $storedPassword)) {
            throw new RuntimeException('Pastikan password sesuai');
        }

        $fields = ['display_name=:display_name'];
        $params = ['display_name' => $displayName, 'id' => $userId];
        foreach (['email' => $email, 'company' => $company, 'phone_number' => $phoneNumber] as $field => $value) {
            if ($value === null) continue;
            $fields[] = $field . '=:' . $field;
            $params[$field] = $value !== '' ? $value : null;
        }
        if ($newPassword !== '') {
            $fields[] = 'password=:password';
            $params['password'] = $this->hashUserPassword($newPassword);
        }
        $update = $this->pdo->prepare('UPDATE users SET ' . implode(',', $fields) . ' WHERE id=:id');
        $update->execute($params);
        return $this->getUser($userId) ?? throw new RuntimeException('Profil tidak ditemukan');
    }
}
