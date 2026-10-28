<?php

declare(strict_types=1);

final class InternalApiAuthException extends RuntimeException
{
}

final class InternalApiAuth
{
    private const KEY_PATH = '/config/app/data/core/internal.key';

    public static function verify(string $rawBody): int
    {
        $remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (!in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
            throw new InternalApiAuthException('Akses API internal ditolak.');
        }

        $key = trim((string)@file_get_contents(self::KEY_PATH));
        if (strlen($key) < 32) {
            throw new RuntimeException('Kunci API internal belum tersedia.');
        }

        $rawUserId = trim((string)($_SERVER['HTTP_X_LINNMS_USER'] ?? ''));
        $userId = (int)$rawUserId;
        $expiresAt = (int)($_SERVER['HTTP_X_LINNMS_EXPIRES'] ?? 0);
        $signature = trim((string)($_SERVER['HTTP_X_LINNMS_SIGNATURE'] ?? ''));
        $now = time();

        if (preg_match('/^(0|[1-9][0-9]*)$/', $rawUserId) !== 1
            || $expiresAt < $now || $expiresAt > $now + 120 || $signature === '') {
            throw new InternalApiAuthException('Tanda tangan API internal tidak valid.');
        }

        $expected = hash_hmac(
            'sha256',
            $userId . "\n" . $expiresAt . "\n" . hash('sha256', $rawBody),
            $key
        );
        if (!hash_equals($expected, $signature)) {
            throw new InternalApiAuthException('Tanda tangan API internal tidak cocok.');
        }

        return $userId;
    }
}
