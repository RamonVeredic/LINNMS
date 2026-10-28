<?php

declare(strict_types=1);

trait LinnmsSyncTrait
{
    public function getOltScanStatus(int $userId, int $oltRowId): array
    {
        $this->ensureOltScanRequestsTable();
        $stmt = $this->pdo->prepare(
            "SELECT id, status, force_full, requested_at, started_at, finished_at, last_error, phase_detail, processed_onus, total_onus,
                    attempt, TIMESTAMPDIFF(SECOND, started_at, COALESCE(finished_at, NOW())) AS elapsed_seconds
             FROM olt_scan_requests
             WHERE user_id=:uid AND olt_row_id=:row
             ORDER BY id DESC LIMIT 1"
        );
        $this->executeInventoryWrite($stmt, ['uid' => $userId, 'row' => max(1, $oltRowId)]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return ['ok' => true, 'active' => false, 'status' => 'idle', 'progress' => 100, 'phase' => 'Selesai', 'elapsed_seconds' => 0, 'force_full' => false];
        }
        $status = (string)$row['status'];
        $lastError = trim((string)($row['last_error'] ?? ''));
        if ($status === 'done') {
            $this->ensureSyncPipelineTable();
            $pending = $this->pdo->prepare(
                "SELECT status, COUNT(*) AS total, MAX(last_error) AS last_error FROM sync_pipeline
                 WHERE user_id=:uid AND olt_row_id=:row AND handler='snmp_olt'
                   AND created_at BETWEEN :requested AND :finished
                   AND status IN ('courier','queued','writing','failed')
                 GROUP BY status"
            );
            $pending->execute([
                'uid' => $userId,
                'row' => $oltRowId,
                'requested' => (string)$row['requested_at'],
                'finished' => (string)$row['finished_at'],
            ]);
            foreach ($pending->fetchAll(PDO::FETCH_ASSOC) ?: [] as $pendingRow) {
                if ((string)$pendingRow['status'] === 'failed') {
                    $status = 'failed';
                    $lastError = trim((string)($pendingRow['last_error'] ?? '')) ?: $lastError;
                    break;
                }
                if ((int)$pendingRow['total'] > 0) $status = 'writing';
            }
        }
        $active = in_array($status, ['queued', 'running', 'writing'], true);
        $processedOnus = max(0, (int)($row['processed_onus'] ?? 0));
        $totalOnus = max(0, (int)($row['total_onus'] ?? 0));
        $progress = $this->oltScanProgress($status, $processedOnus, $totalOnus, (string)($row['phase_detail'] ?? ''));
        $phase = match ($status) {
            'queued' => 'Menunggu diproses',
            'running' => 'Membaca perangkat',
            'writing' => 'Menyimpan hasil pembacaan',
            'done' => 'Selesai',
            default => 'Gagal',
        };
        if ($active && trim((string)($row['phase_detail'] ?? '')) !== '') $phase = (string)$row['phase_detail'];
        return [
            'ok' => true,
            'active' => $active,
            'status' => $status,
            'progress' => $progress,
            'phase' => $phase,
            'processed_onus' => $processedOnus,
            'total_onus' => $totalOnus,
            'force_full' => (int)($row['force_full'] ?? 0) === 1,
            'elapsed_seconds' => max(0, (int)($row['elapsed_seconds'] ?? 0)),
            'requested_at' => (string)($row['requested_at'] ?? ''),
            'started_at' => (string)($row['started_at'] ?? ''),
            'last_error' => $status === 'failed' ? $lastError : '',
        ];
    }

    private function oltScanProgress(string $status, int $processedOnus, int $totalOnus, string $phase): int
    {
        if ($status === 'done') return 100;
        if ($status === 'writing') return 96;
        if ($status === 'queued') return 2;
        if ($status !== 'running') return 0;
        if ($totalOnus > 0) {
            return max(5, min(92, 5 + (int)floor(min(1, $processedOnus / $totalOnus) * 85)));
        }
        if ($processedOnus > 0) return min(88, 12 + $processedOnus);
        $phase = strtolower($phase);
        if (str_contains($phase, 'menghubungi')) return 5;
        if (str_contains($phase, 'lanjutan')) return 80;
        if (str_contains($phase, 'layanan')) return 85;
        if (str_contains($phase, 'menyimpan')) return 92;
        return 12;
    }

    
    public function ensureOltScanRequestsTable(): void
    {
        if ($this->oltScanTableEnsured) return;
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS olt_scan_requests (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            olt_row_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'queued',
            force_full TINYINT(1) NOT NULL DEFAULT 1,
            initial_scan TINYINT(1) NOT NULL DEFAULT 0,
            record_changes TINYINT(1) NOT NULL DEFAULT 1,
            attempt INT UNSIGNED NOT NULL DEFAULT 0,
            worker_token VARCHAR(64) NULL,
            requested_at DATETIME NOT NULL,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            last_error TEXT NULL,
            phase_detail VARCHAR(120) NULL,
            processed_onus INT UNSIGNED NOT NULL DEFAULT 0,
            total_onus INT UNSIGNED NOT NULL DEFAULT 0,
            retry_middle_json LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_scan_requests_claim(status, requested_at, id),
            INDEX idx_scan_requests_user(user_id),
            CONSTRAINT fk_scan_requests_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if ($this->pdo->query("SHOW COLUMNS FROM olt_scan_requests LIKE 'force_full'")->fetchColumn() === false) {
            $this->pdo->exec('ALTER TABLE olt_scan_requests ADD COLUMN force_full TINYINT(1) NOT NULL DEFAULT 1');
        }
        if ($this->pdo->query("SHOW COLUMNS FROM olt_scan_requests LIKE 'initial_scan'")->fetchColumn() === false) {
            $this->pdo->exec('ALTER TABLE olt_scan_requests ADD COLUMN initial_scan TINYINT(1) NOT NULL DEFAULT 0');
        }
        if ($this->pdo->query("SHOW COLUMNS FROM olt_scan_requests LIKE 'record_changes'")->fetchColumn() === false) {
            $this->pdo->exec('ALTER TABLE olt_scan_requests ADD COLUMN record_changes TINYINT(1) NOT NULL DEFAULT 1');
        }
        if ($this->pdo->query("SHOW COLUMNS FROM olt_scan_requests LIKE 'phase_detail'")->fetchColumn() === false) {
            $this->pdo->exec('ALTER TABLE olt_scan_requests ADD COLUMN phase_detail VARCHAR(120) NULL');
        }
        if ($this->pdo->query("SHOW COLUMNS FROM olt_scan_requests LIKE 'processed_onus'")->fetchColumn() === false) {
            $this->pdo->exec('ALTER TABLE olt_scan_requests ADD COLUMN processed_onus INT UNSIGNED NOT NULL DEFAULT 0');
        }
        if ($this->pdo->query("SHOW COLUMNS FROM olt_scan_requests LIKE 'total_onus'")->fetchColumn() === false) {
            $this->pdo->exec('ALTER TABLE olt_scan_requests ADD COLUMN total_onus INT UNSIGNED NOT NULL DEFAULT 0');
        }
        if ($this->pdo->query("SHOW COLUMNS FROM olt_scan_requests LIKE 'retry_middle_json'")->fetchColumn() === false) {
            $this->pdo->exec('ALTER TABLE olt_scan_requests ADD COLUMN retry_middle_json LONGTEXT NULL');
        }
        $errorColumn = $this->pdo->query("SHOW COLUMNS FROM olt_scan_requests LIKE 'last_error'")->fetch(PDO::FETCH_ASSOC);
        if (is_array($errorColumn) && strtolower((string)($errorColumn['Type'] ?? '')) !== 'text') {
            $this->pdo->exec('ALTER TABLE olt_scan_requests MODIFY COLUMN last_error TEXT NULL');
        }
        $this->oltScanTableEnsured = true;
    }

    public function getActiveOltScanRowIds(int $userId): array
    {
        $this->ensureOltScanRequestsTable();
        $stmt = $this->pdo->prepare(
            "SELECT s.olt_row_id FROM olt_scan_requests s "
            . "JOIN (SELECT olt_row_id, MAX(id) AS id FROM olt_scan_requests WHERE user_id=:uid GROUP BY olt_row_id) latest ON latest.id=s.id "
            . "WHERE s.status IN ('queued','running','writing')"
        );
        $stmt->execute(['uid' => $userId]);
        $active = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
        $this->ensureSyncPipelineTable();
        $pending = $this->pdo->prepare(
            "SELECT DISTINCT p.olt_row_id FROM sync_pipeline p
             INNER JOIN (SELECT s.* FROM olt_scan_requests s
                 JOIN (SELECT olt_row_id, MAX(id) AS id FROM olt_scan_requests WHERE user_id=:latest_uid GROUP BY olt_row_id) latest ON latest.id=s.id
             ) s ON s.user_id=p.user_id AND s.olt_row_id=p.olt_row_id
             WHERE p.user_id=:uid AND s.status='done' AND p.handler='snmp_olt'
               AND p.created_at BETWEEN s.requested_at AND s.finished_at
               AND p.status IN ('courier','queued','writing')"
        );
        $pending->execute(['uid' => $userId, 'latest_uid' => $userId]);
        foreach ($pending->fetchAll(PDO::FETCH_COLUMN) ?: [] as $rowId) $active[(int)$rowId] = true;
        return $active;
    }

    public function hasPendingMiddleRetry(int $userId, int $oltRowId): bool
    {
        $this->ensureOltScanRequestsTable();
        $stmt = $this->pdo->prepare(
            "SELECT retry_middle_json FROM olt_scan_requests
             WHERE user_id=:uid AND olt_row_id=:row ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['uid'=>$userId, 'row'=>$oltRowId]);
        return trim((string)$stmt->fetchColumn()) !== '';
    }

    public function getLatestOltScanStatuses(int $userId): array
    {
        $this->ensureOltScanRequestsTable();
        $this->ensureSyncPipelineTable();
        $stmt = $this->pdo->prepare(
            "SELECT s.olt_row_id, CASE "
            . "WHEN s.status='done' AND EXISTS ("
            . "SELECT 1 FROM sync_pipeline p WHERE p.user_id=s.user_id AND p.olt_row_id=s.olt_row_id "
            . "AND p.handler='snmp_olt' AND p.status='failed' "
            . "AND p.created_at BETWEEN s.requested_at AND s.finished_at) THEN 'failed' "
            . "WHEN s.status='done' AND EXISTS ("
            . "SELECT 1 FROM sync_pipeline p WHERE p.user_id=s.user_id AND p.olt_row_id=s.olt_row_id "
            . "AND p.handler='snmp_olt' AND p.status IN ('courier','queued','writing') "
            . "AND p.created_at BETWEEN s.requested_at AND s.finished_at) THEN 'writing' "
            . "ELSE s.status END AS status, "
            . "s.phase_detail, s.processed_onus, s.total_onus, "
            . "COALESCE((SELECT p.last_error FROM sync_pipeline p WHERE p.user_id=s.user_id "
            . "AND p.olt_row_id=s.olt_row_id AND p.handler='snmp_olt' AND p.status='failed' "
            . "AND p.created_at BETWEEN s.requested_at AND s.finished_at ORDER BY p.id DESC LIMIT 1), s.last_error) AS last_error "
            . 'FROM olt_scan_requests s '
            . 'JOIN (SELECT olt_row_id, MAX(id) AS id FROM olt_scan_requests WHERE user_id=:uid GROUP BY olt_row_id) latest ON latest.id=s.id'
        );
        $stmt->execute(['uid' => $userId]);
        $statuses = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $row['progress'] = $this->oltScanProgress(
                (string)($row['status'] ?? ''),
                max(0, (int)($row['processed_onus'] ?? 0)),
                max(0, (int)($row['total_onus'] ?? 0)),
                (string)($row['phase_detail'] ?? '')
            );
            $row['phase'] = (string)($row['phase_detail'] ?? '');
            $statuses[(int)$row['olt_row_id']] = $row;
        }
        return $statuses;
    }

