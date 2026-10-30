<?php

declare(strict_types=1);

final class OltTerminalActionService
{
    public function __construct(private LinnmsRepository $db, private CredentialCipher $cipher)
    {
    }

    public function execute(int $userId, array $input): array
    {
        $fail = static fn(string $message, int $status): array => [
            'payload' => ['ok' => false, 'message' => $message], 'status' => $status,
        ];
        $user = $this->db->getUser($userId);
        if (!is_array($user) || (int)($user['is_active'] ?? 0) !== 1) return $fail('Akun tidak aktif.', 403);
        $action = (string)($input['action'] ?? '');
        if (!in_array($action, ['open', 'exchange', 'close'], true)) return $fail('Aksi tidak valid.', 422);
        $rowId = filter_var($input['row_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $device = $rowId !== false && $rowId !== null ? $this->db->getEditableDevice($userId, (int)$rowId) : null;
        if (!is_array($device) || ($device['device_type'] ?? '') !== 'olt') return $fail('OLT tidak ditemukan.', 404);
        $token = (string)($input['token'] ?? '');
        $sessionHash = (string)($input['session_hash'] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $sessionHash) !== 1) return $fail('Sesi terminal tidak valid.', 422);
        $driverId = (string)device_driver_type($device);
        $definition = monitoring_driver_definition($driverId);
        if ($action !== 'close') {
            if (!olt_driver_support_enabled($this->db->getOltDriverSupportFlags(), $driverId)) {
                return $fail('Driver OLT ini tidak diizinkan atau sedang dinonaktifkan.', 403);
            }
            if (empty($definition['telnet_cli'])) return $fail('Terminal tidak tersedia', 422);
            if ((int)($device['hybrid_status'] ?? 0) !== 1) return $fail('Aktifkan Telnet terlebih dahulu.', 422);
        }
        $data = (string)($input['data'] ?? '');
        $decoded = base64_decode($data, true);
        if ($decoded === false || strlen($decoded) > 8192) return $fail('Input terminal terlalu panjang.', 422);
        $request = [
            'method' => 'terminal-' . $action,
            'key' => hash('sha256', implode('|', [$sessionHash, (string)$userId, (string)$rowId, $token])),
            'data' => $data,
            'cols' => (int)($input['cols'] ?? 100),
            'rows' => (int)($input['rows'] ?? 28),
        ];
        if ($action === 'open') {
            $username = trim((string)($device['username'] ?? ''));
            $encrypted = (string)($device['password_enc'] ?? '');
            try {
                $password = $encrypted !== '' ? $this->cipher->decrypt($encrypted) : '';
            } catch (Throwable) {
                return $fail('Password Telnet tidak dapat dibaca. Simpan kembali melalui Edit Perangkat.', 422);
            }
            if ($username === '' || $password === '') {
                return $fail('Isi Username dan Password Telnet di Edit Perangkat terlebih dahulu.', 422);
            }
            $request['olt'] = [
                'driver_id' => $driverId,
                'device_id' => $rowId,
                'user_id' => $userId,
                'host' => (string)$device['ip_address'],
                'port' => max(1, min(65535, (int)($device['ports'] ?? 23))),
                'username' => $username,
                'password' => $password,
            ];
        }
        set_time_limit(120);
        $result = OltTerminalBrokerClient::request($request);
        return ['payload' => $result, 'status' => !empty($result['ok']) ? 200 : 503];
    }
}
