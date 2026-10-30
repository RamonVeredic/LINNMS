<?php

declare(strict_types=1);

function validation_required_message(string $field): string
{
    return trim($field) . ' harus diisi.';
}

function validation_port_message(): string
{
    return 'Periksa port (1-65535), Community (SNMP), Port Telnet, Username, dan Password.';
}

function validation_ip_message(): string
{
    return 'Periksa IP Anda.';
}

function device_connection_message(string $kind): string
{
    return match (trim($kind)) {
        'snmp' => 'SNMP tidak merespons. Pastikan alamat IP, port SNMP, dan community sudah benar.',
        'telnet' => 'Telnet tidak merespons. Pastikan port Telnet, username, dan password sudah benar.',
        'api' => 'Koneksi OLT External API gagal. Periksa pengaturan layanan.',
        default => 'Perangkat tidak dapat dijangkau.',
    };
}

function validation_invalid_message(string $field): string
{
    return trim($field) . ' tidak valid.';
}

function registration_sanitize_display_name(mixed $name): string
{
    $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$name) ?? '';
    $clean = preg_replace('/\s{2,}/u', ' ', $clean) ?? '';

    return trim($clean);
}
