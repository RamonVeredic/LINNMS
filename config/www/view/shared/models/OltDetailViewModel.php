<?php

declare(strict_types=1);

final class OltDetailViewModel
{
    public static function text(mixed $value): string
    {
        $text = trim((string)($value ?? ''));
        return $text !== '' ? $text : '—';
    }

    public static function number(mixed $value, string $unit = ''): string
    {
        if (!is_numeric($value) || !is_finite((float)$value)) return '—';
        return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.') . $unit;
    }

    public static function temperatureText(mixed $value): string
    {
        $temperature = is_numeric($value) && is_finite((float)$value) ? (float)$value : 0.0;
        return number_format($temperature, 2, '.', '') . ' °C';
    }

    public static function status(mixed $value): array
    {
        $status = strtolower(trim((string)$value));
        return [$status, match ($status) {
            'online', 'up' => 'Online',
            'offline', 'down' => 'Offline',
            default => '—',
        }];
    }

    public static function identity(array $detail, array $system): array
    {
        [$status, $statusLabel] = self::status($detail['driver_status'] ?? '');
        $uptime = is_numeric($system['runtime_seconds'] ?? null) ? max(0, (int)$system['runtime_seconds']) : null;
        $model = trim((string)($detail['model'] ?? '')) !== '' ? $detail['model'] : ($system['model'] ?? null);
        return [
            'status' => $status,
            'status_label' => $statusLabel,
            'uptime' => $uptime,
            'model' => self::text($model),
            'temperature_text' => self::temperatureText($system['temperature'] ?? $system['environment_temperature'] ?? null),
        ];
    }

    public static function stats(array $cards, array $system, array $onuStats): array
    {
        $installedCards = array_values(array_filter($cards, static function (mixed $card): bool {
            return is_array($card) && (!array_key_exists('is_present', $card) || (int)$card['is_present'] === 1);
        }));
        $countRole = static function (string $role) use ($installedCards): int {
            return count(array_filter($installedCards, static fn(array $card): bool => ($card['role'] ?? '') === $role));
        };
        $uplinkCards = count(array_filter($installedCards, static function (array $card): bool {
            $role = (string)($card['role'] ?? '');
            if ($role === 'uplink') return true;
            return $role === 'control' && (int)($card['port_count'] ?? 0) > 0;
        }));
        return [
            'Card Uplink' => $uplinkCards,
            'Card PON' => $countRole('pon'),
            'Fan' => is_array($system['fans'] ?? null) && $system['fans'] !== [] ? count($system['fans']) : null,
            'Total ONU' => $onuStats['total'] ?? null,
            'Online' => $onuStats['online'] ?? null,
            'Unregist' => $onuStats['unregist'] ?? null,
            'Offline' => $onuStats['down'] ?? null,
            'DyingGasp' => $onuStats['power_down'] ?? null,
            'Lost' => $onuStats['loss'] ?? null,
            'Bermasalah' => $onuStats['problem'] ?? null,
        ];
    }

    public static function onuTypeManager(int $deviceId, array $capabilities, array $catalog, array $gponFields): array
    {
        $deviceSupported = !empty($capabilities['onu_type_device_supported']);
        $scope = $deviceSupported ? 'device' : 'local';
        $groups = ['gpon' => [], 'epon' => []];
        foreach ($catalog as $row) {
            if (!is_array($row) || ($row['kind'] ?? '') !== 'onu_type') continue;
            $data = is_array($row['data'] ?? null) ? $row['data'] : [];
            $name = trim((string)($row['name'] ?? $data['name'] ?? ''));
            $pon = strtolower(trim((string)($data['pon_type'] ?? '')));
            $data['pon_type'] = $pon;
            $data['name'] = $name;
            foreach ($gponFields as $key => $field) {
                $response = (string)($field['response'] ?? '');
                $data['gpon_values'][$key] = $data['fields'][$response] ?? null;
            }
            $data['speed'] = (string)($data['speed'] ?? $data['fields']['speed'] ?? '');
            $data['protect_type'] = (string)($data['protect_type'] ?? $data['fields']['protect type'] ?? '');
            foreach (['eth', 'wifi', 'pots'] as $kind) {
                $data['interface_values'][$kind] = implode(', ', array_map(
                    static fn(mixed $ref): string => str_starts_with((string)$ref, $kind . '_') ? substr((string)$ref, strlen($kind) + 1) : (string)$ref,
                    (array)($data['interfaces'][$kind] ?? [])
                ));
            }
            $groups[in_array($pon, ['gpon', 'epon'], true) ? $pon : 'lainnya'][] = [
                'name' => $name, 'data' => $data,
            ];
        }
        $displayFields = [];
        foreach ($gponFields as $key => $field) {
            $displayFields[$key] = [
                'label' => (string)($field['label'] ?? ''),
                'min' => (int)($field['min'] ?? 0),
                'max' => (int)($field['max'] ?? 0),
            ];
        }
        return ['device_id' => $deviceId, 'scope' => $scope, 'groups' => $groups, 'gpon_fields' => $displayFields];
    }

