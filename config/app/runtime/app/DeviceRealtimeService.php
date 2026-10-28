<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/driver/Controller.php';

use App\Driver\Controller as DriverController;
use App\Driver\SnmpDeviceDriver;
use App\Service\TrafficService;

final class DeviceRealtimeService
{
    public function __construct(
        private readonly array $config,
        private readonly CredentialCipher $cipher,
    ) {}

    public function snmpTraffic(array $context, string $interfaceName, int $userId, int $rowId): array
    {
        if ($interfaceName === '' || strlen($interfaceName) > 255) {
            throw new InvalidArgumentException('Interface tidak valid.');
        }
        $cacheDir = cache_path('snmp-device-' . $userId . '-' . $rowId);
        $lockDir = lock_path('snmp-device-' . $userId . '-' . $rowId);
        return (new TrafficService($this->snmpDriver($context), $cacheDir, lockDir: $lockDir))->sample($interfaceName);
    }

    public function snmpInterfaceRates(array $context, array $ifIndexes): array
    {
        return $this->sampleDirectTraffic($this->snmpDriver($context), $ifIndexes);
    }

    private function snmpDriver(array $context): SnmpDeviceDriver
    {
        $type = trim((string)($context['device_type'] ?? ''));
        $host = trim((string)($context['ip_address'] ?? ''));
        $snmpPort = (int)($context['snmp_port'] ?? 161);
        $community = trim((string)($context['snmp_community'] ?? ''));
        $controller = new DriverController();
        $driverId = $controller->driverIdForDeviceType($type);
        if ($driverId === null || filter_var($host, FILTER_VALIDATE_IP) === false || $snmpPort < 1 || $snmpPort > 65535 || $community === '') {
            throw new RuntimeException('Konteks perangkat realtime tidak valid.');
        }
        $settings = is_array($this->config['snmp_devices'] ?? null) ? $this->config['snmp_devices'] : [];
        $driver = $controller->createSnmp(
            $driverId,
            $host,
            $community,
            $snmpPort,
            [
                'timeout_us' => max(100000, (int)($settings['timeout_us'] ?? 500000)),
                'retries' => max(0, (int)($settings['retries'] ?? 0)),
            ],
        );
        if (!$driver instanceof SnmpDeviceDriver) throw new RuntimeException('Konteks perangkat realtime tidak valid.');
        return $driver;
    }

    private function sampleDirectTraffic(SnmpDeviceDriver $driver, array $ifIndexes): array
    {
        $ifIndexes = array_values(array_unique(array_filter(
            array_map('intval', $ifIndexes),
            static fn(int $value): bool => $value > 0,
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
}
