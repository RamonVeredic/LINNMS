<?php

declare(strict_types=1);

trait LinnmsOltTrait
{
    private const TRAFFIC_SAMPLE_SECONDS = 180;
    private const TRAFFIC_KEEP_SECONDS = 5356800;
    private const OLT_TRAFFIC_KEEP_SECONDS = 604800;

    private function oltDeviceId(int $userId, string $source, int $oltId): int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM devices WHERE user_id=:uid AND device_type='olt' AND source=:source AND olt_id=:oid LIMIT 1");
        $this->executeInventoryWrite($stmt, ['uid'=>$userId,'source'=>$source,'oid'=>$oltId]);
        $id = (int)$stmt->fetchColumn();
        if ($id < 1) throw new RuntimeException('Perangkat OLT induk tidak ditemukan.');
        return $id;
    }

    public function getOltDriverSupportFlags(): array
    {
        $stmt = $this->pdo->query(
            "SELECT setting_key,setting_value FROM app_settings WHERE setting_key LIKE 'olt_support_driver_%'"
        );
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $out[(string)$row['setting_key']] = (string)$row['setting_value'];
        }
        return $out;
    }

    public function getOltVlans(int $userId, int $oltDeviceId): array
    {
        if ($userId < 1 || $oltDeviceId < 1 || !is_array($this->getOltByRowId($userId, $oltDeviceId))) return [];
        $stmt = $this->pdo->prepare('SELECT v.vlan_number,v.name,v.related_ports FROM olt_vlans v INNER JOIN devices d ON d.id=v.olt_device_id WHERE d.user_id=:uid AND v.olt_device_id=:dev ORDER BY v.vlan_number');
        $this->executeInventoryWrite($stmt, ['uid' => $userId, 'dev' => $oltDeviceId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $number = (int)$row['vlan_number'];
            $related = json_decode((string)($row['related_ports'] ?? ''), true);
            $related = is_array($related) ? $related : [];
            $out[] = [
                'kind' => 'vlan',
                'name' => (string)$number,
                'data' => [
                    'vlan_id' => $number,
                    'vlan_name' => (string)($row['name'] ?? ''),
                    'access' => (array)($related['access'] ?? []),
                    'trunk' => (array)($related['trunk'] ?? []),
                ],
                'seen_at' => null,
            ];
        }
        return $out;
    }

    public function getOltVlanIds(int $userId, int $oltDeviceId): array
    {
        $ids = [];
        foreach ($this->getOltVlans($userId, $oltDeviceId) as $row) {
            $id = (int)(((array)($row['data'] ?? []))['vlan_id'] ?? $row['name'] ?? 0);
            if ($id >= 1 && $id <= 4095) $ids[$id] = true;
        }
        $out = array_map('intval', array_keys($ids));
        sort($out);
        return $out;
    }

    public function saveOltVlanLocalDefinition(int $userId, int $oltDeviceId, int $vlanId, string $name): bool
    {
        $name = trim($name);
        if ($userId < 1 || $oltDeviceId < 1 || $vlanId < 1 || $vlanId > 4095 || mb_strlen($name) > 32
            || !is_array($this->getOltByRowId($userId, $oltDeviceId))) return false;
        $stmt = $this->pdo->prepare(
            'INSERT INTO olt_vlans(olt_device_id,vlan_number,name,related_ports) '
            . 'VALUES(:dev,:vid,:name,NULL) '
            . 'ON DUPLICATE KEY UPDATE name=VALUES(name)'
        );
        return $this->executeInventoryWrite($stmt, ['dev' => $oltDeviceId, 'vid' => $vlanId, 'name' => $name]);
    }

    public function saveOltLocalProfile(int $userId, int $oltDeviceId, string $kind, string $name, array $data): bool
    {
        $kind = mb_substr(trim($kind), 0, 24);
        $name = mb_substr(trim($name), 0, 128);
        if ($userId < 1 || $oltDeviceId < 1 || $kind === '' || $name === ''
            || !is_array($this->getOltByRowId($userId, $oltDeviceId))) return false;
        $stmt = $this->pdo->prepare(
            'INSERT INTO olt_profiles(user_id,olt_device_id,kind,name,data_json,source,seen_at) '
            . "VALUES(:uid,:dev,:kind,:name,:data,'local',NOW()) "
            . "ON DUPLICATE KEY UPDATE data_json=VALUES(data_json),source='local',seen_at=NOW()"
        );
        return $this->executeInventoryWrite($stmt, [
            'uid' => $userId,
            'dev' => $oltDeviceId,
            'kind' => $kind,
            'name' => $name,
            'data' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function deleteOltLocalProfile(int $userId, int $oltDeviceId, string $kind, string $name): bool
    {
        $kind = mb_substr(trim($kind), 0, 24);
        $name = mb_substr(trim($name), 0, 128);
        if ($userId < 1 || $oltDeviceId < 1 || $kind === '' || $name === ''
            || !is_array($this->getOltByRowId($userId, $oltDeviceId))) return false;
        $stmt = $this->pdo->prepare('DELETE FROM olt_profiles WHERE user_id=:uid AND olt_device_id=:dev AND kind=:kind AND name=:name');
        $this->executeInventoryWrite($stmt, ['uid' => $userId, 'dev' => $oltDeviceId, 'kind' => $kind, 'name' => $name]);
        return $stmt->rowCount() > 0;
    }

    public function deleteOltVlanLocalDefinition(int $userId, int $oltDeviceId, int $vlanId): bool
    {
        if ($userId < 1 || $oltDeviceId < 1 || $vlanId < 1 || $vlanId > 4095
            || !is_array($this->getOltByRowId($userId, $oltDeviceId))) return false;
        $stmt = $this->pdo->prepare('DELETE FROM olt_vlans WHERE olt_device_id=:dev AND vlan_number=:vid');
        $this->executeInventoryWrite($stmt, ['dev' => $oltDeviceId, 'vid' => $vlanId]);
        return $stmt->rowCount() > 0;
    }

    public function getOltOnuTypes(int $userId, int $oltDeviceId): array
    {
        if (!is_array($this->getOltByRowId($userId, $oltDeviceId))) return [];
        $stmt = $this->pdo->prepare('SELECT t.id,t.name,t.speed,t.pon_type,t.description,t.fields_json FROM olt_onu_types t INNER JOIN devices d ON d.id=t.olt_device_id WHERE d.user_id=:uid AND t.olt_device_id=:dev ORDER BY t.name');
        $stmt->execute(['uid' => $userId, 'dev' => $oltDeviceId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $extra = json_decode((string)($row['fields_json'] ?? ''), true);
            $data = is_array($extra) ? $extra : [];
            $data['name'] = (string)$row['name'];
            $data['speed'] = (string)($row['speed'] ?? '');
            $data['pon_type'] = (string)($row['pon_type'] ?? '');
            $data['description'] = (string)($row['description'] ?? '');
            $out[] = [
                'id' => (int)$row['id'], 'kind' => 'onu_type', 'name' => (string)$row['name'],
                'data' => $data,
            ];
        }
        return $out;
    }

    public function saveOltOnuTypeLocalDefinition(int $userId, int $oltDeviceId, string $name, array $data, string $operation): bool
    {
        $name = trim($name);
        if (!is_array($this->getOltByRowId($userId, $oltDeviceId)) || $name === '' || mb_strlen($name) > 64
            || !in_array($operation, ['create', 'edit'], true)) throw new InvalidArgumentException('ONU Type tidak valid.');
        $ownedTransaction = !$this->pdo->inTransaction();
        if ($ownedTransaction) $this->pdo->beginTransaction();
        try {
            $find = $this->pdo->prepare('SELECT id FROM olt_onu_types WHERE olt_device_id=:dev AND name=:name FOR UPDATE');
            $params = ['dev' => $oltDeviceId, 'name' => $name];
            $find->execute($params);
            $existing = $find->fetch(PDO::FETCH_ASSOC);
            if ($operation === 'create' && $existing !== false) throw new InvalidArgumentException('Nama ONU Type sudah ada.');
            if ($operation === 'edit' && $existing === false) throw new InvalidArgumentException('ONU Type tidak ditemukan.');
            $pon = strtolower(trim((string)($data['pon_type'] ?? '')));
            if (!in_array($pon, ['gpon', 'epon'], true)) throw new InvalidArgumentException('Jenis PON ONU Type tidak valid.');
            $speed = mb_substr(trim((string)($data['speed'] ?? '')), 0, 64);
            $description = mb_substr(trim((string)($data['description'] ?? '')), 0, 255);
            unset($data['name'], $data['pon_type'], $data['speed'], $data['description']);
            $params['speed'] = $speed;
            $params['pon'] = $pon;
            $params['description'] = $description;
            $params['fields'] = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $sql = $existing === false
                ? 'INSERT INTO olt_onu_types(olt_device_id,name,speed,pon_type,description,fields_json) VALUES(:dev,:name,:speed,:pon,:description,:fields)'
                : 'UPDATE olt_onu_types SET speed=:speed,pon_type=:pon,description=:description,fields_json=:fields WHERE olt_device_id=:dev AND name=:name';
            $ok = $this->pdo->prepare($sql)->execute($params);
            if ($ownedTransaction) $this->pdo->commit();
            return $ok;
        } catch (Throwable $error) {
            if ($ownedTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function deleteOltOnuTypeLocalDefinition(int $userId, int $oltDeviceId, string $name): bool
    {
        if (!is_array($this->getOltByRowId($userId, $oltDeviceId))) return false;
        $stmt = $this->pdo->prepare('DELETE FROM olt_onu_types WHERE olt_device_id=:dev AND name=:name');
        $stmt->execute(['dev' => $oltDeviceId, 'name' => trim($name)]);
        return $stmt->rowCount() > 0;
    }

    public function saveOltDriverSupportFlags(array $flags): void
    {
        $catalog = function_exists('monitoring_driver_catalog')
            ? array_filter(monitoring_driver_catalog(), static fn(array $definition): bool => (string)($definition['group'] ?? '') === 'olt')
            : [];
        $stmt = $this->pdo->prepare(
            "INSERT INTO app_settings(setting_key,setting_value) VALUES (:key,:value) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
        );
        $this->pdo->beginTransaction();
        try {
            foreach ($catalog as $deviceKey => $_definition) {
                $settingKey = olt_driver_support_setting_key((string)$deviceKey);
                $enabled = !empty($flags[$deviceKey]) ? '1' : '0';
                $this->executeInventoryWrite($stmt, ['key' => $settingKey, 'value' => $enabled]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function getApiOltTypeSupportFlags(): array
    {
        $stmt = $this->pdo->query(
            "SELECT setting_key,setting_value FROM app_settings WHERE setting_key LIKE 'olt_support_api_type_%'"
        );
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $out[(string)$row['setting_key']] = (string)$row['setting_value'];
        }
        return $out;
    }

    public function saveApiOltTypeSupportFlags(array $flags): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO app_settings(setting_key,setting_value) VALUES (:key,:value) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
        );
        $this->pdo->beginTransaction();
        try {
            foreach (olt_type_options() as $oltType => $_label) {
                $this->executeInventoryWrite($stmt, [
                    'key' => api_olt_type_support_setting_key((string)$oltType),
                    'value' => !empty($flags[$oltType]) ? '1' : '0',
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function requestApiOltFirstScanApproval(
        int $userId,
        int $oltId
    ): bool {
        $stmt = $this->pdo->prepare(
            "SELECT d.id
             FROM devices d
             LEFT JOIN device_scan_state s ON s.device_id=d.id
             WHERE d.user_id=:uid
               AND d.device_type='olt'
               AND d.source='api'
               AND d.olt_id=:oid
               AND s.first_scan_approved_at IS NULL"
        );
        $this->executeInventoryWrite($stmt, ['uid' => $userId, 'oid' => $oltId]);
        $deviceIds = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $deviceId = (int)($row['id'] ?? 0);
            if ($deviceId > 0) $deviceIds[] = $deviceId;
        }
        foreach ($deviceIds as $deviceId) {
            $this->markDeviceScanApprovalRequested($deviceId, $userId);
        }
        return $deviceIds !== [];
    }

    public function autoApproveExpiredApiOltFirstScans(
        ?int $userId = null
    ): int {
        $sql =
            "SELECT d.id,d.user_id
             FROM devices d
             INNER JOIN device_scan_state s ON s.device_id=d.id
             LEFT JOIN device_status st ON st.device_id=d.id
             WHERE d.device_type='olt'
               AND d.source='api'
               AND COALESCE(st.is_present,1)=1
               AND s.first_scan_approval_requested_at IS NOT NULL
               AND s.first_scan_approved_at IS NULL
               AND s.first_scan_approval_requested_at <=
                   DATE_SUB(NOW(), INTERVAL 30 MINUTE)";

        $params = [];
        if ($userId !== null && $userId > 0) {
            $sql .= ' AND d.user_id=:uid';
            $params['uid'] = $userId;
        }

        $stmt = $this->pdo->prepare($sql);
        $this->executeInventoryWrite($stmt, $params);
        $expired = $stmt->fetchAll() ?: [];
        foreach ($expired as $expiredOlt) {
            $deviceId = (int)($expiredOlt['id'] ?? 0);
            if ($deviceId < 1) continue;
            $this->markDeviceScanApproved($deviceId, (int)($expiredOlt['user_id'] ?? 0));
        }

        return count($expired);
    }

    public function approveApiOltFirstScan(
        int $oltRowId,
        int $adminUserId
    ): ?array {
        $stmt = $this->pdo->prepare(
            "SELECT d.id,d.user_id
             FROM devices d
             LEFT JOIN device_scan_state s ON s.device_id=d.id
             WHERE d.id=:id
               AND d.device_type='olt'
               AND d.source='api'
               AND s.first_scan_approval_requested_at IS NOT NULL
               AND s.first_scan_approved_at IS NULL
             LIMIT 1"
        );
        $this->executeInventoryWrite($stmt, ['id' => $oltRowId]);
        $firstScanTarget = $stmt->fetch();
        if (is_array($firstScanTarget)) {
            $this->markDeviceScanApproved(
                (int)$firstScanTarget['id'],
                (int)$firstScanTarget['user_id'],
                $adminUserId
            );
        }

        $read = $this->pdo->prepare(
            "SELECT o.*,u.username,u.display_name
             FROM devices o
             JOIN users u ON u.id=o.user_id
             WHERE o.id=:id
               AND o.device_type='olt'
               AND o.source='api'
             LIMIT 1"
        );
        $this->executeInventoryWrite($read, ['id' => $oltRowId]);
        $row = $read->fetch();
        return is_array($row) ? $this->hydrateDeviceRow($row) : null;
    }

    public function markApiOltFirstScanStarted(
        int $userId,
        int $oltId
    ): void {
        foreach ($this->deviceIdsForScope($userId, [
            'device_type' => 'olt',
            'source' => 'api',
            'olt_id' => $oltId,
        ]) as $deviceId) {
            $this->markDeviceScanApproved($deviceId, $userId);
            $this->touchDeviceFirstScanStarted($deviceId, $userId);
        }
    }

    public function getOlts(int $userId, bool $presentOnly = true): array
    {
        $sql = "
            SELECT
                o.*,
                st.driver_status,st.driver_error,st.seen_at,st.cli_status,st.cli_seen_at,
                st.snmp_fail_count,st.cli_fail_count,st.is_present,
                (
                    SELECT COUNT(*)
                    FROM onus n
                    WHERE n.olt_device_id=o.id
                      AND n.is_present=1
                ) AS database_total_onus
            FROM devices o
            LEFT JOIN device_status st ON st.device_id=o.id
            WHERE o.user_id=:uid
              AND o.device_type='olt'
        ";

        if ($presentOnly) {
            $sql .= ' AND COALESCE(st.is_present,1)=1';
        }

        $sql .= ' ORDER BY o.source,o.olt_id';

        $stmt = $this->pdo->prepare($sql);
        $this->executeInventoryWrite($stmt, ['uid' => $userId]);
        $rows = $this->hydrateDeviceRows($stmt->fetchAll() ?: []);

        foreach ($rows as &$row) {
            $row['total_onus'] = (int)($row['database_total_onus'] ?? 0);
            unset($row['database_total_onus']);
        }
        unset($row);

        return $rows;
    }

    public function getOlt(int $userId, int $oltId, string $source = 'api'): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                o.*,
                (
                    SELECT COUNT(*)
                    FROM onus n
                    WHERE n.olt_device_id=o.id
                      AND n.is_present=1
                ) AS database_total_onus
             FROM devices o
             WHERE o.user_id=:uid
               AND o.device_type='olt'
               AND o.source=:source
               AND o.olt_id=:oid
             LIMIT 1"
        );
        $this->executeInventoryWrite($stmt, [
            'uid' => $userId,
            'source' => $source,
            'oid' => $oltId,
        ]);

        $row = $this->hydrateDeviceRow($stmt->fetch() ?: null);
        if (!is_array($row)) return null;

        $row['total_onus'] = (int)($row['database_total_onus'] ?? 0);
        unset($row['database_total_onus']);

        return $row;
    }

    public function getOltByRowId(int $userId, int $rowId, bool $includeInactiveCredentials = false): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                o.*,
                (
                    SELECT COUNT(*)
                    FROM onus n
                    WHERE n.olt_device_id=o.id
                      AND n.is_present=1
                ) AS database_total_onus
             FROM devices o
             WHERE o.user_id=:uid
               AND o.device_type='olt'
               AND o.id=:id
             LIMIT 1"
        );
        $this->executeInventoryWrite($stmt, [
            'uid' => $userId,
            'id' => $rowId,
        ]);

        $row = $this->hydrateDeviceRow($stmt->fetch() ?: null, null, $includeInactiveCredentials);
        if (!is_array($row)) return null;

        $row['total_onus'] = (int)($row['database_total_onus'] ?? 0);
        unset($row['database_total_onus']);

        return $row;
    }


    public function updateApiOltConnectionSnapshot(
        int $userId,
        int $rowId,
        string $name,
        string $ip,
        int $snmpPort
    ): bool {
        if ($snmpPort < 1 || $snmpPort > 65535) {
            throw new InvalidArgumentException(validation_port_message());
        }
        $stmt = $this->pdo->prepare(
            "UPDATE devices
             SET name=:name,
                 ip_address=:ip,
                 snmp_port=:snmp_port
             WHERE user_id=:uid
               AND id=:id
               AND device_type='olt'
               AND source='api'"
        );
        $this->executeInventoryWrite($stmt, [
            'name' => $name,
            'ip' => $ip,
            'snmp_port' => $snmpPort,
            'uid' => $userId,
            'id' => $rowId,
        ]);
        $updated = $stmt->rowCount() > 0;
        if ($updated) {
            $this->markDeviceSeen($rowId, $userId);
        }
        return $updated;
    }

    public function saveOltSystemSnapshot(
        int $userId,
        int $rowId,
        array $system,
        bool $recordChanges = true
    ): bool {
        return $this->mergeOltSystemFields($userId, $rowId, $system);
    }

    public function getOltSystemSnapshot(int $userId, int $rowId): array
    {
        $deviceIds = $this->deviceIdsForScope($userId, ['device_type' => 'olt', 'id' => $rowId]);
        if ($deviceIds === []) return [];
        $stmt = $this->pdo->prepare('SELECT system_json FROM device_systems WHERE device_id=:id AND user_id=:uid LIMIT 1');
        $this->executeInventoryWrite($stmt, ['id' => (int)$deviceIds[0], 'uid' => $userId]);
        $json = (string)($stmt->fetchColumn() ?: '');
        $decoded = $json !== '' ? json_decode($json, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    public function mergeOltSystemFields(int $userId, int $rowId, array $fields, array $clearKeys = [], ?string $phase = null): bool
    {
        if ($phase !== null) {
            $owned = match ($phase) {
                'fast' => ['current_time', 'runtime_seconds'],
                'middle' => ['cpu_percent', 'memory_percent', 'fans', 'fanboard_status', 'environment_temperature'],
                'low' => ['model', 'software_version', 'revision', 'mac', 'serial', 'system_name', 'location', 'temperature', 'ip_address', 'source', 'object_id', 'uptime', 'contact', 'vendor', 'snmp_version'],
                default => throw new InvalidArgumentException('Fase sistem OLT tidak valid.'),
            };
            $fields = array_intersect_key($fields, array_flip($owned));
            $clearKeys = array_values(array_intersect($clearKeys, $owned));
        }
        $fields = array_filter(
            $fields,
            static fn(mixed $value, string|int $key): bool => $value !== null || in_array($key, $clearKeys, true),
            ARRAY_FILTER_USE_BOTH
        );
        if ($fields === []) return false;
        $deviceIds = $this->deviceIdsForScope($userId, ['device_type'=>'olt', 'id'=>$rowId]);
        if ($deviceIds === []) return false;
        $json = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) return false;
        $stmt = $this->pdo->prepare(
            "INSERT INTO device_systems(device_id,user_id,system_json,system_seen_at) VALUES(:dev,:uid,:full,NOW())"
            . " ON DUPLICATE KEY UPDATE system_json=JSON_MERGE_PATCH(COALESCE(system_json,'{}'),:patch),system_seen_at=NOW()"
        );
        $this->executeInventoryWrite($stmt, ['dev'=>(int)$deviceIds[0], 'uid'=>$userId, 'full'=>$json, 'patch'=>$json]);
        if (isset($fields['model'])) $this->saveDeviceModelFromSystemJson((int)$deviceIds[0], $userId, $json);
        return true;
    }

    public function updateOltCardHealth(int $userId, int $oltDeviceId, array $health): int
    {
        if ($health === []) return 0;
        $stmt = $this->pdo->prepare(
            'UPDATE olt_cards SET cpu_percent=COALESCE(:cpu,cpu_percent),memory_percent=COALESCE(:memory,memory_percent)'
            . ' WHERE user_id=:uid AND olt_device_id=:dev AND slot_number=:slot'
        );
        $written = 0;
        foreach ($health as $slot => $row) {
            if ((int)$slot < 1 || !is_array($row)) continue;
            $cpu = is_numeric($row['cpu_percent'] ?? null) ? (float)$row['cpu_percent'] : null;
            $memory = is_numeric($row['memory_percent'] ?? null) ? (float)$row['memory_percent'] : null;
            if ($cpu === null && $memory === null) continue;
            $this->executeInventoryWrite($stmt, ['cpu'=>$cpu, 'memory'=>$memory, 'uid'=>$userId, 'dev'=>$oltDeviceId, 'slot'=>(int)$slot]);
            $written += $stmt->rowCount();
        }
        return $written;
    }

    public function getOltOnuStats(
        int $userId,
        int $oltId,
        string $source
    ): array {
        if (!in_array($source, ['api', 'snmp'], true)) {
            $source = 'api';
        }

        $onus = $this->getOnus($userId, $oltId, true, $source);
        $total = count($onus);
        $online = 0;
        $loss = 0;
        $powerDown = 0;
        $down = 0;
        $unregist = 0;

        foreach ($onus as $onu) {
            $state = effective_onu_state_key(
                $onu['onu_state'] ?? null,
                $onu['rx_power'] ?? null,
                onu_loss_without_rx($onu)
            );

            if ($state === 'online') $online++;
            if ($state === 'loss') $loss++;
            if ($state === 'powerdown') $powerDown++;
            if ($state === 'offline') $down++;
            if (in_array(trim((string)($onu['auth_mode'] ?? '')), ['', 'Undef', 'uncfg'], true)) $unregist++;
        }

        $problem = 0;
        foreach ($this->getProblemOnus($userId, 1000) as $problemOnu) {
            if (
                (int)($problemOnu['olt_id'] ?? 0) === $oltId &&
                (string)($problemOnu['source'] ?? '') === $source
            ) {
                $problem++;
            }
        }

        return [
            'total' => $total,
            'online' => $online,
            'unregist' => $unregist,
            'loss' => $loss,
            'down' => $down,
            'problem' => $problem,
            'power_down' => $powerDown,
        ];
    }

    public function deleteOltSnapshotByRowId(int $userId, int $rowId, string $source): bool
    {
        if (!in_array($source, ['api', 'snmp'], true)) return false;
        $olt = $this->getOltByRowId($userId, $rowId);
        if ($olt === null || (string) ($olt['source'] ?? '') !== $source) return false;

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare("SELECT id FROM devices WHERE user_id=:uid AND id=:id AND device_type='olt' AND source=:source FOR UPDATE");
            $lock->execute(['uid' => $userId, 'id' => $rowId, 'source' => $source]);
            if ($lock->fetchColumn() === false) {
                $this->pdo->commit();
                return false;
            }
            $onuRows = $this->pdo->prepare('SELECT id FROM onus WHERE user_id=:uid AND olt_device_id=:device_id');
            $onuRows->execute(['uid' => $userId, 'device_id' => $rowId]);
            $onuIds = $onuRows->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $this->deleteOltLifecycleData($userId, $source, (int)$olt['olt_id']);
            $this->pdo->prepare('DELETE FROM onus WHERE user_id=:uid AND olt_device_id=:device_id')->execute(['uid'=>$userId,'device_id'=>$rowId]);
            $this->deleteOltPendingWork($userId, $rowId);
            $delete = $this->pdo->prepare("DELETE FROM devices WHERE user_id=:uid AND id=:id AND device_type='olt' AND source=:source");
            $this->executeInventoryWrite($delete, ['uid' => $userId, 'id' => $rowId, 'source' => $source]);
            $deleted = $delete->rowCount() === 1;
            $this->pdo->commit();
            if ($deleted) {
                cache_forget_device($rowId, $userId);
                cache_forget_onus($userId, $onuIds);
            }
            return $deleted;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function hideApiOltByRowId(int $userId, int $rowId): bool
    {
        $olt = $this->getOltByRowId($userId, $rowId);
        if ($olt === null || (string) ($olt['source'] ?? '') !== 'api') return false;

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare("SELECT id FROM devices WHERE user_id=:uid AND id=:id AND device_type='olt' AND source='api' FOR UPDATE");
            $lock->execute(['uid' => $userId, 'id' => $rowId]);
            if ($lock->fetchColumn() === false) {
                $this->pdo->commit();
                return false;
            }
            $onuRows = $this->pdo->prepare('SELECT id FROM onus WHERE user_id=:uid AND olt_device_id=:device_id');
            $onuRows->execute(['uid' => $userId, 'device_id' => $rowId]);
            $onuIds = $onuRows->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $oltId = (int) $olt['olt_id'];
            $this->pdo->prepare('INSERT INTO ignored_api_olts(user_id,olt_id,name) VALUES (:uid,:oid,:name) ON DUPLICATE KEY UPDATE name=VALUES(name),deleted_at=CURRENT_TIMESTAMP')
                ->execute(['uid' => $userId, 'oid' => $oltId, 'name' => $olt['name'] ?? null]);
            $this->deleteOltLifecycleData($userId, 'api', $oltId);
            $this->pdo->prepare("DELETE FROM onus WHERE user_id=:uid AND olt_device_id=:device_id")
                ->execute(['uid' => $userId, 'device_id' => $rowId]);
            $this->deleteOltPendingWork($userId, $rowId);
            $delete = $this->pdo->prepare("DELETE FROM devices WHERE user_id=:uid AND id=:id AND device_type='olt' AND source='api'");
            $this->executeInventoryWrite($delete, ['uid' => $userId, 'id' => $rowId]);
            $deleted = $delete->rowCount() === 1;
            $this->pdo->commit();
            if ($deleted) {
                cache_forget_device($rowId, $userId);
                cache_forget_onus($userId, $onuIds);
            }
            return $deleted;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function deleteOltPendingWork(int $userId, int $rowId): void
    {
        $this->pdo->prepare('DELETE FROM sync_pipeline WHERE user_id=:uid AND olt_row_id=:row')
            ->execute(['uid' => $userId, 'row' => $rowId]);
        $this->pdo->prepare('DELETE FROM olt_scan_requests WHERE user_id=:uid AND olt_row_id=:row')
            ->execute(['uid' => $userId, 'row' => $rowId]);
    }

    private function deleteOltLifecycleData(int $userId, string $source, int $oltId): void
    {
        $prefix = strtolower(trim($source)) . '|' . $oltId . '|';
        $oltKey = strtolower(trim($source)) . '|' . $oltId;

        $events = $this->pdo->prepare(
            "DELETE FROM change_events
             WHERE user_id=:uid
               AND (
                    (event_source=:event_source AND event_olt_id=:event_olt_id)
                    OR (
                        entity_type='ONU'
                        AND LEFT(entity_key,:prefix_length)=:entity_prefix
                    )
                    OR (
                        entity_type='OLT'
                        AND entity_key=:olt_key
                    )
               )"
        );
        $this->executeInventoryWrite($events, [
            'uid' => $userId,
            'event_source' => $source,
            'event_olt_id' => $oltId,
            'prefix_length' => strlen($prefix),
            'entity_prefix' => $prefix,
            'olt_key' => $oltKey,
        ]);

        $confirmations = $this->pdo->prepare(
            "DELETE FROM sync_entitas
             WHERE user_id=:uid
               AND (
                    LEFT(entity_key,:prefix_length)=:entity_prefix
                    OR entity_key=:olt_key
               )"
        );
        $this->executeInventoryWrite($confirmations, [
            'uid' => $userId,
            'prefix_length' => strlen($prefix),
            'entity_prefix' => $prefix,
            'olt_key' => $oltKey,
        ]);
        $this->forgetMonitorEntityCache($userId, $prefix, true);
        $this->forgetMonitorEntityCache($userId, $oltKey, false);
    }

    public function resetApiOltFirstScanApproval(
        int $userId,
        int $oltId
    ): void {
        foreach ($this->deviceIdsForScope($userId, [
            'device_type' => 'olt',
            'source' => 'api',
            'olt_id' => $oltId,
        ]) as $deviceId) {
            $this->resetDeviceScanState($deviceId, $userId);
        }
    }

    private function releaseIgnoredApiOltsThatDisappeared(
        int $userId,
        array $reportedOltIds
    ): void {
        $ignored = $this->getIgnoredApiOltIds($userId);
        if ($ignored === []) return;

        $reported = array_fill_keys(
            array_map('intval', $reportedOltIds),
            true
        );

        $delete = $this->pdo->prepare(
            'DELETE FROM ignored_api_olts WHERE user_id=:uid AND olt_id=:oid'
        );

        foreach ($ignored as $oltId) {
            if (isset($reported[(int)$oltId])) continue;

            $this->executeInventoryWrite($delete, [
                'uid' => $userId,
                'oid' => (int)$oltId,
            ]);
        }
    }

    public function getIgnoredApiOltIds(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT olt_id FROM ignored_api_olts WHERE user_id=:uid');
        $this->executeInventoryWrite($stmt, ['uid' => $userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public function restoreIgnoredApiOlt(int $userId, int $oltId): bool
    {
        if ($userId < 1 || $oltId < 1) return false;
        $stmt = $this->pdo->prepare('DELETE FROM ignored_api_olts WHERE user_id=:uid AND olt_id=:oid');
        $stmt->execute(['uid' => $userId, 'oid' => $oltId]);
        return $stmt->rowCount() === 1;
    }

    public function createSnmpOlt(
        int $userId,
        string $name,
        string $ip,
        string $community,
        string $driverId,
        int $snmpPort = 161,
        int $hybridStatus = 0,
        int $cliPort = 23,
        ?string $cliUsername = null,
        ?string $cliPasswordEnc = null,
    ): int {
        $this->ensureOltScanRequestsTable();
        $quotaLock = $this->acquireDeviceQuotaLock('olt');
        try {
        $this->pdo->beginTransaction();
        try {
            $quota = $this->getUserOltQuota($userId);
            if (empty($quota['can_add'])) throw new RuntimeException('Batas OLT telah tercapai.');
            $sql = "INSERT INTO devices(user_id,device_type,type,source,name,ip_address,snmp_port) VALUES (:uid,'olt',:driver_id,'snmp',:name,:ip,:snmp_port)";
            $stmt = $this->pdo->prepare($sql);
            $this->executeInventoryWrite($stmt, [
                'uid'=>$userId,
                'name'=>$name,
                'ip'=>$ip,
                'driver_id'=>$driverId,
                'snmp_port'=>$snmpPort,
            ]);
            $id = (int)$this->pdo->lastInsertId();
            $this->saveDeviceCommunity($id, $userId, $community);
            $credentials = ['hybrid_status' => $hybridStatus === 1 ? 1 : 0];
            if ($hybridStatus === 1) {
                $credentials['ports'] = max(1, min(65535, $cliPort));
                $credentials['username'] = $cliUsername;
                $credentials['password_enc'] = $cliPasswordEnc;
            }
            $this->saveDeviceCredentials($id, $userId, $credentials);
            $this->saveDeviceSystem($id, $userId, ['raw_json' => '{}']);
            $this->saveDeviceStatus($id, $userId, ['driver_status' => 'Belum Sync', 'is_present' => 1]);
            $stmt = $this->pdo->prepare("UPDATE devices SET olt_id=:olt_id WHERE id=:row_id AND user_id=:uid AND source='snmp'");
            $this->executeInventoryWrite($stmt, ['olt_id'=>$id,'row_id'=>$id,'uid'=>$userId]);
            $this->pdo->prepare(
                "INSERT INTO olt_scan_requests(user_id,olt_row_id,status,force_full,initial_scan,record_changes,requested_at)
                 VALUES(:uid,:row,'queued',1,1,0,NOW())"
            )->execute(['uid'=>$userId, 'row'=>$id]);
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
 public function saveSnmpOltCliCredentials(
        int $userId,
        int $oltId,
        int $hybridStatus,
        int $port,
        ?string $username,
        ?string $passwordEnc = null
    ): bool {
        $stmt = $this->pdo->prepare("SELECT id FROM devices WHERE user_id=:uid AND device_type='olt' AND source='snmp' AND olt_id=:oid LIMIT 1");
        $this->executeInventoryWrite($stmt, ['uid' => $userId, 'oid' => $oltId]);
        $deviceId = (int)($stmt->fetchColumn() ?: 0);
        if ($deviceId < 1) return false;

        $data = ['hybrid_status' => $hybridStatus === 1 ? 1 : 0];
        if ($hybridStatus === 1) {
            $data['ports'] = max(1, min(65535, $port));
            $data['username'] = $username;
            if ($passwordEnc !== null) $data['password_enc'] = $passwordEnc;
        }
        $this->saveDeviceCredentials($deviceId, $userId, $data);

        return true;
    }

    public function markSnmpOltSyncFailed(
        int $userId,
        int $oltId,
        string $error,
        bool $recordChanges = true,
        bool $deviceDown = false
    ): bool
    {
        $created = false;
        $targetStatus = $deviceDown ? 'DOWN' : 'WARN';
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare(
                "SELECT d.id,d.name,st.driver_status
                 FROM devices d
                 LEFT JOIN device_status st ON st.device_id=d.id
                 WHERE d.user_id=:uid AND d.device_type='olt' AND d.source='snmp' AND d.olt_id=:oid
                 FOR UPDATE"
            );
            $this->executeInventoryWrite($lock, ['uid'=>$userId,'oid'=>$oltId]);
            $olt = $lock->fetch();
            $confirmedFailure = $recordChanges && $deviceDown && is_array($olt)
                ? $this->confirmScannedStatus(
                    $userId,'OLT','snmp|' . $oltId,'driver_status',
                    $olt['driver_status'] ?? 'OK',$targetStatus
                )
                : null;
            if ($confirmedFailure !== null) {
                $this->insertChangeEvent(
                    $userId,'OLT','snmp|' . $oltId,'driver_status',
                    $confirmedFailure['old'],$confirmedFailure['new'],
                    'OLT ' . display_text($olt['name'] ?? null) . ' down dan tidak dapat dijangkau.',
                    ['source'=>'snmp','olt_id'=>$oltId,'failure_kind'=>'down']
                );
                $created = true;
            }
            foreach ($this->deviceIdsForScope($userId, ['source' => 'snmp', 'olt_id' => $oltId]) as $deviceId) {
                if (!$deviceDown) {
                    $this->markDeviceSyncFailure($deviceId, $userId, 'WARN', $error);
                    continue;
                }
                $this->recordConnectionProbe($deviceId, $userId, 'snmp', false, $error !== '' ? $error : $targetStatus);
            }
            $this->pdo->commit();
            return $created;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function getDueSnmpOlts(int $userId): array
    {
        $this->ensureOltScanRequestsTable();
        $fastInterval = $this->getSyncTimingSettings($userId)['fast_interval_minutes'];
        $params = ['uid' => $userId];
        $intervalExpression = ':fast_interval';
        $params['fast_interval'] = $fastInterval;
        $markerClause = $this->deviceStatusHasConfigChangedColumn() ? ' OR st.config_changed_at IS NOT NULL' : '';
        $sql = "SELECT d.* FROM devices d LEFT JOIN device_status st ON st.device_id=d.id WHERE d.user_id=:uid AND d.device_type='olt' AND d.source='snmp' AND COALESCE(st.is_present,1)=1 AND NOT EXISTS (SELECT 1 FROM olt_scan_requests initial_scan WHERE initial_scan.user_id=d.user_id AND initial_scan.olt_row_id=d.id AND initial_scan.initial_scan=1 AND initial_scan.status IN ('queued','running','writing')) AND (st.seen_at IS NULL OR TIMESTAMPDIFF(MINUTE,st.seen_at,NOW()) >= {$intervalExpression}{$markerClause}) ORDER BY d.id";
        $stmt = $this->pdo->prepare($sql);
        $this->executeInventoryWrite($stmt, $params);
        return $this->hydrateDeviceRows($stmt->fetchAll() ?: []);
    }

    private function oltPonLinkStatusLabel(string $value): string
    {
        return match (strtolower(trim($value))) {
            'online', 'up', 'linkup' => 'LinkUp',
            'offline', 'down', 'linkdown' => 'LinkDown',
            default => $value,
        };
    }

    public function updateOltPonSnapshot(
        int $userId,
        int $localOltId,
        array $ports,
        bool $recordChanges = true,
        string $source = 'snmp',
        bool $fastOnly = false
    ): array {
        
        
        $deviceSource = 'snmp';
        $current = $this->normalizeOltPonPorts($ports);
        if ($current === []) return [];

        $events = [];
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare(
                "SELECT id,name
                 FROM devices
                 WHERE user_id=:uid
                   AND device_type='olt'
                   AND source=:source
                   AND olt_id=:oid
                 LIMIT 1 FOR UPDATE"
            );
            $this->executeInventoryWrite($lock, ['uid' => $userId, 'source' => $deviceSource, 'oid' => $localOltId]);
            $olt = $lock->fetch();
            if (!is_array($olt)) {
                $this->pdo->rollBack();
                return [];
            }

            $previous = [];
            foreach ($this->getOltPons($userId, (int)$olt['id']) as $storedPort) {
                $identity = trim((string)($storedPort['port_ref'] ?? ''));
                if ($identity === '') continue;
                $payload = is_array($storedPort) ? $storedPort : [];
                $payload['port_ref'] = $identity;
                if (!array_key_exists('description', $payload)) {
                    $payload['description'] = (string)($storedPort['description'] ?? '');
                }
                $previous[$identity] = $payload;
            }
            $oltName = display_text($olt['name'] ?? null);

            if ($previous !== []) {
                foreach ($current as $identity => $port) {
                    $oldPort = $previous[$identity] ?? null;
                    if (!is_array($oldPort)) continue;
                    foreach (['description', 'admin_state', 'autoneg', 'speed', 'duplex', 'flow_control'] as $field) {
                        if (trim((string)($port[$field] ?? '')) === '') {
                            $current[$identity][$field] = (string)($oldPort[$field] ?? '');
                        }
                    }
                    foreach ((array)($oldPort['optics'] ?? []) as $field => $value) {
                        if (in_array($field, ['tx_power', 'rx_power'], true)) continue;
                        if (($port['optics'][$field] ?? null) === null || trim((string)$port['optics'][$field]) === '') {
                            $current[$identity]['optics'][$field] = $value;
                        }
                    }
                }
            }

            if ($recordChanges && $previous !== []) {
                foreach ($current as $identity => $port) {
                    $old = $previous[$identity] ?? null;
                    if (!is_array($old)) continue;
                    $oldStatus = (string)($old['link_status'] ?? '');
                    $newStatus = (string)($port['link_status'] ?? '');
                    $ponNumber = (int)($port['pon_number'] ?? 0);
                    $ponLabel = $ponNumber > 0 ? 'PON' . $ponNumber : 'PON ' . $identity;
                    $entityKey = $source . '|' . $localOltId . '|pon|' . ($ponNumber > 0 ? $ponNumber : rawurlencode($identity));
                    $context = ['source' => $source, 'olt_id' => $localOltId];

                    $confirmedLinkStatus = $oldStatus !== '' && $newStatus !== ''
                        ? $this->confirmScannedStatus(
                            $userId,
                            'OLT',
                            $entityKey,
                            'pon_link_status',
                            $oldStatus,
                            $newStatus
                        )
                        : null;
                    if ($confirmedLinkStatus !== null) {
                        $current[$identity]['link_status'] = $this->oltPonLinkStatusLabel(
                            (string)$confirmedLinkStatus['new']
                        );
                        $events[] = $this->insertChangeEvent(
                            $userId,
                            'OLT',
                            $entityKey,
                            'pon_link_status',
                            $confirmedLinkStatus['old'],
                            $confirmedLinkStatus['new'],
                            $oltName . ' ' . $ponLabel . ' ' . state_label($confirmedLinkStatus['new']),
                            $context
                        );
                    } elseif ($oldStatus !== '' && $newStatus !== '') {
                        $current[$identity]['link_status'] = $oldStatus;
                    }

                    if (empty($port['tx_power_sampled'])) continue;
                    $txChange = $this->confirmOltPonTxPowerChange($userId, $entityKey, $old, $port);
                    if ($txChange === null) continue;

                    $oldTxPower = $txChange['old'];
                    $newTxPower = $txChange['new'];
                    
                    $txStatusRaw = $newStatus !== '' ? $newStatus : $oldStatus;
                    $txStatusText = match (strtolower(trim((string)$txStatusRaw))) {
                        'linkup', 'up', 'online' => 'Up',
                        'linkdown', 'down', 'offline' => 'Down',
                        default => ($txStatusRaw !== '' ? $txStatusRaw : 'N/A'),
                    };
                    $txPonRef = (string)$identity;
                    $txOldText = $oldTxPower !== null ? format_activity_power($oldTxPower) : 'N/A';
                    $txNewText = is_numeric($newTxPower) ? format_activity_power($newTxPower) : 'N/A';
                    $txNote = $txChange['kind'] === 'missing' ? 'error' : 'perubahan';
                    $txContext = $context + ['details' => [
                        'name' => $oltName,
                        'status' => $txStatusText,
                        'pon_ref' => $txPonRef,
                        'note' => $txNote,
                    ]];
                    $message = 'Nama : ' . $oltName . ', Status: ' . $txStatusText . ', Pon ' . $txPonRef
                        . ' tx power ' . $txOldText . ' ➠ ' . $txNewText . ' tidak terdeteksi ' . $txNote;
                    $events[] = $this->insertChangeEvent(
                        $userId,
                        'OLT',
                        $entityKey,
                        'pon_tx_power',
                        $oldTxPower ?? 'N/A',
                        $newTxPower ?? 'N/A',
                        $message,
                        $txContext
                    );
                }
            }
            foreach ($current as $identity => $port) {
                $old = $previous[$identity] ?? null;
                if (is_array($old)) {
                    if (trim((string)($port['flow_control'] ?? '')) === '') $port['flow_control'] = (string)($old['flow_control'] ?? '');
                    $current[$identity] = $port;
                }
            }
            $this->replaceOltPons($userId, (int)($olt['id'] ?? 0), array_values($current), $source, false, $fastOnly);
            $this->pdo->commit();
            return $events;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function updateOltPonMiddleSettings(int $userId, int $oltDeviceId, array $ports): int
    {
        if ($userId < 1 || $oltDeviceId < 1 || $ports === []) return 0;
        $allowed = ['admin_state', 'autoneg', 'speed', 'duplex', 'flow_control', 'description'];
        $written = 0;
        foreach ($ports as $port) {
            if (!is_array($port)) continue;
            $ref = trim((string)($port['port_ref'] ?? ''));
            if ($ref === '') continue;
            $assignments = [];
            $params = ['uid'=>$userId, 'dev'=>$oltDeviceId, 'ref'=>$ref];
            foreach ($allowed as $field) {
                if (!array_key_exists($field, $port) || !is_string($port[$field])) continue;
                $value = trim($port[$field]);
                if ($value === '' && $field !== 'description') continue;
                $assignments[] = $field . '=:' . $field;
                $params[$field] = mb_substr($value, 0, $field === 'description' ? 191 : 16);
            }
            if ($assignments === []) continue;
            $stmt = $this->pdo->prepare(
                'UPDATE pons SET ' . implode(',', $assignments)
                . ' WHERE user_id=:uid AND olt_device_id=:dev AND port_ref=:ref'
            );
            $this->executeInventoryWrite($stmt, $params);
            $written += $stmt->rowCount();
        }
        return $written;
    }


    public function commitOltPonDescription(
        int $userId,
        int $oltDeviceId,
        int $oltLocalId,
        string $source,
        string $portRef,
        int $ponNumber,
        string $description,
        string $actor = ''
    ): array {
        $result = ['ok' => false, 'changed' => false, 'previous' => '', 'port_ref' => '', 'event' => null];
        if ($userId < 1 || $oltDeviceId < 1) return $result;

        $source = 'snmp';
        $description = mb_substr(trim($description), 0, 191);
        $portRef = mb_substr(trim($portRef), 0, 120);
        $ponNumber = max(0, min(4096, $ponNumber));
        $actor = mb_substr(trim($actor), 0, 120);
        if ($actor === '') $actor = $this->getUserDisplayName($userId);

        $identity = $portRef !== '' ? $portRef : ($ponNumber > 0 ? '0/1_' . $ponNumber : '');
        if ($identity === '') return $result;

        $this->pdo->beginTransaction();
        try {
            $stored = $this->writeOltPonDescriptionRow(
                $userId,
                $oltDeviceId,
                $portRef,
                $ponNumber,
                $description,
                $source
            );
            $result['ok'] = $stored['port_ref'] !== '';
            $result['previous'] = $stored['previous'];
            $result['port_ref'] = $stored['port_ref'];
            $result['changed'] = $result['ok'] && $result['previous'] !== $description;

            if ($result['changed']) {
                $message = $description === ''
                    ? 'Keterangan dihapus oleh ' . $actor . '.'
                    : 'Keterangan menjadi "' . $description . '" oleh ' . $actor . '.';
                $result['event'] = $this->insertChangeEvent(
                    $userId,
                    'OLT',
                    $source . '|' . $oltLocalId . '|pon|' . $ponNumber,
                    'pon_description',
                    $result['previous'],
                    $description,
                    $message,
                    ['source' => $source, 'olt_id' => $oltLocalId]
                );
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return $result;
    }

    public function saveOltPonDescriptionLocal(
        int $userId,
        int $oltDeviceId,
        string $portRef,
        int $ponNumber,
        string $description,
        string $source = 'snmp'
    ): array {
        $result = ['ok' => false, 'changed' => false, 'previous' => '', 'port_ref' => ''];
        if ($userId < 1 || $oltDeviceId < 1) return $result;

        $source = 'snmp';
        $description = mb_substr(trim($description), 0, 191);
        $portRef = mb_substr(trim($portRef), 0, 120);
        $ponNumber = max(0, min(4096, $ponNumber));
        if ($portRef === '' && $ponNumber < 1) return $result;

        $this->pdo->beginTransaction();
        try {
            $stored = $this->writeOltPonDescriptionRow(
                $userId,
                $oltDeviceId,
                $portRef,
                $ponNumber,
                $description,
                $source
            );
            $result['ok'] = $stored['port_ref'] !== '';
            $result['previous'] = $stored['previous'];
            $result['port_ref'] = $stored['port_ref'];
            $result['changed'] = $result['ok'] && $result['previous'] !== $description;
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return $result;
    }

    public function updateOltPonFlowControl(int $userId, int $oltDeviceId, string $portRef, int $ponNumber, string $value): bool
    {
        if (!in_array($value, ['Enable', 'Disable'], true)) return false;
        $port = $this->findOltPonRow($userId, $oltDeviceId, $portRef, $ponNumber);
        if (!is_array($port)) return false;
        $stmt = $this->pdo->prepare('UPDATE pons SET flow_control=:value WHERE id=:id AND user_id=:uid AND olt_device_id=:dev');
        return $this->executeInventoryWrite($stmt, ['value' => $value, 'id' => (int)$port['id'], 'uid' => $userId, 'dev' => $oltDeviceId]);
    }

    public function saveOltPonLocalConfig(int $userId, int $oltDeviceId, string $portRef, int $ponNumber, string $field, string $value): bool
    {
        $columns = [
            'olt_port_enable' => ['admin_state', ['Enable', 'Disable']],
            'olt_port_autoneg' => ['autoneg', ['Enable', 'Disable']],
            'olt_port_speed' => ['speed', ['1000M', '100M']],
            'olt_port_duplex' => ['duplex', ['Full', 'Half']],
            'olt_port_flow_control' => ['flow_control', ['Enable', 'Disable']],
        ];
        if ($userId < 1 || $oltDeviceId < 1 || !isset($columns[$field])) return false;
        [$column, $allowed] = $columns[$field];
        $value = trim($value);
        if (!in_array($value, $allowed, true)) return false;
        $sets = $column . '=:value,seen_at=NOW()';
        $params = [
            'uid' => $userId,
            'dev' => $oltDeviceId,
            'ref' => mb_substr(trim($portRef), 0, 120),
            'num' => max(0, $ponNumber),
            'value' => mb_substr($value, 0, 16),
        ];
        $stmt = $this->pdo->prepare(
            'UPDATE pons SET ' . $sets . ' WHERE user_id=:uid AND olt_device_id=:dev AND (port_ref=:ref OR pon_number=:num)'
        );
        $this->executeInventoryWrite($stmt, $params);
        return $stmt->rowCount() > 0;
    }

    public function updateOltPonAdminState(int $userId, int $oltDeviceId, string $portRef, int $ponNumber, string $value): bool
    {
        if ($userId < 1 || $oltDeviceId < 1) return false;
        $table = $this->oltPonTable($userId, $oltDeviceId);
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE {$table} SET admin_state=:value, seen_at=NOW() "
                . 'WHERE user_id=:uid AND olt_device_id=:dev AND (port_ref=:ref OR pon_number=:num)'
            );
            $this->executeInventoryWrite($stmt, [
                'uid' => $userId,
                'dev' => $oltDeviceId,
                'ref' => mb_substr(trim($portRef), 0, 120),
                'num' => $ponNumber,
                'value' => mb_substr(trim($value), 0, 16),
            ]);
            return $stmt->rowCount() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public function recordOltPonConfigEvent(
        int $userId,
        int $oltLocalId,
        string $source,
        int $ponNumber,
        bool $applied,
        string $actor = '',
        string $fieldLabel = '',
        string $previousValue = '',
        string $newValue = ''
    ): ?array {
        if ($userId < 1) return null;
        $source = 'snmp';
        $ponNumber = max(0, min(4096, $ponNumber));
        $actor = mb_substr(trim($actor), 0, 120);
        if ($actor === '') $actor = $this->getUserDisplayName($userId);
        $fieldLabel = mb_substr(trim($fieldLabel), 0, 60);
        $previousValue = mb_substr(trim($previousValue), 0, 60);
        $newValue = mb_substr(trim($newValue), 0, 60);

        $ponLabel = $ponNumber > 0 ? 'PON' . $ponNumber : 'PON';
        $subject = ($fieldLabel !== '' ? $fieldLabel . ' ' : 'Konfigurasi ') . $ponLabel;
        if ($applied) {
            $message = $fieldLabel !== '' && $newValue !== ''
                ? $subject . ' diubah dari ' . ($previousValue !== '' ? $previousValue : 'N/A')
                    . ' ke ' . $newValue . ' oleh ' . $actor . '.'
                : $subject . ' diperbarui oleh ' . $actor . '.';
        } else {
            $message = 'Perubahan ' . $subject . ' tidak dapat diterapkan.';
        }

        return $this->insertChangeEvent(
            $userId,
            'OLT',
            $source . '|' . $oltLocalId . '|pon|' . $ponNumber,
            'pon_port_config',
            null,
            $applied ? 'applied' : 'unsupported',
            $message,
            [
                'source' => $source,
                'olt_id' => $oltLocalId,
                'details' => [
                    'field' => $fieldLabel,
                    'old_value' => $previousValue,
                    'new_value' => $newValue,
                    'actor_user_id' => $userId,
                    'actor' => $actor,
                    'result' => $applied ? 'ok' : 'failed',
                ],
            ]
        );
    }

    private function writeOltPonDescriptionRow(
        int $userId,
        int $oltDeviceId,
        string $portRef,
        int $ponNumber,
        string $description,
        string $source
    ): array {
        $identity = $portRef !== '' ? $portRef : ($ponNumber > 0 ? '0/1_' . $ponNumber : '');
        if ($identity === '') return ['previous' => '', 'port_ref' => ''];

        $row = $this->findOltPonRow($userId, $oltDeviceId, $portRef, $ponNumber);
        if (!is_array($row)) {
            $label = $ponNumber > 0 ? '0/1/' . $ponNumber : $identity;
            $payload = [
                'port_ref' => $identity,
                'pon' => $label,
                'description' => $description,
                'port_type' => 'PON',
            ];
            $this->pdo->prepare(
                "INSERT INTO {\$this->oltPonTable(\$userId, \$oltDeviceId)}(user_id,olt_device_id,port_ref,pon_number,label,pon_type,description,is_present,seen_at)"
                . ' VALUES(:uid,:dev,:ref,:number,:label,:type,:description,1,NOW())'
                . ' ON DUPLICATE KEY UPDATE description=VALUES(description),is_present=1,seen_at=NOW()'
            )->execute([
                'uid' => $userId,
                'dev' => $oltDeviceId,
                'ref' => $identity,
                'number' => $ponNumber,
                'label' => mb_substr($label, 0, 120),
                'type' => 'PON',
                'description' => $description,
            ]);

            return ['previous' => '', 'port_ref' => $identity];
        }

        $storedRef = trim((string)($row['port_ref'] ?? ''));
        if ($storedRef !== '') $identity = $storedRef;

        $this->pdo->prepare(
            "UPDATE {\$this->oltPonTable(\$userId, \$oltDeviceId)} SET description=:description,is_present=1,seen_at=NOW()"
            . ' WHERE id=:id AND user_id=:uid'
        )->execute([
            'description' => $description,
            'id' => (int)$row['id'],
            'uid' => $userId,
        ]);

        return ['previous' => trim((string)($row['description'] ?? '')), 'port_ref' => $identity];
    }

    
    
    private function oltPonTable(int $userId, int $oltDeviceId): string
    {
        return 'pons';
    }

    
    private function oltPonRowToPort(array $row): array
    {
        $row['pon'] = (string)($row['label'] ?? '');
        $row['admin_state'] = trim((string)($row['admin_state'] ?? '')) !== '' ? (string)$row['admin_state'] : 'Enable';
        $row['link_status'] = (string)($row['link_status'] ?? '');
        $row['flow_control'] = (string)($row['flow_control'] ?? '');
        $row['autoneg'] = (string)($row['autoneg'] ?? '');
        $row['speed'] = (string)($row['speed'] ?? '');
        $row['duplex'] = (string)($row['duplex'] ?? '');
        $moduleJson = $row['module_json'] ?? null;
        $decoded = is_string($moduleJson) ? json_decode($moduleJson, true) : $moduleJson;
        $row['module_json'] = is_array($decoded) ? $decoded : [];
        $row['tx_power'] = is_numeric($row['module_tx_power'] ?? null) ? (float)$row['module_tx_power'] : null;
        $row['optics'] = $row['module_json'] + [
            'module_type' => trim((string)($row['module_type'] ?? '')),
            'temperature' => is_numeric($row['module_temperature'] ?? null) ? (float)$row['module_temperature'] : null,
            'voltage' => is_numeric($row['module_voltage'] ?? null) ? (float)$row['module_voltage'] : null,
            'current' => is_numeric($row['module_current'] ?? null) ? (float)$row['module_current'] : null,
            'tx_power' => $row['tx_power'],
            'version' => (string)($row['module_json']['module_version'] ?? ''),
        ];
        unset($row['module_tx_power'], $row['module_temperature'], $row['module_voltage'], $row['module_current']);
        return $row;
    }

    
    private function cleanModuleText(mixed $value): string
    {
        $text = (string)$value;
        $text = (string)preg_replace('/\x1B\[[0-9;?]*[A-Za-z]/', '', $text);
        $text = (string)preg_replace('/[\x{200B}-\x{200F}\x{FEFF}\x{202A}-\x{202E}]/u', '', $text);
        $text = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        return trim($text);
    }

    
    private function ponModuleJson(array $optics): array
    {
        $num = static fn(mixed $v): ?float => (is_numeric($v) && is_finite((float)$v)) ? (float)$v : null;
        return [
            'rx_power' => $num($optics['rx_power'] ?? null),
            'bias_current' => $num($optics['bias_current'] ?? null),
            'vendor' => $this->cleanModuleText($optics['vendor'] ?? ''),
            'vendor_pn' => $this->cleanModuleText($optics['vendor_pn'] ?? ''),
            'vendor_sn' => $this->cleanModuleText($optics['vendor_sn'] ?? ''),
            'class' => $this->cleanModuleText($optics['class'] ?? ''),
            'connector' => $this->cleanModuleText($optics['connector'] ?? ''),
            'trans_distance' => $this->cleanModuleText($optics['trans_distance'] ?? ''),
            'wavelength' => $num($optics['wavelength'] ?? null),
            'module_version' => $this->cleanModuleText($optics['version'] ?? ''),
            'production_date' => $this->cleanModuleText($optics['production_date'] ?? ''),
        ];
    }

    private function findOltPonRow(int $userId, int $oltDeviceId, string $portRef, int $ponNumber): ?array
    {
        if ($userId < 1 || $oltDeviceId < 1) return null;
        $table = $this->oltPonTable($userId, $oltDeviceId);
        $sql = "SELECT id,port_ref,description FROM {$table} WHERE user_id=:uid AND olt_device_id=:dev";
        $params = ['uid' => $userId, 'dev' => $oltDeviceId];

        if (trim($portRef) !== '') {
            $stmt = $this->pdo->prepare($sql . ' AND port_ref=:ref LIMIT 1');
            $this->executeInventoryWrite($stmt, $params + ['ref' => trim($portRef)]);
            $row = $stmt->fetch();
            if (is_array($row)) return $row;
        }
        if ($ponNumber > 0) {
            $stmt = $this->pdo->prepare($sql . ' AND pon_number=:number ORDER BY is_present DESC,id ASC LIMIT 1');
            $this->executeInventoryWrite($stmt, $params + ['number' => $ponNumber]);
            $row = $stmt->fetch();
            if (is_array($row)) return $row;
        }

        return null;
    }

    public function getOltPonWriteActions(int $userId, int $oltLocalId, string $source = 'snmp', int $limit = 200): array
    {
        if ($userId < 1 || $oltLocalId < 1) return [];
        $source = 'snmp';
        $stmt = $this->pdo->prepare(
            "SELECT id,field_name,old_value,new_value,message,event_source,event_olt_id,entity_key,created_at
             FROM change_events
             WHERE user_id=:uid AND entity_type='OLT' AND event_olt_id=:oid
               AND field_name='pon_description'
               AND (event_source IS NULL OR event_source='' OR event_source=:source)
             ORDER BY id DESC LIMIT " . max(1, min(500, $limit))
        );
        $this->executeInventoryWrite($stmt, ['uid' => $userId, 'oid' => $oltLocalId, 'source' => $source]);

        $writes = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            if (preg_match('~\|pon\|(\d+)$~', trim((string)($row['entity_key'] ?? '')), $match) !== 1) continue;
            $ponNumber = (int)$match[1];
            if ($ponNumber < 1 || isset($writes[$ponNumber])) continue;
            $writes[$ponNumber] = $row;
        }

        return $writes;
    }


    public function replaceOltPons(int $userId, int $oltDeviceId, array $ports, string $source = 'snmp', bool $ownTransaction = true, bool $fastOnly = false): int
    {
        if ($userId < 1 || $oltDeviceId < 1 || $ports === []) return 0;
        $source = in_array($source, ['snmp', 'cli', 'web', 'api', 'fast'], true) ? $source : 'snmp';

        
        $table = $this->oltPonTable($userId, $oltDeviceId);
        $columns = 'user_id,olt_device_id,card_id,port_ref,pon_number,label,pon_type,link_status,is_present,seen_at';
        $values = ':uid,:dev,:card_id,:ref,:number,:label,:type,:link,1,NOW()';
        $updates = 'id=LAST_INSERT_ID(id),card_id=VALUES(card_id),pon_number=VALUES(pon_number),label=VALUES(label),pon_type=VALUES(pon_type),link_status=VALUES(link_status),is_present=1,seen_at=NOW()';
        if (!$fastOnly) {
            $columns = 'user_id,olt_device_id,card_id,port_ref,pon_number,label,description,pon_type,admin_state,link_status,flow_control,autoneg,speed,duplex,is_present,seen_at';
            $values = ':uid,:dev,:card_id,:ref,:number,:label,:description,:type,:admin,:link,:flow,:autoneg,:speed,:duplex,1,NOW()';
            $updates = 'id=LAST_INSERT_ID(id),card_id=VALUES(card_id),pon_number=VALUES(pon_number),label=VALUES(label),description=VALUES(description),pon_type=VALUES(pon_type),'
                . 'admin_state=VALUES(admin_state),link_status=VALUES(link_status),flow_control=VALUES(flow_control),autoneg=COALESCE(VALUES(autoneg),autoneg),speed=COALESCE(VALUES(speed),speed),duplex=COALESCE(VALUES(duplex),duplex),'
                . 'is_present=1,seen_at=NOW()';
        }
        $insert = $this->pdo->prepare(
            "INSERT INTO {$table}({$columns}) VALUES({$values}) ON DUPLICATE KEY UPDATE {$updates}"
        );

        $started = false;
        if ($ownTransaction && !$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $started = true;
        }

        $written = 0;
        $seen = [];
        $moduleStmt = $this->pdo->prepare(
            'INSERT INTO pon_modules(user_id,pon_id,source,sampled_at,tx_power,temperature,voltage,current,module_type,module_json) '
            . 'VALUES(:uid,:pon,:source,NOW(),:tx,:temp,:volt,:cur,:mtype,:mjson)'
            . ' ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),source=VALUES(source),sampled_at=VALUES(sampled_at),'
            . 'tx_power=VALUES(tx_power),temperature=VALUES(temperature),'
            . 'voltage=VALUES(voltage),current=VALUES(current),'
            . 'module_type=VALUES(module_type),module_json=VALUES(module_json)'
        );
        $cardStmt = $this->pdo->prepare(
            "SELECT id,slot_number FROM olt_cards WHERE user_id=:uid AND olt_device_id=:dev AND role='pon' AND is_present=1"
        );
        $this->executeInventoryWrite($cardStmt, ['uid' => $userId, 'dev' => $oltDeviceId]);
        $cardIdsBySlot = [];
        foreach ($cardStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $cardRow) {
            $cardSlot = (int)($cardRow['slot_number'] ?? 0);
            if ($cardSlot > 0) $cardIdsBySlot[$cardSlot] = (int)$cardRow['id'];
        }
        try {
            foreach ($ports as $port) {
                if (!is_array($port)) continue;
                $ref = trim((string)($port['port_ref'] ?? ''));
                if ($ref === '') $ref = trim((string)($port['pon'] ?? ''));
                if ($ref === '') continue;

                $label = trim((string)($port['pon'] ?? ''));
                if ($label === '') $label = $ref;
                $number = (int)($port['pon_number'] ?? 0);
                if ($number < 1 && preg_match('~(?:/|_)(\d+)$~', $label, $match) === 1) $number = (int)$match[1];

                $type = trim((string)($port['port_type'] ?? ''));
                $portSlot = (int)($port['slot'] ?? 0);
                if (!array_key_exists('slot', $port) && preg_match('~^\d+/(\d+)/\d+$~', str_replace('_', '/', $ref), $slotMatch) === 1) {
                    $portSlot = (int)$slotMatch[1];
                }
                $cardId = $portSlot > 0 ? ($cardIdsBySlot[$portSlot] ?? null) : null;

                $params = [
                    'uid' => $userId,
                    'dev' => $oltDeviceId,
                    'card_id' => $cardId,
                    'ref' => mb_substr($ref, 0, 120),
                    'number' => $number,
                    'label' => mb_substr($label, 0, 120),
                    'type' => $type !== '' ? mb_substr($type, 0, 16) : 'PON',
                    'link' => mb_substr(trim((string)($port['link_status'] ?? '')), 0, 16),
                ];
                if (!$fastOnly) {
                    $params['description'] = mb_substr(trim((string)($port['description'] ?? '')), 0, 191);
                    $params['admin'] = mb_substr(trim((string)($port['admin_state'] ?? $port['enable'] ?? '')) !== '' ? trim((string)($port['admin_state'] ?? $port['enable'] ?? '')) : 'Enable', 0, 16);
                    $params['flow'] = mb_substr(trim((string)($port['flow_control'] ?? '')), 0, 16);
                    $params['autoneg'] = trim((string)($port['autoneg'] ?? '')) !== '' ? mb_substr(trim((string)$port['autoneg']), 0, 16) : null;
                    $params['speed'] = trim((string)($port['speed'] ?? '')) !== '' ? mb_substr(trim((string)$port['speed']), 0, 16) : null;
                    $params['duplex'] = trim((string)($port['duplex'] ?? '')) !== '' ? mb_substr(trim((string)$port['duplex']), 0, 16) : null;
                }
                $this->executeInventoryWrite($insert, $params);
                $ponId = (int)$this->pdo->lastInsertId();
                $optics = is_array($port['optics'] ?? null) ? $port['optics'] : [];
                $num = static fn(mixed $v): ?float => (is_numeric($v) && is_finite((float)$v)) ? (float)$v : null;
                $this->executeInventoryWrite($moduleStmt, [
                    'uid' => $userId,
                    'pon' => $ponId,
                    'source' => $source,
                    'tx' => $num($port['tx_power'] ?? $optics['tx_power'] ?? null),
                    'temp' => $num($optics['temperature'] ?? null),
                    'volt' => $num($optics['voltage'] ?? null),
                    'cur' => $num($optics['current'] ?? null),
                    'mtype' => mb_substr($this->cleanModuleText($optics['module_type'] ?? ''), 0, 40),
                    'mjson' => json_encode($this->ponModuleJson($optics), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null,
                ]);
                $seen[$ref] = true;
                $written++;
            }

            if ($seen !== []) {
                $params = ['uid' => $userId, 'dev' => $oltDeviceId];
                $placeholders = [];
                $index = 0;
                foreach (array_keys($seen) as $ref) {
                    $name = 'ref' . $index;
                    $placeholders[] = ':' . $name;
                    $params[$name] = $ref;
                    $index++;
                }
                $stmt = $this->pdo->prepare(
                    "UPDATE {$table} SET is_present=0 WHERE user_id=:uid AND olt_device_id=:dev"
                    . ' AND port_ref NOT IN (' . implode(',', $placeholders) . ')'
                );
                $this->executeInventoryWrite($stmt, $params);
            }

            if ($started) $this->pdo->commit();
        } catch (Throwable $error) {
            if ($started && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }

        return $written;
    }

    
    public function saveOltCards(int $userId, int $oltDeviceId, array $cards): int
    {
        if ($userId < 1 || $oltDeviceId < 1 || $cards === []) return 0;

        $stmt = $this->pdo->prepare(
            'INSERT INTO olt_cards(user_id,olt_device_id,card_key,slot_number,entity_index,entity,model,description,'
            . 'serial_number,hardware_version,software_version,role,port_count,ports_json,versions_json,is_present,seen_at) '
            . 'VALUES(:uid,:dev,:key,:slot,:entity_index,:entity,:model,:descr,:serial,:hw,:sw,:role,:ports,:ports_json,:versions_json,:present,NOW()) '
            . 'ON DUPLICATE KEY UPDATE slot_number=VALUES(slot_number),entity_index=VALUES(entity_index),'
            . 'entity=VALUES(entity),model=VALUES(model),description=VALUES(description),'
            . 'serial_number=VALUES(serial_number),hardware_version=VALUES(hardware_version),'
            . 'software_version=VALUES(software_version),role=VALUES(role),port_count=VALUES(port_count),'
            . 'ports_json=VALUES(ports_json),versions_json=VALUES(versions_json),'
            . 'is_present=VALUES(is_present),seen_at=NOW()'
        );

        $started = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $started = true;
        }

        $written = 0;
        $seen = [];
        try {
            foreach ($cards as $card) {
                if (!is_array($card)) continue;
                $key = trim((string)($card['key'] ?? ''));
                if ($key === '') continue;
                $seen[$key] = true;
                $this->executeInventoryWrite($stmt, [
                    'uid' => $userId,
                    'dev' => $oltDeviceId,
                    'key' => mb_substr($key, 0, 60),
                    'slot' => (int)($card['slot'] ?? 0),
                    'entity_index' => (int)($card['entity_index'] ?? 0),
                    'entity' => mb_substr(trim((string)($card['entity'] ?? '')), 0, 30),
                    'model' => mb_substr(trim((string)($card['model'] ?? '')), 0, 120),
                    'descr' => mb_substr(trim((string)($card['descr'] ?? '')), 0, 191),
                    'serial' => mb_substr(trim((string)($card['serial'] ?? '')), 0, 120),
                    'hw' => mb_substr(trim((string)($card['hardware'] ?? '')), 0, 60),
                    'sw' => mb_substr(trim((string)($card['software'] ?? '')), 0, 60),
                    'role' => mb_substr(trim((string)($card['role'] ?? 'other')) ?: 'other', 0, 20),
                    'ports' => (int)($card['port_count'] ?? 0),
                    'ports_json' => is_array($card['ports'] ?? null) ? json_encode(array_values($card['ports']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                    'versions_json' => is_array($card['versions'] ?? null) ? json_encode(array_values($card['versions']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                    'present' => !empty($card['present']) ? 1 : 0,
                ]);
                $written++;
            }

            if ($seen !== []) {
                $params = ['uid' => $userId, 'dev' => $oltDeviceId];
                $placeholders = [];
                $index = 0;
                foreach (array_keys($seen) as $key) {
                    $name = 'k' . $index;
                    $placeholders[] = ':' . $name;
                    $params[$name] = $key;
                    $index++;
                }
                $mark = $this->pdo->prepare(
                    'UPDATE olt_cards SET is_present=0 WHERE user_id=:uid AND olt_device_id=:dev'
                    . ' AND card_key NOT IN (' . implode(',', $placeholders) . ')'
                );
                $this->executeInventoryWrite($mark, $params);
            }

            if ($started) $this->pdo->commit();
        } catch (Throwable $error) {
            if ($started && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }

        return $written;
    }

    
    
    public function deleteOltProfileSnapshot(int $userId, int $oltDeviceId, string $kind, string $name): bool
    {
        if ($userId < 1 || $oltDeviceId < 1) return false;
        $kind = mb_substr(trim($kind), 0, 24);
        $name = mb_substr(trim($name), 0, 128);
        if ($kind === '' || $name === '') return false;
        $stmt = $this->pdo->prepare(
            'DELETE FROM olt_profiles WHERE user_id=:uid AND olt_device_id=:dev AND kind=:kind AND name=:name'
        );
        $this->executeInventoryWrite($stmt, ['uid' => $userId, 'dev' => $oltDeviceId, 'kind' => $kind, 'name' => $name]);
        return $stmt->rowCount() > 0;
    }

    function saveOltProfileSnapshots(int $userId, int $oltDeviceId, array $rows, array $kinds = []): int
    {
        if ($userId < 1 || $oltDeviceId < 1) return 0;

        $covered = [];
        foreach ($kinds as $kind) {
            $kind = mb_substr(trim((string)$kind), 0, 24);
            if ($kind !== '') $covered[$kind] = true;
        }
        if ($covered === [] && $rows === []) return 0;

        $vlanRows = [];
        $onuTypeRows = [];
        foreach ($rows as $row) {
            if (is_array($row) && trim((string)($row['kind'] ?? '')) === 'vlan') $vlanRows[] = $row;
            if (is_array($row) && trim((string)($row['kind'] ?? '')) === 'onu_type') $onuTypeRows[] = $row;
        }
        $vlanCovered = isset($covered['vlan']) || $vlanRows !== [];
        $onuTypesCovered = isset($covered['onu_type']) || $onuTypeRows !== [];
        unset($covered['vlan']);
        unset($covered['onu_type']);
        $rows = array_values(array_filter($rows, static fn(mixed $row): bool => !is_array($row) || !in_array(trim((string)($row['kind'] ?? '')), ['vlan', 'onu_type'], true)));
        $vlanWritten = $vlanCovered ? $this->saveOltVlanDeviceSnapshot($userId, $oltDeviceId, $vlanRows) : 0;
        $onuTypesWritten = $onuTypesCovered ? $this->saveOltOnuTypeDeviceSnapshot($userId, $oltDeviceId, $onuTypeRows) : 0;
        if ($covered === [] && $rows === []) return $vlanWritten + $onuTypesWritten;

        $stmt = $this->pdo->prepare(
            'INSERT INTO olt_profiles(user_id,olt_device_id,kind,name,data_json,seen_at) '
            . 'VALUES(:uid,:dev,:kind,:name,:data,NOW()) '
            . 'ON DUPLICATE KEY UPDATE data_json=VALUES(data_json),seen_at=NOW()'
        );

        $started = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $started = true;
        }

        $written = 0;
        $seen = [];
        try {
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $kind = mb_substr(trim((string)($row['kind'] ?? '')), 0, 24);
                $name = mb_substr(trim((string)($row['name'] ?? '')), 0, 128);
                if ($kind === '' || $name === '') continue;
                $seen[$kind][$name] = true;
                $covered[$kind] = true;
                $this->executeInventoryWrite($stmt, [
                    'uid' => $userId,
                    'dev' => $oltDeviceId,
                    'kind' => $kind,
                    'name' => $name,
                    'data' => json_encode((array)($row['data'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
                ]);
                $written++;
            }

            foreach (array_keys($covered) as $kind) {
                $params = ['uid' => $userId, 'dev' => $oltDeviceId, 'kind' => (string)$kind];
                $placeholders = [];
                $index = 0;
                foreach (array_keys((array)($seen[$kind] ?? [])) as $name) {
                    $key = 'n' . $index;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $name;
                    $index++;
                }
                $sql = 'DELETE FROM olt_profiles WHERE user_id=:uid AND olt_device_id=:dev AND kind=:kind';
                if ($placeholders !== []) {
                    $sql .= ' AND name NOT IN (' . implode(',', $placeholders) . ')';
                }
                $this->pdo->prepare($sql)->execute($params);
            }

            if ($started) $this->pdo->commit();
        } catch (Throwable $error) {
            if ($started && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }

        return $written + $vlanWritten + $onuTypesWritten;
    }

    private function saveOltOnuTypeDeviceSnapshot(int $userId, int $oltDeviceId, array $rows): int
    {
        if (!is_array($this->getOltByRowId($userId, $oltDeviceId))) return 0;
        $upsert = $this->pdo->prepare(
            'INSERT INTO olt_onu_types(olt_device_id,name,speed,pon_type,description,fields_json) '
            . 'VALUES(:dev,:name,:speed,:pon,:description,:fields) '
            . 'ON DUPLICATE KEY UPDATE speed=VALUES(speed),pon_type=VALUES(pon_type),description=VALUES(description),fields_json=VALUES(fields_json)'
        );
        $seen = [];
        $written = 0;
        $started = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $started = true;
        }
        try {
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $name = mb_substr(trim((string)($row['name'] ?? '')), 0, 64);
                if ($name === '') continue;
                $data = is_array($row['data'] ?? null) ? $row['data'] : [];
                $pon = strtolower(trim((string)($data['pon_type'] ?? '')));
                $pon = in_array($pon, ['gpon', 'epon'], true) ? $pon : '';
                $speed = mb_substr(trim((string)($data['speed'] ?? '')), 0, 64);
                $description = mb_substr(trim((string)($data['description'] ?? '')), 0, 255);
                unset($data['name'], $data['speed'], $data['pon_type'], $data['description']);
                $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!is_string($encoded)) continue;
                $upsert->execute([
                    'dev' => $oltDeviceId, 'name' => $name, 'speed' => $speed,
                    'pon' => $pon, 'description' => $description, 'fields' => $encoded,
                ]);
                $seen[$name] = true;
                $written++;
            }

            $clearParams = ['dev' => $oltDeviceId];
            $clearSql = 'DELETE FROM olt_onu_types WHERE olt_device_id=:dev';
            if ($seen !== []) {
                $holders = [];
                foreach (array_keys($seen) as $index => $name) {
                    $key = 'n' . $index;
                    $holders[] = ':' . $key;
                    $clearParams[$key] = $name;
                }
                $clearSql .= ' AND name NOT IN (' . implode(',', $holders) . ')';
            }
            $this->pdo->prepare($clearSql)->execute($clearParams);
            if ($started) $this->pdo->commit();
        } catch (Throwable $error) {
            if ($started && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $written;
    }

    private function saveOltVlanDeviceSnapshot(int $userId, int $oltDeviceId, array $rows): int
    {
        if (!is_array($this->getOltByRowId($userId, $oltDeviceId))) return 0;
        $upsert = $this->pdo->prepare(
            'INSERT INTO olt_vlans(olt_device_id,vlan_number,name,related_ports) '
            . 'VALUES(:dev,:number,:name,:related) '
            . 'ON DUPLICATE KEY UPDATE name=VALUES(name),related_ports=VALUES(related_ports)'
        );
        $seen = [];
        $written = 0;
        $started = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $started = true;
        }
        try {
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $data = is_array($row['data'] ?? null) ? $row['data'] : [];
            $number = (int)($data['vlan_id'] ?? $row['name'] ?? 0);
            if ($number < 1 || $number > 4095) continue;
            $related = [
                'access' => array_values((array)($data['access'] ?? [])),
                'trunk' => array_values((array)($data['trunk'] ?? [])),
            ];
            $encoded = json_encode($related, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($encoded)) continue;
            $this->executeInventoryWrite($upsert, [
                'dev' => $oltDeviceId,
                'number' => $number,
                'name' => mb_substr(trim((string)($data['vlan_name'] ?? $data['name'] ?? '')), 0, 32),
                'related' => $encoded,
            ]);
            $seen[$number] = true;
            $written++;
        }

        $clearParams = ['dev' => $oltDeviceId];
        $clearSql = 'DELETE FROM olt_vlans WHERE olt_device_id=:dev';
        if ($seen !== []) {
            $holders = [];
            foreach (array_keys($seen) as $index => $number) {
                $key = 'v' . $index;
                $holders[] = ':' . $key;
                $clearParams[$key] = $number;
            }
            $clearSql .= ' AND vlan_number NOT IN (' . implode(',', $holders) . ')';
        }
        $this->pdo->prepare($clearSql)->execute($clearParams);
        if ($started) $this->pdo->commit();
        } catch (Throwable $error) {
            if ($started && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $written;
    }

    public function saveOltVlanCatalogRows(int $userId, int $oltDeviceId, array $rows): int
    {
        return $this->saveOltVlanDeviceSnapshot($userId, $oltDeviceId, $rows);
    }

    public function getOltProfileSnapshots(int $userId, int $oltDeviceId, ?string $kind = null): array
    {
        if ($userId < 1 || $oltDeviceId < 1) return [];

        $params = ['uid' => $userId, 'dev' => $oltDeviceId];
        $sql = 'SELECT kind,name,data_json,seen_at FROM olt_profiles WHERE user_id=:uid AND olt_device_id=:dev';
        $filter = $kind !== null ? trim($kind) : '';
        if ($filter !== '') {
            $sql .= ' AND kind=:kind';
            $params['kind'] = $filter;
        }
        $sql .= ' ORDER BY kind ASC,name ASC';

        $stmt = $this->pdo->prepare($sql);
        $this->executeInventoryWrite($stmt, $params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $data = json_decode((string)($row['data_json'] ?? ''), true);
            $out[] = [
                'kind' => (string)($row['kind'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'data' => is_array($data) ? $data : [],
                'seen_at' => $row['seen_at'] ?? null,
            ];
        }

        return $out;
    }

    public function saveOltPonTrafficSamples(int $userId, int $oltDeviceId, array $samples, string $sampledAt): int
    {
        return $this->saveGraphInterfaceSamples($userId, $oltDeviceId, $samples, $sampledAt);
    }

    public function pruneOltPonTrafficSamples(int $userId, int $oltDeviceId, int $keepSeconds = self::OLT_TRAFFIC_KEEP_SECONDS): int
    {
        return $this->pruneGraphInterfaceSamples($userId, $oltDeviceId);
    }

    public function getLatestOltPonTrafficSampleAt(int $userId, int $oltDeviceId): int
    {
        if ($userId < 1 || $oltDeviceId < 1) return 0;
        $stmt = $this->pdo->prepare(
            'SELECT UNIX_TIMESTAMP(MAX(sampled_at)) FROM graph_interface_samples WHERE user_id=:uid AND device_id=:dev'
        );
        $stmt->execute(['uid' => $userId, 'dev' => $oltDeviceId]);
        return max(0, (int)$stmt->fetchColumn());
    }

    public function saveOnuTrafficSamples(int $userId, int $oltDeviceId, int $onuId, string $ponRef, string $onuIndex, float $rxBps, float $txBps, string $sampledAt): void
    {
        if ($userId < 1 || $oltDeviceId < 1 || $onuId < 1) return;
        $sampledAt = trim($sampledAt);
        if ($sampledAt === '') return;
        $stmt = $this->pdo->prepare(
            'INSERT INTO graph_onu_samples(user_id,device_id,onu_id,port_ref,rx_bps,tx_bps,sampled_at) '
            . 'VALUES(:uid,:dev,:onu,:ref,:rx,:tx,:at) '
            . 'ON DUPLICATE KEY UPDATE rx_bps=VALUES(rx_bps),tx_bps=VALUES(tx_bps),port_ref=VALUES(port_ref)'
        );
        $this->executeInventoryWrite($stmt, [
            'uid' => $userId, 'dev' => $oltDeviceId, 'onu' => $onuId,
            'ref' => mb_substr($ponRef, 0, 60),
            'rx' => max(0.0, $rxBps), 'tx' => max(0.0, $txBps), 'at' => $sampledAt,
        ]);
        $this->pruneGraphOnuSamples($userId, $oltDeviceId, $onuId);
    }

    public function pruneOnuTrafficSamples(int $userId, int $oltDeviceId, int $keepSeconds = self::TRAFFIC_KEEP_SECONDS): void
    {
        if ($userId < 1 || $oltDeviceId < 1) return;
        $this->pruneGraphOnuSamples($userId, $oltDeviceId);
    }

    private function pruneGraphOnuSamples(int $userId, int $oltDeviceId, int $onuId = 0): void
    {
        $params = ['uid' => $userId, 'dev' => $oltDeviceId, 'limit' => self::GRAPH_SAMPLE_LIMIT];
        $filter = '';
        if ($onuId > 0) {
            $filter = ' AND onu_id=:onu';
            $params['onu'] = $onuId;
        }
        $stmt = $this->pdo->prepare(
            'DELETE s FROM graph_onu_samples s JOIN ('
            . ' SELECT id FROM ('
            . '  SELECT id, ROW_NUMBER() OVER (PARTITION BY onu_id ORDER BY sampled_at DESC, id DESC) AS rn'
            . '  FROM graph_onu_samples WHERE user_id=:uid AND device_id=:dev' . $filter
            . ' ) ranked WHERE ranked.rn > :limit'
            . ') old ON old.id=s.id'
        );
        $this->executeInventoryWrite($stmt, $params);
    }

    public function getOnuTrafficSeries(int $userId, int $oltDeviceId, int $onuId, int $rangeSeconds): array
    {
        $rangeSeconds = max(60, min(self::TRAFFIC_KEEP_SECONDS, $rangeSeconds));
        $empty = ['range_seconds' => $rangeSeconds, 'step' => 0, 'window_seconds' => 0, 'samples' => 0, 'points' => 0, 'series' => [], 'average' => []];
        if ($userId < 1 || $oltDeviceId < 1 || $onuId < 1) return $empty;
        $stmt = $this->pdo->prepare(
            'SELECT port_ref, UNIX_TIMESTAMP(sampled_at) AS point_slot, rx_bps, tx_bps FROM ('
            . ' SELECT port_ref, sampled_at, rx_bps, tx_bps,'
            . ' ROW_NUMBER() OVER (PARTITION BY onu_id ORDER BY sampled_at DESC, id DESC) AS rn'
            . ' FROM graph_onu_samples WHERE user_id=:uid AND device_id=:dev AND onu_id=:onu'
            . ') ranked WHERE rn <= :limit ORDER BY sampled_at ASC'
        );
        $this->executeInventoryWrite($stmt, ['uid' => $userId, 'dev' => $oltDeviceId, 'onu' => $onuId, 'limit' => self::GRAPH_SAMPLE_LIMIT]);
        $series = []; $points = 0; $ponRef = '';
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if ($ponRef === '') $ponRef = trim((string)($row['port_ref'] ?? ''));
            $series[] = ['at' => (int)($row['point_slot'] ?? 0), 'rx' => (float)($row['rx_bps'] ?? 0), 'tx' => (float)($row['tx_bps'] ?? 0)];
            $points++;
        }
        $average = ['rx_bps' => 0.0, 'tx_bps' => 0.0, 'points' => $points];
        if ($points > 0) {
            $average['rx_bps'] = array_sum(array_column($series, 'rx')) / $points;
            $average['tx_bps'] = array_sum(array_column($series, 'tx')) / $points;
        }
        $key = $ponRef !== '' ? $ponRef : (string)$onuId;
        return ['range_seconds' => $rangeSeconds, 'step' => 0, 'window_seconds' => 0, 'samples' => $points, 'points' => $points, 'series' => $points > 0 ? [$key => $series] : [], 'average' => $points > 0 ? [$key => $average] : [], 'pon_ref' => $ponRef, 'onu_index' => '']; 
    }

    public function getLatestOnuTrafficSample(int $userId, int $oltDeviceId, int $onuId): ?array
    {
        if ($userId < 1 || $oltDeviceId < 1 || $onuId < 1) return null;
        $stmt = $this->pdo->prepare(
            'SELECT rx_bps, tx_bps, UNIX_TIMESTAMP(sampled_at) AS sampled_at '
            . 'FROM graph_onu_samples WHERE user_id=:uid AND device_id=:dev AND onu_id=:onu '
            . 'ORDER BY sampled_at DESC LIMIT 1'
        );
        $this->executeInventoryWrite($stmt, ['uid' => $userId, 'dev' => $oltDeviceId, 'onu' => $onuId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || time() - (int)($row['sampled_at'] ?? 0) > 120) return null;
        return ['rx_bps' => (float)$row['rx_bps'], 'tx_bps' => (float)$row['tx_bps'], 'at' => (int)$row['sampled_at']];
    }

    
    public static function trafficSeriesBucket(int $rangeSeconds): int
    {
        return max(60, min(86400, $rangeSeconds));
    }

    public static function trafficSeriesStep(int $rangeSeconds): int
    {
        $expected = max(1, (int)ceil(max(60, $rangeSeconds) / self::TRAFFIC_SAMPLE_SECONDS));
        return max(1, (int)ceil($expected / 720));
    }

    public function getOltPonTrafficSeries(int $userId, int $oltDeviceId, int $rangeSeconds): array
    {
        $rangeSeconds = max(60, min(self::OLT_TRAFFIC_KEEP_SECONDS, $rangeSeconds));
        $empty = ['range_seconds' => $rangeSeconds, 'step' => 0, 'window_seconds' => 0, 'samples' => 0, 'points' => 0, 'series' => [], 'average' => []];
        if ($userId < 1 || $oltDeviceId < 1) return $empty;
        $stmt = $this->pdo->prepare(
            'SELECT port_ref, UNIX_TIMESTAMP(sampled_at) AS point_slot, rx_bps, tx_bps FROM ('
            . ' SELECT port_ref, sampled_at, rx_bps, tx_bps,'
            . ' ROW_NUMBER() OVER (PARTITION BY port_ref ORDER BY sampled_at DESC, id DESC) AS rn'
            . ' FROM graph_interface_samples WHERE user_id=:uid AND device_id=:dev'
            . ') ranked WHERE rn <= :limit ORDER BY port_ref ASC, sampled_at ASC'
        );
        $this->executeInventoryWrite($stmt, ['uid' => $userId, 'dev' => $oltDeviceId, 'limit' => self::GRAPH_SAMPLE_LIMIT]);

        $series = [];
        $points = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $ref = trim((string)($row['port_ref'] ?? ''));
            if ($ref === '') continue;
            $series[$ref][] = ['at' => (int)($row['point_slot'] ?? 0), 'rx' => (float)($row['rx_bps'] ?? 0), 'tx' => (float)($row['tx_bps'] ?? 0)];
            $points++;
        }
        if ($series === []) return $empty;
        $average = [];
        foreach ($series as $ref => $rows) {
            $count = count($rows);
            $average[$ref] = [
                'rx_bps' => $count > 0 ? array_sum(array_column($rows, 'rx')) / $count : 0.0,
                'tx_bps' => $count > 0 ? array_sum(array_column($rows, 'tx')) / $count : 0.0,
                'points' => $count,
            ];
        }
        $counts = array_map('count', $series);
        return [
            'range_seconds' => $rangeSeconds,
            'step' => 0,
            'window_seconds' => 0,
            'samples' => $counts !== [] ? max($counts) : 0,
            'points' => $points,
            'series' => $series,
            'average' => $average,
        ];
    }

    public function getOltCards(int $userId, int $oltDeviceId, bool $presentOnly = true): array
    {
        if ($userId < 1 || $oltDeviceId < 1) return [];
        $sql = 'SELECT id,card_key,slot_number,entity_index,entity,model,label,description,serial_number,hardware_version,'
            . 'software_version,role,port_count,ports_json,versions_json,cpu_percent,memory_percent,temperature,is_present,seen_at FROM olt_cards'
            . ' WHERE user_id=:uid AND olt_device_id=:dev';
        if ($presentOnly) $sql .= ' AND is_present=1';
        $sql .= ' ORDER BY slot_number,card_key';

        try {
            $stmt = $this->pdo->prepare($sql);
            $this->executeInventoryWrite($stmt, ['uid' => $userId, 'dev' => $oltDeviceId]);
            return $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            
            return [];
        }
    }

    public function updateOltCardTemperatures(int $userId, int $oltDeviceId, array $temperatures): int
    {
        if ($userId < 1 || $oltDeviceId < 1 || $temperatures === []) return 0;
        $stmt = $this->pdo->prepare('UPDATE olt_cards SET temperature=:temperature WHERE user_id=:uid AND olt_device_id=:dev AND slot_number=:slot AND is_present=1');
        $written = 0;
        foreach ($temperatures as $slot => $temperature) {
            if ((int)$slot < 1 || !is_numeric($temperature)) continue;
            $this->executeInventoryWrite($stmt, ['temperature' => (float)$temperature, 'uid' => $userId, 'dev' => $oltDeviceId, 'slot' => (int)$slot]);
            $written += $stmt->rowCount();
        }
        return $written;
    }

    
    public function saveOltCardLabel(int $userId, int $oltDeviceId, string $cardKey, string $label): bool
    {
        $cardKey = trim($cardKey);
        if ($userId < 1 || $oltDeviceId < 1 || $cardKey === '') return false;

        try {
            $stmt = $this->pdo->prepare(
                'UPDATE olt_cards SET label=:label,label_updated_at=NOW()'
                . ' WHERE user_id=:uid AND olt_device_id=:dev AND card_key=:key'
            );
            $this->executeInventoryWrite($stmt, [
                'label' => mb_substr(trim($label), 0, 80),
                'uid' => $userId,
                'dev' => $oltDeviceId,
                'key' => mb_substr($cardKey, 0, 60),
            ]);
            return $stmt->rowCount() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public function getOltPonFilterOptions(int $userId): array
    {
        if ($userId < 1) return [];
        $stmt = $this->pdo->prepare(
            "SELECT p.olt_device_id,p.port_ref,p.label,p.pon_number,p.description,p.link_status AS link_status,d.name AS olt_name
               FROM pons p
               INNER JOIN devices d
                       ON d.id=p.olt_device_id
                      AND d.user_id=p.user_id
                      AND d.device_type='olt'
              WHERE p.user_id=:uid AND p.is_present=1
              ORDER BY olt_name,pon_number,port_ref"
        );
        $this->executeInventoryWrite($stmt, ['uid' => $userId]);

        $options = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $deviceId = (int)($row['olt_device_id'] ?? 0);
            $identity = trim((string)($row['label'] ?? ''));
            if ($identity === '') $identity = trim((string)($row['port_ref'] ?? ''));
            $path = function_exists('onu_pon_filter_key')
                ? onu_pon_filter_key($identity)
                : strtolower($identity);
            if ($deviceId < 1 || $path === '') continue;

            $key = $deviceId . '|' . $path;
            if (isset($options[$key])) continue;
            $options[$key] = [
                'key' => $key,
                'path' => $path,
                'olt_name' => trim((string)($row['olt_name'] ?? '')),
                'description' => trim((string)($row['description'] ?? '')),
                'link_status' => trim((string)($row['link_status'] ?? '')),
            ];
        }

        return array_values($options);
    }
    public function getOltPons(int $userId, int $oltDeviceId, bool $presentOnly = true): array
    {
        if ($userId < 1 || $oltDeviceId < 1) return [];
        $table = $this->oltPonTable($userId, $oltDeviceId);
        $sql = "SELECT p.*,m.tx_power AS module_tx_power,m.temperature AS module_temperature,"
            . "m.voltage AS module_voltage,m.current AS module_current,m.module_type,m.module_json,m.source AS module_source "
            . "FROM {$table} p LEFT JOIN pon_modules m ON m.pon_id=p.id AND m.user_id=p.user_id "
            . 'WHERE p.user_id=:uid AND p.olt_device_id=:dev';
        if ($presentOnly) $sql .= ' AND p.is_present=1';
        $sql .= ' ORDER BY p.pon_number,p.port_ref';
        $stmt = $this->pdo->prepare($sql);
        $this->executeInventoryWrite($stmt, ['uid' => $userId, 'dev' => $oltDeviceId]);
        $rows = $stmt->fetchAll() ?: [];
        return array_map(fn(array $row): array => $this->oltPonRowToPort($row), $rows);
    }


    public function getOltPonSnapshot(int $userId, int $oltDeviceId, bool $presentOnly = true): array
    {
        $snapshot = [];
        foreach ($this->getOltPons($userId, $oltDeviceId, $presentOnly) as $storedPort) {
            $identity = trim((string)($storedPort['port_ref'] ?? ''));
            if ($identity === '') continue;

            $payload = is_array($storedPort) ? $storedPort : [];
            $payload['port_ref'] = $identity;
            if (trim((string)($payload['description'] ?? '')) === '') $payload['description'] = '';
            $snapshot[] = $payload;
        }
        return $snapshot;
    }

    private function normalizeOltPonPorts(array $ports): array
    {
        $normalized = [];
        foreach ($ports as $port) {
            if (!is_array($port)) continue;
            $portRef = trim((string)($port['port_ref'] ?? ''));
            $pon = trim((string)($port['pon'] ?? ''));
            $identity = $portRef !== '' ? $portRef : $pon;
            if ($identity === '') continue;

            $rawStatus = trim((string)($port['link_status'] ?? $port['status'] ?? ''));
            $statusKey = state_key($rawStatus);
            if (!in_array($statusKey, ['online', 'offline'], true)) continue;

            $optics = is_array($port['optics'] ?? null) ? $port['optics'] : [];
            $rawOltTxPower = array_key_exists('tx_power', $port)
                ? $port['tx_power']
                : ($optics['tx_power'] ?? null);
            $olt_txPower = $this->powerNumber($rawOltTxPower);
            if ($olt_txPower !== null && !is_finite($olt_txPower)) $olt_txPower = null;

            $ponNumber = 0;
            if (preg_match('~(?:_|/)(\d+)$~', $pon !== '' ? $pon : $portRef, $match)) {
                $ponNumber = (int)$match[1];
            }
            $normalized[$identity] = [
                'port_ref' => $portRef,
                'pon' => $pon,
                'pon_number' => $ponNumber,
                'port_type' => trim((string)($port['port_type'] ?? '')),
                'admin_state' => trim((string)($port['admin_state'] ?? $port['enable'] ?? '')),
                'link_status' => $statusKey === 'online' ? 'LinkUp' : 'LinkDown',
                'autoneg' => trim((string)($port['autoneg'] ?? '')),
                'speed' => trim((string)($port['speed'] ?? '')),
                'duplex' => trim((string)($port['duplex'] ?? '')),
                'flow_control' => trim((string)($port['flow_control'] ?? '')),
                'autoneg' => trim((string)($port['autoneg'] ?? '')),
                'speed' => trim((string)($port['speed'] ?? '')),
                'duplex' => trim((string)($port['duplex'] ?? '')),
                'optics' => [
                    'module_type' => trim((string)($optics['module_type'] ?? '')),
                    'vendor' => trim((string)($optics['vendor'] ?? '')),
                    'vendor_pn' => trim((string)($optics['vendor_pn'] ?? '')),
                    'vendor_sn' => trim((string)($optics['vendor_sn'] ?? '')),
                    'class' => trim((string)($optics['class'] ?? '')),
                    'connector' => trim((string)($optics['connector'] ?? '')),
                    'trans_distance' => trim((string)($optics['trans_distance'] ?? '')),
                    'version' => trim((string)($optics['version'] ?? '')),
                    'production_date' => trim((string)($optics['production_date'] ?? '')),
                    'wavelength' => $this->nullableFloat($optics['wavelength'] ?? null),
                    'temperature' => $this->nullableFloat($optics['temperature'] ?? null),
                    'voltage' => $this->nullableFloat($optics['voltage'] ?? null),
                    'current' => $this->nullableFloat($optics['current'] ?? null),
                    'rx_power' => $this->nullableFloat($optics['rx_power'] ?? null),
                    'tx_power' => $olt_txPower,
                ],
                'tx_power' => $olt_txPower,
                'tx_power_sampled' => array_key_exists('tx_power_sampled', $port)
                    ? !empty($port['tx_power_sampled'])
                    : (array_key_exists('tx_power', $port) || array_key_exists('tx_power', $optics)),
                
                'status_source' => trim((string)($port['status_source'] ?? '')),
                'optics_source' => trim((string)($port['optics_source'] ?? '')),
                'enable_intent' => trim((string)($port['enable_intent'] ?? '')),
                'enable_intent_at' => trim((string)($port['enable_intent_at'] ?? '')),
            ];
            $descriptionRaw = $port['description'] ?? $port['port_description'] ?? null;
            if (is_string($descriptionRaw)) {
                $normalized[$identity]['description'] = trim($descriptionRaw);
            }
        }
        ksort($normalized, SORT_NATURAL);
        return $normalized;
    }

    private function oltPonTxPowerChange(array $old, array $current): ?array
    {

        $oldPower = is_numeric($old['tx_power'] ?? null) ? (float)$old['tx_power'] : null;
        $newPower = is_numeric($current['tx_power'] ?? null) ? (float)$current['tx_power'] : null;
        if ($oldPower === null && $newPower === null) return null;
        $oldLastDetectedPower = $oldPower;
        if ($oldPower !== null && !is_finite($oldPower)) $oldPower = null;
        if ($newPower !== null && !is_finite($newPower)) $newPower = null;
        if ($oldLastDetectedPower !== null && !is_finite($oldLastDetectedPower)) $oldLastDetectedPower = null;

        if (
            $oldPower !== null
            && $newPower !== null
            && abs($newPower - $oldPower) >= self::OLT_PON_TX_POWER_THRESHOLD_DBM
        ) {
            return ['kind' => 'changed', 'old' => $oldPower, 'new' => $newPower];
        }

        $newLinkUp = state_key($current['link_status'] ?? null) === 'online';
        if (!$newLinkUp || $newPower !== null) return null;

        $oldLinkUp = state_key($old['link_status'] ?? null) === 'online';
        if ($oldPower !== null || !$oldLinkUp) {
            return ['kind' => 'missing', 'old' => $oldPower ?? $oldLastDetectedPower, 'new' => null];
        }

        return null;
    }

    private function confirmOltPonTxPowerChange(int $userId, string $entityKey, array $old, array $current): ?array
    {
        $powerText = static fn(?float $value): string => $value === null
            ? 'N/A'
            : rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
        $oldPower = is_numeric($old['tx_power'] ?? null) && is_finite((float)$old['tx_power'])
            ? (float)$old['tx_power'] : null;
        $newPower = is_numeric($current['tx_power'] ?? null) && is_finite((float)$current['tx_power'])
            ? (float)$current['tx_power'] : null;
        $initialChange = $this->oltPonTxPowerChange($old, $current);
        $tracker = $this->monitorFieldState(
            $userId, 'OLT', $entityKey, 'pon_tx_power', $powerText($oldPower),
            $initialChange === null ? null : $powerText($newPower)
        );
        if (($tracker['created'] ?? false) === true) return null;

        $confirmedText = (string)$tracker['confirmed_value'];
        $confirmedPower = is_numeric($confirmedText) ? (float)$confirmedText : null;
        $confirmedOld = $old;
        $confirmedOld['tx_power'] = $confirmedPower;
        $change = $this->oltPonTxPowerChange($confirmedOld, $current);
        if ($change === null) {
            if ((int)$tracker['candidate_count'] > 0) {
                $reset = $this->pdo->prepare(
                    'UPDATE sync_state SET candidate_value=NULL,candidate_count=0,candidate_since=NULL,updated_at=NOW() WHERE id=:id'
                );
                $reset->execute(['id'=>(int)$tracker['id']]);
            }
            return null;
        }

        $candidate = (string)($tracker['candidate_value'] ?? '');
        $sameDirection = $newPower === null
            ? $candidate === 'N/A'
            : is_numeric($candidate) && $confirmedPower !== null
                && (((float)$candidate - $confirmedPower) <=> 0) === (($newPower - $confirmedPower) <=> 0);
        $nextCount = $sameDirection ? (int)$tracker['candidate_count'] + 1 : 1;
        if ($nextCount < 2) {
            $pending = $this->pdo->prepare(
                'UPDATE sync_state SET candidate_value=:candidate,candidate_count=:candidate_count,'
                . 'candidate_since=IF(:reset=1,NOW(),COALESCE(candidate_since,NOW())),updated_at=NOW() WHERE id=:id'
            );
            $pending->execute([
                'candidate'=>$powerText($newPower),
                'candidate_count'=>$nextCount,
                'reset'=>$nextCount === 1 ? 1 : 0,
                'id'=>(int)$tracker['id'],
            ]);
            return null;
        }

        $confirm = $this->pdo->prepare(
            'UPDATE sync_state SET confirmed_value=:confirmed,candidate_value=NULL,candidate_count=0,'
            . 'candidate_since=NULL,confirmed_at=NOW(),updated_at=NOW() WHERE id=:id'
        );
        $confirm->execute(['confirmed'=>$powerText($newPower),'id'=>(int)$tracker['id']]);
        return $change;
    }


    public function saveOltPorts(int $userId, int $oltDeviceId, string $kind, array $ports): void
    {
        $kind = trim($kind);
        if ($kind === '') return;
        if ($kind === 'uplink') {
            $this->pdo->prepare('UPDATE uplink_port SET is_present=0 WHERE user_id=:uid AND olt_device_id=:oid')
                ->execute(['uid' => $userId, 'oid' => $oltDeviceId]);
            $stmt = $this->pdo->prepare(
                'INSERT INTO uplink_port(user_id,olt_device_id,port_ref,slot,port_number,port_type,label,if_index,admin_state,link_status,speed_mbps,description,duplex,negotiation,flow_control,connector,vlan_mode,pvid,tagged_vlan,untagged_vlan,is_present,seen_at) '
                . 'VALUES(:uid,:oid,:ref,:slot,:portnum,:porttype,:label,:ifindex,:admin,:link,:speed,:desc,:duplex,:nego,:flow,:conn,:vlanmode,:pvid,:tagged,:untagged,1,NOW())'
            );
            foreach (array_values($ports) as $port) {
                if (!is_array($port)) continue;
                $ref = trim((string)($port['port_ref'] ?? ''));
                if ($ref === '') continue;
                $config = is_array($port['config'] ?? null) ? $port['config'] : [];
                $vlan = is_array($port['vlan'] ?? null) ? $port['vlan'] : [];
                $admin = trim((string)($port['enable'] ?? $config['admin_state'] ?? ''));
                $slot = (int)($port['slot'] ?? 0);
                if ($slot < 1 && preg_match('~^(?:[a-z]+_)?\d+/(\d+)/~i', $ref, $slotMatch) === 1) $slot = (int)$slotMatch[1];
                $portNumber = $port['port_number'] ?? null;
                if ($portNumber === null && preg_match('~/(\d+)$~', $ref, $numMatch) === 1) $portNumber = (int)$numMatch[1];
                $this->executeInventoryWrite($stmt, [
                    'uid' => $userId,
                    'oid' => $oltDeviceId,
                    'ref' => mb_substr($ref, 0, 120),
                    'slot' => $slot,
                    'portnum' => $portNumber,
                    'porttype' => trim((string)($port['port_type'] ?? $port['type'] ?? '')),
                    'label' => trim((string)($port['label'] ?? $port['name'] ?? '')),
                    'ifindex' => $port['if_index'] ?? null,
                    'admin' => $admin,
                    'link' => trim((string)($port['link_status'] ?? '')),
                    'speed' => $port['speed_mbps'] ?? null,
                    'desc' => trim((string)($config['description'] ?? '')),
                    'duplex' => trim((string)($config['duplex'] ?? '')),
                    'nego' => trim((string)($config['negotiation'] ?? '')),
                    'flow' => trim((string)($config['flow_control'] ?? '')),
                    'conn' => trim((string)($config['medium'] ?? $config['connector'] ?? '')),
                    'vlanmode' => trim((string)($vlan['mode'] ?? '')),
                    'pvid' => $vlan['pvid'] ?? null,
                    'tagged' => trim((string)($vlan['tagged_vlan'] ?? '')),
                    'untagged' => trim((string)($vlan['untagged_vlan'] ?? '')),
                ]);
            }
            return;
        }
    }

    public function getOltPorts(int $userId, int $oltDeviceId, string $kind): array
    {
        $kind = trim($kind);
        if ($kind === 'uplink') {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM uplink_port WHERE user_id=:uid AND olt_device_id=:oid AND is_present=1 ORDER BY port_number,port_ref'
            );
            $this->executeInventoryWrite($stmt, ['uid' => $userId, 'oid' => $oltDeviceId]);
            $out = [];
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $out[] = [
                    'port_ref' => trim((string)($row['port_ref'] ?? '')),
                    'if_index' => $row['if_index'] ?? null,
                    'slot' => (int)($row['slot'] ?? 0),
                    'port_number' => $row['port_number'] ?? null,
                    'port_type' => trim((string)($row['port_type'] ?? '')),
                    'label' => trim((string)($row['label'] ?? '')),
                    'enable' => trim((string)($row['admin_state'] ?? '')),
                    'admin_state' => trim((string)($row['admin_state'] ?? '')),
                    'link_status' => trim((string)($row['link_status'] ?? '')),
                    'oper_state' => trim((string)($row['link_status'] ?? '')),
                    'speed_mbps' => $row['speed_mbps'] ?? null,
                    'description' => trim((string)($row['description'] ?? '')),
                    'seen_at' => $row['seen_at'] ?? null,
                    'config' => [
                        'admin_state' => trim((string)($row['admin_state'] ?? '')),
                        'description' => trim((string)($row['description'] ?? '')),
                        'duplex' => trim((string)($row['duplex'] ?? '')),
                        'negotiation' => trim((string)($row['negotiation'] ?? '')),
                        'flow_control' => trim((string)($row['flow_control'] ?? '')),
                        'medium' => trim((string)($row['connector'] ?? '')),
                    ],
                    'vlan' => [
                        'mode' => trim((string)($row['vlan_mode'] ?? '')),
                        'pvid' => $row['pvid'] ?? null,
                        'tagged_vlan' => trim((string)($row['tagged_vlan'] ?? '')),
                        'untagged_vlan' => trim((string)($row['untagged_vlan'] ?? '')),
                    ],
                ];
            }
            return $out;
        }
        return [];
    }

    public function getOltViewCacheSettings(): array
    {
        $stmt = $this->pdo->query(
            "SELECT setting_value FROM app_settings WHERE setting_key='olt_view_cache_ttl_minutes' LIMIT 1"
        );
        $value = $stmt->fetchColumn();
        $minutes = is_numeric($value) ? (int)$value : self::DEFAULT_OLT_VIEW_CACHE_TTL_MINUTES;
        return [
            'ttl_minutes' => max(
                self::MIN_OLT_VIEW_CACHE_TTL_MINUTES,
                min(self::MAX_OLT_VIEW_CACHE_TTL_MINUTES, $minutes)
            ),
        ];
    }

    public function saveOltViewCacheTtlMinutes(int $minutes): void
    {
        if ($minutes < self::MIN_OLT_VIEW_CACHE_TTL_MINUTES || $minutes > self::MAX_OLT_VIEW_CACHE_TTL_MINUTES) {
            throw new InvalidArgumentException(
                'Durasi cache View OLT harus antara '
                . self::MIN_OLT_VIEW_CACHE_TTL_MINUTES . ' sampai '
                . self::MAX_OLT_VIEW_CACHE_TTL_MINUTES . ' menit.'
            );
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO app_settings(setting_key,setting_value)
             VALUES ('olt_view_cache_ttl_minutes',:value)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
        );
        $this->executeInventoryWrite($stmt, ['value' => (string)$minutes]);
    }
}
