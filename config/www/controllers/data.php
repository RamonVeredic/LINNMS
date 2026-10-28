<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';
require_once LINNMS_UI_ROOT . '/controllers/dashboard.php';

function data_error_result(string $code, string $type = ''): array
{
    $message = match ($code) {
        'unauthorized' => 'Sesi aplikasi telah berakhir.',
        'method_not_allowed' => 'Method tidak didukung.',
        'port_invalid' => 'Port LAN tidak valid.',
        'validation' => match ($type) {
            'olt-scan-status' => 'OLT tidak valid.',
            'zte-onu-remote-interface' => 'Antarmuka ONU tidak valid.',
            default => 'ONU tidak valid.',
        },
        default => 'Permintaan tidak didukung.',
    };
    $status = match ($code) {
        'unauthorized' => 401,
        'method_not_allowed' => 405,
        'validation', 'port_invalid' => 422,
        default => 404,
    };
    return ['status' => $status, 'payload' => ['ok' => false, 'message' => $message]];
}

function onu_snapshot_detail_result(int $userId, string $type, int $rowId, string $kind = ''): array
{
    try {
        $result = internal_api_request($userId, 'data.snapshot.read', [
            'type' => $type,
            'onu_row_id' => $rowId,
            'kind' => $kind,
        ], 60);
    } catch (Throwable $error) {
        error_log('[LINNMS] ONU live read API: ' . $error->getMessage());
        $result = ['ok' => false, 'code' => 'internal', 'data' => []];
    }

    $code = (string)($result['code'] ?? 'internal');
    $ok = !empty($result['ok']);
    $status = $ok ? 200 : match ($code) {
        'validation' => 422,
        'not_found' => 404,
        'forbidden' => 403,
        'conflict' => 409,
        'device_unreachable', 'device_auth_failed', 'device_rejected' => 502,
        default => 500,
    };
    $message = match ($code) {
        'ok' => 'Data berhasil dibaca.',
        'validation' => 'Permintaan pembacaan ONU tidak valid.',
        'not_found' => 'ONU tidak ditemukan.',
        'forbidden' => 'Akses ONU tidak diizinkan.',
        'unsupported' => 'Pembacaan data ini belum tersedia.',
        'device_unreachable' => 'Perangkat tidak dapat dijangkau.',
        'device_auth_failed' => 'Autentikasi perangkat gagal.',
        'device_rejected' => 'Data gagal dibaca.',
        'conflict' => 'ONU sedang diproses. Coba lagi.',
        default => 'Data tidak dapat diproses.',
    };
    return ['status' => $status, 'payload' => ['ok' => $ok, 'message' => $message]
        + (is_array($result['data'] ?? null) ? $result['data'] : [])];
}

function data_snapshot_read_result(int $userId, array $params): array
{
    try {
        $result = internal_api_request($userId, 'data.snapshot.read', $params);
    } catch (Throwable $error) {
        error_log('[LINNMS] Data snapshot API: ' . $error->getMessage());
        $result = ['ok' => false, 'code' => 'internal', 'data' => []];
    }
    $code = (string)($result['code'] ?? 'internal');
    $type = (string)($params['type'] ?? '');
    $ok = !empty($result['ok']);
    $status = $ok ? 200 : match ($code) {
        'validation' => 422,
        'not_found' => 404,
        'forbidden' => 403,
        'unsupported' => 422,
        'conflict' => 409,
        default => 500,
    };
    $message = match ($code) {
        'validation' => 'Permintaan data tidak valid.',
        'not_found' => 'Data tidak ditemukan.',
        'forbidden' => 'Akses data tidak diizinkan.',
        'unsupported' => 'Data belum tersedia.',
        'conflict' => 'Data sedang diproses.',
        default => 'Data tidak dapat dimuat.',
    };
    $payload = ['ok' => $ok] + (is_array($result['data'] ?? null) ? $result['data'] : []);
    if ($ok && $type === 'olt-scan-status') {
        $phase = trim((string)($payload['phase'] ?? ''));
        $count = max(0, (int)($payload['processed_onus'] ?? 0));
        $payload['progress_label'] = ($phase !== '' ? $phase : 'Membaca perangkat')
            . ($count > 0 ? ' · ' . $count . ' ONU' : '');
        $payload['completion_level'] = (string)($payload['status'] ?? '') === 'done' ? 'success' : 'error';
        $payload['completion_message'] = $payload['completion_level'] === 'success'
            ? 'Fast, Middle, dan Low Sync OLT selesai.'
            : 'Sinkronisasi OLT gagal. Periksa log aplikasi untuk detailnya.';
        unset($payload['last_error']);
    }
    if ($ok && $type === 'onu-ethernet-port') {
        require_once project_path('view', 'shared', 'models', 'OnuEthernetViewModel.php');
        $payload['detail'] = OnuEthernetViewModel::port(
            is_array($payload['port_row'] ?? null) ? $payload['port_row'] : [],
            (string)($payload['ui_family'] ?? ''),
            is_array($payload['vlan_ids'] ?? null) ? $payload['vlan_ids'] : [],
            !empty($payload['can_write'])
        );
        unset($payload['port_row'], $payload['ui_family'], $payload['vlan_ids'], $payload['can_write']);
    }
    if (!$ok) $payload['message'] = $message;
    return ['status' => $status, 'payload' => $payload];
}

