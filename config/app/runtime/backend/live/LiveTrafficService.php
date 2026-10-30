<?php

declare(strict_types=1);

final class LiveTrafficService
{
    private ?TrafficSampleWriteService $sampleWriter = null;
    private ?OnuTrafficDriverReader $onuReader = null;
    private ?PonTrafficLiveReader $ponReader = null;

    public function __construct(private LinnmsRepository $db, private array $config)
    {
    }

    public function execute(int $userId, string $type, array $input): array
    {
        $user = $this->db->getUser($userId);
        if (!is_array($user) || (int)($user['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'code' => 'forbidden', 'data' => []];
        }
        try {
            return match ($type) {
                'pon-traffic-live' => $this->pon($userId, $type, $input),
                'onu-traffic-live' => $this->onu($userId, $type, $input),
                'device-traffic-record' => $this->deviceRecord($userId, $input),
                default => ['ok' => false, 'code' => 'unsupported', 'data' => []],
            };
        } catch (InvalidArgumentException $error) {
            return ['ok' => false, 'code' => 'validation', 'data' => []];
        } catch (Throwable $error) {
            $this->log($userId, 'Driver', $type . '_driver', (string)$error);
            return ['ok' => false, 'code' => 'device_unreachable', 'data' => []];
        }
    }

    private function pon(int $userId, string $type, array $input): array
    {
        $rowId = (int)($input['olt_row_id'] ?? 0);
        if ($rowId < 1) throw new InvalidArgumentException('OLT tidak valid.');
        if (!$this->ponReader()->supports($userId, $rowId)) {
            return ['ok' => false, 'code' => 'unsupported', 'data' => []];
        }
        $data = $this->ponReader()->read($userId, $rowId);
        unset($data['message'], $data['error'], $data['raw'], $data['trace'], $data['diagnostic']);
        return ['ok' => true, 'code' => 'ok', 'data' => ['olt_row_id' => $rowId] + $data];
    }

    private function onu(int $userId, string $type, array $input): array
    {
        $rowId = (int)($input['onu_row_id'] ?? 0);
        if ($rowId < 1) throw new InvalidArgumentException('ONU tidak valid.');
        if (!$this->onuReader()->isActive($userId, $rowId)) {
            return ['ok' => true, 'code' => 'ok', 'data' => [
                'onu_row_id' => $rowId, 'active' => false, 'series' => [], 'live' => null,
            ]];
        }
        if (!$this->onuReader()->supports($userId, $rowId)) {
            return ['ok' => false, 'code' => 'unsupported', 'data' => []];
        }
        $series = [];
        $fileSeries = (new OnuTrafficLiveStore())->watch($userId, $rowId);
        $onu = $this->db->getOnuByRowId($userId, $rowId);
        if (is_array($onu)) {
            $oltDeviceId = (int)($onu['olt_device_id'] ?? 0);
            $latest = $this->db->getLatestOnuTrafficSample($userId, $oltDeviceId, $rowId);
            if (!is_array($latest) || time() - (int)($latest['at'] ?? 0) >= 15) {
                try {
                    $sample = $this->onuReader()->sample($userId, $rowId);
                    if (is_array($sample)) {
                        $this->sampleWriter()->writeOnu($userId, $oltDeviceId, $rowId,
                            (string)($onu['pon_ref'] ?? ''), (string)($onu['onu_index'] ?? ''),
                            (float)($sample['rx_bps'] ?? 0), (float)($sample['tx_bps'] ?? 0),
                            date('Y-m-d H:i:s', (int)($sample['at'] ?? time())));
                    }
                } catch (Throwable $error) {
                    $this->log($userId, 'Driver', 'onu_traffic_sample_driver', (string)$error);
                }
            }
            $history = $this->db->getOnuTrafficSeries($userId, $oltDeviceId, $rowId, 3600);
            $series = reset($history['series']) ?: [];
        }
        if ($series === []) {
            if ($fileSeries !== [] && is_array($onu)) {
                try {
                    foreach (array_slice($fileSeries, -12) as $point) {
                        $at = (int)($point['at'] ?? 0);
                        if ($at < 1) continue;
                        $this->sampleWriter()->writeOnu($userId, (int)($onu['olt_device_id'] ?? 0), $rowId,
                            (string)($onu['pon_ref'] ?? ''), (string)($onu['onu_index'] ?? ''),
                            (float)($point['rx'] ?? 0), (float)($point['tx'] ?? 0), date('Y-m-d H:i:s', $at));
                    }
                    $series = array_slice($fileSeries, -12);
                } catch (Throwable $error) {
                    $this->log($userId, 'LINNMS APP', 'onu_traffic_backfill_error', (string)$error);
                    $series = $fileSeries;
                }
            } else {
                $series = $fileSeries;
            }
        }
        $last = $series !== [] ? end($series) : null;
        return ['ok' => true, 'code' => 'ok', 'data' => [
            'onu_row_id' => $rowId,
            'active' => true,
            'series' => $series,
            'live' => is_array($last)
                ? ['at' => $last['at'], 'rx_bps' => $last['rx'], 'tx_bps' => $last['tx']] : null,
        ]];
    }

    private function deviceRecord(int $userId, array $input): array
    {
        $deviceId = (int)($input['device_id'] ?? 0);
        $ref = trim((string)($input['if_ref'] ?? ''));
        $enabled = (string)($input['enabled'] ?? '0') === '1';
        if ($deviceId < 1 || $ref === '') throw new InvalidArgumentException('Interface tidak valid.');
        $device = $this->db->getEditableDevice($userId, $deviceId);
        $type = is_array($device) ? (string)($device['device_type'] ?? '') : '';
        if (!in_array($type, ['olt', 'mikrotik', 'cisco_catalyst_2960s'], true)) {
            return ['ok' => false, 'code' => 'not_found', 'data' => []];
        }
        $refs = [];
        if ($type === 'olt') {
            foreach ($this->db->getOltPonSnapshot($userId, $deviceId) as $pon) {
                $name = trim((string)($pon['port_ref'] ?? ''));
                if ($name !== '') $refs[$name] = 0;
            }
            foreach ($this->db->getOltPorts($userId, $deviceId, 'uplink') as $uplink) {
                if (!is_array($uplink)) continue;
                $name = trim((string)($uplink['port_ref'] ?? ''));
                if ($name !== '') $refs[$name] = (int)($uplink['if_index'] ?? 0);
            }
        } else {
            foreach ($this->db->getDeviceInterfaces($userId, $deviceId) as $interface) {
                $name = trim((string)($interface['if_ref'] ?? ''));
                if ($name !== '') $refs[$name] = (int)($interface['if_index'] ?? 0);
            }
        }
        if (!array_key_exists($ref, $refs)) return ['ok' => false, 'code' => 'not_found', 'data' => []];
        try {
            $this->db->toggleDeviceTrafficInterface($userId, $deviceId, $ref, (int)$refs[$ref], $enabled);
        } catch (Throwable $error) {
            $this->log($userId, 'LINNMS APP', 'device_traffic_record_error', (string)$error);
            return ['ok' => false, 'code' => 'internal', 'data' => []];
        }
        return ['ok' => true, 'code' => 'ok', 'data' => [
            'device_id' => $deviceId,
            'if_ref' => $ref,
            'enabled' => $enabled,
            'count' => count($this->db->getDeviceTrafficInterfaces($userId, $deviceId)),
        ]];
    }

    private function ponReader(): PonTrafficLiveReader
    {
        if ($this->ponReader === null) {
            require_once __DIR__ . '/PonTrafficLiveReader.php';
            $this->ponReader = new PonTrafficLiveReader($this->db, $this->config);
        }
        return $this->ponReader;
    }

    private function sampleWriter(): TrafficSampleWriteService
    {
        if ($this->sampleWriter === null) {
            require_once project_path('backend', 'services', 'TrafficSampleWriteService.php');
            $this->sampleWriter = new TrafficSampleWriteService($this->db);
        }
        return $this->sampleWriter;
    }

    private function onuReader(): OnuTrafficDriverReader
    {
        if ($this->onuReader === null) {
            require_once project_path('backend', 'services', 'OnuTrafficDriverReader.php');
            $this->onuReader = new OnuTrafficDriverReader($this->db, $this->config);
        }
        return $this->onuReader;
    }

    private function log(int $userId, string $area, string $action, string $detail): void
    {
        try {
            $this->db->logApplication($userId, $area, $action, 'error', $detail);
        } catch (Throwable $error) {
            error_log('[LINNMS] Live traffic log: ' . $error->getMessage());
        }
    }
}