    public function startOltScanNow(int $userId, int $oltRowId, string $workerToken): ?int
    {
        $this->ensureOltScanRequestsTable();
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM devices WHERE id=:row AND user_id=:uid FOR UPDATE');
            $lock->execute(['row' => $oltRowId, 'uid' => $userId]);
            if ($lock->fetchColumn() === false) {
                $this->pdo->commit();
                return null;
            }
            $initial = $this->pdo->prepare("SELECT 1 FROM olt_scan_requests WHERE user_id=:uid AND olt_row_id=:row AND initial_scan=1 AND status NOT IN ('done','cancelled') LIMIT 1");
            $initial->execute(['uid' => $userId, 'row' => $oltRowId]);
            if ($initial->fetchColumn() !== false) {
                $this->pdo->commit();
                return null;
            }
            $active = $this->pdo->prepare(
                "SELECT id FROM olt_scan_requests WHERE user_id=:uid AND olt_row_id=:row AND status IN ('queued','running','writing') LIMIT 1"
            );
            $active->execute(['uid' => $userId, 'row' => $oltRowId]);
            if ($active->fetchColumn() !== false) {
                $this->pdo->commit();
                return null;
            }
            $this->pdo->prepare(
                "DELETE FROM olt_scan_requests WHERE user_id=:uid AND olt_row_id=:row AND initial_scan=0 AND status IN ('done','failed')"
            )->execute(['uid' => $userId, 'row' => $oltRowId]);
            $insert = $this->pdo->prepare(
                "INSERT INTO olt_scan_requests(user_id,olt_row_id,status,force_full,attempt,worker_token,requested_at,started_at)
                 VALUES(:uid,:row,'running',1,1,:token,NOW(),NOW())"
            );
            $insert->execute(['uid' => $userId, 'row' => $oltRowId, 'token' => $workerToken]);
            $requestId = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();
            return $requestId;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    
    public function requestOltScan(int $userId, int $oltRowId): bool
    {
        $this->ensureOltScanRequestsTable();
        $oltRowId = max(1, $oltRowId);
        $this->ensureSyncPipelineTable();
        $pending = $this->pdo->prepare(
            "SELECT 1 FROM sync_pipeline WHERE user_id=:uid AND olt_row_id=:row
             AND handler='snmp_olt' AND status IN ('courier','queued','writing') LIMIT 1"
        );
        $pending->execute(['uid' => $userId, 'row' => $oltRowId]);
        if ($pending->fetchColumn() !== false) return false;
        $this->pdo->beginTransaction();
        try {
            $device = $this->pdo->prepare('SELECT id FROM devices WHERE user_id=:uid AND id=:row FOR UPDATE');
            $device->execute(['uid' => $userId, 'row' => $oltRowId]);
            if ($device->fetchColumn() === false) {
                $this->pdo->commit();
                return false;
            }
            $active = $this->pdo->prepare("SELECT 1 FROM olt_scan_requests WHERE user_id=:uid AND olt_row_id=:row AND status IN ('queued','running','writing') LIMIT 1");
            $active->execute(['uid' => $userId, 'row' => $oltRowId]);
            if ($active->fetchColumn() !== false) {
                $this->pdo->commit();
                return false;
            }
            $this->pdo->prepare(
                "DELETE FROM olt_scan_requests WHERE user_id=:uid AND olt_row_id=:row AND status IN ('done','failed')"
            )->execute(['uid' => $userId, 'row' => $oltRowId]);
            $this->pdo->prepare(
                "INSERT INTO olt_scan_requests(user_id,olt_row_id,status,force_full,initial_scan,record_changes,requested_at)
                 VALUES(:uid,:row,'queued',1,1,1,NOW())"
            )->execute(['uid' => $userId, 'row' => $oltRowId]);
            $this->pdo->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function hasActiveOltScanRequest(int $userId, int $oltRowId): bool
    {
        $this->ensureOltScanRequestsTable();
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM olt_scan_requests
             WHERE user_id=:uid AND olt_row_id=:row AND status IN ('queued','running','writing')
             LIMIT 1"
        );
        $this->executeInventoryWrite($stmt, ['uid' => $userId, 'row' => $oltRowId]);
        return $stmt->fetchColumn() !== false;
    }

    public function hasPendingOltPipeline(int $userId, int $oltRowId): bool
    {
        $this->ensureSyncPipelineTable();
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM sync_pipeline WHERE user_id=:uid AND olt_row_id=:row
             AND status IN ('courier','queued','writing') LIMIT 1"
        );
        $this->executeInventoryWrite($stmt, ['uid'=>$userId, 'row'=>$oltRowId]);
        return $stmt->fetchColumn() !== false;
    }

    public function claimOltScanRequest(string $workerToken, bool $initialScan = false): ?array
    {
        $workerToken = substr(trim($workerToken), 0, 64);
        if ($workerToken === '') throw new InvalidArgumentException('Token worker tidak valid.');
        $this->ensureOltScanRequestsTable();
        $this->pdo->exec("DELETE s FROM olt_scan_requests s LEFT JOIN devices d ON d.id=s.olt_row_id AND d.user_id=s.user_id AND d.device_type='olt' WHERE d.id IS NULL");
        $this->recoverStaleOltScanRequests();
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $this->claimOltScanRequestOnce($workerToken, $initialScan);
            } catch (PDOException $error) {
                $code = (int)($error->errorInfo[1] ?? 0);
                if ($code === 1064 && $this->supportsSkipLocked !== false) {
                    $this->supportsSkipLocked = false;
                    continue;
                }
                if (!in_array($code, [1205, 1213], true) || $attempt >= 3) throw $error;
                usleep(150000 * $attempt);
            }
        }
    }

    private function claimOltScanRequestOnce(string $workerToken, bool $initialScan): ?array
    {
        $lock = $this->supportsSkipLocked === false ? 'FOR UPDATE' : 'FOR UPDATE SKIP LOCKED';
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->query(
                "SELECT id, user_id, olt_row_id, record_changes, requested_at, retry_middle_json FROM olt_scan_requests
                 WHERE initial_scan=" . ($initialScan ? '1' : '0') . "
                   AND (status='queued' OR (status='failed' AND (initial_scan=1 OR retry_middle_json IS NOT NULL)
                     AND finished_at <= DATE_SUB(NOW(), INTERVAL LEAST(600, 60 * GREATEST(1, attempt)) SECOND)))
                 ORDER BY requested_at ASC, id ASC
                 LIMIT 1 {$lock}"
            );
            $row = $stmt->fetch();
            if (!is_array($row)) {
                $this->pdo->commit();
                return null;
            }
            $this->pdo->prepare(
                "UPDATE olt_scan_requests
                 SET status='running', worker_token=:token, attempt=attempt+1, started_at=NOW(), finished_at=NULL, last_error=NULL,
                     phase_detail='Menghubungi OLT', processed_onus=0
                 WHERE id=:id"
            )->execute(['token' => $workerToken, 'id' => (int)$row['id']]);
            $this->pdo->commit();
            $row['worker_token'] = $workerToken;
            return $row;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    
    public function recoverStaleOltScanRequests(): void
    {
        $this->ensureOltScanRequestsTable();
        $this->pdo->exec(
            "UPDATE olt_scan_requests
             SET status='queued', worker_token=NULL, started_at=NULL,
                 last_error='Lease worker pemindaian kedaluwarsa.'
             WHERE status IN ('running','writing') AND started_at < DATE_SUB(NOW(), INTERVAL IF(initial_scan=1, 7200, 1800) SECOND)"
        );
    }

    public function markOltScanWriting(int $id, string $workerToken): void
    {
        $this->ensureOltScanRequestsTable();
        $this->pdo->prepare(
            "UPDATE olt_scan_requests SET status='writing', phase_detail='Menyimpan hasil pembacaan' WHERE id=:id AND status='running' AND worker_token=:token"
        )->execute(['id' => $id, 'token' => $workerToken]);
    }

    public function updateOltScanProgress(int $id, string $workerToken, string $phase, int $processedOnus = 0, int $totalOnus = 0): void
    {
        $this->ensureOltScanRequestsTable();
        $this->pdo->prepare(
            "UPDATE olt_scan_requests SET phase_detail=:phase, processed_onus=GREATEST(processed_onus,:count), total_onus=GREATEST(total_onus,:total)
             WHERE id=:id AND status='running' AND worker_token=:token"
        )->execute(['phase' => mb_substr($phase, 0, 120), 'count' => max(0, $processedOnus), 'total' => max(0, $totalOnus), 'id' => $id, 'token' => $workerToken]);
    }

    public function finishOltScanRequest(int $id, string $workerToken, bool $ok, string $error = '', bool $cancelled = false, array $retryMiddleRows = [], string $retryDriverId = ''): void
    {
        $this->ensureOltScanRequestsTable();
        $retryPayload = !$ok && !$cancelled && $retryMiddleRows !== [] && $retryDriverId !== ''
            ? json_encode(['driver_id'=>$retryDriverId, 'rows'=>array_values($retryMiddleRows)], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)
            : null;
        $this->pdo->prepare(
            "UPDATE olt_scan_requests
             SET status=:status, finished_at=NOW(), worker_token=NULL, last_error=:error, retry_middle_json=:retry
             WHERE id=:id AND status IN ('running','writing') AND worker_token=:token"
        )->execute([
            'status' => $cancelled ? 'cancelled' : ($ok ? 'done' : 'failed'),
            'error' => $error !== '' ? $error : null,
            'retry' => $retryPayload,
            'id' => $id,
            'token' => $workerToken,
        ]);
    }

    private function ensureSyncPipelineTable(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS sync_pipeline (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            olt_row_id INT UNSIGNED NOT NULL,
            olt_id INT UNSIGNED NOT NULL,
            handler VARCHAR(32) NOT NULL,
            driver_id VARCHAR(100) NOT NULL,
            payload LONGTEXT NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'queued',
            attempt INT UNSIGNED NOT NULL DEFAULT 0,
            worker_token VARCHAR(64) NULL,
            available_at DATETIME NOT NULL,
            read_at DATETIME NULL,
            written_at DATETIME NULL,
            last_error VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status_avail(status, available_at),
            INDEX idx_device_order(user_id, olt_row_id, id, status),
            INDEX idx_user(user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function getSyncTimingSettings(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT setting_key,setting_value FROM user_settings WHERE user_id=:uid AND setting_key IN ('sync_fast_interval_minutes','sync_discovery_interval_minutes','sync_middle_interval_minutes','sync_fast_deadline_seconds','sync_discovery_deadline_seconds','sync_middle_deadline_seconds','snmp_request_timeout_ms')");
        $stmt->execute(['uid' => $userId]);
        $values = [];
        foreach ($stmt->fetchAll() ?: [] as $row) $values[(string)$row['setting_key']] = (string)$row['setting_value'];
        $fastInterval = $values['sync_fast_interval_minutes'] ?? (string)self::DEFAULT_SYNC_FAST_INTERVAL_MINUTES;
        $discoveryInterval = $values['sync_discovery_interval_minutes'] ?? (string)self::DEFAULT_SYNC_DISCOVERY_INTERVAL_MINUTES;
        $middleInterval = $values['sync_middle_interval_minutes'] ?? (string)self::DEFAULT_SYNC_MIDDLE_INTERVAL_MINUTES;
        $fastDeadline = $values['sync_fast_deadline_seconds'] ?? (string)self::DEFAULT_SYNC_FAST_DEADLINE_SECONDS;
        $discoveryDeadline = $values['sync_discovery_deadline_seconds'] ?? (string)self::DEFAULT_SYNC_DISCOVERY_DEADLINE_SECONDS;
        $middleDeadline = $values['sync_middle_deadline_seconds'] ?? (string)self::DEFAULT_SYNC_MIDDLE_DEADLINE_SECONDS;
        $snmpTimeout = $values['snmp_request_timeout_ms'] ?? (string)self::DEFAULT_SNMP_REQUEST_TIMEOUT_MS;

        return [
            'fast_interval_minutes' => max(
                self::MIN_SYNC_FAST_INTERVAL_MINUTES,
                min(
                    self::MAX_SYNC_FAST_INTERVAL_MINUTES,
                    is_numeric($fastInterval) ? (int)$fastInterval : self::DEFAULT_SYNC_FAST_INTERVAL_MINUTES
                )
            ),
            'discovery_interval_minutes' => max(
                self::MIN_SYNC_DISCOVERY_INTERVAL_MINUTES,
                min(
                    self::MAX_SYNC_DISCOVERY_INTERVAL_MINUTES,
                    is_numeric($discoveryInterval) ? (int)$discoveryInterval : self::DEFAULT_SYNC_DISCOVERY_INTERVAL_MINUTES
                )
            ),
            'middle_interval_minutes' => max(
                self::MIN_SYNC_MIDDLE_INTERVAL_MINUTES,
                min(
                    self::MAX_SYNC_MIDDLE_INTERVAL_MINUTES,
                    is_numeric($middleInterval) ? (int)$middleInterval : self::DEFAULT_SYNC_MIDDLE_INTERVAL_MINUTES
                )
            ),
            'fast_deadline_seconds' => max(
                self::MIN_SYNC_DEADLINE_SECONDS,
                min(
                    self::MAX_SYNC_DEADLINE_SECONDS,
                    is_numeric($fastDeadline) ? (int)$fastDeadline : self::DEFAULT_SYNC_FAST_DEADLINE_SECONDS
                )
            ),
            'discovery_deadline_seconds' => max(
                self::MIN_SYNC_DEADLINE_SECONDS,
                min(
                    self::MAX_SYNC_DEADLINE_SECONDS,
                    is_numeric($discoveryDeadline) ? (int)$discoveryDeadline : self::DEFAULT_SYNC_DISCOVERY_DEADLINE_SECONDS
                )
            ),
            'middle_deadline_seconds' => max(
                self::MIN_SYNC_DEADLINE_SECONDS,
                min(
                    self::MAX_SYNC_DEADLINE_SECONDS,
                    is_numeric($middleDeadline) ? (int)$middleDeadline : self::DEFAULT_SYNC_MIDDLE_DEADLINE_SECONDS
                )
            ),
            'snmp_request_timeout_ms' => max(
                0,
                min(
                    self::MAX_SNMP_REQUEST_TIMEOUT_MS,
                    is_numeric($snmpTimeout) ? (int)$snmpTimeout : self::DEFAULT_SNMP_REQUEST_TIMEOUT_MS
                )
            ),
        ];
    }

    private function effectiveFastSyncIntervalSeconds(int $configuredMinutes): int
    {
        $minutes = $configuredMinutes > 0
            ? $configuredMinutes
            : self::DEFAULT_SYNC_FAST_INTERVAL_MINUTES;
        return max(60, min(86400, $minutes * 60));
    }

    private function applySyncTimingScheduleChange(int $userId, array $before, array $after): void
    {
        $beforeFast = (int)($before['fast_interval_minutes'] ?? self::DEFAULT_SYNC_FAST_INTERVAL_MINUTES);
        $afterFast = (int)($after['fast_interval_minutes'] ?? self::DEFAULT_SYNC_FAST_INTERVAL_MINUTES);
        if ($beforeFast !== $afterFast) {
            $deltaSeconds = $this->effectiveFastSyncIntervalSeconds($afterFast)
                - $this->effectiveFastSyncIntervalSeconds($beforeFast);
            if ($deltaSeconds !== 0) {
                $stmt = $this->pdo->prepare(
                    "UPDATE sync_jobs
                     SET available_at=GREATEST(NOW(),DATE_ADD(available_at,INTERVAL {$deltaSeconds} SECOND))
                     WHERE user_id=:uid AND status IN ('queued','done')"
                );
                $stmt->execute(['uid' => $userId]);
            }
        }

        $beforeDiscovery = (int)($before['discovery_interval_minutes'] ?? self::DEFAULT_SYNC_DISCOVERY_INTERVAL_MINUTES);
        $afterDiscovery = (int)($after['discovery_interval_minutes'] ?? self::DEFAULT_SYNC_DISCOVERY_INTERVAL_MINUTES);
        if ($afterDiscovery < $beforeDiscovery) {
            $stmt = $this->pdo->prepare(
                "UPDATE sync_jobs SET available_at=NOW()
                 WHERE user_id=:uid AND status IN ('queued','done')"
            );
            $stmt->execute(['uid' => $userId]);
        }
    }

    public function saveSyncTimingSettings(int $userId, array $settings): void
    {
        $fastInterval = (int)($settings['fast_interval_minutes'] ?? self::DEFAULT_SYNC_FAST_INTERVAL_MINUTES);
        $discoveryInterval = (int)($settings['discovery_interval_minutes'] ?? self::DEFAULT_SYNC_DISCOVERY_INTERVAL_MINUTES);
        $middleInterval = (int)($settings['middle_interval_minutes'] ?? self::DEFAULT_SYNC_MIDDLE_INTERVAL_MINUTES);
        $fastDeadline = (int)($settings['fast_deadline_seconds'] ?? self::DEFAULT_SYNC_FAST_DEADLINE_SECONDS);
        $discoveryDeadline = (int)($settings['discovery_deadline_seconds'] ?? self::DEFAULT_SYNC_DISCOVERY_DEADLINE_SECONDS);
        $middleDeadline = (int)($settings['middle_deadline_seconds'] ?? self::DEFAULT_SYNC_MIDDLE_DEADLINE_SECONDS);
        $snmpTimeout = (int)($settings['snmp_request_timeout_ms'] ?? self::DEFAULT_SNMP_REQUEST_TIMEOUT_MS);

        if ($fastInterval < self::MIN_SYNC_FAST_INTERVAL_MINUTES || $fastInterval > self::MAX_SYNC_FAST_INTERVAL_MINUTES) {
            throw new InvalidArgumentException('Interval Fast Sync harus antara ' . self::MIN_SYNC_FAST_INTERVAL_MINUTES . ' sampai ' . self::MAX_SYNC_FAST_INTERVAL_MINUTES . ' menit.');
        }
        if ($discoveryInterval < self::MIN_SYNC_DISCOVERY_INTERVAL_MINUTES || $discoveryInterval > self::MAX_SYNC_DISCOVERY_INTERVAL_MINUTES) {
            throw new InvalidArgumentException('Interval Low Sync harus antara ' . self::MIN_SYNC_DISCOVERY_INTERVAL_MINUTES . ' sampai ' . self::MAX_SYNC_DISCOVERY_INTERVAL_MINUTES . ' menit.');
        }
        if ($middleInterval < self::MIN_SYNC_MIDDLE_INTERVAL_MINUTES || $middleInterval > self::MAX_SYNC_MIDDLE_INTERVAL_MINUTES) {
            throw new InvalidArgumentException('Interval Middle Sync harus antara ' . self::MIN_SYNC_MIDDLE_INTERVAL_MINUTES . ' sampai ' . self::MAX_SYNC_MIDDLE_INTERVAL_MINUTES . ' menit.');
        }
        if ($fastDeadline < self::MIN_SYNC_DEADLINE_SECONDS || $fastDeadline > self::MAX_SYNC_DEADLINE_SECONDS) {
            throw new InvalidArgumentException('Batas durasi Fast Sync harus antara ' . self::MIN_SYNC_DEADLINE_SECONDS . ' sampai ' . self::MAX_SYNC_DEADLINE_SECONDS . ' detik.');
        }
        if ($discoveryDeadline < self::MIN_SYNC_DEADLINE_SECONDS || $discoveryDeadline > self::MAX_SYNC_DEADLINE_SECONDS) {
            throw new InvalidArgumentException('Batas durasi Low Sync harus antara ' . self::MIN_SYNC_DEADLINE_SECONDS . ' sampai ' . self::MAX_SYNC_DEADLINE_SECONDS . ' detik.');
        }
        if ($middleDeadline < self::MIN_SYNC_DEADLINE_SECONDS || $middleDeadline > self::MAX_SYNC_DEADLINE_SECONDS) {
            throw new InvalidArgumentException('Batas durasi Middle Sync harus antara ' . self::MIN_SYNC_DEADLINE_SECONDS . ' sampai ' . self::MAX_SYNC_DEADLINE_SECONDS . ' detik.');
        }
        if ($snmpTimeout < 0 || $snmpTimeout > self::MAX_SNMP_REQUEST_TIMEOUT_MS) {
            throw new InvalidArgumentException('Batas waktu SNMP harus antara 0 sampai ' . self::MAX_SNMP_REQUEST_TIMEOUT_MS . ' milidetik (0 = otomatis).');
        }

        $before = $this->getSyncTimingSettings($userId);
        foreach ([
            'sync_fast_interval_minutes' => $fastInterval,
            'sync_discovery_interval_minutes' => $discoveryInterval,
            'sync_middle_interval_minutes' => $middleInterval,
            'sync_fast_deadline_seconds' => $fastDeadline,
            'sync_discovery_deadline_seconds' => $discoveryDeadline,
            'sync_middle_deadline_seconds' => $middleDeadline,
            'snmp_request_timeout_ms' => $snmpTimeout,
        ] as $key => $value) $this->saveUserSetting($userId, $key, (string)$value);
        $this->applySyncTimingScheduleChange($userId, $before, $this->getSyncTimingSettings($userId));
    }

    public function saveSyncTimingSetting(int $userId, string $key, int $value): void
    {
        $definitions = [
            'fast_interval_minutes' => [
                self::MIN_SYNC_FAST_INTERVAL_MINUTES,
                self::MAX_SYNC_FAST_INTERVAL_MINUTES,
                'Interval Fast Sync',
                'menit',
            ],
            'discovery_interval_minutes' => [
                self::MIN_SYNC_DISCOVERY_INTERVAL_MINUTES,
                self::MAX_SYNC_DISCOVERY_INTERVAL_MINUTES,
                'Interval Low Sync',
                'menit',
            ],
            'middle_interval_minutes' => [
                self::MIN_SYNC_MIDDLE_INTERVAL_MINUTES,
                self::MAX_SYNC_MIDDLE_INTERVAL_MINUTES,
                'Interval Middle Sync',
                'menit',
            ],
            'fast_deadline_seconds' => [
                self::MIN_SYNC_DEADLINE_SECONDS,
                self::MAX_SYNC_DEADLINE_SECONDS,
                'Batas durasi Fast Sync',
                'detik',
            ],
            'discovery_deadline_seconds' => [
                self::MIN_SYNC_DEADLINE_SECONDS,
                self::MAX_SYNC_DEADLINE_SECONDS,
                'Batas durasi Low Sync',
                'detik',
            ],
            'middle_deadline_seconds' => [
                self::MIN_SYNC_DEADLINE_SECONDS,
                self::MAX_SYNC_DEADLINE_SECONDS,
                'Batas durasi Middle Sync',
                'detik',
            ],
            'snmp_request_timeout_ms' => [
                0,
                self::MAX_SNMP_REQUEST_TIMEOUT_MS,
                'Batas waktu SNMP',
                'milidetik',
            ],
        ];

        if (!isset($definitions[$key])) {
            throw new InvalidArgumentException('Pengaturan sinkronisasi tidak valid.');
        }

        [$minimum, $maximum, $label, $unit] = $definitions[$key];
        if ($value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException($label . ' harus antara ' . $minimum . ' sampai ' . $maximum . ' ' . $unit . '.');
        }

        $before = $this->getSyncTimingSettings($userId);
        if ((int)($before[$key] ?? $value) === $value) return;

        $settingKey = $key === 'snmp_request_timeout_ms' ? $key : 'sync_' . $key;
        $this->saveUserSetting($userId, $settingKey, (string)$value);
        $this->applySyncTimingScheduleChange($userId, $before, $this->getSyncTimingSettings($userId));
    }

    public function setLastSync(int $userId, string $status, string $message): void
    {
        $this->pdo->prepare('INSERT IGNORE INTO user_sync_states(user_id) VALUES (:id)')->execute(['id' => $userId]);
        $stmt = $this->pdo->prepare('UPDATE user_sync_states SET last_sync_at=NOW(),last_sync_status=:status,last_sync_message=:message WHERE user_id=:id');
        $this->executeInventoryWrite($stmt, ['status' => $status, 'message' => $message, 'id' => $userId]);
    }

    public function markMonitoringSync(
        int $userId,
        string $driverId
    ): void {
        $this->pdo->prepare(
            'INSERT IGNORE INTO user_sync_states(user_id) VALUES (:id)'
        )->execute(['id' => $userId]);

        $definition=monitoring_driver_definition($driverId);
        if (!is_array($definition)) {
            throw new InvalidArgumentException('Driver pemantauan tidak terdaftar: '.$driverId);
        }
        $method=(string)($definition['method']??'');
        if ($method === 'api') {
            $sql =
                "UPDATE user_sync_states
                 SET last_api_sync_at=NOW(),
                     last_sync_at=NOW()
                 WHERE user_id=:id";
        } else $sql="UPDATE user_sync_states SET last_sync_at=NOW() WHERE user_id=:id";

        $this->pdo->prepare($sql)->execute([
            'id' => $userId,
        ]);
    }

    public function enqueuePipelineSnapshot(int $userId, int $oltRowId, int $oltId, string $handler, string $driverId, array $snapshot): int
    {
        $payload = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        $status = trim((string)(getenv('LINNMS_WORKER_SLOT') ?: '')) !== '' ? 'courier' : 'queued';
        $stmt = $this->pdo->prepare(
            "INSERT INTO sync_pipeline(user_id, olt_row_id, olt_id, handler, driver_id, payload, status, available_at)
             SELECT :uid, :row, :oid, :handler, :driver, :payload, :status, NOW()
             FROM devices d WHERE d.id=:target_row AND d.user_id=:target_user AND d.source='snmp'"
        );
        $this->executeInventoryWrite($stmt, [
            'uid' => $userId,
            'row' => $oltRowId,
            'target_user' => $userId,
            'target_row' => $oltRowId,
            'oid' => $oltId,
            'handler' => $handler,
            'driver' => $driverId,
            'payload' => $payload,
            'status' => $status,
        ]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Perangkat sudah tidak terdaftar.');
        return (int)$this->pdo->lastInsertId();
    }

    public function dispatchCourierSnapshot(): bool
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->query(
                "SELECT id FROM sync_pipeline WHERE status='courier' ORDER BY id ASC LIMIT 1 FOR UPDATE"
            );
            $id = (int)($stmt->fetchColumn() ?: 0);
            if ($id < 1) {
                $this->pdo->commit();
                return false;
            }
            $this->pdo->prepare(
                "UPDATE sync_pipeline SET status='queued', available_at=NOW() WHERE id=:id AND status='courier'"
            )->execute(['id' => $id]);
            $this->pdo->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function markPipelineSeen(int $userId, int $oltRowId): void
    {
        $this->markDeviceSeen($oltRowId, $userId);
    }

    public function claimPipelineSnapshot(string $workerToken): ?array
    {
        $this->pdo->exec("DELETE p FROM sync_pipeline p LEFT JOIN devices d ON d.id=p.olt_row_id AND d.user_id=p.user_id AND d.source='snmp' WHERE d.id IS NULL");
        
        
        try {
            $this->pdo->prepare(
                "UPDATE sync_pipeline
                 SET status='queued', worker_token=NULL, available_at=NOW(),
                     last_error='Lease writer kedaluwarsa.'
                 WHERE status='writing' AND read_at < DATE_SUB(NOW(), INTERVAL 600 SECOND)"
            )->execute();
        } catch (Throwable) {
        }

        try {
            $this->dispatchCourierSnapshot();
        } catch (Throwable) {
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->query(
                "SELECT p.* FROM sync_pipeline p
                 WHERE p.status='queued' AND p.available_at<=NOW()
                   AND NOT EXISTS (
                     SELECT 1 FROM sync_pipeline earlier
                     WHERE earlier.user_id=p.user_id AND earlier.olt_row_id=p.olt_row_id
                       AND earlier.id<p.id AND earlier.status IN ('courier','queued','writing')
                   )
                 ORDER BY p.available_at ASC, p.id ASC
                 LIMIT 1 FOR UPDATE"
            );
            $row = $stmt->fetch();
            if (!is_array($row)) {
                $this->pdo->commit();
                return null;
            }
            $this->pdo->prepare(
                "UPDATE sync_pipeline SET status='writing', worker_token=:token, attempt=attempt+1, read_at=NOW() WHERE id=:id"
            )->execute(['token' => $workerToken, 'id' => (int)$row['id']]);
            $this->pdo->commit();
            $row['worker_token'] = $workerToken;
            return $row;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function finishPipelineSnapshot(int $id, bool $ok, string $error = '', bool $retryable = true): void
    {
        if ($ok) {
            $this->pdo->prepare(
                "DELETE FROM sync_pipeline WHERE id=:id AND status='writing'"
            )->execute(['id' => $id]);
            return;
        }
        $row = $this->pdo->prepare("SELECT attempt FROM sync_pipeline WHERE id=:id");
        $this->executeInventoryWrite($row, ['id' => $id]);
        $attempt = (int)$row->fetchColumn();
        $wait = min(900, 30 * (1 << min(5, max(0, $attempt - 1))));
        $this->pdo->prepare(
            "UPDATE sync_pipeline
             SET status=:status, last_error=:error, worker_token=NULL,
                 available_at=DATE_ADD(NOW(), INTERVAL :wait SECOND)
             WHERE id=:id AND status='writing'"
        )->execute([
            'status' => $retryable ? 'queued' : 'failed',
            'error' => mb_substr($error, 0, 500),
            'wait' => $wait,
            'id' => $id,
        ]);
    }

    public function pipelineStats(): array
    {
        $row = $this->pdo->query(
            "SELECT
                SUM(status='queued') queued,
                SUM(status='courier') courier,
                SUM(status='writing') writing,
                SUM(status='done') done,
                SUM(status='failed') failed
             FROM sync_pipeline"
        )->fetch();
        return is_array($row) ? $row : [];
    }

    public function syncOlts(int $userId, array $items, bool $recordChanges = true): array
    {
        $quotaLock = $this->acquireDeviceQuotaLock('olt');
        try {
        $events = [];
        $reportedOltIds = [];
        foreach ($items as $reportedItem) {
            if (!is_array($reportedItem)) continue;
            if ($this->isSnmpManagedApiItem($reportedItem)) continue;

            $reportedId = $this->positiveInt(
                $reportedItem['id'] ?? $reportedItem['olt_id'] ?? null
            );

            if ($reportedId !== null) {
                $reportedOltIds[] = $reportedId;
            }
        }

        $this->releaseIgnoredApiOltsThatDisappeared(
            $userId,
            $reportedOltIds
        );

        $ignored = array_fill_keys(
            $this->getIgnoredApiOltIds($userId),
            true
        );
        $existing = [];
        foreach ($this->getOlts($userId, false) as $row) {
            if ((string) ($row['source'] ?? 'api') === 'api') {
                $existing[(string) $row['olt_id']] = $row;
            }
        }
        $seen = [];
        $quota = $this->getUserOltQuota($userId);
        $quotaUsed = (int)$quota['used'];
        $quotaLimit = (int)$quota['limit'];
        $quotaUserUsed = (int)$quota['user_used'];
        $quotaUserLimit = (int)$quota['user_limit'];

        $written = 0;
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            if ($this->isSnmpManagedApiItem($item)) continue;
            $oltId = $this->positiveInt($item['id'] ?? $item['olt_id'] ?? null);
            if ($oltId === null) continue;
            if (isset($ignored[$oltId])) continue;
            $old = $existing[(string) $oltId] ?? null;
            $needsQuotaSlot = $old === null;
            if ($needsQuotaSlot && (($quotaLimit > 0 && $quotaUsed >= $quotaLimit) || ($quotaUserLimit > 0 && $quotaUserUsed >= $quotaUserLimit))) continue;
            if ($needsQuotaSlot) {
                $quotaUsed++;
                $quotaUserUsed++;
            }
            $seen[] = $oltId;
            $reportedSnmpPort = $this->positiveInt($item['port'] ?? $item['snmp_port'] ?? null);
            if ($reportedSnmpPort !== null && $reportedSnmpPort > 65535) $reportedSnmpPort = null;
            $new = [
                'name' => $this->nullText($item['name'] ?? $item['olt_name'] ?? null),
                'ip_address' => $this->nullText($item['ip_address'] ?? null),
                'snmp_port' => $reportedSnmpPort ?? (is_array($old) ? (int)($old['snmp_port'] ?? 0) : 0),
                'olt_type' => $this->nullText($item['olt_type'] ?? $item['type'] ?? null),
                'total_onus' => $this->nullableInt($item['total_onus'] ?? null),
            ];
            if ($recordChanges && $old === null) {
                $events[] = $this->insertChangeEvent($userId,'OLT',(string)$oltId,'is_present',null,'1','OLT baru ditemukan: ' . display_text($new['name']) . ' (' . $oltId . ')');
            }
            if ($recordChanges && is_array($old)) {
                $confirmedPresence = $this->confirmScannedStatus(
                    $userId,
                    'OLT',
                    'api|' . $oltId,
                    'is_present',
                    $old['is_present'] ?? 1,
                    1
                );
                foreach (['name','ip_address','snmp_port','olt_type','total_onus'] as $field) {
                    if ($this->different($old[$field] ?? null, $new[$field] ?? null)) {
                        $events[] = $this->insertChangeEvent($userId, 'OLT', (string) $oltId, $field, $old[$field] ?? null, $new[$field] ?? null,
                            'OLT ' . display_text($new['name'] ?? $old['name'] ?? null) . ' (' . $oltId . '): ' . event_field_label($field) . ' berubah ' . display_text($old[$field] ?? null) . ' ➠ ' . display_text($new[$field] ?? null));
                    }
                }
                if ($confirmedPresence !== null) {
                    $this->resetApiOltFirstScanApproval(
                        $userId,
                        $oltId
                    );

                    $events[] = $this->insertChangeEvent(
                        $userId,
                        'OLT',
                        (string)$oltId,
                        'is_present',
                        $confirmedPresence['old'],
                        $confirmedPresence['new'],
                        'OLT ' . display_text($new['name']) .
                            ' kembali online'
                    );
                }
            }

            $stmt = $this->pdo->prepare("INSERT INTO devices(user_id,device_type,type,source,olt_id,name,ip_address,snmp_port)
                VALUES (:uid,'olt',:type,'api',:oid,:name,:ip,:snmp_port)
                ON DUPLICATE KEY UPDATE type=VALUES(type),name=VALUES(name),ip_address=VALUES(ip_address),snmp_port=VALUES(snmp_port)");
            $this->executeInventoryWrite($stmt, ['uid'=>$userId,'oid'=>$oltId,'name'=>$new['name'],'ip'=>$new['ip_address'],'snmp_port'=>$new['snmp_port'],'type'=>$new['olt_type']]);
            
            $apiDeviceId = (int)$this->pdo->lastInsertId();
            if ($apiDeviceId < 1) {
                $findApiDevice = $this->pdo->prepare("SELECT id FROM devices WHERE user_id=:uid AND device_type='olt' AND source='api' AND olt_id=:oid LIMIT 1");
                $this->executeInventoryWrite($findApiDevice, ['uid'=>$userId,'oid'=>$oltId]);
                $apiDeviceId = (int)$findApiDevice->fetchColumn();
            }
            if ($apiDeviceId > 0) {
                $this->markDeviceOnline($apiDeviceId, $userId, null, $new['total_onus']);
                $this->saveDeviceSystem($apiDeviceId, $userId, [
                    'raw_json' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                ]);
                
                $apiModel = olt_type_label($new['olt_type']);
                if ($apiModel !== '-') $this->saveDeviceModel($apiDeviceId, $userId, $apiModel);
            }
            $this->paceDatabaseWrites(++$written);
        }

        foreach ($existing as $key => $old) {
            $oid = (int) $old['olt_id'];
            if ((int) ($old['is_present'] ?? 1) === 1 && !in_array($oid, $seen, true)) {
                $confirmedMissing = $recordChanges
                    ? $this->confirmScannedStatus(
                        $userId,
                        'OLT',
                        'api|' . $oid,
                        'is_present',
                        $old['is_present'] ?? 1,
                        0
                    )
                    : null;
                if ($confirmedMissing !== null) {
                    foreach ($this->deviceIdsForScope($userId, [
                        'device_type' => 'olt',
                        'source' => 'api',
                        'olt_id' => $oid,
                    ]) as $deviceId) {
                        $this->markDeviceOffline($deviceId, $userId);
                    }
                    $events[] = $this->insertChangeEvent($userId,'OLT',(string)$oid,'is_present',$confirmedMissing['old'],$confirmedMissing['new'],'OLT ' . display_text($old['name'] ?? null) . ' Offline');
                }
            }
        }
        return $events;
        } finally {
            $this->releaseDeviceQuotaLock($quotaLock);
        }
    }

    public function syncOnusForOlt(int $userId, int $oltId, array $items, float $signalThreshold = 1.0, bool $recordChanges = true): array
    {
        $events = [];
        $oltDeviceId = $this->oltDeviceId($userId, 'api', $oltId);
        $rxProblemThreshold = $this->getOnuProblemSettings($userId)['rx_power_threshold_dbm'];
        $existing = [];
        foreach ($this->getOnus($userId, $oltId, false, 'api') as $row) $existing[(string) $row['onu_index']] = $row;
        $seen = [];
        $written = 0;

        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $idx = trim((string) ($item['onu_index'] ?? ''));
            if ($idx === '') continue;
            $rxRejected = false;
            $item = onu_strip_rejected_rx($item, $rxRejected);
            $seen[] = $idx;
            $rxPower = onu_rx_power_number($item['rx_power'] ?? null);
            $new = [
                'onu_type' => $this->nullText($item['onu_type'] ?? null),
                'onu_serial' => $this->nullText($item['onu_serial'] ?? null),
                'onu_name' => $this->syncedOnuNameFromItem($item),
                'onu_state' => $this->normalizeOnuState($item['onu_state'] ?? null, $rxPower, $item),
                'rx_power' => $rxPower,
                'tx_power' => $this->nullableFloat($item['tx_power'] ?? null),
                'temperature' => $this->nullableFloat($item['temperature'] ?? null),
                'distance' => $this->nullableFloat($item['distance'] ?? null),
                'last_online' => $this->nullText($item['last_online'] ?? null),
                'last_checked' => $this->nullText($item['last_checked'] ?? null),
            ];
            $persistedState = $new['onu_state'];
            $old = $existing[$idx] ?? null;
            if (is_array($old) && onu_rx_power_rejected($old['rx_power'] ?? null)) {
                $this->clearRejectedOnuRx((int)$old['id'], $userId);
            }
            $idxPonNumber = 0;
            if (preg_match('~(\d+)/[^/]*$~', (string)$idx, $im) === 1) { $idxPonNumber = (int)$im[1]; }
            if (is_array($old)) {
                $oldName = $this->nullText($old['onu_name'] ?? null);
                if ($new['onu_name'] === null) {
                    $new['onu_name'] = $oldName;
                }
            }
            if ($recordChanges) {
                $confirmedFound = $this->confirmScannedStatusTracker(
                    $userId, 'ONU', 'api|' . $oltId . '|' . $idx, 'is_present',
                    '0', state_key($new['onu_state']) === 'online' ? '1' : '0', 2
                );
                if ($confirmedFound !== null && $confirmedFound['new'] === '1') {
                    $events[] = $this->insertChangeEvent($userId,'ONU','api|' . $oltId . '|' . $idx,'is_present',$confirmedFound['old'],$confirmedFound['new'],'ONU baru ditemukan: ' . display_onu_name($new['onu_name']) . " [OLT {$oltId} / {$idx}].",
                        ['source'=>'api','olt_id'=>$oltId,'onu_index'=>$idx,'onu_name'=>$new['onu_name'],'onu_serial'=>$new['onu_serial']]);
                }
            }
            if ($recordChanges && $old === null) {
                $this->confirmScannedStatus(
                    $userId, 'ONU', 'api|' . $oltId . '|' . $idx, 'onu_state',
                    $new['onu_state'], $new['onu_state']
                );
            }
            if ($recordChanges && is_array($old)) {
                $persistedState = $old['onu_state'] ?? $new['onu_state'];
                $confirmedState = $this->confirmScannedStatus(
                    $userId,
                    'ONU',
                    'api|' . $oltId . '|' . $idx,
                    'onu_state',
                    $old['onu_state'] ?? null,
                    $new['onu_state']
                );
                if ($confirmedState !== null) {
                    $persistedState = $confirmedState['new'];
                    $events[] = $this->insertChangeEvent($userId,'ONU','api|' . $oltId . '|' . $idx,'onu_state',$confirmedState['old'],$confirmedState['new'],
                        'ONU ' . display_onu_name($new['onu_name'] ?? $old['onu_name'] ?? null) . " [OLT {$oltId} / {$idx}] status: " . state_label($confirmedState['old']) . ' -> ' . state_label($confirmedState['new']),
                        ['source'=>'api','olt_id'=>$oltId,'onu_index'=>$idx,'onu_name'=>$new['onu_name'] ?? $old['onu_name'] ?? null]);
                }
                $confirmedPower = $this->confirmRxPowerChange(
                    $userId,
                    'api|' . $oltId . '|' . $idx,
                    onu_rx_power_number($old['rx_power'] ?? null),
                    $new['rx_power'],
                    $signalThreshold
                );
                if ($confirmedPower !== null && $new['rx_power'] !== null && $new['rx_power'] > -$rxProblemThreshold) {
                    $events[] = $this->insertChangeEvent($userId,'ONU','api|' . $oltId . '|' . $idx,'rx_power',$confirmedPower['old'],$confirmedPower['new'],
                        'ONU ' . display_onu_name($new['onu_name'] ?? $old['onu_name'] ?? null) . " [OLT {$oltId} / {$idx}] " . event_field_label('rx_power') . ': ' . format_power($confirmedPower['old']) . ' -> ' . format_power($confirmedPower['new']),
                        ['source'=>'api','olt_id'=>$oltId,'onu_index'=>$idx,'onu_name'=>$new['onu_name'] ?? $old['onu_name'] ?? null]);
                }
                foreach (['onu_name','onu_type','onu_serial','temperature','distance'] as $field) {
                    if ($this->different($old[$field] ?? null, $new[$field] ?? null)) {
                        $events[] = $this->insertChangeEvent($userId,'ONU','api|' . $oltId . '|' . $idx,$field,$old[$field] ?? null,$new[$field],
                            'ONU ' . display_onu_name($new['onu_name'] ?? $old['onu_name'] ?? null) . " [OLT {$oltId} / {$idx}] " . event_field_label($field) . ': '
                                . ($field === 'onu_name' ? display_onu_name($old[$field] ?? null) : display_text($old[$field] ?? null))
                                . ' -> '
                                . ($field === 'onu_name' ? display_onu_name($new[$field] ?? null) : display_text($new[$field])),
                            ['source'=>'api','olt_id'=>$oltId,'onu_index'=>$idx,'onu_name'=>$new['onu_name'] ?? $old['onu_name'] ?? null]);
                    }
                }
            }
            if ($recordChanges) {
                $confirmedProblem = $this->confirmRxProblemState(
                    $userId,
                    'api|' . $oltId . '|' . $idx,
                    $new['rx_power'],
                    $rxProblemThreshold
                );
                if ($confirmedProblem !== null && $confirmedProblem['new'] === 'problem' && $new['rx_power'] !== null) {
                    $events[] = $this->insertChangeEvent(
                        $userId, 'ONU', 'api|' . $oltId . '|' . $idx,
                        'problem_rx_power', -$rxProblemThreshold, $new['rx_power'],
                        'ONU ' . display_onu_name($new['onu_name'] ?? (is_array($old) ? ($old['onu_name'] ?? null) : null))
                            . " [OLT {$oltId} / {$idx}] bermasalah karena RX Power " . format_power($new['rx_power']) . '.',
                        ['source'=>'api','olt_id'=>$oltId,'onu_index'=>$idx,'onu_name'=>$new['onu_name'] ?? (is_array($old) ? ($old['onu_name'] ?? null) : null)]
                    );
                }
                if ($confirmedProblem !== null && $confirmedProblem['new'] === 'normal' && $new['rx_power'] !== null) {
                    $events[] = $this->insertChangeEvent(
                        $userId, 'ONU', 'api|' . $oltId . '|' . $idx,
                        'problem_rx_recovered', -$rxProblemThreshold, $new['rx_power'],
                        'ONU ' . display_onu_name($new['onu_name'] ?? (is_array($old) ? ($old['onu_name'] ?? null) : null))
                            . " [OLT {$oltId} / {$idx}] RX Power kembali normal (" . format_power($new['rx_power']) . ').',
                        ['source'=>'api','olt_id'=>$oltId,'onu_index'=>$idx,'onu_name'=>$new['onu_name'] ?? (is_array($old) ? ($old['onu_name'] ?? null) : null)]
                    );
                }
            }

            $ponIdByNumberApi = [];
            $ponMapStmtApi = $this->pdo->prepare('SELECT id,pon_number FROM pons WHERE user_id=:uid AND olt_device_id=:dev');
            $this->executeInventoryWrite($ponMapStmtApi, ['uid' => $userId, 'dev' => $oltDeviceId]);
            foreach ($ponMapStmtApi->fetchAll() ?: [] as $p) { $ponIdByNumberApi[(int)$p['pon_number']] = (int)$p['id']; }
            $stmt = $this->pdo->prepare("INSERT INTO onus(user_id,olt_device_id,pon_id,onu_index,onu_type,onu_serial,onu_name,onu_state,rx_power,is_present,missing_at)
                VALUES (:uid,:olt_device_id,:pon_id,:idx,:type,:serial,:name,:state,:rx,1,0)
                ON DUPLICATE KEY UPDATE pon_id=COALESCE(VALUES(pon_id),pon_id),onu_type=VALUES(onu_type),onu_serial=COALESCE(VALUES(onu_serial),onu_serial),onu_name=VALUES(onu_name),onu_state=VALUES(onu_state),rx_power=COALESCE(VALUES(rx_power),rx_power),is_present=1,missing_at=0");
            $updateExistingApi = $this->pdo->prepare(
                "UPDATE onus SET pon_id=COALESCE(:pon_id,pon_id),onu_type=:type,onu_serial=:serial,onu_name=:name,onu_state=:state,rx_power=COALESCE(:rx,rx_power),is_present=1,missing_at=0 WHERE id=:row_id AND user_id=:uid AND olt_device_id=:olt_device_id"
            );
            $values = [
                'uid'=>$userId,'olt_device_id'=>$oltDeviceId,'idx'=>$idx,
                'pon_id'=>(int)(($ponIdByNumberApi[(int)($idxPonNumber ?? 0)] ?? 0)) ?: null,
                'type'=>$new['onu_type'],'serial'=>$new['onu_serial'],'name'=>$new['onu_name'],'state'=>$persistedState,'rx'=>$new['rx_power']
            ];
            if (is_array($old)) {
                $updateValues = $values;
                unset($updateValues['idx']);
                $updateValues['row_id'] = (int)$old['id'];
                $this->executeInventoryWrite($updateExistingApi, $updateValues);
                if ($updateExistingApi->rowCount() === 0) $this->executeInventoryWrite($stmt, $values);
            } else {
                $this->executeInventoryWrite($stmt, $values);
            }
            $onuRowId = is_array($old) ? (int)$old['id'] : (int)$this->pdo->lastInsertId();
            if ($onuRowId > 0) {
                $this->mergeOnuJson($onuRowId, $userId, 'detail_json', [
                    'tx_power' => $new['tx_power'],
                    'temperature' => $new['temperature'],
                    'distance' => $new['distance'],
                    'device_response' => $item,
                ]);
            }
            $this->paceDatabaseWrites(++$written);
        }

        foreach ($existing as $idx => $old) {
            if (!in_array($idx, $seen, true)) {
                $confirmedMissing = $recordChanges
                    ? $this->confirmScannedStatus(
                        $userId,
                        'ONU',
                        'api|' . $oltId . '|' . $idx,
                        'onu_state',
                        $old['onu_state'] ?? null,
                        'Lost'
                    )
                    : null;
                if ((int)($old['missing_at'] ?? 0) + 1 >= $this->getOnuMissingScanLimit()) {
                    $this->pdo->prepare('DELETE FROM onus WHERE id=:id AND user_id=:uid AND olt_device_id=:olt_device_id')
                        ->execute(['id'=>(int)$old['id'],'uid'=>$userId,'olt_device_id'=>$oltDeviceId]);
                } else {
                    $this->pdo->prepare("UPDATE onus SET is_present=0,onu_state='Lost',missing_at=COALESCE(missing_at,0)+1 WHERE id=:id AND user_id=:uid AND olt_device_id=:olt_device_id")
                        ->execute(['id'=>(int)$old['id'],'uid'=>$userId,'olt_device_id'=>$oltDeviceId]);
                }
                if ($confirmedMissing !== null && (int)($old['is_present'] ?? 1) === 1) {
                    $events[] = $this->insertChangeEvent($userId,'ONU','api|' . $oltId . '|' . $idx,'onu_state',$confirmedMissing['old'],$confirmedMissing['new'],'ONU ' . display_onu_name($old['onu_name'] ?? null) . " [OLT {$oltId} / {$idx}] lost.",
                        ['source'=>'api','olt_id'=>$oltId,'onu_index'=>$idx,'onu_name'=>$old['onu_name'] ?? null]);
                }
            }
        }
        return $events;
    }

    public function enqueueScheduledSyncJobs(int $intervalSeconds = 60, int $leaseSeconds = 900): int
    {
        $intervalSeconds = max(60, min(86400, $intervalSeconds));
        $leaseSeconds = max(120, min(3600, $leaseSeconds));

        $recover = $this->pdo->prepare(
            "UPDATE sync_jobs SET status='queued',worker_token=NULL,locked_at=NULL,available_at=NOW(),last_error='Lease worker kedaluwarsa.'
             WHERE status='running' AND locked_at < DATE_SUB(NOW(), INTERVAL {$leaseSeconds} SECOND)"
        );

        $sql = "INSERT INTO sync_jobs(user_id,status,available_at)
                SELECT u.id,'queued',NOW()
                FROM users u
                WHERE u.is_active=1
                ON DUPLICATE KEY UPDATE
                    worker_token=IF(sync_jobs.status IN ('pending','done','failed') AND sync_jobs.available_at<=NOW(),NULL,sync_jobs.worker_token),
                    locked_at=IF(sync_jobs.status IN ('pending','done','failed') AND sync_jobs.available_at<=NOW(),NULL,sync_jobs.locked_at),
                    status=IF(sync_jobs.status IN ('pending','done','failed') AND sync_jobs.available_at<=NOW(),'queued',sync_jobs.status)";

        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $this->executeInventoryWrite($recover);
                $affected = $this->pdo->exec($sql);
                return $affected === false ? 0 : (int)$affected;
            } catch (PDOException $error) {
                $code = (int)($error->errorInfo[1] ?? 0);
                if (!in_array($code, [1205, 1213], true) || $attempt >= 3) throw $error;
                usleep(150000 * $attempt);
            }
        }
    }

    public function claimScheduledSyncJob(string $workerToken): ?array
    {
        $workerToken = substr(trim($workerToken), 0, 64);
        if ($workerToken === '') throw new InvalidArgumentException('Token worker tidak valid.');

        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $this->claimScheduledSyncJobOnce($workerToken);
            } catch (PDOException $error) {
                $code = (int)($error->errorInfo[1] ?? 0);
                if ($code === 1064 && $this->supportsSkipLocked !== false) {
                    $this->supportsSkipLocked = false;
                    continue;
                }
                if (!in_array($code, [1205, 1213], true) || $attempt >= 3) throw $error;
                usleep(150000 * $attempt);
            }
        }
    }

    private function claimScheduledSyncJobOnce(string $workerToken): ?array
    {
        $lock = $this->supportsSkipLocked === false ? 'FOR UPDATE' : 'FOR UPDATE SKIP LOCKED';
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->query(
                "SELECT j.id,j.user_id,j.attempts,u.username,u.display_name
                 FROM sync_jobs j
                 INNER JOIN users u ON u.id=j.user_id AND u.is_active=1
                 WHERE j.status='queued' AND j.available_at<=NOW()
                 ORDER BY j.available_at ASC,j.id ASC
                 LIMIT 1 {$lock}"
            );
            $job = $stmt->fetch();
            if (!is_array($job)) {
                $this->pdo->commit();
                return null;
            }

            $update = $this->pdo->prepare(
                "UPDATE sync_jobs SET status='running',locked_at=NOW(),worker_token=:token,attempts=attempts+1,last_error=NULL WHERE id=:id"
            );
            $this->executeInventoryWrite($update, ['token' => $workerToken, 'id' => (int)$job['id']]);
            $this->pdo->commit();
            $job['worker_token'] = $workerToken;
            return $job;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function finishScheduledSyncJob(int $userId, int $jobId, string $workerToken, bool $success, string $error = ''): void
    {
        $intervalSeconds = $this->effectiveFastSyncIntervalSeconds(
            (int)$this->getSyncTimingSettings($userId)['fast_interval_minutes']
        );
        $retrySeconds = max(60, min(900, $intervalSeconds * 2));
        $delaySeconds = $success ? $intervalSeconds : $retrySeconds;
        $stmt = $this->pdo->prepare(
            "UPDATE sync_jobs SET
                status=:status,
                available_at=DATE_ADD(NOW(), INTERVAL {$delaySeconds} SECOND),
                locked_at=NULL,
                worker_token=NULL,
                attempts=IF(:success=1,0,attempts),
                last_error=:error
             WHERE id=:id AND user_id=:uid AND status='running' AND worker_token=:token"
        );
        $stmt->bindValue('status', $success ? 'done' : 'failed');
        $stmt->bindValue('success', $success ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue('error', $error !== '' ? mb_substr($error, 0, 2000) : null);
        $stmt->bindValue('id', $jobId, PDO::PARAM_INT);
        $stmt->bindValue('uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue('token', $workerToken);

        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $this->executeInventoryWrite($stmt);
                return;
            } catch (PDOException $error) {
                $code = (int)($error->errorInfo[1] ?? 0);
                if (!in_array($code, [1205, 1213], true) || $attempt >= 3) throw $error;
                usleep(150000 * $attempt);
            }
        }
    }

    public function countQueuedScheduledSyncJobs(): int
    {
        return max(0, (int)$this->pdo->query("SELECT COUNT(*) FROM sync_jobs WHERE status='queued' AND available_at<=NOW()")->fetchColumn());
    }

    public function scheduledSyncQueueLoad(): array
    {
        $row = $this->pdo->query(
            "SELECT COUNT(DISTINCT j.user_id) AS users, COUNT(d.id) AS devices
             FROM sync_jobs j LEFT JOIN devices d ON d.user_id=j.user_id
             WHERE j.status='queued' AND j.available_at<=NOW()"
        )->fetch(PDO::FETCH_ASSOC);
        return [
            'users' => max(0, (int)($row['users'] ?? 0)),
            'devices' => max(0, (int)($row['devices'] ?? 0)),
        ];
    }

    public function touchScheduledSyncJob(int $jobId, string $workerToken): void
    {
        $this->pdo->prepare(
            "UPDATE sync_jobs SET locked_at=NOW() WHERE id=:id AND status='running' AND worker_token=:token"
        )->execute(['id' => $jobId, 'token' => $workerToken]);
    }

    
    private function workerTokenPid(string $token): int
    {
        if ($token === '') return 0;
        $parts = explode('-', trim($token));
        if (count($parts) < 2) return 0;
        $pid = (int)($parts[count($parts) - 2] ?? 0);
        return $pid > 0 && $pid < 100000 ? $pid : 0;
    }

    
    public function recoverDeadSyncWorkers(): void
    {
        foreach ($this->pdo->query("SELECT id, worker_token FROM sync_jobs WHERE status='running'")->fetchAll() ?: [] as $job) {
            $pid = $this->workerTokenPid((string)($job['worker_token'] ?? ''));
            if ($pid < 1 || file_exists('/proc/' . $pid)) continue;
            $this->pdo->prepare(
                "UPDATE sync_jobs
                 SET status='queued', worker_token=NULL, locked_at=NULL,
                     attempts=attempts+1,
                     last_error='Worker pemilik job sudah tidak aktif; job dikembalikan ke antrean.'
                 WHERE id=:id AND status='running'"
            )->execute(['id' => (int)$job['id']]);
        }
    }

    
    public function recoverDeadOltScanWorkers(): void
    {
        $this->ensureOltScanRequestsTable();
        foreach ($this->pdo->query("SELECT id, worker_token FROM olt_scan_requests WHERE status IN ('running','writing')")->fetchAll() ?: [] as $request) {
            $pid = $this->workerTokenPid((string)($request['worker_token'] ?? ''));
            if ($pid < 1 || file_exists('/proc/' . $pid)) continue;
            $this->pdo->prepare(
                "UPDATE olt_scan_requests
                 SET status='queued', worker_token=NULL, started_at=NULL, attempt=attempt+1,
                     last_error='Worker pemilik pemindaian sudah tidak aktif; kembali ke antrean.'
                 WHERE id=:id AND status IN ('running','writing')"
            )->execute(['id' => (int)$request['id']]);
        }
    }
}