function whatsapp_fields_read_result(int $userId, string $provider): array
{
    try {
        $result = internal_api_request($userId, 'whatsapp.fields.read', ['provider' => $provider]);
    } catch (Throwable $error) {
        error_log('[LINNMS] WhatsApp fields API: ' . $error->getMessage());
        return ['status' => 502, 'payload' => ['ok' => false, 'message' => 'Data gateway tidak dapat dimuat.']];
    }
    if (empty($result['ok'])) {
        return ['status' => 422, 'payload' => ['ok' => false, 'message' => 'Gateway tidak dikenal.']];
    }
    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
    $whatsappDefinition = is_array($data['definition'] ?? null) ? $data['definition'] : [];
    $whatsappUserSettings = is_array($data['saved_fields'] ?? null) ? $data['saved_fields'] : [];
    ob_start();
    require project_path('pages', 'partials', 'whatsapp', 'whatsapp-credential-fields.php');
    $fieldsHtml = (string)ob_get_clean();
    return ['status' => 200, 'payload' => [
        'ok' => true,
        'provider' => (string)($data['provider'] ?? $provider),
        'label' => (string)($whatsappDefinition['label'] ?? $provider),
        'info' => (string)($whatsappDefinition['info'] ?? ''),
        'info_url' => (string)($data['info_url'] ?? ''),
        'fields_html' => $fieldsHtml,
        'supports_groups' => !empty($data['supports_groups']),
        'supports_group_listing' => !empty($data['supports_group_listing']),
    ]];
}

