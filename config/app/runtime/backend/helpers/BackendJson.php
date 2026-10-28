<?php

declare(strict_types=1);

final class BackendJson
{
    public static function decode(string $raw): array
    {
        if (trim($raw) === '') throw new InvalidArgumentException('Body JSON kosong.');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) throw new InvalidArgumentException('Body JSON tidak valid.');
        return $decoded;
    }

    public static function encode(array $payload): string
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if (!is_string($json)) throw new RuntimeException('Respons JSON gagal dibuat.');
        return $json;
    }

    public static function send(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo self::encode($payload);
        exit;
    }

    public static function ok(mixed $data, array $meta = []): never
    {
        self::send(['ok' => true, 'data' => $data, 'meta' => $meta]);
    }

    public static function error(string $code, string $message, int $status = 400): never
    {
        self::send([
            'ok' => false,
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }
}
