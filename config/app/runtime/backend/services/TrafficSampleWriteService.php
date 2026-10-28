<?php

declare(strict_types=1);

final class TrafficSampleWriteService
{
    public function __construct(private LinnmsRepository $db)
    {
    }

    public function writeDevice(int $userId, int $deviceId, array $samples, string $sampledAt): int
    {
        $samples = $this->normalize($samples);
        if ($userId < 1 || $deviceId < 1 || $samples === []) return 0;
        return $this->db->saveDeviceTrafficSamples($userId, $deviceId, $samples, $sampledAt);
    }

    public function pruneDevice(int $userId, int $deviceId): int
    {
        if ($userId < 1 || $deviceId < 1) return 0;
        return $this->db->pruneDeviceTrafficSamples($userId, $deviceId);
    }

    public function writeOltPon(int $userId, int $oltDeviceId, array $samples, string $sampledAt): int
    {
        $samples = $this->normalize($samples);
        if ($userId < 1 || $oltDeviceId < 1 || $samples === []) return 0;
        return $this->db->saveOltPonTrafficSamples($userId, $oltDeviceId, $samples, $sampledAt);
    }

    public function pruneOltPon(int $userId, int $oltDeviceId): int
    {
        if ($userId < 1 || $oltDeviceId < 1) return 0;
        return $this->db->pruneOltPonTrafficSamples($userId, $oltDeviceId);
    }

    public function writeOnu(
        int $userId,
        int $oltDeviceId,
        int $onuRowId,
        string $ponRef,
        string $onuIndex,
        float $rxBps,
        float $txBps,
        string $sampledAt
    ): void {
        if ($userId < 1 || $oltDeviceId < 1 || $onuRowId < 1) return;
        $this->db->saveOnuTrafficSamples(
            $userId,
            $oltDeviceId,
            $onuRowId,
            $ponRef,
            $onuIndex,
            max(0.0, $rxBps),
            max(0.0, $txBps),
            $sampledAt
        );
    }

    private function normalize(array $samples): array
    {
        $normalized = [];
        foreach ($samples as $ref => $row) {
            $ref = trim((string)$ref);
            if ($ref === '' || !is_array($row)) continue;
            $rx = $row['rx_bps'] ?? $row['rx'] ?? null;
            $tx = $row['tx_bps'] ?? $row['tx'] ?? null;
            if (!is_numeric($rx) || !is_numeric($tx)) continue;
            $normalized[$ref] = [
                'rx_bps' => max(0.0, (float)$rx),
                'tx_bps' => max(0.0, (float)$tx),
            ];
        }
        return $normalized;
    }
}
