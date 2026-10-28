<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';

function linnms_whatsapp_action_request(int $uid, string $action, array $data): array
{
    try {
        return internal_api_request($uid, 'whatsapp.action', ['action' => $action, 'data' => $data], 45);
    } catch (Throwable $error) {
        error_log('[LINNMS] WhatsApp action API: ' . $error->getMessage());
        return ['ok' => false, 'code' => 'internal'];
    }
}

function linnms_whatsapp_action_input(): array
{
    $data = [];
    foreach (['whatsapp_provider', 'whatsapp_target_type', 'whatsapp_token', 'whatsapp_device',
        'whatsapp_base_url', 'whatsapp_target', 'whatsapp_generator_session',
        'whatsapp_generator_secret'] as $key) {
        if (array_key_exists($key, $_POST)) $data[$key] = trim((string)$_POST[$key]);
    }
    return $data;
}

function linnms_whatsapp_json(array $payload, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function handle_whatsapp_action(string $action): bool
{
    if (!in_array($action, ['save_whatsapp_connection', 'whatsapp_toggle_notifications',
        'whatsapp_disable', 'generate_whatsapp_token', 'list_whatsapp_groups'], true)) return false;
    if (!is_logged_in()) redirect('?page=login');
    $uid = current_user_id();

    if ($action === 'save_whatsapp_connection') {
        $returnPage = action_return_page($_POST['return_page'] ?? '', '?page=whatsapp',
            ['whatsapp', 'notification', 'telegram', 'alerts']);
        $data = linnms_whatsapp_action_input();
        if (($data['whatsapp_provider'] ?? '') === '') {
            flash('error', 'Gateway tidak dikenal.');
            redirect($returnPage);
        }
        $result = linnms_whatsapp_action_request($uid, $action, $data);
        $code = (string)linnms_action_reason($result, 'internal');
        if (!empty($result['ok'])) {
            $response = is_array($result['data'] ?? null) ? $result['data'] : [];
            $message = 'Koneksi WhatsApp (' . (string)($response['provider_label'] ?? '') . ') tersimpan'
                . ' Tekan "Tes Koneksi" untuk memastikan pengiriman berhasil.'
                . (!empty($response['previously_enabled']) ? '' : ' aktifkan notifikasi dari halaman Notifikasi');
            flash('success', $message);
        } elseif ($code === 'unsupported') flash('warning', 'WhatsApp belum tersedia');
        elseif ($code === 'provider_invalid') flash('error', 'Gateway WhatsApp tidak dikenal');
        elseif ($code === 'group_unsupported') flash('error', 'Gateway ini belum mendukung tujuan grup');
        elseif ($code === 'missing_fields') flash('error', 'Lengkapi kolom wajib.');
        elseif ($code === 'group_invalid') flash('error', 'Group ID WhatsApp tidak valid');
        elseif ($code === 'target_invalid') flash('error', 'Nomor tujuan tidak valid');
        else flash('error', 'Koneksi WhatsApp gagal disimpan');
        redirect($returnPage);
    }

    if ($action === 'whatsapp_toggle_notifications') {
        $enabled = (string)($_POST['whatsapp_enabled'] ?? '0') === '1';
        $result = linnms_whatsapp_action_request($uid, $action, ['whatsapp_enabled' => $enabled ? '1' : '0']);
        $unsupported = linnms_action_reason($result, '') === 'unsupported';
        $message = $unsupported ? 'WhatsApp belum tersedia' : (!empty($result['ok'])
            ? ($enabled ? 'Notifikasi WhatsApp diaktifkan'
                : 'Notifikasi WhatsApp dimatikan')
            : 'Notifikasi WhatsApp gagal diubah');
        linnms_action_result(!empty($result['ok']), $message, '?page=notif', $unsupported ? 'warning' : 'error');
    }

    if ($action === 'whatsapp_disable') {
        $result = linnms_whatsapp_action_request($uid, $action, []);
        flash(!empty($result['ok']) ? 'success' : 'error', !empty($result['ok'])
            ? 'Berhasil menghapus koneksi whatsapp'
            : 'Gagal menghapus koneksi whatsapp');
        redirect('?page=whatsapp');
    }

    if ($action === 'generate_whatsapp_token') {
        $data = linnms_whatsapp_action_input();
        $result = linnms_whatsapp_action_request($uid, $action, $data);
        $code = (string)linnms_action_reason($result, 'internal');
        $message = match ($code) {
            'ok' => 'Token session berhasil dibuat',
            'unsupported' => 'WhatsApp belum tersedia',
            'device_rejected' => 'Token gagal dibuat. Periksa URL server dan secret key',
            default => linnms_action_code_message($result, 'Token gagal dibuat'),
        };
        linnms_whatsapp_json([
            'ok' => !empty($result['ok']),
            'message' => $message,
            'token' => !empty($result['ok']) ? (string)($result['data']['token'] ?? '') : '',
        ], $code === 'ok' ? 200 : ($code === 'unsupported' ? 403 : ($code === 'device_rejected' ? 422 : 500)));
    }

    if ($action === 'list_whatsapp_groups') {
        $result = linnms_whatsapp_action_request($uid, $action, linnms_whatsapp_action_input());
        $code = (string)linnms_action_reason($result, 'internal');
        $rawGroups = !empty($result['ok']) && is_array($result['data']['groups'] ?? null)
            ? array_values($result['data']['groups']) : [];
        $groups = [];
        foreach ($rawGroups as $group) {
            if (!is_array($group)) continue;
            $id = trim((string)($group['id'] ?? ''));
            if ($id === '') continue;
            $name = trim((string)($group['name'] ?? ''));
            $groups[] = ['id' => $id, 'label' => $name !== '' ? $name . ' (' . $id . ')' : $id];
        }
        linnms_whatsapp_json([
            'ok' => !empty($result['ok']),
            'message' => $code === 'ok' ? ($groups === [] ? 'Grup tidak ditemukan pada gateway'
                : (count($groups) === 1 ? 'Satu grup ditemukan'
                    : count($groups) . ' grup ditemukan'))
                : ($code === 'unsupported' ? 'WhatsApp belum tersedia'
                    : 'Gagal meminta daftar grup. Pilih opsi Group ID manual atau coba lagi'),
            'groups' => $groups,
        ], $code === 'unsupported' ? 403 : ($code === 'internal' ? 500 : 200));
    }

    return false;
}
