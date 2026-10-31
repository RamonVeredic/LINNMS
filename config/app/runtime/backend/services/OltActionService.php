<?php

declare(strict_types=1);

final class OltActionService
{
    private ?OltSnapshotWriteService $snapshotWriter = null;
    private ?OltConfigurationWriteService $configWriter = null;

    public function __construct(
        private LinnmsRepository $db,
        private SnmpOltService $snmp,
        private ApiClientFactory $apiClients,
        private array $config
    ) {
    }

    public function execute(int $userId, string $action, array $data): array
    {
        $user = $this->db->getUser($userId);
        if (!is_array($user) || (int)($user['is_active'] ?? 0) !== 1) return ['ok' => false, 'code' => 'forbidden'];
        try {
            return match ($action) {
                'approve_api_olt_first_scan' => $this->approveFirstScan($userId, $user, $data),
                'add_olt' => $this->add($userId, $data),
                'sync_snmp_olt' => $this->sync($userId, $data),
                'update_snmp_olt_port_description' => $this->updatePonDescription($userId, $data),
                'save_olt_pon_description_local' => $this->saveLocalPonDescription($userId, $data),
                'save_olt_card_label' => $this->saveCardLabel($userId, $user, $data),
                'refresh_olt' => $this->refresh($userId, $data, true),
                'refresh_olt_view' => $this->refresh($userId, $data, false),
                'scan_snmp_olt' => $this->scan($userId, $data),
                'save_olt_pon_port_config' => $this->savePonPortConfig($userId, $data),
                'save_olt_pon_modal' => $this->savePonModal($userId, $data),
                'save_olt_cli_connection' => $this->saveCliConnection($userId, $data),
                'test_olt_cli_connection' => $this->cliRead($userId, $action, $data),
                'refresh_olt_cli_optical' => $this->cliRead($userId, $action, $data),
                'refresh_onu_cli_optical' => $this->cliRead($userId, $action, $data),
                'save_olt_profile' => $this->profile($userId, $action, $data),
                'delete_olt_profile' => $this->profile($userId, $action, $data),
                'save_olt_vlan_port' => $this->saveVlanPort($userId, $data),
                'save_olt_vlan_members' => $this->saveVlanMembers($userId, $data),
                'save_zte_vlan', 'delete_zte_vlan' => ['ok' => false, 'code' => 'unsupported'],
                default => ['ok' => false, 'code' => 'unsupported'],
            };
        } catch (Throwable $error) {
            return $this->fail($userId, $action, 'internal', (string)$error);
        }
    }

    private function fail(int $userId, string $action, string $code, string $detail = '', array $data = []): array
    {
        $validation = preg_match('/(?:_invalid|_required|_empty)$/', $code) === 1;
        $driver = in_array($code, ['device_failed', 'device_rejected', 'device_unreachable', 'device_auth_failed', 'partial'], true);
        if (!$validation || in_array($action, ['save_olt_card_label', 'save_olt_cli_connection'], true)) {
            try {
                $this->db->logApplication($userId, $driver ? 'Driver' : 'OLT',
                    $action . ($driver ? '_driver' : ''),
                    'error', $detail !== '' ? $detail : $code);
            } catch (Throwable $error) {
                error_log('[LINNMS] OLT action log: ' . $error->getMessage());
            }
        }
        return ['ok' => false, 'code' => $code, 'data' => $data];
    }

    private function result(int $userId, string $action, array $result, string $errorCode = 'device_failed'): array
    {
        if (empty($result['ok'])) {
            if (!empty($result['driver_disabled']) || (string)($result['code'] ?? '') === 'forbidden') {
                return ['ok' => false, 'code' => 'forbidden', 'data' => []];
            }
            $detail = trim((string)($result['message'] ?? ''));
            return $this->fail($userId, $action, $errorCode,
                $detail !== '' ? $detail : raw_result_error($result, $action));
        }
        return ['ok' => true, 'code' => !empty($result['partial']) ? 'partial' : 'ok', 'data' => [
            'changed' => !empty($result['changed']),
            'rx_power' => $result['rx_power'] ?? null,
            'tx_power' => $result['tx_power'] ?? null,
        ]];
    }

    private function rowId(array $data): ?int
    {
        return valid_positive_id($data['olt_row_id'] ?? null);
    }

