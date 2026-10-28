<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';

function linnms_onu_action_request(int $uid, string $action, array $data = []): array
{
    try {
        return internal_api_request($uid, 'onu.action', ['action' => $action, 'data' => $data], 45);
    } catch (Throwable $error) {
        error_log('[LINNMS] ONU action API: ' . $error->getMessage());
        return ['ok' => false, 'code' => 'internal'];
    }
}

function linnms_onu_action_feedback(array $result, string $redirect): never
{
    if (linnms_action_reason($result, '') === 'unsupported') {
        flash('warning', 'Aksi belum didukung untuk perangkat ini');
    } else {
        flash('error', linnms_action_code_message($result, 'Gagal menjalankan tindakan.'));
    }
    redirect($redirect);
}

function linnms_onu_action_identity(array $post, int $rowId): array
{
    return [
        'onu_row_id' => $rowId,
        'olt_device_id' => valid_positive_id($post['olt_device_id'] ?? null),
        'driver_id' => trim((string)($post['driver_id'] ?? '')),
    ];
}

function handle_onu_action(string $action): bool
{
    $actions = ['export_onu_backup', 'preview_onu_backup', 'cancel_onu_backup', 'confirm_onu_backup',
        'update_onu_name', 'save_onu_control', 'unsupported_onu_action', 'onu_operation',
        'delete_onu', 'unsupported_olt_port_action'];
    if (!in_array($action, $actions, true)) return false;
    if (!is_logged_in()) redirect('?page=login');
    $uid = current_user_id();

    if ($action === 'cancel_onu_backup') {
        unset($_SESSION['onu_backup_preview']);
        flash('success', 'Import dibatalkan');
        redirect(public_settings_url() . '#onu-backup');
    }

    if ($action === 'unsupported_onu_action') {
        $rowId = valid_positive_id($_POST['onu_row_id'] ?? null);
        flash('warning', 'Aksi belum didukung untuk perangkat ini');
        redirect($rowId === null ? '?page=onus' : '?page=view-onu&id=' . $rowId);
    }
    if ($action === 'unsupported_olt_port_action') {
        $rowId = valid_positive_id($_POST['olt_row_id'] ?? null);
        flash('warning', 'Aksi belum didukung untuk perangkat ini');
        redirect($rowId === null ? '?page=dashboard' : '?page=view-olt&id=' . $rowId . '#olt-pon-config');
    }

    if ($action === 'export_onu_backup') {
        $result = linnms_onu_action_request($uid, $action);
        if (empty($result['ok']) || !is_array($result['data']['rows'] ?? null)) {
            linnms_onu_action_feedback($result, public_settings_url() . '#onu-backup');
        }
        $filename = 'backup-onu-' . date('Y-m-d-His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        $output = fopen('php://output', 'wb');
        if ($output === false) linnms_onu_action_feedback(['code' => 'internal'], public_settings_url() . '#onu-backup');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['mac_address', 'nama', 'alamat'], ',', '"', '');
        foreach ($result['data']['rows'] as $row) {
            if (!is_array($row)) continue;
            fputcsv($output, [$row['mac_address'] ?? '', $row['nama'] ?? '', $row['alamat'] ?? ''], ',', '"', '');
        }
        fclose($output);
        exit;
    }

    if ($action === 'preview_onu_backup') {
        $file = is_array($_FILES['onu_backup_file'] ?? null) ? $_FILES['onu_backup_file'] : [];
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $size = (int)($file['size'] ?? 0);
        $name = trim((string)($file['name'] ?? ''));
        $path = (string)($file['tmp_name'] ?? '');
        if ($error !== UPLOAD_ERR_OK || $size < 1 || $size > OnuBackupService::MAX_FILE_BYTES
            || strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) !== 'csv'
            || $path === '' || !is_uploaded_file($path)) {
            unset($_SESSION['onu_backup_preview']);
            flash('error', 'Gagal menjalankan tindakan.');
            redirect(public_settings_url() . '#onu-backup');
        }
        $content = file_get_contents($path);
        if (!is_string($content) || strlen($content) !== $size) {
            unset($_SESSION['onu_backup_preview']);
            flash('error', 'Gagal menjalankan tindakan.');
            redirect(public_settings_url() . '#onu-backup');
        }
        $result = linnms_onu_action_request($uid, $action, [
            'filename' => $name,
            'content_base64' => base64_encode($content),
        ]);
        if (empty($result['ok'])) {
            unset($_SESSION['onu_backup_preview']);
            linnms_onu_action_feedback($result, public_settings_url() . '#onu-backup');
        }
        $preview = is_array($result['data'] ?? null) ? $result['data'] : [];
        $_SESSION['onu_backup_preview'] = [
            'user_id' => $uid,
            'created_at' => time(),
            'token' => bin2hex(random_bytes(24)),
            'records' => is_array($preview['records'] ?? null) ? $preview['records'] : [],
            'summary' => is_array($preview['summary'] ?? null) ? $preview['summary'] : [],
            'rows' => is_array($preview['rows'] ?? null) ? $preview['rows'] : [],
        ];
        flash('success', 'File berhasil diperiksa');
        redirect(public_settings_url() . '#onu-backup');
    }

    if ($action === 'confirm_onu_backup') {
        $preview = $_SESSION['onu_backup_preview'] ?? null;
        $token = is_array($preview) ? (string)($preview['token'] ?? '') : '';
        if (!is_array($preview) || (int)($preview['user_id'] ?? 0) !== $uid
            || time() - (int)($preview['created_at'] ?? 0) > OnuBackupService::PREVIEW_TTL_SECONDS
            || $token === '' || !hash_equals($token, (string)($_POST['preview_token'] ?? ''))) {
            flash('error', 'Gagal menjalankan tindakan.');
            redirect(public_settings_url() . '#onu-backup');
        }
        $result = linnms_onu_action_request($uid, $action, [
            'records' => is_array($preview['records'] ?? null) ? $preview['records'] : [],
        ]);
        if (empty($result['ok'])) linnms_onu_action_feedback($result, public_settings_url() . '#onu-backup');
        unset($_SESSION['onu_backup_preview']);
        $updated = (int)($result['data']['updated'] ?? 0);
        $GLOBALS['LINNMS_ACTION_LOG'] = [
            'area' => 'Backup ONU',
            'level' => 'success',
            'message' => "Import backup ONU selesai: {$updated} diperbarui.",
        ];
        flash('success', "Import selesai: {$updated} diperbarui");
        redirect(public_settings_url() . '#onu-backup');
    }

    $rowId = valid_positive_id($_POST['onu_row_id'] ?? null);
    if ($rowId === null) {
        flash('error', 'Gagal menjalankan tindakan.');
        redirect('?page=onus');
    }
    $destination = '?page=view-onu&id=' . $rowId;
    $data = linnms_onu_action_identity($_POST, $rowId);

    if ($action === 'update_onu_name') {
        $data['onu_name'] = trim((string)($_POST['onu_name'] ?? ''));
        $result = linnms_onu_action_request($uid, $action, $data);
        if (empty($result['ok'])) linnms_onu_action_feedback($result,
            in_array((string)linnms_action_reason($result, ''), ['not_found', 'forbidden'], true) ? '?page=onus' : $destination);
        flash('success', 'Berhasil mengedit nama');
        redirect($destination);
    }

    if ($action === 'save_onu_control') {
        foreach (['onu_name', 'onu_location', 'auth_mode', 'onu_type'] as $key) {
            if (array_key_exists($key, $_POST)) $data[$key] = (string)$_POST[$key];
        }
        if (isset($data['auth_mode']) && !in_array(trim($data['auth_mode']), ['Undef', 'Pass', 'Deny'], true)) {
            flash('error', 'Gagal menjalankan tindakan.');
            redirect($destination);
        }
        $result = linnms_onu_action_request($uid, $action, $data);
        if (empty($result['ok'])) linnms_onu_action_feedback($result,
            in_array((string)linnms_action_reason($result, ''), ['not_found', 'forbidden'], true) ? '?page=onus' : $destination);
        $code = (string)linnms_action_reason($result, 'ok');
        if ($code === 'type_updated') flash('success', 'Berhasil mengedit ONU type');
        elseif ($code === 'location_updated') flash('success', 'Berhasil mengedit lokasi');
        elseif ($code === 'unchanged') flash('info', 'Tidak ada perubahan');
        else {
            $labels = [];
            foreach ((array)($result['data']['changed'] ?? []) as $field) {
                $labels[] = match ($field) {
                    'name' => 'nama',
                    'location' => 'lokasi',
                    'auth' => 'register',
                    default => '',
                };
            }
            $labels = array_values(array_filter($labels, static fn(string $label): bool => $label !== ''));
            flash('success', 'Berhasil mengedit ' . implode(' dan ', $labels));
        }
        redirect($destination);
    }

    if ($action === 'onu_operation') {
        $operation = trim((string)($_POST['operation'] ?? ''));
        $data['operation'] = $operation;
        $result = linnms_onu_action_request($uid, $action, $data);
        if (empty($result['ok'])) linnms_onu_action_feedback($result,
            in_array((string)linnms_action_reason($result, ''), ['not_found', 'forbidden'], true) ? '?page=onus' : $destination);
        if ($operation === 'deleteOp') {
            flash('success', 'Berhasil menghapus ONU');
            redirect('?page=onus');
        }
        $label = match ($operation) {
            'rebootOp' => 'Berhasil reboot ONU',
            'activeOp' => 'Berhasil mengaktifkan ONU',
            'noactiveOp' => 'Berhasil menonaktifkan ONU',
            'restoreOp' => 'Berhasil memulihkan ONU',
            default => 'Berhasil menjalankan tindakan ONU',
        };
        flash('success', $label);
        redirect($destination);
    }

    $result = linnms_onu_action_request($uid, $action, $data);
    if (empty($result['ok'])) linnms_onu_action_feedback($result,
        in_array((string)linnms_action_reason($result, ''), ['not_found', 'forbidden'], true) ? '?page=onus' : $destination);
    flash('success', 'Berhasil menghapus ONU');
    redirect(!empty($result['data']['present_after']) ? $destination : '?page=onus');
}
