<?php

declare(strict_types=1);



if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'paths.php';
require_once project_path('src', 'helpers.php');
require_once project_path('app', 'OltTerminalSession.php');
require_once project_path('vendor', 'driver', 'olt', 'OltAware.php');
require_once project_path('vendor', 'driver', 'olt', 'hioso', 'Cli.php');

final class LinnmsCliBroker
{
    private const IDLE_TTL_SECONDS = 3600;
    private const MAX_REQUEST_BYTES = 262144;
    
    private array $sessions = [];

    private array $ponTrafficSessions = [];
    
    private array $terminals = [];

    public function __construct(private readonly string $socketPath) {}

    public function run(): void
    {
        ensure_data_directory(dirname($this->socketPath));
        
        if (file_exists($this->socketPath)) @unlink($this->socketPath);

        $server = @stream_socket_server('unix://' . $this->socketPath, $errNo, $errStr);
        if (!is_resource($server)) {
            fwrite(STDERR, '[cli-broker] gagal membuka socket: ' . ($errStr !== '' ? $errStr : (string)$errNo) . PHP_EOL);
            exit(1);
        }
        @chmod($this->socketPath, 0660);
        stream_set_blocking($server, false);

        register_shutdown_function(function (): void {
            $this->closeAll();
            if (is_file($this->socketPath)) @unlink($this->socketPath);
        });

        fwrite(STDOUT, '[cli-broker] mendengarkan ' . $this->socketPath . PHP_EOL);

        while (true) {
            $read = [$server];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 1, 0);
            if (is_int($ready) && $ready > 0) {
                $client = @stream_socket_accept($server, 0);
                if (is_resource($client)) $this->handle($client);
            }
            $this->evictIdle();
            if (function_exists('pcntl_signal_dispatch')) @pcntl_signal_dispatch();
        }
    }

    
    private function handle($client): void
    {
        stream_set_timeout($client, 5);
        $raw = '';
        while (strlen($raw) < self::MAX_REQUEST_BYTES) {
            $chunk = @fgets($client, 65536);
            if (!is_string($chunk)) break;
            $raw .= $chunk;
            if (str_ends_with($chunk, "\n")) break;
        }

        $request = json_decode(trim($raw), true);
        $response = $this->dispatch(is_array($request) ? $request : []);

        stream_set_timeout($client, 120);
        @fwrite($client, json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        @fclose($client);
    }

    
    private function dispatch(array $request): array
    {
        $method = trim((string)($request['method'] ?? ''));
        if ($method === 'ping') {
            return ['ok' => true, 'result' => 'pong', 'sessions' => count($this->sessions)];
        }
        if ($method === 'close-device') return $this->closeDevice((array)($request['olt'] ?? []));
        if ($method === 'read-pon-cli-traffic') return $this->readPonCliTraffic($request);
        if ($method === 'read-pon-module') return $this->readPonModule($request);
        if (in_array($method, ['terminal-open', 'terminal-exchange', 'terminal-close'], true)) {
            return $this->terminalRequest($request);
        }
        $olt = is_array($request['olt'] ?? null) ? (array)$request['olt'] : [];
        $driverId = $this->driverId($olt);
        $definition = monitoring_driver_definition($driverId);
        $clientClass = trim((string)($definition['cli_terminal_class'] ?? ''));
        if ($clientClass === '' || !class_exists($clientClass)
            || !is_subclass_of($clientClass, OltCliBrokerOperationsAware::class)
            || !in_array($method, $clientClass::brokerMethods(), true)) {
            return ['ok' => false, 'message' => 'Operasi broker CLI tidak diizinkan oleh driver.'];
        }
        $key = trim((string)($request['key'] ?? ''));
        if ($key === '') $key = self::sessionKey($olt);
        if ($key === '') return ['ok' => false, 'message' => 'Identitas OLT tidak valid.'];

        if ($method === 'sessionActive') {
            return [
                'ok' => true,
                'result' => isset($this->sessions[$key]) && $this->sessions[$key]['client']->sessionActive(),
            ];
        }
        if ($method === 'close') {
            $this->closeSession($key);
            return ['ok' => true, 'result' => true];
        }

        $session = $this->session($key, $olt, $driverId, $clientClass);
        if (isset($session['error'])) {
            return ['ok' => false, 'message' => (string)$session['error']];
        }

        try {
            $args = array_values((array)($request['args'] ?? []));
            $result = $session['client']->{$method}(...$args);
            $this->sessions[$key]['last'] = microtime(true);
            return ['ok' => true, 'result' => $result];
        } catch (Throwable $error) {
            $this->closeSession($key);
            return ['ok' => false, 'message' => 'Broker CLI: ' . $error->getMessage()];
        }
    }

    private function terminalRequest(array $request): array
    {
        $key = (string)($request['key'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) return ['ok' => false, 'message' => 'Sesi terminal tidak valid.'];
        $method = (string)$request['method'];
        if ($method === 'terminal-close') {
            if (isset($this->terminals[$key])) $this->terminals[$key]['client']->close();
            unset($this->terminals[$key]);
            return ['ok' => true];
        }
        try {
            if ($method === 'terminal-open') {
                $this->evictIdle();
                if (isset($this->terminals[$key])) throw new RuntimeException('Sesi terminal sudah dibuka.');
                if (count($this->terminals) >= 32) throw new RuntimeException('Terminal sedang penuh. Coba lagi sebentar.');
                $olt = (array)($request['olt'] ?? []);
                $sessionKey = self::sessionKey($olt);
                $this->terminals[$key] = [
                    'client' => OltTerminalSession::open($olt),
                    'session_key' => $sessionKey,
                    'driver_id' => trim((string)device_driver_type($olt)),
                    'host' => trim((string)($olt['host'] ?? '')),
                    'device_id' => (int)($olt['device_id'] ?? 0),
                    'user_id' => (int)($olt['user_id'] ?? 0),
                    'last' => microtime(true),
                ];
            }
            $terminal = $this->terminals[$key]['client'] ?? null;
            if (!$terminal instanceof OltTerminalSession || !$terminal->active()) {
                throw new RuntimeException('Sesi terminal telah berakhir. Buka terminal kembali.');
            }
            $encoded = (string)($request['data'] ?? '');
            $data = base64_decode($encoded, true);
            if ($data === false || strlen($data) > 8192) throw new RuntimeException('Input terminal tidak valid.');
            $terminal->resize((int)($request['cols'] ?? 100), (int)($request['rows'] ?? 28));
            if ($data !== '') $terminal->write($data);
            $output = $terminal->read();
            $this->terminals[$key]['last'] = microtime(true);
            $active = $terminal->active();
            if (!$active) { $terminal->close(); unset($this->terminals[$key]); }
            return ['ok' => true, 'active' => $active, 'output' => base64_encode($output)];
        } catch (Throwable $error) {
            if (isset($this->terminals[$key])) $this->terminals[$key]['client']->close();
            unset($this->terminals[$key]);
            return ['ok' => false, 'message' => $error->getMessage()];
        }
    }

    private function ponCliClient(array $olt, string $interface, string $label): array
    {
        $driverId = $this->driverId($olt);
        $definition = monitoring_driver_definition($driverId);
        $clientClass = is_array($definition) ? trim((string)($definition['cli_terminal_class'] ?? '')) : '';
        if ($clientClass === '' || !class_exists($clientClass) || !is_subclass_of($clientClass, $interface)) {
            return ['ok' => false, 'message' => 'Driver tidak mendukung pembacaan ' . $label . ' melalui CLI.'];
        }
        $key = self::sessionKey($olt);
        if ($key === '') return ['ok' => false, 'message' => 'Identitas OLT tidak valid.'];
        foreach ($this->terminals as $terminal) {
            if (($terminal['driver_id'] ?? '') === $driverId && ($terminal['session_key'] ?? '') === $key) {
                return ['ok' => false, 'busy' => true, 'message' => 'Sesi terminal sedang digunakan.'];
            }
        }
        $client = $this->ponTrafficSessions[$key]['client'] ?? null;
        if (!is_a($client, $interface) || !$client->sessionActive()) {
            if (is_object($client) && method_exists($client, 'close')) $client->close();
            $client = (new \App\Driver\Controller())->createCli($driverId, $olt + [
                'timeout_seconds' => 15,
                'read_wait_ms' => 300,
            ]);
            if (!$client instanceof $interface || !$client instanceof OltTerminalCliClient) {
                return ['ok' => false, 'message' => 'Driver tidak mendukung pembacaan ' . $label . ' melalui CLI.'];
            }
            $login = $client->connectForTerminal();
            if (empty($login['ok'])) {
                $client->close();
                unset($this->ponTrafficSessions[$key]);
                return ['ok' => false, 'message' => (string)($login['message'] ?? 'Login CLI gagal.')];
            }
            $this->ponTrafficSessions[$key] = [
                'client' => $client,
                'last' => microtime(true),
                'host' => trim((string)($olt['host'] ?? '')),
                'driver_id' => $driverId,
                'device_id' => (int)($olt['device_id'] ?? 0),
                'user_id' => (int)($olt['user_id'] ?? 0),
            ];
        }
        return ['ok' => true, 'client' => $client, 'key' => $key];
    }

    private function readPonCliTraffic(array $request): array
    {
        $olt = is_array($request['olt'] ?? null) ? $request['olt'] : [];
        $session = $this->ponCliClient($olt, OltPonCliTrafficAware::class, 'trafik PON');
        if (empty($session['ok'])) return $session;
        $client = $session['client'];
        $key = (string)$session['key'];
        $targets = array_values(array_filter((array)($request['targets'] ?? []), 'is_array'));
        $commandTimeoutMs = max(1500, (int)($request['command_timeout_ms'] ?? 5000));
        try {
            $result = $client->readPonTrafficRates($targets, $commandTimeoutMs);
        } catch (Throwable $error) {
            error_log('[cli-broker] CLI PON traffic: ' . $error->getMessage());
            $client->close();
            unset($this->ponTrafficSessions[$key]);
            return ['ok' => false, 'message' => 'Pembacaan trafik PON melalui CLI gagal.'];
        }
        $rates = (array)($result['rates'] ?? []);
        if ($rates === []) {
            $client->close();
            unset($this->ponTrafficSessions[$key]);
        } else {
            $this->ponTrafficSessions[$key]['last'] = microtime(true);
        }
        return ['ok' => !empty($result['ok']), 'busy' => false, 'rates' => $rates, 'message' => (string)($result['message'] ?? '')];
    }

    private function readPonModule(array $request): array
    {
        $olt = is_array($request['olt'] ?? null) ? $request['olt'] : [];
        $session = $this->ponCliClient($olt, OltPonModuleCliAware::class, 'modul optik');
        if (empty($session['ok'])) return $session;
        $client = $session['client'];
        $key = (string)$session['key'];
        $refs = array_values(array_filter(array_map('strval', (array)($request['refs'] ?? [])), static fn(string $ref): bool => trim($ref) !== ''));
        try {
            $modules = $client->readPonModuleInfoByRefs($refs);
        } catch (Throwable $error) {
            error_log('[cli-broker] CLI PON module: ' . $error->getMessage());
            $client->close();
            unset($this->ponTrafficSessions[$key]);
            return ['ok' => false, 'message' => 'Pembacaan modul optik PON melalui CLI gagal.'];
        }
        $modules = is_array($modules) ? $modules : [];
        $valid = array_filter($modules, static fn(mixed $module): bool => is_array($module) && !empty($module['ok']));
        if ($valid === []) {
            $client->close();
            unset($this->ponTrafficSessions[$key]);
        } else {
            $this->ponTrafficSessions[$key]['last'] = microtime(true);
        }
        return ['ok' => $valid !== [], 'modules' => $modules,
            'message' => $valid === [] ? 'Tidak ada informasi modul optik PON yang berhasil dibaca.' : ''];
    }

    private function session(string $key, array $olt, string $driverId, string $clientClass): array
    {
        if (isset($this->sessions[$key])) {
            if ($this->sessions[$key]['client']->sessionActive()) {
                $this->sessions[$key]['last'] = microtime(true);
                return ['client' => $this->sessions[$key]['client']];
            }
            $this->closeSession($key);
        }

        $host = trim((string)($olt['host'] ?? ''));
        $username = trim((string)($olt['username'] ?? ''));
        $password = (string)($olt['password'] ?? '');
        if ($host === '' || $username === '' || $password === '') {
            return ['error' => 'Kredensial CLI tidak lengkap.'];
        }

        $client = (new \App\Driver\Controller())->createCli($driverId, $olt);
        if (!$client instanceof $clientClass) {
            return ['error' => 'Driver CLI tidak sesuai dengan katalog.'];
        }
        $login = $client->connectForTerminal();
        if (empty($login['ok'])) {
            $client->close();
            return ['error' => (string)($login['message'] ?? 'Login CLI gagal.')];
        }

        $this->sessions[$key] = [
            'client' => $client,
            'last' => microtime(true),
            'host' => $host,
            'driver_id' => $driverId,
            'device_id' => (int)($olt['device_id'] ?? 0),
            'user_id' => (int)($olt['user_id'] ?? 0),
        ];
        return ['client' => $client];
    }

    private function closeDevice(array $olt): array
    {
        $deviceId = (int)($olt['device_id'] ?? 0);
        $userId = (int)($olt['user_id'] ?? 0);
        if ($deviceId < 1 || $userId < 1) return ['ok' => false, 'message' => 'Identitas OLT tidak valid.'];
        foreach ($this->terminals as $key => $session) {
            if (($session['device_id'] ?? 0) !== $deviceId || ($session['user_id'] ?? 0) !== $userId) continue;
            $session['client']->close();
            unset($this->terminals[$key]);
        }
        foreach ($this->sessions as $key => $session) {
            if (($session['device_id'] ?? 0) !== $deviceId || ($session['user_id'] ?? 0) !== $userId) continue;
            $this->closeSession($key);
        }
        foreach ($this->ponTrafficSessions as $key => $session) {
            if (($session['device_id'] ?? 0) !== $deviceId || ($session['user_id'] ?? 0) !== $userId) continue;
            $session['client']->close();
            unset($this->ponTrafficSessions[$key]);
        }
        return ['ok' => true];
    }

    private function closeSession(string $key): void
    {
        if (!isset($this->sessions[$key])) return;
        $this->sessions[$key]['client']->close();
        unset($this->sessions[$key]);
    }

    private function evictIdle(): void
    {
        $now = microtime(true);
        foreach ($this->terminals as $key => $session) {
            if ($now - $session['last'] <= 90 && $session['client']->active()) continue;
            $session['client']->close();
            unset($this->terminals[$key]);
        }
        foreach (array_keys($this->sessions) as $key) {
            if (($now - (float)$this->sessions[$key]['last']) <= self::IDLE_TTL_SECONDS) continue;
            $this->sessions[$key]['client']->close();
            unset($this->sessions[$key]);
        }
        foreach (array_keys($this->ponTrafficSessions) as $key) {
            if (($now - (float)$this->ponTrafficSessions[$key]['last']) <= self::IDLE_TTL_SECONDS) continue;
            $this->ponTrafficSessions[$key]['client']->close();
            unset($this->ponTrafficSessions[$key]);
        }
    }

    private function closeAll(): void
    {
        foreach ($this->terminals as $session) $session['client']->close();
        $this->terminals = [];
        foreach (array_keys($this->sessions) as $key) {
            $this->sessions[$key]['client']->close();
        }
        $this->sessions = [];
        foreach ($this->ponTrafficSessions as $session) $session['client']->close();
        $this->ponTrafficSessions = [];
    }

    private function driverId(array $olt): string
    {
        $driverId = trim((string)($olt['driver_id'] ?? ''));
        return $driverId !== '' ? $driverId : trim((string)device_driver_type($olt));
    }

    private static function sessionKey(array $olt): string
    {
        $host = trim((string)($olt['host'] ?? ''));
        $username = trim((string)($olt['username'] ?? ''));
        if ($host === '' || $username === '') return '';
        $driverId = trim((string)($olt['driver_id'] ?? ''));
        if ($driverId === '') $driverId = trim((string)device_driver_type($olt));
        $deviceId = (int)($olt['device_id'] ?? 0);
        $userId = (int)($olt['user_id'] ?? 0);
        return sha1(implode('|', [
            $driverId,
            $userId > 0 && $deviceId > 0 ? $userId . ':' . $deviceId : '',
            $host,
            (string)(int)($olt['port'] ?? 23),
            $username,
            sha1((string)($olt['password'] ?? '')),
        ]));
    }
}

if (defined('LINNMS_CLI_BROKER_LIBRARY_ONLY')) return;

$socketPath = trim((string)(getenv('LINNMS_CLI_BROKER_SOCKET') ?: ''));
if ($socketPath === '') {
    $socketPath = lock_path('olt-cli-broker.sock');
}

$broker = new LinnmsCliBroker($socketPath);
$broker->run();
