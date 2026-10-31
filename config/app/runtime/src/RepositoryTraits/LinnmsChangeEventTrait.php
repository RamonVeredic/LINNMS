<?php

declare(strict_types=1);

trait LinnmsChangeEventTrait
{
    private ?PDOStatement $notificationReadinessStatement = null;

    private function hasReadyNotificationChannel(int $userId): bool
    {
        $this->notificationReadinessStatement ??= $this->pdo->prepare(
            "SELECT 1 FROM users u
             LEFT JOIN user_settings te ON te.user_id=u.id AND te.setting_key='telegram_enabled'
             LEFT JOIN user_settings tt ON tt.user_id=u.id AND tt.setting_key='telegram_bot_token'
             LEFT JOIN user_settings tc ON tc.user_id=u.id AND tc.setting_key='telegram_chat_id'
             LEFT JOIN app_settings ts ON ts.setting_key='telegram_bot_enabled'
             LEFT JOIN user_settings we ON we.user_id=u.id AND we.setting_key='whatsapp_enabled'
             LEFT JOIN user_settings wp ON wp.user_id=u.id AND wp.setting_key='whatsapp_provider'
             LEFT JOIN user_settings wt ON wt.user_id=u.id AND wt.setting_key='whatsapp_target'
             LEFT JOIN user_settings wk ON wk.user_id=u.id AND wk.setting_key='whatsapp_token'
             LEFT JOIN user_settings wb ON wb.user_id=u.id AND wb.setting_key='whatsapp_base_url'
             LEFT JOIN app_settings ws ON ws.setting_key='whatsapp_notification_service_enabled'
             WHERE u.id=:uid AND u.is_active=1
               AND (
                   (te.setting_value='1' AND ts.setting_value='1'
                    AND TRIM(tt.setting_value)<>'' AND TRIM(tc.setting_value)<>'')
                   OR
                   (we.setting_value='1' AND ws.setting_value='1'
                    AND TRIM(wp.setting_value)<>'' AND TRIM(wt.setting_value)<>''
                    AND (TRIM(wk.setting_value)<>'' OR TRIM(wb.setting_value)<>''))
               )
             LIMIT 1"
        );
        $this->notificationReadinessStatement->execute(['uid' => $userId]);
        $ready = $this->notificationReadinessStatement->fetchColumn() !== false;
        $this->notificationReadinessStatement->closeCursor();
        return $ready;
    }

    private function refreshNotificationChannelReadiness(int $userId, bool $wasReady): void
    {
        if ($wasReady && $this->hasReadyNotificationChannel($userId)) return;

        $this->pdo->prepare(
            'UPDATE change_events SET notified_at=NOW(),notification_error=NULL '
            . 'WHERE user_id=:uid AND notified_at IS NULL AND notification_ready_at IS NOT NULL'
        )->execute(['uid' => $userId]);
    }

