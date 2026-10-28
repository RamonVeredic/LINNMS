<?php

declare(strict_types=1);

function linnms_log_original_error(
    LinnmsRepository $db,
    Throwable $error,
    string $action = 'application_error',
    array $context = []
): void {
    static $writing = false;
    static $fingerprints = [];

    $fingerprint = get_class($error) . '|' . $error->getMessage() . '|' . $error->getFile() . '|' . $error->getLine();
    if ($writing || isset($fingerprints[$fingerprint])) return;
    $fingerprints[$fingerprint] = true;
    $writing = true;

    try {
        $userId = function_exists('current_user_id') && current_user_id() > 0
            ? current_user_id()
            : null;
        $actor = function_exists('current_username') && current_username() !== ''
            ? current_username()
            : null;
        $db->logApplication(
            $userId,

            'LINNMS APP',
            trim($action) !== '' ? $action : 'application_error',
            'error',
            (string)$error,
            [],
            $actor,
            $_SERVER['REMOTE_ADDR'] ?? null
        );
        $GLOBALS['LINNMS_ORIGINAL_ERROR_LOGGED'] = true;
    } catch (Throwable $loggingError) {
        error_log('Pencatatan error aplikasi gagal: ' . $loggingError->getMessage());
    } finally {
        $writing = false;
    }
}

function linnms_log_error_message(
    LinnmsRepository $db,
    string $area,
    string $action,
    string $message,
    array $context = []
): void {
    $message = trim($message);
    if ($message === '') return;

    $details = [];
    foreach ($context as $key => $value) {
        if (!is_scalar($value)) continue;
        $value = trim((string)$value);
        if ($value !== '') $details[] = trim((string)$key) . '=' . $value;
    }
    if ($details !== []) $message .= ' | ' . implode(' ', $details);

    try {
        $userId = function_exists('current_user_id') && current_user_id() > 0
            ? current_user_id()
            : null;
        $actor = function_exists('current_username') && current_username() !== ''
            ? current_username()
            : null;
        $db->logApplication(
            $userId,
            trim($area) !== '' ? $area : 'LINNMS APP',
            trim($action) !== '' ? $action : 'application_error',
            'error',
            $message,
            [],
            $actor,
            $_SERVER['REMOTE_ADDR'] ?? null
        );
    } catch (Throwable $loggingError) {
        error_log('Pencatatan error aplikasi gagal: ' . $loggingError->getMessage());
    }
}

function linnms_register_php_error_logging(LinnmsRepository $db): void
{
    set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($db): bool {
        if ((error_reporting() & $severity) === 0) return false;
        linnms_log_original_error(
            $db,
            new ErrorException($message, 0, $severity, $file, $line),
            'php_error'
        );
        return false;
    });

    register_shutdown_function(static function () use ($db): void {
        $fatal = error_get_last();
        if (!is_array($fatal) || !in_array((int)($fatal['type'] ?? 0), [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR], true)) {
            return;
        }
        $message = 'PHP Fatal error: ' . (string)($fatal['message'] ?? 'Fatal error tanpa pesan.') .
            ' in ' . (string)($fatal['file'] ?? '') . ' on line ' . (int)($fatal['line'] ?? 0);
        linnms_log_original_error(
            $db,
            new ErrorException($message, 0, (int)($fatal['type'] ?? E_ERROR), (string)($fatal['file'] ?? ''), (int)($fatal['line'] ?? 0)),
            'php_fatal_error'
        );
    });
}

function monitoring_row_uses_handler(array $row, string $handler): bool
{
    $definition=monitoring_driver_definition(device_driver_type($row));
    return is_array($definition)&&(string)($definition['handler']??'')===$handler;
}

function activity_event_detail_text(mixed $value): string
{
    return rtrim(trim((string)$value), " .\t\n\r");
}

function activity_event_is_pppoe(array $event): bool
{
    return strtoupper(trim((string)($event['entity_type'] ?? ''))) === 'INTERFACE'
        && preg_match('~^mikrotik\|\d+\|pppoe:~i', trim((string)($event['entity_key'] ?? ''))) === 1;
}

