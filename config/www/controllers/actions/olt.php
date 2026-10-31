<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';

function handle_olt_action(string $action): bool
{
    $actions = ['approve_api_olt_first_scan', 'add_olt', 'sync_snmp_olt',
        'update_snmp_olt_port_description', 'save_olt_pon_description_local',
        'save_olt_card_label', 'refresh_olt', 'refresh_olt_view', 'scan_snmp_olt',
        'save_olt_pon_port_config', 'save_olt_pon_modal', 'save_olt_cli_connection',
        'test_olt_cli_connection', 'refresh_olt_cli_optical', 'refresh_onu_cli_optical',
        'save_olt_profile', 'delete_olt_profile', 'save_olt_vlan_port', 'save_olt_vlan_members',
        'save_zte_vlan', 'delete_zte_vlan'];
    if (!in_array($action, $actions, true)) return false;
    if ($action === 'add_olt') {
        $definition = monitoring_driver_definition(trim((string)($_POST['driver_id'] ?? '')));
        if (is_array($definition) && ($definition['handler'] ?? '') !== 'snmp_olt') return false;
    }
    if (!is_logged_in()) redirect('?page=login');
    if (in_array($action, ['approve_api_olt_first_scan', 'save_olt_card_label'], true)) require_admin_role();

    $rowId = valid_positive_id($_POST['olt_row_id'] ?? null) ?? 0;
    $onuRowId = valid_positive_id($_POST['onu_row_id'] ?? null) ?? 0;
    $pon = (int)($_POST['olt_port_number'] ?? 0);
    $portRef = trim((string)($_POST['olt_port_ref'] ?? ''));
    $kind = trim((string)($_POST['kind'] ?? ''));
    $destination = '?page=dashboard';
    if (in_array($action, ['sync_snmp_olt', 'update_snmp_olt_port_description',
        'save_olt_pon_description_local', 'save_olt_card_label', 'refresh_olt', 'refresh_olt_view',
        'save_olt_pon_port_config', 'save_olt_pon_modal', 'save_olt_cli_connection',
        'test_olt_cli_connection', 'refresh_olt_cli_optical', 'save_olt_profile',
        'delete_olt_profile', 'save_olt_vlan_port', 'save_olt_vlan_members',
        'save_zte_vlan', 'delete_zte_vlan'], true) && $rowId > 0) {
        $destination = '?page=view-olt&id=' . $rowId;
    }
    if (in_array($action, ['update_snmp_olt_port_description', 'save_olt_pon_description_local',
        'save_olt_pon_port_config'], true) && $rowId > 0 && $pon > 0) {
        $destination .= '&pon=' . $pon . '#pon-' . $pon;
    }
    if (in_array($action, ['refresh_olt', 'refresh_olt_view'], true) && $rowId > 0) $destination .= '#olt-pon-config';
    if ($action === 'save_olt_card_label' && $rowId > 0) $destination .= '#olt-pon-cards';
    if (in_array($action, ['save_olt_cli_connection', 'test_olt_cli_connection',
        'refresh_olt_cli_optical'], true) && $rowId > 0) $destination .= '#olt-cli-connection';
    if (in_array($action, ['save_olt_profile', 'delete_olt_profile', 'save_olt_vlan_port',
        'save_olt_vlan_members', 'save_zte_vlan', 'delete_zte_vlan'], true) && $rowId > 0) {
        $destination .= ($kind === 'vlan' || str_contains($action, 'vlan')) ? '#olt-panel-vlans' : '#olt-panel-onu-types';
    }
    if ($action === 'save_olt_pon_modal' && $rowId > 0 && $portRef !== '') {
        $destination .= '&pon_ref=' . rawurlencode($portRef) . '#pon-'
            . preg_replace('~[^a-zA-Z0-9]+~', '-', $portRef);
    }
    if ($action === 'refresh_onu_cli_optical') {
        $destination = $onuRowId > 0 ? '?page=view-onu&id=' . $onuRowId : '?page=onus';
    }

    $data = $_POST;
    unset($data['csrf'], $data['action']);
    try {
        $result = internal_api_request(current_user_id(), 'olt.action',
            ['action' => $action, 'data' => $data], 480);
    } catch (Throwable $error) {
        error_log('[LINNMS] OLT action API: ' . $error->getMessage());
        $result = ['ok' => false, 'code' => 'internal'];
    }
    $code = (string)linnms_action_reason($result, 'internal');
    $payload = is_array($result['data'] ?? null) ? $result['data'] : [];
    $ok = !empty($result['ok']);

    if ($action === 'add_olt') {
        if ($ok) {
            unset($_SESSION['old_olt']);
            flash('success', 'OLT berhasil ditambahkan.');
            redirect('?page=dashboard');
        }
        $old = $_POST;
        unset($old['csrf'], $old['password']);
        $_SESSION['old_olt'] = $old;
        $message = match ($code) {
            'name_required' => validation_required_message('Nama perangkat'),
            'ip_invalid' => validation_ip_message(),
            'community_required' => validation_required_message('Community'),
            'port_invalid' => validation_port_message(),
            'username_required' => validation_required_message('Username'),
            'password_required' => validation_required_message('Password'),
            'driver_invalid' => 'Driver OLT SNMP belum didukung.',
            'driver_disabled' => 'Driver OLT ini tidak diizinkan atau sedang dinonaktifkan.',
            'quota_total' => 'Batas OLT keseluruhan telah tercapai.',
            'quota_user' => 'Batas OLT akun telah tercapai.',
            default => linnms_action_code_message($result, 'OLT gagal ditambahkan.'),
        };
        flash('error', $message);
        redirect('?page=dashboard&add_device=1');
    }

    if ($action === 'approve_api_olt_first_scan') {
        flash($ok ? 'success' : 'error', $ok
            ? 'Scan pertama OLT ' . display_text($payload['name'] ?? null, (string)($payload['olt_id'] ?? ''))
                . ' milik @' . (string)($payload['username'] ?? '-') . ' telah disetujui.'
            : ($code === 'olt_invalid' ? 'Permintaan scan OLT tidak valid.'
                : 'Permintaan scan pertama tidak ditemukan atau sudah diproses.'));
        redirect('?page=dashboard');
    }

    if ($action === 'scan_snmp_olt') {
        $message = $code === 'queued' ? 'Fast, Middle, dan Low Sync OLT masuk antrean.'
            : ($code === 'running' ? 'Sinkronisasi OLT masih berjalan.' : 'Sinkronisasi OLT gagal dijalankan.');
        flash($ok ? 'success' : 'error', $message);
        redirect('?page=dashboard');
    }

    if ($action === 'save_olt_card_label') {
        $label = trim((string)($_POST['card_label'] ?? ''));
        $message = $ok ? ($label === '' ? 'Nama kartu dihapus.' : 'Nama kartu disimpan.')
            : 'Nama kartu gagal disimpan.';
        flash($ok ? 'success' : 'error', $message);
        redirect($destination);
    }

    if ($action === 'save_olt_cli_connection') {
        $message = $ok ? ($code === 'enabled' ? 'Telnet diaktifkan.' : 'Telnet dimatikan.')
            : 'Pengaturan Telnet gagal disimpan.';
        flash($ok ? 'success' : 'error', $message);
        redirect($destination);
    }

    if ($action === 'save_olt_pon_port_config') {
        $fields = olt_pon_port_config_fields();
        $fieldName = (string)($payload['field'] ?? '');
        $label = (string)($fields[$fieldName]['label'] ?? 'Konfigurasi');
        $number = (int)($payload['number'] ?? $pon);
        $message = match ($code) {
            'ok' => $label . ' PON ' . $number . ' tersimpan.',
            'unchanged' => $label . ' tidak berubah.',
            'config_empty' => 'Pilih nilai konfigurasi PON yang ingin disimpan.',
            'config_invalid' => 'Nilai konfigurasi PON tidak sesuai pilihan.',
            'unsupported' => 'Perubahan ' . $label . ' PON ' . $number . ' tidak dapat diterapkan untuk perangkat ini.',
            default => linnms_action_code_message($result, 'Perubahan ' . $label . ' PON ' . $number . ' gagal disimpan.'),
        };
        flash($ok ? ($code === 'unchanged' ? 'info' : 'success')
            : (in_array($code, ['config_empty', 'config_invalid', 'unsupported'], true) ? 'warning' : 'error'), $message);
        redirect($destination);
    }

    if ($action === 'save_olt_vlan_members') {
        $count = (int)($payload['applied'] ?? 0);
        $message = $ok ? ($code === 'unchanged' ? 'Keanggotaan VLAN tidak berubah.'
            : $count . ' perubahan keanggotaan VLAN berhasil diterapkan.')
            : ($code === 'partial' ? $count . ' perubahan diterapkan; sisanya gagal.'
                : 'Perubahan keanggotaan VLAN gagal diterapkan.');
        flash($ok ? 'success' : ($code === 'partial' ? 'warning' : 'error'), $message);
        redirect($destination);
    }

    $message = match ($action) {
        'sync_snmp_olt' => $ok ? 'Sinkronisasi OLT berhasil dijalankan.' : 'Sinkronisasi OLT gagal dijalankan.',
        'update_snmp_olt_port_description' => $ok ? 'Keterangan PON tersimpan.' : 'Keterangan PON gagal disimpan.',
        'save_olt_pon_description_local' => $ok
            ? (trim((string)($_POST['olt_port_description'] ?? '')) === ''
                ? 'Keterangan PON ' . $pon . ' dihapus.'
                : 'Keterangan PON ' . $pon . ' "' . trim((string)$_POST['olt_port_description']) . '" tersimpan.')
            : 'Keterangan PON gagal disimpan.',
        'refresh_olt' => $ok ? 'Data detail OLT diperbarui.' : 'Data detail OLT gagal diperbarui.',
        'refresh_olt_view' => $ok
            ? match ($code) {
                'partial' => 'Data OLT diperbarui sebagian.',
                'scanning' => 'Pemindaian OLT API sedang berjalan.',
                default => 'Data OLT diperbarui.',
            } : 'Data OLT gagal diperbarui.',
        'save_olt_pon_modal' => $ok
            ? (!empty($payload['changed']) ? 'Konfigurasi PON tersimpan.' : 'Tidak ada perubahan konfigurasi PON.')
            : ($code === 'partial' ? 'Konfigurasi PON tersimpan sebagian.' : 'Konfigurasi PON gagal disimpan.'),
        'test_olt_cli_connection' => $ok ? 'Uji koneksi CLI berhasil.'
            : 'Uji koneksi CLI gagal. ' . device_connection_message('telnet'),
        'refresh_olt_cli_optical' => $ok ? 'Pembacaan daya optik berhasil.' : 'Pembacaan daya optik gagal.',
        'refresh_onu_cli_optical' => $ok
            ? 'Daya optik ONU berhasil dibaca (RX ' . onu_rx_power_display($payload['rx_power'] ?? null)
                . ' / TX ' . format_power($payload['tx_power'] ?? null) . ').'
            : 'Daya optik ONU gagal dibaca.',
        'save_olt_profile' => $ok ? 'Profil berhasil disimpan.' : 'Profil gagal disimpan.',
        'delete_olt_profile' => $ok ? 'Profil berhasil dihapus.' : 'Profil gagal dihapus.',
        'save_olt_vlan_port' => $ok ? 'Konfigurasi VLAN berhasil diterapkan.' : 'Konfigurasi VLAN gagal diterapkan.',
        default => linnms_action_code_message($result, 'Aksi OLT belum tersedia.'),
    };
    if ($code === 'olt_invalid') $message = 'OLT tidak valid.';
    if ($code === 'olt_not_found') $message = 'OLT tidak ditemukan.';
    if ($code === 'driver_disabled') $message = 'Driver OLT ini tidak diizinkan atau sedang dinonaktifkan.';
    if ($code === 'pon_invalid') $message = 'Data PON tidak valid.';
    if ($code === 'onu_invalid') $message = 'ONU tidak valid.';
    if ($action === 'update_snmp_olt_port_description' && !$ok && $rowId > 0) {
        $destination = '?page=view-olt&id=' . $rowId;
    }
    flash($ok ? ($code === 'partial' ? 'warning' : ($action === 'save_olt_pon_modal' && empty($payload['changed']) ? 'info' : 'success'))
        : ($code === 'partial' ? 'warning' : 'error'), $message);
    redirect($destination);
}