    public static function profiles(array $snapshots): array
    {
        $groups = ['vlan_profile' => [], 'traffic' => [], 'tcont' => [], 'ip' => []];
        $seenAt = '';
        foreach ($snapshots as $row) {
            if (!is_array($row)) continue;
            $kind = (string)($row['kind'] ?? '');
            if (!isset($groups[$kind])) continue;
            $groups[$kind][] = $row;
            $stamp = trim((string)($row['seen_at'] ?? ''));
            if ($stamp !== '' && $stamp > $seenAt) $seenAt = $stamp;
        }
        return ['groups' => $groups, 'seen_at' => $seenAt !== '' ? $seenAt : '—'];
    }

    public static function ponGroups(array $cards, array $snapshot, array $modules, array $optical): array
    {
        $normalize = static function (mixed $value): string {
            $ref = trim((string)$value);
            $ref = (string)preg_replace('~^(?:gpon|epon)(?:-olt)?[_/]?~i', '', $ref);
            return str_replace('_', '/', $ref);
        };
        $slotOf = static fn(string $ref): int => preg_match('~^\d+/(\d+)/\d+$~', $ref, $m) ? (int)$m[1] : 0;
        $groups = [];
        $roleBySlot = [];
        $slotByCardId = [];
        foreach ($cards as $card) {
            if (!is_array($card)) continue;
            $slot = (int)($card['slot_number'] ?? 0);
            $role = (string)($card['role'] ?? '');
            $roleBySlot[$slot] ??= $role;
            if ((int)($card['id'] ?? 0) > 0) $slotByCardId[(int)$card['id']] = $slot;
            if ($role === 'pon') $groups[$slot] = ['card' => $card, 'ports' => []];
        }

        $ports = [];
        foreach ($snapshot as $port) {
            if (!is_array($port)) continue;
            $ref = $normalize($port['port_ref'] ?? $port['pon'] ?? '');
            if ($ref !== '') $ports[$ref] = $port;
        }
        foreach ($modules as $module) {
            if (!is_array($module)) continue;
            $ref = $normalize($module['port_ref'] ?? '');
            if ($ref === '' || !isset($ports[$ref])) continue;
            foreach ($module as $key => $value) {
                if ($value !== null && $value !== '') $ports[$ref][$key] = $value;
            }
        }
        $olt_txPower = [];
        foreach ($optical as $port) {
            if (!is_array($port)) continue;
            $ref = $normalize($port['port_ref'] ?? $port['pon'] ?? '');
            if ($ref !== '') $olt_txPower[$ref] = $port['tx_power'] ?? null;
        }
        uksort($ports, 'strnatcmp');
        foreach ($ports as $ref => $port) {
            $cardId = (int)($port['card_id'] ?? 0);
            $slot = $cardId > 0 && isset($slotByCardId[$cardId])
                ? $slotByCardId[$cardId]
                : (array_key_exists('slot', $port) && is_numeric($port['slot'])
                    ? (int)$port['slot']
                    : $slotOf((string)$ref));
            $groups[$slot] ??= ['card' => [], 'ports' => []];
            $groups[$slot]['ports'][$ref] = $port;
        }

        $roleRank = static fn(string $role): int => $role === 'uplink' ? 0 : ($role === 'pon' ? 1 : 2);
        uksort($groups, static function ($left, $right) use ($roleBySlot, $roleRank): int {
            $rank = $roleRank($roleBySlot[$left] ?? '') <=> $roleRank($roleBySlot[$right] ?? '');
            return $rank !== 0 ? $rank : ((int)$left <=> (int)$right);
        });
        return ['groups' => $groups, 'tx' => $olt_txPower];
    }

