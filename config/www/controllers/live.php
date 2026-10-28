<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';

function live_error_result(string $code): array
{
    $status = match ($code) {
        'unauthorized' => 401,
        'forbidden' => 403,
        'method_not_allowed' => 405,
        'conflict' => 409,
        default => 404,
    };
    $message = match ($code) {
        'unauthorized' => 'Sesi aplikasi telah berakhir.',
        'forbidden' => 'Akun tidak aktif.',
        'method_not_allowed' => 'Method tidak didukung.',
        'conflict' => 'Konteks realtime berakhir. Muat ulang halaman perangkat.',
        default => 'Permintaan tidak didukung.',
    };
    return ['status' => $status, 'payload' => ['ok' => false, 'message' => $message]];
}

function live_direct_result(int $userId, string $type, array $context, array $query): array
{
    $indexes = array_slice(array_values(array_unique(array_filter(array_map('intval',
        explode(',', (string)($query['indexes'] ?? ''))), static fn(int $value): bool => $value > 0))), 0, 256);
    try {
        $result = internal_api_request($userId, 'device.realtime.read', [
            'type' => $type,
            'context' => $context,
            'interface' => trim((string)($query['interface'] ?? '')),
            'indexes' => $indexes,
        ], 30);
    } catch (Throwable $error) {
        error_log('[LINNMS] Device realtime API: ' . $error->getMessage());
        $result = ['ok' => false, 'code' => 'internal', 'data' => []];
    }
    $ok = !empty($result['ok']);
    $code = (string)($result['code'] ?? 'internal');
    if ($ok) return ['status' => 200, 'payload' => ['ok' => true]
        + (is_array($result['data'] ?? null) ? $result['data'] : [])];
    return ['status' => $code === 'conflict' ? 409 : ($code === 'forbidden' ? 403 : 502), 'payload' => [
        'ok' => false,
        'message' => $code === 'forbidden' ? 'Akun tidak aktif.' : ($code === 'conflict'
            ? 'Konteks realtime berakhir. Muat ulang halaman perangkat.'
            : ($type === 'device-traffic' ? 'Data trafik perangkat tidak dapat dibaca.' : 'Data realtime perangkat tidak dapat dibaca.')),
    ]];
}

