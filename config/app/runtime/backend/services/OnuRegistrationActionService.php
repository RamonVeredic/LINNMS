<?php

declare(strict_types=1);

final class OnuRegistrationActionService
{
    private ?OnuSnapshotWriteService $onuWriter = null;

    public function __construct(private LinnmsRepository $db, private SnmpOltService $snmp)
    {
    }

    public function execute(int $userId, string $action, array $data): array
    {
        $user = $this->db->getUser($userId);
        if (!is_array($user) || (int)($user['is_active'] ?? 0) !== 1) return ['ok' => false, 'code' => 'forbidden'];
        try {
            return match ($action) {
                'register_onu', 'pre_register_onu' => $this->register($userId, $action, $data),
                'unregister_onu' => $this->unregister($userId, $data),
                'replace_onu_registration' => $this->replace($userId, $data),
                default => ['ok' => false, 'code' => 'unsupported'],
            };
        } catch (Throwable $error) {
            return $this->fail($userId, $action, 'internal', (string)$error);
        }
    }

    private function authWrite(int $userId, array $onu, string $mode): array
    {
        if (!monitoring_row_uses_handler($onu, 'snmp_olt')) return ['ok' => false, 'code' => 'unsupported', 'data' => []];
        return $this->snmp->updateOnuAuthMode($userId, (int)$onu['id'], $mode);
    }

