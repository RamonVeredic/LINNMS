<?php

declare(strict_types=1);

final class DeviceManagementActionService
{
    public function __construct(
        private LinnmsRepository $db,
        private SnmpDeviceService $devices,
        private SnmpOltService $olts
    ) {
    }

    public function execute(int $userId, string $action, array $data): array
    {
        $user = $this->db->getUser($userId);
        if (!is_array($user) || (int)($user['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'code' => 'forbidden', 'data' => []];
        }
        try {
            return match ($action) {
                'add_mikrotik', 'add_switch' => $this->add($userId, $action, $data),
                'delete_device' => $this->delete($userId, $data),
                'update_device' => $this->update($userId, $data),
                default => ['ok' => false, 'code' => 'unsupported', 'data' => []],
            };
        } catch (InvalidArgumentException $error) {
            return ['ok' => false, 'code' => 'validation', 'data' => ['public_message' => $error->getMessage()]];
        } catch (Throwable $error) {
            $this->log($userId, 'LINNMS APP', $action . '_error', (string)$error);
            return ['ok' => false, 'code' => 'internal', 'data' => []];
        }
    }

    private function add(int $userId, string $action, array $data): array
    {
        $driverId = trim((string)($data['driver_id'] ?? ''));
        $definition = monitoring_driver_definition($driverId);
        $type = $action === 'add_mikrotik' ? 'mikrotik' : 'cisco_catalyst_2960s';
        $handler = $action === 'add_mikrotik' ? 'mikrotik_snmp' : 'switch_snmp';
        if (!is_array($definition) || (string)($definition['handler'] ?? '') !== $handler
            || (string)($definition['device_type'] ?? '') !== $type) {
            throw new InvalidArgumentException('Jenis perangkat tidak valid.');
        }
        $created = $this->devices->create($userId, $driverId, $data);
        return ['ok' => true, 'code' => 'ok', 'data' => ['id' => (int)($created['id'] ?? 0)]];
    }

    private function delete(int $userId, array $data): array
    {
        $rowId = valid_positive_id($data['device_id'] ?? null);
        if ($rowId === null) return ['ok' => false, 'code' => 'not_found', 'data' => []];
        $device = $this->db->getOwnedDeviceIdentity($userId, $rowId);
        if (!is_array($device)) return ['ok' => false, 'code' => 'not_found', 'data' => []];

        $type = (string)($device['device_type'] ?? '');
        $source = (string)($device['source'] ?? '');
        if ($type === 'olt') {
            if (!in_array($source, ['api', 'snmp'], true)) {
                return ['ok' => false, 'code' => 'unsupported', 'data' => []];
            }
            $deleted = match ($source) {
                'api' => $this->db->hideApiOltByRowId($userId, $rowId),
                'snmp' => $this->db->deleteOltSnapshotByRowId($userId, $rowId, 'snmp'),
            };
        } elseif ($source === 'snmp' && $type !== '') {
            $deleted = $this->devices->deleteByType($userId, $rowId, $type);
        } else {
            return ['ok' => false, 'code' => 'unsupported', 'data' => []];
        }

        return $deleted
            ? ['ok' => true, 'code' => 'ok', 'data' => []]
            : ['ok' => false, 'code' => 'internal', 'data' => []];
    }

    private function update(int $userId, array $data): array
    {
        $rowId = valid_positive_id($data['device_row_id'] ?? null);
        $row = $rowId !== null ? $this->db->getEditableDevice($userId, $rowId) : null;
        if (!is_array($row)) return ['ok' => false, 'code' => 'not_found', 'data' => []];
        $kind = device_edit_kind($row);
        if ($kind === 'olt_api') return ['ok' => false, 'code' => 'unsupported', 'data' => []];
        $driverId = (string)device_driver_type($row);
        $data['driver_id'] = $driverId;
        if ($kind === 'olt_snmp') {
            $result = $this->olts->update($userId, $rowId, $data);
            if (empty($result['ok'])) {
                $detail = raw_result_error($result, 'update_device');
                $this->log($userId, 'OLT', 'update_device_error', $detail);
                $public = (string)($result['message'] ?? 'OLT gagal diperbarui.');
                $errors = (array)($result['errors'] ?? []);
                if ($errors !== []) $public = (string)reset($errors);
                if (!in_array($public, [validation_required_message('Nama perangkat'), validation_ip_message(),
                    validation_required_message('Community'), validation_port_message(),
                    validation_required_message('Username'), validation_required_message('Password'),
                    'Driver OLT SNMP belum didukung.', 'Driver OLT ini tidak diizinkan atau sedang dinonaktifkan.',
                    'OLT tidak ditemukan.', 'Perangkat tidak dapat dijangkau.', 'Tipe perangkat tidak sesuai.',
                    'Gagal menyimpan perubahan.'], true)) {
                    $public = 'OLT gagal diperbarui.';
                }
                $code = match ($public) {
                    'OLT tidak ditemukan.' => 'not_found',
                    'Perangkat tidak dapat dijangkau.' => 'device_unreachable',
                    'Driver OLT SNMP belum didukung.' => 'unsupported',
                    'Driver OLT ini tidak diizinkan atau sedang dinonaktifkan.' => 'forbidden',
                    'Gagal menyimpan perubahan.', 'OLT gagal diperbarui.' => 'internal',
                    default => 'validation',
                };
                return ['ok' => false, 'code' => $code, 'data' => ['public_message' => $public]];
            }
            return ['ok' => true, 'code' => 'ok', 'data' => ['public_message' => 'Berhasil menyimpan perubahan']];
        }
        if ($kind !== 'snmp_device') {
            return ['ok' => false, 'code' => 'unsupported', 'data' => []];
        }
        $this->devices->update($userId, $rowId, $driverId, $data);
        return ['ok' => true, 'code' => 'ok', 'data' => []];
    }

    private function log(int $userId, string $area, string $action, string $detail): void
    {
        try {
            $this->db->logApplication($userId, $area, $action, 'error', $detail);
        } catch (Throwable $error) {
            error_log('[LINNMS] Device management log: ' . $error->getMessage());
        }
    }
}
