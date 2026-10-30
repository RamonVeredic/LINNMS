<?php

declare(strict_types=1);

function settings_admin_timing_fields(array $shell): array
{
    $limits = is_array($shell['numeric_setting_limits'] ?? null) ? $shell['numeric_setting_limits'] : [];
    $timing = is_array($shell['sync_timing_settings'] ?? null) ? $shell['sync_timing_settings'] : [];
    $definitions = [
        ['sync_fast_interval_minutes', 'fast_interval_minutes', 'settingFastInterval', 'Interval Fast Sync', 'menit', 'Jeda antar sinkronisasi cepat untuk memperbarui status perangkat dan ONU'],
        ['olt_graph_interval_minutes', 'olt_graph_interval_minutes', 'settingOltGraphInterval', 'Interval Sampel Trafik OLT', 'menit', 'Jeda pengambilan data trafik OLT untuk grafik'],
        ['sync_discovery_interval_minutes', 'discovery_interval_minutes', 'settingDiscoveryInterval', 'Interval Low Sync', 'jam', 'Jeda penemuan ulang perangkat dan ONU melalui sinkronisasi menyeluruh'],
        ['sync_middle_interval_minutes', 'middle_interval_minutes', 'settingMiddleInterval', 'Interval Middle Sync', 'jam', 'Jeda sinkronisasi tingkat menengah untuk memperbarui data yang tidak diambil Fast Sync'],
        ['sync_fast_deadline_seconds', 'fast_deadline_seconds', 'settingFastDeadline', 'Durasi Fast Sync', 'detik', 'Batas waktu pengerjaan satu Fast Sync sebelum dianggap melewati tenggat'],
        ['sync_discovery_deadline_seconds', 'discovery_deadline_seconds', 'settingDiscoveryDeadline', 'Batas Durasi Low Sync', 'detik', 'Batas waktu pengerjaan satu Low Sync sebelum dianggap melewati tenggat'],
        ['sync_middle_deadline_seconds', 'middle_deadline_seconds', 'settingMiddleDeadline', 'Batas Durasi Middle Sync', 'detik', 'Batas waktu pengerjaan satu Middle Sync sebelum dianggap melewati tenggat'],
        ['snmp_request_timeout_ms', 'snmp_request_timeout_ms', 'settingSnmpTimeout', 'Batas Waktu SNMP', 'ms', 'Lama maksimum menunggu jawaban untuk setiap permintaan SNMP'],
        ['onu_missing_scan_limit', 'onu_missing_scan_limit', 'settingOnuMissing', 'Batas Scan Onu', 'kali', 'Jumlah scan berturut-turut tanpa menemukan ONU sebelum ONU dianggap hilang'],
        ['reader_count', 'reader_count', 'settingReaderCount', 'Jumlah Reader', 'proses', 'Jumlah proses yang membaca perangkat dan mengumpulkan hasil sinkronisasi secara paralel'],
        ['fast_sync_onu_batch_size', 'fast_sync_onu_batch_size', 'settingFastOnuBatchSize', 'ONU per Batch Fast Sync', 'ONU', 'Jumlah maksimum ONU yang dibaca sebelum hasil dikirim ke writer dan reader memberi giliran ke user lain'],
        ['writer_count', 'writer_count', 'settingWriterCount', 'Jumlah Writer', 'proses', 'Jumlah proses yang menyimpan hasil sinkronisasi ke database secara paralel'],
    ];

    $fields = [];
    foreach ($definitions as [$key, $source, $id, $label, $unit, $help]) {
        $constraint = is_array($limits[$key] ?? null) ? $limits[$key] : null;
        if ($constraint === null) throw new RuntimeException('Batas pengaturan tidak tersedia: ' . $key);
        $valueSource = in_array($key, ['olt_graph_interval_minutes', 'onu_missing_scan_limit', 'reader_count', 'writer_count', 'fast_sync_onu_batch_size'], true) ? $shell : $timing;
        $value = (int)($valueSource[$source] ?? $constraint['default'] ?? 0);
        $min = (int)($constraint['min'] ?? 0);
        $max = (int)($constraint['max'] ?? 0);
        if ($unit === 'jam') {
            $value = max(1, (int)ceil($value / 60));
            $min = max(1, (int)ceil($min / 60));
            $max = max(1, intdiv($max, 60));
        }
        $fields[] = [
            'key' => $key,
            'id' => $id,
            'label' => $label,
            'value' => $value,
            'min' => $min,
            'max' => $max,
            'step' => $key === 'snmp_request_timeout_ms' ? 100 : 1,
            'unit' => $unit,
            'help' => $help,
        ];
    }
    return $fields;
}

