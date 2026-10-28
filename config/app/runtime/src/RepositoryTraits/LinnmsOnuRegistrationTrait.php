<?php

declare(strict_types=1);

trait LinnmsOnuRegistrationTrait
{
    public function saveOnuRegistrationProfile(
        int $userId,
        int $rowId,
        ?string $name,
        ?string $location,
        ?string $onuType
    ): array {
        $name = trim((string)$name);
        $location = onu_location_value($location);
        $onuType = trim((string)$onuType);

        if (mb_strlen($name) > 255) {
            throw new InvalidArgumentException('Nama ONU maksimal 255 karakter.');
        }
        if (mb_strlen($location) > 255) {
            throw new InvalidArgumentException('Lokasi ONU maksimal 255 karakter.');
        }
        if (mb_strlen($onuType) > 255) {
            throw new InvalidArgumentException('ONU Type maksimal 255 karakter.');
        }

        $before = $this->getOnuByRowId($userId, $rowId);
        if ($before === null) {
            return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];
        }

        $this->onuWriteTransaction(function () use ($userId, $rowId, $name, $location, $onuType, $before): void {
            $stmt = $this->pdo->prepare(
                'UPDATE onus SET onu_name=:name,onu_location=:location,onu_type=:onu_type,auth_confirmed_once=1 '
                . 'WHERE id=:id AND user_id=:uid'
            );
            $stmt->execute([
                'name' => $name !== '' ? $name : null,
                'location' => $location !== '' ? $location : null,
                'onu_type' => $onuType !== '' ? $onuType : null,
                'id' => $rowId,
                'uid' => $userId,
            ]);

            $state = trim((string)($before['onu_state'] ?? ''));
            $source = trim((string)($before['source'] ?? ''));
            $oltId = (int)($before['olt_id'] ?? 0);
            $index = trim((string)($before['onu_index'] ?? ''));
            $entityKey = $source !== '' && $oltId > 0 && $index !== '' ? $source . '|' . $oltId . '|' . $index : '';
            if ($state === '' || strcasecmp($state, 'Unknown') === 0 || $entityKey === '') return;
            $previous = $this->pdo->prepare(
                "SELECT new_value FROM change_events WHERE user_id=:uid AND entity_type='ONU' AND field_name='onu_state' AND entity_key=:key ORDER BY id DESC LIMIT 1"
            );
            $previous->execute(['uid' => $userId, 'key' => $entityKey]);
            $lastState = $previous->fetchColumn();
            if ($lastState !== false && state_key($lastState) === state_key($state)) return;
            $event = $this->insertChangeEvent(
                $userId, 'ONU', $entityKey, 'onu_state', $lastState === false ? null : $lastState, $state,
                'Status ONU saat registrasi: ' . $state . '.',
                [
                    'source' => $source,
                    'olt_id' => $oltId,
                    'onu_index' => $index,
                    'onu_name' => $name,
                    'onu_serial' => $before['onu_serial'] ?? null,
                    'onu_location' => $location,
                ]
            );
            $markRecorded = $this->pdo->prepare('UPDATE change_events SET notified_at=NOW() WHERE id=:id');
            $markRecorded->execute(['id' => (int)$event['id']]);
        });

        return ['ok' => true, 'message' => 'Profil ONU berhasil disimpan.'];
    }

    public function clearOnuRegistrationProfile(int $userId, int $rowId): array
    {
        $before = $this->getOnuByRowId($userId, $rowId);
        if ($before === null) {
            return ['ok' => false, 'message' => 'ONU tidak ditemukan.'];
        }

        $stmt = $this->pdo->prepare(
            'UPDATE onus SET onu_name=NULL,onu_location=NULL,onu_type=NULL,auth_confirmed_once=0 '
            . 'WHERE id=:id AND user_id=:uid'
        );
        $stmt->execute(['id' => $rowId, 'uid' => $userId]);

        return ['ok' => true, 'message' => 'Profil registrasi ONU dikosongkan.'];
    }

    public function transferOnuRegistrationProfile(int $userId, int $fromRowId, int $toRowId): array
    {
        if ($fromRowId < 1 || $toRowId < 1 || $fromRowId === $toRowId) {
            return ['ok' => false, 'message' => 'ONU pengganti tidak valid.'];
        }

        $from = $this->getOnuByRowId($userId, $fromRowId);
        $to = $this->getOnuByRowId($userId, $toRowId);
        if ($from === null || $to === null) {
            return ['ok' => false, 'message' => 'ONU asal atau ONU pengganti tidak ditemukan.'];
        }
        if ((int)($from['olt_device_id'] ?? 0) !== (int)($to['olt_device_id'] ?? 0)) {
            return ['ok' => false, 'message' => 'ONU pengganti harus berada pada OLT yang sama.'];
        }

        $this->pdo->beginTransaction();
        try {
            $target = $this->pdo->prepare(
                'UPDATE onus SET onu_name=:name,onu_location=:location,onu_type=:onu_type,auth_confirmed_once=1 '
                . 'WHERE id=:id AND user_id=:uid'
            );
            $target->execute([
                'name' => $from['onu_name'] ?? null,
                'location' => $from['onu_location'] ?? null,
                'onu_type' => $from['onu_type'] ?? null,
                'id' => $toRowId,
                'uid' => $userId,
            ]);

            $source = $this->pdo->prepare(
                'UPDATE onus SET onu_name=NULL,onu_location=NULL,onu_type=NULL,auth_confirmed_once=0 '
                . 'WHERE id=:id AND user_id=:uid'
            );
            $source->execute(['id' => $fromRowId, 'uid' => $userId]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return ['ok' => true, 'message' => 'Profil ONU dipindahkan ke Serial pengganti.'];
    }
}