    public static function ponPortStates(array $ports, array $card, bool $preferAdminState = true): array
    {
        $counts = ['Up' => 0, 'Down' => 0, 'Shutdown' => 0, 'NotSuported' => 0];
        $states = [];
        foreach ($ports as $ref => $port) {
            if (!is_array($port)) continue;
            $adminState = strtolower(trim((string)($preferAdminState ? ($port['admin_state'] ?? $port['enable'] ?? '') : ($port['enable'] ?? ''))));
            $linkStatus = strtolower(trim((string)($port['link_status'] ?? '')));
            $state = $adminState === 'disable' ? 'Shutdown'
                : match ($linkStatus) { 'linkup' => 'Up', 'linkdown' => 'Down', default => '—' };
            $states[$ref] = $state;
            $counts[$state]++;
        }
        $total = count($ports);
        $expected = is_numeric($card['port_count'] ?? null) && (int)$card['port_count'] > 0
            ? (int)$card['port_count'] : null;
        return [
            'counts' => $counts,
            'states' => $states,
            'classes' => array_map(static fn(string $state): string => match ($state) {
                'Up' => 'is-up',
                'Down' => 'is-down',
                'Shutdown' => 'is-shutdown',
                default => 'is-unknown',
            }, $states),
            'total' => $total,
            'expected' => $expected,
            'incomplete' => ($counts['_'] ?? 0) > 0 || $total === 0 || ($expected !== null && $expected > $total),
        ];
    }

    public static function trafficRows(array $groups, array $traffic, array $selected): array
    {
        $rows = [];
        $graphRows = [];
        foreach ($groups as $group) {
            $role = trim((string)($group['card']['role'] ?? ''));
            foreach ((array)($group['ports'] ?? []) as $ref => $port) {
                if (!is_array($port)) continue;
                $sample = is_array($traffic[$ref] ?? null) ? $traffic[$ref] : [];
                $row = [
                    'rx' => format_bps($sample['rx_bps'] ?? 0),
                    'tx' => format_bps($sample['tx_bps'] ?? 0),
                    'supported' => !empty($sample['supported']),
                    'label' => trim((string)($port['label'] ?? '')) !== '' ? trim((string)$port['label']) : (string)$ref,
                    'role' => $role,
                    'is_uplink' => $role === 'uplink',
                ];
                $rows[(string)$ref] = $row;
                $rawRef = trim((string)($port['port_ref'] ?? ''));
                if ($rawRef !== '' && isset($selected[$rawRef])) $graphRows[(string)$ref] = $row;
            }
        }
        return ['rows' => $rows, 'graph_rows' => $graphRows];
    }

    public static function otherPortState(string $enable, string $link): string
    {
        return $enable === 'Disable' ? 'Shutdown'
            : ($link === 'LinkUp' ? 'Up' : ($link === 'LinkDown' ? 'Down' : 'NotSuported'));
    }

    public static function stateClass(string $state): string
    {
        return match ($state) {
            'Up' => 'is-up',
            'Down' => 'is-down',
            'Shutdown' => 'is-shutdown',
            default => 'is-unknown',
        };
    }

