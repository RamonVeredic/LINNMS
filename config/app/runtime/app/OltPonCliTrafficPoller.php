<?php

declare(strict_types=1);


final class OltPonCliTrafficPoller
{
    
    private array $lastSampleAt = [];

    
    private int $sampleIntervalSeconds = 180;
    private int $lastGraphReadAt = 0;
    
    private array $skipLogAt = [];
    
    private array $pendingSkips = [];
    private array $failUntil = [];
    private ?TrafficSampleWriteService $sampleWriter = null;

    public function __construct(
        private readonly LinnmsRepository $repository,
        private readonly CredentialCipher $cipher,
        private readonly int $timeoutMs = 20000,
    ) {
    }

    
    public function refresh(): int
    {
        $this->sampleIntervalSeconds = $this->repository->getOltGraphIntervalMinutes() * 60;
        $devices = $this->discoverDevices();
        $this->logSkippedDevices();

        $now = time();
        
        
        $this->repository->saveOltPonCliTrafficLive([], $now, array_map('intval', array_keys($devices)));

        if ($devices === []) return 0;

        $demand = $this->repository->oltPonCliTrafficDemandActive();
        
        
        if (!$demand) {
            if ($now - $this->lastGraphReadAt < 60) return 0;
            $sampleDue = false;
            foreach ($devices as $deviceId => $device) {
                $userId = (int)($device['user_id'] ?? 0);
                if ($userId > 0 && $now - $this->repository->getLatestOltPonTrafficSampleAt($userId, (int)$deviceId) >= $this->sampleIntervalSeconds) {
                    $sampleDue = true;
                    break;
                }
            }
            if (!$sampleDue) return 0;
        }

        $fresh = [];
        foreach ($devices as $deviceId => $device) {
            if (!$demand && $now - $this->repository->getLatestOltPonTrafficSampleAt((int)$device['user_id'], (int)$deviceId) < $this->sampleIntervalSeconds) {
                continue;
            }
            
            $points = $this->repository->getOltPonCliTrafficLive((int)$deviceId);
            if (!is_array($points)) $points = [];
            $updated = false;

            foreach ((array)($device['targets'] ?? []) as $target) {
                if (!is_array($target)) continue;
                $entry = $this->readDevice($deviceId, $device, [$target]);
                
                if ($entry === null) {
                    $this->failUntil[$deviceId] = time() + 300;
                    break;
                }
                $updated = true;
                foreach ((array)($entry['points'] ?? []) as $ref => $row) $points[(string)$ref] = $row;
                
                $this->repository->saveOltPonCliTrafficLive(
                    [$deviceId => ['at' => time(), 'points' => $points]],
                    time()
                );
            }

            if ($updated) $fresh[$deviceId] = ['at' => time(), 'points' => $points];
        }
        if ($fresh !== []) {
            $this->lastGraphReadAt = $now;
            $this->persistSamples($devices, $fresh);
        }
        return count($fresh);
    }

    
    private function persistSamples(array $devices, array $fresh): void
    {
        $now = time();
        foreach ($fresh as $deviceId => $entry) {
            $device = $devices[$deviceId] ?? null;
            if (!is_array($device)) continue;
            if (($now - ($this->lastSampleAt[$deviceId] ?? 0)) < $this->sampleIntervalSeconds) continue;

            $userId = (int)($device['user_id'] ?? 0);
            if ($userId < 1) continue;
            if ($now - $this->repository->getLatestOltPonTrafficSampleAt($userId, (int)$deviceId) < $this->sampleIntervalSeconds) continue;
            $enabled = $this->repository->getDeviceTrafficInterfaces($userId, (int)$deviceId);
            if ($enabled === []) continue;

            $points = is_array($entry['points'] ?? null) ? $entry['points'] : [];
            $samples = [];
            foreach ($points as $ref => $row) {
                $ref = (string)$ref;
                if (!is_array($row) || !isset($enabled[$ref])) continue;
                $samples[$ref] = [
                    'rx_bps' => (float)($row['rx_bps'] ?? 0),
                    'tx_bps' => (float)($row['tx_bps'] ?? 0),
                ];
            }
            if ($samples === []) continue;

            try {
                $this->sampleWriter()->writeOltPon($userId, (int)$deviceId, $samples, date('Y-m-d H:i:00'));
                $this->sampleWriter()->pruneOltPon($userId, (int)$deviceId);
                $this->lastSampleAt[$deviceId] = $now;
            } catch (Throwable $error) {
                error_log('OLT PON CLI traffic sample: ' . $error->getMessage());
            }
        }
    }

    private function sampleWriter(): TrafficSampleWriteService
    {
        if ($this->sampleWriter === null) {
            require_once project_path('backend', 'services', 'TrafficSampleWriteService.php');
            $this->sampleWriter = new TrafficSampleWriteService($this->repository);
        }
        return $this->sampleWriter;
    }

    public function close(): void
    {
        // CLI sessions are owned by the shared CLI broker.
    }

    
    