function activity_event_parts(array $event): array
{
    $type = strtoupper(trim((string)($event['entity_type'] ?? '')));
    $field = trim((string)($event['field_name'] ?? ''));
    $old = display_text($event['old_value'] ?? null, '—');
    $new = display_text($event['new_value'] ?? null, '—');
    $statusKey = 'neutral';

    if ($type === 'ONU') {
        if ($field === 'onu_name') {
            $old = display_onu_name($event['old_value'] ?? null);
            $new = display_onu_name($event['new_value'] ?? null);
        }
        $olt = display_text($event['olt_name'] ?? null, '—');
        $index = display_text($event['event_onu_index'] ?? null, '—');
        $onu = display_onu_name($event['onu_name_display'] ?? $event['current_onu_name'] ?? $event['event_onu_name'] ?? null);
        $location = display_text($event['onu_location_display'] ?? $event['current_onu_location'] ?? null, '—');
        $identity = implode(' ', array_filter([
            $olt !== '—' ? $olt : '',
            $index !== '—' ? $index : '',
        ], static fn(string $part): bool => $part !== ''));
        $subject = $identity;
        $locationContext = $location !== '—' ? ' di lokasi ' . $location : '';
        $onuContext = '' . $onu . $locationContext . ' ';

        if ($field === 'problem_offline') {
            $hours = is_numeric($event['new_value'] ?? null)
                ? max(1, (int)$event['new_value'])
                : LinnmsRepository::ONU_PROBLEM_AFTER_HOURS;
            return [
                'subject' => $subject,
                'detail' => 'Offline lebih dari ' . $hours . ' jam',
                'status_key' => 'loss',
            ];
        }

        if ($field === 'problem_rx_power' && is_numeric($event['new_value'] ?? null)) {
            return [
                'subject' => $subject,
                'detail' => 'RX Power Bermasalah (' . format_activity_power($event['new_value']) . ')',
                'status_key' => 'loss',
            ];
        }

        if ($field === 'problem_rx_recovered' && is_numeric($event['new_value'] ?? null)) {
            return [
                'subject' => $subject,
                'detail' => 'RX Power kembali normal (' . format_activity_power($event['new_value']) . ')',
                'status_key' => 'online',
            ];
        }

        if ($field === 'rx_power' && is_numeric($event['old_value'] ?? null) && is_numeric($event['new_value'] ?? null)) {
            $oldPower = (float)$event['old_value'];
            $newPower = (float)$event['new_value'];
            $signalThreshold = max(0.1, (float)($event['signal_threshold_dbm'] ?? 1.0));
            $powerDrop = $oldPower - $newPower;
            $powerTrend = $newPower > $oldPower
                ? 'better'
                : ($powerDrop >= ($signalThreshold + 3.0) ? 'critical' : ($newPower < $oldPower ? 'worse' : 'same'));
            return [
                'subject' => $subject,
                'detail' => 'dari ' . format_activity_power($oldPower) . ' ➠ ' . format_activity_power($newPower),
                'old_power_display' => format_activity_power($oldPower),
                'new_power_display' => format_activity_power($newPower),
                'new_power_trend' => $powerTrend,
                'status_key' => 'neutral',
            ];
        }
        if ($field === 'onu_state') {
            $newState = state_key($event['new_value'] ?? null);
            $statusKey = $newState;
            $detail = $newState === 'online'
                ? ((int)($event['recovery_within_six_hours'] ?? 0) === 1 ? 'Kembali online' : 'Online')
                : strtolower(state_label($event['new_value'] ?? null));
            return ['subject' => $subject, 'detail' => $detail, 'status_key' => $statusKey];
        }
        if ($field === 'is_present') {
            $found = (string)($event['new_value'] ?? '') === '1';
            return [
                'subject' => $subject,
                'detail' => $found ? 'Ditemukan' : 'Tidak tersedia',
                'status_key' => $found ? 'online' : 'offline',
            ];
        }
        if ($field === 'onu_registration') {
            return [
                'subject' => '',
                'detail' => 'Berhasil mendaftarkan ONU dengan nama ' . $onu . ' alamat ' . $location,
                'status_key' => 'online',
            ];
        }
        if ($field === 'auth_mode') {
            $registrationResult = match (strtolower(trim((string)($event['new_value'] ?? '')))) {
                'pass' => 'Register Berhasil',
                'undef', 'uncfg' => 'Unregister Berhasil',
                'deny' => 'Deregister Berhasil',
                default => 'Status register ' . $new,
            };
            return [
                'subject' => $subject,
                'detail' => $registrationResult,
                'status_key' => strtolower($new) === 'pass' ? 'online' : 'neutral',
            ];
        }
        if ($field === 'onu_edit') {
            return ['subject' => '', 'detail' => activity_event_detail_text(safe_event_message($event['message'] ?? null)), 'status_key' => 'neutral'];
        }
        if ($field === 'onu_name') {
            return ['subject' => '', 'detail' => 'Mengedit nama ' . $old . ' ➠ ' . $new, 'status_key' => 'neutral'];
        }
        if ($field === 'onu_location') {
            return ['subject' => $subject, 'detail' => 'lokasi ' . $old . ' ➠ ' . $new, 'status_key' => 'neutral'];
        }
        if ($field === 'deleted') {
            return ['subject' => $subject, 'detail' => 'Dihapus', 'status_key' => 'offline'];
        }
        return ['subject' => $subject, 'detail' => activity_event_detail_text(safe_event_message($event['message'] ?? null)), 'status_key' => 'neutral'];
    }

    if ($type === 'INTERFACE') {
        $interface = display_text($event['interface_name_display'] ?? null, '—');
        $isPppoe = activity_event_is_pppoe($event);
        $subjectType = $isPppoe ? 'Pelanggan PPPoE' : 'Interface';
        $subject = trim(display_text($event['snmp_device_name'] ?? null, '—') . ' ' . $subjectType . ' ' . $interface);
        $online = state_key($event['new_value'] ?? null) === 'online';

        $detail = $online
            ? 'kembali Online'
            : 'Down';
        return ['subject' => $subject, 'detail' => $detail, 'status_key' => $online ? 'online' : 'offline'];
    }

    if ($type === 'DEVICE') {
        $online = state_key($event['new_value'] ?? null) === 'online';
        return [
            'subject' => display_text($event['snmp_device_name'] ?? null, '—'),
            'detail' => $online ? 'kembali online' : 'Down',
            'status_key' => $online ? 'online' : 'offline',
        ];
    }

    if ($type === 'OLT' && $field === 'pon_link_status') {
        $entityKey = trim((string)($event['entity_key'] ?? ''));
        $ponNumber = '';
        if (preg_match('~\\|pon\\|(\\d+)$~', $entityKey, $match)) {
            $ponNumber = (string)(int)$match[1];
        }
        $online = state_key($event['new_value'] ?? null) === 'online';
        return [
            'subject' => trim(display_text($event['olt_name'] ?? null, '—') . ' PON' . $ponNumber),
            'detail' => $online
                ? ((int)($event['recovery_within_six_hours'] ?? 0) === 1 ? 'LinkUp kembali' : 'LinkUp')
                : 'LinkDown',
            'status_key' => $online ? 'online' : 'offline',
        ];
    }

    if ($type === 'OLT' && $field === 'pon_tx_power') {
        $details = json_decode((string)($event['event_details_json'] ?? ''), true);
        if (is_array($details) && trim((string)($details['pon_ref'] ?? '')) !== '') {
            return [
                'pon_event_name' => trim((string)($details['name'] ?? $event['olt_name'] ?? '')),
                'pon_event_status' => trim((string)($details['status'] ?? '—')),
                'pon_event_ref' => trim((string)$details['pon_ref']),
                'pon_event_old_power' => format_activity_power($event['old_value'] ?? null),
                'pon_event_new_power' => format_activity_power($event['new_value'] ?? null),
                'pon_event_note' => trim((string)($details['note'] ?? 'perubahan')),
                'subject' => '',
                'detail' => '',
                'status_key' => 'signal-warning',
            ];
        }
        $entityKey = trim((string)($event['entity_key'] ?? ''));
        $ponNumber = '';
        if (preg_match('~\|pon\|(\d+)$~', $entityKey, $match)) {
            $ponNumber = (string)(int)$match[1];
        }
        $subject = trim(display_text($event['olt_name'] ?? null, '—') . ' PON' . $ponNumber);
        if (!is_numeric($event['new_value'] ?? null)) {
            $oldPower = is_numeric($event['old_value'] ?? null)
                ? format_activity_power($event['old_value'])
                : '—';
            return [
                'subject' => $subject,
                'detail' => 'LinkUp ' . $oldPower . ' ( SFP janggal )',
                'status_key' => 'signal-warning',
            ];
        }

        return [
            'subject' => $subject,
            'detail' => 'TX Power '
                . format_activity_power($event['old_value'] ?? null) . ' ➠ '
                . format_activity_power($event['new_value'] ?? null),
            'status_key' => 'signal-warning',
        ];
    }

    if ($type === 'OLT' && $field === 'pon_description') {
        $entityKey = trim((string)($event['entity_key'] ?? ''));
        $ponNumber = '';
        if (preg_match('~\|pon\|(\d+)$~', $entityKey, $match)) {
            $ponNumber = (string)(int)$match[1];
        }
        $message = trim((string)($event['message'] ?? ''));
        return [
            'subject' => trim(display_text($event['olt_name'] ?? null, '—') . ' PON' . $ponNumber),
            'detail' => $message !== ''
                ? activity_event_detail_text($message)
                : ($new === '—' ? 'Hapus berhasil' : 'Berhasil mengedit "' . $new . '"'),
            'status_key' => 'neutral',
        ];
    }

    if ($type === 'OLT' && in_array($field, ['driver_status', 'cli_status', 'is_present'], true)) {
        $newStatus = strtoupper(trim((string)($event['new_value'] ?? '')));
        $connectionLabel = match ($field) {
            'cli_status' => 'CLI/Telnet',
            'driver_status' => strtolower(trim((string)($event['event_source'] ?? ''))) === 'api' ? 'API' : 'SNMP',
            default => 'Perangkat',
        };
        $online = $field === 'is_present'
            ? (string)($event['new_value'] ?? '') === '1'
            : (state_key($event['new_value'] ?? null) === 'online' || in_array($newStatus, ['OK', 'SUCCESS'], true));
        return [
            'subject' => display_text($event['olt_name'] ?? null, '—'),
            'detail' => $online
                ? $connectionLabel . ' kembali terhubung'
                : $connectionLabel . ' tidak dapat dijangkau',
            'status_key' => $online ? 'online' : 'offline',
        ];
    }

    return ['subject' => '', 'detail' => activity_event_detail_text(safe_event_message($event['message'] ?? null)), 'status_key' => 'neutral'];
}

