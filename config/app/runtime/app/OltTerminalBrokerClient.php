<?php

declare(strict_types=1);

final class OltTerminalBrokerClient
{
    public static function closeDevice(array $olt): bool
    {
        $deviceId = (int)($olt['id'] ?? 0);
        $userId = (int)($olt['user_id'] ?? 0);
        if ($deviceId < 1 || $userId < 1) return false;
        return !empty(self::request([
            'method' => 'close-device',
            'olt' => ['device_id' => $deviceId, 'user_id' => $userId],
        ])['ok']);
    }

    public static function request(array $request): array
    {
        $path = trim((string)(getenv('LINNMS_CLI_BROKER_SOCKET') ?: ''));
        if ($path === '') $path = lock_path('olt-cli-broker.sock');
        $socket = @stream_socket_client('unix://' . $path, $errno, $error, 2);
        if (!is_resource($socket)) return ['ok' => false, 'message' => 'Koneksi terminal belum tersedia. Coba lagi sebentar.'];
        try {
            stream_set_timeout($socket, 90);
            $payload = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            while ($payload !== '') {
                $written = @fwrite($socket, $payload);
                if (!is_int($written) || $written < 1) throw new RuntimeException('Koneksi terminal terputus.');
                $payload = substr($payload, $written);
            }
            $raw = @fgets($socket, 262145);
            $response = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($response)) throw new RuntimeException('Terminal tidak memberikan jawaban. Buka kembali terminal.');
            return $response;
        } catch (Throwable $error) {
            error_log('[terminal] ' . $error->getMessage());
            return ['ok' => false, 'message' => 'Koneksi terminal terputus. Buka kembali terminal.'];
        } finally {
            fclose($socket);
        }
    }
}
