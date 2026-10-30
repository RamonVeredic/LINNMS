<?php

declare(strict_types=1);

function normalize_phone_number(mixed $phone): string
{
    $digits = preg_replace('/\D+/', '', trim((string)$phone)) ?? '';
    if ($digits === '') return '';
    if (str_starts_with($digits, '0')) $digits = '62' . substr($digits, 1);
    if (str_starts_with($digits, '8')) $digits = '62' . $digits;

    return $digits;
}

function mask_phone_number(mixed $phone): string
{
    $digits = normalize_phone_number($phone);
    if (strlen($digits) < 8) return $digits;

    return substr($digits, 0, 5) . str_repeat('*', max(3, strlen($digits) - 8)) . substr($digits, -3);
}
