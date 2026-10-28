<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/driver/Controller.php';

use App\Driver\Controller as DriverController;

final class DeviceConnectionTestService
{

    public function __construct(
        private readonly LinnmsRepository $db,
        private readonly array $config,
        private readonly ApiClientFactory $apiClients,
        private readonly CredentialCipher $credentialCipher
    ) {
    }

    public function test(int $userId, array $input): array
    {
        if ($userId < 1) throw new InvalidArgumentException('User API internal tidak valid.');


        try {
            return $this->run($userId, $input);
        } catch (InvalidArgumentException $error) {
            return $this->failure('validation');
        } catch (Throwable $error) {
            if ($error instanceof Error) {
                linnms_log_original_error($this->db, $error, 'connection_test_device_internal');
                return $this->failure('internal');
            }
            error_log('[LINNMS] Connection test device: ' . $error->getMessage());
            return $this->failure('device_unreachable');
        }
    }

    private function run(int $userId, array $input): array
    {
        $editRowId = valid_positive_id($input['device_row_id'] ?? null);
        $editDeviceRow = $editRowId !== null ? $this->db->getEditableDevice($userId, $editRowId) : null;
        if (!is_array($editDeviceRow)) $editDeviceRow = null;

        $selectedDriverId = trim((string)($input['driver_id'] ?? ''));
        $driverId = $selectedDriverId;
        if (str_starts_with($selectedDriverId, 'api_olt_type:')) {
            $apiOltType = substr($selectedDriverId, strlen('api_olt_type:'));
            if ($apiOltType === '' || !array_key_exists($apiOltType, selectable_api_olt_type_options())) {
                throw new InvalidArgumentException('Jenis OLT API tidak valid.');
            }
            $driverId = 'external_olt_api';
        }

        $definition = monitoring_driver_definition($driverId);
        if (!is_array($definition)) {
            throw new InvalidArgumentException('Pilih jenis perangkat yang valid.');
        }

        $storedCredentialsAllowed = false;
        if (is_array($editDeviceRow)) {
            $currentDefinition = monitoring_driver_definition(trim((string)device_driver_type($editDeviceRow)));
            $storedCredentialsAllowed = is_array($currentDefinition)
                && (string)($currentDefinition['handler'] ?? '') === (string)($definition['handler'] ?? '')
                && (string)($currentDefinition['device_type'] ?? '') === (string)($definition['device_type'] ?? '');
        }

        $passwordValue = function (string $postName, string $encryptedField) use (
            $input,
            $editDeviceRow,
            $storedCredentialsAllowed
        ): string {
            $password = (string)($input[$postName] ?? '');
            if ($password !== '' || !$storedCredentialsAllowed || !is_array($editDeviceRow)) {
                return $password;
            }
            $encrypted = trim((string)($editDeviceRow[$encryptedField] ?? ''));
            return $encrypted !== '' ? $this->credentialCipher->decrypt($encrypted) : '';
        };

        $method = (string)($definition['method'] ?? '');
        $handler = (string)($definition['handler'] ?? '');
        $ip = trim((string)($input['ip_address'] ?? ''));
        $driverController = new DriverController();
        $route = $driverController->resolve($driverId, $method);
        if (empty($route['ok'])) return $this->failure('unsupported');

        if ($method === 'api') {
            $client = $this->apiClients->forUser($userId);
            if ($client === null) {
                return $this->failure('unsupported');
            }
            $result = $client->request('HEAD', (string)($this->config['endpoints']['olts'] ?? '/api/v1/olts'));
            $probe = api_probe_result($result);
            if (empty($probe['ok'])) {
                $status = (int)($result['status'] ?? 0);
                return $this->failure($status === 401 || $status === 403 ? 'device_auth_failed' : 'device_rejected');
            }
        } elseif ($method === 'snmp') {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new InvalidArgumentException(validation_ip_message());
            }
            $snmpPort = $this->portValue($input, 'snmp_port', 161);
            $community = trim((string)($input['community'] ?? ''));
            if ($community === '') {
                throw new InvalidArgumentException(validation_required_message('Community'));
            }

            if ($handler === 'snmp_olt') {
                $timing = $this->db->getSyncTimingSettings($userId);
                $timeoutMs = (int)($timing['snmp_request_timeout_ms'] ?? 0);
                $driver = $driverController->createSnmp(
                    $driverId,
                    $ip,
                    $community,
                    $snmpPort,
                    [
                        'row_id' => 0,
                        'timeout_us' => $timeoutMs > 0 ? $timeoutMs * 1000 : 1500000,
                        'retries' => 1,
                    ]
                );
                $result = $driver->test();
                if (empty($result['ok'])) {
                    return $this->failure('device_unreachable');
                }
                $modelCheck = olt_model_validate($driverId, $definition, $driver->getSystemInfo());
                if (empty($modelCheck['ok'])) {
                    return $this->failure('validation');
                }
            } elseif ($driverController->driverIdForDeviceType((string)($definition['device_type'] ?? '')) === $driverId) {
                $timing = $this->db->getSyncTimingSettings($userId);
                $timeoutMs = (int)($timing['snmp_request_timeout_ms'] ?? 0);
                $timeoutUs = $timeoutMs > 0 ? $timeoutMs * 1000 : 500000;
                $driver = $driverController->createSnmp($driverId, $ip, $community, $snmpPort, [
                    'timeout_us' => max(100000, $timeoutUs),
                    'retries' => 1,
                ]);
                $driver->getSystemInfo();
            } else {
                return $this->failure('unsupported');
            }
        } else {
            return $this->failure('unsupported');
        }

        if (!empty($definition['telnet_cli']) && (string)($input['hybrid_status'] ?? '') === '1') {
            if ($ip === '') {
                throw new InvalidArgumentException(validation_required_message('IP / Host'));
            }
            $cliPort = $this->portValue($input, 'ports', 23);
            $cliUsername = trim((string)($input['username'] ?? ''));
            $cliPassword = $passwordValue('password', 'password_enc');
            if ($cliUsername === '') {
                throw new InvalidArgumentException(validation_required_message('Username'));
            }
            if ($cliPassword === '') {
                throw new InvalidArgumentException(validation_required_message('Password'));
            }

            $reader = $driverController->createCli($driverId, [
                'host' => $ip,
                'port' => $cliPort,
                'username' => $cliUsername,
                'password' => $cliPassword,
                'timeout_seconds' => 20,
                'read_wait_ms' => (int)($definition['cli_test_read_wait_ms'] ?? 300),
            ]);
            try {
                $result = $driverController->execute($driverId, $reader, 'connection_test');
                if (empty($result['ok'])) {
                    return $this->failure('device_unreachable');
                }
            } finally {
                $reader->close();
            }
        }

        return [
            'ok' => true,
            'code' => 'ok',
            'data' => [],
        ];
    }

    private function portValue(array $input, string $name, int $default): int
    {
        $raw = trim((string)($input[$name] ?? (string)$default));
        if (!ctype_digit($raw)) throw new InvalidArgumentException(validation_port_message());
        $port = (int)$raw;
        if ($port < 1 || $port > 65535) throw new InvalidArgumentException(validation_port_message());
        return $port;
    }

    private function failure(string $code): array
    {
        return [
            'ok' => false,
            'code' => $code,
            'data' => [],
        ];
    }
}
