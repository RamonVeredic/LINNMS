<?php

declare(strict_types=1);

trait LinnmsUserTrait
{
    public function setUserPhoneNumber(int $userId, string $phone): void
    {
        $normalized = normalize_phone_number($phone);
        if ($userId < 1 || $normalized === '') return;
        $this->pdo->prepare('UPDATE users SET phone_number=:phone WHERE id=:id')
            ->execute(['phone' => $normalized, 'id' => $userId]);
    }

    public function usernameExists(string $username): bool
    {
        $username = trim($username);
        if ($username === '') return false;
        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE username=:username LIMIT 1');
        $stmt->execute(['username' => $username]);

        return $stmt->fetchColumn() !== false;
    }

    public function synchronizeVerifiedAdmin(string $username): bool
    {
        $username = mb_strtolower(trim($username));
        if (preg_match('/^[a-z0-9._-]{3,80}$/', $username) !== 1) {
            throw new InvalidArgumentException('Username tidak valid.');
        }
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE username=:username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $id = $stmt->fetchColumn();
        $admins = $this->pdo->query("SELECT username FROM users WHERE role='admin'")->fetchAll(PDO::FETCH_COLUMN);
        if ($id !== false && $admins === [$username]) return true;
        $this->pdo->prepare("UPDATE users SET role='user' WHERE role='admin' AND username<>:username")
            ->execute(['username' => $username]);
        if ($id === false) return false;
        $this->pdo->prepare("UPDATE users SET role='admin',is_active=1 WHERE id=:id")
            ->execute(['id' => (int)$id]);
        return true;
    }

