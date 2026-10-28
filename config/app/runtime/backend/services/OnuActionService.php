<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/driver/Controller.php';

final class OnuActionService
{
    private ?OnuSnapshotWriteService $onuWriter = null;

    public function __construct(
        private LinnmsRepository $db,
        private SnmpOltService $snmp,
        private OnuBackupService $backups
    ) {
    }

    public function execute(int $userId, string $action, array $data): array
    {
        $user = $this->db->getUser($userId);
        if (!is_array($user) || (int)($user['is_active'] ?? 0) !== 1) return ['ok' => false, 'code' => 'forbidden'];
        try {
            return match ($action) {
                'export_onu_backup' => ['ok' => true, 'code' => 'ok', 'data' => ['rows' => $this->backups->exportRows($userId)]],
                'preview_onu_backup' => $this->preview($userId, $data),
                'confirm_onu_backup' => $this->confirm($userId, $data),
                'update_onu_name' => $this->updateName($userId, $data),
                'save_onu_control' => $this->saveControl($userId, $data),
                'onu_operation' => $this->operation($userId, $data),
                'delete_onu' => $this->delete($userId, $data),
                default => ['ok' => false, 'code' => 'unsupported'],
            };
        } catch (Throwable $error) {
            return $this->failure($userId, $action, 'exception', (string)$error, 'internal');
        }
    }

    private function preview(int $userId, array $data): array
    {
        $content = base64_decode((string)($data['content_base64'] ?? ''), true);
        if (!is_string($content)) return $this->failure($userId, 'preview_onu_backup', 'decode', 'File CSV tidak valid.', 'validation');
        try {
            $records = $this->backups->parseContent((string)($data['filename'] ?? ''), $content);
            $analysis = $this->backups->analyze($userId, $records);
            return ['ok' => true, 'code' => 'ok', 'data' => [
                'records' => $records,
                'summary' => $analysis['summary'],
                'rows' => $analysis['rows'],
            ]];
        } catch (InvalidArgumentException $error) {
            return $this->failure($userId, 'preview_onu_backup', 'validation', (string)$error, 'validation');
        }
    }

    private function confirm(int $userId, array $data): array
    {
        $records = $data['records'] ?? null;
        if (!is_array($records)) return $this->failure($userId, 'confirm_onu_backup', 'records', 'Preview import tidak valid.', 'validation');
        $analysis = $this->backups->analyze($userId, $records);
        $updated = $this->onuWriter()->restoreBackupRows($userId, $analysis['updates']);
        return ['ok' => true, 'code' => 'ok', 'data' => ['updated' => $updated]];
    }

    private function onu(int $userId, string $action, array $data): array
    {
        $rowId = valid_positive_id($data['onu_row_id'] ?? null);
        if ($rowId === null) return ['ok' => false, 'code' => 'validation'];
        $onu = $this->db->getOnuByRowId($userId, $rowId);
        if (!is_array($onu)) return ['ok' => false, 'code' => 'not_found'];
        $postedOltId = (int)($data['olt_device_id'] ?? 0);
        $actualOltId = (int)($onu['olt_device_id'] ?? 0);
        if ($postedOltId > 0 && $actualOltId > 0 && $postedOltId !== $actualOltId) {
            return $this->failure($userId, $action, 'device_identity', 'Aksi dikirim untuk perangkat yang berbeda dari ONU ini.', 'forbidden');
        }
        $postedDriver = trim((string)($data['driver_id'] ?? ''));
        $actualDriver = trim((string)device_driver_type($onu));
        if ($postedDriver !== '' && $actualDriver !== '' && strcasecmp($postedDriver, $actualDriver) !== 0) {
            return $this->failure($userId, $action, 'driver_identity', 'Driver aksi tidak cocok dengan driver ONU ini.', 'forbidden');
        }
        return ['ok' => true, 'code' => 'ok', 'data' => ['onu' => $onu, 'row_id' => $rowId]];
    }

