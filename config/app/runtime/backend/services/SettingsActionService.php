<?php

declare(strict_types=1);

final class SettingsActionService
{
    public function __construct(private LinnmsRepository $db)
    {
    }

    public function execute(int $userId, string $action, array $data): array
    {
        $user = $this->db->getUser($userId);
        if (!is_array($user) || (int)($user['is_active'] ?? 0) !== 1) return ['ok' => false, 'code' => 'forbidden'];
        $allowed = [
            'save_notifications', 'save_notification_setting', 'save_notification_devices',
            'save_numeric_setting', 'save_sync_timing_settings', 'save_device_driver_support',
            'refresh_device_access',
        ];
        if (!in_array($action, $allowed, true)) return ['ok' => false, 'code' => 'unsupported'];
        if (in_array($action, ['save_numeric_setting', 'save_sync_timing_settings',
            'save_device_driver_support', 'refresh_device_access'], true) && (string)$user['role'] !== 'admin') {
            return ['ok' => false, 'code' => 'forbidden'];
        }
        try {
            return match ($action) {
                'save_notifications' => $this->saveNotifications($userId, $data),
                'save_notification_setting' => $this->saveNotificationSetting($userId, $data),
                'save_notification_devices' => $this->saveNotificationDevices($userId, $data),
                'save_numeric_setting' => $this->saveNumericSetting($userId, $data),
                'save_sync_timing_settings' => $this->saveSyncTiming($userId, $data),
                'save_device_driver_support' => $this->saveDriverSupport($data),
                'refresh_device_access' => $this->refreshAccess(),
            };
        } catch (Throwable $error) {
            $validation = $error instanceof InvalidArgumentException;
            if (!$validation) {
                try {
                    $this->db->logApplication($userId, 'LINNMS APP', $action . '_error', 'error', (string)$error);
                } catch (Throwable $logError) {
                    error_log('[LINNMS] Settings action log: ' . $logError->getMessage());
                }
            }
            return ['ok' => false, 'code' => $validation ? 'validation' : 'internal'];
        }
    }

    private function saveNotifications(int $userId, array $data): array
    {
        $this->db->saveNotificationPreferences($userId, $data);
        return ['ok' => true, 'code' => 'ok'];
    }

    private function saveNotificationSetting(int $userId, array $data): array
    {
        $definitions = [
            'problem_threshold' => ['onu_rx_problem_threshold_dbm', 'number', LinnmsRepository::ONU_RX_PROBLEM_MIN_DBM, LinnmsRepository::ONU_RX_PROBLEM_MAX_DBM],
            'signal_threshold_dbm' => ['signal_threshold_dbm', 'number', LinnmsRepository::ONU_RX_DETECTION_MIN_DBM, LinnmsRepository::ONU_RX_DETECTION_MAX_DBM],
            'notify_onu_signal' => ['notify_onu_signal', 'toggle'],
            'notify_onu_state' => ['notify_onu_state', 'toggle'],
            'notify_device_status' => ['notify_device_status', 'toggle'],
            'notify_interface_changes' => ['notify_interface_changes', 'toggle'],
            'notify_pppoe_status' => ['notify_pppoe_status', 'toggle'],
            'notify_onu_problem' => ['notify_onu_problem', 'toggle'],
            'notify_onu_details' => ['notify_onu_details', 'toggle'],
        ];
        $definition = $definitions[trim((string)($data['setting_key'] ?? ''))] ?? null;
        if (!is_array($definition)) throw new InvalidArgumentException('Pengaturan notifikasi tidak valid.');
        $raw = trim((string)($data['setting_value'] ?? ''));
        if ($definition[1] === 'number') {
            if ($raw === '' || !is_numeric($raw)) throw new InvalidArgumentException('Nilai notifikasi bukan angka.');
            $value = (float)$raw;
            if (!is_finite($value) || $value < $definition[2] || $value > $definition[3]) {
                throw new InvalidArgumentException('Nilai notifikasi di luar rentang.');
            }
        } else {
            $value = $raw === '1' ? 1 : 0;
        }
        $this->db->saveNotificationPreference($userId, $definition[0], $value);
        return ['ok' => true, 'code' => 'ok'];
    }

    private function saveNotificationDevices(int $userId, array $data): array
    {
        $ids = $data['notification_device_ids'] ?? [];
        if (!is_array($ids)) throw new InvalidArgumentException('Pilihan perangkat tidak valid.');
        $this->db->saveNotificationSelectedDeviceIds($userId, $ids);
        return ['ok' => true, 'code' => 'ok'];
    }