function activity_event_has_onu_identity(array $event): bool
{
    return strtoupper(trim((string)($event['entity_type'] ?? ''))) === 'ONU'
        && in_array(
            trim((string)($event['field_name'] ?? '')),
            ['onu_state', 'is_present', 'problem_offline', 'problem_rx_power', 'problem_rx_recovered', 'rx_power', 'onu_location', 'auth_mode', 'deleted'],
            true
        );
}

function activity_event_text(array $event): string
{
    $parts = activity_event_parts($event);
    if (isset($parts['pon_event_name'], $parts['pon_event_ref'], $parts['pon_event_note'])) {
        return trim((string)$parts['pon_event_name'] . ' pon ' . (string)$parts['pon_event_ref']
            . ' status ' . (string)$parts['pon_event_status']
            . ' power tx ' . (string)$parts['pon_event_old_power'] . ' ➠ '
            . (string)$parts['pon_event_new_power'] . ' ket: ' . (string)$parts['pon_event_note']);
    }
    if (activity_event_has_onu_identity($event)) {
        $name = display_onu_name($event['onu_name_display'] ?? $event['current_onu_name'] ?? $event['event_onu_name'] ?? null);
        $mac = display_mac_or_serial($event['onu_serial_display'] ?? $event['current_onu_serial'] ?? null, '—');
        return trim(($parts['subject'] !== '' ? $parts['subject'] . ' ' : '')
            . 'Nama : ' . $name . ', Serial : ' . $mac . ', ' . activity_event_detail_text($parts['detail'] ?? ''));
    }
    return trim(($parts['subject'] !== '' ? $parts['subject'] . ' ' : '') . activity_event_detail_text($parts['detail'] ?? ''));
}

