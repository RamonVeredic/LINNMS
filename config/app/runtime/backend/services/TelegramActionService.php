<?php

declare(strict_types=1);

final class TelegramActionService
{
    public function __construct(
        private LinnmsRepository $db,
        private TelegramNotifier $telegram,
        private RuntimeBridge $bridge
    ) {
    }

    public function execute(int $userId, string $action, array $data): array
    {
        $user = $this->db->getUser($userId);
        if (!is_array($user) || (int)($user['is_active'] ?? 0) !== 1) return ['ok' => false, 'code' => 'forbidden'];
        if (!in_array($action, ['save_telegram_user_bot', 'save_telegram',
            'telegram_toggle_notifications', 'telegram_disable'], true)) {
            return ['ok' => false, 'code' => 'unsupported'];
        }
        try {
            return match ($action) {
                'save_telegram_user_bot' => $this->saveBot($userId, $data),
                'save_telegram' => $this->savePreferences($userId, $data),
                'telegram_toggle_notifications' => $this->toggle($userId, $data),
                'telegram_disable' => $this->disable($userId),
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
            return $this->failure($userId, 'connection_test_telegram', $error);
        }
    }

    private function failure(int $userId, string $action, Throwable $error): array
    {
        $validation = $error instanceof InvalidArgumentException;
        if (!$validation) {
            try {
                $this->db->logApplication($userId, 'LINNMS APP', $action . '_error', 'error', (string)$error);
            } catch (Throwable $logError) {
                error_log('[LINNMS] Telegram action log: ' . $logError->getMessage());
            }
        }
        return ['ok' => false, 'code' => $validation ? 'validation' : 'internal'];
    }

    private function available(): bool
    {
        return $this->bridge->allowsTelegram()
            && (int)($this->db->getTelegramServiceSettings()['enabled'] ?? 0) === 1;
    }

    private function saveBot(int $userId, array $data): array
    {
        if (!$this->available()) return ['ok' => false, 'code' => 'unsupported'];
        $settings = $this->db->getUserSettings($userId);
        $bot = $this->db->getUserTelegramBot($userId);
        $postedToken = trim((string)($data['telegram_bot_token'] ?? ''));
        $token = $postedToken !== '' ? $postedToken : trim((string)($bot['token'] ?? ''));
        $postedChat = trim((string)($data['telegram_chat_id'] ?? ''));
        $chatId = $postedChat !== '' ? $postedChat : trim((string)($settings['telegram_chat_id'] ?? ''));
        if ($token === '') return ['ok' => false, 'code' => 'token_required'];
        if (preg_match('/^-?\d{1,24}$/', $chatId) !== 1) return ['ok' => false, 'code' => 'chat_invalid'];
        $me = $this->telegram->getMe($token);
        if (empty($me['ok'])) {
            $this->logDriver($userId, 'save_telegram_user_bot', (string)($me['error'] ?? 'Telegram menolak token.'));
            return ['ok' => false, 'code' => 'device_auth_failed'];
        }
        $username = ltrim(trim((string)($me['username'] ?? '')), '@');
        if ($username === '') return ['ok' => false, 'code' => 'device_rejected'];
        $this->db->saveUserTelegramBot($userId, $token, $username, trim((string)($me['name'] ?? '')));
        $this->db->saveTelegramChatId($userId, $chatId);
        $saved = $this->db->getUserSettings($userId);
        return ['ok' => true, 'code' => 'ok', 'data' => [
            'username' => $username,
            'chat_id' => $chatId,
            'enabled' => (int)($saved['telegram_enabled'] ?? 0) === 1,
        ]];
    }

    private function savePreferences(int $userId, array $data): array
    {
        if (!$this->available()) return ['ok' => false, 'code' => 'unsupported'];
        $this->db->saveTelegramUserPreferences($userId, $data);
        if (array_key_exists('problem_threshold', $data)) {
            $this->db->saveOnuRxProblemThreshold((float)$data['problem_threshold'], $userId);
        }
        return ['ok' => true, 'code' => 'ok'];
    }

    private function toggle(int $userId, array $data): array
    {
        if (!$this->bridge->allowsTelegram()) return ['ok' => false, 'code' => 'unsupported'];
        $enabled = (string)($data['telegram_enabled'] ?? '0') === '1';
        $this->db->setTelegramNotificationsEnabled($userId, $enabled);
        return ['ok' => true, 'code' => 'ok', 'data' => ['enabled' => $enabled]];
    }

    private function disable(int $userId): array
    {
        $bot = $this->db->getUserTelegramBot($userId);
        $token = trim((string)($bot['token'] ?? ''));
        $this->db->disableTelegramForUser($userId);
        if ($token !== '' && $this->db->acquireTelegramUserUpdateLock($userId, 2)) {
            try {
                $this->telegram->resetPollingState($token, true);
            } catch (Throwable $error) {
                $this->logDriver($userId, 'telegram_disable', (string)$error);
            } finally {
                $this->db->releaseTelegramUserUpdateLock($userId);
            }
        }
        return ['ok' => true, 'code' => 'ok'];
    }

    private function test(int $userId, array $data): array
    {
        if (!$this->available()) return ['ok' => false, 'code' => 'unsupported'];
        $settings = $this->db->getUserSettings($userId);
        $postedToken = trim((string)($data['telegram_bot_token'] ?? ''));
        $postedChat = trim((string)($data['telegram_chat_id'] ?? ''));
        $token = $postedToken !== '' ? $postedToken : trim((string)($settings['telegram_bot_token'] ?? ''));
        $chat = $postedChat !== '' ? $postedChat : trim((string)($settings['telegram_chat_id'] ?? ''));
        if ($token === '') return ['ok' => false, 'code' => 'token_required'];
        if ($chat === '') return ['ok' => false, 'code' => 'chat_required'];
        if (preg_match('/^-?\d{1,24}$/', $chat) !== 1) return ['ok' => false, 'code' => 'chat_invalid'];
        $result = $this->telegram->send($token, $chat, 'Tes koneksi dari ' . $this->db->getAppName());
        if (empty($result['ok'])) {
            $this->logDriver($userId, 'connection_test_telegram', (string)($result['error'] ?? 'Telegram gagal mengirim.'));
            return ['ok' => false, 'code' => 'device_rejected'];
        }
        $this->db->recordNotificationHistory($userId, 'telegram');
        return ['ok' => true, 'code' => 'ok'];
    }

    private function logDriver(int $userId, string $action, string $message): void
    {
        try {
            $this->db->logApplication($userId, 'Driver', $action . '_driver', 'error', $message);
        } catch (Throwable $error) {
            error_log('[LINNMS] Telegram driver log: ' . $error->getMessage());
        }
    }
}