    private function olt(int $userId, array $data, bool $secret = false): ?array
    {
        $rowId = $this->rowId($data);
        return $rowId === null ? null : $this->db->getOltByRowId($userId, $rowId, $secret);
    }

    private function ponFields(): array
    {
        return [
            'olt_port_enable' => ['label' => 'Status Port', 'options' => ['Enable', 'Disable']],
            'olt_port_autoneg' => ['label' => 'Auto Negotiation', 'options' => ['Enable', 'Disable']],
            'olt_port_speed' => ['label' => 'Kecepatan Port', 'options' => ['1000M', '100M']],
            'olt_port_duplex' => ['label' => 'Mode Duplex', 'options' => ['Full', 'Half']],
            'olt_port_flow_control' => ['label' => 'Flow Control', 'options' => ['Enable', 'Disable']],
        ];
    }

    private function approveFirstScan(int $userId, array $user, array $data): array
    {
        if ((string)($user['role'] ?? '') !== 'admin') return $this->fail($userId, 'approve_api_olt_first_scan', 'forbidden');
        $rowId = $this->rowId($data);
        if ($rowId === null) return $this->fail($userId, 'approve_api_olt_first_scan', 'olt_invalid');
        $approved = $this->db->approveApiOltFirstScan($rowId, $userId);
        if (!is_array($approved) || empty($approved['first_scan_approved_at'])) {
            return $this->fail($userId, 'approve_api_olt_first_scan', 'scan_not_found');
        }
        return ['ok' => true, 'code' => 'ok', 'data' => [
            'name' => (string)($approved['name'] ?? ''),
            'olt_id' => (string)($approved['olt_id'] ?? ''),
            'username' => (string)($approved['username'] ?? ''),
        ]];
    }

    private function add(int $userId, array $data): array
    {
        $driver = monitoring_driver_definition(trim((string)($data['driver_id'] ?? '')));
        if (!is_array($driver) || ($driver['handler'] ?? '') !== 'snmp_olt') {
            return $this->fail($userId, 'add_olt', 'driver_invalid');
        }
        $result = $this->snmp->create($userId, $data);
        if (!empty($result['ok'])) return ['ok' => true, 'code' => 'ok'];
        $raw = raw_result_error($result, 'SnmpOltService::create()');
        error_log('[LINNMS] Tambah OLT SNMP gagal: ' . $raw);
        $first = trim((string)($result['errors'][0] ?? ''));
        $code = match ($first) {
            'Nama perangkat harus diisi.' => 'name_required',
            'Periksa IP Anda.' => 'ip_invalid',
            'Community harus diisi.' => 'community_required',
            'Periksa port (1-65535), Community (SNMP), Port Telnet, Username, dan Password.' => 'port_invalid',
            'Username harus diisi.' => 'username_required',
            'Password harus diisi.' => 'password_required',
            'Driver OLT SNMP belum didukung.' => 'driver_invalid',
            'Driver OLT ini tidak diizinkan atau sedang dinonaktifkan.' => 'driver_disabled',
            'Batas OLT keseluruhan telah tercapai.' => 'quota_total',
            'Batas OLT akun telah tercapai.' => 'quota_user',
            default => 'create_failed',
        };
        return $this->fail($userId, 'add_olt', $code, $raw);
    }

    private function sync(int $userId, array $data): array
    {
        $rowId = $this->rowId($data);
        if ($rowId === null) return $this->fail($userId, 'sync_snmp_olt', 'olt_invalid');
        if (!is_array($this->olt($userId, $data))) {
            return ['ok' => false, 'code' => 'not_found', 'data' => []];
        }
        return $this->result($userId, 'sync_snmp_olt',
            (new OltSyncOrchestrator($this->db, $this->snmp))->syncManual($userId, $rowId));
    }

    private function updatePonDescription(int $userId, array $data): array
    {
        $rowId = $this->rowId($data);
        if ($rowId === null) return $this->fail($userId, 'update_snmp_olt_port_description', 'olt_invalid');
        return $this->result($userId, 'update_snmp_olt_port_description', $this->snmp->updatePonPortDescription(
            $userId, $rowId, (int)($data['olt_port_number'] ?? 0),
            (string)($data['olt_port_description'] ?? ''), (string)($data['olt_port_clear'] ?? '0') === '1',
            trim((string)($data['olt_port_ref'] ?? ''))));
    }

