<?php

declare(strict_types=1);

trait LinnmsLogTrait
{
    public function recordNotificationHistory(int $userId, string $gateway, string $message, string $provider = '', string $deviceName = '', string $category = '', ?string $status = null, ?int $eventId = null, int $attempts = 1): void
    {
        $message = trim($message);
        if ($userId < 1 || !in_array($gateway, ['telegram', 'whatsapp'], true) || $message === '') {
            throw new InvalidArgumentException('Riwayat notifikasi tidak valid.');
        }
        $status = strtolower(trim((string)$status));
        // Status kosong berarti tidak diketahui; jangan default ke "sent".
        if (!in_array($status, ['sent', 'failed', 'pending'], true)) $status = null;
        $eventId = $eventId !== null && $eventId > 0 ? $eventId : null;
        $attempts = max(1, $attempts);
        $stmt = $this->pdo->prepare(
            'INSERT INTO notification_history(user_id,gateway,provider,message,device_name,category,status,event_id,attempts,send_at) VALUES (:uid,:gateway,:provider,:message,:device_name,:category,:status,:event_id,:attempts,NOW())'
        );
        $stmt->execute([
            'uid' => $userId,
            'gateway' => $gateway,
            'provider' => mb_substr(trim($provider), 0, 32),
            'message' => $message,
            'device_name' => mb_substr(trim($deviceName), 0, 500),
            'category' => mb_substr(trim($category), 0, 80),
            'status' => $status,
            'event_id' => $eventId,
            'attempts' => $attempts,
        ]);
        $this->trimNotificationHistory($userId, $gateway);
    }

    public function countNotificationHistory(int $userId, string $gateway): int
    {
        if ($userId < 1) return 0;
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM notification_history WHERE user_id=:uid AND gateway=:gateway');
        $stmt->execute(['uid' => $userId, 'gateway' => $gateway]);
        return (int)$stmt->fetchColumn();
    }

    public function trimNotificationHistory(int $userId, string $gateway, int $max = self::NOTIFICATION_HISTORY_LIMIT): int
    {
        if ($userId < 1) return 0;
        $max = max(1, $max);
        $stmt = $this->pdo->prepare(
            "DELETE FROM notification_history
             WHERE user_id=:user_id AND gateway=:gateway
               AND id NOT IN (
                   SELECT id FROM (
                       SELECT id FROM notification_history
                        WHERE user_id=:keep_user_id AND gateway=:keep_gateway
                        ORDER BY created_at DESC,id DESC LIMIT {$max}
                   ) AS keep_rows
               )"
        );
        $stmt->execute(['user_id' => $userId, 'gateway' => $gateway, 'keep_user_id' => $userId, 'keep_gateway' => $gateway]);
        return $stmt->rowCount();
    }

    public function getNotificationHistory(int $userId, string $gateway, int $limit = 100, int $offset = 0): array
    {
        if ($userId < 1) return [];
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $stmt = $this->pdo->prepare(
            "SELECT id,user_id,gateway,provider,message,device_name,category,status,event_id,attempts,send_at,created_at
             FROM notification_history WHERE user_id=:uid AND gateway=:gateway
             ORDER BY created_at DESC,id DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute(['uid' => $userId, 'gateway' => $gateway]);
        return $stmt->fetchAll() ?: [];
    }

    private function normalizeLoginIp(string $ipAddress): string
    {
        $ipAddress = trim($ipAddress);
        return $ipAddress !== '' ? mb_substr($ipAddress, 0, 64) : 'unknown';
    }

    public function logApplication(
        ?int $userId,
        string $area,
        string $action,
        string $level,
        string $message,
        array $context = [],
        ?string $actorUsername = null,
        ?string $ipAddress = null
    ): void {
        $level = strtolower(trim($level));
        if ($level === 'debug' || $level === 'success') return;
        $action = mb_substr(trim($action) !== '' ? trim($action) : 'activity', 0, 120);
        if (in_array($action, ['hioso_live_skip', 'olt_cli_live_skip'], true)) return;
        if (in_array($action, ['device_connection_offline', 'device_connection_online', 'sync_snmp_olt_down'], true)) {
            $action = 'device_status';
            $level = 'info';
        } elseif ($action === 'device_connection_error') {
            $action = 'device_status';
            $level = 'error';
        }
        if (!in_array($level, ['info','warning','error','critical'], true)) {
            $level = 'info';
        }
        if ($level === 'critical' && $userId !== null) $level = 'error';
        $messageFallback = in_array($level, ['error','critical'], true)
            ? 'Error source returned an empty message.'
            : 'aplikasi.';
        if ($message === '') $message = $messageFallback;
        if ($actorUsername === null && $userId !== null && $userId > 0) {
            $u = $this->getUser($userId);
            $actorUsername = is_array($u) ? trim((string)($u['username'] ?? '')) : null;
        }
        $operationalActions = [
            'communication_error', 'device_inspect', 'device_sync', 'read_failed',
            'olt_update_stopped', 'pipeline_writer', 'scheduled_notification',
            'scheduled_sync', 'scheduled_sync_worker', 'send_notification',
            'sync', 'sync_snmp_olt', 'sync_snmp_olt_down', 'sync_lock', 'sync_lock_open', 'pon_snapshot_sync', 'pon_snapshot_store',
            'onu_snapshot_sync', 'telnet_connection',
        ];
        $isOperationalError = in_array($level, ['error','critical'], true)
            && (
                in_array($action, $operationalActions, true)
                || str_starts_with($action, 'read_pon_')
                || str_starts_with($action, 'pon_')
            );
        if ($isOperationalError) {
            $duplicate = $this->pdo->prepare(
                "SELECT id FROM app_logs
                 WHERE user_id <=> :uid AND action_name=:action
                   AND level=:level AND message=:message
                   AND created_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)
                 ORDER BY id DESC LIMIT 1"
            );
            $duplicate->execute([
                'uid' => $userId !== null && $userId > 0 ? $userId : null,
                'action' => $action,
                'level' => $level,
                'message' => $message,
            ]);
            if ($duplicate->fetchColumn() !== false) return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO app_logs(user_id,username,action_name,level,message) '
            . 'VALUES (:uid,:actor,:action,:level,:message)'
        );
        $stmt->execute([
            'uid' => $userId !== null && $userId > 0 ? $userId : null,
            'actor' => ($actorUsername !== null && trim($actorUsername) !== '') ? mb_substr(trim($actorUsername), 0, 80) : null,
            'action' => $action,
            'level' => $level,
            'message' => $message,
        ]);
    }

