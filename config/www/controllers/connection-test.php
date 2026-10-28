<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';
require_once LINNMS_UI_ROOT . '/controllers/actions/action-result.php';

function connection_test_result(int $userId, array $input): array
{
    $type = is_scalar($input['type'] ?? null) ? trim((string)$input['type']) : '';
    return match ($type) {
        'device' => connection_test_device_result($userId, $input),
        'telegram' => connection_test_telegram_result($userId, $input),
        'whatsapp' => connection_test_whatsapp_result($userId, $input),
        default => ['status' => 422, 'payload' => ['ok' => false, 'message' => 'Jenis tes koneksi tidak dikenal.']],
    };
}

function connection_test_device_result(int $userId, array $input): array
{
    $params = [];
    foreach ([
        'driver_id',
        'device_row_id',
        'ip_address',
        'snmp_port',
        'community',
        'ports',
        'username',
        'password',
        'hybrid_status',
    ] as $field) {
        if (!array_key_exists($field, $input) || !is_scalar($input[$field])) continue;
        $params[$field] = (string)$input[$field];
    }

    try {
        $result = internal_api_request($userId, 'connection_test', ['type' => 'device', 'data' => $params], 35);
    } catch (Throwable $error) {
        error_log('[LINNMS] Tes koneksi web: ' . $error->getMessage());
        return [
            'status' => 502,
            'payload' => ['ok' => false, 'message' => 'Tes koneksi tidak dapat dijalankan.'],
        ];
    }

    $ok = !empty($result['ok']);
    $code = trim((string)($result['code'] ?? ''));
    $message = match ($code) {
        'ok' => 'Koneksi perangkat berhasil.',
        'validation' => 'Periksa kembali data perangkat yang diisi.',
        'not_found' => 'Perangkat tidak ditemukan.',
        'forbidden' => 'Akses perangkat tidak diizinkan.',
        'unsupported' => 'Tes koneksi belum tersedia untuk perangkat ini.',
        'device_unreachable' => 'Perangkat tidak dapat dijangkau. Periksa alamat, port, dan kredensialnya.',
        'device_auth_failed' => 'Autentikasi perangkat gagal. Periksa kredensialnya.',
        'device_rejected' => 'Perangkat menolak tes koneksi.',
        'conflict' => 'Perangkat sedang diproses. Coba lagi.',
        'internal' => 'Tes koneksi tidak dapat diproses.',
        default => $ok ? 'Koneksi perangkat berhasil.' : 'Tes koneksi gagal.',
    };
    $status = $ok ? 200 : match ($code) {
        'validation', 'unsupported' => 422,
        'not_found' => 404,
        'forbidden' => 403,
        'conflict' => 409,
        'device_unreachable', 'device_auth_failed', 'device_rejected' => 502,
        default => 500,
    };

    return [
        'status' => $status,
        'payload' => ['ok' => $ok, 'message' => $message],
    ];
}

function connection_test_request(int $userId, string $type, array $data): array
{
    try {
        return internal_api_request($userId, 'connection_test', ['type' => $type, 'data' => $data], 45);
    } catch (Throwable $error) {
        error_log('[LINNMS] Tes koneksi ' . $type . ': ' . $error->getMessage());
        return ['ok' => false, 'code' => 'internal'];
    }
}

function connection_test_reason(array $result): string
{
    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
    return (string)($data['reason'] ?? $result['code'] ?? 'internal');
}

function connection_test_telegram_result(int $userId, array $input): array
{
    $chat = trim((string)(is_scalar($input['telegram_chat_id'] ?? null) ? $input['telegram_chat_id'] : ''));
    $token = trim((string)(is_scalar($input['telegram_bot_token'] ?? null) ? $input['telegram_bot_token'] : ''));
    $result = $chat !== '' && preg_match('/^-?\d{1,24}$/', $chat) !== 1
        ? ['ok' => false, 'code' => 'chat_invalid']
        : connection_test_request($userId, 'telegram', [
            'telegram_bot_token' => $token,
            'telegram_chat_id' => $chat,
        ]);
    $code = connection_test_reason($result);
    $message = match ($code) {
        'ok' => 'Pesan berhasil terkirim',
        'unsupported' => 'Telegram belum tersedia',
        'token_required' => 'Token Bot wajib diisi',
        'chat_required' => 'Chat ID wajib diisi',
        'chat_invalid' => 'Chat ID harus berupa angka',
        'device_rejected' => 'Pesan gagal dikirim',
        default => linnms_action_code_message($result, 'Tes koneksi gagal'),
    };
    $status = match ($code) {
        'ok' => 200,
        'unsupported' => 403,
        'token_required', 'chat_required', 'chat_invalid' => 422,
        'device_rejected' => 502,
        default => 500,
    };
    return ['status' => $status, 'payload' => ['ok' => !empty($result['ok']), 'message' => $message]];
}

function connection_test_whatsapp_result(int $userId, array $input): array
{
    $data = [];
    foreach (['whatsapp_provider', 'whatsapp_target_type', 'whatsapp_token', 'whatsapp_device',
        'whatsapp_base_url', 'whatsapp_target', 'whatsapp_generator_session',
        'whatsapp_generator_secret'] as $key) {
        if (array_key_exists($key, $input) && is_scalar($input[$key])) {
            $data[$key] = trim((string)$input[$key]);
        }
    }
    $result = connection_test_request($userId, 'whatsapp', $data);
    $code = connection_test_reason($result);
    $message = match ($code) {
        'ok' => 'Pesan berhasil terkirim',
        'unsupported' => 'WhatsApp belum tersedia.',
        'missing_fields' => 'Lengkapi kolom wajib.',
        'group_invalid' => 'Group ID WhatsApp tidak valid.',
        'target_invalid' => 'Nomor tujuan tidak valid.',
        'device_rejected' => 'Pesan gagal dikirim',
        default => linnms_action_code_message($result, 'Tes WhatsApp mengalami kesalahan internal.'),
    };
    $status = match ($code) {
        'ok' => 200,
        'unsupported' => 403,
        'missing_fields', 'group_invalid', 'target_invalid' => 422,
        'device_rejected' => 502,
        default => 500,
    };
    return ['status' => $status, 'payload' => ['ok' => !empty($result['ok']), 'message' => $message]];
}
