<?php

declare(strict_types=1);

final class WhatsAppGateway
{
    private const TIMEOUT_SECONDS = 20;
    private const DEFAULT_PROVIDER = 'fonnte';

    
    private static ?array $registry = null;

    public function __construct(private array $settings)
    {
    }

    
    public static function providers(): array
    {
        if (is_array(self::$registry)) return self::$registry;
        $path = dirname(LINNMS_ROOT) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'whatsapp-gateways.php';
        $loaded = is_file($path) ? require $path : [];

        return self::$registry = is_array($loaded) ? $loaded : [];
    }

    
    public static function providerOptions(): array
    {
        $options = [];
        foreach (self::providers() as $key => $definition) {
            $options[(string)$key] = (string)($definition['label'] ?? (string)$key);
        }

        return $options;
    }

    public static function isValidProvider(string $provider): bool
    {
        return array_key_exists(strtolower(trim($provider)), self::providers());
    }

    public function providerKey(): string
    {
        $provider = strtolower(trim((string)($this->settings['provider'] ?? '')));

        return self::isValidProvider($provider) ? $provider : self::DEFAULT_PROVIDER;
    }

    
    public function definition(): array
    {
        $providers = self::providers();

        return (array)($providers[$this->providerKey()] ?? []);
    }

    public function providerLabel(): string
    {
        return (string)($this->definition()['label'] ?? $this->providerKey());
    }

    public function providerInfo(): string
    {
        return trim((string)($this->definition()['info'] ?? ''));
    }

    public function providerInfoUrl(): string
    {
        return trim((string)($this->definition()['info_url'] ?? ''));
    }

    public function isVerifiedProvider(): bool
    {
        return !empty($this->definition()['verified']);
    }

    
    public function providerFields(): array
    {
        $fields = $this->definition()['fields'] ?? [];

        return is_array($fields) ? $fields : [];
    }

    public function isEnabled(): bool
    {
        return (int)($this->settings['enabled'] ?? 0) === 1;
    }

    public function token(): string
    {
        return trim((string)($this->settings['token'] ?? ''));
    }

    public function deviceId(): string
    {
        return trim((string)($this->settings['device'] ?? ''));
    }

    public function baseUrl(): string
    {
        return rtrim(trim((string)($this->settings['base_url'] ?? '')), '/');
    }

    public function missingFields(): array
    {
        $missing = [];
        foreach ($this->providerFields() as $key => $field) {
            if (empty($field['required'])) continue;
            $value = match ((string)$key) {
                'token' => $this->token(),
                'device' => $this->deviceId(),
                'base_url' => $this->baseUrl(),
                default => trim((string)($this->settings[(string)$key] ?? '')),
            };
            if ($value === '') $missing[] = (string)($field['label'] ?? $key);
        }
        if (!empty($this->definition()['token_contains_session'])
            && $this->token() !== ''
            && !str_contains($this->token(), ':')) {
            $missing[] = 'Token lengkap session:token';
        }

        return $missing;
    }

    public function isConfigured(): bool
    {
        return $this->missingFields() === [];
    }

    public function isReady(): bool
    {
        return $this->isEnabled() && $this->isConfigured();
    }

    public function senderNumber(): string
    {
        return $this->normalizePhone((string)($this->settings['sender'] ?? ''));
    }

    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') return '';
        if (str_starts_with($digits, '0')) $digits = '62' . substr($digits, 1);
        if (str_starts_with($digits, '8')) $digits = '62' . $digits;

