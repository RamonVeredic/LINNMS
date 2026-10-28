<?php

declare(strict_types=1);

function connection_method_label(array $row): string
{
    $definition = monitoring_driver_definition(trim((string)device_driver_type($row)));
    $method = is_array($definition) ? strtolower(trim((string)($definition['method'] ?? ''))) : '';
    return match ($method) {
        'snmp' => 'SNMP',
        'api' => 'API',
        default => $method !== '' ? strtoupper($method) : '',
    };
}

function display_text(mixed $value, string $fallback = '—'): string
{
    if ($value === null) return $fallback;
    if (is_bool($value)) return $value ? 'Ya' : 'Tidak';
    if (!is_scalar($value)) return $fallback;
    $text = trim((string) $value);
    return $text === '' || $text === '_' ? $fallback : $text;
}

function display_model_chars(mixed $value, string $fallback = '—'): string
{
    $text = display_text($value, $fallback);
    if ($text === $fallback) return $text;
    $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = trim($text);
    return $text !== '' ? $text : $fallback;
}

function display_onu_serial(mixed $value, string $fallback = '—'): string
{
    $text = display_text($value, $fallback);
    if ($text === $fallback) return $text;
    if (preg_match('/^[0-9a-f]{12}$/i', $text) === 1) {
        return strtoupper(implode(':', str_split($text, 2)));
    }

    if (preg_match('/^(?:[0-9a-f]{2}[:-]){5}[0-9a-f]{2}$/i', $text) === 1) {
        $hex = preg_replace('/[:-]/', '', $text) ?? $text;
        return strtoupper(implode(':', str_split($hex, 2)));
    }

    return $text;
}


function display_mac_or_serial(mixed $value, string $fallback = '—'): string
{
    return display_onu_serial($value, $fallback);
}


function onu_location_value(mixed $value): string
{
    $location = trim((string)$value);
    return in_array($location, ['', '-', '_', '—'], true) ? '' : $location;
}


function onu_decode_hex_label(mixed $value): string
{
    $label = trim((string)$value);
    if (preg_match('/^(?:Hex-STRING:\s*)?((?:[0-9a-f]{2}\s+)+[0-9a-f]{2})$/i', $label, $match) !== 1) {
        return $label;
    }

    $bytes = hex2bin(preg_replace('/\s+/', '', $match[1]) ?? '');
    if ($bytes === false || preg_match('//u', $bytes) !== 1
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $bytes) === 1) {
        return $label;
    }

    return trim($bytes);
}


function onu_split_device_label(mixed $value): array
{
    $raw = onu_decode_hex_label($value);
    if ($raw === '') return ['name' => '', 'location' => ''];
    if (preg_match('/^(.+?)\s+-\s+(.+)$/u', $raw, $m) === 1) {
        return ['name' => trim($m[1]), 'location' => onu_location_value($m[2])];
    }
    return ['name' => $raw, 'location' => ''];
}


function onu_join_device_label(mixed $name, mixed $location = ''): string
{
    $name = trim((string)$name);
    $location = onu_location_value($location);
    if ($name === '') return $location;
    return $location === '' ? $name : $name . ' - ' . $location;
}

function display_onu_name(mixed $value): string
{
    $raw = onu_decode_hex_label($value);
    if ($raw === '') return '—';
    return display_text(onu_split_device_label($raw)['name'], '—');
}


function display_onu_location(mixed $value, mixed $deviceLabel = null): string
{
    $stored = onu_location_value($value);
    if ($stored !== '') return display_text($stored, '—');
    $fromLabel = onu_split_device_label($deviceLabel)['location'];
    return display_text($fromLabel, '—');
}

function onu_pon_label(mixed $physical, mixed $onuIndex = null): string
{
    $pon = trim((string)($physical ?? ''));
    if ($pon === '' && $onuIndex !== null) {
        $index = trim((string)$onuIndex);
        if ($index !== '') {
            $separator = strpos($index, ':');
            $pon = trim($separator === false ? $index : substr($index, 0, $separator));
        }
    }
    return $pon;
}


