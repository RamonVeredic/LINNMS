<?php

declare(strict_types=1);

function onu_list_filters_from_request(): array
{
    $status = strtolower(trim((string)($_GET['status'] ?? '')));
    if (!in_array($status, ['online', 'down', 'powerdown', 'loss', 'problem'], true)) $status = '';

    $oltRaw = $_GET['olt'] ?? null;
    $olt = function_exists('valid_nonzero_id')
        ? valid_nonzero_id($oltRaw)
        : ((is_numeric($oltRaw) && (int)$oltRaw > 0) ? (int)$oltRaw : null);

    $q = trim((string)($_GET['q'] ?? ''));
    $q = function_exists('mb_substr') ? mb_substr($q, 0, 100) : substr($q, 0, 100);

    $pon = trim((string)($_GET['pon'] ?? ''));
    $pon = function_exists('mb_substr') ? mb_substr($pon, 0, 120) : substr($pon, 0, 120);

    return [
        'olt' => $olt,
        'source' => function_exists('valid_olt_source') ? valid_olt_source($_GET['source'] ?? null) : null,
        'pon' => $pon,
        'status' => $status,
        'q' => $q,
    ];
}

function onu_list_filter_url(array $filters, int $page = 1): string
{
    $params = array_filter([
        'page' => 'onus',
        'olt' => isset($filters['olt']) && $filters['olt'] !== null ? (int)$filters['olt'] : null,
        'pon' => trim((string)($filters['pon'] ?? '')) !== '' ? (string)$filters['pon'] : null,
        'status' => trim((string)($filters['status'] ?? '')) !== '' ? (string)$filters['status'] : null,
        'q' => trim((string)($filters['q'] ?? '')) !== '' ? (string)$filters['q'] : null,
        'p' => $page > 1 ? $page : null,
    ], static fn($v): bool => $v !== null && $v !== '');
    return public_page_url('onus', $params);
}
