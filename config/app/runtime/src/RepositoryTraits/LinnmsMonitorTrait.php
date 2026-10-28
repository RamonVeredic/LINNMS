<?php

declare(strict_types=1);

trait LinnmsMonitorTrait
{
    private function paceDatabaseWrites(int $processed): void
    {
        $batchSize = max(1, (int)(getenv('LINNMS_DB_WRITE_BATCH_SIZE') ?: 20));
        if ($processed <= 0 || $processed % $batchSize !== 0) return;

        $pauseUs = max(0, min(2_000_000, (int)(getenv('LINNMS_DB_WRITE_PAUSE_US') ?: 125_000)));
        if ($pauseUs > 0) usleep($pauseUs);
    }

     private function monitorFieldConfirmedValue(int $userId, string $entityType, string $entityKey, string $field): mixed
    {
        if ($userId < 1 || trim($entityKey) === '' || trim($field) === '') return null;
        $stmt = $this->pdo->prepare(
            'SELECT state.confirmed_value FROM sync_state state '
            . 'INNER JOIN sync_entitas entity ON entity.id=state.monitor_entity_id '
            . 'WHERE entity.user_id=:uid AND entity.entity_type=:entity_type AND entity.entity_key=:entity_key '
            . 'AND state.field_name=:field LIMIT 1'
        );
        $stmt->execute(['uid'=>$userId,'entity_type'=>$entityType,'entity_key'=>$entityKey,'field'=>$field]);
        $value = $stmt->fetchColumn();
        if ($value === false) return null;
        return $value;
    }

    private function resetScanConfirmationCandidate(int $userId, string $entityKey, string $field): void
    {
        if ($userId < 1 || trim($entityKey) === '' || trim($field) === '') return;
        $reset = $this->pdo->prepare(
            'UPDATE sync_state state '
            . 'INNER JOIN sync_entitas entity ON entity.id=state.monitor_entity_id '
            . 'SET state.candidate_value=NULL,state.candidate_count=0,state.candidate_since=NULL,state.updated_at=NOW() '
            . 'WHERE entity.user_id=:uid AND entity.entity_type=\'ONU\' AND entity.entity_key=:entity_key '
            . 'AND state.field_name=:field AND (state.candidate_value IS NOT NULL OR state.candidate_count>0)'
        );
        $reset->execute(['uid'=>$userId,'entity_key'=>$entityKey,'field'=>$field]);
    }

    private function monitorEntityId(int $userId, string $entityType, string $entityKey): int
    {
        $entityType = mb_substr(strtoupper(trim($entityType)), 0, 20);
        $entityKey = mb_substr(trim($entityKey), 0, 300);
        if ($userId < 1 || $entityType === '' || $entityKey === '') {
            throw new InvalidArgumentException('Identitas entitas monitoring tidak lengkap.');
        }
        $cacheKey = $userId . "\0" . $entityType . "\0" . $entityKey;
        if (isset($this->monitorEntityIds[$cacheKey])) {
            $cached = $this->pdo->prepare('SELECT device_id,onu_id FROM sync_entitas WHERE id=:id AND user_id=:uid LIMIT 1');
            $cached->execute(['id' => $this->monitorEntityIds[$cacheKey], 'uid' => $userId]);
            $current = $cached->fetch(PDO::FETCH_ASSOC);
            if (is_array($current)
                && (!in_array($entityType, ['DEVICE', 'INTERFACE', 'OLT', 'ONU'], true) || $current['device_id'] !== null)
                && ($entityType !== 'ONU' || $current['onu_id'] !== null)) {
                return $this->monitorEntityIds[$cacheKey];
            }
            unset($this->monitorEntityIds[$cacheKey]);
        }

        $find = $this->pdo->prepare(
            'SELECT id,device_id,onu_id FROM sync_entitas '
            . 'WHERE user_id=:uid AND entity_type=:type AND entity_key=:entity_key LIMIT 1'
        );
        $find->execute(['uid'=>$userId,'type'=>$entityType,'entity_key'=>$entityKey]);
        $existing = $find->fetch();
        if (is_array($existing)) {
            $existingId = (int)$existing['id'];
            $needsDeviceId = in_array($entityType, ['DEVICE', 'INTERFACE', 'OLT', 'ONU'], true);
            if (($needsDeviceId && $existing['device_id'] === null)
                || ($entityType === 'ONU' && $existing['onu_id'] === null)) {
                [$resolvedDeviceId, $resolvedOnuId] = $this->resolveMonitorInternalIds($userId, $entityType, $entityKey);
                if ($resolvedDeviceId !== null || $resolvedOnuId !== null) {
                    $attach = $this->pdo->prepare(
                        'UPDATE sync_entitas SET device_id=COALESCE(device_id,:device_id),onu_id=COALESCE(onu_id,:onu_id) WHERE id=:id'
                    );
                    $attach->execute(['device_id'=>$resolvedDeviceId,'onu_id'=>$resolvedOnuId,'id'=>$existingId]);
                }
                $existing['device_id'] ??= $resolvedDeviceId;
                $existing['onu_id'] ??= $resolvedOnuId;
            }
            if ((!$needsDeviceId || $existing['device_id'] !== null)
                && ($entityType !== 'ONU' || $existing['onu_id'] !== null)) {
                $this->monitorEntityIds[$cacheKey] = $existingId;
            }
            return $existingId;
        }

        [$deviceId, $onuId] = $this->resolveMonitorInternalIds($userId, $entityType, $entityKey);
        $insert = $this->pdo->prepare(
            'INSERT INTO sync_entitas(user_id,entity_type,entity_key,device_id,onu_id) '
            . 'VALUES (:uid,:type,:entity_key,:device_id,:onu_id) '
            . 'ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),'
            . 'device_id=COALESCE(VALUES(device_id),device_id),onu_id=COALESCE(VALUES(onu_id),onu_id)'
        );
        $insert->execute([
            'uid'=>$userId,'type'=>$entityType,'entity_key'=>$entityKey,
            'device_id'=>$deviceId,'onu_id'=>$onuId,
        ]);
        $id = (int)$this->pdo->lastInsertId();
        if ($id < 1) {
            $find->execute(['uid'=>$userId,'type'=>$entityType,'entity_key'=>$entityKey]);
            $row = $find->fetch();
            $id = is_array($row) ? (int)$row['id'] : 0;
        }
        if ($id < 1) throw new RuntimeException('Entitas monitoring gagal dibuat.');
        if ((!in_array($entityType, ['DEVICE', 'INTERFACE', 'OLT', 'ONU'], true) || $deviceId !== null)
            && ($entityType !== 'ONU' || $onuId !== null)) $this->monitorEntityIds[$cacheKey] = $id;
        return $id;
    }