function notification_page_view_data(array $shell, bool $telegramAllowed, bool $whatsappAllowed): array
{
    $settings = (array)($shell['user_settings'] ?? []);
    $telegramService = (array)($shell['telegram_service_settings'] ?? []);
    $whatsappUser = (array)($shell['whatsapp_user_settings'] ?? []);
    $whatsappService = (array)($shell['whatsapp_service_settings'] ?? []);
    $telegramAvailable = $telegramAllowed && (int)($telegramService['enabled'] ?? 0) === 1;
    $whatsappAvailable = $whatsappAllowed && (int)($whatsappService['enabled'] ?? 0) === 1;
    $limits = (array)($shell['notification_limits'] ?? []);
    $thresholds = [];
    foreach ([
        ['problem_threshold', 'notificationProblemThreshold', 'Batas RX Bermasalah'],
        ['signal_threshold_dbm', 'notificationSignalThreshold', 'Perubahan RX Power'],
    ] as [$key, $id, $label]) {
        $constraint = is_array($limits[$key] ?? null) ? $limits[$key] : null;
        if ($constraint === null) throw new RuntimeException('Batas notifikasi tidak tersedia: ' . $key);
        $thresholds[] = ['key' => $key, 'id' => $id, 'label' => $label,
            'value' => $constraint['value'], 'min' => $constraint['min'],
            'max' => $constraint['max'], 'unit' => 'dBm'];
    }
    $toggleLabels = [
        'notify_onu_signal' => 'RX Power', 'notify_onu_state' => 'Status ONU',
        'notify_device_status' => 'Device', 'notify_interface_changes' => 'Interface',
        'notify_pppoe_status' => 'PPPoE', 'notify_onu_problem' => 'Bermasalah',
        'notify_onu_details' => 'Aktivitas ONU',
    ];
    $toggles = [];
    foreach ($toggleLabels as $key => $label) {
        $fallback = match ($key) {
            'notify_interface_changes' => $settings['notify_device_status'] ?? 1,
            'notify_pppoe_status' => $settings['notify_interface_changes'] ?? $settings['notify_device_status'] ?? 1,
            default => 1,
        };
        $toggles[] = ['key' => $key, 'label' => $label,
            'enabled' => (int)($settings[$key] ?? $fallback) === 1];
    }
    $selectedIds = $shell['notification_selected_ids'] ?? null;
    return [
        'telegram_enabled' => (int)($settings['telegram_enabled'] ?? 0) === 1,
        'telegram_available' => $telegramAvailable,
        'telegram_allowed' => $telegramAllowed,
        'telegram_message' => !$telegramAllowed ? 'Tidak diizinkan.' : (!$telegramAvailable ? 'Belum tersedia.' : ''),
        'whatsapp_enabled' => (int)($whatsappUser['enabled'] ?? 0) === 1,
        'whatsapp_available' => $whatsappAvailable,
        'whatsapp_allowed' => $whatsappAllowed,
        'whatsapp_message' => !$whatsappAllowed ? 'Tidak diizinkan.' : (!$whatsappAvailable ? 'Belum tersedia.' : ''),
        'thresholds' => $thresholds,
        'selected_map' => $selectedIds === null ? null : array_fill_keys($selectedIds, true),
        'toggles' => $toggles,
    ];
}
