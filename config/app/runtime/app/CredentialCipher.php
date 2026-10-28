<?php

declare(strict_types=1);

final class CredentialCipher
{
    public function __construct(private readonly string $applicationKey)
    {
        if (!extension_loaded('openssl')) {
            throw new RuntimeException('Ekstensi OpenSSL diperlukan untuk menyimpan password OLT Web Config.');
        }
    }

    public function encrypt(string $plainText): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt(
            $plainText,
            'aes-256-gcm',
            hash('sha256', $this->applicationKey, true),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );
        if ($encrypted === false) throw new RuntimeException('Gagal mengenkripsi password');
        return base64_encode($iv . $tag . $encrypted);
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Format password OLT tidak valid.');
        $decrypted = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            hash('sha256', $this->applicationKey, true),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16),
        );
        if ($decrypted === false) throw new RuntimeException('Password OLT tidak di kenali');
        return $decrypted;
    }
}
