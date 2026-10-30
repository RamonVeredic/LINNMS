<?php

declare(strict_types=1);

use App\Driver\SnmpDriverFactory;
use App\Driver\Controller as DriverController;
use App\Service\TrafficService;

final class SnmpDeviceService
{
    private const INTERFACE_DISCOVERY_INTERVAL_SECONDS = 86400;
    private ?DeviceSnapshotWriteService $deviceWriter = null;
    private ?DeviceConfigurationWriteService $configWriter = null;
    private ?TrafficSampleWriteService $sampleWriter = null;

    
    public function __construct(
        private readonly LinnmsRepository $db,
        private readonly array $config = [],
    ) {}

    
    public function supportedDevices(): array
    {
        return SnmpDriverFactory::supportedDevices();
    }

    
    public function create(int $userId, string $driverId, array $input): array
    {
        $definition = monitoring_driver_definition($driverId);
        $type = is_array($definition) ? trim((string)($definition['device_type'] ?? '')) : '';
        $name = trim((string)($input['device_name'] ?? ''));
        $ip = trim((string)($input['ip_address'] ?? ''));
        $snmpPortRaw = trim((string)($input['snmp_port'] ?? '161'));
        $community = trim((string)($input['community'] ?? ''));
        if ($community === '') $community = trim((string)($device['snmp_community'] ?? ''));

        $expectedHandler = $type === 'mikrotik' ? 'mikrotik_snmp' : 'switch_snmp';
        if (!is_array($definition) || ($definition['handler'] ?? '') !== $expectedHandler || !isset($this->supportedDevices()[$type])) {
            throw new InvalidArgumentException('Jenis perangkat tidak valid.');
        }
        if ($name === '') throw new InvalidArgumentException(validation_required_message('Nama perangkat'));
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) throw new InvalidArgumentException(validation_ip_message());
        if (!ctype_digit($snmpPortRaw) || (int)$snmpPortRaw < 1 || (int)$snmpPortRaw > 65535) throw new InvalidArgumentException(validation_port_message());
        if ($community === '') throw new InvalidArgumentException(validation_required_message('Community'));
        if (!snmp_driver_support_enabled($this->db->getSnmpDriverSupportFlags(), $driverId)) {
            throw new RuntimeException((string)$definition['label'] . ' Belum tersedia');
        }

        $quota = $this->db->getUserSnmpDeviceQuota($userId, $type);
        if (empty($quota['can_add'])) {
            $label = $type === 'mikrotik' ? 'MikroTik' : 'switch';
            $scope = ($quota['blocked_by'] ?? '') === 'q' ? 'keseluruhan' : 'akun';
            $used = $scope === 'keseluruhan' ? (int)$quota['used'] : (int)$quota['user_used'];
            $limit = $scope === 'keseluruhan' ? (int)$quota['limit'] : (int)$quota['user_limit'];
            throw new RuntimeException('Batas ' . $label . ' ' . $scope . ' telah tercapai (' . $used . '/' . $limit . ').');
        }

        $snmpPort = (int)$snmpPortRaw;
       $driver = $this->driver($userId, $type, $ip, $community, $snmpPort, 1);
        $system = $driver->getSystemInfo();
        $interfaces = $driver->listInterfaces();
        if ($this->driverController()->canScheduleDiscovery($driverId)) {
            $system['_interfaces_discovered_at'] = date('Y-m-d H:i:s');
        }
        $id = $this->db->createSnmpDevice(
            $userId,
            $driverId,
            $type,
            $name,
            $ip,
            $snmpPort,
            $community,
            $system,
            $interfaces,
        );

