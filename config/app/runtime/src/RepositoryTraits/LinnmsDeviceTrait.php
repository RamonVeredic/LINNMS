<?php

declare(strict_types=1);

trait LinnmsDeviceTrait
{
    public function getDeviceConnectionStatuses(int $userId, array $ids): array
    {
        if ($userId < 1 || $ids === []) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare('SELECT s.device_id,s.seen_at,s.driver_status FROM device_status s JOIN devices d ON d.id=s.device_id AND d.user_id=s.user_id WHERE s.user_id=? AND s.device_id IN (' . $placeholders . ')');
        $stmt->execute(array_merge([$userId], $ids));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getSnmpDriverSupportFlags(): array
    {
        $stmt=$this->pdo->query("SELECT setting_key,setting_value FROM app_settings WHERE setting_key LIKE 'snmp_support_driver_%'");
        $out=[];foreach($stmt->fetchAll()?:[] as $row)$out[(string)$row['setting_key']]=(string)$row['setting_value'];return $out;
    }

    public function saveSnmpDriverSupportFlags(array $flags): void
    {
        $stmt=$this->pdo->prepare("INSERT INTO app_settings(setting_key,setting_value) VALUES (:key,:value) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        $this->pdo->beginTransaction();
        try{foreach(snmp_driver_catalog() as $deviceKey=>$_definition)$stmt->execute(['key'=>snmp_driver_support_setting_key((string)$deviceKey),'value'=>!empty($flags[$deviceKey])?'1':'0']);$this->pdo->commit();}
        catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function getSnmpDevices(int $userId, ?string $deviceType = null): array
    {
        $sql = "SELECT * FROM devices WHERE user_id=:uid AND device_type<>'olt' AND source='snmp'";
        $params = ['uid' => $userId];
        if ($deviceType !== null && $deviceType !== '') {
            $sql .= ' AND device_type=:device_type';
            $params['device_type'] = $deviceType;
        }
        $sql .= ' ORDER BY name,id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $this->hydrateDeviceRows($stmt->fetchAll() ?: []);
    }

    public function getSnmpDevice(int $userId, int $rowId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM devices WHERE user_id=:uid AND id=:id AND device_type<>'olt' AND source='snmp' LIMIT 1");
        $stmt->execute(['uid' => $userId, 'id' => $rowId]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrateDeviceRow($row) : null;
    }

    
    public function getEditableDevice(int $userId, int $rowId): ?array
    {
        if ($userId < 1 || $rowId < 1) return null;
        $stmt = $this->pdo->prepare('SELECT * FROM devices WHERE user_id=:uid AND id=:id LIMIT 1');
        $stmt->execute(['uid' => $userId, 'id' => $rowId]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrateDeviceRow($row, null, true) : null;
    }

    public function getOwnedDeviceIdentity(int $userId, int $rowId): ?array
    {
        if ($userId < 1 || $rowId < 1) return null;
        $stmt = $this->pdo->prepare('SELECT id,device_type,source FROM devices WHERE user_id=:uid AND id=:id LIMIT 1');
        $stmt->execute(['uid' => $userId, 'id' => $rowId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    
    public function updateDeviceConnection(int $userId, int $rowId, array $data): bool
    {
        if ($userId < 1 || $rowId < 1 || $data === []) return false;

        $values = [];
        foreach (['type', 'name', 'ip_address', 'snmp_port'] as $column) {
            if (!array_key_exists($column, $data)) continue;
            $values[$column] = $data[$column];
        }
        if ($values === []) return false;

        $owned = $this->pdo->prepare('SELECT id FROM devices WHERE user_id=:uid AND id=:id LIMIT 1');
        $owned->execute(['uid' => $userId, 'id' => $rowId]);
        if ((int)($owned->fetchColumn() ?: 0) < 1) return false;

        $assignments = [];
        $params = ['uid' => $userId, 'id' => $rowId];
        foreach ($values as $column => $value) {
            $assignments[] = $column . '=:' . $column;
            $params[$column] = $value;
        }
        $stmt = $this->pdo->prepare('UPDATE devices SET ' . implode(',', $assignments) . ' WHERE user_id=:uid AND id=:id');
        $stmt->execute($params);
        return true;
    }

    public function createSnmpDevice(int $userId, string $driverId, string $deviceType, string $name, string $ipAddress, int $snmpPort, string $community, array $system, array $interfaces): int
    {
        $isMikrotik = $deviceType === 'mikrotik';
        if (!$isMikrotik && $deviceType !== 'cisco_catalyst_2960s') throw new InvalidArgumentException('Jenis perangkat tidak valid.');
        $label = $isMikrotik ? 'MikroTik' : 'switch';

        $quotaLock = $this->acquireDeviceQuotaLock($deviceType);
        try {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM users WHERE id=:uid FOR UPDATE');
            $lock->execute(['uid'=>$userId]);
            if ($lock->fetchColumn() === false) throw new RuntimeException('Akun tidak ditemukan.');
            $quota = $this->getUserSnmpDeviceQuota($userId, $deviceType);
            if (empty($quota['can_add'])) throw new RuntimeException("Batas {$label} telah tercapai.");

            $stmt = $this->pdo->prepare(
                "INSERT INTO devices(user_id,device_type,type,source,name,ip_address,snmp_port)
                 VALUES(:uid,:device_type,:driver_id,'snmp',:name,:ip_address,:snmp_port)"
            );
            $stmt->execute([
                'uid'=>$userId,'device_type'=>$deviceType,'driver_id'=>$driverId,'name'=>$name,'ip_address'=>$ipAddress,'snmp_port'=>$snmpPort,
            ]);
            $id = (int)$this->pdo->lastInsertId();
            $this->ensureNotificationDeviceTarget($userId, $id);
            
            $this->saveDeviceCommunity($id, $userId, $community);
            $this->markDeviceOnline($id, $userId, 'online');
            $this->saveDeviceSystem($id, $userId, [
                'system_json'=>json_encode($system,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),
                'interfaces_json'=>json_encode($interfaces,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        } finally {
            $this->releaseDeviceQuotaLock($quotaLock);
        }
    }

    public function updateSnmpDeviceSnapshot(int $userId, int $rowId, array $system, array $interfaces): array
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare("SELECT d.device_type,d.name,st.driver_status FROM devices d LEFT JOIN device_status st ON st.device_id=d.id WHERE d.user_id=:uid AND d.id=:id AND d.device_type<>'olt' AND d.source='snmp' FOR UPDATE");
            $lock->execute(['uid'=>$userId,'id'=>$rowId]);
            $device = $lock->fetch();
            if (!is_array($device)) throw new RuntimeException('Perangkat tidak ditemukan.');

            $interfacesStmt = $this->pdo->prepare('SELECT interfaces_json FROM device_systems WHERE device_id=:id');
            $interfacesStmt->execute(['id' => $rowId]);
            $oldInterfaces = json_decode((string)($interfacesStmt->fetchColumn() ?: ''), true);
            if (!is_array($oldInterfaces)) $oldInterfaces = [];
            [$storedInterfaces, $changes] = $this->reconcileSnmpDeviceInterfaces(
                array_values(array_filter($oldInterfaces, 'is_array')),
                $interfaces,
                (string)($device['device_type'] ?? ''),
                (string)($device['name'] ?? ''),
                $rowId,
            );

            $events = [];
            $deviceEntityKey = $this->snmpDeviceEntityKey((string)($device['device_type'] ?? ''), $rowId);
            $confirmedDeviceRecovery = $this->confirmScannedStatus(
                $userId,
                'DEVICE',
                $deviceEntityKey,
                'driver_status',
                $device['driver_status'] ?? 'online',
                'online'
            );
            if ($confirmedDeviceRecovery !== null) {
                $deviceLabel = (string)($device['device_type'] ?? '') === 'mikrotik' ? 'MikroTik' : 'Switch';
                $events[] = $this->insertChangeEvent(
                    $userId,
                    'DEVICE',
                    $deviceEntityKey,
                    'driver_status',
                    $confirmedDeviceRecovery['old'],
                    $confirmedDeviceRecovery['new'],
                    $deviceLabel . ' ' . display_text($device['name'] ?? null) . ' kembali online.',
                    ['source'=>'snmp']
                );
            }
            foreach ($changes as $change) {
                $confirmedInterface = $this->confirmScannedStatus(
                    $userId,
                    'INTERFACE',
                    (string)$change['entity_key'],
                    'oper_status',
                    $change['old_value'],
                    $change['new_value']
                );
                if ($confirmedInterface === null) continue;
                $events[] = $this->insertChangeEvent(
                    $userId,
                    'INTERFACE',
                    (string)$change['entity_key'],
                    'oper_status',
                    $confirmedInterface['old'],
                    $confirmedInterface['new'],
                    (string)$change['label'] . ' berubah dari '
                        . state_label($confirmedInterface['old']) . ' menjadi '
                        . state_label($confirmedInterface['new']) . '.',
                    ['source'=>'snmp'],
                );
            }

            $this->markDeviceOnline($rowId, $userId, 'online');
            $this->saveDeviceSystem($rowId, $userId, [
                'system_json' => json_encode($system, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                'interfaces_json' => json_encode($storedInterfaces, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
            $this->pdo->commit();
            return ['interfaces'=>$storedInterfaces,'events'=>$events];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function reconcileSnmpDeviceInterfaces(array $oldInterfaces, array $newInterfaces, string $deviceType, string $deviceName, int $rowId): array
    {
        $oldByIdentity = [];
        foreach ($oldInterfaces as $interface) {
            $identity = $this->snmpInterfaceIdentity($interface);
            if ($identity !== '') $oldByIdentity[$identity] = $interface;
        }

        $stored = [];
        $changes = [];
        foreach ($newInterfaces as $interface) {
            if (!is_array($interface)) continue;
            $identity = $this->snmpInterfaceIdentity($interface);
            if ($identity === '') continue;
            $old = $oldByIdentity[$identity] ?? null;
            $merged = is_array($old) ? array_replace($old, $interface) : $interface;
            $stored[] = $merged;
            unset($oldByIdentity[$identity]);

            if (!is_array($old)) continue;
            $oldState = $this->snmpInterfaceState($old['oper_status'] ?? null);
            $newState = $this->snmpInterfaceState($merged['oper_status'] ?? null);
            if ($oldState !== null && $newState !== null) {
                $changes[] = $this->snmpInterfaceChange($identity, $oldState, $newState, $merged, $deviceType, $deviceName, $rowId);
            }
        }

        foreach ($oldByIdentity as $identity => $old) {
            if (!$this->isPppoeInterface($old)) continue;
            $oldState = $this->snmpInterfaceState($old['oper_status'] ?? null);
            $retained = $old;

            $retained['if_index'] = 0;
            $retained['oper_status'] = 'down';
            $stored[] = $retained;
            if ($oldState !== null) $changes[] = $this->snmpInterfaceChange($identity, $oldState, 'Down', $retained, $deviceType, $deviceName, $rowId);
        }

        return [array_values($stored), array_values($changes)];
    }

    private function snmpInterfaceIdentity(array $interface): string
    {
        $name = trim((string)($interface['name'] ?? ''));
        if ($this->isPppoeInterface($interface) && $name !== '') return 'pppoe:' . mb_strtolower($name);
        $ifIndex = (int)($interface['if_index'] ?? 0);
        if ($ifIndex > 0) return 'ifindex:' . $ifIndex;
        return $name !== '' ? 'ifname:' . mb_strtolower($name) : '';
    }

    private function isPppoeInterface(array $interface): bool
    {
        return strtolower(trim((string)($interface['interface_type'] ?? ''))) === 'pppoe'
            || str_contains(strtolower((string)($interface['name'] ?? '')), 'pppoe');
    }

    private function snmpInterfaceState(mixed $value): ?string
    {
        return match (strtolower(trim((string)$value))) {
            'up', 'online' => 'Up',
            'down', 'offline', 'testing', 'dormant', 'notpresent', 'lowerlayerdown' => 'Down',
            default => null,
        };
    }

    private function snmpDeviceEntityKey(string $deviceType, int $rowId): string
    {
        return ($deviceType === 'mikrotik' ? 'mikrotik' : 'switch') . '|' . $rowId;
    }

    private function snmpInterfaceChange(string $identity, string $oldState, string $newState, array $interface, string $deviceType, string $deviceName, int $rowId): array
    {
        $name = trim((string)($interface['name'] ?? ''));
        $isPppoe = $this->isPppoeInterface($interface);
        if ($isPppoe) {
            $name = trim($name, "<> \t\n\r\0\x0B");
            $name = preg_replace('/^pppoe[\s_-]*/i', '', $name) ?? $name;
        }
        $name = $name !== '' ? $name : 'Tanpa nama';
        $deviceLabel = $deviceType === 'mikrotik' ? 'MikroTik' : 'Switch';
        $subjectLabel = $isPppoe ? 'Pelanggan PPPoE' : 'Interface';
        $entityKey = mb_substr($this->snmpDeviceEntityKey($deviceType, $rowId) . '|' . $identity, 0, 300);
        return [
            'entity_key' => $entityKey,
            'old_value' => $oldState,
            'new_value' => $newState,
            'label' => "{$subjectLabel} {$name} pada {$deviceLabel} {$deviceName}",
            'message' => "{$subjectLabel} {$name} pada {$deviceLabel} {$deviceName} berubah dari {$oldState} menjadi {$newState}.",
        ];
    }

    public function markSnmpDeviceError(int $userId, int $rowId, string $message): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare(
                "SELECT d.device_type,d.name,st.driver_status FROM devices d LEFT JOIN device_status st ON st.device_id=d.id WHERE d.user_id=:uid AND d.id=:id AND d.device_type<>'olt' AND d.source='snmp' FOR UPDATE"
            );
            $lock->execute(['uid' => $userId, 'id' => $rowId]);
            $device = $lock->fetch();
            if (!is_array($device)) {
                $this->pdo->commit();
                return null;
            }

            $event = null;
            $deviceEntityKey = $this->snmpDeviceEntityKey((string)($device['device_type'] ?? ''), $rowId);
            $confirmedFailure = $this->confirmScannedStatus(
                $userId,
                'DEVICE',
                $deviceEntityKey,
                'driver_status',
                $device['driver_status'] ?? 'online',
                'error'
            );
            if ($confirmedFailure !== null) {
                $deviceLabel = (string)($device['device_type'] ?? '') === 'mikrotik' ? 'MikroTik' : 'Switch';
                $event = $this->insertChangeEvent(
                    $userId,
                    'DEVICE',
                    $deviceEntityKey,
                    'driver_status',
                    $confirmedFailure['old'],
                    $confirmedFailure['new'],
                    $deviceLabel . ' ' . display_text($device['name'] ?? null) . ' down dan tidak dapat dijangkau.',
                    ['source'=>'snmp']
                );
            }

            $this->markDeviceSyncFailure($rowId, $userId, 'error', $message);
            $this->pdo->commit();
            return $event;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function deleteSnmpDevice(int $userId, int $rowId): bool
    {
        $this->pdo->beginTransaction();
        try {
            $find = $this->pdo->prepare("SELECT device_type FROM devices WHERE user_id=:uid AND id=:id AND device_type<>'olt' AND source='snmp' FOR UPDATE");
            $find->execute(['uid' => $userId, 'id' => $rowId]);
            $deviceType = $find->fetchColumn();
            if ($deviceType === false) {
                $this->pdo->commit();
                return false;
            }

            $entityKey = $this->snmpDeviceEntityKey((string)$deviceType, $rowId);
            $prefix = $entityKey . '|';
            $params = ['uid' => $userId, 'key' => $entityKey, 'prefix' => $prefix, 'prefix_length' => strlen($prefix)];
            $this->pdo->prepare('DELETE FROM change_events WHERE user_id=:uid AND (entity_key=:key OR LEFT(entity_key,:prefix_length)=:prefix)')->execute($params);
            $this->pdo->prepare('DELETE FROM sync_entitas WHERE user_id=:uid AND (entity_key=:key OR LEFT(entity_key,:prefix_length)=:prefix)')->execute($params);
            $this->pdo->prepare('DELETE FROM sync_pipeline WHERE user_id=:uid AND olt_row_id=:id')->execute(['uid' => $userId, 'id' => $rowId]);
            $this->pdo->prepare('DELETE FROM olt_scan_requests WHERE user_id=:uid AND olt_row_id=:id')->execute(['uid' => $userId, 'id' => $rowId]);
            $stmt = $this->pdo->prepare("DELETE FROM devices WHERE user_id=:uid AND id=:id AND device_type<>'olt' AND source='snmp'");
            $stmt->execute(['uid' => $userId, 'id' => $rowId]);
            $deleted = $stmt->rowCount() === 1;
            $this->pdo->commit();
            if ($deleted) {
                $this->forgetMonitorEntityCache($userId, $prefix, true);
                $this->forgetMonitorEntityCache($userId, $entityKey, false);
                cache_forget_device($rowId, $userId);
            }
            return $deleted;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function isSnmpManagedApiItem(array $item): bool
    {
        $type = strtolower(trim((string)($item['olt_type'] ?? $item['type'] ?? '')));
        return str_contains($type, 'hioso') || str_contains($type, 'hisfocus');
    }

    
    public function getDeviceInterfaces(int $userId, int $deviceId): array
    {
        if ($userId < 1 || $deviceId < 1) return [];
        $stmt = $this->pdo->prepare('SELECT interfaces_json FROM device_systems WHERE device_id=:id AND user_id=:uid LIMIT 1');
        $stmt->execute(['id' => $deviceId, 'uid' => $userId]);
        $decoded = json_decode((string)($stmt->fetchColumn() ?: ''), true);
        if (!is_array($decoded)) return [];

        $interfaces = [];
        foreach ($decoded as $interface) {
            if (!is_array($interface)) continue;
            $name = trim((string)($interface['name'] ?? $interface['if_name'] ?? ''));
            if ($name === '') continue;
            $interfaces[] = [
                'if_ref' => $name,
                'if_index' => (int)($interface['if_index'] ?? $interface['index'] ?? 0),
                'if_type' => trim((string)($interface['interface_type'] ?? $interface['type'] ?? '')),
                'if_status' => trim((string)($interface['oper_status'] ?? $interface['status'] ?? '')),
            ];
        }
        return $interfaces;
    }

    
    public function getDeviceTrafficInterfaces(int $userId, int $deviceId): array
    {
        if ($userId < 1 || $deviceId < 1) return [];
        $stmt = $this->pdo->prepare('SELECT if_ref, if_index FROM device_traffic_interfaces WHERE user_id=:uid AND device_id=:dev ORDER BY if_ref ASC');
        $stmt->execute(['uid' => $userId, 'dev' => $deviceId]);

        $selected = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $ref = trim((string)($row['if_ref'] ?? ''));
            if ($ref === '') continue;
            $selected[$ref] = (int)($row['if_index'] ?? 0);
        }
        return $selected;
    }

    
    public function getDeviceTrafficSeries(int $userId, int $deviceId, string $ifRef, int $rangeSeconds, int $maxPoints = 240): array
    {
        $ifRef = trim($ifRef);
        $empty = [
            'if_ref' => $ifRef,
            'range_seconds' => max(60, $rangeSeconds),
            'step' => 0,
            'points' => 0,
            'series' => [],
            'average' => ['rx_bps' => 0.0, 'tx_bps' => 0.0],
        ];
        if ($userId < 1 || $deviceId < 1 || $ifRef === '') return $empty;

        $stmt = $this->pdo->prepare(
            'SELECT UNIX_TIMESTAMP(sampled_at) AS point_slot, rx_bps, tx_bps '
            . 'FROM graph_interface_samples '
            . 'WHERE user_id=:uid AND device_id=:dev AND port_ref=:ref '
            . 'ORDER BY sampled_at DESC, id DESC LIMIT ' . self::GRAPH_SAMPLE_LIMIT
        );
        $stmt->execute(['uid' => $userId, 'dev' => $deviceId, 'ref' => $ifRef]);
        $series = [];
        $rxTotal = 0.0;
        $txTotal = 0.0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $at = (int)($row['point_slot'] ?? 0);
            if ($at < 1) continue;
            $rx = (float)($row['rx_bps'] ?? 0);
            $tx = (float)($row['tx_bps'] ?? 0);
            $series[] = ['at' => $at, 'rx' => $rx, 'tx' => $tx];
            $rxTotal += $rx;
            $txTotal += $tx;
        }
        $series = array_reverse($series);
        $count = count($series);
        return [
            'if_ref' => $ifRef,
            'range_seconds' => max(60, $rangeSeconds),
            'step' => 0,
            'points' => $count,
            'series' => $series,
            'average' => [
                'rx_bps' => $count > 0 ? $rxTotal / $count : 0.0,
                'tx_bps' => $count > 0 ? $txTotal / $count : 0.0,
            ],
        ];
    }

    public function saveGraphInterfaceSamples(int $userId, int $deviceId, array $samples, string $sampledAt): int
    {
        if ($userId < 1 || $deviceId < 1 || $samples === []) return 0;
        $sampledAt = trim($sampledAt);
        if ($sampledAt === '') return 0;
        $stmt = $this->pdo->prepare(
            'INSERT INTO graph_interface_samples(user_id,device_id,port_ref,rx_bps,tx_bps,sampled_at) '
            . 'VALUES(:uid,:dev,:ref,:rx,:tx,:at) '
            . 'ON DUPLICATE KEY UPDATE rx_bps=VALUES(rx_bps),tx_bps=VALUES(tx_bps),user_id=VALUES(user_id)'
        );
        $started = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $started = true;
        }
        $written = 0;
        try {
            foreach ($samples as $ref => $row) {
                $ref = mb_substr(trim((string)$ref), 0, 160);
                if ($ref === '' || !is_array($row)) continue;
                $this->executeInventoryWrite($stmt, [
                    'uid' => $userId,
                    'dev' => $deviceId,
                    'ref' => $ref,
                    'rx' => is_numeric($row['rx_bps'] ?? $row['rx'] ?? null) ? max(0.0, (float)($row['rx_bps'] ?? $row['rx'])) : 0.0,
                    'tx' => is_numeric($row['tx_bps'] ?? $row['tx'] ?? null) ? max(0.0, (float)($row['tx_bps'] ?? $row['tx'])) : 0.0,
                    'at' => $sampledAt,
                ]);
                $written++;
            }
            if ($started) $this->pdo->commit();
        } catch (Throwable $error) {
            if ($started && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        $this->pruneGraphInterfaceSamples($userId, $deviceId);
        return $written;
    }

    public function pruneGraphInterfaceSamples(int $userId, int $deviceId): int
    {
        if ($userId < 1 || $deviceId < 1) return 0;
        $stmt = $this->pdo->prepare(
            'DELETE s FROM graph_interface_samples s JOIN ('
            . ' SELECT id FROM ('
            . '  SELECT id, ROW_NUMBER() OVER (PARTITION BY port_ref ORDER BY sampled_at DESC, id DESC) AS rn'
            . '  FROM graph_interface_samples WHERE user_id=:uid AND device_id=:dev'
            . ' ) ranked WHERE ranked.rn > ' . self::GRAPH_SAMPLE_LIMIT
            . ') old ON old.id=s.id'
        );
        $this->executeInventoryWrite($stmt, ['uid' => $userId, 'dev' => $deviceId]);
        return $stmt->rowCount();
    }

    public function saveDeviceTrafficSamples(int $userId, int $deviceId, array $samples, string $sampledAt): int
    {
        return $this->saveGraphInterfaceSamples($userId, $deviceId, $samples, $sampledAt);
    }

    public function pruneDeviceTrafficSamples(int $userId, int $deviceId, int $keepSeconds = 5356800): int
    {
        return $this->pruneGraphInterfaceSamples($userId, $deviceId);
    }

    public function toggleDeviceTrafficInterface(int $userId, int $deviceId, string $ifRef, int $ifIndex, bool $enabled): bool
    {
        $ifRef = trim($ifRef);
        if ($userId < 1 || $deviceId < 1 || $ifRef === '') return false;

        if (!$enabled) {
            $delete = $this->pdo->prepare('DELETE FROM device_traffic_interfaces WHERE user_id=:uid AND device_id=:dev AND if_ref=:ref');
            $delete->execute(['uid' => $userId, 'dev' => $deviceId, 'ref' => $ifRef]);
            return true;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO device_traffic_interfaces (user_id, device_id, if_index, if_ref) VALUES (:uid,:dev,:idx,:ref) '
            . 'ON DUPLICATE KEY UPDATE if_index=CASE WHEN VALUES(if_index) > 0 THEN VALUES(if_index) ELSE if_index END'
        );
        $insert->execute(['uid' => $userId, 'dev' => $deviceId, 'idx' => max(0, $ifIndex), 'ref' => $ifRef]);
        return true;
    }

    
    public function saveDeviceTrafficInterfaces(int $userId, int $deviceId, array $refs): int
    {
        if ($userId < 1 || $deviceId < 1) return 0;
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM device_traffic_interfaces WHERE user_id=:uid AND device_id=:dev');
            $delete->execute(['uid' => $userId, 'dev' => $deviceId]);
            $insert = $this->pdo->prepare(
                'INSERT INTO device_traffic_interfaces (user_id, device_id, if_index, if_ref) VALUES (:uid,:dev,:idx,:ref)'
            );
            $saved = 0;
            foreach ($refs as $ref => $index) {
                $ref = trim((string)$ref);
                if ($ref === '') continue;
                $insert->execute(['uid' => $userId, 'dev' => $deviceId, 'idx' => (int)$index, 'ref' => $ref]);
                $saved++;
            }
            $this->pdo->commit();
            return $saved;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
}