function activity_event_notification_category(array $event): string
{
    $type = strtoupper(trim((string)($event['entity_type'] ?? '')));
    $field = trim((string)($event['field_name'] ?? ''));

    if ($type === 'ONU') {
        return match ($field) {
            'onu_state', 'is_present' => 'Status ONU',
            'problem_offline', 'problem_rx_power', 'problem_rx_recovered' => 'Bermasalah',
            'rx_power' => 'RX Power',
            default => 'Aktivitas ONU',
        };
    }
    if ($type === 'INTERFACE') {
        return activity_event_is_pppoe($event) ? 'PPPoE' : 'Interface';
    }
    if (in_array($type, ['OLT', 'DEVICE'], true)) return 'Device';

    return 'Aktivitas';
}

function activity_event_telegram_group_category(array $event): string
{
    $type = strtoupper(trim((string)($event['entity_type'] ?? '')));
    $field = trim((string)($event['field_name'] ?? ''));
    $parts = activity_event_parts($event);
    $status = state_key($event['new_value'] ?? null);

    if ($type === 'ONU') {
        if ($field === 'problem_rx_recovered') return 'Normal kembali';
        if (in_array($field, ['problem_offline','problem_rx_power'], true)) return 'Bermasalah';
        if ($field === 'rx_power') return 'RX Power';
        if (in_array($field, ['onu_state','is_present'], true)) {
            $online = $field === 'is_present' ? (string)($event['new_value'] ?? '') === '1' : $status === 'online';
            if ($online) return (int)($event['recovery_within_six_hours'] ?? 0) === 1 ? 'Kembali Online' : 'Online';
            if ($status === 'loss') return 'Loss';
            return 'Down';
        }
        if ($field === 'onu_registration') return 'Terdaftar';
        if ($field === 'auth_mode') return 'Terdaftar';
        return 'Aktivitas ONU';
    }

    if (in_array($type, ['OLT','DEVICE','INTERFACE'], true)) {
        if ($type === 'OLT' && $field === 'pon_tx_power') {
            return 'TX Power';
        }
        if ((string)($parts['status_key'] ?? '') === 'online') {
            return str_contains(strtolower((string)($parts['detail'] ?? '')), 'kembali') ? 'Kembali Online' : 'Online';
        }
        if ((string)($parts['status_key'] ?? '') === 'offline') return 'Down';
        return 'Aktivitas Device';
    }

    return activity_event_notification_category($event);
}

