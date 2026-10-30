<?php

declare(strict_types=1);

function format_time_ago(?string $date): string
{
    if ($date === null || trim($date) === '') return '-';
    $ts = strtotime($date);
    if ($ts === false) return display_text($date);
    $diff = time() - $ts;
    if ($diff < 0) return date('d/m/Y H:i', $ts);
    if ($diff < 60) return 'Baru saja';
    if ($diff < 3600) return floor($diff / 60) . ' Menit lalu';
    if ($diff < 86400) return floor($diff / 3600) . ' Jam lalu';
    if ($diff < 604800) return floor($diff / 86400) . ' Hari lalu';
    return date('d/m/Y H:i', $ts);
}

function format_feed_time(mixed $value): string
{
    $createdAt = trim((string)($value ?? ''));
    if ($createdAt === '') return 'N/A';

    $ts = strtotime($createdAt);
    if ($ts === false) return $createdAt;

    $diff = max(0, time() - $ts);
    if ($diff <= 60) return 'Baru saja';
    if ($diff < 3600) return floor($diff / 60) . ' Menit yang lalu';
    if ($diff < 86400) return floor($diff / 3600) . ' Jam yang lalu';
    if ($diff < (30 * 86400)) return floor($diff / 86400) . ' Hari yang lalu';
    if ($diff < (60 * 86400)) return '1 Bulan yang lalu';
    return format_indonesian_date($ts);
}

function format_indonesian_date(int $timestamp): string
{
    $months = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];
    $month = $months[(int)date('n', $timestamp)] ?? '';
    return date('j', $timestamp) . ' ' . $month . ' ' . date('Y', $timestamp);
}

function format_indonesian_date_short(int $timestamp): string
{
    $months = [
        1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
        'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des',
    ];
    $month = $months[(int)date('n', $timestamp)] ?? '';
    return date('j', $timestamp) . ' ' . $month . ' ' . date('Y', $timestamp);
}


function format_sync_datetime(?string $date): string
{
    if ($date === null || trim($date) === '') return '-';
    $ts = strtotime($date);
    if ($ts === false) return display_text($date);
    return date('d/m/Y H:i:s', $ts);
}
