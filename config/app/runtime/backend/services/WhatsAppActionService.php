<?php

declare(strict_types=1);

final class WhatsAppActionService
{
    public function __construct(private LinnmsRepository $db, private RuntimeBridge $bridge)
    {
    }

    public function execute(int $userId, string $action, array $data): array
    {
        $user = $this->db->getUser($userId);
        if (!is_array($user) || (int)($user['is_active'] ?? 0) !== 1) return ['ok' => false, 'code' => 'forbidden'];
        if (!in_array($action, ['save_whatsapp_connection', 'whatsapp_toggle_notifications',
            'whatsapp_disable', 'generate_whatsapp_token', 'list_whatsapp_groups'], true)) {
            return ['ok' => false, 'code' => 'unsupported'];
        }
        try {
            return match ($action) {
                'save_whatsapp_connection' => $this->saveConnection($userId, $data),
                'whatsapp_toggle_notifications' => $this->toggle($userId, $data),
                'whatsapp_disable' => $this->disable($userId),
                'generate_whatsapp_token' => $this->generateToken($userId, $data),
                'list_whatsapp_groups' => $this->groups($userId, $data),
            };
        } catch (Throwable $error) {
            return $this->failure($userId, $action, $error);
        }
    }

    public function connectionTest(int $userId, array $data): array
    {
        $user = $this->db->getUser($userId);
        if (!is_array($user) || (int)($user['is_active'] ?? 0) !== 1) return ['ok' => false, 'code' => 'forbidden'];
        try {
            return $this->test($userId, $data);
        } catch (Throwable $error) {
            return $this->failure($userId, 'connection_test_whatsapp', $error);
        }
    }

    private function failure(int $userId, string $action, Throwable $error): array
    {
        $validation = $error instanceof InvalidArgumentException;
        if (!$validation) {
            try {
                $this->db->logApplication($userId, 'LINNMS APP', $action . '_error', 'error', (string)$error);
            } catch (Throwable $logError) {
                error_log('[LINNMS] WhatsApp action log: ' . $logError->getMessage());
            }
        }
        return ['ok' => false, 'code' => $validation ? 'validation' : 'internal'];
    }

    private function available(): bool
    {
        return $this->bridge->allowsWhatsApp();
    }

    private function pick(string $key, array $data, array $saved, bool $changed = false): string
    {
        $posted = trim((string)($data[$key] ?? ''));
        if ($posted !== '') return $posted;
        return $changed ? '' : trim((string)($saved[substr($key, strlen('whatsapp_'))] ?? ''));
    }

    private function saveConnection(int $userId, array $data): array
    {
        if (!$this->available()) return ['ok' => false, 'code' => 'unsupported'];
        $current = $this->db->getUserWhatsAppSettings($userId);
        $provider = strtolower(trim((string)($data['whatsapp_provider'] ?? '')));
        if (!WhatsAppGateway::isValidProvider($provider)) return ['ok' => false, 'code' => 'provider_invalid'];
        $changed = strtolower(trim((string)($current['provider'] ?? ''))) !== $provider;
        $targetType = strtolower(trim((string)($data['whatsapp_target_type'] ?? '')));
        if (!in_array($targetType, ['phone', 'group'], true)) $targetType = (string)$current['target_type'];
        $probe = new WhatsAppGateway(['provider' => $provider, 'enabled' => 1]);
        if ($targetType === 'group' && !$probe->supportsGroups()) return ['ok' => false, 'code' => 'group_unsupported'];
        $token = $this->pick('whatsapp_token', $data, $current, $changed);
        $device = $this->pick('whatsapp_device', $data, $current, $changed);
        $baseUrl = $this->pick('whatsapp_base_url', $data, $current, $changed);
        $connectionChanged = $provider !== (string)$current['provider']
            || $token !== (string)$current['token'] || $device !== (string)$current['device']
            || rtrim($baseUrl, '/') !== rtrim((string)$current['base_url'], '/');
        $payload = [
            'provider' => $provider,
            'token' => $token,
            'device' => $device,
            'base_url' => $baseUrl,
            'target' => $this->pick('whatsapp_target', $data, $current, $changed),
            'target_type' => $targetType,
            'connected_number' => $connectionChanged ? '' : (string)($current['connected_number'] ?? ''),
            'enabled' => (int)$current['enabled'] === 1,
        ];
        $gateway = new WhatsAppGateway($payload);
        $missing = $gateway->missingFields();
        if ($missing !== []) return ['ok' => false, 'code' => 'missing_fields'];
        if (!$gateway->isValidTarget((string)$payload['target'])) {
            return ['ok' => false, 'code' => $targetType === 'group' ? 'group_invalid' : 'target_invalid'];
        }
        if ($gateway->buildDeviceRequest() !== null) {
            try {
                $status = $gateway->deviceStatus();
                $number = !empty($status['ok']) ? normalize_phone_number((string)(
                    ($status['raw'] ?? [])['phone'] ?? ($status['raw'] ?? [])['device'] ?? '')) : '';
                if ($number !== '') $payload['connected_number'] = $number;
            } catch (Throwable $error) {
                $this->logDriver($userId, 'save_whatsapp_connection', (string)$error);
            }
        }
        $this->db->saveUserWhatsAppSettings($userId, $payload);
        return ['ok' => true, 'code' => 'ok', 'data' => [
            'provider_label' => $gateway->providerLabel(),
            'previously_enabled' => (int)($current['enabled'] ?? 0) === 1,
        ]];
    }

