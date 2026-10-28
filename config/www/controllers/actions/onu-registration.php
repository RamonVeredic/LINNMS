<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';

function linnms_onu_registration_redirect(string $page, int $rowId = 0): never
{
    redirect(public_page_url($page, $rowId > 0 ? ['id' => $rowId] : []));
}

function handle_onu_registration_action(string $action): bool
{
    if (!in_array($action, ['register_onu', 'pre_register_onu', 'unregister_onu', 'replace_onu_registration'], true)) return false;
    if (!is_logged_in()) redirect('?page=login');
    $rowId = valid_positive_id($_POST['onu_row_id'] ?? null) ?? 0;
    if (in_array($action, ['register_onu', 'unregister_onu', 'replace_onu_registration'], true) && $rowId < 1) {
        flash('error', $action === 'replace_onu_registration' ? 'Serial pengganti tidak valid.' : 'ONU tidak valid.');
        linnms_onu_registration_redirect($action === 'register_onu' ? 'add-onu' : 'onus');
    }
    if (in_array($action, ['register_onu', 'pre_register_onu'], true)
        && in_array(trim((string)($_POST['onu_name'] ?? '')), ['', '-', '_', '—'], true)) {
        flash('error', 'Nama ONU wajib diisi.');
        linnms_onu_registration_redirect('add-onu');
    }
    if ($action === 'replace_onu_registration') {
        $replacementId = valid_positive_id($_POST['replacement_onu_row_id'] ?? null);
        if ($replacementId === null || $replacementId === $rowId) {
            flash('error', 'Serial pengganti tidak valid.');
            linnms_onu_registration_redirect('view-onu', $rowId);
        }
    }
    $data = $_POST;
    unset($data['action'], $data['csrf']);
    try {
        $result = internal_api_request(current_user_id(), 'onu.registration.action',
            ['action' => $action, 'data' => $data], 180);
    } catch (Throwable $error) {
        error_log('[LINNMS] ONU registration API: ' . $error->getMessage());
        $result = ['ok' => false, 'code' => 'internal'];
    }
    $code = (string)linnms_action_reason($result, 'internal');
    $resultData = is_array($result['data'] ?? null) ? $result['data'] : [];
    $targetRowId = (int)($resultData['row_id'] ?? $rowId);
    if (!empty($result['ok'])) {
        if ($action === 'unregister_onu') {
            flash($code === 'already_unregistered' ? 'info' : 'success',
                $code === 'already_unregistered' ? 'ONU sudah berstatus belum terdaftar.'
                    : 'Registrasi ONU dihapus. Serial kembali ke daftar ONU belum terdaftar.');
            linnms_onu_registration_redirect('add-onu');
        }
        if ($action === 'replace_onu_registration') {
            flash('success', 'Serial ONU berhasil diganti. Serial lama kembali menjadi belum terdaftar.');
        } else {
            if ($code === 'partial') flash('warning', 'Data detail ONU tidak lengkap setelah registrasi.');
            flash('success', 'ONU berhasil didaftarkan.');
        }
        linnms_onu_registration_redirect('view-onu', $targetRowId);
    }

    $number = (int)($resultData['number'] ?? 0);
    $message = match ($code) {
        'onu_invalid' => 'ONU tidak valid.',
        'name_required' => 'Nama ONU wajib diisi.',
        'manual_identity_invalid' => 'OLT, PON, atau serial pra-registrasi tidak valid.',
        'pon_not_found' => 'PON tujuan tidak ditemukan pada OLT.',
        'onu_not_found' => $action === 'replace_onu_registration'
            ? 'ONU lama atau ONU pengganti tidak ditemukan.' : 'ONU tidak ditemukan.',
        'already_registered' => 'ONU ini sudah tidak berada pada status belum terdaftar.',
        'serial_unavailable' => $action === 'replace_onu_registration'
            ? 'Serial pengganti belum tersedia dari hasil discovery.' : 'Serial ONU belum tersedia dari perangkat.',
        'olt_missing' => 'ONU ini tidak terhubung ke perangkat OLT mana pun.',
        'olt_identity_mismatch' => 'Aksi dikirim untuk perangkat yang berbeda dari ONU ini.',
        'driver_identity_mismatch' => 'Driver aksi tidak cocok dengan driver ONU ini.',
        'service_required' => 'Service ' . $number . ': VLAN, profil upload, dan profil download wajib dipilih.',
        'service_mode_invalid' => 'Service ' . $number . ': mode VLAN atau tipe layanan yang dipilih belum didukung driver ZTE.',
        'lan_ports_invalid' => 'Service ' . $number . ': daftar port LAN IPTV tidak valid.',
        'lan_port_number_invalid' => 'Service ' . $number . ': nomor port LAN IPTV tidak valid.',
        'outer_vlan_required' => 'Service ' . $number . ': VLAN layanan (outer) QinQ wajib dipilih.',
        'multiple_untag' => 'Hanya satu Service Untag dapat dipasang pada VEIP yang sama.',
        'service_index_invalid' => 'Index T-CONT/GEMPORT untuk Service aktif harus berada pada 1–32.',
        'duplicate_vlan' => 'VLAN antar-Service tidak boleh sama.',
        'wan_method_invalid' => 'Metode WAN tidak valid.',
        'wan_service_required' => 'WAN OMCi memerlukan Service aktif dan VLAN Profile WAN.',
        'wan_service_one_required' => 'Metode WAN pada bagian atas hanya berlaku untuk Service 1. Aktifkan Service 1 atau pilih Setup via ONT.',
        'pppoe_credentials_required' => 'Username dan password PPPoE wajib diisi.',
        'static_ip_invalid' => 'IP Profile, alamat IP, dan mask WAN Static wajib valid.',
        'service_wan_invalid' => 'Konfigurasi WAN Service ' . $number . ' tidak lengkap atau tidak valid.',
        'tr069_service_required' => 'TR069 memerlukan Service aktif.',
        'acs_required' => 'Alamat ACS diperlukan jika kredensial ACS diisi.',
        'acs_credentials_pair' => 'Username dan password ACS harus diisi berpasangan.',
        'batch_unsupported' => 'Driver perangkat belum mendukung penulisan konfigurasi Service yang dipilih',
        'service_activate_required' => 'Aktifkan Service dan pilih profil yang diperlukan.',
        'type_unsupported' => 'Driver belum mendukung perubahan type onu',
        'onu_id_unsupported' => 'Driver belum mendukung perubahan ONU ID',
        'manual_service_required' => 'Pra-registrasi memerlukan minimal satu Service.',
        'rename_unsupported' => 'Driver perangkat belum mendukung penulisan nama ONU.',
        'registration_unverified' => 'Konfigurasi service registrasi tidak terverifikasi.',
        'rename_failed' => 'Nama ONU gagal disimpan. Registrasi dibatalkan.',
        'profile_after_device' => 'ONU sudah terdaftar pada perangkat, tetapi profil aplikasi tidak tersimpan. Periksa detail ONU sebelum mengulang.',
        'profile_rolled_back' => 'Profil ONU gagal disimpan. Registrasi dikembalikan ke status belum terdaftar.',
        'unregister_failed' => 'Registrasi ONU gagal dihapus.',
        'replacement_invalid' => 'Serial pengganti tidak valid.',
        'olt_mismatch' => 'Serial pengganti harus berasal dari OLT yang sama.',
        'replacement_registered' => 'Serial pengganti sudah terdaftar. Pilih Serial yang masih belum terdaftar.',
        'replacement_register_failed' => 'Serial pengganti gagal didaftarkan.',
        'current_unregister_failed' => 'Serial lama gagal dilepas. Registrasi Serial pengganti dibatalkan.',
        'replacement_rename_failed' => 'Nama ONU pengganti gagal diterapkan. Pertukaran Serial dibatalkan.',
        'profile_transfer_failed' => 'Profil ONU gagal dipindahkan. Status registrasi dikembalikan.',
        default => linnms_action_code_message($result, 'Registrasi ONU gagal. Periksa log aplikasi.'),
    };
    flash(in_array($code, ['already_registered', 'replacement_registered'], true) ? 'warning' : 'error', $message);
    if ($action === 'unregister_onu') {
        linnms_onu_registration_redirect($code === 'onu_invalid' || $code === 'onu_not_found' ? 'onus' : 'view-onu', $targetRowId);
    }
    if ($action === 'replace_onu_registration') {
        linnms_onu_registration_redirect($targetRowId > 0 ? 'view-onu' : 'onus', $targetRowId);
    }
    if ($code === 'already_registered' || $code === 'profile_after_device') {
        linnms_onu_registration_redirect('view-onu', $targetRowId);
    }
    linnms_onu_registration_redirect('add-onu');
}