    private function saveLocalPonDescription(int $userId, array $data): array
    {
        $rowId = $this->rowId($data);
        if ($rowId === null) return $this->fail($userId, 'save_olt_pon_description_local', 'olt_invalid');
        $olt = $this->olt($userId, $data);
        if (!is_array($olt)) return $this->fail($userId, 'save_olt_pon_description_local', 'olt_not_found');
        $saved = $this->configWriter()->writePonDescription($userId, (int)$olt['id'],
            trim((string)($data['olt_port_ref'] ?? '')), (int)($data['olt_port_number'] ?? 0),
            (string)($data['olt_port_description'] ?? ''), (string)($olt['source'] ?? 'snmp'));
        return !empty($saved['ok']) ? ['ok' => true, 'code' => 'ok']
            : $this->fail($userId, 'save_olt_pon_description_local', 'save_failed', (string)($saved['message'] ?? ''));
    }

    private function saveCardLabel(int $userId, array $user, array $data): array
    {
        if ((string)($user['role'] ?? '') !== 'admin') return $this->fail($userId, 'save_olt_card_label', 'forbidden');
        $rowId = $this->rowId($data);
        $key = trim((string)($data['card_key'] ?? ''));
        if ($rowId === null || $key === '') return $this->fail($userId, 'save_olt_card_label', 'card_invalid');
        $saved = $this->configWriter()->writeCardLabel($userId, $rowId, $key, trim((string)($data['card_label'] ?? '')));
        return $saved ? ['ok' => true, 'code' => 'ok'] : $this->fail($userId, 'save_olt_card_label', 'card_not_found');
    }

    private function refresh(int $userId, array $data, bool $strictDriver): array
    {
        $action = $strictDriver ? 'refresh_olt' : 'refresh_olt_view';
        $rowId = $this->rowId($data);
        if ($rowId === null) return $this->fail($userId, $action, 'olt_invalid');
        $olt = $this->olt($userId, $data);
        if (!is_array($olt)) return $this->fail($userId, $action, 'olt_not_found');
        if (!$strictDriver && olt_monitoring_driver($olt) === 'external_olt_api') {
            $client = $this->apiClients->forUser($userId);
            if ($client === null) return $this->fail($userId, $action, 'api_unavailable');
            $apiOltId = (int)($olt['olt_id'] ?? 0);
            if ($apiOltId < 1) return $this->fail($userId, $action, 'api_id_invalid');
            $adapter = $this->externalAdapter($client);
            $scan = $adapter->requestScan($apiOltId);
            if (($scan['state'] ?? '') === 'error') {
                return $this->fail($userId, $action, 'api_failed', (string)($scan['message'] ?? 'External API scan OLT gagal.'));
            }
            if (($scan['state'] ?? '') === 'scanning' && empty($scan['running'])) {
                return ['ok' => true, 'code' => 'scanning'];
            }
            $stored = $this->storeApiScanOnus($userId, $apiOltId, $adapter);
            $state = (string)($stored['state'] ?? 'scanning');
            if ($state === 'error') return $this->fail($userId, $action, 'api_failed', (string)($stored['detail'] ?? ''));
            return ['ok' => true, 'code' => $state === 'scanning' ? 'scanning' : 'ok',
                'data' => ['count' => (int)($stored['count'] ?? 0)]];
        }
        return $this->result($userId, $action,
            (new OltSyncOrchestrator($this->db, $this->snmp))->refreshView($userId, $rowId));
    }

    private function storeApiScanOnus(int $userId, int $apiOltId, DriverExternalAdapter $adapter): array
    {
        $result = $adapter->fetchOnus($apiOltId);
        if (($result['state'] ?? '') === 'error') {
            return ['state' => 'error', 'detail' => (string)($result['message'] ?? '')];
        }
        if (($result['state'] ?? '') === 'scanning') {
            return ['state' => 'scanning', 'count' => 0];
        }
        $valid = (array)($result['onus'] ?? []);
        if ($valid === []) return ['state' => 'scanning', 'count' => 0];
        $threshold = effective_signal_threshold_dbm($this->db->getUserSettings($userId));
        $this->snapshotWriter()->writeDriverExternalOnuInventory($userId, $apiOltId, $valid, $threshold, false);
        $count = count($this->db->getOnus($userId, $apiOltId, true, 'api'));
        if ($count < 1) return ['state' => 'scanning', 'count' => 0];
        $this->snapshotWriter()->markMonitoringSync($userId, 'external_olt_api');
        return ['state' => 'success', 'count' => $count];
    }