    public static function otherCardPort(mixed $row): array
    {
        $name = is_array($row) ? trim((string)($row['name'] ?? '')) : trim((string)$row);
        $state = self::otherPortState(
            is_array($row) ? (string)($row['enable'] ?? '') : '',
            is_array($row) ? (string)($row['status'] ?? '') : ''
        );
        $number = is_array($row) && is_numeric($row['port_number'] ?? null)
            ? (int)$row['port_number']
            : (preg_match('~[/_.](\d+)$~', $name, $match) ? (int)$match[1] : null);
        $speed = '';
        $mbps = is_array($row) ? ($row['speed_mbps'] ?? null) : null;
        if (is_numeric($mbps) && (float)$mbps > 0) {
            $value = (float)$mbps;
            $speed = $value >= 1000
                ? rtrim(rtrim(number_format($value / 1000, 1, '.', ''), '0'), '.') . 'G'
                : (string)(int)$value . 'M';
        }
        return [
            'name' => $name,
            'state' => $state,
            'class' => self::stateClass($state),
            'number' => $number,
            'speed' => $speed,
            'enable' => is_array($row) ? (string)($row['enable'] ?? '') : '',
        ];
    }

    public static function otherCards(array $cards, array $uplinkBySlot = []): array
    {
        $rows = [];
        foreach ($cards as $card) {
            if (!is_array($card)) continue;
            $role = (string)($card['role'] ?? 'other');
            if ($role === 'pon' || $role === 'shelf') continue;
            $slot = (int)($card['slot_number'] ?? 0);
            $ports = json_decode((string)($card['ports_json'] ?? ''), true);
            $ports = is_array($ports) ? array_values($ports) : [];
            if ($ports === [] && isset($uplinkBySlot[$slot])) $ports = array_values((array)$uplinkBySlot[$slot]);
            $states = ['Up' => 0, 'Down' => 0, 'Shutdown' => 0, 'NotSuporte' => 0];
            foreach ($ports as $port) {
                $state = self::otherPortState(
                    is_array($port) ? (string)($port['enable'] ?? '') : '',
                    is_array($port) ? (string)($port['status'] ?? '') : ''
                );
                $states[$state]++;
            }
            $rows[] = [
                'card' => $card,
                'role' => $role,
                'slot' => $slot,
                'model' => trim((string)($card['model'] ?? '')),
                'label' => trim((string)($card['label'] ?? '')),
                'hardware' => trim((string)($card['hardware_version'] ?? '')),
                'software' => trim((string)($card['software_version'] ?? '')),
                'present' => !isset($card['is_present']) || !empty($card['is_present']),
                'ports' => $ports,
                'has_port_layout' => $ports !== [] || in_array($role, ['uplink', 'control'], true),
                'port_states' => $states,
            ];
        }
        return $rows;
    }

    public static function ponPortIdentity(array $port, string $ref): array
    {
        $ponRef = (string)($port['port_ref'] ?? $ref);
        $label = trim((string)($port['label'] ?? $port['pon'] ?? ''));
        if ($label === '') $label = $ponRef;
        $number = preg_match('~/(\d+)$~', $ref, $match) ? (int)$match[1] : 0;
        $key = (string)preg_replace('~[^a-zA-Z0-9]+~', '—', $ref);
        return [
            'ref' => $ponRef,
            'label' => $label,
            'number' => $number,
            'key' => $key,
            'modal_id' => 'olt-pon-modal-' . $key,
        ];
    }

    public static function ponPortConfigValues(array $port, bool $preferAdminState): array
    {
        $values = [];
        $values['olt_port_enable'] = (string)($preferAdminState
            ? ($port['admin_state'] ?? $port['enable'] ?? '')
            : ($port['enable'] ?? ''));
        foreach (['autoneg', 'speed', 'duplex', 'flow_control'] as $field) {
            $values['olt_port_' . $field] = (string)($port[$field] ?? '');
        }
        return $values;
    }

    public static function ponSwitchState(mixed $value): array
    {
        $state = strtolower(trim((string)($value ?? '')));
        $normalized = match ($state) {
            'enable' => 'Enable',
            'disable' => 'Disable',
            default => '',
        };
        return [
            'value' => $normalized,
            'label' => $normalized !== '' ? $normalized : 'N/A',
            'switch_label' => $normalized !== '' ? $normalized : 'Disable',
            'checked_attribute' => $normalized === 'Enable' ? ' checked' : '',
            'known' => $normalized !== '',
            'known_attribute' => $normalized !== '' ? '1' : '0',
        ];
    }
}
