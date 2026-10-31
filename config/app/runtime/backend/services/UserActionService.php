<?php

declare(strict_types=1);

final class UserActionService
{
    public function __construct(private LinnmsRepository $db)
    {
    }

    public function execute(int $actorId, string $action, array $data): array
    {
        $actor = $this->db->getUser($actorId);
        if (!is_array($actor) || (int)($actor['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'code' => 'forbidden'];
        }
        if (!in_array($action, ['create_user', 'toggle_user', 'delete_user', 'reset_password', 'update_profile', 'save_user_device_limits'], true)) {
            return ['ok' => false, 'code' => 'unsupported'];
        }
        if ($action !== 'update_profile' && (string)($actor['role'] ?? '') !== 'admin') {
            return ['ok' => false, 'code' => 'forbidden'];
        }

        try {
            return match ($action) {
                'create_user' => $this->create($data),
                'toggle_user' => $this->toggle($data),
                'delete_user' => $this->delete($data),
                'reset_password' => $this->resetPassword($data),
                'update_profile' => $this->profile($actorId, $data),
                'save_user_device_limits' => $this->limits($data),
            };
        } catch (Throwable $error) {
            $validation = $error instanceof InvalidArgumentException;
            $code = $validation ? 'validation'
                : ($action === 'update_profile' && $error instanceof RuntimeException
                    && $error->getMessage() === 'Pastikan password sesuai' ? 'auth' : 'internal');
            if (!$validation) {
                try {
                    $this->db->logApplication($actorId, 'LINNMS APP', $action . '_error', 'error', (string)$error);
                } catch (Throwable $logError) {
                    error_log('[LINNMS] User action log: ' . $logError->getMessage());
                }
            }
            return ['ok' => false, 'code' => $code];
        }
    }

    private function create(array $data): array
    {
        $username = trim((string)($data['username'] ?? ''));
        $password = (string)($data['password'] ?? '');
        if (preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username) !== 1 || $password === '') {
            throw new InvalidArgumentException('Username atau password tidak valid.');
        }
        $this->db->createUser($username, $password, trim((string)($data['display_name'] ?? '')));
        return ['ok' => true, 'code' => 'ok', 'data' => ['username' => $username]];
    }

    private function toggle(array $data): array
    {
        $id = valid_positive_id($data['user_id'] ?? null);
        if ($id === null) throw new InvalidArgumentException('Akun pengguna tidak valid.');
        $this->db->setUserActive($id, (string)($data['make_active'] ?? '0') === '1');
        return ['ok' => true, 'code' => 'ok'];
    }

    private function delete(array $data): array
    {
        $id = valid_positive_id($data['user_id'] ?? null);
        if ($id === null) throw new InvalidArgumentException('Akun pengguna tidak valid.');
        $this->db->deleteUser($id);
        return ['ok' => true, 'code' => 'ok'];
    }

    private function resetPassword(array $data): array
    {
        $id = valid_positive_id($data['user_id'] ?? null);
        $password = (string)($data['password'] ?? '');
        if ($id === null || $password === '') throw new InvalidArgumentException('User/password tidak valid.');
        $this->db->resetUserPassword($id, $password);
        return ['ok' => true, 'code' => 'ok'];
    }

    private function profile(int $actorId, array $data): array
    {
        $profile = $this->db->updateProfile($actorId,
            (string)($data['display_name'] ?? ''),
            (string)($data['current_password'] ?? ''),
            (string)($data['new_password'] ?? ''),
            (string)($data['phone_number'] ?? '')
        );
        return ['ok' => true, 'code' => 'ok', 'data' => [
            'username' => (string)$profile['username'],
            'display_name' => (string)($profile['display_name'] ?? ''),
        ]];
    }

    private function limits(array $data): array
    {
        $id = valid_positive_id($data['user_id'] ?? null);
        if ($id === null) throw new InvalidArgumentException('Akun pengguna tidak valid.');
        $limits = [];
        foreach (['olt_limit', 'mikrotik_limit', 'switch_limit'] as $key) {
            $value = $data[$key] ?? null;
            if (!is_scalar($value) || filter_var((string)$value, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0]]) === false) {
                throw new InvalidArgumentException('Limit perangkat tidak valid.');
            }
            $limits[$key] = (int)$value;
        }
        $this->db->setManagedUserDeviceLimits($id, $limits['olt_limit'], $limits['mikrotik_limit'], $limits['switch_limit']);
        return ['ok' => true, 'code' => 'ok'];
    }
}
