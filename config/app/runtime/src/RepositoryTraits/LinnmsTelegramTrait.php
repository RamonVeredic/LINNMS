<?php

declare(strict_types=1);

trait LinnmsTelegramTrait
{
    public function getTelegramServiceSettings(): array
    {
        $stmt = $this->pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key='telegram_bot_enabled' LIMIT 1");
        $stmt->execute();
        return ['enabled' => (string)($stmt->fetchColumn() ?: '0') === '1' ? 1 : 0];
    }

    public function getTelegramNotifiableUsers(): array
    {
        $stmt = $this->pdo->query(
            "SELECT u.id,u.username,u.display_name
             FROM users u
             INNER JOIN user_settings enabled ON enabled.user_id=u.id AND enabled.setting_key='telegram_enabled' AND enabled.setting_value='1'
             INNER JOIN user_settings chat ON chat.user_id=u.id AND chat.setting_key='telegram_chat_id'
             INNER JOIN user_settings token ON token.user_id=u.id AND token.setting_key='telegram_bot_token'
             WHERE u.is_active=1
               AND TRIM(chat.setting_value)<>''
               AND TRIM(token.setting_value)<>''
             ORDER BY u.id"
        );
        return $stmt->fetchAll() ?: [];
    }

    public function getTelegramMessageTargets(): array
    {
        $stmt = $this->pdo->query(
            "SELECT u.id AS user_id,u.username,u.display_name,
                    bot.setting_value telegram_bot_username,bot_name.setting_value telegram_bot_name,
                    chat.setting_value telegram_chat_id,chat_type.setting_value telegram_chat_type,chat_title.setting_value telegram_chat_title
             FROM users u
             INNER JOIN user_settings token ON token.user_id=u.id AND token.setting_key='telegram_bot_token'
             INNER JOIN user_settings chat ON chat.user_id=u.id AND chat.setting_key='telegram_chat_id'
             LEFT JOIN user_settings bot ON bot.user_id=u.id AND bot.setting_key='telegram_bot_username'
             LEFT JOIN user_settings bot_name ON bot_name.user_id=u.id AND bot_name.setting_key='telegram_bot_name'
             LEFT JOIN user_settings chat_type ON chat_type.user_id=u.id AND chat_type.setting_key='telegram_chat_type'
             LEFT JOIN user_settings chat_title ON chat_title.user_id=u.id AND chat_title.setting_key='telegram_chat_title'
             WHERE u.is_active=1
               AND token.setting_value<>'' AND chat.setting_value<>''
             ORDER BY COALESCE(NULLIF(u.display_name,''),u.username),u.id"
        );
        return $stmt->fetchAll() ?: [];
    }

