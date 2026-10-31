<?php

declare(strict_types=1);

final class DeviceRealtimeReadService
{
    public function __construct(private LinnmsRepository $db, private array $config, private CredentialCipher $cipher)
    {
    }

    public function read(int $userId, array $params): array
    {
        $type = (string)($params['type'] ?? '');
        $context = is_array($params['context'] ?? null) ? $params['context'] : [];
        $rowId = (int)($context['row_id'] ?? 0);
        $user = $this->db->getUser($userId);
        if (!is_array($user) || (int)($user['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'code' => 'forbidden', 'data' => []];
        }
        $device = $rowId > 0 ? $this->db->getEditableDevice($userId, $rowId) : null;
        if (!is_array($device) || (int)($context['user_id'] ?? 0) !== $userId
            || (int)($context['expires_at'] ?? 0) < time()
            || (string)($context['device_type'] ?? '') !== (string)($device['device_type'] ?? '')
            || !in_array($type, ['device-traffic', 'device-interface-rates'], true)) {
            return ['ok' => false, 'code' => 'conflict', 'data' => []];
        }

        // Konteks sesi hanya menyimpan identitas perangkat; lengkapi kredensial SNMP dari baris perangkat.
        $context['ip_address'] = (string)($device['ip_address'] ?? '');
        $context['snmp_port'] = (int)($device['snmp_port'] ?? 161);
        $context['snmp_community'] = (string)($device['snmp_community'] ?? '');

        $statuses = $this->db->getDeviceConnectionStatuses($userId, [$rowId]);
        if (strtoupper(trim((string)($statuses[0]['driver_status'] ?? ''))) === 'DOWN') {
            return ['ok' => true, 'code' => 'ok', 'data' => ['active' => false, 'rates' => []]];
        }
        $downIndexes = [];
        $downNames = [];
        foreach ($this->db->getDeviceInterfaces($userId, $rowId) as $interface) {
            if (!is_array($interface)) continue;
            $status = strtolower(trim((string)($interface['if_status'] ?? '')));
            if (!in_array($status, ['down', 'offline', 'linkdown'], true)) continue;
            $downIndexes[(int)($interface['if_index'] ?? 0)] = true;
            $downNames[(string)($interface['if_ref'] ?? '')] = true;
        }
        if ($type === 'device-traffic' && isset($downNames[trim((string)($params['interface'] ?? ''))])) {
            return ['ok' => true, 'code' => 'ok', 'data' => ['active' => false]];
        }

        $direct = new DeviceRealtimeService($this->config, $this->cipher);
        try {
            if ($type === 'device-traffic') {
                $result = $direct->snmpTraffic($context, trim((string)($params['interface'] ?? '')), $userId, $rowId);
                if (empty($result['ok'])) return $this->failed($userId, $rowId, $type, $result);
                unset($result['message'], $result['error'], $result['raw'], $result['trace'], $result['diagnostic']);
                return ['ok' => true, 'code' => 'ok', 'data' => $result];
            }

            $indexes = array_slice(array_values(array_unique(array_filter(array_map('intval',
                (array)($params['indexes'] ?? [])), static fn(int $value): bool => $value > 0))), 0, 256);
            $indexes = array_values(array_filter($indexes, static fn(int $index): bool => !isset($downIndexes[$index])));
            if ($indexes === []) {
                return ['ok' => true, 'code' => 'ok', 'data' => ['active' => false, 'rates' => []]];
            }
            $result = $direct->snmpInterfaceRates($context, $indexes);
            if (empty($result['ok'])) return $this->failed($userId, $rowId, $type, $result);
            if (in_array((string)($context['device_type'] ?? ''), ['mikrotik', 'cisco_catalyst_2960s'], true)) {
                try {
                    (new GraphSampleWriteService($this->db))->recordSelectedInterfaces($userId, $rowId,
                        is_array($result['rates'] ?? null) ? $result['rates'] : []);
                } catch (Throwable $error) {
                    $this->log($userId, $rowId, 'graph_sample', (string)$error);
                }
            }
            unset($result['message'], $result['error'], $result['raw'], $result['trace'], $result['diagnostic']);
            return ['ok' => true, 'code' => 'ok', 'data' => $result];
        } catch (Throwable $error) {
            $this->log($userId, $rowId, $type, (string)$error);
            return ['ok' => false, 'code' => 'device_unreachable', 'data' => []];
        }
    }

    private function failed(int $userId, int $rowId, string $type, array $result): array
    {
        $this->log($userId, $rowId, $type, (string)($result['message'] ?? raw_result_error($result, $type)));
        return ['ok' => false, 'code' => 'device_unreachable', 'data' => []];
    }

    private function log(int $userId, int $rowId, string $action, string $detail): void
    {
        try {
            $this->db->logApplication($userId, 'Driver', $action . '_driver', 'error', $detail,
                ['device_id' => $rowId]);
        } catch (Throwable $error) {
            error_log('[LINNMS] Device realtime log: ' . $error->getMessage());
        }
    }
}