    private function toggle(int $userId, array $data): array
    {
        if (!$this->available()) return ['ok' => false, 'code' => 'unsupported'];
        $enabled = (string)($data['whatsapp_enabled'] ?? '0') === '1';
        $this->db->setWhatsAppNotificationsEnabled($userId, $enabled);
        return ['ok' => true, 'code' => 'ok', 'data' => ['enabled' => $enabled]];
    }

    private function disable(int $userId): array
    {
        $this->db->resetUserWhatsAppConnection($userId);
        return ['ok' => true, 'code' => 'ok'];
    }

    private function generateToken(int $userId, array $data): array
    {
        if (!$this->available()) return ['ok' => false, 'code' => 'unsupported'];
        $gateway = new WhatsAppGateway([
            'provider' => trim((string)($data['whatsapp_provider'] ?? '')),
            'base_url' => trim((string)($data['whatsapp_base_url'] ?? '')),
            'enabled' => 1,
        ]);
        $result = $gateway->generateSessionToken(
            (string)($data['whatsapp_generator_session'] ?? ''),
            (string)($data['whatsapp_generator_secret'] ?? '')
        );
        if (empty($result['ok'])) {
            $this->logDriver($userId, 'generate_whatsapp_token',
                (string)($result['detail'] ?? $result['message'] ?? 'Token gagal dibuat.'));
            return ['ok' => false, 'code' => 'device_rejected'];
        }
        return ['ok' => true, 'code' => 'ok', 'data' => ['token' => (string)($result['token'] ?? '')]];
    }

    private function groups(int $userId, array $data): array
    {
        if (!$this->available()) return ['ok' => false, 'code' => 'unsupported'];
        $saved = $this->db->getUserWhatsAppSettings($userId);
        $gateway = new WhatsAppGateway([
            'provider' => $this->pick('whatsapp_provider', $data, $saved),
            'token' => $this->pick('whatsapp_token', $data, $saved),
            'device' => $this->pick('whatsapp_device', $data, $saved),
            'base_url' => $this->pick('whatsapp_base_url', $data, $saved),
            'target_type' => 'group',
            'enabled' => 1,
        ]);
        $result = $gateway->fetchGroups();
        if (empty($result['ok'])) {
            $this->logDriver($userId, 'list_whatsapp_groups', (string)($result['message'] ?? 'Grup gagal dimuat.'));
            return ['ok' => false, 'code' => 'device_rejected'];
        }
        return ['ok' => true, 'code' => 'ok', 'data' => ['groups' => array_values((array)($result['groups'] ?? []))]];
    }

    private function test(int $userId, array $data): array
    {
        if (!$this->available()) return ['ok' => false, 'code' => 'unsupported'];
        $saved = $this->db->getUserWhatsAppSettings($userId);
        $providerChanged = strtolower(trim((string)($data['whatsapp_provider'] ?? (string)$saved['provider'])))
            !== strtolower(trim((string)$saved['provider']));
        $targetType = strtolower($this->pick('whatsapp_target_type', $data, $saved, $providerChanged));
        if (!in_array($targetType, ['phone', 'group'], true)) $targetType = 'phone';
        $gateway = new WhatsAppGateway([
            'provider' => $this->pick('whatsapp_provider', $data, $saved, $providerChanged),
            'token' => $this->pick('whatsapp_token', $data, $saved, $providerChanged),
            'device' => $this->pick('whatsapp_device', $data, $saved, $providerChanged),
            'base_url' => $this->pick('whatsapp_base_url', $data, $saved, $providerChanged),
            'target_type' => $targetType,
            'sender' => (string)$saved['target'],
            'enabled' => 1,
        ]);
        $target = $this->pick('whatsapp_target', $data, $saved, $providerChanged);
        $missing = $gateway->missingFields();
        if ($missing !== []) return ['ok' => false, 'code' => 'missing_fields'];
        if (!$gateway->isValidTarget($target)) {
            return ['ok' => false, 'code' => $targetType === 'group' ? 'group_invalid' : 'target_invalid'];
        }
        $result = $gateway->send($target, 'Tes notifikasi WhatsApp dari ' . $this->db->getAppName());
        if (empty($result['ok'])) {
            $this->logDriver($userId, 'connection_test_whatsapp', (string)($result['message'] ?? 'Pengiriman gagal.'));
            return ['ok' => false, 'code' => 'device_rejected'];
        }
        $this->db->recordNotificationHistory($userId, 'whatsapp', $gateway->providerKey());
        return ['ok' => true, 'code' => 'ok'];
    }

    private function logDriver(int $userId, string $action, string $message): void
    {
        try {
            $this->db->logApplication($userId, 'Driver', $action . '_driver', 'error', $message);
        } catch (Throwable $error) {
            error_log('[LINNMS] WhatsApp driver log: ' . $error->getMessage());
        }
    }
}
