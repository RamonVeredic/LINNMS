<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';

function linnms_external_olt_action_request(int $uid, string $action, array $data = []): array
{
    try {
        return internal_api_request($uid, 'external_olt.action', ['action' => $action, 'data' => $data], 75);
    } catch (Throwable $error) {
        error_log('[LINNMS] External OLT action API: ' . $error->getMessage());
        return ['ok' => false, 'code' => 'internal'];
    }
}

function handle_api_action(string $action): bool
{
    if (!in_array($action, ['test_api', 'add_olt', 'update_api_olt', 'scan_olt', 'reboot_onu'], true)) return false;
    $uid = current_user_id();

    if ($action === 'test_api') {
        $returnPage = action_return_page($_POST['return_page'] ?? '', public_settings_url(), ['settings', 'settings-admin']);
        $result = linnms_external_olt_action_request($uid, $action);
        if (linnms_action_reason($result, '') === 'api_unavailable') {
            flash('error', 'Layanan akun belum tersedia.');
            redirect(public_settings_url());
        }
        $message = match ((string)linnms_action_reason($result, 'internal')) {
            'ok' => 'Koneksi OLT External API aktif.',
            'network' => 'Koneksi OLT External API tidak tersedia. Periksa koneksi jaringan.',
            'auth' => 'Koneksi OLT External API ditolak. Periksa pengaturan layanan.',
            'server' => 'Koneksi OLT External API gagal. Server API tidak merespons.',
            default => linnms_action_code_message($result, 'Koneksi API gagal.'),
        };
        flash(!empty($result['ok']) ? 'success' : 'error', $message);
        redirect($returnPage);
    }

    if ($action === 'add_olt' || $action === 'update_api_olt') {
        $name = trim((string)($_POST['olt_name'] ?? ''));
        $ip = trim((string)($_POST['ip_address'] ?? ''));
        $port = trim((string)($_POST['snmp_port'] ?? ''));
        $community = trim((string)($_POST['community'] ?? ''));
        $type = trim((string)($_POST['olt_type'] ?? ''));
        $rowId = valid_positive_id($_POST['olt_row_id'] ?? null);
        $returnPage = $action === 'add_olt' ? '?page=dashboard&add_device=1'
            : ($rowId !== null ? public_page_url('dashboard', ['edit_device' => $rowId]) : '?page=dashboard');
        $errors = [];
        if ($name === '') $errors[] = validation_required_message('Nama perangkat');
        if ($ip === '') $errors[] = validation_required_message('IP / Host');
        if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) $errors[] = validation_port_message();
        if ($community === '' && $action === 'add_olt') $errors[] = validation_required_message('Community');
        if ($action === 'add_olt' && ($type === '' || !array_key_exists($type, olt_type_options()))) {
            $errors[] = 'Jenis perangkat harus dipilih dari daftar.';
        }
        if ($errors !== []) {
            foreach ($errors as $error) flash('error', $error);
            if ($action === 'add_olt') {
                $_SESSION['old_olt'] = $_POST;
                unset($_SESSION['old_olt']['community'], $_SESSION['old_olt']['password'], $_SESSION['old_olt']['csrf']);
            }
            redirect($returnPage);
        }
        $result = linnms_external_olt_action_request($uid, $action, [
                'olt_row_id' => $rowId,
                'olt_name' => $name,
                'ip_address' => $ip,
                'port' => (int)$port,
                'community' => $community,
                'olt_type' => $type,
        ]);
        if (!empty($result['ok'])) {
            if ($action === 'add_olt') unset($_SESSION['old_olt']);
            flash('success', $action === 'add_olt' ? 'OLT berhasil ditambahkan.' : 'Koneksi OLT berhasil diperbarui.');
            redirect('?page=dashboard');
        }
        if ($action === 'add_olt') {
            $_SESSION['old_olt'] = $_POST;
            unset($_SESSION['old_olt']['community'], $_SESSION['old_olt']['password'], $_SESSION['old_olt']['csrf']);
        }
        $code = (string)linnms_action_reason($result, 'internal');
        if ($code === 'quota') {
            $quota = is_array($result['data'] ?? null) ? $result['data'] : [];
            $scope = ($quota['blocked_by'] ?? '') === 'q' ? 'keseluruhan' : 'akun';
            $used = $scope === 'keseluruhan' ? (int)($quota['used'] ?? 0) : (int)($quota['user_used'] ?? 0);
            $limit = $scope === 'keseluruhan' ? (int)($quota['limit'] ?? 0) : (int)($quota['user_limit'] ?? 0);
            flash('error', 'Batas OLT ' . $scope . ' telah tercapai (' . $used . '/' . $limit . ').');
        } elseif ($code === 'api_unavailable') {
            flash('error', 'Layanan akun belum tersedia.');
            redirect(public_settings_url());
        } elseif ($code === 'not_found') {
            flash('error', 'OLT API tidak valid.');
            redirect('?page=dashboard');
        } elseif ($code === 'validation') {
            flash('error', 'Data koneksi OLT tidak valid.');
        } elseif ($code === 'driver_disabled') {
            flash('error', 'Jenis OLT ini tidak diizinkan atau sedang dinonaktifkan.');
        } else {
            flash('error', $action === 'add_olt' ? 'OLT gagal ditambahkan.' : 'Perubahan koneksi OLT gagal dikirim ke Zetset.');
        }
        redirect($returnPage);
    }

    if ($action === 'scan_olt') {
        $id = valid_positive_id($_POST['olt_id'] ?? null);
        if ($id === null) {
            flash('error', 'OLT tidak valid.');
            redirect('?page=dashboard');
        }
        $result = linnms_external_olt_action_request($uid, $action, ['olt_id' => $id]);
        $code = (string)linnms_action_reason($result, 'internal');
        if ($code === 'api_unavailable') {
            flash('error', 'Layanan akun belum tersedia.');
            redirect(public_settings_url());
        }
        if ($code === 'success') {
            $count = (int)($result['data']['count'] ?? 0);
            flash('success', "Pemindaian selesai. {$count} ONU berhasil diperbarui.");
        } elseif ($code === 'scanning') {
            flash('scanning', 'Pemindaian pertama kali mungkin membutuhkan waktu sedikit lebih lama. Silakan tunggu beberapa saat.');
        } elseif ($code === 'not_found') {
            flash('error', 'OLT tidak valid.');
        } else {
            flash('error', 'Pemindaian OLT gagal.');
        }
        redirect('?page=dashboard');
    }

    $id = valid_positive_id($_POST['olt_id'] ?? null);
    $index = trim((string)($_POST['onu_index'] ?? ''));
    if ($id === null || $index === '') {
        flash('error', 'Data ONU tidak valid.');
        redirect('?page=onus');
    }
    $result = linnms_external_olt_action_request($uid, $action, ['olt_id' => $id, 'onu_index' => $index]);
    if (linnms_action_reason($result, '') === 'api_unavailable') {
        flash('error', 'Layanan akun belum tersedia.');
        redirect(public_settings_url());
    }
    flash(!empty($result['ok']) ? 'success' : 'error', !empty($result['ok'])
        ? 'Perintah reboot ONU berhasil dikirim.' : 'Reboot ONU gagal.');
    redirect('?page=onus');
}