    private function forgetMonitorEntityCache(int $userId, string $entityKey, bool $prefix): void
    {
        $needle = $userId . "\0";
        foreach (array_keys($this->monitorEntityIds) as $cacheKey) {
            if (!str_starts_with($cacheKey, $needle)) continue;
            $separator = strrpos($cacheKey, "\0");
            $cachedEntityKey = $separator === false ? '' : substr($cacheKey, $separator + 1);
            if (($prefix && str_starts_with($cachedEntityKey, $entityKey)) || (!$prefix && $cachedEntityKey === $entityKey)) {
                unset($this->monitorEntityIds[$cacheKey]);
            }
        }
    }

    private function resolveMonitorInternalIds(int $userId, string $entityType, string $entityKey): array
    {
        $parts = explode('|', $entityKey);
        $source = strtolower(trim((string)($parts[0] ?? '')));
        $externalId = isset($parts[1]) && ctype_digit((string)$parts[1]) ? (int)$parts[1] : 0;
        $deviceId = null;
        $onuId = null;

        if (in_array($entityType, ['DEVICE','INTERFACE'], true) && $externalId > 0) {
            $stmt = $this->pdo->prepare('SELECT id FROM devices WHERE id=:id AND user_id=:uid LIMIT 1');
            $stmt->execute(['id'=>$externalId,'uid'=>$userId]);
            $deviceId = ($value = (int)($stmt->fetchColumn() ?: 0)) > 0 ? $value : null;
        } elseif (in_array($entityType, ['OLT','ONU'], true) && $externalId > 0) {
            // CLI is a collection method for an SNMP OLT, not a devices.source value.
            $deviceSource = $source === 'cli' ? 'snmp' : $source;
            $stmt = $this->pdo->prepare(
                'SELECT id FROM devices WHERE user_id=:uid AND device_type=\'olt\' AND source=:source AND olt_id=:olt_id LIMIT 1'
            );
            $stmt->execute(['uid'=>$userId,'source'=>$deviceSource,'olt_id'=>$externalId]);
            $deviceId = ($value = (int)($stmt->fetchColumn() ?: 0)) > 0 ? $value : null;
        }

        if ($entityType === 'ONU' && $deviceId !== null && isset($parts[2])) {
            $onuIndex = implode('|', array_slice($parts, 2));
            $stmt = $this->pdo->prepare(
                'SELECT id FROM onus WHERE user_id=:uid AND olt_device_id=:device_id AND onu_index=:onu_index LIMIT 1'
            );
            $stmt->execute(['uid'=>$userId,'device_id'=>$deviceId,'onu_index'=>$onuIndex]);
            $onuId = ($value = (int)($stmt->fetchColumn() ?: 0)) > 0 ? $value : null;
        }
        return [$deviceId, $onuId];
    }

    private function hasUsableRxPower(mixed $value): bool
    {
        $power = $this->powerNumber($value);
        return $power !== null && is_finite($power);
    }

}
