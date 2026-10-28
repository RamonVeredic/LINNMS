<?php

declare(strict_types=1);

final class SyncTargetLock
{
    public static function acquire(string $kind, int $userId, int $rowId): mixed
    {
        if (!in_array($kind, ['snmp-device'], true) || $userId < 1 || $rowId < 1) {
            throw new InvalidArgumentException('Sasaran sinkronisasi tidak valid.');
        }
        ensure_data_directory(lock_path());
        $lock = @fopen(lock_path($kind . '-sync-' . $userId . '-' . $rowId . '.lock'), 'c+');
        if (!is_resource($lock)) throw new RuntimeException('Kunci sinkronisasi tidak tersedia.');
        for ($attempt = 0; $attempt < 10; $attempt++) {
            if (flock($lock, LOCK_EX | LOCK_NB)) return $lock;
            usleep(250000);
        }
        fclose($lock);
        return false;
    }

    public static function release(mixed $lock): void
    {
        if (!is_resource($lock)) return;
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