function dashboard_detail_result(int $userId, array $query): array
{
    try {
        $category = trim((string)($query['category'] ?? ''));
        $detailRead = internal_api_request($userId, 'dashboard.detail.read', $query);
        if (empty($detailRead['ok'])) return ['status' => 422, 'payload' => ['ok' => false, 'message' => 'Kategori dashboard tidak dikenal.']];
        $detailData = is_array($detailRead['data'] ?? null) ? $detailRead['data'] : [];
        $categoryMeta = [
            'total_devices' => ['kind' => 'device'],
            'mikrotik' => ['kind' => 'device'],
            'olt' => ['kind' => 'device'],
            'switch' => ['kind' => 'device'],
            'total_onus' => ['kind' => 'onu'],
            'online_onus' => ['kind' => 'onu'],
            'down_onus' => ['kind' => 'onu'],
            'power_down_onus' => ['kind' => 'onu'],
            'loss_onus' => ['kind' => 'onu'],
            'problem_onus' => ['kind' => 'onu'],
        ];
        if (!isset($categoryMeta[$category])) {
            return ['status' => 422, 'payload' => ['ok' => false, 'message' => 'Kategori dashboard tidak dikenal.']];
        }
        $detailKind = (string)$categoryMeta[$category]['kind'];


        $rows = [];
        $deviceCounts = [];
        $truncated = false;
        $detailMaxRows = 1000;
        $onuIdentityKey = static function (array $onu): string {
            return strtolower(trim((string)($onu['source'] ?? ''))) . '|' . (int)($onu['olt_id'] ?? 0) . '|' . trim((string)($onu['onu_index'] ?? ''));
        };
        $onuPayload = static function (array $onu, ?array $problem = null) use ($onuIdentityKey): array {
            $baseState = effective_onu_state_key($onu['onu_state'] ?? null, $onu['rx_power'] ?? null, onu_loss_without_rx($onu));
            $row = [
                'kind' => 'onu',
                'url' => public_page_url('view-onu', ['id' => (int)($onu['id'] ?? 0)]),
                'olt' => display_text($onu['olt_name'] ?? null),
                'index' => (string)($onu['onu_index'] ?? ''),
                'name' => display_onu_name($onu['onu_name'] ?? null),
                'location' => display_text($onu['onu_location'] ?? null),
                'serial' => display_onu_serial($onu['onu_serial'] ?? null),
                'rx' => onu_rx_power_display($onu['rx_power'] ?? null),
                'rx_class' => power_class($onu['rx_power'] ?? null),
                'rx_icon' => power_icon_class($onu['rx_power'] ?? null),
                'state_key' => $baseState,
                'state' => state_label($baseState),
                'note' => '',
                'since' => '',
                'problem' => false,
            ];
            if ($problem !== null) {
                $row['problem'] = true;
                $row['note'] = display_text($problem['problem_description'] ?? null);
                if (trim($row['note']) === 'N/A' || trim($row['note']) === '—') $row['note'] = 'Bermasalah';
                $row['since'] = format_time_ago((string)($problem['problem_since'] ?? ''));
                if (($problem['replace_state'] ?? true) !== false) {
                    $row['state_key'] = 'problem';
                    $row['state'] = display_text($problem['problem_status'] ?? null, 'Bermasalah');
                }
            }
            $row['search'] = mb_strtolower(implode(' ', array_values(array_filter(
                [$row['olt'], $row['index'], $row['name'], $row['location'], $row['serial'], (string)$row['state'], (string)$row['note']],
                static fn(string $piece): bool => $piece !== '' && $piece !== 'N/A' && $piece !== '-'
            ))));
            return $row;
        };

        if ($detailKind === 'device') {
            $apiOltFlags = (array)($detailData['api_olt_flags'] ?? []);
            $driverFlags = (array)($detailData['driver_flags'] ?? []);
            $apiKeyOn = !empty($detailData['api_key_on']);

            $deviceGroups = [
                ['kind' => 'olt', 'label' => 'OLT', 'icon' => 'bi-hdd-rack', 'page' => 'view-olt', 'rows' => (array)($detailData['olts'] ?? [])],
                ['kind' => 'mikrotik', 'label' => 'MikroTik', 'icon' => 'bi-router', 'page' => 'view-mikrotik', 'rows' => (array)($detailData['mikrotik'] ?? [])],
                ['kind' => 'switch', 'label' => 'Switch', 'icon' => 'bi-hdd-network', 'page' => 'view-switch', 'rows' => (array)($detailData['switch'] ?? [])],
            ];
            foreach ($deviceGroups as $group) {
                foreach ($group['rows'] as $device) {
                    if ($group['kind'] === 'olt') {
                        $isSnmpDriver = monitoring_row_uses_handler($device, 'snmp_olt');
                        $driverId = olt_monitoring_driver($device);
                        $isApiDriver = $driverId === 'external_olt_api';
                        $driverEnabled = $isApiDriver
                            ? (!empty($apiKeyOn) && api_olt_type_support_enabled($apiOltFlags, (string)($device['type'] ?? '')))
                            : ($driverId !== '' && olt_driver_support_enabled($driverFlags, $driverId));
                        $driverStatus = (string)($device['driver_status'] ?? '');
                        $isActive = $driverEnabled
                            && ($isSnmpDriver ? $driverStatus === 'OK' : !empty($device['first_scan_started_at']));
                        $statusLabel = !$driverEnabled
                            ? 'Tidak tersedia'
                            : ($isActive
                                ? 'Aktif'
                                : ($driverStatus === 'DOWN'
                                    ? 'Tidak terjangkau'
                                    : ($driverStatus === 'ERROR' ? 'Perlu perhatian' : 'Belum aktif')));
                        $port = (int)($device['snmp_port'] ?? 0);
                        $address = display_text($device['ip_address'] ?? null);
                        if ($address !== 'N/A' && $port > 0) $address .= ':' . $port;
                        $snapshot = olt_system_snapshot($device);
                        $model = trim((string)($device['model'] ?? ''));
                        if ($model === '') $model = trim((string)($snapshot['model'] ?? ''));
                        $info = (int)($device['total_onus'] ?? 0) . ' ONU';
                    } else {
                        $statusLabel = (string)($device['driver_status'] ?? '') === 'online' ? 'Aktif' : 'Perlu perhatian';
                        $isActive = $statusLabel === 'Aktif';
                        $port = (int)($device['snmp_port'] ?? 0);
                        $address = display_text($device['ip_address'] ?? null);
                        if ($address !== 'N/A' && $port > 0) $address .= ':' . $port;
                        $model = trim((string)($device['model'] ?? ''));
                        if ($model === '') {
                            $system = json_decode((string)($device['system_json'] ?? ''), true);
                            if (is_array($system)) $model = trim((string)($system['model'] ?? ''));
                        }
                        $interfaceRows = json_decode((string)($device['interfaces_json'] ?? ''), true);
                        if (!is_array($interfaceRows)) $interfaceRows = [];
                        if ($group['kind'] === 'switch') {
                            $interfaceRows = array_values(array_filter($interfaceRows, static function (mixed $interface): bool {
                                if (!is_array($interface)) return false;
                                return preg_match('/^(?:stackport|stacksub(?:-|$))/i', trim((string)($interface['name'] ?? ''))) !== 1;
                            }));
                        } else {
                            $interfaceRows = array_values(array_filter($interfaceRows, static function (mixed $interface): bool {
                                if (!is_array($interface)) return false;
                                $type = strtolower(trim((string)($interface['interface_type'] ?? '')));
                                $name = strtolower((string)($interface['name'] ?? ''));
                                return $type !== 'pppoe' && !str_contains($name, 'pppoe');
                            }));
                        }
                        $info = count($interfaceRows) . ' Interface';
                    }
                    $name = display_text($device['name'] ?? null);
                    $statusKey = $isActive ? 'online' : 'offline';
                    $rows[] = [
                        'kind' => 'device',
                        'url' => public_page_url((string)$group['page'], ['id' => (int)($device['id'] ?? 0)]),
                        'type' => (string)$group['label'],
                        'icon' => (string)$group['icon'],
                        'name' => $name,
                        'address' => $address,
                        'model' => $model,
                        'info' => $info,
                        'status' => $statusLabel,
                        'state_key' => $statusKey,
                        'search' => mb_strtolower(implode(' ', array_values(array_filter(
                            [$name, $address, $model, (string)$group['label'], $statusLabel],
                            static fn(string $piece): bool => $piece !== '' && $piece !== 'N/A'
                        )))),
                    ];
                }
            }
        } else {
            $perPage = max(25, min(200, (int)($query['per'] ?? 100)));
            $pageNo = max(1, (int)($query['p'] ?? 1));
            $modalStatus = match ($category) {
                'online_onus' => 'online',
                'down_onus' => 'down',
                'power_down_onus' => 'powerdown',
                'loss_onus' => 'loss',
                'problem_onus' => 'problem',
                default => '',
            };
            $modalFilters = [
                'olt' => null,
                'source' => null,
                'pon' => '',
                'status' => $modalStatus,
                'q' => mb_substr(trim((string)($query['q'] ?? '')), 0, 100),
                'device' => mb_substr(trim((string)($query['device'] ?? '')), 0, 100),
            ];
            $list = (array)($detailData['list'] ?? []);
            $detailTotal = $list['total'];
            $detailPages = $list['pages'];
            $detailPage = $list['page'];
            foreach ($list['rows'] as $onu) {
                $problem = !empty($onu['is_problem']) ? [
                    'problem_description' => $onu['problem_description'] ?? null,
                    'problem_since' => $onu['problem_since'] ?? null,
                    'replace_state' => false,
                ] : null;
                $rows[] = $onuPayload($onu, $problem);
            }
            $deviceOptions = [];
            foreach ((array)($detailData['device_counts'] ?? []) as $deviceCount) {
                $deviceOptions[] = ['value' => $deviceCount['label'], 'label' => $deviceCount['label'], 'meta' => $deviceCount['count'] . ' ONU'];
            }
        }

        if ($detailKind === 'device') {
            if (count($rows) > $detailMaxRows) {
                $truncated = true;
                $rows = array_slice($rows, 0, $detailMaxRows);
            }
            foreach ($rows as $categoryRow) {
                if (($categoryRow['kind'] ?? '') !== 'onu') continue;
                $deviceLabel = (string)($categoryRow['olt'] ?? '');
                if ($deviceLabel === '' || $deviceLabel === 'N/A' || $deviceLabel === '-') continue;
                $deviceCounts[$deviceLabel] = ($deviceCounts[$deviceLabel] ?? 0) + 1;
            }
            uksort($deviceCounts, static fn(string $a, string $b): int => strnatcasecmp($a, $b));
            $deviceOptions = [];
            foreach ($deviceCounts as $deviceLabel => $deviceCount) {
                $deviceOptions[] = ['value' => $deviceLabel, 'label' => $deviceLabel, 'meta' => $deviceCount . ' ONU'];
            }
            $detailTotal = count($rows);
            $detailPages = 1;
            $detailPage = 1;
        }

        return ['status' => 200, 'payload' => [
            'ok' => true,
            'category' => $category,
            'kind' => $detailKind,
            'count' => count($rows),
            'truncated' => $truncated,
            'page' => $detailPage ?? 1,
            'pages' => $detailPages ?? 1,
            'total' => $detailTotal ?? count($rows),
            'devices' => $deviceOptions,
            'rows' => $rows,
            'polled_at' => time(),
        ]];
    } catch (Throwable $e) {
        error_log('Dashboard detail failed: ' . $e->getMessage());
        return ['status' => 502, 'payload' => ['ok' => false, 'message' => 'Data kategori tidak dapat dimuat.']];
    }
}

