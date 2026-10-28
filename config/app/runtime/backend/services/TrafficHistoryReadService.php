<?php

declare(strict_types=1);

final class TrafficHistoryReadService
{
    public function __construct(private LinnmsRepository $db)
    {
    }

    public function read(int $userId, string $type, array $input): array
    {
        $user = $this->db->getUser($userId);
        if (!is_array($user) || (int)($user['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'code' => 'forbidden', 'data' => []];
        }
        return match ($type) {
            'pon-traffic' => $this->ponResult($userId, $input),
            'onu-traffic' => $this->onuResult($userId, $input),
            'device-traffic-history' => $this->deviceResult($userId, $input),
            default => ['ok' => false, 'code' => 'unsupported', 'data' => []],
        };
    }

    private function ponResult(int $userId, array $input): array
    {
        $rowId = (int)($input['olt_row_id'] ?? 0);
        if ($rowId < 1) return ['ok' => false, 'code' => 'validation', 'data' => []];
        $olt = $this->db->getOltByRowId($userId, $rowId);
        $definition = is_array($olt) ? monitoring_driver_definition((string)device_driver_type($olt)) : null;
        $class = is_array($definition) ? trim((string)($definition['driver_class'] ?? '')) : '';
        if (!is_array($olt) || !monitoring_row_uses_handler($olt, 'snmp_olt')
            || (($definition['pon_traffic_source'] ?? '') !== 'cli'
                && ($class === '' || !class_exists($class) || !is_subclass_of($class, OltPonTrafficAware::class)))) {
            return ['ok' => false, 'code' => 'unsupported', 'data' => []];
        }
        return ['ok' => true, 'code' => 'ok', 'data' => ['olt_row_id' => $rowId]
            + $this->pon($userId, $rowId, (int)($input['range'] ?? 3600))];
    }

    private function onuResult(int $userId, array $input): array
    {
        $rowId = (int)($input['onu_row_id'] ?? 0);
        if ($rowId < 1) return ['ok' => false, 'code' => 'validation', 'data' => []];
        $onu = $this->db->getOnuByRowId($userId, $rowId);
        $olt = is_array($onu) ? $this->db->getOltByRowId($userId, (int)($onu['olt_row_id'] ?? 0)) : null;
        $definition = is_array($olt) ? monitoring_driver_definition((string)device_driver_type($olt)) : null;
        $class = is_array($definition) ? trim((string)($definition['driver_class'] ?? '')) : '';
        if (!is_array($onu) || !monitoring_row_uses_handler($onu, 'snmp_olt')
            || !is_array($olt) || $class === '' || !class_exists($class)
            || !is_subclass_of($class, OltOnuTrafficAware::class)) {
            return ['ok' => false, 'code' => 'unsupported', 'data' => []];
        }
        return ['ok' => true, 'code' => 'ok', 'data' => ['onu_row_id' => $rowId]
            + $this->onu($userId, $rowId, (int)($input['range'] ?? 3600))];
    }

    private function deviceResult(int $userId, array $input): array
    {
        $deviceId = (int)($input['device_id'] ?? 0);
        $ref = trim((string)($input['if_ref'] ?? ''));
        $range = max(60, min(5356800, (int)($input['range'] ?? 86400)));
        if ($deviceId < 1) return ['ok' => false, 'code' => 'validation', 'data' => []];
        $device = $this->db->getEditableDevice($userId, $deviceId);
        if (!is_array($device) || !in_array((string)($device['device_type'] ?? ''),
            ['mikrotik', 'cisco_catalyst_2960s'], true)) {
            return ['ok' => false, 'code' => 'not_found', 'data' => []];
        }
        $selected = $this->db->getDeviceTrafficInterfaces($userId, $deviceId);
        if ($ref === '') {
            $interfaces = [];
            foreach (array_keys($selected) as $name) {
                $interfaces[(string)$name] = $this->db->getDeviceTrafficSeries($userId, $deviceId,
                    (string)$name, $range);
            }
            return ['ok' => true, 'code' => 'ok', 'data' => ['device_id' => $deviceId, 'interfaces' => $interfaces]];
        }
        if (!array_key_exists($ref, $selected)) return ['ok' => false, 'code' => 'not_found', 'data' => []];
        return ['ok' => true, 'code' => 'ok', 'data' => ['device_id' => $deviceId]
            + $this->db->getDeviceTrafficSeries($userId, $deviceId, $ref, $range)];
    }

    public function pon(int $userId, int $rowId, int $rangeSeconds): array
    {
        $olt = $this->db->getOltByRowId($userId, $rowId);
        if (!is_array($olt) || !monitoring_row_uses_handler($olt, 'snmp_olt')) {
            return ['range_seconds' => $rangeSeconds, 'samples' => 0, 'series' => [], 'average' => [], 'live' => []];
        }
        $history = $this->db->getOltPonTrafficSeries($userId, (int)$olt['id'], $rangeSeconds);
        $history['live'] = [];
        return $history;
    }

    public function onu(int $userId, int $rowId, int $rangeSeconds): array
    {
        $onu = $this->db->getOnuByRowId($userId, $rowId);
        if (!is_array($onu) || !monitoring_row_uses_handler($onu, 'snmp_olt')) {
            return ['range_seconds' => $rangeSeconds, 'samples' => 0, 'series' => [], 'average' => [], 'live' => null];
        }
        $oltRowId = (int)($onu['olt_row_id'] ?? 0);
        $olt = $oltRowId > 0 ? $this->db->getOltByRowId($userId, $oltRowId) : null;
        $history = $this->db->getOnuTrafficSeries($userId, (int)($olt['id'] ?? 0), (int)$onu['id'], $rangeSeconds);
        $series = reset($history['series']);
        $last = is_array($series) && $series !== [] ? end($series) : null;
        $history['live'] = is_array($last) ? ['rx_bps' => $last['rx'], 'tx_bps' => $last['tx']] : null;
        return $history;
    }
}