        return ['id' => $id, 'device' => $this->requireDevice($userId, $id), 'system' => $system, 'interfaces' => $interfaces];
    }

    
    public function update(int $userId, int $rowId, string $driverId, array $input): array
    {
        $device = $this->db->getSnmpDevice($userId, $rowId);
        if ($device === null) throw new RuntimeException('Perangkat tidak ditemukan.');

        $deviceType = (string)($device['device_type'] ?? '');
        $definition = monitoring_driver_definition($driverId);
        $expectedHandler = $deviceType === 'mikrotik' ? 'mikrotik_snmp' : 'switch_snmp';
        if (
            !is_array($definition)
            || (string)($definition['handler'] ?? '') !== $expectedHandler
            || (string)($definition['device_type'] ?? '') !== $deviceType
        ) {
            throw new InvalidArgumentException('Jenis perangkat tidak valid.');
        }

        $name = trim((string)($input['device_name'] ?? ''));
        $ip = trim((string)($input['ip_address'] ?? ''));
        $snmpPortRaw = trim((string)($input['snmp_port'] ?? '161'));
        $community = trim((string)($input['community'] ?? ''));

        if ($name === '') throw new InvalidArgumentException(validation_required_message('Nama perangkat'));
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) throw new InvalidArgumentException(validation_ip_message());
        if (!ctype_digit($snmpPortRaw) || (int)$snmpPortRaw < 1 || (int)$snmpPortRaw > 65535) throw new InvalidArgumentException(validation_port_message());
        if ($community === '') throw new InvalidArgumentException(validation_required_message('Community'));
        if (!snmp_driver_support_enabled($this->db->getSnmpDriverSupportFlags(), $driverId)) {
            throw new RuntimeException((string)$definition['label'] . ' Belum tersedia');
        }

        $snmpPort = (int)$snmpPortRaw;
        $connectionChanged = $driverId !== trim((string)device_driver_type($device))
            || $ip !== trim((string)($device['ip_address'] ?? ''))
            || $snmpPort !== (int)($device['snmp_port'] ?? 161)
            || $community !== trim((string)($device['snmp_community'] ?? ''));

        $system = [];
        $interfaces = [];
        if ($connectionChanged) {
            try {
                $driver = $this->driver($userId, $deviceType, $ip, $community, $snmpPort, 1);
                $system = $driver->getSystemInfo();
                $interfaces = $driver->listInterfaces();
            } catch (Throwable $e) {
                throw new RuntimeException('IP perangkat tidak dapat dijangkau', 0, $e);
            }
            if ($this->driverController()->canScheduleDiscovery($driverId)) {
                $system['_interfaces_discovered_at'] = date('Y-m-d H:i:s');
            }
        }

        $this->configWriter()->updateConnection($userId, $rowId, [
            'type' => $driverId,
            'name' => $name,
            'ip_address' => $ip,
            'snmp_port' => $snmpPort,
        ]);
        $this->configWriter()->writeCommunity($rowId, $userId, $community);

        if ($system !== [] && $interfaces !== []) {
            $this->writer()->write($userId, $rowId, $system, $interfaces);
        }

        return ['id' => $rowId, 'device' => $this->requireDevice($userId, $rowId)];
    }

    
    public function inspect(int $userId, int $rowId, ?string $expectedType = null, bool $refresh = true): array
    {
        $device = $this->requireDevice($userId, $rowId, $expectedType);
        $system = $this->decodeObject($device['system_json'] ?? null);
        $interfaces = $this->decodeList($device['interfaces_json'] ?? null);
        $error = '';
        $live = false;
        $snapshotRead = false;

        if ($refresh) {
            try {
                $driver = $this->driverForRow($device);
                [$system, $interfaces] = $this->readDeviceSnapshot($device, $driver, false);
                $snapshotRead = true;
                $this->writer()->clearReadFailure($userId, $rowId);
                $stored = $this->writer()->write($userId, $rowId, $system, $interfaces);
                $interfaces = $stored['interfaces'];
                $device = $this->requireDevice($userId, $rowId, $expectedType);
                $live = true;
            } catch (Throwable $e) {
                $error = $e->getMessage();
                $this->writer()->markUnreachable($userId, $rowId, $error);
                if (!$snapshotRead) {
                    $this->writer()->recordReadFailure($userId, $rowId, $error);
                }
            }
        }

        return ['device' => $device, 'system' => $system, 'interfaces' => $interfaces, 'live' => $live, 'error' => $error];
    }

    public function syncAll(int $userId, ?string $onlyDriver = null, ?callable $yieldAfterDevice = null): array
    {
        require_once project_path('backend', 'services', 'SyncTargetLock.php');
        $emptyStats = ['checked' => 0, 'online' => 0, 'failed' => 0, 'changes' => 0];
        $result = [
            'checked' => 0,
            'online' => 0,
            'failed' => 0,
            'changes' => 0,
            'errors' => [],
            'by_driver' => [],
        ];

        $devices = $this->db->getSnmpDevices($userId);
        $driverOrder = array_keys(array_filter(monitoring_driver_catalog(), static fn(array $definition): bool =>
            in_array((string)($definition['handler'] ?? ''), ['mikrotik_snmp', 'switch_snmp'], true)));
        foreach ($driverOrder as $driverName) {
        if ($onlyDriver !== null && $onlyDriver !== $driverName) continue;
        $result['by_driver'][$driverName] = $emptyStats;
        foreach ($devices as $device) {
            if (snmp_monitoring_driver($device) !== $driverName) continue;
            $rowId = (int)($device['id'] ?? 0);
            if ($rowId < 1) continue;
            $result['checked']++;
            $result['by_driver'][$driverName]['checked']++;

            $syncLock = SyncTargetLock::acquire('snmp-device', $userId, $rowId);
            if (!is_resource($syncLock)) continue;

            $driver = null;
            $deviceReachable = null;
            $snapshotRead = false;
            try {
                $driver = $this->driverForRow($device);
                [$system, $interfaces] = $this->readMonitoringSnapshot($device, $driver);
                $snapshotRead = true;
                $deviceReachable = true;
                $this->writer()->clearReadFailure($userId, $rowId);
                if ($this->pipelineEnabled()) {
                    $this->db->markPipelineSeen($userId, $rowId);
                    $handler = (string)(monitoring_driver_definition($driverName)['handler'] ?? $driverName);
                    $this->db->enqueuePipelineSnapshot($userId, $rowId, $rowId, $handler, $driverName, [
                        'system' => $system,
                        'interfaces' => $interfaces,
                        'record_changes' => true,
                        '_name' => (string)($device['name'] ?? ''),
                        '_type' => ucfirst((string)($device['device_type'] ?? 'perangkat')),
                    ]);
                    $result['online']++;
                    $result['by_driver'][$driverName]['online']++;
                    continue;
                }
                $stored = $this->writer()->write($userId, $rowId, $system, $interfaces);
                $result['online']++;
                $result['by_driver'][$driverName]['online']++;
                $changeCount = count($stored['events'] ?? []);
                $result['changes'] += $changeCount;
                $result['by_driver'][$driverName]['changes'] += $changeCount;
            } catch (Throwable $e) {
                $result['failed']++;
                $result['by_driver'][$driverName]['failed']++;
                if ($driver instanceof App\Driver\SnmpDeviceDriver) {
                    try {
                        $deviceReachable = $driver->isReachable();
                    } catch (Throwable) {
                        $deviceReachable = false;
                    }
                }
                if (!$snapshotRead) {
                    $this->writer()->recordReadFailure($userId, $rowId, (string)$e);
                }
                $failureEvent = $deviceReachable === false
                    ? $this->writer()->markUnreachable($userId, $rowId, $e->getMessage())
                    : null;
                if ($failureEvent !== null) {
                    $result['changes']++;
                    $result['by_driver'][$driverName]['changes']++;
                }
                $result['errors'][] = [
                    'device_id' => $rowId,
                    'driver_id' => $driverName,
                    'device_name' => (string)($device['name'] ?? ''),
                    'ip_address' => (string)($device['ip_address'] ?? ''),
                    'error' => (string)$e,
                ];
            } finally {
                SyncTargetLock::release($syncLock);
                if ($yieldAfterDevice !== null) $yieldAfterDevice();
            }
        }
        }

        return $result;
    }

    
    public function health(int $userId, int $rowId, ?string $expectedType = null): array
    {
        $device = $this->requireDevice($userId, $rowId, $expectedType);
        return $this->driverForRow($device)->getHealthInfo();
    }

    
    public function traffic(int $userId, int $rowId, string $interfaceName, ?string $expectedType = null): array
    {
        if ($interfaceName === '' || strlen($interfaceName) > 255) throw new InvalidArgumentException('Interface tidak valid.');
        $device = $this->requireDevice($userId, $rowId, $expectedType);
        $cacheDir = cache_path('snmp-device-' . $userId . '-' . $rowId);
        $lockDir = lock_path('snmp-device-' . $userId . '-' . $rowId);
        return (new TrafficService($this->driverForRow($device), $cacheDir, lockDir: $lockDir))->sample($interfaceName);
    }

    
    public function interfaceRates(int $userId, int $rowId, array $ifIndexes, ?string $expectedType = null): array
    {
        $device = $this->requireDevice($userId, $rowId, $expectedType);
        return $this->sampleDirectTraffic($this->driverForRow($device), $ifIndexes);
    }

    
    
    public function sampleDeviceTraffic(int $userId, int $rowId): int
    {
        $selected = $this->db->getDeviceTrafficInterfaces($userId, $rowId);
        if ($selected === []) return 0;

        $indexes = [];
        foreach ($selected as $index) {
            $index = (int)$index;
            if ($index > 0) $indexes[] = $index;
        }
        if ($indexes === []) return 0;

        try {
            $result = $this->interfaceRates($userId, $rowId, $indexes);
        } catch (Throwable $error) {
            error_log((string)$error);
            return 0;
        }
        $rates = is_array($result['rates'] ?? null) ? $result['rates'] : [];
        $samples = [];
        foreach ($selected as $ref => $index) {
            $row = $rates[(string)$index] ?? null;
            if (!is_array($row)) continue;
            $samples[(string)$ref] = ['rx' => (float)($row['rx_bps'] ?? 0), 'tx' => (float)($row['tx_bps'] ?? 0)];
        }
        if ($samples === []) return 0;

        $writer = $this->sampleWriter();
        $written = $writer->writeDevice($userId, $rowId, $samples, date('Y-m-d H:i:00'));
        $writer->pruneDevice($userId, $rowId);
        return $written;
    }

    public function realtime(int $userId, int $rowId, array $ifIndexes, ?string $expectedType = null): array
    {
        $device = $this->requireDevice($userId, $rowId, $expectedType);
        $driver = $this->driverForRow($device);
        $health = $driver->getRealtimeHealthInfo();
        $traffic = $this->sampleDirectTraffic($driver, $ifIndexes);
        $traffic['health'] = $health;
        return $traffic;
    }

    
    private function sampleDirectTraffic(App\Driver\SnmpDeviceDriver $driver, array $ifIndexes): array
    {
        $ifIndexes = array_values(array_unique(array_filter(
            array_map('intval', $ifIndexes),
            static fn(int $value): bool => $value > 0
        )));
        if ($ifIndexes === []) throw new InvalidArgumentException('Daftar index interface kosong.');

        $first = $driver->readAllTrafficCounters($ifIndexes);
        $firstTime = microtime(true);

        usleep(1000000);
        $second = $driver->readAllTrafficCounters($ifIndexes);
        $secondTime = microtime(true);
        $interval = max(0.001, $secondTime - $firstTime);
        $delta = static function (int $previous, int $current, int $bits): int {
            if ($current >= $previous) return $current - $previous;
            return $bits === 32 ? (4294967296 - $previous) + $current : 0;
        };
        $rates = [];
        foreach ($second as $ifIndex => $current) {
            $previous = $first[$ifIndex] ?? null;
            if (!is_array($previous) || (int)$previous['counter_bits'] !== (int)$current['counter_bits']) continue;
            $rates[(string)$ifIndex] = [
                'rx_bps' => round(($delta((int)$previous['rx_octets'], (int)$current['rx_octets'], (int)$current['counter_bits']) * 8) / $interval, 2),
                'tx_bps' => round(($delta((int)$previous['tx_octets'], (int)$current['tx_octets'], (int)$current['counter_bits']) * 8) / $interval, 2),
            ];
        }

        return [
            'ok' => true,
            'ready' => true,
            'cached' => false,
            'rates' => $rates,
            'counters' => $second,
            'interval_seconds' => round($interval, 4),
            'timestamp' => $secondTime,
        ];
    }

    public function delete(int $userId, int $rowId, string $expectedDriverId): bool
    {
        $device=$this->requireDevice($userId,$rowId);
        if (snmp_monitoring_driver($device)!==$expectedDriverId) throw new RuntimeException('Perangkat tidak ditemukan.');
        return $this->db->deleteSnmpDevice($userId, $rowId);
    }

    private function pipelineEnabled(): bool
    {
        return filter_var((string)(getenv('LINNMS_SYNC_PIPELINE') ?: '0'), FILTER_VALIDATE_BOOL);
    }

    
    public function deleteByType(int $userId, int $rowId, string $expectedType): bool
    {
        $this->requireDevice($userId, $rowId, $expectedType);
        return $this->db->deleteSnmpDevice($userId, $rowId);
    }

    
    private function writer(): DeviceSnapshotWriteService
    {
        if ($this->deviceWriter === null) {
            require_once project_path('backend', 'services', 'DeviceSnapshotWriteService.php');
            $this->deviceWriter = new DeviceSnapshotWriteService($this->db);
        }
        return $this->deviceWriter;
    }

    private function configWriter(): DeviceConfigurationWriteService
    {
        if ($this->configWriter === null) {
            require_once project_path('backend', 'services', 'DeviceConfigurationWriteService.php');
            $this->configWriter = new DeviceConfigurationWriteService($this->db);
        }
        return $this->configWriter;
    }

    private function sampleWriter(): TrafficSampleWriteService
    {
        if ($this->sampleWriter === null) {
            require_once project_path('backend', 'services', 'TrafficSampleWriteService.php');
            $this->sampleWriter = new TrafficSampleWriteService($this->db);
        }
        return $this->sampleWriter;
    }

    private function requireDevice(int $userId, int $rowId, ?string $expectedType = null): array
    {
        $device = $this->db->getSnmpDevice($userId, $rowId);
        if ($device === null || ($expectedType !== null && (string)$device['device_type'] !== $expectedType)) {
            throw new RuntimeException('Perangkat tidak ditemukan.');
        }
        return $device;
    }

    private function driverForRow(array $device): App\Driver\SnmpDeviceDriver
    {
        $definition = monitoring_driver_definition(snmp_monitoring_driver($device));
        if (!is_array($definition) || !in_array((string)($definition['handler'] ?? ''), ['mikrotik_snmp', 'switch_snmp'], true)) {
            throw new RuntimeException('Driver perangkat tidak terdaftar.');
        }
        return $this->driver((int)$device['user_id'],
            (string)$definition['device_type'],
            (string)$device['ip_address'],
            trim((string)($device['snmp_community'] ?? '')),
            (int)($device['snmp_port'] ?? 161),
        );
    }

    
    private function readDeviceSnapshot(
        array $device,
        App\Driver\SnmpDeviceDriver $driver,
        bool $allowScheduledDiscovery
    ): array {
        $driverId = (string)device_driver_type($device);
        if (!$this->driverController()->canScheduleDiscovery($driverId)) {
            return [$driver->getSystemInfo(), $driver->listInterfaces()];
        }

        $previousSystem = $this->decodeObject($device['system_json'] ?? null);
        $knownInterfaces = $this->decodeList($device['interfaces_json'] ?? null);
        $discoveredAt = strtotime((string)($previousSystem['_interfaces_discovered_at'] ?? ''));

        $discoveryIntervalSeconds = max(300, $this->db->getSyncTimingSettings((int)$device['user_id'])['discovery_interval_minutes'] * 60);
        $discoveryDue = $knownInterfaces === [] || $discoveredAt === false
            || (time() - $discoveredAt) >= $discoveryIntervalSeconds;

        if ($allowScheduledDiscovery && $discoveryDue) {
            $system = $driver->getSystemInfo();
            $interfaces = $driver->listInterfaces();
            $system['_interfaces_discovered_at'] = date('Y-m-d H:i:s');
            return [$system, $interfaces];
        }

        if ($knownInterfaces === []) {
            $system = $driver->getSystemInfo();
            $interfaces = $driver->listInterfaces();
            $system['_interfaces_discovered_at'] = date('Y-m-d H:i:s');
            return [$system, $interfaces];
        }

        $interfaces = $this->driverController()->refreshKnownInterfaces($driverId, $driver, $knownInterfaces);

        return [
            $this->driverController()->getSystemInfoByGet($driverId, $driver, $previousSystem),
            $interfaces,
        ];
    }

    private function readMonitoringSnapshot(
        array $device,
        App\Driver\SnmpDeviceDriver $driver
    ): array {
        $system = $this->decodeObject($device['system_json'] ?? null);
        $interfaces = $this->decodeList($device['interfaces_json'] ?? null);

        $discoveredAt = strtotime((string)($system['_interfaces_discovered_at'] ?? ''));
        $discoveryIntervalSeconds = max(300, $this->db->getSyncTimingSettings((int)$device['user_id'])['discovery_interval_minutes'] * 60);
        if ($interfaces === [] || $discoveredAt === false || (time() - $discoveredAt) >= $discoveryIntervalSeconds) {
            $system = $driver->getSystemInfo();
            $interfaces = $driver->listInterfaces();
            $system['_interfaces_discovered_at'] = date('Y-m-d H:i:s');
            return [$system, $interfaces];
        }

        try {
            return [$system, $driver->refreshKnownInterfaceStatusByGet($interfaces)];
        } catch (Throwable $error) {

            $system = $driver->getSystemInfo();
            $interfaces = $driver->listInterfaces();
            $system['_interfaces_discovered_at'] = date('Y-m-d H:i:s');
            $system['_interfaces_refreshed_reason'] = $error->getMessage();
            return [$system, $interfaces];
        }
    }

    private function driverController(): DriverController
    {
        return new DriverController();
    }

    private function driver(
        int $userId,
        string $type,
        string $host,
        string $community,
        int $snmpPort = 161,
        int $minimumRetries = 0
    ): App\Driver\SnmpDeviceDriver
    {
        $settings = is_array($this->config['snmp_devices'] ?? null) ? $this->config['snmp_devices'] : [];

        $timeoutMs = $this->db->getSyncTimingSettings($userId)['snmp_request_timeout_ms'];
        if ($timeoutMs > 0) $settings['timeout_us'] = $timeoutMs * 1000;
        return SnmpDriverFactory::create(
            $type,
            $host,
            $community,
            max(100000, (int)($settings['timeout_us'] ?? 500000)),
            max($minimumRetries, (int)($settings['retries'] ?? 0)),
            $snmpPort,
        );
    }

    
    private function decodeObject(mixed $json): array
    {
        $value = json_decode((string)$json, true);
        return is_array($value) ? $value : [];
    }

    
    private function decodeList(mixed $json): array
    {
        $value = json_decode((string)$json, true);
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