function onu_pon_filter_key(mixed $identity): string
{
    $text = strtolower(trim((string)$identity));
    if ($text === '') return '';
    $text = preg_replace('/^gpon[-_]/i', '', $text) ?? $text;
    return trim(str_replace('_', '/', $text), '/');
}

function olt_type_label(mixed $value): string
{
    $raw = display_text($value);
    if ($raw === '—') return $raw;
    $definition = monitoring_driver_definition($raw);
    return is_array($definition) ? (string)$definition['label'] : (olt_type_options()[$raw] ?? $raw);
}

function device_driver_label(array $row): string
{
    $definition=monitoring_driver_definition(trim((string)device_driver_type($row)));
    return is_array($definition)?(string)$definition['label']:olt_type_label($row['type']??null);
}

function event_field_label(string $field): string
{
    return [
        'name' => 'Nama',
        'ip_address' => 'Alamat IP',
        'olt_type' => 'Model',
        'total_onus' => 'Total ONU',
        'address' => 'Alamat',
        'model' => 'Model',
        'software_version' => 'Software',
        'revision' => 'Revision',
        'mac' => 'MAC',
        'serial' => 'Serial Number',
        'system_name' => 'System Name',
        'description' => 'Description',
        'location' => 'Lokasi',
        'onu_name' => 'Nama ONU',
        'onu_location' => 'Lokasi ONU',
        'onu_type' => 'Tipe ONU',
        'onu_serial' => 'Serial ONU',
        'onu_state' => 'Status ONU',
        'rx_power' => 'Sinyal RX',
        'tx_power' => 'Sinyal TX',
        'pon_tx_power' => 'TX Power PON',
        'pon_description' => 'Keterangan PON',
        'pon_port_config' => 'Konfigurasi PON',
        'temperature' => 'Suhu',
        'distance' => 'Jarak',
        'physical' => 'Posisi',
        'is_present' => 'Keberadaan',
    ][$field] ?? 'Informasi';
}

function format_activity_power(mixed $power): string
{
    if ($power === null || $power === '' || !is_numeric($power)) return '—';
    $number = rtrim(rtrim(number_format((float)$power, 2, '.', ''), '0'), '.');
    return $number . ' dBm';
}

function effective_signal_threshold_dbm(array $settings): float
{
    return LinnmsRepository::clampOnuRxDetection($settings['signal_threshold_dbm'] ?? null);
}

function state_key(mixed $state): string
{
    $v = strtolower(trim((string) $state));
    if ($v === '') return 'offline';
    $compact = str_replace(['', '_', '_'], '', $v);
    if (str_contains($v, 'loss') || in_array($v, ['los', 'lost'], true)) return 'loss';
    if (in_array($compact, ['powerdown', 'pwrdown', 'dyinggasp'], true)) return 'powerdown';
    if (in_array($compact, ['online', 'up', 'active', 'working', 'linkup'], true)) return 'online';
    if (in_array($compact, ['offline', 'down', 'inactive', 'linkdown', 'unknown'], true)) return 'offline';
    return $v;
}

function effective_onu_state_key(mixed $state, mixed $rxPower, bool $lossWithoutRx = false): string
{
    return state_key($state);
}


function onu_loss_without_rx(array $onu): bool
{
    $definition = monitoring_driver_definition(trim((string)device_driver_type($onu)));
    return !empty($definition['onu_loss_without_rx']);
}

function onu_rx_problem_recovery_dbm(float $limitDbm, float $detectionDbm): float
{
    $limit = abs($limitDbm);
    $recovery = $limit - abs($detectionDbm);

    return $recovery > 0.0 ? $recovery : $limit;
}

function onu_rx_problem_next_state(string $state, float $rxPowerDbm, float $limitDbm, float $detectionDbm): string
{
    $limit = abs($limitDbm);
    if (strtolower(trim($state)) === 'problem') {
        $recoveryLimit = onu_rx_problem_recovery_dbm($limit, $detectionDbm);

        return abs($rxPowerDbm) <= $recoveryLimit ? 'normal' : 'problem';
    }

    return $rxPowerDbm <= -$limit ? 'problem' : 'normal';
}

