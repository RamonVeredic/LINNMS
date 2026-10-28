<?php

declare(strict_types=1);

require_once __DIR__ . '/OnuListReadService.php';

final class DashboardDetailReadService
{
    public function __construct(private LinnmsRepository $db)
    {
    }

    public function read(int $userId, array $params): array
    {
        $category = trim((string)($params['category'] ?? ''));
        if (!in_array($category, ['total_devices', 'mikrotik', 'olt', 'switch', 'total_onus',
            'online_onus', 'down_onus', 'power_down_onus', 'loss_onus', 'problem_onus'], true)) {
            return ['ok' => false, 'code' => 'validation', 'data' => []];
        }

        if (in_array($category, ['total_devices', 'mikrotik', 'olt', 'switch'], true)) {
            $connection = device_external_api_connection();
            $apiKeyOn = !empty($connection['managed']) && !empty($connection['enabled'])
                && trim((string)($connection['base'] ?? '')) !== ''
                && trim((string)($connection['key'] ?? '')) !== '';
            return ['ok' => true, 'code' => 'ok', 'data' => [
                'api_olt_flags' => $this->db->getApiOltTypeSupportFlags(),
                'driver_flags' => $this->db->getOltDriverSupportFlags(),
                'api_key_on' => $apiKeyOn,
                'olts' => in_array($category, ['total_devices', 'olt'], true)
                    ? array_map($this->oltRow(...), $this->db->getOlts($userId)) : [],
                'mikrotik' => in_array($category, ['total_devices', 'mikrotik'], true)
                    ? array_map($this->snmpRow(...), $this->db->getSnmpDevices($userId, 'mikrotik')) : [],
                'switch' => in_array($category, ['total_devices', 'switch'], true)
                    ? array_map($this->snmpRow(...), $this->db->getSnmpDevices($userId, 'cisco_catalyst_2960s')) : [],
            ]];
        }

        $status = match ($category) {
            'online_onus' => 'online',
            'down_onus' => 'down',
            'power_down_onus' => 'powerdown',
            'loss_onus' => 'loss',
            'problem_onus' => 'problem',
            default => '',
        };
        $filters = [
            'olt' => null,
            'source' => null,
            'pon' => '',
            'status' => $status,
            'q' => mb_substr(trim((string)($params['q'] ?? '')), 0, 100),
            'device' => mb_substr(trim((string)($params['device'] ?? '')), 0, 100),
        ];
        $list = (new OnuListReadService($this->db))->read(
            $userId,
            $filters,
            max(1, (int)($params['p'] ?? 1)),
            false,
            max(25, min(200, (int)($params['per'] ?? 100)))
        );
        $list['rows'] = array_map($this->onuRow(...), (array)($list['rows'] ?? []));
        return ['ok' => true, 'code' => 'ok', 'data' => [
            'list' => $list,
            'device_counts' => $this->db->getOnusListDeviceCounts($userId, $list['filters']),
        ]];
    }

    private function oltRow(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'source' => (string)($row['source'] ?? ''),
            'type' => (string)($row['type'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'ip_address' => (string)($row['ip_address'] ?? ''),
            'snmp_port' => (int)($row['snmp_port'] ?? 0),
            'model' => (string)($row['model'] ?? ''),
            'driver_status' => (string)($row['driver_status'] ?? ''),
            'first_scan_started_at' => (string)($row['first_scan_started_at'] ?? ''),
            'total_onus' => (int)($row['total_onus'] ?? 0),
            'system_json' => json_encode(olt_system_snapshot($row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];
    }

    private function snmpRow(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'ip_address' => (string)($row['ip_address'] ?? ''),
            'snmp_port' => (int)($row['snmp_port'] ?? 0),
            'model' => (string)($row['model'] ?? ''),
            'driver_status' => (string)($row['driver_status'] ?? ''),
            'system_json' => (string)($row['system_json'] ?? ''),
            'interfaces_json' => (string)($row['interfaces_json'] ?? ''),
        ];
    }

    private function onuRow(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'source' => (string)($row['source'] ?? ''),
            'type' => (string)($row['type'] ?? ''),
            'olt_id' => (int)($row['olt_id'] ?? 0),
            'olt_name' => (string)($row['olt_name'] ?? ''),
            'onu_index' => (string)($row['onu_index'] ?? ''),
            'onu_name' => (string)($row['onu_name'] ?? ''),
            'onu_location' => (string)($row['onu_location'] ?? ''),
            'onu_serial' => (string)($row['onu_serial'] ?? ''),
            'onu_state' => (string)($row['onu_state'] ?? ''),
            'rx_power' => $row['rx_power'] ?? null,
            'is_problem' => (int)($row['is_problem'] ?? 0),
            'problem_description' => $row['problem_description'] ?? null,
            'problem_since' => $row['problem_since'] ?? null,
        ];
    }
}
