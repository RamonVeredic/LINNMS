<?php

declare(strict_types=1);


trait LinnmsDeviceStoreTrait
{
    public function getUserDriverCounts(int $userId): array
    {
        if ($userId < 1) return [];
        $stmt = $this->pdo->prepare(
            'SELECT type, COUNT(*) AS total FROM devices WHERE user_id=:uid AND type IS NOT NULL AND type<>"" GROUP BY type'
        );
        $stmt->execute(['uid' => $userId]);
        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $driverId = trim((string)($row['type'] ?? ''));
            if ($driverId !== '') $counts[$driverId] = (int)($row['total'] ?? 0);
        }
        return $counts;
    }

    
    private function deviceCompanionColumns(): array
    {
        return [
            'credentials' => ['hybrid_status', 'ports', 'username', 'password_enc'],
            'system' => ['system_json', 'system_seen_at', 'interfaces_json', 'raw_json', 'model'],
            'scan' => [
                'first_scan_approval_requested_at',
                'first_scan_approved_at',
                'first_scan_approved_by',
                'first_scan_started_at',
            ],
            'status' => array_merge(
                ['driver_status', 'driver_error', 'total_onus', 'is_present', 'seen_at'],
                $this->deviceStatusHasConfigChangedColumn() ? ['config_changed_at'] : [],
                $this->deviceStatusHasConnectionColumns()
                    ? ['cli_status', 'cli_seen_at', 'cli_fail_count', 'snmp_fail_count']
                    : []
            ),
        ];
    }

    
    private function deviceCompanionTables(): array
    {
        return [
            'credentials' => 'device_credentials',
            'system' => 'device_systems',
            'scan' => 'device_scan_state',
            'status' => 'device_status',
        ];
    }

    
    public function saveDeviceCompanion(int $deviceId, int $userId, string $group, array $data): void
    {
        if ($deviceId < 1 || $userId < 1) return;

        $tables = $this->deviceCompanionTables();
        $columns = $this->deviceCompanionColumns();
        if (!isset($tables[$group], $columns[$group])) {
            throw new InvalidArgumentException('Kelompok kolom pendamping tidak dikenal: ' . $group);
        }

        $values = [];
        foreach ($columns[$group] as $column) {
            if (array_key_exists($column, $data)) $values[$column] = $data[$column];
        }
        if ($values === []) return;

        $names = array_keys($values);
        $placeholders = [];
        $updates = [];
        $params = ['device_id' => $deviceId, 'user_id' => $userId];
        foreach ($names as $column) {
            $placeholders[] = ':' . $column;
            $updates[] = $column . '=VALUES(' . $column . ')';
            $params[$column] = $values[$column];
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . $tables[$group] . '(device_id,user_id,' . implode(',', $names) . ')'
            . ' VALUES(:device_id,:user_id,' . implode(',', $placeholders) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . implode(',', $updates)
        );
        $stmt->execute($params);

        
        
        if ($group === 'system' && isset($values['system_json']) && is_string($values['system_json'])) {
            $this->saveDeviceModelFromSystemJson($deviceId, $userId, $values['system_json']);
        }
    }

    
    public function saveDeviceCommunity(int $deviceId, int $userId, string $community): void
    {
        if ($deviceId < 1 || $userId < 1) return;
        $stmt = $this->pdo->prepare('UPDATE devices SET snmp_community=:community WHERE id=:id AND user_id=:uid');
        $stmt->execute(['community'=>$community, 'id'=>$deviceId, 'uid'=>$userId]);
    }

    public function saveDeviceCredentials(int $deviceId, int $userId, array $data): void
    {
        if ($deviceId < 1 || $userId < 1) return;
        $deviceStmt = $this->pdo->prepare('SELECT device_type,source,type FROM devices WHERE id=:id AND user_id=:uid LIMIT 1');
        $deviceStmt->execute(['id'=>$deviceId, 'uid'=>$userId]);
        $device = $deviceStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($device)) return;
        $definition = monitoring_driver_definition((string)($device['type'] ?? ''));
        if (($device['device_type'] ?? '') !== 'olt' || ($device['source'] ?? '') !== 'snmp'
            || !is_array($definition) || empty($definition['telnet_cli'])) {
            $delete = $this->pdo->prepare('DELETE FROM device_credentials WHERE device_id=:id AND user_id=:uid');
            $delete->execute(['id'=>$deviceId, 'uid'=>$userId]);
            return;
        }
        $hybridStatus = (string)($data['hybrid_status'] ?? '');
        if (!in_array($hybridStatus, ['0', '1'], true)) return;
        $data['hybrid_status'] = (int)$hybridStatus;
        if ($hybridStatus === '0') {
            $data['ports'] = null;
        } elseif (array_key_exists('ports', $data)) {
            $data['ports'] = max(1, min(65535, (int)$data['ports']));
        }
        $this->saveDeviceCompanion($deviceId, $userId, 'credentials', $data);
        if ($hybridStatus === '0' && $this->deviceStatusHasConnectionColumns()) {
            $clear = $this->pdo->prepare("UPDATE device_status SET cli_status='',cli_seen_at=NULL WHERE device_id=:id AND user_id=:uid");
            $clear->execute(['id' => $deviceId, 'uid' => $userId]);
        }
    }

    
    public function saveDeviceSystem(int $deviceId, int $userId, array $data): void
    {
        $this->saveDeviceCompanion($deviceId, $userId, 'system', $data);
    }

    
    public function saveDeviceScanState(int $deviceId, int $userId, array $data): void
    {
        $this->saveDeviceCompanion($deviceId, $userId, 'scan', $data);
    }

    
    public function saveDeviceStatus(int $deviceId, int $userId, array $data): void
    {
        $this->saveDeviceCompanion($deviceId, $userId, 'status', $data);
    }

    
    public function touchDeviceFirstScanStarted(int $deviceId, int $userId): void
    {
        if ($deviceId < 1 || $userId < 1) return;
        $stmt = $this->pdo->prepare(
            "INSERT INTO device_scan_state(device_id,user_id,first_scan_started_at)
             VALUES(:device_id,:user_id,NOW())
             ON DUPLICATE KEY UPDATE
                first_scan_started_at=COALESCE(first_scan_started_at,VALUES(first_scan_started_at))"
        );
        $stmt->execute(['device_id' => $deviceId, 'user_id' => $userId]);
    }

    
    public function deviceIdsForScope(int $userId, array $scope): array
    {
        if ($userId < 1) return [];
        $sql = 'SELECT id FROM devices WHERE user_id=:uid';
        $params = ['uid' => $userId];
        foreach (['device_type', 'source'] as $column) {
            if (!array_key_exists($column, $scope)) continue;
            $sql .= " AND {$column}=:" . $column;
            $params[$column] = (string)$scope[$column];
        }
        foreach (['olt_id', 'id'] as $column) {
            if (!array_key_exists($column, $scope)) continue;
            $sql .= " AND {$column}=:" . $column;
            $params[$column] = (int)$scope[$column];
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $ids = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) $ids[] = $id;
        }
        return $ids;
    }

    
    public function markDeviceScanApprovalRequested(int $deviceId, int $userId): void
    {
        if ($deviceId < 1 || $userId < 1) return;
        $stmt = $this->pdo->prepare(
            "INSERT INTO device_scan_state(device_id,user_id,first_scan_approval_requested_at)
             VALUES(:device_id,:user_id,NOW())
             ON DUPLICATE KEY UPDATE
                first_scan_approval_requested_at=COALESCE(first_scan_approval_requested_at,NOW())"
        );
        $stmt->execute(['device_id' => $deviceId, 'user_id' => $userId]);
    }

    
    public function markDeviceScanApproved(int $deviceId, int $userId, ?int $approvedBy = null): void
    {
        if ($deviceId < 1 || $userId < 1) return;
        $stmt = $this->pdo->prepare(
            "INSERT INTO device_scan_state(device_id,user_id,first_scan_approved_at,first_scan_approved_by)
             VALUES(:device_id,:user_id,NOW(),:approved_by)
             ON DUPLICATE KEY UPDATE
                first_scan_approved_at=COALESCE(first_scan_approved_at,NOW()),
                first_scan_approved_by=VALUES(first_scan_approved_by)"
        );
        $stmt->execute(['device_id' => $deviceId, 'user_id' => $userId, 'approved_by' => $approvedBy]);
    }

    
    public function resetDeviceScanState(int $deviceId, int $userId): void
    {
        $this->saveDeviceScanState($deviceId, $userId, [
            'first_scan_approval_requested_at' => null,
            'first_scan_approved_at' => null,
            'first_scan_approved_by' => null,
            'first_scan_started_at' => null,
        ]);
    }

    
    public function saveDeviceSystemSnapshot(int $deviceId, int $userId, string $json): void
    {
        if ($deviceId < 1 || $userId < 1) return;
        $stmt = $this->pdo->prepare(
            "INSERT INTO device_systems(device_id,user_id,system_json,system_seen_at)
             VALUES(:device_id,:user_id,:system_json,NOW())
             ON DUPLICATE KEY UPDATE system_json=VALUES(system_json),system_seen_at=NOW()"
        );
        $stmt->execute(['device_id' => $deviceId, 'user_id' => $userId, 'system_json' => $json]);
        $this->saveDeviceModelFromSystemJson($deviceId, $userId, $json);
    }

    
    public function saveDeviceModel(int $deviceId, int $userId, string $model): void
    {
        if ($deviceId < 1 || $userId < 1) return;
        $model = mb_substr(trim($model), 0, 191);
        if ($model === '') return;
        $this->saveDeviceCompanion($deviceId, $userId, 'system', ['model' => $model]);
    }
    private function saveDeviceModelFromSystemJson(int $deviceId, int $userId, string $json): void
    {
        if ($deviceId < 1 || $userId < 1 || trim($json) === '') return;
        $system = json_decode($json, true);
        if (!is_array($system)) return;
        $this->saveDeviceModel($deviceId, $userId, (string)($system['model'] ?? ''));
    }
    public function markDeviceOnline(int $deviceId, int $userId, ?string $status = null, ?int $totalOnus = null): void
    {
        if ($deviceId < 1 || $userId < 1) return;

        $columns = 'device_id,user_id,is_present,seen_at';
        $values = ':device_id,:user_id,1,NOW()';
        $updates = 'is_present=1,seen_at=NOW()';
        $params = ['device_id' => $deviceId, 'user_id' => $userId];

        if ($status !== null) {
            $columns .= ',driver_status,driver_error';
            $values .= ',:driver_status,NULL';
            $updates .= ",driver_status=VALUES(driver_status),driver_error=NULL";
            $params['driver_status'] = mb_substr($status, 0, 30);
        }
        if ($totalOnus !== null) {
            $columns .= ',total_onus';
            $values .= ',:total_onus';
            $updates .= ',total_onus=VALUES(total_onus)';
            $params['total_onus'] = $totalOnus;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO device_status(' . $columns . ') VALUES(' . $values . ')'
            . ' ON DUPLICATE KEY UPDATE ' . $updates
        );
        $stmt->execute($params);
    }

    
    public function markDeviceSeen(int $deviceId, int $userId, ?int $totalOnus = null): void
    {
        $this->markDeviceOnline($deviceId, $userId, null, $totalOnus);
    }

    
    private function deviceStatusHasConfigChangedColumn(): bool
    {
        static $known = null;
        static $checkedAt = 0;
        if ($known !== null && (time() - $checkedAt) < 60) return $known;
        $checkedAt = time();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND COLUMN_NAME=:column'
            );
            $stmt->execute(['table' => 'device_status', 'column' => 'config_changed_at']);
            $known = (int)$stmt->fetchColumn() > 0;
        } catch (Throwable) {
            $known = false;
        }

        return $known;
    }

    
    private function deviceStatusHasConnectionColumns(): bool
    {
        static $known = null;
        static $checkedAt = 0;
        if ($known !== null && (time() - $checkedAt) < 60) return $known;
        $checkedAt = time();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND COLUMN_NAME=:column'
            );
            $stmt->execute(['table' => 'device_status', 'column' => 'cli_status']);
            $known = (int)$stmt->fetchColumn() > 0;
        } catch (Throwable) {
            $known = false;
        }

        return $known;
    }

    
    public function markDeviceConnectionStatus(int $deviceId, int $userId, string $kind, string $status): void
    {
        if ($deviceId < 1 || $userId < 1 || $kind !== 'cli') return;
        if (!$this->deviceStatusHasConnectionColumns()) return;

        $column = 'cli_status';
        $seenColumn = 'cli_seen_at';
        $status = mb_substr($status, 0, 16);
        $stmt = $this->pdo->prepare(
            "INSERT INTO device_status(device_id,user_id,{$column},{$seenColumn})
             VALUES (:id,:uid,:st,NOW())
             ON DUPLICATE KEY UPDATE {$column}=:st2, {$seenColumn}=NOW()"
        );
        $stmt->execute(['id' => $deviceId, 'uid' => $userId, 'st' => $status, 'st2' => $status]);
    }

    
    public function markDeviceConfigChanged(int $userId, int $deviceId): void
    {
        if (!$this->deviceStatusHasConfigChangedColumn()) return;
        $this->saveDeviceCompanion($deviceId, $userId, 'status', [
            'config_changed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    
    public function clearDeviceConfigChanged(int $userId, int $deviceId): void
    {
        if (!$this->deviceStatusHasConfigChangedColumn()) return;
        $this->saveDeviceCompanion($deviceId, $userId, 'status', ['config_changed_at' => null]);
    }

    
    public function getDeviceConfigChangeState(int $userId, int $deviceId): array
    {
        if (!$this->deviceStatusHasConfigChangedColumn()) {
            return ['pending' => false, 'changed_at' => null, 'seen_at' => null];
        }
        $stmt = $this->pdo->prepare(
            'SELECT st.config_changed_at,st.seen_at FROM devices d'
            . ' LEFT JOIN device_status st ON st.device_id=d.id'
            . ' WHERE d.user_id=:uid AND d.id=:id LIMIT 1'
        );
        $stmt->execute(['uid' => $userId, 'id' => $deviceId]);
        $row = $stmt->fetch() ?: [];
        $changedAt = trim((string)($row['config_changed_at'] ?? ''));
        $seenAt = trim((string)($row['seen_at'] ?? ''));

        return [
            'pending' => $changedAt !== '',
            'changed_at' => $changedAt !== '' ? $changedAt : null,
            'seen_at' => $seenAt !== '' ? $seenAt : null,
        ];
    }

    
    
    public function recordConnectionProbe(int $deviceId, int $userId, string $kind, bool $ok, string $reason = ''): void
    {
        if ($deviceId < 1 || $userId < 1) return;
        $kind = $kind === 'snmp' ? 'snmp' : 'cli';
        if (!$this->deviceStatusHasConnectionColumns()) return;

        $statusCol = $kind === 'snmp' ? 'driver_status' : 'cli_status';
        $seenCol = $kind === 'snmp' ? 'seen_at' : 'cli_seen_at';
        $countCol = $kind . '_fail_count';
        $loggedCol = $kind . '_down_logged';

        $stmt = $this->pdo->prepare(
            "SELECT d.name,st.{$statusCol} AS st,st.{$countCol} AS cnt,st.{$loggedCol} AS logged
             FROM devices d LEFT JOIN device_status st ON st.device_id=d.id
             WHERE d.id=:id AND d.user_id=:uid LIMIT 1"
        );
        $stmt->execute(['id' => $deviceId, 'uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') $name = 'OLT #' . $deviceId;
        $prev = strtoupper(trim((string)($row['st'] ?? '')));
        $count = (int)($row['cnt'] ?? 0);
        $logged = (int)($row['logged'] ?? 0);
        $label = $kind === 'snmp' ? 'SNMP' : 'Telnet';
        $reasonText = trim($reason) !== '' ? trim($reason) : 'time out';

        $newStatus = $prev;
        $newCount = $count;
        $newLogged = $logged;
        $clearError = false;

        if ($ok) {
            $newStatus = 'OK';
            $newCount = 0;
            $newLogged = 0;
            $clearError = true;
            if ($kind !== 'snmp' && ($prev === 'DOWN' || $logged === 1)) {
                $this->logApplication($userId, 'device', 'device_status', 'info',
                    sprintf('%s, %s, online.', $name, $label));
            }
        } else {
            $newCount = $count + 1;
            if ($newCount >= 3) {
                $newStatus = 'DOWN';
                if ($logged === 0) {
                    $newLogged = 1;
                    if ($kind !== 'snmp') {
                        $this->logApplication($userId, 'device', 'device_status', 'info',
                            sprintf('%s, %s, offline.', $name, $label));
                    }
                } else {
                    $newLogged = 1;
                }
            } else {
                
                $newStatus = 'WARN';
            }
        }

        $errorSql = $clearError && $kind === 'snmp'
            ? ',driver_error=NULL'
            : ($newStatus === 'DOWN' && $kind === 'snmp' ? ',driver_error=:err' : '');
        $sql = "INSERT INTO device_status(device_id,user_id,{$statusCol},{$countCol},{$loggedCol},{$seenCol})
                VALUES(:id,:uid,:st,:cnt,:lg,NOW())
                ON DUPLICATE KEY UPDATE {$statusCol}=VALUES({$statusCol}),{$countCol}=VALUES({$countCol}),
                    {$loggedCol}=VALUES({$loggedCol}),{$seenCol}=NOW(){$errorSql}";
        $params = ['id' => $deviceId, 'uid' => $userId, 'st' => $newStatus, 'cnt' => $newCount, 'lg' => $newLogged];
        if (!$clearError && $newStatus === 'DOWN' && $kind === 'snmp') $params['err'] = mb_substr($reasonText, 0, 2000);
        $this->pdo->prepare($sql)->execute($params);
    }

    public function markDeviceOffline(int $deviceId, int $userId): void
    {
        if ($deviceId < 1 || $userId < 1) return;
        $stmt = $this->pdo->prepare(
            "INSERT INTO device_status(device_id,user_id,is_present) VALUES(:device_id,:user_id,0)
             ON DUPLICATE KEY UPDATE is_present=0"
        );
        $stmt->execute(['device_id' => $deviceId, 'user_id' => $userId]);
    }

    
    public function markDeviceSyncFailure(int $deviceId, int $userId, string $status, string $error): void
    {
        if ($deviceId < 1 || $userId < 1) return;
        $stmt = $this->pdo->prepare(
            "INSERT INTO device_status(device_id,user_id,driver_status,driver_error)
             VALUES(:device_id,:user_id,:driver_status,:driver_error)
             ON DUPLICATE KEY UPDATE driver_status=VALUES(driver_status),driver_error=VALUES(driver_error)"
        );
        $stmt->execute([
            'device_id' => $deviceId,
            'user_id' => $userId,
            'driver_status' => mb_substr($status, 0, 30),
            'driver_error' => mb_substr($error, 0, 2000),
        ]);
    }

    
    public function saveDeviceTotalOnus(int $deviceId, int $userId, int $totalOnus): void
    {
        if ($deviceId < 1 || $userId < 1) return;
        $stmt = $this->pdo->prepare(
            "INSERT INTO device_status(device_id,user_id,total_onus) VALUES(:device_id,:user_id,:total_onus)
             ON DUPLICATE KEY UPDATE total_onus=VALUES(total_onus)"
        );
        $stmt->execute(['device_id' => $deviceId, 'user_id' => $userId, 'total_onus' => $totalOnus]);
    }

    
    public function ensureDeviceStatusRow(int $deviceId, int $userId): void
    {
        if ($deviceId < 1 || $userId < 1) return;
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO device_status(device_id,user_id,is_present) VALUES(:device_id,:user_id,1)'
        );
        $stmt->execute(['device_id' => $deviceId, 'user_id' => $userId]);
    }

    
    public function hydrateDeviceRows(array $rows, ?array $groups = null, bool $includeInactiveCredentials = false): array
    {
        if ($rows === []) return $rows;

        $tables = $this->deviceCompanionTables();
        $columns = $this->deviceCompanionColumns();
        $wanted = $groups ?? array_keys($tables);

        $indexes = [];
        foreach ($rows as $index => $row) {
            $deviceId = (int)($row['id'] ?? 0);
            if ($deviceId > 0) $indexes[$deviceId][] = $index;
        }
        if ($indexes === []) return $rows;

        foreach ($wanted as $group) {
            if (!isset($tables[$group], $columns[$group])) continue;

            if ($group === 'credentials') {
                $credentialIndexes = [];
                foreach ($indexes as $deviceId => $rowIndexes) {
                    foreach ($rowIndexes as $index) {
                        if (($rows[$index]['device_type'] ?? '') === 'olt' && ($rows[$index]['source'] ?? '') === 'snmp') {
                            $credentialIndexes[$deviceId] = $rowIndexes;
                            break;
                        }
                    }
                }
                if ($credentialIndexes === []) continue;
                $credentialIds = array_keys($credentialIndexes);
                $credentialPlaceholders = implode(',', array_fill(0, count($credentialIds), '?'));
                $statusStmt = $this->pdo->prepare('SELECT device_id,hybrid_status FROM device_credentials WHERE device_id IN (' . $credentialPlaceholders . ')' . ($includeInactiveCredentials ? '' : ' AND hybrid_status=1'));
                $statusStmt->execute($credentialIds);
                $activeIds = [];
                foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $credential) {
                    $deviceId = (int)$credential['device_id'];
                    $active = (int)$credential['hybrid_status'] === 1;
                    if ($active || $includeInactiveCredentials) $activeIds[] = $deviceId;
                    foreach ($credentialIndexes[$deviceId] ?? [] as $index) {
                        $rows[$index]['hybrid_status'] = $active ? 1 : 0;
                    }
                }
                if ($activeIds !== []) {
                    $detailPlaceholders = implode(',', array_fill(0, count($activeIds), '?'));
                    $detailStmt = $this->pdo->prepare('SELECT device_id,ports,username,password_enc FROM device_credentials WHERE device_id IN (' . $detailPlaceholders . ')' . ($includeInactiveCredentials ? '' : ' AND hybrid_status=1'));
                    $detailStmt->execute($activeIds);
                    foreach ($detailStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $credential) {
                        foreach ($credentialIndexes[(int)$credential['device_id']] ?? [] as $index) {
                            foreach (['ports', 'username', 'password_enc'] as $column) {
                                $rows[$index][$column] = $credential[$column] ?? null;
                            }
                        }
                    }
                }
                continue;
            }

            $placeholders = [];
            $params = [];
            $position = 0;
            foreach (array_keys($indexes) as $deviceId) {
                $name = 'dev' . $position;
                $placeholders[] = ':' . $name;
                $params[$name] = $deviceId;
                $position++;
            }

            $stmt = $this->pdo->prepare(
                'SELECT device_id,' . implode(',', $columns[$group])
                . ' FROM ' . $tables[$group]
                . ' WHERE device_id IN (' . implode(',', $placeholders) . ')'
            );
            $stmt->execute($params);
            $companions = $stmt->fetchAll() ?: [];

            foreach ($companions as $companion) {
                $deviceId = (int)($companion['device_id'] ?? 0);
                foreach ($indexes[$deviceId] ?? [] as $index) {
                    foreach ($columns[$group] as $column) {
                        $rows[$index][$column] = $companion[$column] ?? null;
                    }
                }
            }
        }

        if (in_array('credentials', $wanted, true)) {
            foreach ($rows as &$row) {
                $row['hybrid_status'] = (int)($row['hybrid_status'] ?? 0);
                $credentialDetailsAvailable = $row['hybrid_status'] === 1 || $includeInactiveCredentials;
                $row['ports'] = $credentialDetailsAvailable
                    ? max(1, min(65535, (int)($row['ports'] ?? 23))) : null;
                $row['username'] = $credentialDetailsAvailable ? ($row['username'] ?? null) : null;
                $row['password_enc'] = $credentialDetailsAvailable ? ($row['password_enc'] ?? null) : null;
            }
            unset($row);
        }

        return $rows;
    }

    
    public function hydrateDeviceRow(?array $row, ?array $groups = null, bool $includeInactiveCredentials = false): ?array
    {
        if (!is_array($row)) return null;
        $hydrated = $this->hydrateDeviceRows([$row], $groups, $includeInactiveCredentials);
        return $hydrated[0] ?? $row;
    }
}
