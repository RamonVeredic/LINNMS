<?php

declare(strict_types=1);

final class GraphSampleWriteService
{
    public function __construct(private LinnmsRepository $db)
    {
    }

    public function recordSelectedInterfaces(int $userId, int $rowId, array $rates): void
    {
        $selected = $this->db->getDeviceTrafficInterfaces($userId, $rowId);
        $samples = [];
        foreach ($selected as $ref => $index) {
            $row = $rates[(string)(int)$index] ?? null;
            if (!is_array($row)) continue;
            $samples[(string)$ref] = [
                'rx_bps' => (float)($row['rx_bps'] ?? 0),
                'tx_bps' => (float)($row['tx_bps'] ?? 0),
            ];
        }
        if ($samples !== []) {
            $this->db->saveGraphInterfaceSamples($userId, $rowId, $samples, date('Y-m-d H:i:s'));
        }
    }
}
