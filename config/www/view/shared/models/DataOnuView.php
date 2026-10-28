<?php

declare(strict_types=1);

final class DataOnuView
{
    public static function snapshotFromRow(array $onu): array
    {
        $fields = json_decode((string)($onu['detail_json'] ?? ''), true);
        $ports = json_decode((string)($onu['port_lan_json'] ?? ''), true);
        $snapshot = ['fields' => is_array($fields) ? $fields : []];
        foreach (['temperature', 'distance', 'olt_rx_power', 'tx_power', 'register_time', 'deregister_time', 'deregister_count', 'port_count', 'online_duration', 'running_time'] as $key) {
            $snapshot[$key] = $snapshot['fields'][$key] ?? null;
        }
        $snapshot['equipment_id'] = $onu['actual_type'] ?? null;
        $snapshot['ports'] = is_array($ports) ? $ports : [];
        return $snapshot;
    }

    public static function fieldSupport(array $definition): array
    {
        $fields = is_array($definition['onu_detail_fields'] ?? null) ? $definition['onu_detail_fields'] : [];
        $supported = $fields !== [];
        return [
            'supported' => $supported,
            'index' => in_array('index', $fields, true),
            'temperature' => in_array('temperature', $fields, true),
            'register_status' => in_array('register_status', $fields, true),
            'rx_power' => in_array('rx_power', $fields, true),
            'tx_power' => in_array('tx_power', $fields, true),
            'olt_rx_power' => in_array('olt_rx_power', $fields, true),
            'register_time' => in_array('register_time', $fields, true),
            'deregister_time' => in_array('deregister_time', $fields, true),
            'deregister_count' => in_array('deregister_count', $fields, true),
            'distance' => in_array('distance', $fields, true),
            'port_count' => in_array('port_count', $fields, true),
            'running_time' => in_array('running_time', $fields, true),
        ];
    }

    public static function detailValues(array $definition, array $values): array
    {
        $support = self::fieldSupport($definition);
        $formatters = [
            'index' => static fn(mixed $value): string => (string)$value,
            'temperature' => static fn(mixed $value): string => number_format((float)$value, 2) . ' °C',
            'register_status' => static fn(mixed $value): string => (string)$value,
            'rx_power' => static fn(mixed $value): string => number_format((float)$value, 2) . ' dBm',
            'tx_power' => static fn(mixed $value): string => number_format((float)$value, 2) . ' dBm',
            'olt_rx_power' => static fn(mixed $value): string => number_format((float)$value, 2) . ' dBm',
            'register_time' => static fn(mixed $value): string => (string)$value,
            'deregister_time' => static fn(mixed $value): string => (string)$value,
            'deregister_count' => static fn(mixed $value): string => (string)$value,
            'distance' => static fn(mixed $value): string => (string)(int)round((float)$value) . ' m',
            'port_count' => static fn(mixed $value): string => (string)$value,
            'running_time' => static fn(mixed $value): string => format_onu_uptime($value),
        ];
        $numericFields = ['temperature', 'rx_power', 'tx_power', 'olt_rx_power', 'distance'];
        $result = [];
        foreach ($formatters as $field => $format) {
            $value = $values[$field] ?? null;
            if (empty($support['supported']) || empty($support[$field])) {
                $result[$field] = ['value' => 'N/A', 'state' => 'unsupported'];
            } elseif ($value === null || $value === '' || $value === '—' || $value === '_'
                || (is_string($value) && in_array(strtolower(trim($value)), ['na', 'n/a'], true))) {
                $result[$field] = ['value' => '—', 'state' => 'empty'];
            } elseif ($field === 'rx_power') {
                $display = onu_rx_power_display($value);
                $result[$field] = ['value' => $display, 'state' => $display === '—' ? 'empty' : 'value'];
            } elseif (in_array($field, $numericFields, true) && !is_numeric($value)) {
                $result[$field] = ['value' => (string)$value, 'state' => 'value'];
            } else {
                $result[$field] = ['value' => $format($value), 'state' => 'value'];
            }
        }
        return $result;
    }
}
