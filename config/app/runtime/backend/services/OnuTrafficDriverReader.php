<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/driver/Controller.php';

final class OnuTrafficDriverReader
{
    private array $drivers = [];

    public function __construct(private LinnmsRepository $db, private array $config)
    {
    }

    public function supports(int $userId, int $onuRowId): bool
    {
        $target = $this->target($userId, $onuRowId);
        if ($target === null) return false;
        try {
            return $this->driverFor($userId, $target['olt']) instanceof OltOnuTrafficAware;
        } catch (Throwable) {
            return false;
        }
    }

    public function isActive(int $userId, int $onuRowId): bool
    {
        return $this->target($userId, $onuRowId) !== null;
    }

    public function sample(int $userId, int $onuRowId): ?array
    {
        $target = $this->target($userId, $onuRowId);
        if ($target === null) return null;
        try {
            $driver = $this->driverFor($userId, $target['olt']);
            if (!$driver instanceof OltOnuTrafficAware) return null;
            $samples = $driver->readOnuTrafficBps([
                ['pon' => $target['pon'], 'onu_id' => $target['onu_id']],
            ]);
            $sample = $samples[$target['pon']][$target['onu_id']] ?? null;
            if (!is_array($sample) || !is_numeric($sample['rx_bps'] ?? null)
                || !is_numeric($sample['tx_bps'] ?? null)) return null;
            return ['at' => time(), 'rx_bps' => (float)$sample['rx_bps'], 'tx_bps' => (float)$sample['tx_bps']];
        } catch (Throwable $error) {
            error_log('ONU live traffic sample failed: ' . $error->getMessage());
            unset($this->drivers[$userId . ':' . (int)($target['olt']['id'] ?? 0)]);
            return null;
        }
    }

    private function target(int $userId, int $onuRowId): ?array
    {
        $onu = $this->db->getOnuByRowId($userId, $onuRowId);
        if (!is_array($onu) || (int)($onu['is_present'] ?? 0) !== 1
            || state_key($onu['onu_state'] ?? null) !== 'online'
            || strtoupper(trim((string)($onu['driver_status'] ?? ''))) === 'DOWN') return null;
        $ref = (string)($onu['onu_index'] ?? '');
        if (preg_match('~^(\d+/\d+/\d+):(\d+)$~D', $ref, $parts) !== 1) return null;
        $olt = $this->db->getOltByRowId($userId, (int)($onu['olt_row_id'] ?? 0));
        if (!is_array($olt)) return null;
        $ponActive = true;
        foreach ($this->db->getOltPonSnapshot($userId, (int)$olt['id']) as $pon) {
            if (!is_array($pon)) continue;
            $ponRef = (string)preg_replace('~^(?:gpon|epon)(?:-olt)?[_/]?~i', '', trim((string)($pon['port_ref'] ?? '')));
            if ($ponRef !== $parts[1]) continue;
            $ponStatus = trim((string)($pon['link_status'] ?? $pon['status'] ?? ''));
            $ponActive = $ponStatus === '' || state_key($ponStatus) !== 'offline';
            break;
        }
        if (!$ponActive) return null;
        $driverId = trim((string)device_driver_type($olt));
        if ($driverId === '' || !olt_driver_support_enabled($this->db->getOltDriverSupportFlags(), $driverId)) return null;
        return ['olt' => $olt, 'pon' => $parts[1], 'onu_id' => (int)$parts[2]];
    }

    private function driverFor(int $userId, array $olt): OltSnmpDriver
    {
        $key = $userId . ':' . (int)($olt['id'] ?? 0);
        if (isset($this->drivers[$key]) && time() - $this->drivers[$key]['created'] <= 60) {
            return $this->drivers[$key]['driver'];
        }
        $settings = (array)($this->config['snmp_devices'] ?? []);
        $timeoutMs = $this->db->getSyncTimingSettings($userId)['snmp_request_timeout_ms'];
        if ($timeoutMs > 0) $settings['timeout_us'] = $timeoutMs * 1000;
        $driver = (new \App\Driver\Controller())->createSnmp(
            (string)device_driver_type($olt),
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
        $this->drivers[$key] = ['created' => time(), 'driver' => $driver];
        return $driver;
    }
}