        return $digits;
    }

    public function isValidPhone(string $phone): bool
    {
        return preg_match('/^62[0-9]{8,15}$/', $this->normalizePhone($phone)) === 1;
    }

    public function targetType(): string
    {
        return strtolower(trim((string)($this->settings['target_type'] ?? ''))) === 'group' ? 'group' : 'phone';
    }

    public function isGroupTarget(string $target): bool
    {
        return str_contains(trim($target), '@');
    }

    public function isValidTarget(string $target): bool
    {
        $target = trim($target);
        if ($target === '') return false;
        if ($this->isGroupTarget($target)) {
            return preg_match('/^[A-Za-z0-9_.:-]{3,80}@[A-Za-z0-9.-]{2,20}$/', $target) === 1;
        }
        if ($this->targetType() === 'group') {
            return preg_match('/^[0-9]{6,30}$/', $target) === 1;
        }

        return $this->isValidPhone($target);
    }

    
    public function supportedTargetTypes(): array
    {
        $types = $this->definition()['target']['types'] ?? ['phone', 'group'];
        $types = is_array($types) ? array_values(array_unique(array_filter(array_map('strval', $types)))) : [];

        return $types !== [] ? $types : ['phone'];
    }

    public function supportsGroups(): bool
    {
        return in_array('group', $this->supportedTargetTypes(), true);
    }

    public function groupHint(): string
    {
        $hint = trim((string)($this->definition()['target']['group_hint'] ?? ''));

        return $hint !== '' ? $hint : 'Tempel Group ID dari dashboard gateway Anda (umumnya berakhiran @g.us).';
    }

    public function supportsGroupListing(): bool
    {

        return trim((string)((((array)($this->definition()['groups'] ?? []))['url']) ?? '')) !== '';
    }

    
    public function generateSessionToken(string $session, string $secret): array
    {
        if (empty($this->definition()['token_generator']['enabled'])) {
            return ['ok' => false, 'message' => 'Provider ini tidak menyediakan generator token.', 'token' => ''];
        }
        $baseUrl = $this->baseUrl();
        $session = trim($session);
        $secret = trim($secret);
        if ($baseUrl === '' || preg_match('~^https?://~i', $baseUrl) !== 1) {
            return ['ok' => false, 'message' => 'URL server tidak valid.', 'token' => ''];
        }
        if (preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $session) !== 1) {
            return ['ok' => false, 'message' => 'Nama session tidak valid.', 'token' => ''];
        }
        if (preg_match('/^[A-Za-z0-9_.~-]{4,120}$/', $secret) !== 1) {
            return ['ok' => false, 'message' => 'Secret key tidak valid.', 'token' => ''];
        }
        $request = [
            'url' => $baseUrl . '/api/' . rawurlencode($session) . '/' . rawurlencode($secret) . '/generate-token',
            'method' => 'POST',
            'body' => 'json',
            'headers' => ['Accept: application/json', 'Content-Type: application/json'],
            'payload' => [],
        ];
        $response = $this->performRaw($request);
        $raw = is_array($response['raw']) ? $response['raw'] : [];
        $token = trim((string)($raw['token'] ?? ''));
        $fullToken = trim((string)($raw['full'] ?? ''));
        if (!$response['ok'] || $token === '') {
            $detail = trim((string)($raw['message'] ?? $response['error'] ?? ''));
            $message = match (true) {
                $detail === 'The SECRET_KEY is incorrect' => 'Secret key tidak cocok dengan server WhatsApp.',
                (int)$response['http'] === 404 => 'Endpoint pembuatan token tidak ditemukan. Periksa URL server.',
                (int)$response['http'] === 0 => 'Server WhatsApp tidak dapat dihubungi. Periksa URL dan koneksi server.',
                default => 'Token gagal dibuat. Periksa URL server dan secret key.',
            };
            return [
                'ok' => false,
                'message' => $message,
                'token' => '',
                'detail' => $detail,
            ];
        }

        return ['ok' => true, 'message' => 'Token session berhasil dibuat.', 'token' => $fullToken !== '' ? $fullToken : $session . ':' . $token];
    }

    
    private function placeholders(string $to, string $message): array
    {
        return [
            '{token}' => $this->token(),
            '{auth_token}' => $this->authorizationToken(),
            '{session}' => $this->sessionName(),
            '{device}' => $this->deviceId(),
            '{base_url}' => $this->baseUrl(),
            '{to}' => $to,
            '{message}' => $message,
        ];
    }

    private function sessionName(): string
    {
        $token = $this->token();
        if (str_contains($token, ':')) return trim((string)strstr($token, ':', true));

        return $this->deviceId();
    }

    private function authorizationToken(): string
    {
        $token = $this->token();
        if (!str_contains($token, ':')) return $token;

        return trim((string)substr($token, strpos($token, ':') + 1));
    }

    
    private function render(string $template, array $values): string
    {
        return strtr($template, $values);
    }

    
    private function compose(array $definition, array $values): array
    {
        $payload = [];

        if ($this->isGroupTarget((string)($values['{to}'] ?? ''))) {
            foreach ((array)($this->definition()['group_params'] ?? []) as $field => $template) {
                $payload[(string)$field] = is_string($template)
                    ? $this->render($template, $values)
                    : $template;
            }
        }
        foreach ((array)($definition['payload'] ?? []) as $field => $template) {
            $payload[(string)$field] = is_string($template)
                ? $this->render($template, $values)
                : $template;
        }
        $headers = [];
        foreach ((array)($definition['headers'] ?? []) as $header) {
            $rendered = trim($this->render((string)$header, $values));

            if ($rendered !== '' && !str_ends_with($rendered, ':')) $headers[] = $rendered;
        }

        return [
            'url' => $this->render((string)($definition['url'] ?? ''), $values),
            'method' => strtoupper((string)($definition['method'] ?? 'POST')),
            'body' => (string)($definition['body'] ?? 'form'),
            'headers' => $headers,
            'payload' => $payload,
        ];
    }

    
    public function buildSendRequest(string $to, string $message): array
    {

        $trimmed = trim($to);
        $resolved = $this->isGroupTarget($trimmed) || $this->targetType() === 'group'
            ? $trimmed
            : $this->normalizePhone($trimmed);
        if (!$this->isGroupTarget($resolved) && $this->targetType() !== 'group') {
            $suffix = trim((string)($this->definition()['target']['phone_suffix'] ?? ''));
            if ($suffix !== '' && !str_ends_with($resolved, $suffix)) $resolved .= $suffix;
        }
        if ($resolved === '') $resolved = $trimmed;

        return $this->compose(
            (array)($this->definition()['send'] ?? []),
            $this->placeholders($resolved, $message)
        );
    }

    
    public function buildDeviceRequest(): ?array
    {
        $definition = (array)($this->definition()['device'] ?? []);
        if ($definition === [] || trim((string)($definition['url'] ?? '')) === '') return null;

        return $this->compose($definition, $this->placeholders('', ''));
    }

    
    public function send(string $target, string $message): array
    {
        $missing = $this->missingFields();
        if ($missing !== []) {

            return ['ok' => false, 'message' => 'Lengkapi kolom wajib pada formulir.', 'raw' => []];
        }
        $target = trim($target);
        if (!$this->isValidTarget($target)) {
            return [
                'ok' => false,
                'message' => $this->targetType() === 'group'
                    ? 'Group ID WhatsApp tidak valid.'
                    : 'Nomor tujuan tidak valid.',
                'raw' => [],
            ];
        }

        $to = $target;
        $body = trim($message);
        if ($body === '') {
            return ['ok' => false, 'message' => 'Isi pesan kosong.', 'raw' => []];
        }
        $request = $this->buildSendRequest($to, $body);
        if ($request['url'] === '') {
            return ['ok' => false, 'message' => 'URL gateway belum diatur pada registry.', 'raw' => []];
        }

        return $this->perform($request);
    }

    
    public function deviceStatus(): array
    {
        $missing = $this->missingFields();
        if ($missing !== []) {
            return ['ok' => false, 'message' => implode(', ', $missing) . ' belum diisi.', 'raw' => []];
        }
        $request = $this->buildDeviceRequest();
        if ($request === null) {
            return [
                'ok' => true,
                'message' => $this->providerLabel() . ' Gunakan "Kirim Pesan Uji".',
                'raw' => [],
            ];
        }

        return $this->perform($request);
    }

    
    public function perform(array $request): array
    {
        $response = $this->performRaw($request);
        if (!$response['ok']) {
            return ['ok' => false, 'message' => 'Gagal menghubungi ' . $this->providerLabel() . ': ' . $response['error'], 'raw' => []];
        }
        $status = (int)$response['http'];
        $raw = is_array($response['raw'])
            ? $response['raw']
            : ['http_status' => $status, 'body' => mb_substr((string)$response['raw'], 0, 500)];
        $decoded = is_array($response['raw']) ? $response['raw'] : null;
        $result = (array)($this->definition()['result'] ?? []);
        $okEquals = $result['ok_equals'] ?? true;
        $httpOkOnly = !empty($result['http_ok_only']);

        $value = is_array($decoded) ? self::jsonPath($decoded, trim((string)($result['ok_path'] ?? 'status'))) : null;
        $ok = false;
        if ($value !== null) {
            $ok = is_bool($okEquals) ? ((bool)$value === $okEquals) : ((string)$value === (string)$okEquals);
        }
        if (!$ok && $httpOkOnly && $status >= 200 && $status < 300) $ok = true;

        $detail = '';
        foreach ([(string)($result['message_path'] ?? ''), 'detail', 'message', 'reason'] as $path) {
            $path = trim($path);
            if ($path === '' || !is_array($decoded)) continue;
            $candidate = self::jsonPath($decoded, $path);
            if (is_scalar($candidate) && trim((string)$candidate) !== '') {
                $detail = trim((string)$candidate);
                break;
            }
        }
        if ($detail === '' && $status >= 400) $detail = 'HTTP ' . $status;

        return [
            'ok' => $ok,
            'message' => $ok
                ? ($detail !== '' ? $detail : 'Pesan diterima gateway.')
                : ($detail !== '' ? $detail : 'Gateway menolak permintaan.'),
            'raw' => $raw,
        ];
    }

    
    public function performRaw(array $request): array
    {
        $curl = curl_init($request['url']);
        if ($curl === false) {
            return ['ok' => false, 'http' => 0, 'raw' => [], 'error' => 'cURL tidak tersedia di server.'];
        }

        $headers = $request['headers'];
        if ($request['body'] === 'json') {
            $body = (string)json_encode($request['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!array_filter($headers, static fn(string $h): bool => stripos($h, 'content-type:') === 0)) {
                $headers[] = 'Content-Type: application/json';
            }
        } else {
            $body = http_build_query($request['payload'], '', '&');
            if (!array_filter($headers, static fn(string $h): bool => stripos($h, 'content-type:') === 0)) {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
        }

        $curlOptions = [
            CURLOPT_CUSTOMREQUEST => $request['method'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if (!in_array($request['method'], ['GET', 'HEAD'], true)) {
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($curl, $curlOptions);

        $response = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($response === false) {
            return ['ok' => false, 'http' => $status, 'raw' => [], 'error' => $error !== '' ? $error : 'Permintaan gagal.'];
        }
        $decoded = json_decode((string)$response, true);

        return [
            'ok' => true,
            'http' => $status,
            'raw' => is_array($decoded) ? $decoded : (string)$response,
            'error' => '',
        ];
    }

    
    public function buildGroupRequest(): ?array
    {
        $definition = (array)($this->definition()['groups'] ?? []);
        if ($definition === [] || trim((string)($definition['url'] ?? '')) === '') return null;

        return $this->compose($definition, $this->placeholders('', ''));
    }

    public function fetchGroups(): array
    {
        $missing = $this->missingFields();
        if ($missing !== []) {
            return [
                'ok' => false,
                'message' => 'Lengkapi formulir',
                'groups' => [],
                'raw' => [],
            ];
        }
        $request = $this->buildGroupRequest();
        if ($request === null) {
            return [
                'ok' => false,
                'message' => $this->providerLabel() . ' belum menyediakan daftar grup otomatis. Tempel Group ID secara manual.',
                'groups' => [],
                'raw' => [],
            ];
        }
        $response = $this->performRaw($request);
        $decoded = is_array($response['raw']) ? $response['raw'] : [];
        if (!$response['ok'] || $decoded === []) {
            return [
                'ok' => false,
                'message' => 'Gagal menghubungi ' . $this->providerLabel() . ': ' . $response['error'],
                'groups' => [],
                'raw' => [],
            ];
        }

        $definition = (array)($this->definition()['groups'] ?? []);
        $items = self::jsonPathAny($decoded, self::pathCandidates($definition, 'items_path', ['data']));
        $idPaths = self::pathCandidates($definition, 'item_id_path', ['id']);
        $namePaths = self::pathCandidates($definition, 'item_name_path', ['name']);
        $groups = [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (is_scalar($item)) {
                    $id = trim((string)$item);
                    if ($id !== '') $groups[] = ['id' => $id, 'name' => ''];
                    continue;
                }
                if (!is_array($item)) continue;
                $id = self::firstScalarPath($item, $idPaths);
                if ($id === '') continue;
                $groups[] = [
                    'id' => $id,
                    'name' => self::firstScalarPath($item, $namePaths),
                ];
            }
        }
        $detail = '';
        foreach (['detail', 'reason', 'message'] as $path) {
            $candidate = self::jsonPath($decoded, $path);
            if (is_scalar($candidate) && trim((string)$candidate) !== '') {
                $detail = trim((string)$candidate);
                break;
            }
        }

        return [
            'ok' => $groups !== [],
            'message' => $groups !== []
                ? count($groups) . ' Ditemukan'
                : ($detail !== '' ? $detail : 'Grup tidak ditemukan'),
            'groups' => $groups,
            'raw' => $decoded,
        ];
    }
    private static function jsonPath(array $data, string $path): mixed
    {
        if ($path === '$') return $data;
        if ($path === '') return null;
        $current = $data;
        foreach (explode('.', $path) as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
                continue;
            }

            return null;
        }

        return $current;
    }

    
    private static function pathCandidates(array $definition, string $key, array $fallback): array
    {
        $paths = [];
        foreach ([$key, $key . 's', $key . '_alternatives'] as $field) {
            foreach ((array)($definition[$field] ?? []) as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') $paths[] = trim($candidate);
            }
        }
        foreach ($fallback as $candidate) {
            $value = trim((string)$candidate);
            if ($value !== '' && !in_array($value, $paths, true)) $paths[] = $value;
        }

        return $paths;
    }

    
    private static function jsonPathAny(array $data, array $paths): ?array
    {
        foreach ($paths as $path) {
            $value = self::jsonPath($data, $path);
            if (is_array($value) && $value !== [] && array_is_list($value)) return $value;
        }

        return null;
    }

    
    private static function firstScalarPath(array $data, array $paths): string
    {
        foreach ($paths as $path) {
            $value = self::jsonPath($data, $path);
            if (is_scalar($value) && trim((string)$value) !== '') return trim((string)$value);
        }

        return '';
    }
}

