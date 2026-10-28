<?php

declare(strict_types=1);

final class DataSnapshotReadService
{
    public function __construct(private LinnmsRepository $db)
    {
    }

    public function read(int $userId, array $params): array
    {
        $type = (string)($params['type'] ?? '');
        return match ($type) {
            'device-connection-status' => $this->connectionStatuses($userId, $params),
            'olt-scan-status' => $this->scanStatus($userId, $params),
            'onu-ethernet-port' => $this->onuEthernetPort($userId, $params),
            'zte-onu-ethernet', 'zte-onu-tr069', 'zte-onu-remote-interface' =>
                $this->onuDetailSnapshot($userId, $type, $params),
            default => ['ok' => false, 'code' => 'unsupported', 'data' => []],
        };
    }

    private function connectionStatuses(int $userId, array $params): array
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval',
            (array)($params['ids'] ?? [])), static fn(int $id): bool => $id > 0))), 0, 100);
        if ($ids === []) return ['ok' => true, 'code' => 'ok', 'data' => ['statuses' => []]];

        $rows = $this->db->getDeviceConnectionStatuses($userId, $ids);
        $activeScans = $this->db->getActiveOltScanRowIds($userId);
        $scanStatuses = $this->db->getLatestOltScanStatuses($userId);
        $statuses = [];
        foreach ($rows as $row) {
            $deviceId = (int)$row['device_id'];
            $statuses[(string)$deviceId] = [
                'seen' => trim((string)($row['seen_at'] ?? '')) !== '',
                'status' => (string)($row['driver_status'] ?? ''),
                'scan_active' => isset($activeScans[$deviceId]),
                'scan_failed' => ($scanStatuses[$deviceId]['status'] ?? '') === 'failed',
            ];
        }
        return ['ok' => true, 'code' => 'ok', 'data' => ['statuses' => $statuses]];
    }

    private function scanStatus(int $userId, array $params): array
    {
        $rowId = (int)($params['olt_row_id'] ?? 0);
        if ($rowId < 1) return $this->invalid($userId, 'olt_scan_status_validation');
        if (!is_array($this->db->getOltByRowId($userId, $rowId))) {
            return ['ok' => false, 'code' => 'not_found', 'data' => []];
        }
        $status = $this->db->getOltScanStatus($userId, $rowId);
        if (($status['status'] ?? '') === 'failed') {
            $status['last_error'] = 'Sinkronisasi OLT gagal. Periksa log aplikasi untuk detailnya.';
        }
        return ['ok' => true, 'code' => 'ok', 'data' => $status];
    }

    private function onuEthernetPort(int $userId, array $params): array
    {
        $rowId = (int)($params['onu_row_id'] ?? 0);
        $portNumber = (int)($params['port'] ?? 0);
        if ($rowId < 1 || $portNumber < 1) return $this->invalid($userId, 'onu_ethernet_port_validation');
        $onu = $this->db->getOnuByRowId($userId, $rowId);
        if (!is_array($onu)) return ['ok' => false, 'code' => 'not_found', 'data' => []];
        $definition = monitoring_driver_definition((string)device_driver_type($onu));
        $uiFamily = (string)($definition['ui_family'] ?? '');
        if (empty($definition['onu_ethernet_control'])) {
            return ['ok' => false, 'code' => 'unsupported', 'data' => []];
        }
        $selected = null;
        foreach ($this->db->getOnuEthernetPorts($userId, $rowId) as $port) {
            if (is_array($port) && (int)($port['port'] ?? 0) === $portNumber) {
                $selected = $port;
                break;
            }
        }
        $detail = json_decode((string)($onu['detail_json'] ?? ''), true);
        $count = is_array($detail) ? (int)($detail['port_count'] ?? 0) : 0;
        $limit = !empty($definition['onu_port_count_includes_optical']) ? $count - 1 : $count;
        if ($selected === null && ($limit < 1 || $portNumber > $limit)) {
            return ['ok' => false, 'code' => 'not_found', 'data' => []];
        }
        $selected ??= ['port' => $portNumber];
        $vlanIds = !empty($definition['onu_ethernet_vlan_control'])
            ? $this->db->getOltVlanIds($userId, (int)($onu['olt_device_id'] ?? 0)) : [];
        return ['ok' => true, 'code' => 'ok', 'data' => [
            'source' => 'database_snapshot',
            'port_row' => $selected,
            'ui_family' => $uiFamily,
            'vlan_ids' => $vlanIds,
            'can_write' => olt_driver_support_enabled($this->db->getOltDriverSupportFlags(), (string)device_driver_type($onu)),
        ]];
    }

    private function onuDetailSnapshot(int $userId, string $type, array $params): array
    {
        $rowId = (int)($params['onu_row_id'] ?? 0);
        $kind = (string)($params['kind'] ?? '');
        if ($rowId < 1 || ($type === 'zte-onu-remote-interface' && !in_array($kind, ['wifi', 'veip'], true))) {
            return $this->invalid($userId, $type . '_validation');
        }
        $onu = $this->db->getOnuByRowId($userId, $rowId);
        if (!is_array($onu)) return ['ok' => false, 'code' => 'not_found', 'data' => []];
        if ($type === 'zte-onu-ethernet') {
            return ['ok' => true, 'code' => 'ok', 'data' => [
                'ports' => $this->db->getOnuEthernetPorts($userId, $rowId),
                'source' => 'database_snapshot',
            ]];
        }
        $snapshot = $this->db->getOnuServiceConfig($userId,
            (int)($onu['olt_device_id'] ?? 0), (string)($onu['onu_index'] ?? ''));
        $config = (array)($snapshot['config'] ?? []);
        if ($type === 'zte-onu-remote-interface') {
            return ['ok' => true, 'code' => 'ok', 'data' => [
                'ports' => array_values((array)($config[$kind . '_interfaces'] ?? [])),
                'source' => 'database_snapshot',
            ]];
        }
        $rows = (array)($config['tr069_mgmt'] ?? []);
        $row = reset($rows);
        if (!is_array($row)) return ['ok' => false, 'code' => 'device_rejected', 'data' => []];
        return ['ok' => true, 'code' => 'ok', 'data' => ['tr069' => $row, 'source' => 'database_snapshot']];
    }

    private function invalid(int $userId, string $action): array
    {
        return ['ok' => false, 'code' => 'validation', 'data' => []];
    }
}
