<?php

declare(strict_types=1);

final class OnuRegistrationFeedback
{
    public static function decorate(array $response, string $action): array
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $code = (string)($data['reason'] ?? $response['code'] ?? 'internal');
        $notices = [];
        if (!empty($response['ok'])) {
            if ($action === 'unregister_onu') {
                $notices[] = [
                    'level' => $code === 'already_unregistered' ? 'info' : 'success',
                    'message' => $code === 'already_unregistered' ? 'ONU sudah berstatus belum terdaftar.'
                        : 'Registrasi ONU dihapus. Serial kembali ke daftar ONU belum terdaftar.',
                ];
            } elseif ($action === 'replace_onu_registration') {
                $notices[] = ['level' => 'success', 'message' => 'Serial ONU berhasil diganti. Serial lama kembali menjadi belum terdaftar.'];
            } else {
                if ($code === 'partial') $notices[] = ['level' => 'warning', 'message' => 'Data detail ONU tidak lengkap setelah registrasi.'];
                $notices[] = ['level' => 'success', 'message' => 'ONU berhasil didaftarkan.'];
            }
        } else {
            $number = (int)($data['number'] ?? 0);
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
            'forbidden' => 'Akses tidak diizinkan.',
            default => 'Registrasi ONU gagal. Periksa log aplikasi.',
            };
            $notices[] = [
                'level' => in_array($code, ['already_registered', 'replacement_registered'], true) ? 'warning' : 'error',
                'message' => $message,
            ];
        }
        $data['notices'] = $notices;
        $response['data'] = $data;
        return $response;
    }
}
