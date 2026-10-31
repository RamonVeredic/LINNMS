<?php

declare(strict_types=1);

/** Nilai bawaan dan batas yang dipakai bersama oleh aplikasi web dan worker. */
final class LinnmsDefaults
{
    public const APP_IDENTITY = ['name' => 'LINNMS', 'version' => '1.0.0'];

    // Jeda pembaruan akses dalam menit.
    public const Q_REFRESH_MINUTES = 1440;

    // Nama header untuk kunci akses API.
    public const AUTH_HEADER = 'X-API-Key';
    // Batas waktu permintaan API eksternal dalam detik.
    public const API_TIMEOUT_SECONDS = 30;
    // Menentukan apakah sertifikat TLS API eksternal diverifikasi.
    public const API_VERIFY_SSL = true;
    // Nilai pengganti ketika kunci enkripsi kredensial belum tersedia.
    public const CREDENTIAL_KEY_FALLBACK = 'Expired api key';
    // Data akun admin awal saat database baru dibuat.
    public const DEFAULT_ADMIN = [
        // Nama pengguna admin awal; kosong berarti belum ditentukan.
        'username' => '',
        // Kata sandi admin awal; kosong berarti belum ditentukan.
        'password' => '',
        // Nama tampilan admin awal; kosong berarti belum ditentukan.
        'display_name' => '',
    ];
    // Jalur API eksternal yang digunakan untuk membaca OLT dan ONU serta mengirim aksi.
    public const API_ENDPOINTS = [
        // Jalur untuk mengambil daftar OLT.
        'olts' => '/api/v1/olts',
        // Jalur untuk mengambil satu OLT.
        'olt' => '/api/v1/olts/{id}',
        // Jalur untuk meminta pemindaian OLT.
        'olt_scan' => '/api/v1/olts/{id}/scan',
        // Jalur untuk mengambil daftar ONU pada satu OLT.
        'olt_onus' => '/api/v1/olts/{id}/onus',
        // Jalur untuk mengambil satu ONU.
        'onu' => '/api/v1/olts/{id}/onus/{onu_index}',
        // Jalur untuk memulai ulang satu ONU.
        'onu_reboot' => '/api/v1/olts/{id}/onus/{onu_index}/reboot',
    ];
    // Ambang perubahan daya optik yang dianggap perlu diproses, dalam dBm.
    public const SIGNAL_CHANGE_THRESHOLD_DBM = 1.0;
    // Ukuran maksimum potongan pesan Telegram saat dikirim.
    public const TELEGRAM_CHUNK_SIZE = 3500;
    // Batas waktu pembacaan perangkat SNMP dalam mikrodetik.
    public const SNMP_DEVICE_TIMEOUT_US = 500000;
    // Jumlah percobaan ulang pembacaan perangkat SNMP.
    public const SNMP_DEVICE_RETRIES = 0;