function live_device_action_result(int $userId, string $type, array $input): array
{
    unset($input['csrf']);
    try {
        $result = internal_api_request($userId, 'live.device.action', ['type' => $type, 'data' => $input], 480);
    } catch (Throwable $error) {
        error_log('[LINNMS] Live device action API: ' . $error->getMessage());
        $result = ['ok' => false, 'code' => 'internal', 'data' => []];
    }
    $ok = !empty($result['ok']);
    $code = (string)($result['code'] ?? 'internal');
    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
    $messages = [
        'olt-uplink-save' => ['Konfigurasi uplink tersimpan.', 'Konfigurasi uplink gagal disimpan.'],
        'olt-pon-flow-control-save' => ['Flow Control PON tersimpan.', 'Flow Control PON gagal disimpan.'],
        'olt-profile-save' => ['Profil tersimpan.', 'Profil gagal disimpan.'],
        'olt-profile-delete' => ['Profil dihapus.', 'Profil gagal dihapus.'],
        'onu-interface-state-save' => ['Status antarmuka tersimpan.', 'Status antarmuka gagal disimpan.'],
        'onu-service-config-delete' => ['Layanan ONU dihapus.', 'Layanan ONU gagal dihapus.'],
        'onu-tr069-save' => ['Pengaturan TR069 tersimpan.', 'Pengaturan TR069 gagal disimpan.'],
        'onu-remote-access-save' => ['Pengaturan remote access tersimpan.', 'Pengaturan remote access gagal disimpan.'],
        'onu-veip-save' => ['Pengaturan VEIP tersimpan.', 'Pengaturan VEIP gagal disimpan.'],
        'onu-wan-ip-save' => ['Pengaturan WAN IP tersimpan.', 'Pengaturan WAN IP gagal disimpan.'],
        'onu-data-service-save' => ['Service tersimpan.', 'Service gagal disimpan.'],
        'onu-ethernet-save' => ['Pengaturan port LAN tersimpan.', 'Pengaturan port LAN gagal disimpan.'],
        'onu-wifi-ssid-save' => ['SSID WiFi tersimpan.', 'SSID WiFi gagal disimpan.'],
        'onu-wifi-config-save' => ['Pengaturan WiFi tersimpan.', 'Pengaturan WiFi gagal disimpan.'],
        'onu-unconfigured-refresh' => ['Daftar ONU belum terdaftar diperbarui.', 'Daftar ONU belum terdaftar gagal diperbarui.'],
        'onu-action' => ['Aksi ONU berhasil dijalankan.', 'Aksi ONU gagal dijalankan.'],
    ];
    [$successMessage, $failureMessage] = $messages[$type] ?? ['Perubahan tersimpan.', 'Perubahan gagal disimpan.'];
    if ($type === 'olt-pon-status-save') {
        $successMessage = ($data['state'] ?? '') === 'Disable' ? 'PON berhasil dimatikan.' : 'PON berhasil dihidupkan.';
        $failureMessage = 'Status PON gagal disimpan.';
    } elseif ($type === 'start-olt-discovery') {
        $successMessage = !empty($data['already_running'])
            ? 'Sinkronisasi OLT masih berjalan.' : 'Fast, Middle, dan Low Sync OLT masuk antrean.';
        $failureMessage = $code === 'forbidden'
            ? (($data['reason'] ?? '') === 'driver_disabled' ? 'Driver OLT sedang dinonaktifkan.' : 'Akses OLT tidak diizinkan.')
            : 'OLT tidak ditemukan.';
    } elseif ($type === 'olt-view-refresh') {
        $successMessage = !empty($data['partial'])
            ? 'Data OLT diperbarui sebagian.' : 'Data OLT diperbarui.';
        $failureMessage = !empty($data['busy']) ? 'Sinkronisasi OLT masih berjalan.' : 'Data OLT gagal diperbarui.';
        $data['warnings'] = [];
    } elseif ($type === 'olt-port-update') {
        $failureMessage = 'PON gagal diperbarui.';
        $data['port'] = null;
    }
    if (!$ok && !in_array($type, ['start-olt-discovery', 'olt-view-refresh', 'olt-port-update'], true)) {
        $failureMessage = match ($code) {
            'validation' => 'Periksa kembali data yang diisi.',
            'not_found' => 'Perangkat tidak ditemukan.',
            'forbidden' => 'Akses perangkat tidak diizinkan.',
            'unsupported' => 'Fitur ini belum tersedia.',
            'device_unreachable' => 'Perangkat tidak dapat dijangkau.',
            'device_auth_failed' => 'Autentikasi perangkat gagal.',
            'conflict' => 'Perangkat sedang diproses.',
            default => $failureMessage,
        };
    }
    $status = $ok ? 200 : match ($code) {
        'validation' => 422,
        'not_found' => 404,
        'forbidden' => 403,
        'unsupported' => $type === 'olt-port-update' ? 409 : 422,
        'conflict' => $type === 'olt-view-refresh' ? 202 : 409,
        'device_unreachable', 'device_auth_failed', 'device_rejected' => 502,
        default => 500,
    };
    return ['status' => $status, 'payload' => ['ok' => $ok, 'message' => $ok ? $successMessage : $failureMessage] + $data];
}

function live_device_action_input_error(string $type, array $input): ?string
{
    $oltId = (int)($input['olt_row_id'] ?? 0);
    $onuId = (int)($input['onu_row_id'] ?? 0);
    $port = (int)($input['port'] ?? 0);
    $kind = trim((string)($input['kind'] ?? ''));
    $index = trim((string)($input['index'] ?? ''));
    $defaultVlan = trim((string)($input['default_vlan'] ?? ''));
    $vlans = preg_replace('/\s+/', '', trim((string)($input['vlans'] ?? '')));
    $vlanValid = ($defaultVlan === '' || ctype_digit($defaultVlan))
        && ($vlans === '' || preg_match('/^\d{1,4}(?:,\d{1,4})*$/D', $vlans) === 1);
    return match ($type) {
        'olt-uplink-save' => $oltId > 0 && trim((string)($input['port_ref'] ?? '')) !== ''
            && is_array(json_decode((string)($input['config'] ?? '{}'), true))
            && is_array(json_decode((string)($input['vlan'] ?? '{}'), true))
                ? null : 'Konfigurasi uplink tidak valid.',
        'olt-pon-status-save' => $oltId > 0
            && in_array((string)($input['olt_port_enable'] ?? ''), ['Enable', 'Disable'], true)
                ? null : 'Status PON tidak valid.',
        'olt-pon-flow-control-save' => $oltId > 0 && (int)($input['olt_port_number'] ?? 0) > 0
            && in_array((string)($input['value'] ?? ''), ['Enable', 'Disable'], true)
                ? null : 'Flow Control PON tidak valid.',
        'olt-profile-save' => $oltId > 0 && $kind !== ''
            && is_array(json_decode((string)($input['data'] ?? '{}'), true))
                ? null : 'Data profil tidak valid.',
        'olt-profile-delete' => $oltId > 0 && $kind !== ''
            && trim((string)($input['name'] ?? '')) !== '' ? null : 'Data profil tidak valid.',
        'onu-interface-state-save' => $onuId > 0 && in_array($kind, ['wifi', 'veip'], true)
            && in_array((string)($input['state'] ?? ''), ['lock', 'unlock'], true)
                ? null : 'Status antarmuka tidak valid.',
        'onu-service-config-delete' => $onuId > 0 && in_array($kind, ['wifi', 'veip', 'tr069'], true)
            ? null : 'Layanan ONU tidak valid.',
        'onu-tr069-save' => $onuId > 0
            && ((string)($input['vlan'] ?? '') === '' || ctype_digit((string)$input['vlan']))
            && ctype_digit((string)($input['priority'] ?? '0'))
                ? null : 'VLAN atau prioritas TR069 tidak valid.',
        'onu-remote-access-save' => $onuId > 0 ? null : 'ONU tidak valid.',
        'onu-veip-save' => $onuId > 0 && $vlanValid ? null : 'Pengaturan VEIP tidak valid.',
        'onu-wan-ip-save' => $onuId > 0 && ctype_digit($index) ? null : 'Service WAN tidak valid.',
        'onu-data-service-save' => $onuId > 0 && ctype_digit($index) ? null : 'Service tidak valid.',
        'onu-ethernet-save' => $onuId > 0 && $port > 0 ? null : 'Port LAN tidak valid.',
        'onu-wifi-ssid-save' => $onuId > 0 && $port > 0 ? null : 'ONU atau port WiFi tidak valid.',
        'onu-wifi-config-save' => $onuId > 0 && $port > 0 && $vlanValid ? null : 'Pengaturan WiFi tidak valid.',
        'onu-unconfigured-refresh', 'start-olt-discovery', 'olt-view-refresh', 'olt-port-update' => $oltId > 0
            ? null : 'OLT tidak valid.',
        'onu-action' => $onuId > 0 && trim((string)($input['onu_action'] ?? '')) !== ''
            ? null : 'Aksi ONU tidak valid.',
        default => null,
    };
}