    private function scan(int $userId, array $data): array
    {
        $rowId = $this->rowId($data);
        if ($rowId === null) return $this->fail($userId, 'scan_snmp_olt', 'olt_invalid');
        $olt = $this->olt($userId, $data);
        if (!is_array($olt)) return $this->fail($userId, 'scan_snmp_olt', 'olt_not_found');
        $queued = $this->db->requestOltScan($userId, $rowId);
        return ['ok' => true, 'code' => $queued ? 'queued' : 'running'];
    }

    private function savePonPortConfig(int $userId, array $data): array
    {
        $action = 'save_olt_pon_port_config';
        $rowId = $this->rowId($data);
        if ($rowId === null) return $this->fail($userId, $action, 'olt_invalid');
        $olt = $this->olt($userId, $data);
        if (!is_array($olt)) return $this->fail($userId, $action, 'olt_not_found');
        $fields = $this->ponFields();
        $submitted = [];
        foreach ($fields as $name => $definition) {
            if (!array_key_exists($name, $data)) continue;
            $value = trim((string)$data[$name]);
            if ($value === '') continue;
            if (!in_array($value, $definition['options'], true)) {
                return $this->fail($userId, $action, 'config_invalid', $name, ['field' => $name]);
            }
            $submitted[$name] = $value;
        }
        if ($submitted === []) return $this->fail($userId, $action, 'config_empty');
        $name = (string)array_key_first($submitted);
        $value = (string)$submitted[$name];
        $previous = trim((string)($data['olt_port_previous_value'] ?? ''));
        if ($value === $previous) return ['ok' => true, 'code' => 'unchanged', 'data' => ['field' => $name]];
        $definition = olt_driver_definition((string)device_driver_type($olt));
        $supported = is_array($definition) ? (array)($definition['pon_port_config'] ?? []) : [];
        $attempted = in_array($name, $supported, true);
        $applied = false;
        if ($attempted) {
            $result = $this->snmp->updatePonPortConfig($userId, $rowId,
                max(1, (int)($data['olt_port_number'] ?? 0)), $name, $value,
                trim((string)($data['olt_port_ref'] ?? '')));
            $applied = !empty($result['ok']);
            if (!$applied) $this->fail($userId, $action, 'device_failed', (string)($result['message'] ?? ''));
        }
        $number = (int)($data['olt_port_number'] ?? 0);
        $this->db->recordOltPonConfigEvent($userId, (int)$olt['olt_id'], (string)($olt['source'] ?? 'snmp'),
            $number, $applied, $this->db->getUserDisplayName($userId), (string)$fields[$name]['label'],
            $previous, $value);
        return ['ok' => $applied, 'code' => $applied ? 'ok' : ($attempted ? 'device_failed' : 'unsupported'),
            'data' => ['field' => $name, 'number' => $number]];
    }

    private function savePonModal(int $userId, array $data): array
    {
        $rowId = $this->rowId($data);
        $portRef = trim((string)($data['olt_port_ref'] ?? ''));
        if ($rowId === null || $portRef === '') return $this->fail($userId, 'save_olt_pon_modal', 'pon_invalid');
        $result = $this->snmp->updatePonModal($userId, $rowId,
            max(1, (int)($data['olt_port_number'] ?? 0)), $portRef,
            (string)($data['olt_port_enable'] ?? ''), (string)($data['olt_port_previous_enable'] ?? ''),
            (string)($data['olt_port_description'] ?? ''), (string)($data['olt_port_previous_description'] ?? ''));
        if (empty($result['ok']) && !empty($result['partial'])) {
            $this->fail($userId, 'save_olt_pon_modal', 'partial', (string)($result['message'] ?? ''));
            return ['ok' => false, 'code' => 'partial'];
        }
        return $this->result($userId, 'save_olt_pon_modal', $result);
    }

