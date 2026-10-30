<?php

declare(strict_types=1);

final class DriverExternalActionService
{
    private ?OltSnapshotWriteService $snapshotWriter = null;
    private ?OltConfigurationWriteService $configWriter = null;

    public function __construct(
        private LinnmsRepository $db,
        private array $config,
        private ApiClientFactory $clients
    ) {
    }

    public function execute(int $userId, string $action, array $params): array
    {
        if ($userId < 1) return ['ok' => false, 'code' => 'forbidden'];
        if (!in_array($action, ['test_api', 'add_olt', 'update_api_olt', 'scan_olt', 'reboot_onu'], true)) {
            return ['ok' => false, 'code' => 'unsupported'];
        }

        if ($action === 'add_olt') {
            $quota = $this->db->getUserOltQuota($userId);
            if (empty($quota['can_add'])) return ['ok' => false, 'code' => 'quota', 'data' => $quota];
        }

        $client = $this->clients->forUser($userId);
        if ($client === null) return ['ok' => false, 'code' => 'api_unavailable'];
        $adapter = $this->adapter($client);

        return match ($action) {
            'test_api' => $adapter->test(),
            'add_olt' => $this->add($userId, $adapter, $params),
            'update_api_olt' => $this->update($userId, $adapter, $params),
            'scan_olt' => $this->scan($userId, $adapter, $params),
            'reboot_onu' => $this->reboot($userId, $adapter, $params),
        };
    }

    private function add(int $userId, DriverExternalAdapter $adapter, array $params): array
    {
        $payload = [
            'olt_name' => trim((string)($params['olt_name'] ?? '')),
            'ip_address' => trim((string)($params['ip_address'] ?? '')),
            'port' => (int)($params['port'] ?? 0),
            'community' => trim((string)($params['community'] ?? '')),
            'olt_type' => trim((string)($params['olt_type'] ?? '')),
        ];
        if (!$this->validOltPayload($payload)) {
            return ['ok' => false, 'code' => 'validation'];
        }
        if (!api_olt_type_support_enabled($this->db->getApiOltTypeSupportFlags(), $payload['olt_type'])) {
            return ['ok' => false, 'code' => 'driver_disabled'];
        }
        if ($this->restoreHiddenOltByConnection($userId, $adapter, $payload)) {
            return ['ok' => true, 'code' => 'ok'];
        }
        $result = $adapter->createOlt($payload);
        if (empty($result['ok'])) {
            $this->logError($userId, 'add_olt', (string)($result['message'] ?? 'External API add OLT gagal.'));
            return ['ok' => false, 'code' => 'device_rejected'];
        }
        $this->restoreHiddenOltByConnection($userId, $adapter, $payload);
        return ['ok' => true, 'code' => 'ok'];
    }

