<?php

declare(strict_types=1);

final class DashboardReadService
{
    private ActivityReadService $activity;

    public function __construct(
        private LinnmsRepository $db,
        private array $driverCatalog
    ) {
        $this->activity = new ActivityReadService($db);
    }

    public function read(int $userId, array $params): array
    {
        $olts = $this->db->getOlts($userId);
        $mikrotik = $this->db->getSnmpDevices($userId, 'mikrotik');
        $switch = $this->db->getSnmpDevices($userId, 'cisco_catalyst_2960s');
        $onus = $this->db->getOnus($userId, null, true, null);
        $problemOnus = $this->db->getProblemOnus($userId, 1000);
        $settings = $this->db->getUserSettings($userId);
        $activeScans = $this->db->getActiveOltScanRowIds($userId);
        $scanStatuses = $this->db->getLatestOltScanStatuses($userId);
        $includeActivity = !array_key_exists('include_activity', $params) || (bool)$params['include_activity'];
        $hasZte = false;
        foreach ($olts as $olt) {
            if (monitoring_device_is_zte($olt)) {
                $hasZte = true;
                break;
            }
        }

        return [
            'has_zte' => $hasZte,
            'stats' => $this->stats($olts, $onus, $problemOnus),
            'devices' => [
                'olt' => array_map(fn(array $row): array => $this->olt($row), $olts),
                'mikrotik' => array_map(fn(array $row): array => $this->snmpDevice($row, 'mikrotik'), $mikrotik),
                'switch' => array_map(fn(array $row): array => $this->snmpDevice($row, 'switch'), $switch),
            ],
            'scan' => [
                'active_olt_row_ids' => array_values(array_map('intval', array_keys($activeScans))),
                'latest_statuses' => $this->scanStatuses($scanStatuses),
            ],
            'settings' => [
                'last_sync_at' => trim((string)($settings['last_sync_at'] ?? '')),
                'last_api_sync_at' => trim((string)($settings['last_api_sync_at'] ?? '')),
            ],
            'quota' => [
                'olt' => $this->quota($this->db->getUserOltQuota($userId)),
                'mikrotik' => $this->quota($this->db->getUserSnmpDeviceQuota($userId, 'mikrotik')),
                'switch' => $this->quota($this->db->getUserSnmpDeviceQuota($userId, 'cisco_catalyst_2960s')),
            ],
            'activity' => $includeActivity
                ? $this->activity->read($userId, $params)
                : ['page' => 1, 'pages' => 1, 'total' => 0, 'page_size' => LinnmsRepository::ACTIVITY_FEED_PAGE_SIZE, 'search' => '', 'device' => '', 'events' => []],
            'generated_at' => gmdate('c'),
        ];
    }

    private function stats(array $olts, array $onus, array $problemOnus): array
    {
        $online = 0;
        $down = 0;
        $loss = 0;
        $powerDown = 0;

        foreach ($onus as $onu) {
            $state = state_key($onu['onu_state'] ?? null);
            if ($state === 'online') $online++;
            if ($state === 'offline') $down++;
            if ($state === 'loss') $loss++;
            if ($state === 'powerdown') $powerDown++;
        }

        return [
            'total_olts' => count($olts),
            'total_onus' => count($onus),
            'online_onus' => $online,
            'down_onus' => $down,
            'offline_onus' => $down,
            'loss_onus' => $loss,
            'power_down_onus' => $powerDown,
            'problem_onus' => count($problemOnus),
        ];
    }