    public function getTelegramMessageTarget(int $userId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT id AS user_id,username,display_name FROM users WHERE id=:uid AND is_active=1 LIMIT 1");
        $stmt->execute(['uid'=>$userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) return null;
        $settings = $this->getUserSettings($userId);
        if (trim((string)$settings['telegram_bot_token']) === '' || trim((string)$settings['telegram_chat_id']) === '') return null;
        return $row + $settings;
    }

    public function getUserTelegramBot(int $userId): array
    {
        $settings = $this->getUserSettings($userId);
        $token = trim((string)($settings['telegram_bot_token'] ?? ''));
        $username = ltrim(trim((string)($settings['telegram_bot_username'] ?? '')), '@');
        $name = trim((string)($settings['telegram_bot_name'] ?? ''));
        return [
            'configured' => ($token !== '' && $username !== '') ? 1 : 0,
            'token' => $token,
            'username' => $username,
            'name' => $name,
            'update_offset' => max(0, (int)($settings['telegram_update_offset'] ?? 0)),
        ];
    }

    public function saveUserTelegramBot(int $userId, string $token, string $username, string $name): bool
    {
        $token = trim($token);
        $username = ltrim(trim($username), '@');
        $name = trim($name);
        if ($userId < 1) throw new InvalidArgumentException('Akun tidak valid.');
        if ($token === '' || mb_strlen($token) > 255) throw new InvalidArgumentException('Bot Token tidak valid.');
        if ($username === '' || preg_match('/^[A-Za-z0-9_]{5,64}$/', $username) !== 1) {
            throw new InvalidArgumentException('Username bot Telegram tidak valid.');
        }
        if ($name !== '' && mb_strlen($name) > 100) throw new InvalidArgumentException('Nama bot Telegram maksimal 100 karakter.');

        $dup = $this->pdo->prepare(
            "SELECT user_id FROM user_settings WHERE user_id<>:uid AND setting_key='telegram_bot_token' AND BINARY setting_value=BINARY :token LIMIT 1"
        );
        $dup->execute(['uid' => $userId, 'token' => $token]);
        if ($dup->fetchColumn() !== false) {
            throw new InvalidArgumentException('Bot Token ini sudah digunakan akun lain. Gunakan bot Telegram yang berbeda untuk setiap akun.');
        }

        $current = $this->getUserTelegramBot($userId);
        $currentToken = trim((string)($current['token'] ?? ''));
        $changed = $currentToken === '' || !hash_equals($currentToken, $token);

        $this->pdo->beginTransaction();
        try {
            $wasReady = $this->hasReadyNotificationChannel($userId);
            if ($changed) {
                $this->saveUserSettings($userId, [
                    'telegram_bot_token' => $token,
                    'telegram_bot_username' => $username,
                    'telegram_bot_name' => $name,
                    'telegram_update_offset' => 0,
                    'telegram_chat_id' => '',
                    'telegram_chat_type' => '',
                    'telegram_chat_title' => '',
                ]);
            } else {
                $this->saveUserSettings($userId, ['telegram_bot_username' => $username, 'telegram_bot_name' => $name]);
            }
            $this->refreshNotificationChannelReadiness($userId, $wasReady);
            $this->pdo->commit();
            return $changed;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function acquireTelegramUserUpdateLock(int $userId, int $waitSeconds = 2): bool
    {
        $lockName = 'linnms_tg_' . substr(sha1($this->databaseName . ':' . max(0, $userId)), 0, 32);
        $waitSeconds = max(0, min(5, $waitSeconds));
        try {
            $stmt = $this->pdo->prepare('SELECT GET_LOCK(:name,:wait_seconds)');
            $stmt->execute(['name' => $lockName, 'wait_seconds' => $waitSeconds]);
            return (int)$stmt->fetchColumn() === 1;
        } catch (Throwable) {
            return false;
        }
    }

    public function releaseTelegramUserUpdateLock(int $userId): void
    {
        $lockName = 'linnms_tg_' . substr(sha1($this->databaseName . ':' . max(0, $userId)), 0, 32);
        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $stmt->execute(['name' => $lockName]);
        } catch (Throwable) {
        }
    }

    public function resetTelegramUserConnection(int $userId): void
    {
        if ($userId < 1) return;

        $this->pdo->beginTransaction();
        try {
            $wasReady = $this->hasReadyNotificationChannel($userId);
            $this->saveUserSettings($userId, ['telegram_update_offset' => 0, 'telegram_chat_id' => '', 'telegram_chat_type' => '', 'telegram_chat_title' => '']);
            $this->refreshNotificationChannelReadiness($userId, $wasReady);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function disableTelegramForUser(int $userId): void
    {
        $this->resetTelegramUserConnection($userId);
    }

    public function setTelegramNotificationsEnabled(int $userId, bool $enabled): void
    {
        $this->pdo->beginTransaction();
        try {
            $wasReady = $this->hasReadyNotificationChannel($userId);
            $this->saveUserSetting($userId, 'telegram_enabled', $enabled ? '1' : '0');
            $this->refreshNotificationChannelReadiness($userId, $wasReady);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function saveTelegramUserPreferences(int $userId, array $data): void
    {
        $this->saveUserSettings($userId, [
            'notify_onu_state' => !empty($data['notify_onu_state']) ? 1 : 0,
            'notify_onu_signal' => !empty($data['notify_onu_signal']) ? 1 : 0,
            'notify_onu_problem' => !empty($data['notify_onu_problem']) ? 1 : 0,
            'notify_onu_details' => !empty($data['notify_onu_details']) ? 1 : 0,
            'notify_device_status' => !empty($data['notify_device_status']) ? 1 : 0,
            'notify_interface_changes' => !empty($data['notify_interface_changes']) ? 1 : 0,
            'notify_pppoe_status' => !empty($data['notify_pppoe_status']) ? 1 : 0,
            'signal_threshold_dbm' => number_format(self::clampOnuRxDetection($data['signal_threshold_dbm'] ?? null), 2, '.', ''),
        ]);
    }

    public function saveTelegramChatId(int $userId, string $chatId): void
    {
        $chatId = trim($chatId);
        if ($chatId !== '' && preg_match('/^-?\d{1,24}$/', $chatId) !== 1) {
            throw new InvalidArgumentException('Chat ID harus berupa angka.');
        }
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM users WHERE id=:id FOR UPDATE');
            $lock->execute(['id' => $userId]);
            $wasReady = $this->hasReadyNotificationChannel($userId);
            $this->saveUserSettings($userId, ['telegram_chat_id' => $chatId, 'telegram_chat_type' => '', 'telegram_chat_title' => '']);
            $this->refreshNotificationChannelReadiness($userId, $wasReady);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

}
