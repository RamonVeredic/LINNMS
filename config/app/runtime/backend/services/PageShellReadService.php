<?php

declare(strict_types=1);

final class PageShellReadService
{
    public function __construct(private LinnmsRepository $db)
    {
    }

    public function read(int $userId, array $params): array
    {
        if ($userId < 1) throw new InvalidArgumentException('User tidak valid.');

        $page = (string)($params['page'] ?? '');
        $rowId = (int)($params['row_id'] ?? 0);
        $result = [
            'sidebar_unregistered_onus' => $this->db->countUnregisteredOnus($userId),
            'service_contact' => $this->db->getServiceContact(),
            'user_settings' => $this->db->getUserSettings($userId),
            'onu_problem_settings' => $this->db->getOnuProblemSettings($userId),
            'telegram_service_settings' => $this->db->getTelegramServiceSettings(),
            'whatsapp_user_settings' => $this->db->getUserWhatsAppSettings($userId),
            'whatsapp_service_settings' => $this->db->getWhatsAppNotificationServiceSettings(),
            'olt_driver_flags' => $this->db->getOltDriverSupportFlags(),
            'api_olt_type_flags' => $this->db->getApiOltTypeSupportFlags(),
            'snmp_driver_flags' => $this->db->getSnmpDriverSupportFlags(),
            'olt_quota' => $this->db->getUserOltQuota($userId),
            'mikrotik_quota' => $this->db->getUserSnmpDeviceQuota($userId, 'mikrotik'),
            'switch_quota' => $this->db->getUserSnmpDeviceQuota($userId, 'cisco_catalyst_2960s'),
        ];

        if ($page === 'device-edit-form' && $rowId > 0) {
            $result['editable_device'] = $this->db->getEditableDevice($userId, $rowId);
            $result['olt_driver_flags'] = $this->db->getOltDriverSupportFlags();
            $result['snmp_driver_flags'] = $this->db->getSnmpDriverSupportFlags();
        } elseif (in_array($page, ['view-mikrotik', 'view-mikrotik-interface', 'view-switch'], true) && $rowId > 0) {
            $result['traffic_interfaces'] = $this->db->getDeviceTrafficInterfaces($userId, $rowId);
        } elseif ($page === 'notification') {
            $result['notification_devices'] = $this->db->getNotificationDeviceRows($userId);
            $result['notification_selected_ids'] = $this->db->getNotificationSelectedDeviceIds($userId);
            $result['notification_limits'] = [
                'problem_threshold' => [
                    'value' => LinnmsRepository::clampOnuRxProblemLimit($result['onu_problem_settings']['rx_power_threshold_dbm'] ?? null),
                    'min' => LinnmsRepository::ONU_RX_PROBLEM_MIN_DBM,
                    'max' => LinnmsRepository::ONU_RX_PROBLEM_MAX_DBM,
                ],
                'signal_threshold_dbm' => [
                    'value' => LinnmsRepository::clampOnuRxDetection($result['user_settings']['signal_threshold_dbm'] ?? null),
                    'min' => LinnmsRepository::ONU_RX_DETECTION_MIN_DBM,
                    'max' => LinnmsRepository::ONU_RX_DETECTION_MAX_DBM,
                ],
            ];
        } elseif ($page === 'settings-admin') {
            $user = $this->db->getUser($userId);
            if (!is_array($user) || (string)($user['role'] ?? '') !== 'admin') {
                throw new RuntimeException('Akses pengaturan ditolak.');
            }
            $result['olt_graph_interval_minutes'] = $this->db->getOltGraphIntervalMinutes();
            $result['sync_timing_settings'] = $this->db->getSyncTimingSettings($userId);
            $result['onu_missing_scan_limit'] = $this->db->getOnuMissingScanLimit();
            $result['reader_count'] = $this->db->getDaemonCount('reader');
            $result['writer_count'] = $this->db->getDaemonCount('writer');
            $result['fast_sync_onu_batch_size'] = $this->db->getFastSyncOnuBatchSize();
            $result['managed_device_users'] = $this->db->getManagedUsersForDeviceLimits();
            $result['numeric_setting_limits'] = [
                'sync_fast_interval_minutes' => ['min' => LinnmsRepository::MIN_SYNC_FAST_INTERVAL_MINUTES, 'max' => LinnmsRepository::MAX_SYNC_FAST_INTERVAL_MINUTES, 'default' => LinnmsRepository::DEFAULT_SYNC_FAST_INTERVAL_MINUTES],
                'olt_graph_interval_minutes' => ['min' => LinnmsRepository::MIN_OLT_GRAPH_INTERVAL_MINUTES, 'max' => LinnmsRepository::MAX_OLT_GRAPH_INTERVAL_MINUTES, 'default' => LinnmsRepository::DEFAULT_OLT_GRAPH_INTERVAL_MINUTES],
                'sync_discovery_interval_minutes' => ['min' => LinnmsRepository::MIN_SYNC_DISCOVERY_INTERVAL_MINUTES, 'max' => LinnmsRepository::MAX_SYNC_DISCOVERY_INTERVAL_MINUTES, 'default' => LinnmsRepository::DEFAULT_SYNC_DISCOVERY_INTERVAL_MINUTES],
                'sync_middle_interval_minutes' => ['min' => LinnmsRepository::MIN_SYNC_MIDDLE_INTERVAL_MINUTES, 'max' => LinnmsRepository::MAX_SYNC_MIDDLE_INTERVAL_MINUTES, 'default' => LinnmsRepository::DEFAULT_SYNC_MIDDLE_INTERVAL_MINUTES],
                'sync_fast_deadline_seconds' => ['min' => LinnmsRepository::MIN_SYNC_DEADLINE_SECONDS, 'max' => LinnmsRepository::MAX_SYNC_DEADLINE_SECONDS, 'default' => LinnmsRepository::DEFAULT_SYNC_FAST_DEADLINE_SECONDS],
                'sync_discovery_deadline_seconds' => ['min' => LinnmsRepository::MIN_SYNC_DEADLINE_SECONDS, 'max' => LinnmsRepository::MAX_SYNC_DEADLINE_SECONDS, 'default' => LinnmsRepository::DEFAULT_SYNC_DISCOVERY_DEADLINE_SECONDS],
                'sync_middle_deadline_seconds' => ['min' => LinnmsRepository::MIN_SYNC_DEADLINE_SECONDS, 'max' => LinnmsRepository::MAX_SYNC_DEADLINE_SECONDS, 'default' => LinnmsRepository::DEFAULT_SYNC_MIDDLE_DEADLINE_SECONDS],
                'snmp_request_timeout_ms' => ['min' => 0, 'max' => LinnmsRepository::MAX_SNMP_REQUEST_TIMEOUT_MS, 'default' => LinnmsRepository::DEFAULT_SNMP_REQUEST_TIMEOUT_MS],
                'onu_missing_scan_limit' => ['min' => LinnmsRepository::MIN_ONU_MISSING_SCAN_LIMIT, 'max' => LinnmsRepository::MAX_ONU_MISSING_SCAN_LIMIT, 'default' => LinnmsRepository::DEFAULT_ONU_MISSING_SCAN_LIMIT],
                'reader_count' => ['min' => 1, 'max' => LinnmsRepository::MAX_READER_COUNT, 'default' => LinnmsRepository::DEFAULT_READER_COUNT],
                'fast_sync_onu_batch_size' => ['min' => LinnmsRepository::MIN_FAST_SYNC_ONU_BATCH_SIZE, 'max' => LinnmsRepository::MAX_FAST_SYNC_ONU_BATCH_SIZE, 'default' => LinnmsRepository::DEFAULT_FAST_SYNC_ONU_BATCH_SIZE],
                'writer_count' => ['min' => 1, 'max' => LinnmsRepository::MAX_WRITER_COUNT, 'default' => LinnmsRepository::DEFAULT_WRITER_COUNT],
            ];
        }

        if ($page !== 'dashboard') {
            $result['dashboard_stats'] = $this->db->getDashboardStats($userId);
            $result['olts'] = $this->db->getOlts($userId);
            $result['mikrotik_devices'] = $this->db->getSnmpDevices($userId, 'mikrotik');
            $result['switch_devices'] = $this->db->getSnmpDevices($userId, 'cisco_catalyst_2960s');
        }

        if ($page === 'view-olt' && $rowId > 0) {
            $olt = $this->db->getOltByRowId($userId, $rowId);
            if (is_array($olt)) {
                $ponSnapshot = $this->db->getOltPonSnapshot($userId, $rowId);
                $cards = $this->db->getOltCards($userId, $rowId, false);
                foreach ($cards as &$card) {
                    if (!is_array($card)) continue;
                    $versions = json_decode((string)($card['versions_json'] ?? ''), true);
                    $card['versions'] = is_array($versions) ? $versions : [];
                }
                unset($card);
                $modules = [];
                if (monitoring_row_uses_handler($olt, 'snmp_olt')) {
                    foreach ($ponSnapshot as $port) {
                        if (!is_array($port)) continue;
                        $ref = trim((string)($port['port_ref'] ?? $port['pon'] ?? ''));
                        if ($ref === '') continue;
                        $pon = 0;
                        if (preg_match('~(?:/|_)(\d+)$~', $ref, $match) === 1) {
                            $pon = (int)$match[1];
                        }
                        $optics = is_array($port['optics'] ?? null) ? $port['optics'] : [];
                        $modules[] = [
                            'ok' => true,
                            'pon' => $pon,
                            'port_ref' => $ref,
                            'link_status' => $port['link_status'] ?? null,
                            'admin_state' => $port['admin_state'] ?? ($port['enable'] ?? null),
                            'flow_control' => $port['flow_control'] ?? null,
                            'description' => $port['description'] ?? null,
                            'module_type' => $optics['module_type'] ?? null,
                            'vendor' => $optics['vendor'] ?? null,
                            'vendor_pn' => $optics['vendor_pn'] ?? null,
                            'vendor_sn' => $optics['vendor_sn'] ?? null,
                            'class' => $optics['class'] ?? null,
                            'connector' => $optics['connector'] ?? null,
                            'trans_distance' => $optics['trans_distance'] ?? null,
                            'version' => $optics['version'] ?? null,
                            'production_date' => $optics['production_date'] ?? null,
                            'wavelength' => $optics['wavelength'] ?? null,
                            'temperature' => $optics['temperature'] ?? null,
                            'voltage' => $optics['voltage'] ?? null,
                            'current' => $optics['current'] ?? null,
                            'tx_power' => $port['tx_power'] ?? ($optics['tx_power'] ?? null),
                        ];
                    }
                }
                $result['olt_snapshot'] = [
                    'detail' => $olt,
                    'pon' => $ponSnapshot,
                    'cards' => $cards,
                    'profiles' => $this->db->getOltProfileSnapshots($userId, $rowId),
                    'onu_types' => $this->db->getOltOnuTypes($userId, $rowId),
                    'system' => monitoring_row_uses_handler($olt, 'snmp_olt')
                        ? $this->db->getOltSystemSnapshot($userId, $rowId) : [],
                    'modules' => $modules,
                    'onu_stats' => $this->db->getOltOnuStats($userId, (int)$olt['olt_id'],
                        (string)($olt['source'] ?? 'api')),
                ];
            }
        }

        if ($page === 'view-onu' && $rowId > 0) {
            $onu = $this->db->getOnuByRowId($userId, $rowId);
            if (is_array($onu)) {
                $result['onu_snapshot'] = [
                    'detail' => $onu,
                    'problem_onus' => $this->db->getProblemOnus($userId, 1000),
                    'lan_ports' => $this->db->getOnuEthernetPorts($userId, $rowId),
                    'olt_row' => $this->db->getOltByRowId($userId, (int)($onu['olt_row_id'] ?? 0)),
                ];
            }
        }

        if ($page === 'whatsapp' || $page === 'telegram') {
            $channel = $page;
            $pageSize = LinnmsRepository::NOTIFICATION_HISTORY_PAGE_SIZE;
            $total = $this->db->countNotificationHistory($userId, $channel);
            $pages = max(1, (int)ceil($total / $pageSize));
            $historyPage = max(1, min($pages, (int)($params['notif_page'] ?? 1)));
            $result['notification_history'] = [
                'page_size' => $pageSize,
                'total' => $total,
                'page' => $historyPage,
                'pages' => $pages,
                'rows' => $this->db->getNotificationHistory($userId, $channel, $pageSize,
                    ($historyPage - 1) * $pageSize),
            ];
        }
        if ($page === 'applogs') {
            $user = $this->db->getUser($userId);
            $activityUserId = (string)($user['role'] ?? '') === 'admin' ? null : $userId;
            $pageSize = LinnmsRepository::ACTIVITY_FEED_PAGE_SIZE;
            $total = $this->db->countApplicationLogs(null, $activityUserId);
            $pages = max(1, (int)ceil($total / $pageSize));
            $activityPage = max(1, min($pages, (int)($params['log_page'] ?? 1)));
            $result['application_logs'] = [
                'page_size' => $pageSize,
                'total' => $total,
                'page' => $activityPage,
                'pages' => $pages,
                'rows' => $this->db->getApplicationLogs($pageSize, null, $activityUserId,
                    ($activityPage - 1) * $pageSize),
            ];
        }

        return $result;
    }
}
