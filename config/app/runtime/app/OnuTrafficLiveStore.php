<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config_default.php';

/** Short-lived traffic snapshots for ONU detail pages. No device access happens here. */
final class OnuTrafficLiveStore
{
    private const MAX_POINTS = LinnmsDefaults::ONU_TRAFFIC_LIVE_MAX_POINTS;
    private const LEASE_SECONDS = LinnmsDefaults::ONU_TRAFFIC_LIVE_LEASE_SECONDS;
    private const RETAIN_SECONDS = LinnmsDefaults::ONU_TRAFFIC_LIVE_RETAIN_SECONDS;

    private function directory(): string
    {
        return ensure_data_directory(cache_path('onu-traffic-live'));
    }

    private function file(int $userId, int $onuRowId): string
    {
        return $this->directory() . DIRECTORY_SEPARATOR . $userId . '-' . $onuRowId . '.json';
    }

    private function locked(int $userId, int $onuRowId, callable $change): array
    {
        $handle = @fopen($this->file($userId, $onuRowId), 'c+');
        if (!is_resource($handle)) return [];
        try {
            if (!flock($handle, LOCK_EX)) return [];
            rewind($handle);
            $decoded = json_decode((string)stream_get_contents($handle), true);
            $data = is_array($decoded) ? $decoded : ['expires_at' => 0, 'samples' => []];
            $data = $change($data);
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($data, JSON_UNESCAPED_SLASHES) ?: '{}');
            fflush($handle);
            flock($handle, LOCK_UN);
            return $data;
        } finally {
            fclose($handle);
        }
    }

    public function watch(int $userId, int $onuRowId): array
    {
        if ($userId < 1 || $onuRowId < 1) return [];
        $data = $this->locked($userId, $onuRowId, static function (array $data): array {
            if ((int)($data['expires_at'] ?? 0) < time() - self::RETAIN_SECONDS) $data['samples'] = [];
            $data['expires_at'] = time() + self::LEASE_SECONDS;
            $data['samples'] = array_slice((array)($data['samples'] ?? []), -self::MAX_POINTS);
            return $data;
        });
        return array_values((array)($data['samples'] ?? []));
    }

    public function active(): array
    {
        $active = [];
        foreach (glob($this->directory() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
            if (preg_match('~/(\d+)-(\d+)\.json$~D', $path, $match) !== 1) continue;
            $handle = @fopen($path, 'r');
            if (!is_resource($handle)) continue;
            if (!flock($handle, LOCK_SH)) { fclose($handle); continue; }
            $data = json_decode((string)stream_get_contents($handle), true);
            flock($handle, LOCK_UN);
            fclose($handle);
            if ((int)($data['expires_at'] ?? 0) < time()) {
                if ((int)($data['expires_at'] ?? 0) < time() - self::RETAIN_SECONDS) @unlink($path);
                continue;
            }
            $active[] = ['user_id' => (int)$match[1], 'onu_row_id' => (int)$match[2]];
        }
        return $active;
    }

    public function append(int $userId, int $onuRowId, array $sample): void
    {
        $at = (int)($sample['at'] ?? 0);
        $rx = $sample['rx_bps'] ?? null;
        $tx = $sample['tx_bps'] ?? null;
        if ($at < 1 || !is_numeric($rx) || !is_numeric($tx)
            || !is_finite((float)$rx) || !is_finite((float)$tx)) return;
        $this->locked($userId, $onuRowId, static function (array $data) use ($at, $rx, $tx): array {
            if ((int)($data['expires_at'] ?? 0) < time()) return $data;
            $samples = (array)($data['samples'] ?? []);
            $last = $samples !== [] ? end($samples) : null;
            if ($at <= (int)($last['at'] ?? 0)) return $data;
            $samples[] = ['at' => $at, 'rx' => max(0.0, (float)$rx), 'tx' => max(0.0, (float)$tx)];
            $data['samples'] = array_slice($samples, -self::MAX_POINTS);
            return $data;
        });
    }
}