    // Lama cache identitas aplikasi dalam detik.
    public const APP_IDENTITY_CACHE_TTL = 300;
    // Ambang perubahan daya pancar PON yang dicatat, dalam dBm.
    public const OLT_PON_TX_POWER_THRESHOLD_DBM = 1.0;
    // Batas waktu menunggu proses mulai ulang ONU dalam detik.
    public const ONU_REBOOT_TIMEOUT_SECONDS = 300;
    // Jumlah kegagalan login sebelum penundaan diterapkan.
    public const LOGIN_FAILURE_LIMIT = 5;
    // Rentang waktu penghitung kegagalan login dalam menit.
    public const LOGIN_WINDOW_MINUTES = 10;
    // Penundaan login maksimum setelah kegagalan berulang, dalam detik.
    public const LOGIN_MAX_BACKOFF_SECONDS = 60;
    // Kuota OLT bawaan untuk pengguna.
    public const DEFAULT_OLT_LIMIT = 1;
    // Kuota MikroTik bawaan untuk pengguna.
    public const DEFAULT_MIKROTIK_LIMIT = 1;
    // Kuota switch bawaan untuk pengguna.
    public const DEFAULT_SWITCH_LIMIT = 1;
    // Lama ONU harus offline sebelum ditandai bermasalah, dalam jam.
    public const ONU_PROBLEM_AFTER_HOURS = 6;
    // Ambang deteksi sinyal RX ONU bawaan, dalam dBm.
    public const ONU_RX_DETECTION_DEFAULT_DBM = 1.0;
    // Nilai minimum yang diizinkan untuk ambang deteksi sinyal RX ONU, dalam dBm.
    public const ONU_RX_DETECTION_MIN_DBM = 0.1;
    // Nilai maksimum yang diizinkan untuk ambang deteksi sinyal RX ONU, dalam dBm.
    public const ONU_RX_DETECTION_MAX_DBM = 20.0;
    // Ambang sinyal RX ONU bermasalah bawaan, dalam dBm.
    public const ONU_RX_PROBLEM_DEFAULT_DBM = -30.0;
    // Nilai minimum yang diizinkan untuk ambang masalah sinyal RX ONU, dalam dBm.
    public const ONU_RX_PROBLEM_MIN_DBM = -60.0;
    // Nilai maksimum yang diizinkan untuk ambang masalah sinyal RX ONU, dalam dBm.
    public const ONU_RX_PROBLEM_MAX_DBM = -1.0;
    // Panjang kode OTP WhatsApp.
    public const WHATSAPP_OTP_LENGTH = 4;
    // Masa berlaku kode OTP WhatsApp dalam detik.
    public const WHATSAPP_OTP_TTL_SECONDS = 300;
    // Panjang minimum kata sandi pendaftaran.
    public const REGISTRATION_MIN_PASSWORD_LENGTH = 8;
    // Waktu minimum pengisian formulir pendaftaran dalam detik.
    public const REGISTRATION_MIN_FILL_SECONDS = 2;
    // Umur maksimum formulir pendaftaran dalam detik.
    public const REGISTRATION_FORM_MAX_AGE_SECONDS = 1800;
    // Jumlah maksimum percobaan pendaftaran dalam satu rentang waktu.
    public const REGISTRATION_THROTTLE_LIMIT = 10;
    // Rentang waktu pembatasan percobaan pendaftaran dalam menit.
    public const REGISTRATION_THROTTLE_WINDOW_MINUTES = 60;
    // Jumlah pemindaian tanpa ONU sebelum dianggap hilang secara bawaan.
    public const DEFAULT_ONU_MISSING_SCAN_LIMIT = 5;
    // Jumlah minimum pemindaian tanpa ONU yang boleh diatur.
    public const MIN_ONU_MISSING_SCAN_LIMIT = 1;
    // Jumlah maksimum pemindaian tanpa ONU yang boleh diatur.
    public const MAX_ONU_MISSING_SCAN_LIMIT = 10;

