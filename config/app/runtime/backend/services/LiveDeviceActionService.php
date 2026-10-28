<?php

declare(strict_types=1);

final class LiveDeviceActionService
{
    public function __construct(private LinnmsRepository $db, private SnmpOltService $snmp)
    {
    }

    public function execute(int $userId, string $type, array $data): array
    {
        $user = $this->db->getUser($userId);
        if (!is_array($user) || (int)($user['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'code' => 'forbidden', 'data' => []];
        }
        try {
            return match ($type) {
                'olt-uplink-save' => $this->uplink($userId, $data),
                'olt-pon-status-save', 'olt-pon-flow-control-save' => $this->pon($userId, $type, $data),
                'olt-profile-save', 'olt-profile-delete' => $this->profile($userId, $type, $data),
                'onu-interface-state-save' => $this->onuInterface($userId, $data),
                'onu-service-config-delete' => $this->onuServiceDelete($userId, $data),
                'onu-tr069-save' => $this->onuTr069($userId, $data),
                'onu-remote-access-save' => $this->onuRemoteAccess($userId, $data),
                'onu-veip-save' => $this->onuVeip($userId, $data),
                'onu-wan-ip-save' => $this->onuWanIp($userId, $data),
                'onu-data-service-save' => $this->onuDataService($userId, $data),
                'onu-ethernet-save' => $this->onuEthernet($userId, $data),
                'onu-wifi-ssid-save' => $this->onuWifiSsid($userId, $data),
                'onu-wifi-config-save' => $this->onuWifiConfig($userId, $data),
                'onu-unconfigured-refresh' => $this->unconfigured($userId, $data),
                'start-olt-discovery' => $this->startDiscovery($userId, $data),
                'onu-action' => $this->onuAction($userId, $data),
                'olt-view-refresh' => $this->refreshOlt($userId, $data),
                'olt-port-update' => ['ok' => false, 'code' => 'unsupported', 'data' => []],
                default => ['ok' => false, 'code' => 'unsupported', 'data' => []],
            };
        } catch (InvalidArgumentException $error) {
            return ['ok' => false, 'code' => 'validation', 'data' => []];
        } catch (Throwable $error) {
            $this->log($userId, 'LINNMS APP', $type . '_error', (string)$error);
            return ['ok' => false, 'code' => 'internal', 'data' => []];
        }
    }

    private function uplink(int $userId, array $data): array
    {
        $rowId = (int)($data['olt_row_id'] ?? 0);
        $ref = trim((string)($data['port_ref'] ?? ''));
        $config = json_decode((string)($data['config'] ?? '{}'), true);
        $vlan = json_decode((string)($data['vlan'] ?? '{}'), true);
        if ($rowId < 1 || $ref === '' || !is_array($config) || !is_array($vlan)) {
            throw new InvalidArgumentException('Konfigurasi uplink tidak valid.');
        }
        return $this->result($userId, 'olt-uplink-save',
            $this->snmp->updateUplinkConfiguration($userId, $rowId, $ref, $config, $vlan));
    }

    private function pon(int $userId, string $type, array $data): array
    {
        $rowId = (int)($data['olt_row_id'] ?? 0);
        $number = (int)($data['olt_port_number'] ?? 0);
        $ref = trim((string)($data['olt_port_ref'] ?? ''));
        $value = trim((string)($data[$type === 'olt-pon-status-save' ? 'olt_port_enable' : 'value'] ?? ''));
        if ($rowId < 1 || ($type === 'olt-pon-flow-control-save' && $number < 1)
            || !in_array($value, ['Enable', 'Disable'], true)) {
            throw new InvalidArgumentException('Status PON tidak valid.');
        }
        if ($type === 'olt-pon-status-save') $number = max(1, $number);
        $field = $type === 'olt-pon-status-save' ? 'olt_port_enable' : 'olt_port_flow_control';
        return $this->result($userId, $type,
            $this->snmp->updatePonPortConfig($userId, $rowId, $number, $field, $value, $ref),
            ['state' => $value]);
    }

    private function profile(int $userId, string $type, array $data): array
    {
        $rowId = (int)($data['olt_row_id'] ?? 0);
        $kind = trim((string)($data['kind'] ?? ''));
        if ($rowId < 1 || $kind === '') throw new InvalidArgumentException('Data profil tidak valid.');
        if ($type === 'olt-profile-save') {
            $profile = json_decode((string)($data['data'] ?? '{}'), true);
            if (!is_array($profile)) throw new InvalidArgumentException('Data profil tidak valid.');
            $result = $this->snmp->saveOltProfile($userId, $rowId, $kind, $profile);
        } else {
            $name = trim((string)($data['name'] ?? ''));
            if ($name === '') throw new InvalidArgumentException('Nama profil kosong.');
            $result = $this->snmp->deleteOltProfile($userId, $rowId, $kind, $name);
        }
        return $this->result($userId, $type, $result);
    }

    private function onuInterface(int $userId, array $data): array
    {
        $rowId = (int)($data['onu_row_id'] ?? 0);
        $kind = trim((string)($data['kind'] ?? ''));
        $state = trim((string)($data['state'] ?? ''));
        if ($rowId < 1 || !in_array($kind, ['wifi', 'veip'], true)
            || !in_array($state, ['lock', 'unlock'], true)) {
            throw new InvalidArgumentException('Status antarmuka tidak valid.');
        }
        return $this->result($userId, 'onu-interface-state-save',
            $this->snmp->updateOnuInterfaceState($userId, $rowId, $kind,
                trim((string)($data['port'] ?? '')), $state));
    }

    private function onuServiceDelete(int $userId, array $data): array
    {
        $rowId = (int)($data['onu_row_id'] ?? 0);
        $kind = trim((string)($data['kind'] ?? ''));
        if ($rowId < 1 || !in_array($kind, ['wifi', 'veip', 'tr069'], true)) {
            throw new InvalidArgumentException('Layanan ONU tidak valid.');
        }
        return $this->result($userId, 'onu-service-config-delete',
            $this->snmp->clearOnuServiceConfig($userId, $rowId, $kind,
                strtolower(trim((string)($data['port'] ?? '')))));
    }

    private function onuTr069(int $userId, array $data): array
    {
        $rowId = (int)($data['onu_row_id'] ?? 0);
        $vlan = trim((string)($data['vlan'] ?? ''));
        $priority = trim((string)($data['priority'] ?? '0'));
        if ($rowId < 1 || ($vlan !== '' && !ctype_digit($vlan)) || !ctype_digit($priority)) {
            throw new InvalidArgumentException('Pengaturan TR069 tidak valid.');
        }
        $input = [
            'status_only' => (string)($data['config_action'] ?? '') === 'state',
            'state' => trim((string)($data['state'] ?? '')),
            'acs' => trim((string)($data['acs'] ?? '')),
            'auth_action' => trim((string)($data['auth_action'] ?? 'keep')),
            'username' => trim((string)($data['username'] ?? '')),
            'password' => (string)($data['password'] ?? ''),
            'vlan' => $vlan === '' ? 0 : (int)$vlan,
            'priority' => (int)$priority,
        ];
        return $this->result($userId, 'onu-tr069-save', $this->snmp->updateOnuTr069($userId, $rowId, $input));
    }

    private function onuRemoteAccess(int $userId, array $data): array
    {
        $rowId = (int)($data['onu_row_id'] ?? 0);
        if ($rowId < 1) throw new InvalidArgumentException('ONU tidak valid.');
        $input = [
            'action' => trim((string)($data['config_action'] ?? '')),
            'index' => (int)($data['index'] ?? 0),
            'state' => trim((string)($data['state'] ?? '')),
            'ingress_type' => trim((string)($data['ingress_type'] ?? '')),
            'mode' => trim((string)($data['mode'] ?? '')),
            'protocols' => trim((string)($data['protocols'] ?? '')),
            'start_ip' => trim((string)($data['start_ip'] ?? '')),
            'end_ip' => trim((string)($data['end_ip'] ?? '')),
        ];
        return $this->result($userId, 'onu-remote-access-save',
            $this->snmp->updateOnuRemoteAccess($userId, $rowId, $input));
    }

    private function onuVeip(int $userId, array $data): array
    {
        $rowId = (int)($data['onu_row_id'] ?? 0);
        $defaultVlan = trim((string)($data['default_vlan'] ?? ''));
        $vlans = preg_replace('/\s+/', '', trim((string)($data['vlans'] ?? '')));
        if ($rowId < 1 || ($defaultVlan !== '' && !ctype_digit($defaultVlan))
            || ($vlans !== '' && preg_match('/^\d{1,4}(?:,\d{1,4})*$/D', $vlans) !== 1)) {
            throw new InvalidArgumentException('Pengaturan VEIP tidak valid.');
        }
        $input = [
            'state' => trim((string)($data['state'] ?? '')),
            'mode' => trim((string)($data['mode'] ?? '')),
            'default_vlan' => $defaultVlan === '' ? 0 : (int)$defaultVlan,
            'vlans' => $vlans,
        ];
        return $this->result($userId, 'onu-veip-save', $this->snmp->updateOnuVeip($userId, $rowId, $input));
    }

    private function onuWanIp(int $userId, array $data): array
    {
        $rowId = (int)($data['onu_row_id'] ?? 0);
        $index = trim((string)($data['index'] ?? ''));
        if ($rowId < 1 || !ctype_digit($index)) throw new InvalidArgumentException('Service WAN tidak valid.');
        $input = [
            'index' => (int)$index,
            'mode' => trim((string)($data['mode'] ?? '')),
            'vlan_profile' => trim((string)($data['vlan_profile'] ?? '')),
            'ip_profile' => trim((string)($data['ip_profile'] ?? '')),
            'ip_address' => trim((string)($data['ip_address'] ?? '')),
            'mask' => trim((string)($data['mask'] ?? '')),
            'username' => trim((string)($data['username'] ?? '')),
            'password' => (string)($data['password'] ?? ''),
        ];
        return $this->result($userId, 'onu-wan-ip-save', $this->snmp->updateOnuWanIp($userId, $rowId, $input));
    }

    private function onuDataService(int $userId, array $data): array
    {
        $rowId = (int)($data['onu_row_id'] ?? 0);
        $index = trim((string)($data['index'] ?? ''));
        if ($rowId < 1 || !ctype_digit($index)) throw new InvalidArgumentException('Service tidak valid.');
        $input = [
            'index' => (int)$index,
            'action' => trim((string)($data['config_action'] ?? '')),
            'name' => trim((string)($data['name'] ?? '')),
            'vlan' => (int)($data['vlan'] ?? 0),
            'vlan_mode' => trim((string)($data['vlan_mode'] ?? 'tag')),
            'svlan' => (int)($data['svlan'] ?? 0),
            'tcont_profile' => trim((string)($data['tcont_profile'] ?? '')),
            'traffic_profile' => trim((string)($data['traffic_profile'] ?? '')),
        ];
        return $this->result($userId, 'onu-data-service-save',
            $this->snmp->changeOnuDataService($userId, $rowId, $input));
    }

    private function onuEthernet(int $userId, array $data): array
    {
        $rowId = (int)($data['onu_row_id'] ?? 0);
        $port = (int)($data['port'] ?? 0);
        if ($rowId < 1 || $port < 1) throw new InvalidArgumentException('Port LAN tidak valid.');
        return $this->result($userId, 'onu-ethernet-save', $this->snmp->updateOnuLanField(
            $userId, $rowId, $port, trim((string)($data['field'] ?? '')), trim((string)($data['value'] ?? ''))));
    }

    private function onuWifiSsid(int $userId, array $data): array
    {
        $rowId = (int)($data['onu_row_id'] ?? 0);
        $port = (int)($data['port'] ?? 0);
        if ($rowId < 1 || $port < 1) throw new InvalidArgumentException('Port WiFi tidak valid.');
        return $this->result($userId, 'onu-wifi-ssid-save',
            $this->snmp->updateOnuWifiSsid($userId, $rowId, $port, trim((string)($data['ssid'] ?? ''))));
    }

    private function onuWifiConfig(int $userId, array $data): array
    {
        $rowId = (int)($data['onu_row_id'] ?? 0);
        $port = (int)($data['port'] ?? 0);
        $defaultVlan = trim((string)($data['default_vlan'] ?? ''));
        $vlans = preg_replace('/\s+/', '', trim((string)($data['vlans'] ?? '')));
        if ($rowId < 1 || $port < 1 || ($defaultVlan !== '' && !ctype_digit($defaultVlan))
            || ($vlans !== '' && preg_match('/^\d{1,4}(?:,\d{1,4})*$/D', $vlans) !== 1)) {
            throw new InvalidArgumentException('Pengaturan WiFi tidak valid.');
        }
        $input = [
            'state' => trim((string)($data['state'] ?? '')),
            'ssid' => trim((string)($data['ssid'] ?? '')),
            'mode' => trim((string)($data['mode'] ?? '')),
            'default_vlan' => $defaultVlan === '' ? 0 : (int)$defaultVlan,
            'vlans' => $vlans,
            'security' => trim((string)($data['security'] ?? 'keep')),
            'auth' => trim((string)($data['auth'] ?? '')),
            'encryption' => trim((string)($data['encryption'] ?? '')),
            'key' => (string)($data['key'] ?? ''),
        ];
        return $this->result($userId, 'onu-wifi-config-save',
            $this->snmp->updateOnuWifiConfig($userId, $rowId, $port, $input));
    }

    private function unconfigured(int $userId, array $data): array
    {
        $rowId = (int)($data['olt_row_id'] ?? 0);
        if ($rowId < 1) throw new InvalidArgumentException('OLT tidak valid.');
        return $this->result($userId, 'onu-unconfigured-refresh',
            (new OltSyncOrchestrator($this->db, $this->snmp))->refreshUnconfiguredOnus($userId, $rowId));
    }

    private function startDiscovery(int $userId, array $data): array
    {
        $rowId = (int)($data['olt_row_id'] ?? 0);
        $olt = $rowId > 0 ? $this->db->getOltByRowId($userId, $rowId) : null;
        if (!is_array($olt) || !monitoring_row_uses_handler($olt, 'snmp_olt')) {
            return ['ok' => false, 'code' => 'not_found', 'data' => []];
        }
        $queued = $this->db->requestOltScan($userId, $rowId);
        return ['ok' => true, 'code' => 'ok', 'data' => [
            'active' => true,
            'already_running' => !$queued,
        ]];
    }

    private function onuAction(int $userId, array $data): array
    {
        $rowId = (int)($data['onu_row_id'] ?? 0);
        $action = trim((string)($data['onu_action'] ?? ''));
        if ($rowId < 1 || $action === '') throw new InvalidArgumentException('Aksi ONU tidak valid.');
        $onu = $this->db->getOnuByRowId($userId, $rowId);
        if (!is_array($onu)) return ['ok' => false, 'code' => 'not_found', 'data' => []];
        if (!monitoring_row_uses_handler($onu, 'snmp_olt')) {
            return ['ok' => false, 'code' => 'unsupported', 'data' => []];
        }
        $payload = json_decode((string)($data['payload'] ?? '{}'), true);
        $payload = is_array($payload) ? $payload : [];
        $result = match ($action) {
            'rebootOp', 'activeOp', 'noactiveOp', 'restoreOp' => $this->snmp->operateOnu($userId, $rowId, $action),
            'auth' => $this->snmp->updateOnuAuthMode($userId, $rowId, trim((string)($payload['mode'] ?? ''))),
            'rename' => $this->snmp->renameOnu($userId, $rowId, trim((string)($payload['name'] ?? ''))),
            default => throw new InvalidArgumentException('Aksi ONU tidak dikenal.'),
        };
        return $this->result($userId, 'onu-action', $result, ['action' => $action, 'onu_row_id' => $rowId]);
    }

    private function refreshOlt(int $userId, array $data): array
    {
        $rowId = (int)($data['olt_row_id'] ?? 0);
        if ($rowId < 1) throw new InvalidArgumentException('OLT tidak valid.');
        $result = (new OltSyncOrchestrator($this->db, $this->snmp))->refreshView($userId, $rowId);
        $ok = !empty($result['ok']);
        $busy = !empty($result['busy']);
        $detail = trim((string)($result['message'] ?? 'Pembaruan OLT selesai.'));
        $warnings = array_values(array_filter(array_map('strval', (array)($result['warnings'] ?? [])),
            static fn(string $warning): bool => trim($warning) !== ''));
        $this->log($userId, $ok ? 'OLT' : 'Driver', 'refresh_olt_view',
            $detail . ($warnings !== [] ? ' ' . implode(' ', $warnings) : ''),
            $ok ? (!empty($result['partial']) ? 'warning' : 'success') : ($busy ? 'warning' : 'error'));
        return ['ok' => $ok, 'code' => $busy ? 'conflict' : ($ok ? 'ok' : 'device_rejected'), 'data' => [
            'busy' => $busy,
            'partial' => !empty($result['partial']),
            'pon_ports' => (int)($result['pon_ports'] ?? 0),
            'polled_at' => time(),
        ]];
    }

    private function result(int $userId, string $action, array $result, array $extra = []): array
    {
        $ok = !empty($result['ok']);
        if (!$ok) $this->log($userId, 'Driver', $action . '_driver',
            trim((string)($result['message'] ?? raw_result_error($result, $action))));
        unset($result['ok'], $result['message'], $result['error'], $result['trace'],
            $result['raw'], $result['warnings'], $result['diagnostic']);
        return ['ok' => $ok, 'code' => $ok ? 'ok' : 'device_rejected', 'data' => $extra + $result];
    }

    private function log(int $userId, string $area, string $action, string $detail, string $level = 'error'): void
    {
        try {
            $this->db->logApplication($userId, $area, $action, $level, $detail);
        } catch (Throwable $error) {
            error_log('[LINNMS] Live action log: ' . $error->getMessage());
        }
    }
}