function live_traffic_result(int $userId, string $type, array $input): array
{
    unset($input['csrf']);
    try {
        $resource = in_array($type, ['pon-traffic', 'onu-traffic', 'device-traffic-history'], true)
            ? 'traffic.history.read' : 'live.traffic';
        $result = internal_api_request($userId, $resource, ['type' => $type, 'input' => $input], 60);
    } catch (Throwable $error) {
        error_log('[LINNMS] Live traffic API: ' . $error->getMessage());
        $result = ['ok' => false, 'code' => 'internal', 'data' => []];
    }
    $ok = !empty($result['ok']);
    if ($ok) return ['status' => 200, 'payload' => ['ok' => true]
        + (is_array($result['data'] ?? null) ? $result['data'] : [])];
    $code = (string)($result['code'] ?? 'internal');
    $message = match ($code) {
        'validation' => in_array($type, ['pon-traffic', 'pon-traffic-live'], true) ? 'OLT tidak valid.'
            : (in_array($type, ['onu-traffic', 'onu-traffic-live'], true) ? 'ONU tidak valid.' : 'Interface tidak valid.'),
        'not_found' => $type === 'device-traffic-history' ? 'Interface belum dicatat.' : 'Interface tidak ditemukan pada perangkat ini.',
        'unsupported' => in_array($type, ['pon-traffic', 'pon-traffic-live'], true)
            ? 'Trafik belum tersedia untuk perangkat ini.' : 'Trafik ONU belum tersedia untuk perangkat ini.',
        'forbidden' => 'Akun tidak aktif.',
        'device_unreachable' => 'Data trafik perangkat tidak dapat dibaca.',
        default => $type === 'device-traffic-record'
            ? 'Pilihan interface tidak dapat disimpan.' : 'Data trafik tidak dapat dimuat.',
    };
    $status = match ($code) {
        'validation' => 422,
        'not_found' => 404,
        'forbidden' => 403,
        'unsupported', 'conflict' => 409,
        'device_unreachable' => 502,
        default => 500,
    };
    return ['status' => $status, 'payload' => ['ok' => false, 'message' => $message]];
}

function live_traffic_input_error(string $type, array $input): ?string
{
    return match ($type) {
        'pon-traffic', 'pon-traffic-live' => (int)($input['olt_row_id'] ?? 0) > 0 ? null : 'OLT tidak valid.',
        'onu-traffic', 'onu-traffic-live' => (int)($input['onu_row_id'] ?? 0) > 0 ? null : 'ONU tidak valid.',
        'device-traffic-history' => (int)($input['device_id'] ?? 0) > 0 ? null : 'Perangkat tidak valid.',
        'device-traffic-record' => (int)($input['device_id'] ?? 0) > 0
            && trim((string)($input['if_ref'] ?? '')) !== '' ? null : 'Interface tidak valid.',
        default => null,
    };
}