    private function saveCliConnection(int $userId, array $data): array
    {
        $action = 'save_olt_cli_connection';
        $rowId = $this->rowId($data);
        if ($rowId === null) return $this->fail($userId, $action, 'olt_invalid');
        $olt = $this->olt($userId, $data, true);
        if (!is_array($olt)) return $this->fail($userId, $action, 'olt_not_found');
        $driverId = trim((string)device_driver_type($olt));
        $definition = monitoring_driver_definition($driverId);
        if (!is_array($definition) || empty($definition['telnet_cli'])) {
            return $this->fail($userId, $action, 'cli_unsupported');
        }
        $enabled = (string)($data['hybrid_status'] ?? '') === '1';
        if ($enabled && !olt_driver_support_enabled($this->db->getOltDriverSupportFlags(), $driverId)) {
            return $this->fail($userId, $action, 'driver_disabled');
        }
        $portRaw = trim((string)($data['ports'] ?? '23'));
        $portValid = ctype_digit($portRaw) && (int)$portRaw >= 1 && (int)$portRaw <= 65535;
        $port = $portValid ? (int)$portRaw : (int)($olt['ports'] ?? 23);
        $username = trim((string)($data['username'] ?? ''));
        $password = (string)($data['password'] ?? '');
        if ($enabled) {
            if (!$portValid) return $this->fail($userId, $action, 'port_invalid');
            if ($username === '') return $this->fail($userId, $action, 'username_required');
            if ($password === '' && trim((string)($olt['password_enc'] ?? '')) === '') {
                return $this->fail($userId, $action, 'password_required');
            }
        }
        $saved = $this->configWriter()->writeCliCredentials($userId, (int)($olt['olt_id'] ?? $rowId),
            $enabled ? 1 : 0, $port, $username !== '' ? $username : null,
            $enabled && $password !== '' ? $this->snmp->encryptCredential($password) : null);
        if (!$saved) return $this->fail($userId, $action, 'save_failed');
        if (!$enabled && !OltTerminalBrokerClient::closeDevice($olt)) {
            error_log('[LINNMS] Sesi CLI OLT gagal ditutup setelah Telnet dimatikan');
        }
        return ['ok' => true, 'code' => $enabled ? 'enabled' : 'disabled', 'data' => ['port' => $port]];
    }

    private function cliRead(int $userId, string $action, array $data): array
    {
        $rowId = $action === 'refresh_onu_cli_optical'
            ? valid_positive_id($data['onu_row_id'] ?? null) : $this->rowId($data);
        if ($rowId === null) return $this->fail($userId, $action,
            $action === 'refresh_onu_cli_optical' ? 'onu_invalid' : 'olt_invalid');
        $result = match ($action) {
            'test_olt_cli_connection' => $this->snmp->testCliOptical($userId, $rowId),
            'refresh_olt_cli_optical' => $this->snmp->refreshOltOptical($userId, $rowId),
            'refresh_onu_cli_optical' => $this->snmp->refreshOnuOptical($userId, $rowId),
        };
        if (empty($result['ok']) && $action === 'test_olt_cli_connection') {
            $trace = array_values(array_filter(array_map(static fn(mixed $line): string => trim((string)$line),
                (array)($result['trace'] ?? []))));
            if ($trace !== []) $result['message'] = trim((string)($result['message'] ?? '')
                . ' | trace=' . implode(' / ', array_slice($trace, -3)));
        }
        return $this->result($userId, $action, $result);
    }

    private function profile(int $userId, string $action, array $data): array
    {
        $rowId = $this->rowId($data);
        if ($rowId === null) return $this->fail($userId, $action, 'olt_invalid');
        $kind = trim((string)($data['kind'] ?? ''));
        $name = trim((string)($data['name'] ?? ''));
        $vlanId = (int)($data['vlan_id'] ?? 0);
        if ($action === 'delete_olt_profile') {
            return $this->result($userId, $action, $this->snmp->deleteOltProfileEntry($userId,
                $rowId, $kind, $name, $vlanId,
                isset($data['profile_scope']) ? (string)$data['profile_scope'] : null));
        }
        $profile = [];
        if ($kind === 'onu_type') {
            $profile = [
                'ethernet_enabled' => !empty($data['ethernet_enabled']) ? 1 : 0,
                'ethernet_port' => trim((string)($data['ethernet_port'] ?? '')),
                'wifi_enabled' => !empty($data['wifi_enabled']) ? 1 : 0,
                'wifi_ssid' => trim((string)($data['wifi_ssid'] ?? '')),
                'pots_enabled' => !empty($data['pots_enabled']) ? 1 : 0,
                'pots_port' => trim((string)($data['pots_port'] ?? '')),
            ];
            $fields = array_merge(['profile_scope', 'operation', 'pon_type', 'description', 'speed',
                'protect_type', 'eth_interfaces', 'wifi_interfaces', 'pots_interfaces'],
                array_keys(OltOnuTypeAware::GPON_FIELDS));
            foreach ($fields as $field) {
                if (isset($data[$field]) && is_string($data[$field])) $profile[$field] = trim($data[$field]);
            }
        }
        return $this->result($userId, $action,
            $this->snmp->saveOltProfileEntry($userId, $rowId, $kind, $name, $vlanId, $profile));
    }

