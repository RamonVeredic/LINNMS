<?php

declare(strict_types=1);


trait LinnmsOltCliTrafficTrait
{
    private const OLT_CLI_TRAFFIC_DEMAND_SECONDS = LinnmsDefaults::OLT_CLI_TRAFFIC_DEMAND_SECONDS;
    private const OLT_CLI_TRAFFIC_PRUNE_SECONDS = LinnmsDefaults::OLT_CLI_TRAFFIC_PRUNE_SECONDS;

    private function oltPonCliTrafficCacheDir(): string
    {
        return ensure_data_directory(cache_path('olt-pon-cli-traffic'));
    }

    private function oltPonCliTrafficCacheFile(int $deviceId): string
    {
        return $this->oltPonCliTrafficCacheDir() . DIRECTORY_SEPARATOR . 'device-' . $deviceId . '.json';
    }

    private function withOltPonCliTrafficCacheLock(bool $exclusive, callable $callback): mixed
    {
        $handle = @fopen(ensure_data_directory(lock_path()) . DIRECTORY_SEPARATOR . 'olt-pon-cli-traffic.lock', 'c+');
        if (!is_resource($handle)) return null;
        try {
            if (!flock($handle, $exclusive ? LOCK_EX : LOCK_SH)) return null;
            return $callback();
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    private function readOltPonCliTrafficJson(string $path): array
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }


    public function getOltCliTrafficDeviceRows(): array
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT d.id, d.user_id, d.ip_address, d.type, "
                . "c.ports, c.username, c.password_enc, c.hybrid_status "
                . "FROM devices d "
                . "JOIN device_credentials c ON c.device_id=d.id AND c.hybrid_status=1 "
                . "LEFT JOIN device_status st ON st.device_id=d.id AND st.user_id=d.user_id "
                . "WHERE d.device_type='olt' "
                . "AND COALESCE(st.driver_status,'') <> 'DOWN' "
                . "AND EXISTS (SELECT 1 FROM device_traffic_interfaces t WHERE t.device_id=d.id AND t.user_id=d.user_id)"
            );
            return $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    public function getSelectedOltCliTrafficPons(int $userId, int $deviceId): array
    {
        if ($userId < 1 || $deviceId < 1) return [];
        $stmt = $this->pdo->prepare(
            'SELECT p.port_ref, p.pon_number FROM pons p '
            . 'JOIN device_traffic_interfaces t ON t.user_id=p.user_id '
            . 'AND t.device_id=p.olt_device_id AND t.if_ref COLLATE utf8mb4_unicode_ci = p.port_ref '
            . "WHERE p.user_id=:uid AND p.olt_device_id=:dev AND p.is_present=1 AND p.pon_number>0 "
            . "AND LOWER(COALESCE(p.link_status,'')) NOT IN ('linkdown','down','offline') "
            . 'ORDER BY p.pon_number, p.port_ref'
        );
        $stmt->execute(['uid' => $userId, 'dev' => $deviceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    
    public function touchOltPonCliTrafficDemand(int $seconds = self::OLT_CLI_TRAFFIC_DEMAND_SECONDS): void
    {
        $seconds = max(5, min(120, $seconds));
        $saved = $this->withOltPonCliTrafficCacheLock(true, function () use ($seconds): bool {
            $path = $this->oltPonCliTrafficCacheDir() . DIRECTORY_SEPARATOR . 'demand.json';
            $current = $this->readOltPonCliTrafficJson($path);
            $expiresAt = max((int)($current['expires_at'] ?? 0), time() + $seconds);
            return @file_put_contents($path, json_encode(['expires_at' => $expiresAt], JSON_THROW_ON_ERROR)) !== false;
        });
        if ($saved !== true) throw new RuntimeException('Permintaan trafik PON CLI gagal disimpan.');
    }

    public function oltPonCliTrafficDemandActive(): bool
    {
        return $this->withOltPonCliTrafficCacheLock(false, function (): bool {
            $demand = $this->readOltPonCliTrafficJson($this->oltPonCliTrafficCacheDir() . DIRECTORY_SEPARATOR . 'demand.json');
            return (int)($demand['expires_at'] ?? 0) > time();
        }) === true;
    }

    
    public function getOltPonCliTrafficLive(int $deviceId): array
    {
        if ($deviceId < 1) return [];
        $entry = $this->readOltPonCliTrafficEntry($deviceId);
        return is_array($entry['points'] ?? null) ? $entry['points'] : [];
    }

    public function getOltPonCliTrafficLiveUpdatedAt(int $deviceId): int
    {
        if ($deviceId < 1) return 0;
        return (int)($this->readOltPonCliTrafficEntry($deviceId)['at'] ?? 0);
    }

    private function readOltPonCliTrafficEntry(int $deviceId): array
    {
        $entry = $this->withOltPonCliTrafficCacheLock(false, fn(): array =>
            $this->readOltPonCliTrafficJson($this->oltPonCliTrafficCacheFile($deviceId))
        );
        if (!is_array($entry) || (int)($entry['at'] ?? 0) < time() - self::OLT_CLI_TRAFFIC_PRUNE_SECONDS) return [];
        return $entry;
    }

    public function saveOltPonCliTrafficLive(array $freshDevices, int $updatedAt, array $validDeviceIds = [], bool $dropMissing = false): void
    {
        $saved = $this->withOltPonCliTrafficCacheLock(true, function () use ($freshDevices, $updatedAt, $validDeviceIds, $dropMissing): bool {
            $directory = $this->oltPonCliTrafficCacheDir();
            $valid = array_fill_keys(array_map('intval', $validDeviceIds), true);
            foreach ($freshDevices as $deviceId => $entry) {
                $deviceId = (int)$deviceId;
                if ($deviceId < 1 || !is_array($entry)) continue;
                $payload = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if (@file_put_contents($this->oltPonCliTrafficCacheFile($deviceId), $payload) === false) return false;
            }
            foreach (glob($directory . DIRECTORY_SEPARATOR . 'device-*.json') ?: [] as $path) {
                if (preg_match('/device-(\d+)\.json$/D', $path, $match) !== 1) continue;
                $deviceId = (int)$match[1];
                $entry = $this->readOltPonCliTrafficJson($path);
                $at = (int)($entry['at'] ?? 0);
                if (($validDeviceIds !== [] && !isset($valid[$deviceId]))
                    || ($dropMissing && !isset($freshDevices[$deviceId]))
                    || $at < $updatedAt - self::OLT_CLI_TRAFFIC_PRUNE_SECONDS) {
                    @unlink($path);
                }
            }
            return true;
        });
        if ($saved !== true) throw new RuntimeException('Cache trafik PON CLI gagal disimpan.');
    }
}