    private function updateName(int $userId, array $data): array
    {
        $identity = $this->onu($userId, 'update_onu_name', $data);
        if (empty($identity['ok'])) return $identity;
        $rowId = (int)$identity['data']['row_id'];
        $onu = $identity['data']['onu'];
        $name = trim((string)($data['onu_name'] ?? ''));
        if (monitoring_row_uses_handler($onu, 'snmp_olt')) {
            $result = $this->snmp->renameOnu($userId, $rowId, $name);
            if (empty($result['ok'])) return $this->failure($userId, 'update_onu_name', 'rename',
                (string)($result['message'] ?? 'Perubahan ONU gagal.'), 'device_rejected');
            return ['ok' => true, 'code' => 'ok'];
        }
        if (!$this->onuWriter()->writeName($userId, $rowId, $name)) {
            return $this->failure($userId, 'update_onu_name', 'save_local_name', 'Nama ONU tidak dapat disimpan.', 'internal');
        }
        return ['ok' => false, 'code' => 'unsupported'];
    }

    private function saveControl(int $userId, array $data): array
    {
        $identity = $this->onu($userId, 'save_onu_control', $data);
        if (empty($identity['ok'])) return $identity;
        $rowId = (int)$identity['data']['row_id'];
        $onu = $identity['data']['onu'];
        $nameProvided = array_key_exists('onu_name', $data);
        $locationProvided = array_key_exists('onu_location', $data);
        $authProvided = array_key_exists('auth_mode', $data);
        $name = $nameProvided ? trim((string)$data['onu_name']) : null;
        $location = $locationProvided ? onu_location_value($data['onu_location']) : null;
        $authMode = $authProvided ? trim((string)$data['auth_mode']) : null;
        if ($authProvided && !in_array($authMode, ['Undef', 'Pass', 'Deny'], true)) {
            return $this->failure($userId, 'save_onu_control', 'validate_auth', 'Status autentikasi ONU tidak valid.', 'validation');
        }
        if (array_key_exists('onu_type', $data)) {
            $result = $this->snmp->updateOnuType($userId, $rowId, trim((string)$data['onu_type']));
            if (empty($result['ok'])) return $this->failure($userId, 'save_onu_control', 'update_type',
                (string)($result['message'] ?? 'ONU Type gagal diubah.'), 'device_rejected');
            return ['ok' => true, 'code' => 'type_updated'];
        }
        $isSnmp = monitoring_row_uses_handler($onu, 'snmp_olt');
        $currentName = display_onu_name($onu['onu_name'] ?? null);
        $currentLocation = onu_location_value($onu['onu_location'] ?? null);
        if ($currentLocation === '') $currentLocation = onu_split_device_label($onu['onu_name'] ?? null)['location'];
        $currentName = in_array($currentName, ['N/A', '—'], true) ? '' : $currentName;
        $nameChanged = $nameProvided && $name !== $currentName;
        $locationChanged = $locationProvided && ($location !== $currentLocation
            || ($location === '' && ($onu['onu_location'] ?? null) !== null));
        $authChanged = $authProvided && strcasecmp($authMode ?? '', trim((string)($onu['auth_mode'] ?? ''))) !== 0;

        $locationResult = ['ok' => true, 'changed' => false];
        if ($locationChanged) $locationResult = $this->onuWriter()->writeLocation($userId, $rowId, $location);
        if ($isSnmp) {
            $nameResult = ['ok' => true];
            if ($nameProvided || $locationProvided) {
                $nameResult = $this->snmp->renameOnu($userId, $rowId, $name ?? $currentName, $location ?? $currentLocation);
            }
            $authResult = ['ok' => true];
            if ($authChanged) $authResult = $this->snmp->updateOnuAuthMode($userId, $rowId, $authMode);
            if (empty($locationResult['ok'])) return $this->failure($userId, 'save_onu_control', 'save_location',
                (string)($locationResult['message'] ?? 'Lokasi ONU gagal disimpan.'), 'internal');
            if (empty($nameResult['ok'])) return $this->failure($userId, 'save_onu_control', 'rename',
                (string)($nameResult['message'] ?? 'Perubahan ONU gagal.'), 'device_rejected');
            if (empty($authResult['ok'])) return $this->failure($userId, 'save_onu_control', 'update_auth',
                (string)($authResult['message'] ?? 'Status autentikasi gagal diubah.'), 'device_rejected');
            if (($nameResult['refreshed'] ?? true) === false || ($authResult['refreshed'] ?? true) === false) {
                return $this->failure($userId, 'save_onu_control', 'refresh_snapshot', 'Data ONU tersimpan tidak diperbarui.', 'conflict');
            }
            $changed = [];
            if ($nameChanged) $changed[] = 'name';
            if ($locationChanged) $changed[] = 'location';
            if ($authChanged) $changed[] = 'auth';
            return ['ok' => true, 'code' => $changed === [] ? 'unchanged' : 'ok', 'data' => ['changed' => $changed]];
        }
        if (empty($locationResult['ok'])) return $this->failure($userId, 'save_onu_control', 'save_location',
            (string)($locationResult['message'] ?? 'Lokasi ONU gagal disimpan.'), 'internal');
        if ($nameChanged && !$this->onuWriter()->writeName($userId, $rowId, $name)) {
            return $this->failure($userId, 'save_onu_control', 'save_local_name', 'Nama ONU tidak dapat disimpan.', 'internal');
        }
        if ($nameChanged || $authChanged) return ['ok' => false, 'code' => 'unsupported'];
        return ['ok' => true, 'code' => $locationChanged ? 'location_updated' : 'unchanged'];
    }