    private function restoreHiddenOltByConnection(int $userId, DriverExternalAdapter $adapter, array $payload): bool
    {
        $ignored = array_fill_keys($this->db->getIgnoredApiOltIds($userId), true);
        if ($ignored === []) return false;
        $listed = $adapter->fetchOlts();
        if (empty($listed['ok'])) return false;
        $matches = [];
        foreach ((array)($listed['olts'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $oltId = valid_positive_id($item['id'] ?? $item['olt_id'] ?? null);
            if ($oltId === null || !isset($ignored[$oltId])) continue;
            if (trim((string)($item['ip_address'] ?? '')) !== $payload['ip_address']) continue;
            if ((int)($item['port'] ?? $item['snmp_port'] ?? 0) !== $payload['port']) continue;
            $matches[] = $oltId;
        }
        return count($matches) === 1 && $this->db->restoreIgnoredApiOlt($userId, $matches[0]);
    }

    private function update(int $userId, DriverExternalAdapter $adapter, array $params): array
    {
        $rowId = valid_positive_id($params['olt_row_id'] ?? null);
        $olt = $rowId !== null ? $this->db->getOltByRowId($userId, $rowId) : null;
        if (!is_array($olt) || (string)($olt['source'] ?? '') !== 'api'
            || olt_monitoring_driver($olt) !== 'external_olt_api') {
            return ['ok' => false, 'code' => 'not_found'];
        }
        $oltId = valid_positive_id($olt['olt_id'] ?? null);
        if ($oltId === null) {
            return ['ok' => false, 'code' => 'validation'];
        }
        $payload = [
            'olt_name' => trim((string)($params['olt_name'] ?? '')),
            'ip_address' => trim((string)($params['ip_address'] ?? '')),
            'port' => (int)($params['port'] ?? 0),
            'community' => trim((string)($params['community'] ?? '')),
            'olt_type' => (string)($olt['type'] ?? ''),
        ];
        if ($payload['community'] === '') {
            $stored = json_decode((string)($olt['raw_json'] ?? ''), true);
            $payload['community'] = is_array($stored) ? trim((string)($stored['community'] ?? '')) : '';
            if ($payload['community'] === '') {
                $payload['community'] = trim((string)($olt['snmp_community'] ?? ''));
            }
        }
        if (!$this->validOltPayload($payload)) {
            return ['ok' => false, 'code' => 'validation'];
        }
        $result = $adapter->updateOlt($oltId, $payload);
        if (empty($result['ok'])) {
            $this->logError($userId, 'update_api_olt', (string)($result['message'] ?? 'External API update OLT gagal.'));
            return ['ok' => false, 'code' => 'device_rejected'];
        }
        $this->configWriter()->writeApiConnection($userId, $rowId, $payload['olt_name'], $payload['ip_address'], $payload['port']);
        return ['ok' => true, 'code' => 'ok'];
    }

    private function scan(int $userId, DriverExternalAdapter $adapter, array $params): array
    {
        $id = valid_positive_id($params['olt_id'] ?? null);
        $olt = $id !== null ? $this->db->getOlt($userId, $id) : null;
        if (!is_array($olt) || (string)($olt['source'] ?? 'api') !== 'api') {
            return ['ok' => false, 'code' => 'not_found'];
        }
        $scan = $adapter->requestScan($id);
        if (($scan['state'] ?? '') === 'error') {
            $this->logError($userId, 'scan_olt', (string)($scan['message'] ?? 'External API scan OLT gagal.'));
            return ['ok' => false, 'code' => 'device_rejected'];
        }
        if (($scan['state'] ?? '') === 'scanning' && empty($scan['running'])) {
            return ['ok' => true, 'code' => 'scanning'];
        }
        if (($scan['state'] ?? '') === 'scanning') {
            $this->configWriter()->markApiFirstScanStarted($userId, $id);
            $this->storeScanOnus($userId, $id, $adapter);
            return ['ok' => true, 'code' => 'scanning'];
        }
        $this->configWriter()->markApiFirstScanStarted($userId, $id);
        $snapshot = $this->storeScanOnus($userId, $id, $adapter);
        return ['ok' => $snapshot['state'] !== 'error', 'code' => $snapshot['state'], 'data' => ['count' => $snapshot['count']]];
    }

    private function reboot(int $userId, DriverExternalAdapter $adapter, array $params): array
    {
        $id = valid_positive_id($params['olt_id'] ?? null);
        $index = trim((string)($params['onu_index'] ?? ''));
        if ($id === null || $index === '') {
            return ['ok' => false, 'code' => 'validation'];
        }
        $result = $adapter->rebootOnu($id, $index);
        if (empty($result['ok'])) {
            $this->logError($userId, 'reboot_onu', (string)($result['message'] ?? 'External API reboot ONU gagal.'));
            return ['ok' => false, 'code' => 'device_rejected'];
        }
        return ['ok' => true, 'code' => 'ok'];
    }

    private function validOltPayload(array $payload): bool
    {
        return $payload['olt_name'] !== '' && $payload['ip_address'] !== ''
            && $payload['port'] >= 1 && $payload['port'] <= 65535
            && $payload['community'] !== '' && array_key_exists($payload['olt_type'], olt_type_options());
    }

    private function storeScanOnus(int $userId, int $oltId, DriverExternalAdapter $adapter): array
    {
        $result = $adapter->fetchOnus($oltId);
        if (($result['state'] ?? '') === 'error') {
            $this->logError($userId, 'scan_olt_result', (string)($result['message'] ?? 'External API scan result gagal.'));
            return ['state' => 'error', 'count' => 0];
        }
        $items = (array)($result['onus'] ?? []);
        if (($result['state'] ?? '') === 'scanning' || $items === []) {
            return ['state' => 'scanning', 'count' => 0];
        }
        $settings = $this->db->getUserSettings($userId);
        $this->snapshotWriter()->writeDriverExternalOnuInventory($userId, $oltId, $items, effective_signal_threshold_dbm($settings), false);
        $count = count($this->db->getOnus($userId, $oltId, true, 'api'));
        if ($count < 1) return ['state' => 'scanning', 'count' => 0];
        $this->snapshotWriter()->markMonitoringSync($userId, 'external_olt_api');
        return ['state' => 'success', 'count' => $count];
    }

    private function logError(int $userId, string $action, string $message): void
    {
        try {
            $this->db->logApplication($userId, 'API', $action, 'error', $message);
        } catch (Throwable $error) {
            error_log('[LINNMS] External API action log: ' . $error->getMessage());
        }
    }

    private function adapter(ApiClient $client): DriverExternalAdapter
    {
        require_once project_path('vendor', 'driver', 'olt', 'zetset', 'Adapter.php');
        return new DriverExternalAdapter($client, $this->config);
    }

    private function snapshotWriter(): OltSnapshotWriteService
    {
        if ($this->snapshotWriter === null) {
            require_once __DIR__ . '/OltSnapshotWriteService.php';
            $this->snapshotWriter = new OltSnapshotWriteService($this->db);
        }
        return $this->snapshotWriter;
    }

    private function configWriter(): OltConfigurationWriteService
    {
        if ($this->configWriter === null) {
            require_once __DIR__ . '/OltConfigurationWriteService.php';
            $this->configWriter = new OltConfigurationWriteService($this->db);
        }
        return $this->configWriter;
    }
}
