<?php

declare(strict_types=1);

function linnms_action_reason(array $result, string $default = 'internal'): string
{
    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
    return (string)($data['reason'] ?? $result['code'] ?? $default);
}

function linnms_action_code_message(array $result, string $fallback): string
{
    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
    if (isset($data['reason'])) return $fallback;
    return match ((string)($result['code'] ?? 'internal')) {
        'validation' => 'Periksa kembali data yang diisi.',
        'not_found' => 'Data tidak ditemukan.',
        'forbidden' => 'Akses tidak diizinkan.',
        'unsupported' => 'Fitur ini belum tersedia.',
        'device_unreachable' => 'Perangkat tidak dapat dijangkau.',
        'device_auth_failed' => 'Autentikasi perangkat gagal.',
        'device_rejected' => 'Perangkat menolak permintaan.',
        'conflict' => 'Data sedang diproses. Coba lagi.',
        'internal' => 'Permintaan tidak dapat diproses.',
        default => $fallback,
    };
}

function linnms_action_result(bool $success, string $message, string $redirectUrl, string $failureLevel = 'error'): void
{
    if (strtolower(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))) === 'xmlhttprequest') {
        http_response_code($success ? 200 : 422);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash($success ? 'success' : $failureLevel, $message);
    redirect($redirectUrl);
}