    private function operation(int $userId, array $data): array
    {
        $identity = $this->onu($userId, 'onu_operation', $data);
        if (empty($identity['ok'])) return $identity;
        $rowId = (int)$identity['data']['row_id'];
        $onu = $identity['data']['onu'];
        if (!monitoring_row_uses_handler($onu, 'snmp_olt')) return ['ok' => false, 'code' => 'unsupported'];
        $operation = trim((string)($data['operation'] ?? ''));
        if (!in_array($operation, (new \App\Driver\Controller())->supportedOnuActions((string)device_driver_type($onu)), true)) {
            return ['ok' => false, 'code' => 'unsupported'];
        }
        $result = $operation === 'deleteOp'
            ? $this->snmp->deleteOnu($userId, $rowId)
            : $this->snmp->operateOnu($userId, $rowId, $operation);
        if (empty($result['ok'])) return $this->failure($userId, 'onu_operation', $operation,
            (string)($result['message'] ?? 'Gagal menjalankan tindakan.'), 'device_rejected');
        return ['ok' => true, 'code' => 'ok', 'data' => ['operation' => $operation]];
    }

    private function delete(int $userId, array $data): array
    {
        $identity = $this->onu($userId, 'delete_onu', $data);
        if (empty($identity['ok'])) return $identity;
        if (!monitoring_row_uses_handler($identity['data']['onu'], 'snmp_olt')) {
            return ['ok' => false, 'code' => 'unsupported'];
        }
        if (!in_array('deleteOp', (new \App\Driver\Controller())->supportedOnuActions(
            (string)device_driver_type($identity['data']['onu'])
        ), true)) return ['ok' => false, 'code' => 'unsupported'];
        $result = $this->snmp->deleteOnu($userId, (int)$identity['data']['row_id']);
        if (empty($result['ok'])) return $this->failure($userId, 'delete_onu', 'delete',
            (string)($result['message'] ?? 'Gagal menghapus ONU.'), 'device_rejected');
        return ['ok' => true, 'code' => 'ok', 'data' => ['present_after' => !empty($result['present_after'])]];
    }

    private function failure(int $userId, string $action, string $step, string $message, string $code): array
    {
        try {
            $validation = $code === 'validation';
            $driver = in_array($code, ['device_rejected', 'device_unreachable', 'device_auth_failed'], true);
            if (!$validation) {
                $this->db->logApplication($userId, $driver ? 'Driver' : 'ONU',
                    $action . ($driver ? '_driver' : ''),
                    'error', $message . ' | step=' . $step);
            }
        } catch (Throwable $error) {
            error_log('[LINNMS] ONU action log: ' . $error->getMessage());
        }
        return ['ok' => false, 'code' => $code];
    }

    private function onuWriter(): OnuSnapshotWriteService
    {
        if ($this->onuWriter === null) {
            require_once __DIR__ . '/OnuSnapshotWriteService.php';
            $this->onuWriter = new OnuSnapshotWriteService($this->db);
        }
        return $this->onuWriter;
    }
}
