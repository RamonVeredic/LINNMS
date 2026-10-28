<?php

declare(strict_types=1);

final class TrafficSamplerService
{
    public function __construct(
        private LinnmsRepository $db,
        private OnuTrafficDriverReader $onuReader,
        private OltPonCliTrafficPoller $oltPonCli,
        private OnuTrafficLiveStore $liveStore,
        private TrafficSampleWriteService $sampleWriter
    ) {
    }

    public function sampleOnuLive(?callable $reportError = null): int
    {
        $written = 0;
        foreach ($this->liveStore->active() as $viewer) {
            $userId = (int)($viewer['user_id'] ?? 0);
            $onuRowId = (int)($viewer['onu_row_id'] ?? 0);
            if ($userId < 1 || $onuRowId < 1) continue;
            try {
                $sample = $this->onuReader->sample($userId, $onuRowId);
                if (!is_array($sample)) continue;
                $this->liveStore->append($userId, $onuRowId, $sample);
                $onuRow = $this->db->getOnuByRowId($userId, $onuRowId);
                if (!is_array($onuRow)) continue;
                $this->sampleWriter->writeOnu(
                    $userId,
                    (int)($onuRow['olt_device_id'] ?? 0),
                    $onuRowId,
                    (string)($onuRow['pon_ref'] ?? ''),
                    (string)($onuRow['onu_index'] ?? ''),
                    (float)($sample['rx_bps'] ?? 0),
                    (float)($sample['tx_bps'] ?? 0),
                    date('Y-m-d H:i:s', (int)($sample['at'] ?? time()))
                );
                $written++;
            } catch (Throwable $error) {
                $message = 'ONU live traffic sample failed: ' . $error->getMessage();
                if ($reportError !== null) $reportError($message);
                else error_log($message);
            }
        }
        return $written;
    }

    public function sampleOltPonCli(?callable $reportError = null): int
    {
        try {
            return $this->oltPonCli->refresh();
        } catch (Throwable $error) {
            $message = 'OLT PON CLI traffic sample failed: ' . $error->getMessage();
            if ($reportError !== null) $reportError($message);
            else error_log($message);
            try {
                $this->db->logApplication(null, 'System', 'olt_pon_cli_traffic', 'error', (string)$error);
            } catch (Throwable) {}
            return 0;
        }
    }
}