function activity_event_telegram_device(array $event): array
{
    $type = strtoupper(trim((string)($event['entity_type'] ?? '')));
    $entityKey = trim((string)($event['entity_key'] ?? ''));
    if (in_array($type, ['ONU','OLT'], true)) {
        $name = display_text($event['olt_name'] ?? null, 'OLT');
        $id = (int)($event['event_olt_id'] ?? 0);
        $source = trim((string)($event['event_source'] ?? ''));
        return ['key' => 'olt|' . $source . '|' . ($id > 0 ? $id : mb_strtolower($name)), 'name' => $name];
    }
    if (in_array($type, ['DEVICE','INTERFACE'], true)) {
        $name = display_text($event['snmp_device_name'] ?? $event['device_name'] ?? null, 'Device');
        $id = preg_match('~^(?:mikrotik|switch)\|(\d+)~', $entityKey, $match) ? (int)$match[1] : 0;
        return ['key' => 'device|' . ($id > 0 ? $id : mb_strtolower($name)), 'name' => $name];
    }
    $name = display_text($event['device_name'] ?? $event['olt_name'] ?? null, 'Device');
    return ['key' => 'other|' . mb_strtolower($name), 'name' => $name];
}

function activity_event_telegram_compact_line(array $event): string
{
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $type = strtoupper(trim((string)($event['entity_type'] ?? '')));
    $field = trim((string)($event['field_name'] ?? ''));
    if ($type === 'ONU') {
        $index = display_text($event['event_onu_index'] ?? null, '—');
        $name = display_onu_name($event['onu_name_display'] ?? $event['current_onu_name'] ?? $event['event_onu_name'] ?? null);
        $location = display_text($event['onu_location_display'] ?? $event['current_onu_location'] ?? null, '—');
        $rx = $event['current_onu_rx_power'] ?? null;
        $power = $field === 'rx_power'
            && is_numeric($event['old_value'] ?? null)
            && is_numeric($event['new_value'] ?? null)
                ? format_activity_power($event['old_value']) . ' → ' . format_activity_power($event['new_value'])
                : format_activity_power($rx);
        $line = '- ' . implode(' | ', array_map($escape, [$index, $name, $location, $power]));
        if (in_array($field, ['problem_offline','problem_rx_power'], true)) {
            $line .= ' — ' . $escape(activity_event_detail_text(activity_event_parts($event)['detail'] ?? ''));
        } elseif (!in_array($field, ['onu_state','is_present','onu_registration','rx_power'], true)) {
            $line .= ' — ' . $escape(activity_event_detail_text(activity_event_parts($event)['detail'] ?? ''));
        }
        return $line;
    }

    if ($type === 'OLT' && $field === 'pon_tx_power') {
        $storedMessage = trim((string)($event['message'] ?? ''));
        if (str_starts_with($storedMessage, 'Nama : ')) return '- ' . $escape($storedMessage);
        $entityKey = trim((string)($event['entity_key'] ?? ''));
        $ponLabel = 'PON';
        if (preg_match('~\|pon\|(\d+)$~', $entityKey, $match)) {
            $ponLabel .= (string)(int)$match[1];
        }
        if (is_numeric($event['new_value'] ?? null)) {
            return '- ' . $escape($ponLabel . ' | '
                . format_activity_power($event['old_value'] ?? null) . ' ke '
                . format_activity_power($event['new_value'] ?? null));
        }

        $oldPower = is_numeric($event['old_value'] ?? null)
            ? format_activity_power($event['old_value'])
            : '—';
        return '_ ' . $escape($ponLabel . ' | N/A') . "\n"
            . $escape('LinkUp Perubahan dari ' . $oldPower . ' ( Terjadi kesalahan )');
    }

    $device = activity_event_telegram_device($event);
    $parts = activity_event_parts($event);
    $subject = trim((string)($parts['subject'] ?? ''));
    $deviceName = trim((string)$device['name']);
    if ($deviceName !== '' && str_starts_with(mb_strtolower($subject), mb_strtolower($deviceName))) {
        $subject = trim(mb_substr($subject, mb_strlen($deviceName)));
    }
    $text = trim(($subject !== '' ? $subject . ' | ' : '') . (string)($parts['detail'] ?? ''));
    return '- ' . $escape($text !== '' ? $text : activity_event_text($event));
}