    private function logSkippedDevices(): void
    {
        if ($this->pendingSkips === []) return;
        $now = time();
        foreach ($this->pendingSkips as $deviceId => $info) {
            $reason = (string)($info['reason'] ?? 'tidak diketahui');
            $userId = (int)($info['user_id'] ?? 0);
            $key = $deviceId . '|' . $reason;
            if (($now - ($this->skipLogAt[$key] ?? 0)) >= 300) {
                $this->skipLogAt[$key] = $now;
                if ($userId > 0) {
                    try {
                        $this->repository->logApplication($userId, 'OLT SNMP', 'olt_cli_live_skip', 'warning',
                            sprintf('Live OLT CLI device #%d dilewati: %s.', $deviceId, $reason));
                    } catch (Throwable) {}
                }
            }
        }
        $this->pendingSkips = [];
    }

    private function discoverDevices(): array
    {
        $devices = [];
        $rows = $this->repository->getOltCliTrafficDeviceRows();
        if ($rows === []) return [];
        $supportFlags = $this->repository->getOltDriverSupportFlags();
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $driverId = (string)device_driver_type($row);
            $definition = monitoring_driver_definition($driverId);
            if (($definition['pon_traffic_source'] ?? '') !== 'cli') continue;
            if (!olt_driver_support_enabled($supportFlags, $driverId)) continue;

            $deviceId = (int)($row['id'] ?? 0);
            $userId = (int)($row['user_id'] ?? 0);
            $host = trim((string)($row['ip_address'] ?? ''));
            $username = trim((string)($row['username'] ?? ''));
            $encrypted = trim((string)($row['password_enc'] ?? ''));
            if ($deviceId < 1 || $userId < 1) continue;
            if (($this->failUntil[$deviceId] ?? 0) > time()) continue;
            $targets = [];
            foreach ($this->repository->getSelectedOltCliTrafficPons($userId, $deviceId) as $pon) {
                if (!is_array($pon)) continue;
                $ref = trim((string)($pon['port_ref'] ?? ''));
                $ponNumber = (int)($pon['pon_number'] ?? 0);
                if ($ref === '' || $ponNumber < 1) continue;
                $targets[] = ['ref' => $ref, 'olt' => self::oltIndexFromRef($ref), 'pon' => $ponNumber];
            }
            if ($targets === []) continue;
            if ($host === '' || $username === '' || $encrypted === '') {
                $this->pendingSkips[$deviceId] = ['user_id' => $userId, 'reason' => 'kredensial CLI tidak lengkap'];
                continue;
            }

            try {
                $password = $this->cipher->decrypt($encrypted);
            } catch (Throwable) {
                $this->pendingSkips[$deviceId] = ['user_id' => $userId, 'reason' => 'password CLI gagal didekripsi'];
                continue;
            }
            if (trim($password) === '') {
                $this->pendingSkips[$deviceId] = ['user_id' => $userId, 'reason' => 'password CLI kosong'];
                continue;
            }

            
            
            
            $devices[$deviceId] = [
                'user_id' => $userId,
                'driver_id' => (string)device_driver_type($row),
                'host' => $host,
                'port' => max(1, min(65535, (int)($row['ports'] ?? 23))),
                'username' => $username,
                'password' => $password,
                'targets' => $targets,
            ];
        }
        return $devices;
    }

    private function readDevice(int $deviceId, array $device, ?array $targets = null): ?array
    {
        $current = $this->repository->getOltByRowId((int)$device['user_id'], $deviceId);
        if (!is_array($current) || (int)($current['hybrid_status'] ?? 0) !== 1) return null;
        $useTargets = $targets !== null ? array_values($targets) : (array)$device['targets'];
        $perCommandTimeout = max(1500, (int)floor($this->timeoutMs / max(1, count($useTargets))));
        $result = OltTerminalBrokerClient::request([
            'method' => 'read-pon-cli-traffic',
            'olt' => [
                'driver_id' => (string)($device['driver_id'] ?? device_driver_type($device)),
                'device_id' => $deviceId,
                'user_id' => (int)$device['user_id'],
                'host' => (string)$device['host'],
                'port' => (int)$device['port'],
                'username' => (string)$device['username'],
                'password' => (string)$device['password'],
            ],
            'targets' => $useTargets,
            'command_timeout_ms' => $perCommandTimeout,
        ]);
        if (empty($result['ok'])) {
            error_log('OLT PON CLI traffic poller: ' . (string)($result['message'] ?? 'CLI driver tidak siap.'));
            return null;
        }
        if (!empty($result['busy'])) return null;
        $rates = is_array($result['rates'] ?? null) ? $result['rates'] : [];

        $points = [];
        foreach ($useTargets as $target) {
            $row = $rates[(string)$target['ref']] ?? null;
            if (!is_array($row)) continue;
            $points[(string)$target['ref']] = [
                'supported' => true,
                'rx_bps' => (float)($row['rx_bps'] ?? 0),
                'tx_bps' => (float)($row['tx_bps'] ?? 0),
            ];
        }
        if ($points === []) {
            return null;
        }

        return ['at' => time(), 'points' => $points];
    }

    
    private static function oltIndexFromRef(string $portRef): int
    {
        if (preg_match('~^[0-9]+/([0-9]+)~', $portRef, $match) === 1) {
            $index = (int)$match[1];
            if ($index > 0) return $index;
        }
        return 1;
    }
}
