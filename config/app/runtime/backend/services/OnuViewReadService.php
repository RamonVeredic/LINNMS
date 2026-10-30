<?php

declare(strict_types=1);

require_once __DIR__ . '/OltCapabilities.php';

final class OnuViewReadService
{
    public function __construct(private LinnmsRepository $db)
    {
    }

    public function read(int $userId, int $rowId): array
    {
        if ($userId < 1 || $rowId < 1) throw new InvalidArgumentException('Identitas ONU tidak valid.');
        $onu = $this->db->getOnuByRowId($userId, $rowId);
        if (!is_array($onu)) throw new InvalidArgumentException('ONU tidak ditemukan.');

        $oltId = (int)($onu['olt_device_id'] ?? 0);
        $olt = $this->db->getOltByRowId($userId, $oltId);
        $driverId = device_driver_type($onu);
        $definition = monitoring_driver_definition($driverId) ?? [];
        $capabilities = OltCapabilities::compute(
            $definition,
            olt_driver_support_enabled($this->db->getOltDriverSupportFlags(), $driverId),
            is_array($olt) ? $olt : []
        );

        $serviceRow = $this->db->getOnuServiceConfig($userId, $oltId, (string)($onu['onu_index'] ?? ''));
        $vlanRows = $this->db->getOltVlans($userId, $oltId);
        $vlanNames = [];
        foreach ($vlanRows as $vlanRow) {
            $data = (array)($vlanRow['data'] ?? []);
            $id = (int)($data['vlan_id'] ?? $vlanRow['name'] ?? 0);
            $name = trim((string)($data['vlan_name'] ?? ''));
            if ($id > 0 && $name !== '') $vlanNames[$id] = $name;
        }

        $historySource = trim((string)($onu['source'] ?? 'snmp'));
        if ($historySource === '') $historySource = 'snmp';
        $historyKey = $historySource . '|' . (int)($onu['olt_id'] ?? 0) . '|' . trim((string)($onu['onu_index'] ?? ''));
        $stateHistory = [];
        $signalHistory = [];
        $rxThreshold = (float)($onu['problem_rx_power_threshold_dbm'] ?? LinnmsRepository::ONU_RX_PROBLEM_DEFAULT_DBM);
        try {
            $stateHistory = $this->db->getOnuStateHistory($userId, $historyKey, 4);
        } catch (Throwable $error) {
            error_log('[LINNMS] ONU state history: ' . $error->getMessage());
        }
        try {
            $signalHistory = $this->db->getOnuSignalHistory($userId, $historyKey, 4);
        } catch (Throwable $error) {
            error_log('[LINNMS] ONU signal history: ' . $error->getMessage());
        }
        try {
            $rxThreshold = (float)$this->db->getOnuProblemSettings($userId)['rx_power_threshold_dbm'];
        } catch (Throwable $error) {
            error_log('[LINNMS] ONU problem settings: ' . $error->getMessage());
        }

        return [
            'driver_id' => $driverId,
            'is_snmp_driver' => monitoring_row_uses_handler($onu, 'snmp_olt'),
            'olt_type_label' => device_driver_label($onu),
            'detail_definition' => [
                'implementation' => (string)($definition['implementation'] ?? ''),
                'ui_family' => (string)($definition['ui_family'] ?? ''),
                'onu_detail_fields' => is_array($definition['onu_detail_fields'] ?? null) ? $definition['onu_detail_fields'] : [],
            ],
            'capabilities' => $capabilities,
            'service_row' => is_array($serviceRow) ? $serviceRow : [],
            'replacement_onus' => !in_array(trim((string)($onu['auth_mode'] ?? '')), ['Pass', 'Deny'], true)
                ? $this->db->getUnregisteredOnusByDevice($userId, $oltId, $rowId) : [],
            'vlan_ids' => $this->db->getOltVlanIds($userId, $oltId),
            'vlan_names' => $vlanNames,
            'available_onu_types' => !empty($definition['onu_type_catalog'])
                ? $this->db->getAvailableOnuTypes($userId, $oltId) : [],
            'tcont_profiles' => $this->db->getOltProfileSnapshots($userId, $oltId, 'tcont'),
            'traffic_profiles' => $this->db->getOltProfileSnapshots($userId, $oltId, 'traffic'),
            'state_history' => $stateHistory,
            'signal_history' => $signalHistory,
            'rx_problem_threshold_dbm' => $rxThreshold,
        ];
    }
}
