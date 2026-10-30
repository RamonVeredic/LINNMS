<?php

declare(strict_types=1);

final class ActionResultCodes
{
    private const CODES = [
        'ok', 'validation', 'not_found', 'forbidden', 'unsupported',
        'device_unreachable', 'device_auth_failed', 'device_rejected',
        'conflict', 'internal',
    ];

    public static function normalize(array $result, ?LinnmsRepository $db = null, int $userId = 0, string $action = ''): array
    {
        $ok = !empty($result['ok']);
        $original = trim((string)($result['code'] ?? ''));
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        if (!in_array($original, self::CODES, true)) {
            if ($original !== '') $data['reason'] = $original;
            $code = $ok ? 'ok' : self::failureCode($original);
        } else {
            $code = $original;
        }
        return ['ok' => $ok, 'code' => $code, 'data' => $data];
    }

    private static function failureCode(string $code): string
    {
        if (in_array($code, ['quota', 'quota_total', 'quota_user', 'rate_limited', 'running', 'partial',
            'already_registered', 'replacement_registered', 'profile_after_device', 'profile_rolled_back'], true)) {
            return 'conflict';
        }
        if (in_array($code, ['network', 'connection'], true)) return 'device_unreachable';
        if (in_array($code, ['api_unavailable', 'unavailable', 'group_unsupported'], true)
            || str_ends_with($code, '_unsupported')) return 'unsupported';
        if (in_array($code, ['auth', 'inactive', 'invalid_credentials', 'driver_disabled'], true)) return 'forbidden';
        if (in_array($code, ['device_failed', 'registration_failed', 'registration_unverified',
            'rename_failed', 'serial_unavailable', 'api_failed', 'otp_failed'], true)
            || str_ends_with($code, '_register_failed') || str_ends_with($code, '_unregister_failed')
            || str_ends_with($code, '_rename_failed')) {
            return 'device_rejected';
        }
        if ($code === 'server' || $code === 'save_failed') return 'internal';
        if ($code === 'olt_missing') return 'not_found';
        if (str_ends_with($code, '_not_found')) return 'not_found';
        if (in_array($code, ['multiple_untag', 'duplicate_vlan', 'acs_credentials_pair',
            'olt_identity_mismatch', 'driver_identity_mismatch', 'olt_mismatch', 'session_incomplete'], true)) {
            return 'validation';
        }
        if (str_ends_with($code, '_invalid') || str_ends_with($code, '_required')
            || str_ends_with($code, '_empty') || $code === 'missing_fields' || $code === 'session_incomplete') {
            return 'validation';
        }
        return 'internal';
    }
}