function state_label(mixed $state): string
{
    return match (state_key($state)) {
        'online' => 'Online',
        'offline' => 'Offline',
        'loss' => 'Lost',
        'powerdown' => 'DyingGasp',
        default => display_text($state),
    };
}

function format_power(mixed $power): string
{
    if ($power === null || $power === '') return '—';
    if (!is_numeric($power)) return (string)$power;
    return number_format((float) $power, 2) . ' dBm';
}

function onu_rx_power_observed_number(mixed $value): ?float
{
    if (!is_scalar($value) || is_bool($value)) return null;
    $text = trim((string)$value);
    $text = preg_replace('/^(?:INTEGER|STRING|Gauge32|Counter32):\s*/i', '', $text) ?? $text;
    $text = preg_replace('/\s*dBm$/i', '', $text) ?? $text;
    if (preg_match('/^[+-]?\d{1,3}(?:,\d{3})+(?:\.\d+)?$/D', $text) === 1) {
        $text = str_replace(',', '', $text);
    }
    if (!is_numeric($text)) return null;
    $number = (float)$text;
    return is_finite($number) ? $number : null;
}

function onu_rx_power_rejected(mixed $value): bool
{
    $number = onu_rx_power_observed_number($value);
    return $number !== null && ($number <= -40.0 || $number >= 20.0);
}

function onu_rx_power_device_sentinel(mixed $value): bool
{
    $number = onu_rx_power_observed_number($value);
    return $number === 65535.0 || $number === 2147483647.0;
}

function onu_rx_power_number(mixed $value): ?float
{
    $number = onu_rx_power_observed_number($value);
    return $number === null || onu_rx_power_rejected($number) ? null : $number;
}

function onu_rx_power_display(mixed $value): string
{
    if ($value === null || $value === '') return '—';
    if (is_string($value) && in_array(strtolower(trim($value)), ['na', 'n/a', '_'], true)) return '—';
    if (onu_rx_power_rejected($value)) return '—';
    $number = onu_rx_power_number($value);
    return $number !== null ? format_power($number) : (string)$value;
}

function onu_strip_rejected_rx(array $response, bool &$rejected): array
{
    $rawCandidate = $response['rx_power_raw'] ?? null;
    $valueCandidate = $response['rx_power'] ?? null;
    $valueRawCandidate = is_array($valueCandidate) ? ($valueCandidate['raw'] ?? null) : null;
    if (is_array($valueCandidate)) $valueCandidate = $valueCandidate['value'] ?? null;
    $deviceCandidate = $response['device_raw']['rx_power'] ?? null;
    $originalCandidate = $response['rx_power_device_raw'] ?? null;
    if (onu_rx_power_rejected($rawCandidate) || onu_rx_power_rejected($valueCandidate)
        || onu_rx_power_rejected($valueRawCandidate)
        || onu_rx_power_device_sentinel($deviceCandidate)
        || onu_rx_power_device_sentinel($originalCandidate)) {
        $rejected = true;
        unset($response['rx_power'], $response['rx_power_raw'], $response['rx_power_device_raw']);
        if (is_array($response['device_raw'] ?? null)) unset($response['device_raw']['rx_power']);
    }
    foreach ($response as $key => $value) {
        if ($key === 'device_raw') continue;
        if (is_array($value)) $response[$key] = onu_strip_rejected_rx($value, $rejected);
    }
    if ($rejected) unset($response['raw'], $response['output']);
    return $response;
}

function power_class(mixed $power): string
{
    $p = onu_rx_power_number($power);
    if ($p === null) return 'neutral';
    if ($p <= -28.0) return 'danger';
    if ($p <= -25.0) return 'warning';
    return 'success';
}