function recent_activity_result(int $userId, array $query): array
{
    try {
        $activityRead = internal_api_request($userId, 'activity.read', $query);
        $recent = dashboard_recent_activity_rows((array)($activityRead['events'] ?? []));
        $activityPage = (int)($activityRead['page'] ?? 1);
        $activityPages = (int)($activityRead['pages'] ?? 1);
        $activityTotal = (int)($activityRead['total'] ?? 0);
        $activitySearch = (string)($activityRead['search'] ?? '');
        $activityDevice = (string)($activityRead['device'] ?? '');
        $recentActivityEmptyText = trim($activitySearch) !== '' || trim($activityDevice) !== ''
            || trim((string)($activityRead['category'] ?? '')) !== ''
            ? 'Tidak ditemukan.' : 'Belum ada aktivitas terbaru';
        ob_start();
        require project_path('pages', 'partials', 'dashboard', 'recent-activity.php');
        $html = (string)ob_get_clean();
        $activityUrl = static function (int $targetPage) use ($activitySearch, $activityDevice): string {
            $params = ['activity_page' => max(1, $targetPage)];
            if ($activitySearch !== '') $params['activity_q'] = $activitySearch;
            if ($activityDevice !== '') $params['activity_device'] = $activityDevice;
            return public_page_url('dashboard', $params);
        };
        ob_start();
        render_numbered_pagination([
            'page' => $activityPage,
            'pages' => $activityPages,
            'total' => $activityTotal,
            'url' => $activityUrl,
            'label' => 'Halaman aktivitas',
            'goto' => 'data-activity-goto',
            'class' => 'recent-activity-pagination',
        ]);
        $paginationHtml = (string)ob_get_clean();
        $payload = [
            'ok' => true,
            'html' => $html,
            'pagination_html' => $paginationHtml,
            'page_info' => 'Halaman ' . $activityPage . ' dari ' . $activityPages,
            'count' => count($recent),
            'activity_page' => $activityPage,
            'activity_pages' => $activityPages,
            'activity_total' => $activityTotal,
            'recent_version' => hash('sha256', $html),
            'polled_at' => time(),
        ];

        if ((string)($query['include_dashboard'] ?? '') === '1') {
            $dashboardRead = internal_api_request($userId, 'dashboard.read', ['include_activity' => false]);
            $dashboardDevices = is_array($dashboardRead['devices'] ?? null) ? $dashboardRead['devices'] : [];
            $dashboardStats = is_array($dashboardRead['stats'] ?? null) ? $dashboardRead['stats'] : [];
            $dashboardDeviceRows = dashboard_device_table_rows(
                (array)($dashboardDevices['olt'] ?? []),
                (array)($dashboardDevices['mikrotik'] ?? []),
                (array)($dashboardDevices['switch'] ?? []),
                (array)($dashboardRead['scan'] ?? [])
            );
            $dashboardDeviceRowCount = count($dashboardDeviceRows);

            ob_start();
            require project_path('pages', 'partials', 'dashboard', 'device-table.php');
            $devicesHtml = (string)ob_get_clean();

            $payload['devices_html'] = $devicesHtml;
            $payload['devices_version'] = hash('sha256', $devicesHtml);
            $payload['dashboard_stats'] = [
                'total_devices' => count((array)($dashboardDevices['olt'] ?? []))
                    + count((array)($dashboardDevices['mikrotik'] ?? []))
                    + count((array)($dashboardDevices['switch'] ?? [])),
                'total_onus' => (int)($dashboardStats['total_onus'] ?? 0),
                'online_onus' => (int)($dashboardStats['online_onus'] ?? 0),
                'down_onus' => (int)($dashboardStats['down_onus'] ?? 0),
                'power_down_onus' => (int)($dashboardStats['power_down_onus'] ?? 0),
                'loss_onus' => (int)($dashboardStats['loss_onus'] ?? 0),
                'problem_onus' => (int)($dashboardStats['problem_onus'] ?? 0),
            ];
        }

        return ['status' => 200, 'payload' => $payload];
    } catch (Throwable $e) {
        error_log('Recent activity polling failed: ' . $e->getMessage());
        return ['status' => 502, 'payload' => ['ok' => false, 'message' => 'Aktivitas terbaru tidak dapat dimuat.']];
    }
}