    private function saveNumericSetting(int $userId, array $data): array
    {
        $key = trim((string)($data['setting_key'] ?? ''));
        $raw = trim((string)($data['setting_value'] ?? ''));
        if (!in_array($key, [
            'sync_fast_interval_minutes', 'olt_graph_interval_minutes', 'sync_discovery_interval_minutes',
            'sync_middle_interval_minutes', 'sync_fast_deadline_seconds', 'sync_discovery_deadline_seconds',
            'sync_middle_deadline_seconds', 'snmp_request_timeout_ms', 'onu_missing_scan_limit',
            'reader_count', 'writer_count', 'fast_sync_onu_batch_size',
        ], true) || preg_match('/^-?\d+$/', $raw) !== 1) throw new InvalidArgumentException('Pengaturan angka tidak valid.');
        $value = (int)$raw;
        if (in_array($key, ['sync_discovery_interval_minutes', 'sync_middle_interval_minutes'], true)) {
            $min = $key === 'sync_discovery_interval_minutes'
                ? LinnmsRepository::MIN_SYNC_DISCOVERY_INTERVAL_MINUTES : LinnmsRepository::MIN_SYNC_MIDDLE_INTERVAL_MINUTES;
            $max = $key === 'sync_discovery_interval_minutes'
                ? LinnmsRepository::MAX_SYNC_DISCOVERY_INTERVAL_MINUTES : LinnmsRepository::MAX_SYNC_MIDDLE_INTERVAL_MINUTES;
            if ($value < (int)ceil($min / 60) || $value > intdiv($max, 60)) {
                throw new InvalidArgumentException('Interval sinkronisasi di luar rentang.');
            }
            $value *= 60;
        }
        if (in_array($key, ['reader_count', 'writer_count'], true)) {
            $role = substr($key, 0, -strlen('_count'));
            $current = $this->db->getDaemonCount($role);
            if ($current !== $value) $this->db->saveDaemonCount($role, $value);
        } elseif ($key === 'fast_sync_onu_batch_size') {
            $current = $this->db->getFastSyncOnuBatchSize();
            if ($current !== $value) $this->db->saveFastSyncOnuBatchSize($value);
        } elseif ($key === 'olt_graph_interval_minutes') {
            $current = $this->db->getOltGraphIntervalMinutes();
            if ($current !== $value) $this->db->saveOltGraphIntervalMinutes($value);
        } elseif ($key === 'onu_missing_scan_limit') {
            $current = $this->db->getOnuMissingScanLimit();
            if ($current !== $value) $this->db->saveOnuMissingScanLimit($value);
        } else {
            $syncKey = $key === 'snmp_request_timeout_ms' ? $key : substr($key, strlen('sync_'));
            $current = (int)($this->db->getSyncTimingSettings($userId)[$syncKey] ?? $value);
            if ($current !== $value) $this->db->saveSyncTimingSetting($userId, $syncKey, $value);
        }
        return ['ok' => true, 'code' => $current === $value ? 'unchanged' : 'ok'];
    }

    private function saveSyncTiming(int $userId, array $data): array
    {
        $current = $this->db->getSyncTimingSettings($userId);
        $this->db->saveSyncTimingSettings($userId, [
            'fast_interval_minutes' => (int)($data['sync_fast_interval_minutes'] ?? LinnmsRepository::DEFAULT_SYNC_FAST_INTERVAL_MINUTES),
            'discovery_interval_minutes' => (int)($data['sync_discovery_interval_minutes'] ?? LinnmsRepository::DEFAULT_SYNC_DISCOVERY_INTERVAL_MINUTES),
            'middle_interval_minutes' => (int)($data['sync_middle_interval_minutes'] ?? $current['middle_interval_minutes']),
            'fast_deadline_seconds' => (int)($data['sync_fast_deadline_seconds'] ?? LinnmsRepository::DEFAULT_SYNC_FAST_DEADLINE_SECONDS),
            'discovery_deadline_seconds' => (int)($data['sync_discovery_deadline_seconds'] ?? LinnmsRepository::DEFAULT_SYNC_DISCOVERY_DEADLINE_SECONDS),
            'middle_deadline_seconds' => (int)($data['sync_middle_deadline_seconds'] ?? $current['middle_deadline_seconds']),
            'snmp_request_timeout_ms' => (int)($data['snmp_request_timeout_ms'] ?? LinnmsRepository::DEFAULT_SNMP_REQUEST_TIMEOUT_MS),
        ]);
        return ['ok' => true, 'code' => 'ok'];
    }

    private function saveDriverSupport(array $data): array
    {
        $this->bridge();
        $posted = is_array($data['driver_support'] ?? null) ? $data['driver_support'] : [];
        $apiTypes = is_array($data['api_olt_type_support'] ?? null) ? $data['api_olt_type_support'] : [];
        $olt = [];
        $snmp = [];
        foreach (monitoring_driver_catalog() as $id => $definition) {
            $group = (string)($definition['group'] ?? '');
            $enabled = !empty($posted[$id]) ? 1 : 0;
            if ($enabled === 1 && !device_access_allows((string)$id)) {
                throw new InvalidArgumentException('Perangkat tidak diizinkan.');
            }
            if ($group === 'olt') $olt[$id] = $enabled;
            elseif (in_array($group, ['router', 'switch'], true)) $snmp[$id] = $enabled;
        }
        foreach (array_keys($apiTypes) as $apiType) {
            if (!device_access_allows('api_olt_type:' . (string)$apiType)) {
                throw new InvalidArgumentException('Perangkat API tidak diizinkan.');
            }
        }
        $this->db->saveOltDriverSupportFlags($olt);
        $this->db->saveApiOltTypeSupportFlags($apiTypes);
        $this->db->saveSnmpDriverSupportFlags($snmp);
        return ['ok' => true, 'code' => 'ok'];
    }

    private function refreshAccess(): array
    {
        $bridge = $this->bridge();
        $changed = $bridge->refresh(true);
        $state = $bridge->state();
        return ['ok' => true, 'code' => ($state['mode'] ?? '') !== 'ready' ? 'inactive'
            : ($changed ? 'ok' : 'unchanged')];
    }

    private function bridge(): RuntimeBridge
    {
        $bridge = new RuntimeBridge($this->db->pdo(), (array)(require '/config/app/config/service.php'));
        $GLOBALS['bridge'] = $bridge;
        return $bridge;
    }
}
