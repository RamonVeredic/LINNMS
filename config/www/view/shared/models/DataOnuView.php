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
        $fields = ['index', 'temperature', 'register_status', 'rx_power', 'tx_power', 'olt_rx_power',
            'register_time', 'deregister_time', 'deregister_count', 'distance', 'port_count', 'running_time'];
        $result = [];
        foreach ($fields as $field) {
            $value = $values[$field] ?? null;
            if (empty($support['supported']) || empty($support[$field])) {
                $result[$field] = ['value' => 'N/A', 'state' => 'unsupported'];
            } else {
                $display = self::formatDetailValue($field, $value);
                $result[$field] = ['value' => $display, 'state' => $display === '—' ? 'empty' : 'value'];
            }
        }
        return $result;
    }

    private static function formatDetailValue(string $field, mixed $value): string
    {
        if (is_array($value)) $value = $value['value'] ?? $value['raw'] ?? null;
        if (!is_scalar($value) || is_bool($value)) return '—';
        $text = trim((string)$value);
        if ($text === '' || in_array(strtolower($text), ['—', '-', '_', 'na', 'n/a', 'null'], true)) return '—';

        if ($field === 'running_time') {
            if (preg_match('/^Timeticks:\s*\((\d+)\)/i', $text, $match) === 1) {
                $text = (string)intdiv((int)$match[1], 100);
            }
            return is_numeric($text) && (float)$text <= 0 ? '—' : format_onu_uptime($text);
        }

        $text = preg_replace('/^(?:STRING|INTEGER|Gauge32|Counter32|Counter64):\s*/i', '', $text) ?? $text;
        $text = trim($text, " \t\n\r\0\x0B\"");
        if ($text === '' || in_array(strtolower($text), ['—', '-', '_', 'na', 'n/a', 'null'], true)) return '—';

        if (in_array($field, ['register_time', 'deregister_time'], true)) {
            return str_starts_with($text, '0000-00-00') ? '—' : $text;
        }
        if ($field === 'register_status' || $field === 'index') return $text;

        $numberText = preg_replace('/\s*(?:dBm|°C|m)$/i', '', $text) ?? $text;
        if (preg_match('/^[+-]?\d{1,3}(?:,\d{3})+(?:\.\d+)?$/D', $numberText) === 1) {
            $numberText = str_replace(',', '', $numberText);
        }
        if (!is_numeric($numberText)) return '—';
        $number = (float)$numberText;
        if (!is_finite($number)) return '—';

        return match ($field) {
            'rx_power', 'tx_power', 'olt_rx_power' => $number <= -40.0 || $number >= 20.0
                ? '—' : number_format($number, 2) . ' dBm',
            'temperature' => $number < -40.0 || $number > 125.0
                ? '—' : number_format($number, 2) . ' °C',
            'distance' => $number <= 0 || $number >= 2147483647
                ? '—' : (string)(int)round($number) . ' m',
            'port_count' => $number <= 0 || $number > 256
                ? '—' : (string)(int)$number,
            'deregister_count' => $number < 0 ? '—' : (string)(int)$number,
            default => $text,
        };
    }
}
