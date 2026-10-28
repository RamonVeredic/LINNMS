<?php

declare(strict_types=1);

function validation_required_message(string $field): string
{
    return trim($field) . ' harus diisi.';
}

function validation_port_message(): string
{
    return 'Periksa port (1-65535), Community (SNMP), Port Telnet, Username, dan Password.';
}

function validation_ip_message(): string
{
    return 'Periksa IP Anda.';
}

function device_connection_message(string $kind): string
{
    return match (trim($kind)) {
        'snmp' => 'SNMP tidak merespons. Pastikan alamat IP, port SNMP, dan community sudah benar.',
        'telnet' => 'Telnet tidak merespons. Pastikan port Telnet, username, dan password sudah benar.',
        'api' => 'Koneksi OLT External API gagal. Periksa pengaturan layanan.',
        default => 'Perangkat tidak dapat dijangkau.',
    };
}

function validation_invalid_message(string $field): string
{
    return trim($field) . ' tidak valid.';
}

function registration_sanitize_display_name(mixed $name): string
{
    $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$name) ?? '';
    $clean = preg_replace('/\s{2,}/u', ' ', $clean) ?? '';

    return trim($clean);
}

function text_longest_repeat_run(string $text): int
{
    $text = mb_strtolower($text);
    $previous = '';
    $current = 0;
    $longest = 0;
    for ($index = 0, $length = strlen($text); $index < $length; $index++) {
        $char = $text[$index];
        $current = $char === $previous ? $current + 1 : 1;
        $previous = $char;
        if ($current > $longest) $longest = $current;
    }

    return $longest;
}

function text_has_sequence(string $text, int $minRun = 4): bool
{
    $text = mb_strtolower($text);
    $ascending = 1;
    $descending = 1;
    for ($index = 1, $length = strlen($text); $index < $length; $index++) {
        $delta = ord($text[$index]) - ord($text[$index - 1]);
        $ascending = $delta === 1 ? $ascending + 1 : 1;
        $descending = $delta === -1 ? $descending + 1 : 1;
        if ($ascending >= $minRun || $descending >= $minRun) return true;
    }

    return false;
}

function text_has_repeated_block(string $text, int $minRepeat = 3, int $maxBlockLength = 4): bool
{
    $text = mb_strtolower($text);
    $length = strlen($text);
    for ($block = 2; $block <= $maxBlockLength; $block++) {
        if ($length < $block * $minRepeat) continue;
        for ($start = 0; $start + ($block * $minRepeat) <= $length; $start++) {
            $piece = substr($text, $start, $block);
            if (trim($piece) === '') continue;
            if (substr($text, $start, $block * $minRepeat) === str_repeat($piece, $minRepeat)) return true;
        }
    }

    return false;
}

function text_is_repeated_block(string $text, int $maxBlockLength = 4, int $minLength = 6): bool
{
    $text = mb_strtolower($text);
    $length = strlen($text);
    if ($length < $minLength || $length > $maxBlockLength * 2) return false;
    for ($block = 2; $block <= $maxBlockLength; $block++) {
        if ($length % $block !== 0 || $length === $block) continue;
        $piece = substr($text, 0, $block);
        if (trim($piece) !== '' && str_repeat($piece, intdiv($length, $block)) === $text) return true;
    }

    return false;
}

function text_longest_consonant_run(string $text): int
{
    $text = mb_strtolower($text);
    $run = 0;
    $longest = 0;
    for ($index = 0, $length = strlen($text); $index < $length; $index++) {
        $char = $text[$index];
        if ($char >= 'a' && $char <= 'z' && !str_contains('aeiou', $char)) {
            $run++;
            if ($run > $longest) $longest = $run;
        } else {
            $run = 0;
        }
    }

    return $longest;
}

function text_has_keyboard_run(string $text, int $minRun = 4): bool
{
    $text = mb_strtolower($text);
    foreach (['qwertyuiop', 'asdfghjkl', 'zxcvbnm', '1234567890', 'qazwsxedc'] as $row) {
        for ($index = 0; $index + $minRun <= strlen($row); $index++) {
            if (str_contains($text, substr($row, $index, $minRun))) return true;
        }
    }

    return false;
}

function registration_username_pattern_invalid(string $username): bool
{
    $lower = mb_strtolower(trim($username));
    $letters = (int)preg_match_all('/[a-z]/', $lower);
    $digits = (int)preg_match_all('/[0-9]/', $lower);
    $total = $letters + $digits;
    $lettersOnly = (string)preg_replace('/[^a-z]/', '', $lower);

    return ($total > 0 && $digits === $total)
        || count(array_unique(str_split($lower))) === 1
        || text_longest_repeat_run($lower) >= 4
        || text_has_sequence($lower, 4)
        || text_has_keyboard_run($lower, 4)
        || text_has_repeated_block($lower, 3, 4)
        || text_is_repeated_block($lower, 4, 6)
        || ($total >= 5 && $letters < 1)
        || ($total >= 6 && count(array_unique(str_split($lower))) < 3)
        || ($digits >= 5 && $digits > $letters * 2)
        || (strlen($lettersOnly) >= 6 && text_longest_consonant_run($lettersOnly) >= 5);
}

function registration_normalize_password(string $password): string
{
    return strtr(mb_strtolower($password), [
        '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't',
        '@' => 'a', '$' => 's', '!' => 'i', '|' => 'i', '+' => 't',
    ]);
}

function registration_reserved_usernames(): array
{
    return registration_blocklist()['reserved_usernames'];
}