    public function getNotificationDeviceRows(int $userId): array
    {
        if ($userId < 1) return [];
        $stmt = $this->pdo->prepare(
            "SELECT id,name,device_type,source,olt_id FROM devices WHERE user_id=:uid
             ORDER BY FIELD(device_type,'olt','mikrotik','cisco_catalyst_2960s'),name,id"
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getNotificationSelectedDeviceIds(int $userId): ?array
    {
        if ($userId < 1) return [];
        $configured = $this->pdo->prepare(
            "SELECT setting_value FROM user_settings WHERE user_id=:uid AND setting_key='notify_device_selection_configured' LIMIT 1"
        );
        $configured->execute(['uid' => $userId]);
        if ((string)($configured->fetchColumn() ?: '') !== '1') return null;
        $stmt = $this->pdo->prepare('SELECT device_id FROM notification_device_targets WHERE user_id=:uid');
        $stmt->execute(['uid' => $userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public function saveNotificationSelectedDeviceIds(int $userId, array $deviceIds): void
    {
        if ($userId < 1) throw new InvalidArgumentException('Pengguna tidak valid.');
        $selected = [];
        foreach ($deviceIds as $value) {
            if (!is_scalar($value) || !ctype_digit((string)$value) || (int)$value < 1) {
                throw new InvalidArgumentException('Pilihan perangkat tidak valid.');
            }
            $selected[(int)$value] = true;
        }
        $valid = [];
        foreach ($this->getNotificationDeviceRows($userId) as $row) $valid[(int)$row['id']] = true;
        if (array_diff_key($selected, $valid) !== []) {
            throw new InvalidArgumentException('Ada perangkat yang bukan milik akun ini.');
        }

        $owned = !$this->pdo->inTransaction();
        if ($owned) $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM notification_device_targets WHERE user_id=:uid')->execute(['uid' => $userId]);
            $insert = $this->pdo->prepare('INSERT INTO notification_device_targets(user_id,device_id) VALUES(:uid,:device_id)');
            foreach (array_keys($selected) as $deviceId) $insert->execute(['uid' => $userId, 'device_id' => $deviceId]);
            $this->saveUserSetting($userId, 'notify_device_selection_configured', '1');
            if ($owned) $this->pdo->commit();
        } catch (Throwable $error) {
            if ($owned && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function ensureNotificationDeviceTarget(int $userId, int $deviceId): void
    {
        if ($userId < 1 || $deviceId < 1) return;
        $this->pdo->prepare(
            'INSERT IGNORE INTO notification_device_targets(user_id,device_id)
             SELECT :uid,id FROM devices WHERE id=:device_id AND user_id=:owner_id'
        )->execute(['uid' => $userId, 'device_id' => $deviceId, 'owner_id' => $userId]);
    }

    public function acquireNotificationLock(int $userId, int $timeout = 0): bool
    {
        if ($userId < 1) return false;
        $timeout = max(0, min(30, $timeout));
        $stmt = $this->pdo->prepare("SELECT GET_LOCK(:name, {$timeout})");
        $stmt->execute(['name' => 'linnms_notify_' . $userId]);
        return (int)$stmt->fetchColumn() === 1;
    }

    public function releaseNotificationLock(int $userId): void
    {
        if ($userId < 1) return;
        $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $stmt->execute(['name' => 'linnms_notify_' . $userId]);
    }

    public function getOnuStateHistory(int $userId, string $entityKey, int $limit = 12): array
    {
        $entityKey = trim($entityKey);
        if ($entityKey === '') return [];
        $limit = max(1, min(60, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT old_value, new_value, created_at
             FROM change_events
             WHERE user_id=:uid AND entity_type='ONU' AND field_name='onu_state' AND entity_key=:key
             ORDER BY id DESC
             LIMIT {$limit}"
        );
        $stmt->execute(['uid' => $userId, 'key' => $entityKey]);
        $rows = $stmt->fetchAll() ?: [];
        $history = [];
        $count = count($rows);
        foreach ($rows as $index => $row) {
            $at = strtotime((string)$row['created_at']);
            $previousAt = $index + 1 < $count ? strtotime((string)$rows[$index + 1]['created_at']) : null;
            $history[] = [
                'state' => (string)$row['new_value'],
                'previous_state' => (string)($row['old_value'] ?? ''),
                'at' => (string)$row['created_at'],
                'duration_seconds' => ($at !== false && $previousAt !== false && $previousAt !== null)
                    ? max(0, $at - $previousAt)
                    : null,
            ];
        }
        return $history;
    }

    public function saveNotificationPreferences(int $userId, array $data): void
    {
        $settings = [
            'notify_onu_state' => !empty($data['notify_onu_state']) ? 1 : 0,
            'notify_onu_signal' => !empty($data['notify_onu_signal']) ? 1 : 0,
            'notify_onu_problem' => !empty($data['notify_onu_problem']) ? 1 : 0,
            'notify_onu_details' => !empty($data['notify_onu_details']) ? 1 : 0,
            'notify_device_status' => !empty($data['notify_device_status']) ? 1 : 0,
            'notify_interface_changes' => !empty($data['notify_interface_changes']) ? 1 : 0,
            'notify_pppoe_status' => !empty($data['notify_pppoe_status']) ? 1 : 0,
            'signal_threshold_dbm' => number_format(self::clampOnuRxDetection($data['signal_threshold_dbm'] ?? null), 2, '.', ''),
        ];
        if (array_key_exists('problem_threshold', $data)) {
            $settings['onu_rx_problem_threshold_dbm'] = number_format(
                self::validateOnuRxProblemLimit($data['problem_threshold']), 2, '.', '');
        }
        $this->saveUserSettings($userId, $settings);
    }

    public function saveNotificationPreference(int $userId, string $settingKey, mixed $value): void
    {
        if (!in_array($settingKey, ['notify_onu_state','notify_onu_signal','notify_onu_problem','notify_onu_details','notify_device_status','notify_interface_changes','notify_pppoe_status','signal_threshold_dbm','onu_rx_problem_threshold_dbm'], true)) throw new InvalidArgumentException('Pengaturan notifikasi tidak valid.');

        if ($settingKey === 'signal_threshold_dbm') {
            $storedValue = number_format(self::clampOnuRxDetection($value), 2, '.', '');
        } elseif ($settingKey === 'onu_rx_problem_threshold_dbm') {
            $storedValue = number_format(self::validateOnuRxProblemLimit($value), 2, '.', '');
        } else {
            $storedValue = !empty($value) ? 1 : 0;
        }

        $this->saveUserSetting($userId, $settingKey, (string)$storedValue);
    }

    private function recentActivityFilter(int $userId, int $hours, string $search = '', string $device = ''): array
    {
        $params = ['uid' => $userId];
        $sql = "e.user_id=:uid
                  AND (
                    (e.entity_type='ONU' AND (
                        e.field_name IN ('onu_state','is_present','auth_mode','onu_registration','onu_name','onu_location','onu_edit','deleted','problem_offline','problem_rx_power','problem_rx_recovered')
                        OR (e.field_name='rx_power' AND e.old_value REGEXP '^-?[0-9]+([.][0-9]+)?$' AND e.new_value REGEXP '^-?[0-9]+([.][0-9]+)?$')
                    ))
                    OR (e.entity_type='INTERFACE' AND e.field_name='oper_status')
                    OR (e.entity_type='DEVICE' AND e.field_name='driver_status')
                    OR (e.entity_type='OLT' AND e.field_name IN ('driver_status','is_present','pon_link_status','pon_tx_power'))
                  )
                  AND e.created_at >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
                  AND (
                    e.entity_type<>'ONU' OR e.field_name<>'is_present' OR COALESCE(e.new_value,'')<>'1'
                    OR EXISTS (
                        SELECT 1 FROM onus found_onu
                        INNER JOIN devices found_olt ON found_olt.id=found_onu.olt_device_id
                        WHERE found_onu.user_id=e.user_id AND found_onu.onu_index=e.event_onu_index
                          AND found_onu.auth_confirmed_once=0
                          AND found_olt.device_type='olt' AND found_olt.olt_id=e.event_olt_id
                          AND (e.event_source IS NULL OR e.event_source='' OR found_olt.source=e.event_source)
                    )
                  )";

        $search = mb_substr(trim($search), 0, 100);
        if ($search !== '') {
            $params['activity_search'] = '%' . mb_strtolower($search) . '%';
            $params['activity_search_device'] = $params['activity_search'];
            $params['activity_search_onu_name'] = $params['activity_search'];
            $params['activity_search_onu_serial'] = $params['activity_search'];
            $params['activity_search_onu_location'] = $params['activity_search'];
            $labelFieldSets = [];
            foreach (self::RECENT_ACTIVITY_LABELS as $label => $labelFields) {
                if (mb_stripos($label, $search) === false) continue;
                $labelFieldSets[] = "e.field_name IN ('" . implode("','", $labelFields) . "')";
            }
            $labelClause = $labelFieldSets === [] ? '' : ' OR (' . implode(' OR ', $labelFieldSets) . ')';
            $sql .= " AND (
                LOWER(CONCAT_WS(' ',e.entity_type,e.entity_key,e.field_name,e.old_value,e.new_value,e.message,e.event_onu_index,e.event_onu_name,e.event_onu_location,e.event_onu_serial)) LIKE :activity_search
                OR EXISTS (
                    SELECT 1 FROM devices ad
                    WHERE ad.user_id=e.user_id AND LOWER(ad.name) LIKE :activity_search_device
                      AND (
                        (ad.device_type='olt' AND ad.olt_id=e.event_olt_id AND (e.event_source IS NULL OR e.event_source='' OR ad.source=e.event_source))
                        OR (ad.device_type<>'olt' AND (
                            e.entity_key=CONCAT('mikrotik|',ad.id) OR e.entity_key LIKE CONCAT('mikrotik|',ad.id,'|%')
                            OR e.entity_key=CONCAT('switch|',ad.id) OR e.entity_key LIKE CONCAT('switch|',ad.id,'|%')
                        ))
                      )
                )
                OR EXISTS (
                    SELECT 1 FROM onus sn
                    INNER JOIN devices sd ON sd.id=sn.olt_device_id
                    WHERE sn.user_id=e.user_id AND sn.onu_index=e.event_onu_index
                      AND sd.device_type='olt' AND sd.olt_id=e.event_olt_id
                      AND (e.event_source IS NULL OR e.event_source='' OR sd.source=e.event_source)
                      AND (
                        LOWER(COALESCE(sn.onu_name,'')) LIKE :activity_search_onu_name
                        OR LOWER(COALESCE(sn.onu_serial,'')) LIKE :activity_search_onu_serial
                        OR LOWER(COALESCE(sn.onu_location,'')) LIKE :activity_search_onu_location
                      )
                ){$labelClause}
            )";
        }

        $device = mb_substr(trim($device), 0, 100);
        if ($device !== '') {
            $params['activity_device'] = mb_strtolower($device);
            $sql .= " AND EXISTS (
                SELECT 1 FROM devices fd
                WHERE fd.user_id=e.user_id AND LOWER(fd.name)=:activity_device
                  AND (
                    (fd.device_type='olt' AND fd.olt_id=e.event_olt_id AND (e.event_source IS NULL OR e.event_source='' OR fd.source=e.event_source))
                    OR (fd.device_type<>'olt' AND (
                        e.entity_key=CONCAT('mikrotik|',fd.id) OR e.entity_key LIKE CONCAT('mikrotik|',fd.id,'|%')
                        OR e.entity_key=CONCAT('switch|',fd.id) OR e.entity_key LIKE CONCAT('switch|',fd.id,'|%')
                    ))
                  )
            )";
        }

        return ['sql' => $sql, 'params' => $params];
    }

    private function decorateChangeEvents(array $rows, int $userId): array
    {
        
        $snmpDeviceCache = [];
        $signalThreshold = effective_signal_threshold_dbm($this->getUserSettings($userId));

        $recoveryHistory = $this->changeEventRecoveryHistory($userId, $rows);

        
        $oltNameCache = [];
        $findOltName = function (int $oltId, string $source) use ($userId, &$oltNameCache): ?string {
            $cacheKey = $oltId . '|' . $source;
            if (array_key_exists($cacheKey, $oltNameCache)) return $oltNameCache[$cacheKey];
            $findOlt = $this->pdo->prepare(
                "SELECT name FROM devices
                 WHERE user_id=:uid
                   AND device_type='olt'
                   AND olt_id=:oid
                   AND (:source1='' OR source=:source2)
                 LIMIT 1"
            );
            $findOlt->execute([
                'uid' => $userId,
                'oid' => $oltId,
                'source1' => $source,
                'source2' => $source,
            ]);
            $name = $findOlt->fetchColumn();

            return $oltNameCache[$cacheKey] = ($name === false || $name === null || $name === '') ? null : (string)$name;
        };
        
        $onuLookupCache = [];
        $findOnuRow = function (int $oltId, string $onuIndex, string $source) use ($userId, &$onuLookupCache): ?array {
            $cacheKey = $oltId . '|' . $onuIndex . '|' . $source;
            if (array_key_exists($cacheKey, $onuLookupCache)) return $onuLookupCache[$cacheKey];
            $findOnu = $this->pdo->prepare(
                "SELECT d.source,n.onu_name,n.onu_location,n.onu_serial,n.rx_power FROM onus n
                 INNER JOIN devices d ON d.id=n.olt_device_id
                 WHERE n.user_id=:uid AND d.olt_id=:oid AND n.onu_index=:idx
                   AND (:source1='' OR d.source=:source2)
                 LIMIT 1"
            );
            $findOnu->execute([
                'uid' => $userId,
                'oid' => $oltId,
                'idx' => $onuIndex,
                'source1' => $source,
                'source2' => $source,
            ]);
            $row = $findOnu->fetch();

            return $onuLookupCache[$cacheKey] = (is_array($row) ? $row : null);
        };

        $oltTargets = [];
        $onuTargets = [];
        foreach ($rows as $row) {
            $identity = $this->applyLegacyChangeEventIdentity($row, $findOnuRow);
            $oltId = (int)($identity['event_olt_id'] ?? 0);
            if ($oltId < 1) continue;
            $source = trim((string)($identity['event_source'] ?? ''));
            $onuIndex = trim((string)($identity['event_onu_index'] ?? ''));
            $oltTargets[$oltId . '|' . $source] = ['oid' => $oltId, 'source' => $source];
            if ($onuIndex !== '') $onuTargets[$oltId . '|' . $onuIndex] = ['oid' => $oltId, 'index' => $onuIndex];
        }
        $this->warmChangeEventLookupCaches($userId, $oltTargets, $onuTargets, $oltNameCache, $onuLookupCache);
        foreach ($rows as &$row) {
            $row['signal_threshold_dbm'] = $signalThreshold;
            if (strtoupper((string)($row['entity_type'] ?? '')) === 'DEVICE') {
                $entityKey = trim((string)($row['entity_key'] ?? ''));
                if (preg_match('~^(?:mikrotik|switch)\|(\d+)$~', $entityKey, $match)) {
                    $deviceRowId = (int)$match[1];
                    if (!array_key_exists($deviceRowId, $snmpDeviceCache)) {
                        $snmpDeviceCache[$deviceRowId] = $this->getSnmpDevice($userId, $deviceRowId);
                    }
                    $snmpDevice = $snmpDeviceCache[$deviceRowId];
                    if (is_array($snmpDevice)) {
                        $row['snmp_device_name'] = $snmpDevice['name'] ?? null;
                        $row['snmp_device_type'] = $snmpDevice['device_type'] ?? null;
                    }
                }
            }

            if (strtoupper((string)($row['entity_type'] ?? '')) === 'INTERFACE') {
                $entityKey = trim((string)($row['entity_key'] ?? ''));
                if (preg_match('~^(?:mikrotik|switch)\|(\d+)\|(.+)$~', $entityKey, $match)) {
                    $deviceRowId = (int)$match[1];
                    $identity = (string)$match[2];
                    if (!array_key_exists($deviceRowId, $snmpDeviceCache)) {
                        $snmpDeviceCache[$deviceRowId] = $this->getSnmpDevice($userId, $deviceRowId);
                    }
                    $snmpDevice = $snmpDeviceCache[$deviceRowId];
                    if (is_array($snmpDevice)) {
                        $row['snmp_device_name'] = $snmpDevice['name'] ?? null;
                        $row['snmp_device_type'] = $snmpDevice['device_type'] ?? null;
                        $interfaces = json_decode((string)($snmpDevice['interfaces_json'] ?? ''), true);
                        if (is_array($interfaces)) {
                            foreach ($interfaces as $interface) {
                                if (!is_array($interface) || $this->snmpInterfaceIdentity($interface) !== $identity) continue;
                                $interfaceName = trim((string)($interface['name'] ?? ''));
                                if ($this->isPppoeInterface($interface)) {
                                    $interfaceName = trim($interfaceName, "<> \t\n\r\0\x0B");
                                    $interfaceName = preg_replace('/^pppoe[\s_-]*/i', '', $interfaceName) ?? $interfaceName;
                                }
                                $row['interface_name_display'] = $this->nullText($interfaceName);
                                break;
                            }
                        }
                    }
                }
                if (preg_match('/^(?:Interface|Pelanggan PPPoE)\s+(.+?)\s+pada\s+(MikroTik|Switch)\s+(.+?)\s+berubah\s+dari\s+/iu', (string)($row['message'] ?? ''), $messageMatch)) {
                    if ($this->nullText($row['interface_name_display'] ?? null) === null) {
                        $row['interface_name_display'] = $this->nullText($messageMatch[1]);
                    }
                    if ($this->nullText($row['snmp_device_name'] ?? null) === null) {
                        $row['snmp_device_name'] = $this->nullText($messageMatch[3]);
                    }
                }
            }

            if ((int)($row['event_olt_id'] ?? 0) < 1 || trim((string)($row['event_onu_index'] ?? '')) === '') {
                $row = $this->applyLegacyChangeEventIdentity($row, $findOnuRow);
            }
            if ((int)($row['event_olt_id'] ?? 0) > 0 && trim((string)($row['olt_name'] ?? '')) === '') {
                $row['olt_name'] = $findOltName((int)$row['event_olt_id'], trim((string)($row['event_source'] ?? '')));
            }
            if (
                strtoupper(trim((string)($row['entity_type'] ?? ''))) === 'ONU'
                && (int)($row['event_olt_id'] ?? 0) > 0
                && trim((string)($row['event_onu_index'] ?? '')) !== ''
                && (
                    $this->nullText($row['current_onu_name'] ?? null) === null
                    || $this->nullText($row['current_onu_location'] ?? null) === null
                    || $this->nullText($row['current_onu_serial'] ?? null) === null
                )
            ) {
                $currentOnu = $findOnuRow(
                    (int)$row['event_olt_id'],
                    (string)$row['event_onu_index'],
                    trim((string)($row['event_source'] ?? ''))
                );
                if (is_array($currentOnu)) {
                    $row['current_onu_name'] ??= $currentOnu['onu_name'] ?? null;
                    $row['current_onu_location'] ??= $currentOnu['onu_location'] ?? null;
                    $row['current_onu_serial'] ??= $currentOnu['onu_serial'] ?? null;
                    $row['current_onu_rx_power'] ??= $currentOnu['rx_power'] ?? null;
                }
            }
            $row['onu_name_display'] = $this->nullText($row['event_onu_name'] ?? null)
                ?? $this->nullText($row['current_onu_name'] ?? null)
                ?? ((string)($row['field_name'] ?? '') === 'deleted' ? $this->nullText($row['old_value'] ?? null) : null);
            $row['onu_location_display'] = $this->nullText($row['event_onu_location'] ?? null)
                ?? $this->nullText($row['current_onu_location'] ?? null);
            $row['onu_serial_display'] = $this->nullText($row['event_onu_serial'] ?? null)
                ?? $this->nullText($row['current_onu_serial'] ?? null);

            $entityType = strtoupper(trim((string)($row['entity_type'] ?? '')));
            $field = trim((string)($row['field_name'] ?? ''));
            $isRecovery = $this->isRecoveryChangeEvent($row);
            $row['recovery_within_six_hours'] = 0;
            $row['recovery_outage_seconds'] = null;

            if ($isRecovery && (int)($row['id'] ?? 0) > 0) {
                $rowId = (int)$row['id'];
                $historyKey = $entityType . '|' . trim((string)($row['entity_key'] ?? '')) . '|' . $field;
                $outageStartedAt = null;
                $history = $recoveryHistory[$historyKey] ?? null;
                $historyIds = is_array($history) ? $history['ids'] : [];

                $low = 0;
                $high = count($historyIds);
                while ($low < $high) {
                    $middle = intdiv($low + $high, 2);
                    if ((int)$historyIds[$middle] >= $rowId) $low = $middle + 1;
                    else $high = $middle;
                }
                for ($index = $low; $index < count($historyIds); $index++) {
                    $previous = $history['rows'][$index];
                    $previousKey = state_key($previous['new_value'] ?? null);
                    $isProblemState = in_array($entityType, ['INTERFACE', 'OLT'], true)
                        ? $previousKey === 'offline'
                        : in_array($previousKey, ['offline', 'loss', 'powerdown'], true);
                    if (!$isProblemState) break;
                    $candidate = strtotime((string)($previous['created_at'] ?? ''));
                    if ($candidate !== false) $outageStartedAt = $candidate;
                }

                $recoveredAt = strtotime((string)($row['created_at'] ?? ''));
                if ($outageStartedAt !== null && $recoveredAt !== false) {
                    $outageSeconds = max(0, $recoveredAt - $outageStartedAt);
                    $row['recovery_outage_seconds'] = $outageSeconds;
                    $row['recovery_within_six_hours'] = $outageSeconds < 21600 ? 1 : 0;
                }
            }
        }
        unset($row);
        return $rows;
    }

    private function changeEventRecoveryHistory(int $userId, array $rows): array
    {
        $targets = [];
        foreach ($rows as $row) {
            if (!$this->isRecoveryChangeEvent($row)) continue;
            $id = (int)($row['id'] ?? 0);
            $entityType = strtoupper(trim((string)($row['entity_type'] ?? '')));
            $entityKey = trim((string)($row['entity_key'] ?? ''));
            $field = trim((string)($row['field_name'] ?? ''));
            if ($id < 1 || $entityKey === '' || $field === '') continue;

            $mapKey = $entityType . '|' . $entityKey . '|' . $field;
            if (!isset($targets[$mapKey]) || $id > $targets[$mapKey]['id']) {
                $targets[$mapKey] = ['id' => $id, 'key' => $entityKey, 'field' => $field];
            }
        }
        if ($targets === []) return [];

        $keys = [];
        $fields = [];
        $maxId = 0;
        foreach ($targets as $target) {
            $keys[(string)$target['key']] = true;
            $fields[(string)$target['field']] = true;
            $maxId = max($maxId, (int)$target['id']);
        }
        if ($keys === [] || $fields === []) return [];

        $params = ['uid' => $userId, 'max_id' => $maxId];
        $keyPlaceholders = [];
        $index = 0;
        foreach (array_keys($keys) as $entityKey) {
            $keyPlaceholders[] = ':key' . $index;
            $params['key' . $index] = (string)$entityKey;
            $index++;
        }
        $fieldPlaceholders = [];
        $index = 0;
        foreach (array_keys($fields) as $field) {
            $fieldPlaceholders[] = ':field' . $index;
            $params['field' . $index] = (string)$field;
            $index++;
        }

        $limit = min(3000, max(300, count($targets) * 30));
        $stmt = $this->pdo->prepare(
            'SELECT id,entity_type,entity_key,field_name,new_value,created_at
             FROM change_events
             WHERE user_id=:uid AND id<:max_id
               AND entity_key IN (' . implode(',', $keyPlaceholders) . ')
               AND field_name IN (' . implode(',', $fieldPlaceholders) . ")
             ORDER BY id DESC
             LIMIT {$limit}"
        );
        $stmt->execute($params);

        
        $collected = [];
        foreach ($stmt->fetchAll() ?: [] as $previous) {
            $mapKey = strtoupper(trim((string)($previous['entity_type'] ?? ''))) . '|'
                . trim((string)($previous['entity_key'] ?? '')) . '|'
                . trim((string)($previous['field_name'] ?? ''));
            if (!isset($targets[$mapKey])) continue;
            $collected[$mapKey][] = [
                'id' => (int)($previous['id'] ?? 0),
                'new_value' => $previous['new_value'] ?? null,
                'created_at' => $previous['created_at'] ?? null,
            ];
        }

        $history = [];
        foreach ($collected as $mapKey => $previousRows) {
            $history[$mapKey] = [
                'ids' => array_column($previousRows, 'id'),
                'rows' => $previousRows,
            ];
        }

        return $history;
    }

    private function applyLegacyChangeEventIdentity(array $row, callable $findOnuRow): array
    {
        $entity = trim((string)($row['entity_key'] ?? ''));
        $rowEntityType = strtoupper(trim((string)($row['entity_type'] ?? '')));
        if ($rowEntityType === 'OLT' && preg_match('~^(\d+)$~', $entity, $m)) {
            $row['event_source'] = 'api';
            $row['event_olt_id'] = (int)$m[1];
        } elseif ($rowEntityType === 'ONU' && preg_match('~^api\|(\d+)\|(.+)$~', $entity, $m)) {
            $row['event_source'] = $m[1];
            $row['event_olt_id'] = (int)$m[2];
            $row['event_onu_index'] = $m[3];
        } elseif (preg_match('~^(\d+)/(0/1/\d+:\d+)$~', $entity, $m)) {
            $row['event_olt_id'] = (int)$m[1];
            $row['event_onu_index'] = $m[2];
            $legacyOnu = $findOnuRow((int)$m[1], (string)$m[2], '');
            if (is_array($legacyOnu)) {
                $row['event_source'] = $legacyOnu['source'] ?? null;
                $row['current_onu_name'] = $legacyOnu['onu_name'] ?? null;
                $row['current_onu_location'] = $legacyOnu['onu_location'] ?? null;
                $row['current_onu_serial'] = $legacyOnu['onu_serial'] ?? null;
                $row['current_onu_rx_power'] = $legacyOnu['rx_power'] ?? null;
            }
        }

        return $row;
    }

    private function warmChangeEventLookupCaches(
        int $userId,
        array $oltTargets,
        array $onuTargets,
        array &$oltNameCache,
        array &$onuLookupCache
    ): void {
        if ($oltTargets === [] && $onuTargets === []) return;

        $oltIds = [];
        foreach ($oltTargets as $target) $oltIds[(int)$target['oid']] = true;
        foreach ($onuTargets as $target) $oltIds[(int)$target['oid']] = true;
        if ($oltIds === []) return;

        $params = ['uid' => $userId];
        $placeholders = [];
        $index = 0;
        foreach (array_keys($oltIds) as $oltId) {
            $placeholders[] = ':oid' . $index;
            $params['oid' . $index] = (int)$oltId;
            $index++;
        }
        $inList = implode(',', $placeholders);

        $stmt = $this->pdo->prepare(
            "SELECT olt_id,source,name FROM devices
             WHERE user_id=:uid AND device_type='olt' AND olt_id IN ({$inList})"
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll() ?: [] as $device) {
            $oltId = (int)($device['olt_id'] ?? 0);
            if ($oltId < 1) continue;
            $source = trim((string)($device['source'] ?? ''));
            $name = trim((string)($device['name'] ?? ''));
            $value = $name === '' ? null : $name;
            $oltNameCache[$oltId . '|' . $source] = $value;
            if (!array_key_exists($oltId . '|', $oltNameCache)) $oltNameCache[$oltId . '|'] = $value;
        }

        if ($onuTargets === []) return;

        $indices = [];
        foreach ($onuTargets as $target) $indices[(string)$target['index']] = true;
        $indexPlaceholders = [];
        $index = 0;
        foreach (array_keys($indices) as $onuIndex) {
            $indexPlaceholders[] = ':idx' . $index;
            $params['idx' . $index] = (string)$onuIndex;
            $index++;
        }

        $stmt = $this->pdo->prepare(
            "SELECT d.olt_id,d.source,n.onu_index,n.onu_name,n.onu_location,n.onu_serial,n.rx_power
             FROM onus n
             INNER JOIN devices d ON d.id=n.olt_device_id
             WHERE n.user_id=:uid AND d.olt_id IN ({$inList})
               AND n.onu_index IN (" . implode(',', $indexPlaceholders) . ")"
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll() ?: [] as $onu) {
            $oltId = (int)($onu['olt_id'] ?? 0);
            $onuIndex = trim((string)($onu['onu_index'] ?? ''));
            if ($oltId < 1 || $onuIndex === '') continue;
            $source = trim((string)($onu['source'] ?? ''));
            $detail = [
                'source' => $onu['source'] ?? null,
                'onu_name' => $onu['onu_name'] ?? null,
                'onu_location' => $onu['onu_location'] ?? null,
                'onu_serial' => $onu['onu_serial'] ?? null,
                'rx_power' => $onu['rx_power'] ?? null,
            ];
            $key = $oltId . '|' . $onuIndex;
            $onuLookupCache[$key . '|' . $source] = $detail;
            if (!array_key_exists($key . '|', $onuLookupCache)) $onuLookupCache[$key . '|'] = $detail;
        }
    }

    private function isRecoveryChangeEvent(array $row): bool
    {
        if (state_key($row['new_value'] ?? null) !== 'online') return false;
        $entityType = strtoupper(trim((string)($row['entity_type'] ?? '')));
        $field = trim((string)($row['field_name'] ?? ''));

        return ($entityType === 'INTERFACE' && $field === 'oper_status')
            || ($entityType === 'ONU' && $field === 'onu_state')
            || ($entityType === 'OLT' && $field === 'pon_link_status');
    }

    private function insertChangeEvent(int $userId, string $entityType, string $entityKey, string $field, mixed $old, mixed $new, string $message, array $context = []): array
    {
        $details = is_array($context['details'] ?? null) ? $context['details'] : [];
        $detailsJson = $details !== [] ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null;
        $notificationReadyAt = $this->hasReadyNotificationChannel($userId) ? date('Y-m-d H:i:s') : null;
        $stmt = $this->pdo->prepare('INSERT INTO change_events(user_id,entity_type,entity_key,field_name,old_value,new_value,message,event_source,event_olt_id,event_onu_index,event_onu_name,event_onu_serial,event_onu_location,event_details_json,notification_ready_at,notified_at) VALUES (:uid,:type,:key,:field,:old,:new,:message,:source,:olt_id,:onu_index,:onu_name,:onu_serial,:onu_location,:details,:notification_ready_at,:notified_at)');
        $stmt->execute([
            'uid'=>$userId,'type'=>$entityType,'key'=>$entityKey,'field'=>$field,
            'old'=>$this->eventValue($old),'new'=>$this->eventValue($new),'message'=>$message,
            'source'=>$this->nullText($context['source'] ?? null),
            'olt_id'=>$this->positiveInt($context['olt_id'] ?? null),
            'onu_index'=>$this->nullText($context['onu_index'] ?? null),
            'onu_name'=>$this->nullText($context['onu_name'] ?? null),
            'onu_serial'=>$this->nullText($context['onu_serial'] ?? null),
            'onu_location'=>$this->nullText($context['onu_location'] ?? null),
            'details'=>$detailsJson,
            'notification_ready_at'=>$notificationReadyAt,
            'notified_at'=>null,
        ]);
        return [
            'id'=>(int)$this->pdo->lastInsertId(),'user_id'=>$userId,'entity_type'=>$entityType,'entity_key'=>$entityKey,
            'field_name'=>$field,'old_value'=>$this->eventValue($old),'new_value'=>$this->eventValue($new),'message'=>$message,
            'event_source'=>$this->nullText($context['source'] ?? null),'event_olt_id'=>$this->positiveInt($context['olt_id'] ?? null),
            'event_onu_index'=>$this->nullText($context['onu_index'] ?? null),'event_onu_name'=>$this->nullText($context['onu_name'] ?? null),
            'event_onu_serial'=>$this->nullText($context['onu_serial'] ?? null),'event_onu_location'=>$this->nullText($context['onu_location'] ?? null),
            'event_details_json'=>$detailsJson,
        ];
    }

    private function changeEventOnuIdentity(array $event): string
    {
        $source = trim((string)($event['event_source'] ?? ''));
        $oltId = (int)($event['event_olt_id'] ?? 0);
        $index = trim((string)($event['event_onu_index'] ?? ''));
        if ($source !== '' && $oltId > 0 && $index !== '') return $this->onuIdentity($source, $oltId, $index);

        $entity = trim((string)($event['entity_key'] ?? ''));
        if (preg_match('~^(api)\\|(\\d+)\\|(.+)$~', $entity, $m)) {
            return $this->onuIdentity($m[1], (int)$m[2], $m[3]);
        }
        if (preg_match('~^(\\d+)/(0/1/\\d+:\\d+)$~', $entity, $m)) {
            $find = $this->pdo->prepare("SELECT d.source FROM onus n INNER JOIN devices d ON d.id=n.olt_device_id WHERE n.user_id=:uid AND d.olt_id=:oid AND n.onu_index=:idx LIMIT 1");
            $find->execute(['uid'=>(int)($event['user_id'] ?? 0),'oid'=>(int)$m[1],'idx'=>$m[2]]);
            $legacySource = trim((string)$find->fetchColumn());
            return $this->onuIdentity($legacySource !== '' ? $legacySource : 'api', (int)$m[1], $m[2]);
        }
        return '';
    }

    public function getPendingChangeEvents(int $userId, int $limit = 100, int $retryAfterMinutes = 5): array
    {
        $limit = max(1, min(500, $limit));
        $retryAfterMinutes = max(1, min(1440, $retryAfterMinutes));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM change_events
             WHERE user_id=:uid
               AND notified_at IS NULL
               AND notification_ready_at IS NOT NULL
               AND (
                    notification_attempts=0
                    OR last_notification_attempt_at IS NULL
                    OR last_notification_attempt_at<=DATE_SUB(NOW(), INTERVAL {$retryAfterMinutes} MINUTE)
               )
             ORDER BY created_at DESC,id DESC
             LIMIT {$limit}"
        );
        $stmt->execute(['uid' => $userId]);
        return $this->decorateChangeEvents($stmt->fetchAll() ?: [], $userId);
    }

    public function getOnuSignalHistory(int $userId, string $entityKey, int $limit = 6): array
    {
        $entityKey = trim($entityKey);
        if ($entityKey === '') return [];
        $limit = max(1, min(60, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT field_name, old_value, new_value, created_at
             FROM change_events
             WHERE user_id=:uid AND entity_type='ONU' AND entity_key=:key
               AND field_name='problem_rx_recovered'
             ORDER BY id DESC
             LIMIT {$limit}"
        );
        $stmt->execute(['uid' => $userId, 'key' => $entityKey]);
        return $stmt->fetchAll() ?: [];
    }

    public function getChangeEvents(int $userId, int $limit = 100, int $olderThanHours = 0): array
    {
        return $this->getChangeEventsPage($userId, $limit, 0, $olderThanHours);
    }

    public function getChangeEventsPage(int $userId, int $limit = 50, int $offset = 0, int $olderThanHours = 0): array
    {
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);
        $olderThanHours = $this->normalizeChangeEventAge($olderThanHours);
        $ageCondition = $olderThanHours > 0
            ? " AND created_at < DATE_SUB(NOW(), INTERVAL {$olderThanHours} HOUR)"
            : '';
        $stmt = $this->pdo->prepare(
            "SELECT * FROM change_events
             WHERE user_id=:uid{$ageCondition}
             ORDER BY created_at DESC,id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute(['uid'=>$userId]);
        return $this->decorateChangeEvents($stmt->fetchAll() ?: [], $userId);
    }

    public function countChangeEvents(int $userId, int $olderThanHours = 0): int
    {
        $olderThanHours = $this->normalizeChangeEventAge($olderThanHours);
        $ageCondition = $olderThanHours > 0
            ? " AND created_at < DATE_SUB(NOW(), INTERVAL {$olderThanHours} HOUR)"
            : '';
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM change_events WHERE user_id=:uid{$ageCondition}");
        $stmt->execute(['uid'=>$userId]);
        return (int)$stmt->fetchColumn();
    }

    private function normalizeChangeEventAge(int $olderThanHours): int
    {
        return max(0, min(8760, $olderThanHours));
    }

    public function markEventsNotified(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval',$ids), static fn(int $id): bool => $id > 0));
        if ($ids === []) return;
        $placeholders = implode(',', array_fill(0,count($ids),'?'));
        $this->pdo->prepare("UPDATE change_events SET notified_at=NOW(),notification_error=NULL WHERE id IN ({$placeholders})")->execute($ids);
    }

    public function markEventsNotificationError(array $ids, string $error): void
    {
        $ids = array_values(array_filter(array_map('intval',$ids), static fn(int $id): bool => $id > 0));
        if ($ids === []) return;
        $placeholders = implode(',', array_fill(0,count($ids),'?'));
        $params = array_merge([$error],$ids);
        $this->pdo->prepare("UPDATE change_events SET notification_error=?,notification_attempts=notification_attempts+1,last_notification_attempt_at=NOW() WHERE id IN ({$placeholders})")->execute($params);
    }

    public function markEventsNotificationSkipped(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval',$ids), static fn(int $id): bool => $id > 0));
        if ($ids === []) return;
        $placeholders = implode(',', array_fill(0,count($ids),'?'));
        $this->pdo->prepare("UPDATE change_events SET notified_at=NOW(),notification_error=NULL WHERE id IN ({$placeholders})")->execute($ids);
    }

    private function eventValue(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (is_bool($value)) return $value ? '1' : '0';
        if (is_scalar($value)) return (string) $value;
        $json = json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }
}