    public function recordReadFailure(int $userId, string $source, string $message, array $context = []): void
    {
        $scope = trim((string)($context['scope'] ?? ''));
        if ($userId < 1 || $scope === '') return;
        $confirmed = $this->confirmScannedStatusTracker(
            $userId,
            'READ_ERROR',
            $scope,
            'read_error',
            'online',
            'error',
            2
        );
        if ($confirmed === null) return;
        $source = mb_substr(trim($source), 0, 60);
        $message = trim($message);
        if ($message === '') $message = 'Gagal membaca data ' . ($source !== '' ? $source : 'perangkat') . '.';
        if ($source !== '') $message = '[' . $source . '] ' . $message;
        $this->logApplication(
            $userId > 0 ? $userId : null,
            'Sinkronisasi',
            'read_failed',
            'error',
            $message
        );
    }

    public function clearReadFailure(int $userId, string $scope): void
    {
        $scope = mb_substr(trim($scope), 0, 300);
        if ($userId < 1 || $scope === '') return;
        $stmt = $this->pdo->prepare(
            "UPDATE sync_state s JOIN sync_entitas e ON e.id=s.monitor_entity_id
             SET s.confirmed_value='online',s.candidate_value=NULL,s.candidate_count=0,
                 s.candidate_since=NULL,s.updated_at=NOW()
             WHERE e.user_id=:uid AND e.entity_type='READ_ERROR' AND e.entity_key=:scope
               AND s.field_name='read_error'"
        );
        $stmt->execute(['uid'=>$userId,'scope'=>$scope]);
    }

    public function pruneLogHistory(): void
    {
        $locked = (int)$this->pdo->query("SELECT GET_LOCK('linnms_log_retention',0)")->fetchColumn() === 1;
        if (!$locked) return;

        try {
            $this->pdo->exec("DELETE FROM sync_pipeline WHERE status='done'");
            $this->pdo->exec(
                "DELETE FROM sync_pipeline
                 WHERE status='failed' AND created_at<DATE_SUB(NOW(),INTERVAL 90 DAY)"
            );

            $this->pdo->exec("DELETE FROM app_logs WHERE created_at<DATE_SUB(NOW(),INTERVAL 7 DAY)");

            $notificationTargets = $this->pdo->query('SELECT DISTINCT user_id,gateway FROM notification_history');
            foreach ($notificationTargets->fetchAll(PDO::FETCH_ASSOC) as $target) {
                $this->trimNotificationHistory((int)$target['user_id'], (string)$target['gateway']);
            }
            $this->pdo->exec(
                "DELETE FROM change_events
                 WHERE notified_at IS NOT NULL
                   AND created_at<DATE_SUB(NOW(),INTERVAL 90 DAY)"
            );
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('linnms_log_retention')");
        }
    }

    public function getApplicationLogs(
        int $limit = 500,
        ?string $level = null,
        ?int $userId = null,
        int $offset = 0
    ): array {
        $limit = max(1, min(5000, $limit));
        $offset = max(0, $offset);
        $sql = "SELECT l.*,COALESCE(NULLIF(l.username,''),u.username,'System') display_actor,u.role AS actor_role "
            . "FROM app_logs l LEFT JOIN users u ON u.id=l.user_id WHERE 1=1";
        $params = [];
        if ($level !== null && trim($level) !== '') {
            $sql .= ' AND l.level=:level';
            $params['level'] = strtolower(trim($level));
        }
        if ($userId !== null && $userId > 0) {
            $sql .= ' AND l.user_id=:uid';
            $params['uid'] = $userId;
        }
        $sql .= " ORDER BY l.created_at DESC,l.id DESC LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    public function countApplicationLogs(?string $level = null, ?int $userId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM app_logs WHERE 1=1';
        $params = [];
        if ($level !== null && trim($level) !== '') {
            $sql .= ' AND level=:level';
            $params['level'] = strtolower(trim($level));
        }
        if ($userId !== null && $userId > 0) {
            $sql .= ' AND user_id=:uid';
            $params['uid'] = $userId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function clearApplicationLogs(int $olderThanDays = 0): int
    {
        $olderThanDays = max(0, min(3650, $olderThanDays));
        if ($olderThanDays === 0) {
            return (int)$this->pdo->exec('DELETE FROM app_logs');
        }
        $stmt = $this->pdo->prepare('DELETE FROM app_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)');
        $stmt->execute(['days' => $olderThanDays]);
        return (int)$stmt->rowCount();
    }
}
