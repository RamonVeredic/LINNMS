<?php

declare(strict_types=1);

function deployment_base_path(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    foreach (['/public/'] as $marker) {
        $pos = strpos($script, $marker);
        if ($pos !== false) return rtrim(substr($script, 0, $pos), '/');
    }
    if (preg_match('~^(.+)/(?:index|live)\.php$~i', $script, $match) === 1) {
        return rtrim((string)$match[1], '/');
    }
    return '';
}

function public_app_url(string $suffix = ''): string
{
    $base = deployment_base_path();
    $url = ($base !== '' ? $base : '') . '/';
    return $suffix !== '' ? $url . ltrim($suffix, '/') : $url;
}


function public_page_url(string $page, array $query = [], string $fragment = ''): string
{
    $page = strtolower(trim($page));
    $routeMap = [
        'login' => 'login', 'register' => 'register', 'dashboard' => 'dashboard',
        'settings' => 'settings', 'settings-admin' => 'settings-admin', 'add-device' => 'add-device', 'onus' => 'onus', 'add-onu' => 'add-onu',
        'alerts' => 'notification', 'notification' => 'notification', 'telegram' => 'telegram', 'whatsapp' => 'whatsapp',
        'applogs' => 'applogs',

        'logs' => 'applogs',
        'view-olt' => 'olt', 'edit-api-olt' => 'edit-olt', 'view-onu' => 'onu', 'view-mikrotik' => 'mikrotik',
        'view-mikrotik-interface' => 'mikrotik-interface', 'view-switch' => 'switch', 'edit-device' => 'edit-device',
    ];
    $route = $routeMap[$page] ?? 'dashboard';

    $resourceTypes = [
        'view-olt' => 'olt', 'edit-api-olt' => 'olt', 'view-onu' => 'onu', 'view-mikrotik' => 'mikrotik',
        'view-mikrotik-interface' => 'mikrotik', 'view-switch' => 'switch',
        
        
        'edit-device' => 'device',
    ];
    $rowId = isset($query['id']) && is_numeric($query['id']) ? (int)$query['id'] : 0;
    unset($query['id'], $query['page']);

    $url = public_app_url($route);
    if ($rowId > 0 && isset($resourceTypes[$page])) {
        $url .= '/' . public_resource_token($resourceTypes[$page], $rowId);
    }
    if ($query !== []) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $fragment = trim($fragment, "# \t\n\r\0\x0B");
    if ($fragment !== '') $url .= '#' . rawurlencode($fragment);
    return $url;
}

function public_settings_url(): string
{
    return public_page_url(is_admin() ? 'settings-admin' : 'settings');
}


function resolve_public_route(): array
{
    $path = rawurldecode((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/'));
    $base = deployment_base_path();
    if ($base !== '' && str_starts_with($path, $base . '/')) $path = substr($path, strlen($base));
    $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => $part !== ''));
    if ($parts === []) return ['valid' => true, 'page' => 'dashboard'];

    $simpleRoutes = [
        'login' => 'login', 'register' => 'register', 'dashboard' => 'dashboard',
        'settings' => 'settings', 'settings-admin' => 'settings-admin', 'add-device' => 'add-device', 'onus' => 'onus', 'add-onu' => 'add-onu',
        'alerts' => 'notification', 'notification' => 'notification', 'telegram' => 'telegram', 'whatsapp' => 'whatsapp',
        'applogs' => 'applogs',

        'logs' => 'applogs',
        'edit-device' => 'edit-device',
    ];
    if (count($parts) === 1 && isset($simpleRoutes[$parts[0]])) {
        return ['valid' => true, 'page' => $simpleRoutes[$parts[0]]];
    }

    $resourceRoutes = [
        'olt' => ['page' => 'view-olt', 'type' => 'olt', 'key' => 'id'],
        'edit-olt' => ['page' => 'edit-api-olt', 'type' => 'olt', 'key' => 'id'],
        'onu' => ['page' => 'view-onu', 'type' => 'onu', 'key' => 'id'],
        'mikrotik' => ['page' => 'view-mikrotik', 'type' => 'mikrotik', 'key' => 'id'],
        'mikrotik-interface' => ['page' => 'view-mikrotik-interface', 'type' => 'mikrotik', 'key' => 'id'],
        'switch' => ['page' => 'view-switch', 'type' => 'switch', 'key' => 'id'],
        'edit-device' => ['page' => 'edit-device', 'type' => 'device', 'key' => 'id'],
    ];
    if (count($parts) === 2 && isset($resourceRoutes[$parts[0]])) {
        $definition = $resourceRoutes[$parts[0]];
        if (!is_logged_in()) return ['valid' => true, 'page' => $definition['page']];
        $rowId = public_resource_id($parts[1], $definition['type']);
        if ($rowId !== null) {
            return ['valid' => true, 'page' => $definition['page'], $definition['key'] => $rowId];
        }
    }
    return ['valid' => false, 'page' => ''];
}

function asset_url(
    string $path,
    ?string $appVersion = null
): string {
    $normalized = str_replace('\\', '/', trim($path));
    $normalized = ltrim($normalized, '/');

    if (
        $normalized === '' ||
        !str_starts_with($normalized, 'assets/') ||
        str_contains($normalized, '..') ||
        preg_match('~^[a-z][a-z0-9+.-]*://~i', $normalized)
    ) {
        return $path;
    }

    $version = trim((string)$appVersion);
    $version = preg_replace(
        '/^v\.?/i',
        '',
        $version
    ) ?? $version;
    $version = preg_replace(
        '/[^0-9A-Za-z._-]/',
        '',
        $version
    ) ?? $version;

    $url = public_app_url($normalized);

    return $version !== ''
        ? $url . '?v=' . rawurlencode($version)
        : $url;
}

function action_return_page(
    mixed $value,
    string $fallback,
    array $allowedPages = []
): string {
    $candidate = trim((string)$value);

    $page = '';
    if (preg_match('/^\?page=([a-z0-9-]+)$/i', $candidate, $match) === 1) {
        $page = strtolower((string)$match[1]);
    } else {
        $path = (string)(parse_url($candidate, PHP_URL_PATH) ?? '');
        $page = strtolower((string)basename(rtrim($path, '/')));
    }
    if ($allowedPages !== [] && !in_array($page, $allowedPages, true)) {
        return $fallback;
    }
    return $page !== '' ? public_page_url($page) : $fallback;
}
