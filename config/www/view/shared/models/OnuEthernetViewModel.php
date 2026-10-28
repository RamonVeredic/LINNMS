<?php
declare(strict_types=1);

final class OnuEthernetViewModel
{
    public static function port(array $port, string $uiFamily, array $vlanIds, bool $canWrite): array
    {
        $number = (int)($port['port'] ?? 0);
        $action = is_array($port['action'] ?? null) ? $port['action'] : [];
        $change = is_array($port['change'] ?? null) ? $port['change'] : [];
        $status = strtolower(trim((string)($action['interface_status'] ?? $port['enable'] ?? '')));
        $saveAction = 'onu-ethernet-save';
        $fields = [[
            'key' => 'enable', 'label' => 'Status interface', 'kind' => 'toggle',
            'value' => $status, 'action' => $canWrite ? $saveAction : '',
        ]];

        if ($uiFamily === 'hiosofamily') {
            $autoneg = strtolower(trim((string)($action['autoneg'] ?? '')));
            $fields[] = ['key' => 'autoneg', 'label' => 'AutoNeg', 'kind' => 'toggle', 'value' => $autoneg, 'action' => $canWrite ? $saveAction : ''];
            $fields[] = ['key' => 'speed', 'label' => 'Speed', 'kind' => 'text', 'value' => trim((string)($port['speed'] ?? ''))];
            $duplex = strtolower(trim((string)($port['duplex'] ?? '')));
            $link = strtolower(trim((string)($port['link_status'] ?? '')));
            if (!in_array($duplex, ['half', 'full'], true)) {
                if (in_array($link, ['down', 'offline', 'linkdown', 'disable'], true)) $duplex = 'half';
                if (in_array($link, ['up', 'online', 'linkup'], true)) $duplex = 'full';
            }
            $fields[] = [
                'key' => 'duplex', 'label' => 'Duplex', 'kind' => 'select',
                'value' => in_array($duplex, ['half', 'full'], true) ? $duplex : '',
                'options' => [['value' => 'half', 'label' => 'Half'], ['value' => 'full', 'label' => 'Full']],
                'action' => '', 'preview' => true,
            ];
            $flowctrl = strtolower(trim((string)($action['flowctrl'] ?? $port['flowctrl'] ?? '')));
            $fields[] = [
                'key' => 'flowctrl', 'label' => 'Flow Ctrl', 'kind' => 'toggle',
                'value' => in_array($flowctrl, ['enable', 'disable'], true) ? $flowctrl : 'disable',
                'action' => '', 'preview' => true,
            ];
            return ['port' => $number, 'link_status' => (string)($port['link_status'] ?? ''), 'interface_status' => $status, 'fields' => $fields];
        }

        $mode = strtolower(trim((string)($change['mode'] ?? $port['mode'] ?? '')));
        $vlan = trim((string)($change['vlan_mode'] ?? $port['vlan'] ?? ''));
        $access = '';
        $trunk = [];
        foreach (explode(',', $vlan) as $part) {
            if (preg_match('/^\s*(\d+)\s+(access|trunk)\s*$/i', $part, $match) !== 1) continue;
            if (strtolower($match[2]) === 'access') $access = $match[1];
            else $trunk[] = $match[1];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', array_merge($vlanIds, [$access], $trunk)), static fn(int $id): bool => $id >= 1 && $id <= 4094)));
        sort($ids, SORT_NUMERIC);
        $vlanOptions = array_map(static fn(int $id): array => ['value' => (string)$id, 'label' => (string)$id], $ids);
        $fields[] = [
            'key' => 'mode', 'label' => 'Mode', 'kind' => 'select', 'value' => $mode,
            'options' => [
                ['value' => 'lan', 'label' => 'LAN'], ['value' => 'access', 'label' => 'Access'],
                ['value' => 'trunk', 'label' => 'Trunk'], ['value' => 'hybrid', 'label' => 'Hybrid'],
            ], 'group' => 'settings',
        ];
        $fields[] = [
            'key' => 'default_vlan', 'label' => 'VLAN Access', 'kind' => 'select', 'value' => $access,
            'options' => $vlanOptions, 'group' => 'settings', 'modes' => ['access', 'hybrid'],
        ];
        $fields[] = [
            'key' => 'vlans', 'label' => 'VLAN Trunk', 'kind' => 'select', 'value' => $trunk,
            'options' => $vlanOptions, 'multiple' => true, 'group' => 'settings', 'modes' => ['trunk', 'hybrid'],
        ];
        $dhcp = strtolower(trim((string)($change['dhcp'] ?? $port['dhcp'] ?? '')));
        $fields[] = [
            'key' => 'dhcp', 'label' => 'DHCP Access', 'kind' => 'select', 'value' => $dhcp,
            'options' => [
                ['value' => 'no-ctrl', 'label' => 'Auto'], ['value' => 'from-internet', 'label' => 'From ISP'],
                ['value' => 'from-onu', 'label' => 'From ONU'], ['value' => 'forbidden', 'label' => 'Disabled'],
            ], 'group' => 'settings',
        ];
        return [
            'port' => $number, 'link_status' => (string)($port['link_status'] ?? ''),
            'interface_status' => $status,
            'fields' => $fields, 'save_action' => $canWrite ? $saveAction : '',
        ];
    }
}