    // Jeda Fast Sync bawaan dalam menit; pengaturan pengguna dapat menimpanya.
    public const DEFAULT_SYNC_FAST_INTERVAL_MINUTES = 3;
    // Jeda Fast Sync minimum yang boleh diatur, dalam menit.
    public const MIN_SYNC_FAST_INTERVAL_MINUTES = 0;
    // Jeda Fast Sync maksimum yang boleh diatur, dalam menit.
    public const MAX_SYNC_FAST_INTERVAL_MINUTES = 1440;
    // Jeda penyimpanan sampel grafik OLT bawaan dalam menit.
    public const DEFAULT_OLT_GRAPH_INTERVAL_MINUTES = 3;
    // Jumlah titik sampel grafik yang diambil dalam satu tampilan.
    public const GRAPH_SAMPLE_LIMIT = 12;
    // Jeda penyimpanan sampel grafik OLT minimum dalam menit.
    public const MIN_OLT_GRAPH_INTERVAL_MINUTES = 1;
    // Jeda penyimpanan sampel grafik OLT maksimum dalam menit.
    public const MAX_OLT_GRAPH_INTERVAL_MINUTES = 1440;
    // Jeda Low Sync atau penemuan perangkat bawaan dalam menit.
    public const DEFAULT_SYNC_DISCOVERY_INTERVAL_MINUTES = 1440;
    // Jeda Low Sync minimum yang boleh diatur, dalam menit.
    public const MIN_SYNC_DISCOVERY_INTERVAL_MINUTES = 60;
    // Jeda Low Sync maksimum yang boleh diatur, dalam menit.
    public const MAX_SYNC_DISCOVERY_INTERVAL_MINUTES = 10080;
    // Jeda Middle Sync bawaan dalam menit.
    public const DEFAULT_SYNC_MIDDLE_INTERVAL_MINUTES = 360;
    // Jeda Middle Sync minimum yang boleh diatur, dalam menit.
    public const MIN_SYNC_MIDDLE_INTERVAL_MINUTES = 60;
    // Jeda Middle Sync maksimum yang boleh diatur, dalam menit.
    public const MAX_SYNC_MIDDLE_INTERVAL_MINUTES = 10080;
    // Batas durasi satu Fast Sync bawaan dalam detik.
    public const DEFAULT_SYNC_FAST_DEADLINE_SECONDS = 250;
    // Batas durasi satu Low Sync bawaan dalam detik.
    public const DEFAULT_SYNC_DISCOVERY_DEADLINE_SECONDS = 250;
    // Batas durasi satu Middle Sync bawaan dalam detik.
    public const DEFAULT_SYNC_MIDDLE_DEADLINE_SECONDS = 250;
    // Batas durasi sync minimum yang boleh diatur, dalam detik.
    public const MIN_SYNC_DEADLINE_SECONDS = 30;
    // Batas durasi sync maksimum yang boleh diatur, dalam detik.
    public const MAX_SYNC_DEADLINE_SECONDS = 3600;
    // Batas waktu permintaan SNMP bawaan dalam milidetik; nol memakai perilaku bawaan driver.
    public const DEFAULT_SNMP_REQUEST_TIMEOUT_MS = 0;
    // Batas waktu permintaan SNMP maksimum dalam milidetik.
    public const MAX_SNMP_REQUEST_TIMEOUT_MS = 10000;
    // Jumlah maksimum riwayat notifikasi yang disimpan.
    public const NOTIFICATION_HISTORY_LIMIT = 300;
    // Jumlah baris riwayat notifikasi per halaman.
    public const NOTIFICATION_HISTORY_PAGE_SIZE = 50;
    // Jumlah baris log aplikasi per halaman.
    public const APPLICATION_LOG_PAGE_SIZE = 50;
    // Jumlah maksimum aktivitas yang dimuat.
    public const ACTIVITY_FEED_LIMIT = 2000;
    // Jumlah aktivitas per halaman.
    public const ACTIVITY_FEED_PAGE_SIZE = 50;

    // Masa aktif permintaan trafik PON CLI dalam detik.
    public const OLT_CLI_TRAFFIC_DEMAND_SECONDS = 15;
    // Umur maksimum cache trafik PON CLI dalam detik.
    public const OLT_CLI_TRAFFIC_PRUNE_SECONDS = 30;
    // Jumlah maksimum titik trafik ONU live yang disimpan sementara.
    public const ONU_TRAFFIC_LIVE_MAX_POINTS = 12;
    // Masa aktif permintaan trafik ONU live dalam detik.
    public const ONU_TRAFFIC_LIVE_LEASE_SECONDS = 15;
    // Lama data trafik ONU live yang tidak aktif dipertahankan dalam detik.
    public const ONU_TRAFFIC_LIVE_RETAIN_SECONDS = 3600;
}