    private function register(int $userId, string $action, array $data): array
    {
        $manual = $action === 'pre_register_onu';
        $rowId = valid_positive_id($data['onu_row_id'] ?? null);
        $name = trim((string)($data['onu_name'] ?? ''));
        $location = onu_location_value($data['onu_location'] ?? null);
        $onuType = trim((string)($data['onu_type'] ?? ''));
        $blank = static fn(string $value): bool => in_array($value, ['', '-', '_', '—'], true);
        if ($blank($name)) $name = '';
        if ($blank($location)) $location = '';
        if ($blank($onuType)) $onuType = '';
        if (!$manual && $rowId === null) return $this->fail($userId, $action, 'onu_invalid');
        if ($name === '') return $this->fail($userId, $action, 'name_required');

        $onu = $manual ? null : $this->db->getOnuByRowId($userId, $rowId);
        $ponPath = '';
        if ($manual) {
            $oltDeviceId = valid_positive_id($data['olt_device_id'] ?? null);
            $olt = $oltDeviceId === null ? null : $this->db->getOltByRowId($userId, $oltDeviceId);
            $ponPath = trim((string)($data['pon_path'] ?? ''));
            $serial = strtoupper(trim((string)($data['onu_serial'] ?? '')));
            $oltDefinition = is_array($olt)
                ? monitoring_driver_definition((string)device_driver_type($olt)) : null;
            if (!is_array($olt) || (string)($olt['source'] ?? '') !== 'snmp'
                || empty($oltDefinition['manual_onu_registration'])
                || preg_match('~^\d+/\d+/\d+$~D', $ponPath) !== 1
                || preg_match('/^[A-Z0-9]{12,16}$/D', $serial) !== 1) {
                return $this->fail($userId, $action, 'manual_identity_invalid');
            }
            $knownPon = false;
            foreach ($this->db->getOltPons($userId, $oltDeviceId) as $pon) {
                if (preg_match('~(?:gpon-olt_)?(\d+/\d+/\d+)$~i', (string)($pon['port_ref'] ?? ''), $match) === 1
                    && $match[1] === $ponPath) {
                    $knownPon = true;
                    break;
                }
            }
            if (!$knownPon) return $this->fail($userId, $action, 'pon_not_found');
            $onu = ['olt_device_id' => $oltDeviceId, 'olt_row_id' => $oltDeviceId,
                'driver_id' => (string)device_driver_type($olt), 'onu_serial' => $serial,
                'onu_index' => $ponPath . ':0'];
        }
        if (!is_array($onu)) return $this->fail($userId, $action, 'onu_not_found');
        if (!$manual && in_array(trim((string)($onu['auth_mode'] ?? '')), ['Pass', 'Deny'], true)) {
            return $this->fail($userId, $action, 'already_registered', '', $rowId);
        }
        if (!$manual && trim((string)($onu['onu_serial'] ?? '')) === '') {
            $identity = $this->snmp->prepareOnuRegistration($userId, $rowId);
            if (empty($identity['ok']) || !is_array($identity['onu'] ?? null)) {
                return $this->fail($userId, $action, 'serial_unavailable', (string)($identity['message'] ?? ''));
            }
            $onu = $identity['onu'];
        }
        if (trim((string)($onu['onu_serial'] ?? '')) === '') return $this->fail($userId, $action, 'serial_unavailable');
        $onuOltId = (int)($onu['olt_device_id'] ?? 0);
        $postedOltId = (int)($data['olt_device_id'] ?? 0);
        if ($onuOltId < 1) return $this->fail($userId, $action, 'olt_missing');
        if ($postedOltId > 0 && $postedOltId !== $onuOltId) return $this->fail($userId, $action, 'olt_identity_mismatch');
        $postedDriver = trim((string)($data['driver_id'] ?? ''));
        $onuDriver = trim((string)device_driver_type($onu));
        if ($postedDriver !== '' && $onuDriver !== '' && strcasecmp($postedDriver, $onuDriver) !== 0) {
            return $this->fail($userId, $action, 'driver_identity_mismatch');
        }
        $capabilities = onu_registration_capabilities_for_driver($onuDriver);
        if (empty($capabilities['onu_type'])) $onuType = '';

        $serviceVlan = !empty($capabilities['vlan']) ? (int)($data['service1_vlan'] ?? 0) : 0;
        $serviceTcont = !empty($capabilities['wan']) ? trim((string)($data['service1_upload'] ?? '')) : '';
        $serviceTraffic = !empty($capabilities['wan']) ? trim((string)($data['service1_download'] ?? '')) : '';
        $services = [];
        if (!empty($capabilities['wan'])) {
            $baseIndex = (int)($data['service_index'] ?? 2);
            $untagCount = 0;
            foreach (range(1, 4) as $number) {
                if (empty($data['service' . $number . '_enabled'])) continue;
                $mode = (string)($data['service' . $number . '_vlan_mode'] ?? '1');
                $type = (string)($data['service' . $number . '_service_type'] ?? 'internet');
                $vlan = (int)($data['service' . $number . ($type === 'iptv' ? '_mvlan' : ($mode === '3' ? '_customer_vlan' : '_vlan'))] ?? 0);
                $tcont = trim((string)($data['service' . $number . '_upload'] ?? ''));
                $traffic = trim((string)($data['service' . $number . '_download'] ?? ''));
                if ($vlan < 1 || $vlan > 4094 || $tcont === '' || $traffic === '') {
                    return $this->fail($userId, $action, 'service_required', '', $rowId, ['number' => $number]);
                }
                if (!in_array($mode, ['1', '2', '3'], true)
                    || !in_array($type, ['internet', 'tr069', 'iptv', 'bridge'], true)
                    || ($type === 'iptv' && $mode !== '1')) {
                    return $this->fail($userId, $action, 'service_mode_invalid', '', $rowId, ['number' => $number]);
                }
                $lanPorts = [];
                if ($type === 'iptv') {
                    $postedPorts = preg_replace('/\s+/', '', trim((string)($data['service' . $number . '_lan_ports'] ?? '')));
                    if (preg_match('/^\d{1,2}(?:,\d{1,2})*$/D', $postedPorts) !== 1) {
                        return $this->fail($userId, $action, 'lan_ports_invalid', '', $rowId, ['number' => $number]);
                    }
                    $lanPorts = array_values(array_unique(array_map('intval', explode(',', $postedPorts))));
                    if (array_filter($lanPorts, static fn(int $port): bool => $port < 1 || $port > 32) !== []) {
                        return $this->fail($userId, $action, 'lan_port_number_invalid', '', $rowId, ['number' => $number]);
                    }
                }
                $svlan = (int)($data['service' . $number . '_svlan'] ?? 0);
                if ($mode === '3' && ($svlan < 1 || $svlan > 4094)) {
                    return $this->fail($userId, $action, 'outer_vlan_required', '', $rowId, ['number' => $number]);
                }
                if ($mode === '2' && ++$untagCount > 1) return $this->fail($userId, $action, 'multiple_untag');
                $services[] = [
                    'number' => $number,
                    'index' => $baseIndex + $number - 1,
                    'vlan' => $vlan,
                    'vlan_mode' => $mode,
                    'svlan' => $mode === '3' ? $svlan : 0,
                    'tcont_profile' => $tcont,
                    'traffic_profile' => $traffic,
                    'type' => $type,
                    'lan_ports' => $lanPorts,
                ];
            }
            if ($services !== [] && ($baseIndex < 1 || max(array_column($services, 'index')) > 32)) {
                return $this->fail($userId, $action, 'service_index_invalid');
            }
            $vlans = array_column($services, 'vlan');
            if (count($vlans) !== count(array_unique($vlans))) return $this->fail($userId, $action, 'duplicate_vlan');
            $serviceVlan = $services !== [] ? (int)$services[0]['vlan'] : 0;
            $serviceTcont = $services !== [] ? (string)$services[0]['tcont_profile'] : '';
            $serviceTraffic = $services !== [] ? (string)$services[0]['traffic_profile'] : '';
        }
        $wanMethod = !empty($capabilities['wan']) ? trim((string)($data['wan_method'] ?? 'ont')) : 'ont';
        if (!in_array($wanMethod, ['ont', 'omci-wan', 'omci-pppoe', 'omci-static'], true)) {
            return $this->fail($userId, $action, 'wan_method_invalid');
        }
        $wanProfile = $wanMethod !== 'ont' ? trim((string)($data['wan_vlan_profile'] ?? '')) : '';
        $wanUsername = $wanMethod === 'omci-pppoe' ? trim((string)($data['wan_username'] ?? '')) : '';
        $wanPassword = $wanMethod === 'omci-pppoe' ? (string)($data['wan_password'] ?? '') : '';
        $wanIpProfile = $wanMethod === 'omci-static' ? trim((string)($data['wan_ip_profile'] ?? '')) : '';
        $wanIpAddress = $wanMethod === 'omci-static' ? trim((string)($data['wan_ip_address'] ?? '')) : '';
        $wanMask = $wanMethod === 'omci-static' ? trim((string)($data['wan_mask'] ?? '')) : '';
        if ($wanMethod !== 'ont' && ($services === [] || $wanProfile === '')) {
            return $this->fail($userId, $action, 'wan_service_required');
        }
        if ($wanMethod !== 'ont' && (int)($services[0]['number'] ?? 0) !== 1) {
            return $this->fail($userId, $action, 'wan_service_one_required');
        }
        if ($wanMethod === 'omci-pppoe' && ($wanUsername === '' || $wanPassword === '')) {
            return $this->fail($userId, $action, 'pppoe_credentials_required');
        }
        if ($wanMethod === 'omci-static' && ($wanIpProfile === ''
            || filter_var($wanIpAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || filter_var($wanMask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)) {
            return $this->fail($userId, $action, 'static_ip_invalid');
        }
        foreach ($services as &$service) {
            $number = (int)$service['number'];
            $method = $number === 1 ? $wanMethod : trim((string)($data['service' . $number . '_wan_method'] ?? 'ont'));
            $profile = $number === 1 ? $wanProfile : trim((string)($data['service' . $number . '_wan_vlan_profile'] ?? ''));
            $username = $number === 1 ? $wanUsername : trim((string)($data['service' . $number . '_wan_username'] ?? ''));
            $password = $number === 1 ? $wanPassword : (string)($data['service' . $number . '_wan_password'] ?? '');
            $ipProfile = $number === 1 ? $wanIpProfile : trim((string)($data['service' . $number . '_wan_ip_profile'] ?? ''));
            $ipAddress = $number === 1 ? $wanIpAddress : trim((string)($data['service' . $number . '_wan_ip_address'] ?? ''));
            $mask = $number === 1 ? $wanMask : trim((string)($data['service' . $number . '_wan_mask'] ?? ''));
            if (!in_array($method, ['ont', 'omci-wan', 'omci-pppoe', 'omci-static'], true)
                || ($method !== 'ont' && $profile === '')
                || ($method === 'omci-pppoe' && ($username === '' || $password === ''))
                || ($method === 'omci-static' && ($ipProfile === ''
                    || filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                    || filter_var($mask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false))) {
                return $this->fail($userId, $action, 'service_wan_invalid', '', $rowId, ['number' => $number]);
            }
            $service['wan_method'] = $method;
            $service['wan_vlan_profile'] = $profile;
            $service['wan_username'] = $username;
            $service['wan_password'] = $password;
            $service['wan_ip_profile'] = $ipProfile;
            $service['wan_ip_address'] = $ipAddress;
            $service['wan_mask'] = $mask;
        }
        unset($service);
        $tr069Service = null;
        foreach ($services as $service) if ($service['type'] === 'tr069') $tr069Service = $service;
        $useTr069 = !empty($capabilities['tr069']) && (!empty($data['use_tr069']) || $tr069Service !== null);
        $tr069Acs = $useTr069 ? trim((string)($data['tr069_acs'] ?? '')) : '';
        $tr069Username = $useTr069 ? trim((string)($data['tr069_username'] ?? '')) : '';
        $tr069Password = $useTr069 ? (string)($data['tr069_password'] ?? '') : '';
        if ($useTr069 && $services === []) return $this->fail($userId, $action, 'tr069_service_required');
        if ($useTr069 && $tr069Acs === '' && ($tr069Username !== '' || $tr069Password !== '')) {
            return $this->fail($userId, $action, 'acs_required');
        }
        if ($useTr069 && (($tr069Username === '') !== ($tr069Password === ''))) {
            return $this->fail($userId, $action, 'acs_credentials_pair');
        }
        $serviceMeta = [
            'wan_method' => $wanMethod,
            'use_veip' => !empty($capabilities['veip']) && !empty($data['use_veip']),
            'use_tr069' => $useTr069,
            'vlan_mode' => !empty($capabilities['wan']) ? trim((string)($data['service1_vlan_mode'] ?? '')) : '',
            'service_type' => !empty($capabilities['wan']) ? trim((string)($data['service1_service_type'] ?? '')) : '',
            'wan_mode' => !empty($capabilities['wan']) ? trim((string)($data['service1_wan_mode'] ?? '')) : '',
            'onu_id_mode' => !empty($capabilities['onu_id']) ? trim((string)($data['onu_id_mode'] ?? 'auto')) : 'auto',
            'onu_id_custom' => !empty($capabilities['onu_id']) ? (int)($data['onu_id_custom'] ?? 0) : 0,
        ];
        $didFullBatch = false;
        $needsFullBatch = $serviceVlan > 0 || $services !== [] || $wanMethod !== 'ont' || $useTr069
            || array_filter($services, static fn(array $item): bool => (string)($item['wan_method'] ?? 'ont') !== 'ont') !== []
            || $serviceMeta['onu_id_mode'] === 'custom'
            || (!empty($capabilities['veip']) && empty($data['use_veip']))
            || array_filter($services, static fn(array $item): bool => $item['vlan_mode'] !== '1') !== [];
        if (($needsFullBatch || $manual)
            && (empty($capabilities['batch_provision']) || (!$manual && !$this->snmp->onuSupportsFullRegistration($userId, $rowId)))) {
            return $this->fail($userId, $action, 'batch_unsupported');
        }
        if ($serviceVlan > 0 && $services === []) return $this->fail($userId, $action, 'service_activate_required');
        if ($onuType !== '' && $onuType !== trim((string)($onu['onu_type'] ?? '')) && $services === []) {
            return $this->fail($userId, $action, 'type_unsupported');
        }
        if ($serviceMeta['onu_id_mode'] === 'custom' && $services === []) return $this->fail($userId, $action, 'onu_id_unsupported');
        if ($manual && $services === []) return $this->fail($userId, $action, 'manual_service_required');
        if (!$manual && !$needsFullBatch && !$this->snmp->onuSupportsRename($userId, $rowId)) {
            return $this->fail($userId, $action, 'rename_unsupported');
        }
        $full = [];
        if (!empty($capabilities['batch_provision']) && $services !== []
            && ($manual || $this->snmp->onuSupportsFullRegistration($userId, $rowId))) {
            $options = [
                'type' => $onuType,
                'serial' => (string)($onu['onu_serial'] ?? ''),
                'name' => $name,
                'location' => $location,
                'vlan' => $serviceVlan,
                'service_name' => trim((string)($data['service_name'] ?? '')),
                'tcont_profile' => $serviceTcont,
                'traffic_profile' => $serviceTraffic,
                'index' => (int)$services[0]['index'],
                'services' => $services,
                'onu_id_mode' => $serviceMeta['onu_id_mode'],
                'onu_id_custom' => $serviceMeta['onu_id_custom'],
                'veip' => !empty($capabilities['veip']) && !empty($data['use_veip']),
                'wan_method' => $wanMethod,
                'wan_vlan_profile' => $wanProfile,
                'wan_username' => $wanUsername,
                'wan_password' => $wanPassword,
                'wan_ip_profile' => $wanIpProfile,
                'wan_ip_address' => $wanIpAddress,
                'wan_mask' => $wanMask,
                'tr069' => $useTr069 ? [
                    'acs' => $tr069Acs, 'username' => $tr069Username,
                    'password' => $tr069Password, 'vlan' => (int)($tr069Service['vlan'] ?? $services[0]['vlan']),
                ] : null,
            ];
            $full = $manual
                ? $this->snmp->preRegisterOnuFull($userId, $onuOltId, $ponPath, $options)
                : $this->snmp->registerOnuFull($userId, $rowId, $options);
            if (empty($full['ok'])) return $this->fail($userId, $action, 'registration_failed', (string)($full['message'] ?? ''));
            if (empty($full['verified'])) return $this->fail($userId, $action, 'registration_unverified');
            $didFullBatch = true;
            if ($manual) $rowId = (int)($full['onu_row_id'] ?? 0);
        }
        if (!$didFullBatch) {
            $authResult = $this->authWrite($userId, $onu, 'Pass');
            if (empty($authResult['ok'])) return $this->fail($userId, $action, 'registration_failed', (string)($authResult['message'] ?? ''));
            $rename = $this->snmp->renameOnu($userId, $rowId, $name, $location);
            if (empty($rename['ok'])) {
                $this->authWrite($userId, $onu, 'Undef');
                return $this->fail($userId, $action, 'rename_failed', (string)($rename['message'] ?? ''));
            }
        }
        try {
            $profile = $this->onuWriter()->writeRegistrationProfile($userId, $rowId, $name, $location, $onuType);
            if (empty($profile['ok'])) throw new RuntimeException((string)($profile['message'] ?? 'Profil ONU gagal disimpan.'));
        } catch (Throwable $error) {
            if (!$manual && !$didFullBatch) $this->authWrite($userId, $onu, 'Undef');
            return $this->fail($userId, $action, $manual || $didFullBatch ? 'profile_after_device' : 'profile_rolled_back',
                (string)$error, $rowId);
        }
        $refreshWarning = false;
        try {
            $refresh = $this->snmp->refreshRegisteredOnu($userId, $rowId,
                $didFullBatch ? (array)($full['mgmt_lines'] ?? []) : [],
                $didFullBatch ? (array)($full['observed_config'] ?? []) : []);
            if (empty($refresh['ok'])) {
                $refreshWarning = true;
                $this->log($userId, 'register_onu_refresh', (string)($refresh['message'] ?? 'Data ONU tidak lengkap.'), 'Driver');
            }
        } catch (Throwable $error) {
            $refreshWarning = true;
            $this->log($userId, 'register_onu_refresh', (string)$error, 'Driver');
        }
        return ['ok' => true, 'code' => $refreshWarning ? 'partial' : 'ok', 'data' => ['row_id' => $rowId]];
    }

    private function unregister(int $userId, array $data): array
    {
        $rowId = valid_positive_id($data['onu_row_id'] ?? null);
        if ($rowId === null) return $this->fail($userId, 'unregister_onu', 'onu_invalid');
        $onu = $this->db->getOnuByRowId($userId, $rowId);
        if (!is_array($onu)) return $this->fail($userId, 'unregister_onu', 'onu_not_found');
        if (strcasecmp(trim((string)($onu['auth_mode'] ?? '')), 'Undef') === 0) {
            return ['ok' => true, 'code' => 'already_unregistered'];
        }
        $result = $this->authWrite($userId, $onu, 'Undef');
        if (empty($result['ok'])) return $this->fail($userId, 'unregister_onu', 'unregister_failed',
            (string)($result['message'] ?? 'Registrasi ONU gagal dihapus.'), $rowId);
        $this->onuWriter()->clearRegistrationProfile($userId, $rowId);
        return ['ok' => true, 'code' => 'ok'];
    }

    private function replace(int $userId, array $data): array
    {
        $currentId = valid_positive_id($data['onu_row_id'] ?? null);
        $replacementId = valid_positive_id($data['replacement_onu_row_id'] ?? null);
        if ($currentId === null || $replacementId === null || $currentId === $replacementId) {
            return $this->fail($userId, 'replace_onu_registration', 'replacement_invalid', '', $currentId ?? 0);
        }
        $current = $this->db->getOnuByRowId($userId, $currentId);
        $replacement = $this->db->getOnuByRowId($userId, $replacementId);
        if (!is_array($current) || !is_array($replacement)) {
            return $this->fail($userId, 'replace_onu_registration', 'onu_not_found', '', $currentId);
        }
        if ((int)($current['olt_device_id'] ?? 0) !== (int)($replacement['olt_device_id'] ?? 0)) {
            return $this->fail($userId, 'replace_onu_registration', 'olt_mismatch', '', $currentId);
        }
        if (strcasecmp(trim((string)($replacement['auth_mode'] ?? '')), 'Undef') !== 0) {
            return $this->fail($userId, 'replace_onu_registration', 'replacement_registered', '', $currentId);
        }
        if (trim((string)($replacement['onu_serial'] ?? '')) === '') {
            return $this->fail($userId, 'replace_onu_registration', 'serial_unavailable', '', $currentId);
        }
        $targetPass = $this->authWrite($userId, $replacement, 'Pass');
        if (empty($targetPass['ok'])) return $this->fail($userId, 'replace_onu_registration', 'replacement_register_failed',
            (string)($targetPass['message'] ?? ''), $currentId);
        $currentUndef = $this->authWrite($userId, $current, 'Undef');
        if (empty($currentUndef['ok'])) {
            $this->authWrite($userId, $replacement, 'Undef');
            return $this->fail($userId, 'replace_onu_registration', 'current_unregister_failed',
                (string)($currentUndef['message'] ?? ''), $currentId);
        }
        $currentName = trim((string)($current['onu_name'] ?? ''));
        if ($currentName !== '') {
            $rename = $this->snmp->renameOnu($userId, $replacementId, $currentName);
            if (empty($rename['ok'])) {
                $this->authWrite($userId, $current, 'Pass');
                $this->authWrite($userId, $replacement, 'Undef');
                return $this->fail($userId, 'replace_onu_registration', 'replacement_rename_failed',
                    (string)($rename['message'] ?? ''), $currentId);
            }
        }
        try {
            $transfer = $this->onuWriter()->transferRegistrationProfile($userId, $currentId, $replacementId);
            if (empty($transfer['ok'])) throw new RuntimeException((string)($transfer['message'] ?? 'Profil ONU gagal dipindahkan.'));
        } catch (Throwable $error) {
            $this->authWrite($userId, $current, 'Pass');
            $this->authWrite($userId, $replacement, 'Undef');
            return $this->fail($userId, 'replace_onu_registration', 'profile_transfer_failed', (string)$error, $currentId);
        }
        return ['ok' => true, 'code' => 'ok', 'data' => ['row_id' => $replacementId]];
    }

    private function fail(int $userId, string $action, string $code, string $detail = '', int $rowId = 0, array $extra = []): array
    {
        $message = $detail !== '' ? $detail : $code;
        if ($rowId > 0) $message .= ' | onu_row_id=' . $rowId;
        $validation = in_array($code, ['onu_invalid', 'name_required', 'manual_identity_invalid',
            'service_required', 'service_mode_invalid', 'lan_ports_invalid', 'lan_port_number_invalid',
            'outer_vlan_required', 'multiple_untag', 'service_index_invalid', 'duplicate_vlan',
            'wan_method_invalid', 'wan_service_required', 'wan_service_one_required', 'pppoe_credentials_required',
            'static_ip_invalid', 'service_wan_invalid', 'tr069_service_required', 'acs_required',
            'acs_credentials_pair', 'service_activate_required', 'replacement_invalid'], true);
        $driver = !$validation && (str_ends_with($code, '_failed') || $code === 'serial_unavailable');
        if (!$validation) {
            $this->log($userId, $action . ($driver ? '_driver' : ''),
                $message, $driver ? 'Driver' : 'ONU');
        }
        return ['ok' => false, 'code' => $code, 'data' => ['row_id' => $rowId] + $extra];
    }

    private function log(int $userId, string $action, string $message, string $area = 'ONU'): void
    {
        try {
            $this->db->logApplication($userId, $area, $action, 'error', $message);
        } catch (Throwable $error) {
            error_log('[LINNMS] ONU registration action log: ' . $error->getMessage());
        }
    }

    private function onuWriter(): OnuSnapshotWriteService
    {
        if ($this->onuWriter === null) {
            require_once __DIR__ . '/OnuSnapshotWriteService.php';
            $this->onuWriter = new OnuSnapshotWriteService($this->db);
        }
        return $this->onuWriter;
    }
}