    private function olt(array $row): array
    {        $source = (string)($row['source'] ?? '');
        $type = (string)($row['type'] ?? '');
        $driverId = $source === 'api' ? 'external_olt_api' : $type;
        $definition = is_array($this->driverCatalog[$driverId] ?? null) ? $this->driverCatalog[$driverId] : [];
        $system = $this->jsonObject($row['system_json'] ?? '');
        $model = trim((string)($row['model'] ?? ($system['model'] ?? '')));
        $systemName = trim((string)($system['system_name'] ?? ($system['identity'] ?? '')));

        return [
            'id' => (int)($row['id'] ?? 0),
            'olt_id' => (int)($row['olt_id'] ?? 0),
            'source' => $source,
            'type' => $type,
            'device_type' => (string)($row['device_type'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'ip_address' => (string)($row['ip_address'] ?? ''),
            'snmp_port' => (int)($row['snmp_port'] ?? 0),
            'model' => $model,
            'system_name' => $systemName,
            'seen_at' => (string)($row['seen_at'] ?? ''),
            'driver_status' => (string)($row['driver_status'] ?? ''),
            'cli_status' => (string)($row['cli_status'] ?? ''),
            'snmp_fail_count' => (int)($row['snmp_fail_count'] ?? 0),
            'cli_fail_count' => (int)($row['cli_fail_count'] ?? 0),
            'hybrid_status' => (int)($row['hybrid_status'] ?? 0),
            'total_onus' => (int)($row['total_onus'] ?? 0),
            'is_present' => (int)($row['is_present'] ?? 1),
            'is_api_driver' => (string)($definition['handler'] ?? '') === 'external_api',
            'is_snmp_driver' => (string)($definition['handler'] ?? '') === 'snmp_olt',
            'driver_label' => (string)($definition['label'] ?? ''),
        ];
    }

    private function snmpDevice(array $row, string $kind): array
    {
        $system = $this->jsonObject($row['system_json'] ?? '');
        $interfaces = $this->jsonList($row['interfaces_json'] ?? '');
        $model = trim((string)($row['model'] ?? ($system['model'] ?? '')));
        $systemName = trim((string)($system['identity'] ?? ($system['system_name'] ?? '')));
        $visibleInterfaces = array_values(array_filter(
            $interfaces,
            static function (mixed $interface) use ($kind): bool {
                if (!is_array($interface)) return false;
                $name = trim((string)($interface['name'] ?? ''));
                if ($kind === 'switch') {
                    return preg_match('/^(?:stackport|stacksub(?:-|$))/i', $name) !== 1;
                }
                $type = strtolower(trim((string)($interface['interface_type'] ?? '')));
                return $type !== 'pppoe' && !str_contains(strtolower($name), 'pppoe');
            }
        ));

        return [
            'id' => (int)($row['id'] ?? 0),
            'device_type' => (string)($row['device_type'] ?? ''),
            'type' => (string)($row['type'] ?? ''),
            'kind' => $kind,
            'name' => (string)($row['name'] ?? ''),
            'ip_address' => (string)($row['ip_address'] ?? ''),
            'snmp_port' => (int)($row['snmp_port'] ?? 0),
            'model' => $model,
            'system_name' => $systemName,
            'interface_count' => count($visibleInterfaces),
            'seen_at' => (string)($row['seen_at'] ?? ''),
            'driver_status' => (string)($row['driver_status'] ?? ''),
            'is_active' => (string)($row['driver_status'] ?? '') === 'online',
        ];
    }

    private function scanStatuses(array $rows): array
    {
        $statuses = [];
        foreach ($rows as $id => $row) {
            if (!is_array($row)) continue;
            $statuses[(string)(int)$id] = [
                'status' => (string)($row['status'] ?? ''),
                'last_error' => (string)($row['status'] ?? '') === 'failed'
                    ? 'Sinkronisasi OLT gagal. Periksa log aplikasi untuk detailnya.' : '',
                'progress' => max(0, min(100, (int)($row['progress'] ?? 0))),
                'phase' => (string)($row['phase'] ?? ''),
            ];
        }
        return $statuses;
    }

    private function quota(array $quota): array
    {
        return [
            'used' => (int)($quota['used'] ?? 0),
            'limit' => (int)($quota['limit'] ?? 0),
            'user_limit' => (int)($quota['user_limit'] ?? 0),
            'remaining' => (int)($quota['remaining'] ?? 0),
            'can_add' => (bool)($quota['can_add'] ?? false),
        ];
    }

    private function jsonObject(mixed $value): array
    {
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function jsonList(mixed $value): array
    {
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