function power_icon_class(mixed $power): string
{
    return match (power_class($power)) {
        'danger' => 'bi-reception-2',
        'warning' => 'bi-reception-3',
        'success' => 'bi-reception-4',
        default => 'bi-reception-0',
    };


}


function format_bps(mixed $bps): string
{
    if (!is_numeric($bps)) return '0 bps';
    $value = max(0.0, (float)$bps);
    if ($value >= 1000000000) return round($value / 1000000000, 2) . ' Gbps';
    if ($value >= 1000000) return round($value / 1000000, 1) . ' Mbps';
    if ($value >= 1000) return round($value / 1000, 1) . ' Kbps';
    return (string)(int)round($value) . ' bps';
}


function format_bps_short(mixed $bps): string
{
    $value = is_numeric($bps) ? max(0.0, (float)$bps) : 0.0;
    $trim = static fn(float $number, int $decimals): string => rtrim(rtrim(number_format($number, $decimals, '.', ''), '0'), '.');
    if ($value >= 1000000000) return $trim($value / 1000000000, 2) . ' G';
    if ($value >= 1000000) return $trim($value / 1000000, 2) . ' M';
    if ($value >= 1000) return $trim($value / 1000, 1) . ' K';
    return (string)(int)round($value);
}

function traffic_chart_scale(mixed $series, string $field = 'rx'): array
{
    $max = 0.0;
    foreach ((array)$series as $point) {
        $value = is_array($point) ? (float)($point[$field] ?? 0) : 0.0;
        if ($value > $max) $max = $value;
    }
    $nice = static function (float $value): float {
        if ($value <= 0) return 0.0;
        $exponent = floor(log10($value));
        $base = 10 ** $exponent;
        $normalized = $value / $base;
        $step = $normalized <= 1 ? 1 : ($normalized <= 2 ? 2 : ($normalized <= 5 ? 5 : 10));
        return (float)($step * $base);
    };
    $top = $nice($max);
    if ($top <= 0) $top = 1.0;

    
    if ($top >= 1000000000) { $unit = 'Gbps'; $divisor = 1000000000.0; }
    elseif ($top >= 1000000) { $unit = 'Mbps'; $divisor = 1000000.0; }
    elseif ($top >= 1000) { $unit = 'Kbps'; $divisor = 1000.0; }
    else { $unit = 'bps'; $divisor = 1.0; }

    
    $step = $top / 4 / $divisor;
    $decimals = abs($step - round($step)) < 0.0001 ? 0 : ($step >= 0.1 ? 1 : 2);
    $formatValue = static function (float $value) use ($decimals): string {
        if ($decimals === 0) return (string)(int)round($value);
        return rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');
    };

    $labels = [];
    foreach ([0, 1, 2, 3, 4] as $index) {
        
        $fraction = $index / 4;
        $labels[] = [
            'y' => 180 - ($fraction * 160),
            'text' => $formatValue(($top * $fraction) / $divisor) . ' ' . $unit,
        ];
    }
    return ['max' => $top, 'labels' => $labels];
}
if (!function_exists('format_onu_uptime')) {
    function format_onu_uptime(mixed $value): string
    {
        $text = trim((string)$value);
        if ($text === '') return '—';
        $seconds = null;
        if (is_numeric($text)) {
            $seconds = (int)$text;
        } elseif (preg_match_all('/(\d+)\s*([dhms])/i', $text, $matches, PREG_SET_ORDER)) {
            $total = 0;
            foreach ($matches as $part) {
                $amount = (int)$part[1];
                $total += match (strtolower($part[2])) {
                    'd' => $amount * 86400,
                    'h' => $amount * 3600,
                    'm' => $amount * 60,
                    default => $amount,
                };
            }
            $seconds = $total;
        }
        if ($seconds === null) return display_text($text, '—');
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $parts = [];
        if ($days > 0) $parts[] = $days . 'd';
        if ($hours > 0 || $days > 0) $parts[] = $hours . 'h';
        $parts[] = $minutes . 'm';
        return implode(' ', $parts);
    }
}
