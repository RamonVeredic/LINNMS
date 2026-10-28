<?php

declare(strict_types=1);

trait LinnmsWhatsAppTrait
{
    public function getWhatsAppNotificationServiceSettings(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT setting_value FROM app_settings WHERE setting_key='whatsapp_notification_service_enabled' LIMIT 1"
        );
        $stmt->execute();
        $value = $stmt->fetchColumn();

        return ['enabled' => (string)$value === '1' ? 1 : 0];
    }

    public function getWhatsAppGatewaySettings(): array
    {
        $values = [];
        $stmt = $this->pdo->query(
            "SELECT setting_key,setting_value FROM app_settings
             WHERE setting_key IN ('whatsapp_gateway_enabled','whatsapp_gateway_token',
                                   'whatsapp_gateway_sender',
                                   'whatsapp_gateway_test_target','whatsapp_gateway_provider',
                                   'whatsapp_gateway_connected_number','whatsapp_gateway_base_url',
                                   'whatsapp_gateway_device')"
        );
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $values[(string)$row['setting_key']] = (string)$row['setting_value'];
        }
        $provider = strtolower(trim((string)($values['whatsapp_gateway_provider'] ?? '')));

        return [
            'enabled' => (int)($values['whatsapp_gateway_enabled'] ?? 0) === 1 ? 1 : 0,
            'provider' => $provider !== '' ? $provider : 'fonnte',
            'token' => trim((string)($values['whatsapp_gateway_token'] ?? '')),
            'base_url' => rtrim(trim((string)($values['whatsapp_gateway_base_url'] ?? '')), '/'),
            'device' => trim((string)($values['whatsapp_gateway_device'] ?? '')),
            'sender' => trim((string)($values['whatsapp_gateway_sender'] ?? '')),
            'connected_number' => normalize_phone_number((string)($values['whatsapp_gateway_connected_number'] ?? '')),
            'test_target' => trim((string)($values['whatsapp_gateway_test_target'] ?? '')),
        ];
    }

    public function saveWhatsAppGatewaySettings(
        bool $enabled,
        string $token,
        string $sender,
        string $testTarget = '',
        string $provider = 'fonnte',
        string $baseUrl = '',
        string $device = ''
    ): void {
        $current = $this->getWhatsAppGatewaySettings();
        $stmt = $this->pdo->prepare(
            "INSERT INTO app_settings(setting_key,setting_value) VALUES (:key,:value)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
        );
        $provider = strtolower(trim($provider));
        $normalizedProvider = $provider !== '' ? $provider : 'fonnte';
        $normalizedToken = trim($token);
        $normalizedBaseUrl = rtrim(trim($baseUrl), '/');
        $normalizedDevice = trim($device);
        $connectionChanged = $normalizedProvider !== (string)$current['provider']
            || $normalizedToken !== (string)$current['token']
            || $normalizedBaseUrl !== (string)($current['base_url'] ?? '')
            || $normalizedDevice !== (string)($current['device'] ?? '');
        $payload = [
            'whatsapp_gateway_enabled' => $enabled ? '1' : '0',
            'whatsapp_gateway_provider' => $normalizedProvider,
            'whatsapp_gateway_token' => $normalizedToken,
            'whatsapp_gateway_base_url' => $normalizedBaseUrl,
            'whatsapp_gateway_device' => $normalizedDevice,
            'whatsapp_gateway_sender' => normalize_phone_number($sender),
            'whatsapp_gateway_connected_number' => $connectionChanged ? '' : (string)$current['connected_number'],
            'whatsapp_gateway_test_target' => normalize_phone_number($testTarget),
        ];
        foreach ($payload as $key => $value) {
            $stmt->execute(['key' => $key, 'value' => $value]);
        }
    }

    public function getUserWhatsAppSettings(int $userId): array
    {
        $settings = $this->getUserSettings($userId);
        $lastAt = $settings['whatsapp_last_at'] ?? null;
        $targetType = strtolower(trim((string)($settings['whatsapp_target_type'] ?? ''))) === 'group' ? 'group' : 'phone';

        $rawTarget = trim((string)($settings['whatsapp_target'] ?? ''));

        return [
            'enabled' => (int)($settings['whatsapp_enabled'] ?? 0) === 1 ? 1 : 0,
            'provider' => trim((string)($settings['whatsapp_provider'] ?? '')),
            'token' => trim((string)($settings['whatsapp_token'] ?? '')),
            'device' => trim((string)($settings['whatsapp_device'] ?? '')),
            'connected_number' => normalize_phone_number((string)($settings['connected_number'] ?? '')),
            'base_url' => rtrim(trim((string)($settings['whatsapp_base_url'] ?? '')), '/'),
            'target' => $targetType === 'group' ? $rawTarget : normalize_phone_number($rawTarget),
            'target_type' => $targetType,
            'last_status' => trim((string)($settings['whatsapp_last_status'] ?? '')),
            'last_at' => $lastAt !== null && $lastAt !== '' ? (string)$lastAt : null,
        ];
    }

    public function isUserWhatsAppNotificationEnabled(int $userId): bool
    {
        $stmt = $this->pdo->prepare("SELECT setting_value FROM user_settings WHERE user_id=:uid AND setting_key='whatsapp_enabled' LIMIT 1");
        $stmt->execute(['uid' => $userId]);
        return (string)$stmt->fetchColumn() === '1';
    }

    public function saveUserWhatsAppSettings(int $userId, array $data): void
    {
        if ($userId < 1) throw new InvalidArgumentException('Akun tidak valid.');

        $provider = strtolower(trim((string)($data['provider'] ?? '')));
        if ($provider !== '' && preg_match('/^[a-z0-9_-]{2,32}$/', $provider) !== 1) {
            throw new InvalidArgumentException('Gateway WhatsApp tidak valid.');
        }
        $token = trim((string)($data['token'] ?? ''));
        if (mb_strlen($token) > 255) throw new InvalidArgumentException('Token gateway maksimal 255 karakter.');
        $device = trim((string)($data['device'] ?? ''));
        if (mb_strlen($device) > 120) throw new InvalidArgumentException('Device ID maksimal 120 karakter.');
        $connectedNumber = normalize_phone_number((string)($data['connected_number'] ?? ''));
        $baseUrl = rtrim(trim((string)($data['base_url'] ?? '')), '/');
        if ($baseUrl !== '' && preg_match('~^https?://~i', $baseUrl) !== 1) {
            throw new InvalidArgumentException('URL gateway harus dimulai dengan http:// atau https://');
        }
        if (mb_strlen($baseUrl) > 190) throw new InvalidArgumentException('URL gateway maksimal 190 karakter.');

        $targetType = strtolower(trim((string)($data['target_type'] ?? ''))) === 'group' ? 'group' : 'phone';
        $rawTarget = trim((string)($data['target'] ?? ''));
        $target = $targetType === 'group' ? $rawTarget : normalize_phone_number($rawTarget);
        if (mb_strlen($target) > 120) throw new InvalidArgumentException('Tujuan WhatsApp maksimal 120 karakter.');
        $enabled = !empty($data['enabled']);

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM users WHERE id=:id FOR UPDATE');
            $lock->execute(['id' => $userId]);
            $wasReady = $this->hasReadyNotificationChannel($userId);

            $this->saveUserSettings($userId, [
                'whatsapp_enabled' => $enabled ? 1 : 0,
                'whatsapp_provider' => $provider,
                'whatsapp_token' => $token,
                'whatsapp_device' => $device,
                'whatsapp_base_url' => $baseUrl,
                'whatsapp_target' => $target,
                'whatsapp_target_type' => $targetType,
                'connected_number' => $connectedNumber,
            ]);
            $this->refreshNotificationChannelReadiness($userId, $wasReady);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function getWhatsAppNotifiableUsers(): array
    {
        $stmt = $this->pdo->query(
            "SELECT u.id,u.username,u.display_name
             FROM users u
             INNER JOIN user_settings enabled ON enabled.user_id=u.id AND enabled.setting_key='whatsapp_enabled' AND enabled.setting_value='1'
             INNER JOIN user_settings provider ON provider.user_id=u.id AND provider.setting_key='whatsapp_provider'
             INNER JOIN user_settings target ON target.user_id=u.id AND target.setting_key='whatsapp_target'
             LEFT JOIN user_settings token ON token.user_id=u.id AND token.setting_key='whatsapp_token'
             LEFT JOIN user_settings base_url ON base_url.user_id=u.id AND base_url.setting_key='whatsapp_base_url'
             WHERE u.is_active=1 AND u.role<>'admin'
               AND TRIM(provider.setting_value)<>''
               AND TRIM(target.setting_value)<>''
               AND (COALESCE(TRIM(token.setting_value),'')<>'' OR COALESCE(TRIM(base_url.setting_value),'')<>'')
             ORDER BY u.id"
        );

        return $stmt->fetchAll() ?: [];
    }

    public function setUserWhatsAppResult(int $userId, bool $ok, string $message): void
    {
        if ($userId < 1) return;
        $this->saveUserSettings($userId, [
            'whatsapp_last_status' => mb_substr(trim(($ok ? 'OK: ' : 'GAGAL: ') . $message), 0, 190),
            'whatsapp_last_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function saveWhatsAppGatewayStatus(array $status): void
    {
        $status['checked_at'] = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "INSERT INTO app_settings(setting_key,setting_value) VALUES ('whatsapp_gateway_last_status',:value)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
        );
        $stmt->execute(['value' => json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        $connectedNumber = !empty($status['ok'])
            ? normalize_phone_number((string)($status['device'] ?? ''))
            : '';
        if ($connectedNumber !== '') {
            $this->saveAppSetting('whatsapp_gateway_connected_number', $connectedNumber);
        }
    }

    public function getWhatsAppGatewayStatus(): array
    {
        return $this->getWhatsAppGatewayJsonSetting('whatsapp_gateway_last_status');
    }

    public function saveWhatsAppGatewayTest(array $result): void
    {
        $result['checked_at'] = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "INSERT INTO app_settings(setting_key,setting_value) VALUES ('whatsapp_gateway_last_test',:value)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
        );
        $stmt->execute(['value' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    }

    public function getWhatsAppGatewayTest(): array
    {
        return $this->getWhatsAppGatewayJsonSetting('whatsapp_gateway_last_test');
    }

    private function getWhatsAppGatewayJsonSetting(string $key): array
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=:key LIMIT 1');
        $stmt->execute(['key' => $key]);
        $raw = (string)($stmt->fetchColumn() ?: '');
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function setWhatsAppNotificationsEnabled(int $userId, bool $enabled): void
    {
        $this->pdo->beginTransaction();
        try {
            $wasReady = $this->hasReadyNotificationChannel($userId);
            $this->saveUserSetting($userId, 'whatsapp_enabled', $enabled ? '1' : '0');
            $this->refreshNotificationChannelReadiness($userId, $wasReady);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function resetUserWhatsAppConnection(int $userId): void
    {
        if ($userId < 1) return;

        $wasReady = $this->hasReadyNotificationChannel($userId);
        $this->saveUserSettings($userId, [
            'whatsapp_target' => '', 'whatsapp_target_type' => 'phone',
            'connected_number' => '', 'whatsapp_last_status' => '', 'whatsapp_last_at' => '',
        ]);
        $this->refreshNotificationChannelReadiness($userId, $wasReady);
    }

}
