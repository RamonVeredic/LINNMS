<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/driver/Controller.php';

final class PonTrafficLiveReader
{
    private ?TrafficSampleWriteService $sampleWriter = null;

    public function __construct(private LinnmsRepository $db, private array $config)
    {
    }

    public function supports(int $userId, int $oltRowId): bool
    {
        $olt = $this->db->getOltByRowId($userId, $oltRowId);
        if (!is_array($olt) || !monitoring_row_uses_handler($olt, 'snmp_olt')) return false;
        $definition = monitoring_driver_definition((string)device_driver_type($olt));
        if (($definition['pon_traffic_source'] ?? '') === 'cli') return true;
        try {
            return $this->driver($userId, $olt) instanceof OltPonTrafficAware;
        } catch (Throwable) {
            return false;
        }
    }

    public function read(int $userId, int $oltRowId): array
    {
        $olt = $this->db->getOltByRowId($userId, $oltRowId);
        if (!is_array($olt) || !monitoring_row_uses_handler($olt, 'snmp_olt')) return [];
        $deviceId = (int)($olt['id'] ?? 0);
        $selected = $this->db->getDeviceTrafficInterfaces($userId, $deviceId);
        if ($selected === []) {
            return ['live' => [], 'series' => [], 'average' => [], 'roles' => [], 'updated_at' => 0];
        }
        $definition = monitoring_driver_definition((string)device_driver_type($olt));
        if (($definition['pon_traffic_source'] ?? '') === 'cli') {
            $this->db->touchOltPonCliTrafficDemand();
            $rates = $this->db->getOltPonCliTrafficLive($deviceId);
            $live = $this->filterSelected($selected, $rates);
            $this->recordSamples($userId, $deviceId, $live);
            $history = $this->db->getOltPonTrafficSeries($userId, $deviceId, 86400);
            return [
                'live' => $live,
                'series' => $history['series'] ?? [],
                'average' => $history['average'] ?? [],
                'roles' => $this->roles($userId, $deviceId, (array)($history['series'] ?? [])),
                'updated_at' => $this->db->getOltPonCliTrafficLiveUpdatedAt($deviceId),
                'freshness_seconds' => 360,
                'poll_seconds' => 6,
            ];
        }

        $live = [];
        try {
            $driver = $this->driver($userId, $olt);
            $rates = $driver instanceof OltPonTrafficAware
                ? $driver->readPonTrafficBps(2, false, $selected)
                : $this->db->getOltPonCliTrafficLive($deviceId);
            $live = $this->filterSelected($selected, $rates);
        } catch (Throwable) {
            $live = [];
        }
        $this->recordSamples($userId, $deviceId, $live);
        $history = $this->db->getOltPonTrafficSeries($userId, $deviceId, 86400);
        return [
            'live' => $live,
            'series' => $history['series'] ?? [],
            'average' => $history['average'] ?? [],
            'roles' => $this->roles($userId, $deviceId, (array)($history['series'] ?? [])),
            'freshness_seconds' => 30,
            'poll_seconds' => 3,
        ];
    }

    private function driver(int $userId, array $olt): OltSnmpDriver
    {
        $settings = is_array($this->config['snmp_devices'] ?? null) ? $this->config['snmp_devices'] : [];
        $timeoutMs = $this->db->getSyncTimingSettings($userId)['snmp_request_timeout_ms'];
        if ($timeoutMs > 0) $settings['timeout_us'] = $timeoutMs * 1000;
        $controller = new \App\Driver\Controller();
        $driverId = (string)device_driver_type($olt);
        $driver = $controller->createSnmp(
            $driverId,
            (string)$olt['ip_address'],
            (string)($olt['snmp_community'] ?? ''),
            (int)($olt['snmp_port'] ?? 161),
            [
                'row_id' => (int)($olt['id'] ?? 0),
                'timeout_us' => $settings['timeout_us'] ?? 1500000,
                'retries' => $settings['retries'] ?? 1,
            ]
        );
        if (!$driver instanceof OltSnmpDriver) {
            throw new RuntimeException('Driver OLT tidak sesuai.');
        }
        return $driver;
    }

    private function filterSelected(array $selected, array $rates): array
    {
        if ($rates === [] || $selected === []) return [];
        $normalize = static function (string $ref): string {
            $ref = (string)preg_replace('~^(?:gpon|epon)(?:-olt)?[_/]?~i', '', trim($ref));
            return str_replace('_', '/', $ref);
        };
        $wanted = [];
        foreach (array_keys($selected) as $ref) $wanted[$normalize((string)$ref)] = true;
        $result = [];
        foreach ($rates as $ref => $row) {
            if (isset($wanted[$normalize((string)$ref)])) $result[$ref] = $row;
        }
        return $result;
    }

    private function roles(int $userId, int $deviceId, array $series): array
    {
        $uplinks = [];
        try {
            foreach ($this->db->getOltPorts($userId, $deviceId, 'uplink') as $row) {
                $ref = trim((string)($row['port_ref'] ?? ''));
                if ($ref !== '') $uplinks[$this->normalize($ref)] = true;
            }
        } catch (Throwable) {
        }
        $roles = [];
        foreach (array_keys($series) as $ref) {
            $roles[(string)$ref] = isset($uplinks[$this->normalize((string)$ref)]) ? 'uplink' : 'pon';
        }
        return $roles;
    }

    private function normalize(string $ref): string
    {
        $ref = (string)preg_replace('~^(?:gpon|epon)(?:-olt)?[_/]?~i', '', trim($ref));
        return str_replace('_', '/', $ref);
    }

    private function recordSamples(int $userId, int $deviceId, array $live): void
    {
        $samples = [];
        foreach ($live as $ref => $row) {
            if (!is_array($row) || !is_numeric($row['rx_bps'] ?? null) || !is_numeric($row['tx_bps'] ?? null)) continue;
            $samples[(string)$ref] = [
                'rx_bps' => max(0.0, (float)$row['rx_bps']),
                'tx_bps' => max(0.0, (float)$row['tx_bps']),
            ];
        }
        if ($samples === []) return;
        try {
            $this->sampleWriter()->writeOltPon($userId, $deviceId, $samples, date('Y-m-d H:i:s'));
        } catch (Throwable $error) {
            error_log('PON traffic sample failed: ' . $error->getMessage());
        }
    }

    private function sampleWriter(): TrafficSampleWriteService
    {
        if ($this->sampleWriter === null) {
            require_once dirname(__DIR__) . '/services/TrafficSampleWriteService.php';
            $this->sampleWriter = new TrafficSampleWriteService($this->db);
        }
        return $this->sampleWriter;
    }
}
