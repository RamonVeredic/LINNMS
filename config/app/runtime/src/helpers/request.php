<?php

declare(strict_types=1);

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function raw_result_error(array $result, string $source = 'Operation'): string
{
    foreach (['error', 'diagnostic', 'message'] as $field) {
        $value = $result[$field] ?? null;
        if (is_scalar($value) && (string)$value !== '') return (string)$value;
    }
    $status = (int)($result['status'] ?? $result['http_status'] ?? 0);
    if ($status > 0) return 'HTTP ' . $status;
    return trim($source) . ' returned ok=false without an error message';
}

function public_resource_token(string $type, int $rowId): string
{
    global $credentialCipher;
    if (!$credentialCipher instanceof CredentialCipher || $rowId < 1 || current_user_id() < 1) {
        throw new RuntimeException('Rute sumber daya belum tersedia.');
    }
    $payload = json_encode(
        ['v' => 1, 'u' => current_user_id(), 't' => strtolower(trim($type)), 'i' => $rowId],
        JSON_UNESCAPED_SLASHES
    );
    if (!is_string($payload)) throw new RuntimeException('Rute sumber daya tidak dapat dibuat.');
    return rtrim(strtr($credentialCipher->encrypt($payload), '+/', '-_'), '=');
}

function public_resource_id(string $token, string $expectedType): ?int
{
    $resource = public_resource_identity($token);
    if ($resource === null || !hash_equals(strtolower(trim($expectedType)), $resource['type'])) return null;
    return $resource['id'];
}

function public_resource_identity(string $token): ?array
{
    global $credentialCipher;
    if (!$credentialCipher instanceof CredentialCipher || current_user_id() < 1) return null;
    $token = trim($token);
    if ($token === '' || preg_match('/^[A-Za-z0-9_-]{20,500}$/', $token) !== 1) return null;
    $encoded = strtr($token, '-_', '+/');
    $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
    try {
        $payload = json_decode($credentialCipher->decrypt($encoded), true, 8, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }
    if (!is_array($payload)
        || (int)($payload['v'] ?? 0) !== 1
        || (int)($payload['u'] ?? 0) !== current_user_id()
        || !is_string($payload['t'] ?? null)
        || trim($payload['t']) === ''
    ) {
        return null;
    }
    $rowId = (int)($payload['i'] ?? 0);
    return $rowId > 0 ? ['type' => strtolower(trim($payload['t'])), 'id' => $rowId] : null;
}

function unsupported_action_message(array $row, string $actionLabel, string $localEffect = ''): string
{
    $method = connection_method_label($row);
    $message = 'Metode koneksi ' . ($method !== '' ? $method : 'perangkat ini')
        . ' tidak mendukung ' . trim($actionLabel) . '.';
    if ($localEffect !== '') $message .= ' ' . trim($localEffect);
    return $message;
}

function olt_driver_id_from_input(array $input): string
{
    $driverId = trim((string)($input['driver_id'] ?? $input['type'] ?? ''));
    return olt_driver_definition($driverId) !== null ? $driverId : '';
}

function redirect(string $location): never
{
    if (str_starts_with($location, '?')) {
        [$target, $fragment] = array_pad(explode('#', $location, 2), 2, '');
        parse_str(ltrim($target, '?'), $query);
        $page = strtolower(trim((string)($query['page'] ?? 'dashboard')));
        unset($query['page']);
        $location = public_page_url($page, $query, $fragment);
    }
    header('Location: ' . $location);
    exit;
}

function flash(string $type, string $message): void
{
    if (in_array(strtolower($type), ['error', 'danger', 'critical'], true)
        && preg_match('/SQLSTATE|PDOException|Unknown column|(?:SQL|database) syntax|integrity constraint|foreign key constraint|duplicate entry/i', $message) === 1
    ) {
        $message = 'Terjadi kesalahan saat menyimpan data. Coba lagi.';
    }

    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function pull_flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($items) ? $items : [];
}

function is_logged_in(): bool
{
    return isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0;
}

function is_admin(): bool
{
    return current_role() === 'admin';
}

function safe_event_message(mixed $message): string
{
    $text = is_scalar($message) ? trim((string) $message) : '';
    if ($text === '') return 'Perubahan jaringan terdeteksi.';
    return str_ireplace(
        [
            'kembali muncul pada API',
            'tidak lagi muncul pada daftar API',
            'tidak lagi muncul pada respons API',
            'ip_address', 'olt_type', 'total_onus', 'onu_name', 'onu_type', 'onu_serial',
            'onu_state', 'rx_power', 'tx_power', 'temperature', 'distance', 'physical', 'is_present',
        ],
        [
            'kembali terdeteksi',
            'tidak lagi terdeteksi',
            'tidak lagi terdeteksi',
            'Alamat IP', 'Model', 'Total ONU', 'Nama ONU', 'Tipe ONU', 'Serial ONU',
            'Status ONU', 'Sinyal RX', 'Sinyal TX', 'Suhu', 'Jarak', 'Posisi', 'Keberadaan',
        ],
        $text,
    );
}

function extract_list(mixed $body): array
{
    if (!is_array($body)) return [];
    $data = $body['data'] ?? null;
    return is_array($data) && array_is_list($data) ? $data : [];
}

function api_message(mixed $body): string
{
    return is_array($body) && isset($body['message']) && is_scalar($body['message']) ? trim((string) $body['message']) : '';
}

function api_error_code(mixed $body): ?string
{
    if (!is_array($body) || !isset($body['error']) || !is_array($body['error'])) return null;
    return isset($body['error']['code']) && is_scalar($body['error']['code']) ? (string) $body['error']['code'] : null;
}

function api_connection_result_message(array $result): string
{
    if (!empty($result['ok'])) {
        $count = count(extract_list($result['body'] ?? null));
        return "Koneksi OLT External API aktif. Ditemukan {$count} OLT.";
    }

    $status = (int)($result['status'] ?? 0);
    if ($status === 401 || $status === 403) {
        return 'Koneksi OLT External API ditolak. Periksa pengaturan layanan.';
    }

    if ($status === 0) {
        return 'Koneksi OLT External API belum tersedia. Periksa koneksi jaringan.';
    }

    return 'Koneksi OLT External API gagal. Silakan coba kembali.';
}

function endpoint(array $config, string $name, array $params = []): string
{
    $template = (string) ($config['endpoints'][$name] ?? '');
    foreach ($params as $key => $value) {
        $template = str_replace('{' . $key . '}', (string) $value, $template);
    }
    return $template;
}

function valid_positive_id(mixed $value): ?int
{
    if (!is_scalar($value) || preg_match('/^\d+$/', (string) $value) !== 1) return null;
    $id = (int) $value;
    return $id > 0 ? $id : null;
}

function valid_nonzero_id(mixed $value): ?int
{
    if (!is_scalar($value) || preg_match('/^-?\d+$/', (string) $value) !== 1) return null;
    $id = (int) $value;
    return $id !== 0 ? $id : null;
}

function valid_olt_source(mixed $value): ?string
{
    if (!is_scalar($value)) return null;
    $source = (string) $value;
    return in_array($source, ['api', 'snmp'], true) ? $source : null;
}

function encode_onu_index(string $index): string
{
    $segments = explode('/', trim($index, '/'));
    return implode('/', array_map('rawurlencode', $segments));
}