    private function saveVlanPort(int $userId, array $data): array
    {
        $action = 'save_olt_vlan_port';
        $rowId = $this->rowId($data);
        if ($rowId === null) return $this->fail($userId, $action, 'olt_invalid');
        return $this->result($userId, $action, $this->snmp->updateVlanPortMembership($userId,
            $rowId, (int)($data['vlan_id'] ?? 0), trim((string)($data['port_ref'] ?? '')),
            strtolower(trim((string)($data['mode'] ?? ''))), (string)($data['enabled'] ?? '') === '1'));
    }

    private function saveVlanMembers(int $userId, array $data): array
    {
        $action = 'save_olt_vlan_members';
        $rowId = $this->rowId($data);
        $vlanId = (int)($data['vlan_id'] ?? 0);
        if ($rowId === null || $vlanId < 1 || $vlanId > 4095) return $this->fail($userId, $action, 'vlan_invalid');
        $selected = [];
        foreach (['access', 'trunk'] as $mode) {
            $selected[$mode] = array_values(array_unique(array_map(
                static fn(string $ref): string => trim($ref),
                array_filter(array_slice((array)($data[$mode . '_members'] ?? []), 0, 512),
                    static fn(mixed $ref): bool => is_string($ref) && trim($ref) !== '')
            )));
        }
        $target = $this->snmp->validateOltVlanMembershipTarget($userId, $rowId, $vlanId);
        if (empty($target['ok'])) return $this->fail($userId, $action, 'vlan_target_invalid', (string)($target['message'] ?? ''));
        $current = ['access' => [], 'trunk' => []];
        foreach ($this->db->getOltVlans($userId, $rowId) as $profile) {
            if (!is_array($profile)) continue;
            $profileData = is_array($profile['data'] ?? null) ? $profile['data'] : [];
            if ((int)($profileData['vlan_id'] ?? $profile['name'] ?? 0) !== $vlanId) continue;
            foreach (['access', 'trunk'] as $mode) {
                $current[$mode] = array_values(array_unique(array_filter(array_map(
                    static fn(mixed $ref): string => trim((string)$ref), (array)($profileData[$mode] ?? [])),
                    static fn(string $ref): bool => $ref !== '')));
            }
            break;
        }
        $changes = [];
        foreach (['access', 'trunk'] as $mode) {
            foreach ($current[$mode] as $ref) if (!in_array($ref, $selected[$mode], true)) $changes[] = [$ref, $mode, false];
            foreach ($selected[$mode] as $ref) if (!in_array($ref, $current[$mode], true)) $changes[] = [$ref, $mode, true];
        }
        $applied = 0;
        foreach ($changes as [$ref, $mode, $enabled]) {
            $result = $this->snmp->updateVlanPortMembership($userId, $rowId, $vlanId, $ref, $mode, $enabled);
            if (empty($result['ok'])) {
                $this->fail($userId, $action, 'device_failed', (string)($result['message'] ?? ''));
                return ['ok' => false, 'code' => $applied > 0 ? 'partial' : 'device_failed',
                    'data' => ['applied' => $applied]];
            }
            $applied++;
        }
        return ['ok' => true, 'code' => $changes === [] ? 'unchanged' : 'ok',
            'data' => ['applied' => $applied]];
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

    private function externalAdapter(ApiClient $client): DriverExternalAdapter
    {
        require_once project_path('vendor', 'driver', 'olt', 'zetset', 'Adapter.php');
        return new DriverExternalAdapter($client, $this->config);
    }
}