function registration_is_reserved_username(string $username): bool
{
    $username = mb_strtolower(trim($username));
    if ($username === '') return true;

    return in_array($username, registration_reserved_usernames(), true);
}

function registration_weak_passwords(): array
{
    return registration_blocklist()['weak_passwords'];
}

function registration_blocklist(): array
{
    static $blocklist = null;
    if (is_array($blocklist)) return $blocklist;

    $path = project_path('config', 'blocklist.json');
    $contents = @file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('Daftar keamanan pendaftaran tidak tersedia.');
    }

    try {
        $decoded = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('Daftar keamanan pendaftaran tidak valid.', 0, $error);
    }
    if (!is_array($decoded)) {
        throw new RuntimeException('Daftar keamanan pendaftaran tidak valid.');
    }

    $loaded = [];
    foreach (['reserved_usernames', 'weak_passwords'] as $key) {
        $entries = $decoded[$key] ?? null;
        if (!is_array($entries)) {
            throw new RuntimeException('Daftar keamanan pendaftaran tidak lengkap.');
        }
        $normalized = [];
        foreach ($entries as $entry) {
            if (!is_string($entry)) continue;
            $entry = mb_strtolower(trim($entry));
            if ($entry !== '') $normalized[$entry] = true;
        }
        $loaded[$key] = array_keys($normalized);
    }

    $blocklist = $loaded;
    return $blocklist;
}

function registration_password_invalid(string $password, string $username = '', string $displayName = '', string $phone = ''): bool
{
    $minLength = LinnmsRepository::REGISTRATION_MIN_PASSWORD_LENGTH;
    $length = mb_strlen($password);
    if ($length < $minLength || $length > 200) return true;
    $lower = mb_strtolower($password);
    $normalizedUsername = mb_strtolower(trim($username));
    if ($normalizedUsername !== '' && hash_equals($normalizedUsername, $lower)) return false;
    $displayName = mb_strtolower(registration_sanitize_display_name($displayName));
    $phoneDigits = normalize_phone_number($phone);
    if (preg_match('/[A-Za-z]/', $password) !== 1
        || preg_match('/[0-9]/', $password) !== 1
        || count(array_unique(str_split($lower))) === 1
        || text_longest_repeat_run($lower) >= 4
        || text_has_sequence($lower, 4)
        || text_has_keyboard_run($lower, 4)
        || text_has_repeated_block($lower, 3, 4)
        || text_is_repeated_block($lower, 4, 6)
        || ($normalizedUsername !== '' && mb_strlen($normalizedUsername) >= 3 && str_contains($lower, $normalizedUsername))
        || ($displayName !== '' && mb_strlen($displayName) >= 3 && str_contains($lower, $displayName))
        || ($phoneDigits !== '' && strlen($phoneDigits) >= 6 && str_contains($password, $phoneDigits))) return true;

    $weakPasswords = registration_weak_passwords();
    return in_array($lower, $weakPasswords, true)
        || in_array(registration_normalize_password($password), $weakPasswords, true);
}

function registration_form_guard_error(array $post, int $formStartedAt): ?string
{
    if (trim((string)($post['website'] ?? '')) !== '') {
        return 'Pendaftaran tidak dapat diproses.';
    }
    if ($formStartedAt <= 0) {
        return 'Sesi pendaftaran tidak ditemukan. Muat ulang halaman pendaftaran.';
    }
    $elapsed = time() - $formStartedAt;
    if ($elapsed < LinnmsRepository::REGISTRATION_MIN_FILL_SECONDS) {
        return 'Formulir terkirim terlalu cepat. Silakan periksa kembali isian Anda.';
    }
    if ($elapsed > LinnmsRepository::REGISTRATION_FORM_MAX_AGE_SECONDS) {
        return 'Sesi pendaftaran kedaluwarsa. Muat ulang halaman pendaftaran.';
    }

    return null;
}


function registration_account_validate(array $post, LinnmsRepository $db): array
{
    $displayName = registration_sanitize_display_name($post['display_name'] ?? '');
    $username = trim((string)($post['username'] ?? ''));
    $password = (string)($post['password'] ?? '');
    $passwordConfirm = (string)($post['password_confirm'] ?? '');
    $result = [
        'ok' => false,
        'error' => null,
        'field' => null,
        'display_name' => $displayName,
        'username' => $username,
        'password' => $password,
        'password_confirm' => $passwordConfirm,
    ];

    $nameLength = mb_strlen($displayName);
    if ($nameLength === 0) {
        $result['error'] = 'Nama wajib diisi.';
        $result['field'] = 'display_name';
        return $result;
    }
    if ($nameLength < 2) {
        $result['error'] = 'Nama minimal 2 karakter.';
        $result['field'] = 'display_name';
        return $result;
    }
    if ($nameLength > 120) {
        $result['error'] = 'Nama maksimal 120 karakter.';
        $result['field'] = 'display_name';
        return $result;
    }
    if (preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username) !== 1
        || registration_username_pattern_invalid($username)
        || registration_is_reserved_username($username)
        || $db->usernameExists($username)) {
        $result['error'] = 'Username tidak dapat digunakan.';
        $result['field'] = 'username';
        return $result;
    }
    if (registration_password_invalid($password, $username, $displayName)) {
        $result['error'] = 'Password tidak kuat.';
        $result['field'] = 'password';
        return $result;
    }
    if (!hash_equals($password, $passwordConfirm)) {
        $result['error'] = 'Konfirmasi password tidak sesuai.';
        $result['field'] = 'password_confirm';
        return $result;
    }

    $result['ok'] = true;

    return $result;
}
