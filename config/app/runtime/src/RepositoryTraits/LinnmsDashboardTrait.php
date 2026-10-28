<?php

declare(strict_types=1);

trait LinnmsDashboardTrait
{
    public function getDashboardStats(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT
            (SELECT COUNT(*) FROM devices d LEFT JOIN device_status st ON st.device_id=d.id WHERE d.user_id=:u1 AND d.device_type='olt' AND COALESCE(st.is_present,1)=1) total_olts,
            (SELECT COUNT(*) FROM change_events WHERE user_id=:u2 AND created_at >= DATE_SUB(NOW(),INTERVAL 24 HOUR)) events_24h");
        $stmt->execute(['u1'=>$userId,'u2'=>$userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) $row = [];
        $onus = $this->getOnus($userId, null, true, null);
        $problemOnus = $this->getProblemOnus($userId, 1000);

        $online = 0;
        $down = 0;
        $loss = 0;
        $powerDown = 0;
        foreach ($onus as $onu) {
            
            $state = effective_onu_state_key($onu['onu_state'] ?? null, $onu['rx_power'] ?? null, onu_loss_without_rx($onu));
            if ($state === 'online') $online++;
            if ($state === 'offline') $down++;
            if ($state === 'powerdown') $powerDown++;
            if ($state === 'loss') $loss++;
        }

        $row['total_onus'] = count($onus);
        $row['online_onus'] = $online;
        $row['offline_onus'] = $down;
        $row['down_onus'] = $down;
        $row['loss_onus'] = $loss;
        $row['power_down_onus'] = $powerDown;
        $row['problem_onus'] = count($problemOnus);
        return $row;
    }

    private function confirmRxPowerChange(
        int $userId,
        string $entityKey,
        ?float $baseline,
        ?float $observed,
        float $threshold
    ): ?array {
        if ($observed === null || !is_finite($observed)) {
            $this->resetScanConfirmationCandidate($userId, $entityKey, 'rx_power_change');
            return null;
        }
        $threshold = self::clampOnuRxDetection($threshold);
        $field = 'rx_power_change';
        $powerText = static fn(float $value): string => rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        $confirmed = $baseline !== null && is_finite($baseline) ? $baseline : $observed;
        $isCandidate = abs($observed - $confirmed) >= $threshold;
        $tracker = $this->monitorFieldState(
            $userId,
            'ONU',
            $entityKey,
            $field,
            $powerText($confirmed),
            $isCandidate ? $powerText($observed) : null
        );
        if (($tracker['created'] ?? false) === true) {
            return null;
        }

        $confirmed = is_numeric($tracker['confirmed_value'] ?? null)
            ? (float)$tracker['confirmed_value']
            : ($baseline ?? $observed);
        if (abs($observed - $confirmed) < $threshold) {
            if (
                trim((string)($tracker['candidate_value'] ?? '')) === ''
                && (int)($tracker['candidate_count'] ?? 0) === 0
            ) {
                return null;
            }
            $reset = $this->pdo->prepare(
                'UPDATE sync_state SET candidate_value=NULL,candidate_count=0,candidate_since=NULL,updated_at=NOW() '
                . 'WHERE id=:id'
            );
            $reset->execute(['id'=>(int)$tracker['id']]);
            return null;
        }

        $candidate = is_numeric($tracker['candidate_value'] ?? null) ? (float)$tracker['candidate_value'] : null;
        $sameDirection = $candidate !== null
            && (($candidate - $confirmed) <=> 0) === (($observed - $confirmed) <=> 0);
        $nextCount = $sameDirection ? (int)($tracker['candidate_count'] ?? 0) + 1 : 1;
        if ($nextCount < 2) {
            $pending = $this->pdo->prepare(
                'UPDATE sync_state SET candidate_value=:candidate,candidate_count=:candidate_count,'
                . 'candidate_since=IF(:candidate_reset=1,NOW(),COALESCE(candidate_since,NOW())),updated_at=NOW() '
                . 'WHERE id=:id'
            );
            $pending->execute([
                'candidate'=>$powerText($observed),'candidate_count'=>$nextCount,
                'candidate_reset'=>$nextCount === 1 ? 1 : 0,
                'id'=>(int)$tracker['id'],
            ]);
            return null;
        }

        $confirm = $this->pdo->prepare(
            'UPDATE sync_state SET confirmed_value=:confirmed,candidate_value=NULL,candidate_count=0,'
            . 'candidate_since=NULL,updated_at=NOW(),confirmed_at=NOW() WHERE id=:id'
        );
        $confirm->execute([
            'confirmed'=>$powerText($observed),'id'=>(int)$tracker['id'],
        ]);
        return ['old'=>$confirmed,'new'=>$observed];
    }

    private function confirmRxProblemState(
        int $userId,
        string $entityKey,
        ?float $rxPower,
        float $threshold
    ): ?array
    {
        if ($this->monitorFieldConfirmedValue($userId, 'ONU', $entityKey, 'onu_state') === null) {
            $this->resetScanConfirmationCandidate($userId, $entityKey, 'rx_problem_state');
            return null;
        }
        if ($rxPower === null || !is_finite($rxPower)) {
            $this->resetScanConfirmationCandidate($userId, $entityKey, 'rx_problem_state');
            return null;
        }

        $limit = $this->userSignalThresholdDbm($userId, $threshold);
        $detection = $this->userRxDetectionDbm($userId);
        $state = strtolower(trim((string)$this->monitorFieldConfirmedValue($userId, 'ONU', $entityKey, 'rx_problem_state')));

        return $this->confirmScannedStatus(
            $userId,
            'ONU',
            $entityKey,
            'rx_problem_state',
            'normal',
            onu_rx_problem_next_state($state, $rxPower, $limit, $detection)
        );
    }

    private function confirmScannedStatus(
        int $userId,
        string $entityType,
        string $entityKey,
        string $field,
        mixed $baseline,
        mixed $observed,
        int $requiredScans = 2
    ): ?array {
        return $this->confirmScannedStatusTracker(
            $userId,
            $entityType,
            $entityKey,
            $field,
            $baseline,
            $observed,
            $requiredScans
        );
    }

    private function confirmScannedStatusTracker(
        int $userId,
        string $entityType,
        string $entityKey,
        string $trackerField,
        mixed $baseline,
        mixed $observed,
        int $requiredScans
    ): ?array {
        $entityType = strtoupper(trim($entityType));
        $entityKey = mb_substr(trim($entityKey), 0, 300);
        $trackerField = mb_substr(trim($trackerField), 0, 80);
        $baselineValue = $this->canonicalScannedStatus($trackerField, $baseline);
        $observedValue = $this->canonicalScannedStatus($trackerField, $observed);
        if ($userId < 1 || $entityType === '' || $entityKey === '' || $trackerField === '' || $observedValue === '') return null;
        if ($baselineValue === '') $baselineValue = $observedValue;
        if ($observedValue === 'online' || ($trackerField === 'is_present' && $observedValue === '1')) {
            $requiredScans = 1;
        }

        $sameAsBaseline = hash_equals($baselineValue, $observedValue);
        $tracker = $this->monitorFieldState(
            $userId,
            $entityType,
            $entityKey,
            $trackerField,
            $baselineValue,
            $sameAsBaseline ? null : $observedValue
        );
        if (($tracker['created'] ?? false) === true && $requiredScans > 1) return null;

        $confirmed = (string)$tracker['confirmed_value'];
        $candidate = trim((string)($tracker['candidate_value'] ?? ''));
        $candidateCount = (int)($tracker['candidate_count'] ?? 0);

        if (hash_equals($confirmed, $observedValue)) {
            if ($candidate === '' && $candidateCount === 0) {
                return null;
            }
            $reset = $this->pdo->prepare(
                'UPDATE sync_state SET candidate_value=NULL,candidate_count=0,candidate_since=NULL,updated_at=NOW() WHERE id=:id'
            );
            $reset->execute(['id'=>(int)$tracker['id']]);
            return null;
        }

        $nextCount = $candidate !== '' && hash_equals($candidate, $observedValue)
            ? $candidateCount + 1
            : 1;
        if ($nextCount < $requiredScans) {
            $pending = $this->pdo->prepare(
                'UPDATE sync_state SET candidate_value=:candidate,candidate_count=:candidate_count,'
                . 'candidate_since=IF(:candidate_reset=1,NOW(),COALESCE(candidate_since,NOW())),updated_at=NOW() WHERE id=:id'
            );
            $pending->execute([
                'candidate'=>$observedValue,'candidate_count'=>$nextCount,
                'candidate_reset'=>$nextCount === 1 ? 1 : 0,
                'id'=>(int)$tracker['id'],
            ]);
            return null;
        }

        $confirm = $this->pdo->prepare(
            'UPDATE sync_state SET confirmed_value=:confirmed,candidate_value=NULL,candidate_count=0,'
            . 'candidate_since=NULL,updated_at=NOW(),confirmed_at=NOW() WHERE id=:id'
        );
        $confirm->execute([
            'confirmed'=>$observedValue,'id'=>(int)$tracker['id'],
        ]);
        return ['old'=>$confirmed,'new'=>$observedValue,'changed'=>true];
    }

    private function monitorFieldState(
        int $userId,
        string $entityType,
        string $entityKey,
        string $field,
        string $confirmedValue,
        ?string $candidateValue = null
    ): array {
        $entityId = $this->monitorEntityId($userId, $entityType, $entityKey);
        $field = mb_substr(trim($field), 0, 80);
        $confirmedValue = mb_substr($confirmedValue, 0, 100);
        $candidateValue = $candidateValue === null ? null : mb_substr($candidateValue, 0, 100);

        $find = $this->pdo->prepare(
            'SELECT id,confirmed_value,candidate_value,candidate_count '
            . 'FROM sync_state WHERE monitor_entity_id=:entity_id AND field_name=:field LIMIT 1'
        );
        $find->execute(['entity_id'=>$entityId,'field'=>$field]);
        $row = $find->fetch();
        if (is_array($row)) {
            $created = false;
        } else {
            $insert = $this->pdo->prepare(
                'INSERT INTO sync_state('
                . 'monitor_entity_id,field_name,confirmed_value,candidate_value,candidate_count,candidate_since,confirmed_at,updated_at'
                . ') VALUES (:entity_id,:field,:confirmed,:candidate,:candidate_count,'
                . 'IF(:candidate_since_count>0,NOW(),NULL),NOW(),NOW()) '
                . 'ON DUPLICATE KEY UPDATE id=id'
            );
            $insert->execute([
                'entity_id'=>$entityId,
                'field'=>$field,
                'confirmed'=>$confirmedValue,
                'candidate'=>$candidateValue,
                'candidate_count'=>$candidateValue === null ? 0 : 1,
                'candidate_since_count'=>$candidateValue === null ? 0 : 1,
            ]);
            $created = $insert->rowCount() === 1;
            $find->execute(['entity_id'=>$entityId,'field'=>$field]);
            $row = $find->fetch();
        }
        if (!is_array($row)) throw new RuntimeException('State monitoring gagal dibuat.');
        return [
            'id'=>(int)$row['id'],
            'confirmed_value'=>(string)$row['confirmed_value'],
            'candidate_value'=>$row['candidate_value'] === null ? null : (string)$row['candidate_value'],
            'candidate_count'=>(int)$row['candidate_count'],
            'created'=>$created,
        ];
    }

    private function canonicalScannedStatus(string $field, mixed $value): string
    {
        $raw = strtolower(trim((string)$value));
        if ($raw === '') return '';
        if ($field === 'is_present') return in_array($raw, ['1','true','yes','present','online','up'], true) ? '1' : '0';
        if ($field === 'driver_status') {
            if (in_array($raw, ['ok','success','online','up'], true)) return 'online';
            if (in_array($raw, ['error','failed','offline','down'], true)) return 'offline';
        }
        return state_key($raw);
    }

    public function getControlDashboardStats(): array
    {
        $sql = "SELECT
            (SELECT COUNT(*) FROM devices d LEFT JOIN device_status st ON st.device_id=d.id WHERE d.device_type='olt' AND COALESCE(st.is_present,1)=1) total_olts,
            (SELECT COUNT(*) FROM onus WHERE is_present=1) total_onus,
            (SELECT COUNT(*) FROM devices d LEFT JOIN device_status st ON st.device_id=d.id WHERE d.device_type='mikrotik' AND COALESCE(st.is_present,1)=1) total_mikrotiks,
            (SELECT COUNT(*) FROM devices d LEFT JOIN device_status st ON st.device_id=d.id WHERE d.device_type='cisco_catalyst_2960s' AND COALESCE(st.is_present,1)=1) total_switches";
        $row = $this->pdo->query($sql)->fetch();
        if (!is_array($row)) $row = [];

        $sizeStmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.TABLES WHERE table_schema=:db'
        );
        $sizeStmt->execute(['db' => $this->databaseName]);
        $row['database_bytes'] = (int)$sizeStmt->fetchColumn();

        return $row;
    }
}
