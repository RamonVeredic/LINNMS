<?php

declare(strict_types=1);

function api_olt_type_support_setting_key(string $oltType): string
{
    $safe = preg_replace('/[^A-Za-z0-9_]/', '_', trim($oltType)) ?? '';
    return 'olt_support_api_type_' . $safe;
}

function api_olt_type_support_enabled(array $flags, string $oltType): bool
{
    
    if (!device_access_allows('api_olt_type:' . trim($oltType))) return false;
    $key = api_olt_type_support_setting_key($oltType);
    return array_key_exists($key, $flags) && (string)$flags[$key] === '1';
}

function api_scan_is_running_or_recent(array $result): bool
{
    if (!empty($result['ok'])) return false;

    $body = $result['body'] ?? null;
    $code = strtolower(trim((string)(api_error_code($body) ?? '')));
    $parts = [
        trim((string)($result['error'] ?? '')),
        api_message($body),
    ];

    if (is_array($body)) {
        $error = $body['error'] ?? null;
        if (is_array($error) && isset($error['message']) && is_scalar($error['message'])) {
            $parts[] = trim((string)$error['message']);
        }
    }

    $text = strtolower(implode(' ', array_filter($parts)));

    if ($code !== '' && $code !== 'validation_error') return false;

    return (
        str_contains($text, 'scan is already running') ||
        str_contains($text, 'already running') ||
        str_contains($text, 'was run recently') ||
        str_contains($text, 'run recently') ||
        (
            str_contains($text, 'scan') &&
            str_contains($text, 'please wait')
        )
    );
}

function api_scan_state_message(array $result): string
{
    if (!empty($result['ok'])) {
        return 'Pemindaian OLT berhasil dimulai.';
    }

    if (api_scan_is_running_or_recent($result)) {
        return 'OLT sedang dipindai atau baru saja dipindai. Silakan tunggu beberapa saat lagi.';
    }

    return 'Pemindaian OLT gagal. Silakan coba kembali.';
}

function api_probe_result(array $result): array
{
    $status = (int)($result['status'] ?? 0);
    if ($status === 0) {
        return ['ok' => false, 'message' => 'Koneksi OLT External API tidak tersedia. Periksa koneksi jaringan.'];
    }
    if ($status === 401 || $status === 403) {
        return ['ok' => false, 'message' => 'Koneksi OLT External API ditolak. Periksa pengaturan layanan.'];
    }
    if ($status >= 500) {
        return ['ok' => false, 'message' => 'Koneksi OLT External API gagal. Server API tidak merespons.'];
    }
    return ['ok' => true, 'message' => 'Koneksi OLT External API aktif.'];
}