function activity_events_telegram_device_batches(array $events): array
{
    $notifications = [];
    foreach ($events as $event) {
        $device = activity_event_telegram_device($event);
        $notificationCategory = activity_event_notification_category($event);
        $notifications[$notificationCategory] ??= [
            'notification_category' => $notificationCategory,
            'devices' => [],
            'events' => [],
        ];
        $deviceKey = (string)$device['key'];
        $notifications[$notificationCategory]['devices'][$deviceKey] ??= [
            'name' => $device['name'],
            'categories' => [],
            'events' => [],
        ];
        $category = activity_event_telegram_group_category($event);
        $notifications[$notificationCategory]['devices'][$deviceKey]['categories'][$category][] = $event;
        $notifications[$notificationCategory]['devices'][$deviceKey]['events'][] = $event;
        $notifications[$notificationCategory]['events'][] = $event;
    }

    $order = array_flip(['Online','Kembali Online','Down','Loss','Bermasalah','RX Power','TX Power','Terdaftar','Aktivitas ONU','Aktivitas Device','Status Device','Aktivitas']);
    $batches = [];
    foreach ($notifications as $notification) {
        $htmlLines = [];
        $plainLines = [(string)$notification['notification_category']];
        $deviceNames = [];

        foreach ($notification['devices'] as $device) {
            if ($htmlLines !== []) $htmlLines[] = '';
            if (count($plainLines) > 1) $plainLines[] = '';

            $deviceName = (string)$device['name'];
            $deviceNames[] = $deviceName;
            $htmlLines[] = '<b>' . htmlspecialchars($deviceName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>';
            $plainLines[] = $deviceName;

            uksort($device['categories'], static fn(string $a, string $b): int => ($order[$a] ?? 999) <=> ($order[$b] ?? 999));
            foreach ($device['categories'] as $category => $categoryEvents) {
                $count = count($categoryEvents);
                if ($count < 1) continue;
                if ($category !== 'TX Power') $htmlLines[] = '';
                $categoryHeading = $category === 'TX Power' ? $category : $category . ': ' . $count;
                $htmlLines[] = '<b>' . htmlspecialchars($categoryHeading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>';
                if ($category !== 'TX Power') $plainLines[] = '';
                $plainLines[] = $categoryHeading;
                foreach ($categoryEvents as $event) {
                    $htmlLine = activity_event_telegram_compact_line($event);
                    $htmlLines[] = $htmlLine;
                    $plainLines[] = html_entity_decode(strip_tags($htmlLine), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }

        $ids = [];
        foreach ($notification['events'] as $event) {
            $eventId = (int)($event['id'] ?? 0);
            if ($eventId > 0) $ids[] = $eventId;
            foreach ((array)($event['superseded_notification_ids'] ?? []) as $supersededId) {
                $supersededId = (int)$supersededId;
                if ($supersededId > 0) $ids[] = $supersededId;
            }
        }
        $ids = array_values(array_unique($ids));
        $batches[] = [
            'name' => implode(', ', array_values(array_unique($deviceNames))),
            'category' => $notification['notification_category'],
            'title' => '<b>' . htmlspecialchars((string)$notification['notification_category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>',
            'html_lines' => $htmlLines,
            'plain' => implode("\n", $plainLines),
            'ids' => $ids,
            'events' => $notification['events'],
        ];
    }
    return $batches;
}