    private function hashUserPassword(string $password): string
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') throw new RuntimeException('Password gagal diamankan.');
        return $hash;
    }

    private function isUserPasswordHash(string $value): bool
    {
        return (string)(password_get_info($value)['algoName'] ?? 'unknown') !== 'unknown';
    }

    private function verifyUserPassword(int $userId, string $password, string $storedHash): bool
    {
        if (!$this->isUserPasswordHash($storedHash) || !password_verify($password, $storedHash)) return false;
        if (password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
            $this->pdo->prepare('UPDATE users SET password=:password WHERE id=:id')
                ->execute(['password' => $this->hashUserPassword($password), 'id' => $userId]);
        }
        return true;
    }

    private function ensureUserSettingsForAllUsers(): void
    {
        $this->pdo->exec("INSERT IGNORE INTO user_sync_states(user_id) SELECT id FROM users");
    }

    public function getUser(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id,username,display_name,email,company,phone_number,role,is_active,olt_limit,mikrotik_limit,switch_limit,last_login_at,created_at,updated_at FROM users WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function qDeviceLimit(string $deviceType): int
    {
        $quotaKey = match ($deviceType) {
            'olt' => 'q1',
            'mikrotik' => 'q2',
            'cisco_catalyst_2960s' => 'q3',
            default => throw new InvalidArgumentException('Jenis perangkat tidak valid.'),
        };
        require_once dirname(__DIR__, 2) . '/app/CanonicalJson.php';
        require_once dirname(__DIR__, 2) . '/app/RuntimeBridge.php';
        $bridge = new RuntimeBridge($this->pdo, (array)(require '/config/app/config/service.php'));
        $state = $bridge->state();
        $value = $state['claims']['quota'][$quotaKey] ?? null;
        if (($state['mode'] ?? '') !== 'ready' || !is_numeric($value) || (int)$value < 0) {
            throw new RuntimeException('Batas perangkat belum tersedia.');
        }
        return (int)$value;
    }

    private function validateManagedDeviceLimit(int $limit, string $deviceType, string $label): void
    {
        if ($limit < 0) throw new InvalidArgumentException('Limit ' . $label . ' tidak valid.');
        $qLimit = $this->qDeviceLimit($deviceType);
        if ($qLimit > 0 && ($limit === 0 || $limit > $qLimit)) {
            throw new InvalidArgumentException('Limit ' . $label . ' harus antara 1 dan ' . $qLimit . '.');
        }
    }

    public function getManagedUsersForDeviceLimits(): array
    {
        return $this->pdo->query("SELECT id,username,display_name,olt_limit,mikrotik_limit,switch_limit FROM users WHERE role<>'admin' ORDER BY username ASC")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function setManagedUserDeviceLimits(int $userId, int $oltLimit, int $mikrotikLimit, int $switchLimit): void
    {
        if ($userId < 1) throw new InvalidArgumentException('Akun pengguna tidak valid.');
        $this->validateManagedDeviceLimit($oltLimit, 'olt', 'OLT');
        $this->validateManagedDeviceLimit($mikrotikLimit, 'mikrotik', 'MikroTik');
        $this->validateManagedDeviceLimit($switchLimit, 'cisco_catalyst_2960s', 'switch');
        $stmt = $this->pdo->prepare("UPDATE users SET olt_limit=:olt,mikrotik_limit=:mikrotik,switch_limit=:switch WHERE id=:id AND role<>'admin'");
        $stmt->execute(['olt' => $oltLimit, 'mikrotik' => $mikrotikLimit, 'switch' => $switchLimit, 'id' => $userId]);
        if ($stmt->rowCount() === 0) {
            $user = $this->getUser($userId);
            if ($user === null || (string)$user['role'] === 'admin') throw new InvalidArgumentException('Akun pengguna tidak ditemukan.');
        }
    }

    private function qUserLimit(): int
    {
        require_once dirname(__DIR__, 2) . '/app/CanonicalJson.php';
        require_once dirname(__DIR__, 2) . '/app/RuntimeBridge.php';
        $bridge = new RuntimeBridge($this->pdo, (array)(require '/config/app/config/service.php'));
        $state = $bridge->state();
        $value = $state['claims']['quota']['q0'] ?? null;
        if (($state['mode'] ?? '') !== 'ready' || !is_numeric($value) || (int)$value < 0) {
            throw new RuntimeException('Batas akun belum tersedia.');
        }
        return (int)$value;
    }

    private function acquireDeviceQuotaLock(string $deviceType): string
    {
        $name = 'linnms_quota_' . substr(sha1($this->databaseName . ':' . $deviceType), 0, 32);
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:name,5)');
        $stmt->execute(['name' => $name]);
        if ((int)$stmt->fetchColumn() !== 1) throw new RuntimeException('Kuota perangkat sedang diperbarui. Coba lagi.');
        return $name;
    }

    private function releaseDeviceQuotaLock(string $name): void
    {
        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $stmt->execute(['name' => $name]);
        } catch (Throwable) {
        }
    }

    public function getUserOltQuota(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(u.olt_limit, :default_limit) AS olt_limit,
                    (SELECT COUNT(*) FROM devices o WHERE o.user_id=u.id AND o.device_type='olt') AS user_used,
                    (SELECT COUNT(*) FROM devices o WHERE o.device_type='olt') AS used,
                    u.role
             FROM users u WHERE u.id=:id LIMIT 1"
        );
        $stmt->execute(['default_limit' => self::DEFAULT_OLT_LIMIT, 'id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) throw new RuntimeException('Akun tidak ditemukan.');
        $limit = $this->qDeviceLimit('olt');
        $used = max(0, (int)($row['used'] ?? 0));
        $isOwner = (string)($row['role'] ?? '') === 'admin';
        $userLimit = $isOwner ? 0 : max(0, (int)$row['olt_limit']);
        $userUsed = max(0, (int)($row['user_used'] ?? 0));
        $unlimited = $limit === 0;
        $qFull = !$unlimited && $used >= $limit;
        $accountFull = $userLimit > 0 && $userUsed >= $userLimit;
        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => $unlimited ? null : max(0, $limit - $used),
            'unlimited' => $unlimited,
            'user_used' => $userUsed,
            'user_limit' => $userLimit,
            'can_add' => !$qFull && !$accountFull,
            'blocked_by' => $qFull ? 'q' : ($accountFull ? 'account' : null),
        ];
    }

    public function setUserOltLimit(int $userId, int $limit): bool
    {
        $this->validateManagedDeviceLimit($limit, 'olt', 'OLT');
        $stmt = $this->pdo->prepare("UPDATE users SET olt_limit=:limit WHERE id=:id AND role<>'admin'");
        $stmt->execute(['limit' => $limit, 'id' => $userId]);
        $user = $this->getUser($userId);
        return is_array($user) && (string)($user['role'] ?? '') !== 'admin';
    }

    public function getUserSnmpDeviceQuota(int $userId, string $deviceType): array
    {
        $isMikrotik = $deviceType === 'mikrotik';
        if (!$isMikrotik && $deviceType !== 'cisco_catalyst_2960s') throw new InvalidArgumentException('Jenis perangkat tidak valid.');
        $column = $isMikrotik ? 'mikrotik_limit' : 'switch_limit';
        $default = $isMikrotik ? self::DEFAULT_MIKROTIK_LIMIT : self::DEFAULT_SWITCH_LIMIT;
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(u.{$column}, :default_limit) AS device_limit,
                    (SELECT COUNT(*) FROM devices d WHERE d.user_id=u.id AND d.device_type=:device_type) AS user_used,
                    (SELECT COUNT(*) FROM devices d WHERE d.device_type=:global_device_type) AS used,
                    u.role
             FROM users u WHERE u.id=:id LIMIT 1"
        );
        $stmt->execute(['default_limit' => $default, 'device_type' => $deviceType, 'global_device_type' => $deviceType, 'id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) throw new RuntimeException('Akun tidak ditemukan.');
        $limit = $this->qDeviceLimit($deviceType);
        $used = max(0, (int)($row['used'] ?? 0));
        $isOwner = (string)($row['role'] ?? '') === 'admin';
        $userLimit = $isOwner ? 0 : max(0, (int)$row['device_limit']);
        $userUsed = max(0, (int)($row['user_used'] ?? 0));
        $unlimited = $limit === 0;
        $qFull = !$unlimited && $used >= $limit;
        $accountFull = $userLimit > 0 && $userUsed >= $userLimit;
        return ['used'=>$used,'limit'=>$limit,'remaining'=>$unlimited?null:max(0,$limit-$used),'unlimited'=>$unlimited,'user_used'=>$userUsed,'user_limit'=>$userLimit,'can_add'=>!$qFull&&!$accountFull,'blocked_by'=>$qFull?'q':($accountFull?'account':null)];
    }

    public function setUserSnmpDeviceLimits(int $userId, int $mikrotikLimit, int $switchLimit): bool
    {
        $this->validateManagedDeviceLimit($mikrotikLimit, 'mikrotik', 'MikroTik');
        $this->validateManagedDeviceLimit($switchLimit, 'cisco_catalyst_2960s', 'switch');
        $stmt = $this->pdo->prepare("UPDATE users SET mikrotik_limit=:mikrotik_limit,switch_limit=:switch_limit WHERE id=:id AND role<>'admin'");
        $stmt->execute(['mikrotik_limit'=>$mikrotikLimit,'switch_limit'=>$switchLimit,'id'=>$userId]);
        $user = $this->getUser($userId);
        return is_array($user) && (string)($user['role'] ?? '') !== 'admin';
    }

    public function createUser(string $username, string $password, string $displayName = '', int $oltLimit = self::DEFAULT_OLT_LIMIT, int $mikrotikLimit = self::DEFAULT_MIKROTIK_LIMIT, int $switchLimit = self::DEFAULT_SWITCH_LIMIT, string $role = 'user'): int
    {
        $username = trim($username);
        if ($username === '' || $password === '') throw new InvalidArgumentException('Username dan password wajib diisi.');
        $role = $role === 'admin' ? 'admin' : 'user';
        if ($role !== 'admin') {
            $this->validateManagedDeviceLimit($oltLimit, 'olt', 'OLT');
            $this->validateManagedDeviceLimit($mikrotikLimit, 'mikrotik', 'MikroTik');
            $this->validateManagedDeviceLimit($switchLimit, 'cisco_catalyst_2960s', 'switch');
        }

        $userLimit = $this->qUserLimit();
        $quotaLock = $userLimit > 0 ? $this->acquireDeviceQuotaLock('user') : null;
        try {
            if ($userLimit > 0) {
                $userCount = (int)$this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
                if ($userCount >= $userLimit) throw new InvalidArgumentException('Batas akun keseluruhan telah tercapai.');
            }
            $this->pdo->beginTransaction();
            try {
                $stmt = $this->pdo->prepare("INSERT INTO users(username,password,display_name,role,is_active,olt_limit,mikrotik_limit,switch_limit) VALUES (:username,:password,:display_name,:role,1,:olt_limit,:mikrotik_limit,:switch_limit)");
                $stmt->execute(['username'=>$username,'password'=>$this->hashUserPassword($password),'display_name'=>trim($displayName)!==''?trim($displayName):null,'role'=>$role,'olt_limit'=>$oltLimit,'mikrotik_limit'=>$mikrotikLimit,'switch_limit'=>$switchLimit]);
                $id = (int) $this->pdo->lastInsertId();
                $this->pdo->prepare('INSERT INTO user_sync_states(user_id) VALUES (:id)')->execute(['id' => $id]);
                $this->pdo->commit();
                return $id;
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $e;
            }
        } finally {
            if ($quotaLock !== null) $this->releaseDeviceQuotaLock($quotaLock);
        }
    }

    public function setUserActive(int $id, bool $active): void
    {
        $this->pdo->prepare("UPDATE users SET is_active=:active WHERE id=:id AND role<>'admin'")->execute(['active' => $active ? 1 : 0, 'id' => $id]);
    }

    public function resetUserPassword(int $id, string $password): void
    {
        if ($password === '') throw new InvalidArgumentException('Password baru tidak boleh kosong.');
        $this->pdo->prepare('UPDATE users SET password=:password WHERE id=:id')->execute(['password' => $this->hashUserPassword($password), 'id' => $id]);
    }

    public function updateManagedUser(
        int $id,
        string $username,
        string $displayName,
        string $newPassword,
        bool $isActive,
        int $oltLimit,
        int $mikrotikLimit,
        int $switchLimit,
        bool $clearTelegramBot = false
    ): void {
        $username = trim($username);
        $displayName = trim($displayName);
        if (preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username) !== 1) throw new InvalidArgumentException('Username tidak valid.');
        if (mb_strlen($displayName) > 255) throw new InvalidArgumentException('Nama tampilan maksimal 255 karakter.');
        $this->validateManagedDeviceLimit($oltLimit, 'olt', 'OLT');
        $this->validateManagedDeviceLimit($mikrotikLimit, 'mikrotik', 'MikroTik');
        $this->validateManagedDeviceLimit($switchLimit, 'cisco_catalyst_2960s', 'switch');

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT role FROM users WHERE id=:id FOR UPDATE');
            $lock->execute(['id' => $id]);
            $role = $lock->fetchColumn();
            if (!is_string($role) || $role === 'admin') throw new InvalidArgumentException('User tidak ditemukan atau tidak dapat diedit.');

            $duplicate = $this->pdo->prepare('SELECT 1 FROM users WHERE username=:username AND id<>:id LIMIT 1');
            $duplicate->execute(['username' => $username, 'id' => $id]);
            if ($duplicate->fetchColumn()) throw new InvalidArgumentException('Username sudah digunakan akun lain.');

            $fields = 'username=:username,display_name=:display_name,is_active=:is_active,olt_limit=:olt_limit,mikrotik_limit=:mikrotik_limit,switch_limit=:switch_limit';
            $params = [
                'username' => $username,
                'display_name' => $displayName !== '' ? $displayName : null,
                'is_active' => $isActive ? 1 : 0,
                'olt_limit' => $oltLimit,
                'mikrotik_limit' => $mikrotikLimit,
                'switch_limit' => $switchLimit,
                'id' => $id,
            ];
            if ($newPassword !== '') {
                $fields .= ',password=:password';
                $params['password'] = $this->hashUserPassword($newPassword);
            }
            $this->pdo->prepare("UPDATE users SET {$fields} WHERE id=:id AND role<>'admin'")->execute($params);

            if ($clearTelegramBot) {
                $this->saveUserSettings($id, ['telegram_enabled' => 0, 'telegram_bot_token' => '', 'telegram_bot_username' => '', 'telegram_bot_name' => '', 'telegram_update_offset' => 0, 'telegram_chat_id' => '', 'telegram_chat_type' => '', 'telegram_chat_title' => '']);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function deleteUser(int $id): void
    {
        $this->pdo->prepare("DELETE FROM users WHERE id=:id AND role<>'admin'")->execute(['id' => $id]);
    }

    public function getUsersWithStats(): array
    {
        $sql = "SELECT u.id,u.username,u.display_name,u.role,u.is_active,u.olt_limit,u.mikrotik_limit,u.switch_limit,u.last_login_at,u.created_at,
                sy.last_sync_at,sy.last_sync_status,
                (SELECT COUNT(*) FROM devices o LEFT JOIN device_status st ON st.device_id=o.id WHERE o.user_id=u.id AND o.device_type='olt' AND COALESCE(st.is_present,1)=1) olt_count,
                (SELECT COUNT(*) FROM devices d WHERE d.user_id=u.id AND d.device_type='mikrotik') mikrotik_count,
                (SELECT COUNT(*) FROM devices d WHERE d.user_id=u.id AND d.device_type='cisco_catalyst_2960s') switch_count,
                (SELECT COUNT(*) FROM onus n WHERE n.user_id=u.id AND n.is_present=1) onu_count
            FROM users u
            LEFT JOIN user_sync_states sy ON sy.user_id=u.id
            ORDER BY u.role='admin' DESC,u.username ASC";
        $users = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $settings = [];
        foreach ($this->pdo->query("SELECT user_id,setting_key,setting_value FROM user_settings WHERE setting_key IN ('telegram_enabled','telegram_chat_id','telegram_bot_token','notify_onu_state','notify_onu_signal','notify_onu_problem','notify_onu_details','notify_device_status','notify_interface_changes','notify_pppoe_status','signal_threshold_dbm')") as $row) {
            $settings[(int)$row['user_id']][(string)$row['setting_key']] = (string)$row['setting_value'];
        }
        foreach ($users as &$user) {
            $values = $settings[(int)$user['id']] ?? [];
            foreach (['telegram_enabled' => '0', 'telegram_chat_id' => '', 'notify_onu_state' => '1', 'notify_onu_signal' => '1', 'notify_onu_problem' => '1', 'notify_onu_details' => '1', 'notify_device_status' => '1', 'notify_interface_changes' => '1', 'notify_pppoe_status' => '1', 'signal_threshold_dbm' => (string)self::ONU_RX_DETECTION_DEFAULT_DBM] as $key => $default) $user[$key] = $values[$key] ?? $default;
            $user['has_telegram_bot'] = trim((string)($values['telegram_bot_token'] ?? '')) !== '' ? 1 : 0;
            $user['telegram_ready'] = trim((string)$user['telegram_chat_id']) !== '' ? 1 : 0;
        }
        unset($user);
        return $users;
    }

    public function getUserDisplayName(int $userId): string
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(NULLIF(display_name,''), username, '') FROM users WHERE id=:id LIMIT 1"
        );
        $stmt->execute(['id' => $userId]);
        $name = trim((string)($stmt->fetchColumn() ?: ''));
        return $name !== '' ? $name : ('user-' . $userId);
    }

    public function getUserSettings(int $userId): array
    {
        if ($userId < 1 || !is_array($this->getUser($userId))) return [];
        $defaults = [
            'telegram_enabled' => '0', 'telegram_bot_token' => '', 'telegram_bot_username' => '',
            'telegram_bot_name' => '', 'telegram_update_offset' => '0', 'telegram_chat_id' => '',
            'telegram_chat_type' => '', 'telegram_chat_title' => '',
            'notify_onu_state' => '1', 'notify_onu_signal' => '1', 'notify_onu_problem' => '1',
            'notify_onu_details' => '1', 'notify_device_status' => '1',
            'notify_interface_changes' => '1', 'notify_pppoe_status' => '1',
            'signal_threshold_dbm' => (string)self::ONU_RX_DETECTION_DEFAULT_DBM,
            'onu_rx_problem_threshold_dbm' => (string)self::ONU_RX_PROBLEM_DEFAULT_DBM,
            'whatsapp_enabled' => '0', 'whatsapp_provider' => '', 'whatsapp_token' => '',
            'whatsapp_device' => '', 'whatsapp_base_url' => '', 'whatsapp_target' => '',
            'whatsapp_target_type' => 'phone', 'connected_number' => '',
            'whatsapp_last_status' => '', 'whatsapp_last_at' => '',
        ];
        $stmt = $this->pdo->prepare('SELECT setting_key,setting_value FROM user_settings WHERE user_id=:id');
        $stmt->execute(['id' => $userId]);
        $settings = $defaults;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $settings[(string)$row['setting_key']] = (string)$row['setting_value'];
        $state = $this->pdo->prepare('SELECT last_sync_at,last_api_sync_at,last_sync_status,last_sync_message FROM user_sync_states WHERE user_id=:id LIMIT 1');
        $state->execute(['id' => $userId]);
        return ['user_id' => $userId] + $settings + ($state->fetch(PDO::FETCH_ASSOC) ?: []);
    }

    public function getUserActivityLogs(int $userId, int $limit = 50): array
    {
        return $this->getUserActivityLogsPage($userId, $limit, 0);
    }

    public function getUserActivityLogsPage(int $userId, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);
        $conditions = $this->userActivityActionConditions($userId);
        if ($conditions === null) return [];

        $stmt = $this->pdo->prepare(
            "SELECT id,action_name,level,message,created_at FROM app_logs
             WHERE {$conditions['sql']}
             ORDER BY created_at DESC,id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($conditions['params']);

        return $this->mapUserActivityRows($stmt->fetchAll() ?: [], $this->userActivityActionMap(), $limit);
    }

    public function countUserActivityLogs(int $userId): int
    {
        $conditions = $this->userActivityActionConditions($userId);
        if ($conditions === null) return 0;
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM app_logs WHERE {$conditions['sql']}");
        $stmt->execute($conditions['params']);
        return (int)$stmt->fetchColumn();
    }

    private function userActivityActionConditions(int $userId): ?array
    {
        $actions = array_keys($this->userActivityActionMap());
        if ($userId < 1 || $actions === []) return null;

        $placeholders = [];
        $params = ['uid' => (string)$userId];
        $index = 0;
        foreach ($actions as $action) {
            $name = 'action' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = (string)$action;
            $index++;
        }

        return [
            'sql' => 'user_id=:uid AND action_name IN (' . implode(',', $placeholders) . ')',
            'params' => $params,
        ];
    }

    private function userActivityActionMap(): array
    {
        $map = [
            'login' => [
                'activity' => 'Login',
                'success' => 'Berhasil masuk',
                'failed' => 'Login gagal.',
            ],
            'logout' => [
                'activity' => 'Logout',
                'success' => 'Berhasil keluar',
                'failed' => 'Proses keluar gagal.',
            ],
            'update_profile' => [
                'activity' => 'Profil',
                'success' => 'Profil berhasil diperbarui.',
                'failed' => 'Profil gagal diperbarui.',
            ],
            'add_olt' => [
                'activity' => 'Tambah OLT',
                'success' => 'OLT berhasil ditambahkan.',
                'failed' => 'OLT gagal ditambahkan.',
            ],
            'add_device' => [
                'activity' => 'Tambah Perangkat',
                'success' => 'Perangkat berhasil ditambahkan.',
                'failed' => 'Perangkat gagal ditambahkan.',
            ],
            'add_mikrotik' => [
                'activity' => 'Tambah MikroTik',
                'success' => 'MikroTik berhasil ditambahkan.',
                'failed' => 'MikroTik gagal ditambahkan.',
            ],
            'add_switch' => [
                'activity' => 'Tambah Switch',
                'success' => 'Switch berhasil ditambahkan.',
                'failed' => 'Switch gagal ditambahkan.',
            ],
            'update_device' => [
                'activity' => 'Edit Perangkat',
                'success' => 'Perangkat berhasil diperbarui.',
                'failed' => 'Perangkat gagal diperbarui.',
                'use_message' => true,
            ],
            'delete_device' => [
                'activity' => 'Hapus Perangkat',
                'success' => 'Perangkat berhasil dihapus.',
                'failed' => 'Perangkat gagal dihapus.',
            ],
            'sync_snmp_olt' => [
                'activity' => 'Perbarui OLT',
                'success' => 'Data OLT berhasil diperbarui.',
                'failed' => 'Data OLT gagal diperbarui.',
                'use_message' => true,
            ],
            'sync_snmp_olt_down' => [
                'activity' => 'Perbarui OLT',
                'success' => 'Data OLT berhasil diperbarui.',
                'failed' => 'Perangkat tidak dapat dijangkau.',
                'use_message' => true,
            ],
            'telnet_connection' => [
                'activity' => 'Koneksi Telnet',
                'success' => 'Koneksi Telnet berhasil.',
                'failed' => 'Koneksi Telnet gagal.',
                'use_message' => true,
            ],
            'olt_update_stopped' => [
                'activity' => 'Pembaruan Perangkat',
                'success' => 'Pembaruan perangkat dapat dilanjutkan.',
                'failed' => 'Perangkat gagal dibaca. Percobaan login dalam beberapa menit gagal. Pembaruan dihentikan.',
            ],
            'scan_olt' => [
                'activity' => 'Pindai OLT',
                'success' => 'Pemindaian OLT selesai.',
                'failed' => 'Pemindaian OLT gagal.',
            ],
            'scan_all_olts' => [
                'activity' => 'Pindai OLT',
                'success' => 'Pemindaian OLT selesai.',
                'failed' => 'Pemindaian OLT gagal.',
            ],
            'update_snmp_olt_port_description' => [
                'activity' => 'Keterangan PON',
                'success' => 'Keterangan PON berhasil disimpan.',
                'failed' => 'Keterangan PON gagal disimpan.',
            ],
            'update_onu_name' => [
                'activity' => 'Nama ONU',
                'success' => 'Nama ONU berhasil diperbarui.',
                'failed' => 'Nama ONU gagal diperbarui.',
            ],
            'save_onu_control' => [
                'activity' => 'Konfigurasi ONU',
                'success' => 'Konfigurasi ONU berhasil diperbarui.',
                'failed' => 'Konfigurasi ONU gagal diperbarui.',
            ],
            'onu_operation' => [
                'activity' => 'Kontrol ONU',
                'success' => 'Perintah ONU berhasil diproses.',
                'failed' => 'Perintah ONU gagal diproses.',
            ],
            'reboot_onu' => [
                'activity' => 'Reboot ONU',
                'success' => 'Perintah reboot ONU berhasil dikirim.',
                'failed' => 'Perintah reboot ONU gagal dikirim.',
            ],
            'delete_onu' => [
                'activity' => 'Hapus ONU',
                'success' => 'ONU berhasil dihapus.',
                'failed' => 'ONU gagal dihapus.',
            ],
            'save_telegram_user_bot' => [
                'activity' => 'Bot Telegram Akun',
                'success' => 'Bot Telegram akun berhasil disimpan.',
                'failed' => 'Bot Telegram akun gagal disimpan.',
            ],
            'save_telegram' => [
                'activity' => 'Notifikasi Telegram',
                'success' => 'Pengaturan notifikasi berhasil disimpan.',
                'failed' => 'Pengaturan notifikasi gagal disimpan.',
            ],
            'telegram_toggle_notifications' => [
                'activity' => 'Notifikasi Telegram',
                'success' => 'Status pengiriman notifikasi berhasil diubah.',
                'failed' => 'Status pengiriman notifikasi gagal diubah.',
            ],
            'telegram_disable' => [
                'activity' => 'Notifikasi Telegram',
                'success' => 'Notifikasi Telegram dinonaktifkan.',
                'failed' => 'Notifikasi Telegram gagal dinonaktifkan.',
            ],
            'connection_test_telegram' => [
                'activity' => 'Notifikasi Telegram',
                'success' => 'Tes koneksi berhasil diproses.',
                'failed' => 'Tes koneksi gagal diproses.',
            ],
        ];

        foreach (['delete_mikrotik', 'delete_switch', 'delete_snmp_olt', 'delete_external_api_olt'] as $legacyAction) {
            $map[$legacyAction] = $map['delete_device'];
        }

        return $map;
    }

    private function mapUserActivityRows(array $rows, array $frontend, int $limit): array
    {
        $result = [];
        foreach ($rows as $row) {
            $action = trim((string)($row['action_name'] ?? ''));
            if ($action === '' || !isset($frontend[$action])) continue;

            $level = strtolower(trim((string)($row['level'] ?? 'info')));
            $ok = !in_array($level, ['error', 'critical', 'warning'], true);
            $definition = $frontend[$action];
            $message = ($ok ? $definition['success'] : $definition['failed']);
            $rawMessage = trim((string)($row['message'] ?? ''));
            if (!empty($definition['use_message']) && $rawMessage !== '') {
                $message = $rawMessage;
            }
            $result[] = [
                'id' => (int)($row['id'] ?? 0),
                'created_at' => $row['created_at'] ?? null,
                'activity' => (string)$definition['activity'],
                'status' => $ok ? 'Berhasil' : 'Gagal',
                'is_ok' => $ok ? 1 : 0,
                'duration_ms' => 0,
                'message' => (string)$message,
            ];

            if (count($result) >= $limit) break;
        }

        return $result;
    }

    private function userRxDetectionDbm(int $userId): float
    {
        if ($userId < 1) return 1.0;
        if (array_key_exists($userId, $this->rxDetectionMemory)) return $this->rxDetectionMemory[$userId];
        $threshold = effective_signal_threshold_dbm($this->getUserSettings($userId));
        return $this->rxDetectionMemory[$userId] = self::clampOnuRxDetection($threshold);
    }

      private function userSignalThresholdDbm(int $userId, float $fallbackDbm): float
    {
        $threshold = (float)($this->getOnuProblemSettings($userId)['rx_power_threshold_dbm'] ?? $fallbackDbm);

        return self::clampOnuRxProblemLimit(is_finite($threshold) && $threshold > 0.0 ? $threshold : $fallbackDbm);
    }
}
