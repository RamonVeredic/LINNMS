<?php

declare(strict_types=1);

if (!function_exists('project_path')) { require_once __DIR__ . DIRECTORY_SEPARATOR . 'paths.php'; }

try {
    require project_path('app', 'bootstrap.php');
} catch (Throwable $error) {
    error_log(
        '[LINNMS] Bootstrap gagal: '
        . get_class($error)
        . ': '
        . $error->getMessage()
        . ' at '
        . $error->getFile()
        . ':'
        . $error->getLine()
    );
    http_response_code(500);
    require_once project_path('src', 'helpers.php');
    require project_path('layouts', 'setup-error.php');
    exit;
}
$config = $config ?? ($GLOBALS['config'] ?? []);

require_once project_path('app', 'auth.php');
require_once project_path('app', 'entry.php');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

unset($_GET['page'], $_GET['id'], $_GET['olt_row'], $_GET['edit']);
$publicRoute = resolve_public_route();
if (!$publicRoute['valid']) {
    http_response_code(404);
    exit;
}
$_GET['page'] = $publicRoute['page'];
if (isset($publicRoute['id'])) $_GET['id'] = $publicRoute['id'];
if (isset($publicRoute['olt_row'])) $_GET['olt_row'] = $publicRoute['olt_row'];
if (isset($publicRoute['edit'])) $_GET['edit'] = $publicRoute['edit'];

require_once LINNMS_UI_ROOT . '/controllers/actions/action-result.php';
require_once LINNMS_UI_ROOT . '/controllers/actions/external-olt.php';
require_once LINNMS_UI_ROOT . '/controllers/actions/user.php';
require_once LINNMS_UI_ROOT . '/controllers/actions/settings.php';
require_once LINNMS_UI_ROOT . '/controllers/actions/telegram.php';
require_once LINNMS_UI_ROOT . '/controllers/actions/whatsapp.php';
require_once LINNMS_UI_ROOT . '/controllers/actions/auth.php';
require_once LINNMS_UI_ROOT . '/controllers/actions/onu.php';
require_once LINNMS_UI_ROOT . '/controllers/actions/onu-registration.php';
require_once LINNMS_UI_ROOT . '/controllers/actions/olt.php';
require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';
require_once project_path('app', 'PageController.php');
require_once project_path('view', 'bootstrap.php');

if (linnms_enforce_idle_session()) redirect('?page=login');

linnms_register_php_error_logging($db);

$requestStarted = microtime(true);
$requestMethodForLog = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$requestActionForLog = trim((string)($_POST['action'] ?? ''));
$initialUserIdForLog = current_user_id();
$initialUsernameForLog = current_username();
$submittedUsernameForLog = $requestMethodForLog === 'POST' && $requestActionForLog === 'login'
    && is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';

$importantActionsForLog = [
    'login', 'logout', 'update_profile', 'save_user_device_limits',
    'export_onu_backup', 'preview_onu_backup', 'confirm_onu_backup', 'cancel_onu_backup',
    'add_olt', 'add_device', 'add_mikrotik', 'add_switch', 'delete_device', 'update_device',
    'sync_snmp_olt', 'scan_olt', 'scan_all_olts',
    'update_snmp_olt_port_description', 'save_olt_pon_description_local',
    'save_olt_pon_port_config', 'save_olt_pon_modal', 'save_olt_cli_connection', 'refresh_olt', 'refresh_olt_view', 'refresh_device_access',
    'save_zte_vlan', 'delete_zte_vlan', 'save_olt_profile', 'delete_olt_profile', 'save_olt_vlan_port', 'save_olt_vlan_members',
    'save_olt_card_label',
    'test_olt_cli_connection', 'refresh_olt_cli_optical', 'refresh_onu_cli_optical',
    'update_onu_name', 'save_onu_control', 'onu_operation', 'delete_onu', 'reboot_onu',
    'save_telegram_user_bot', 'save_telegram', 'telegram_toggle_notifications', 'telegram_disable',
    'save_whatsapp_connection', 'whatsapp_toggle_notifications', 'whatsapp_disable',
];

register_shutdown_function(static function () use (
    &$db,
    $requestStarted,
    $requestMethodForLog,
    $requestActionForLog,
    $initialUserIdForLog,
    $initialUsernameForLog,
    $submittedUsernameForLog,
    $importantActionsForLog
): void {
    if (!isset($db) || !$db instanceof LinnmsRepository) return;
    try {
        $fatal = error_get_last();
        $fatalTypes = [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR];
        $isFatal = is_array($fatal) && in_array((int)($fatal['type'] ?? 0), $fatalTypes, true);
        $status = http_response_code();
        $userId = current_user_id() > 0 ? current_user_id() : ($initialUserIdForLog > 0 ? $initialUserIdForLog : null);
        $actor = current_username() !== '' ? current_username() : ($initialUsernameForLog !== '' ? $initialUsernameForLog : null);
        if ($requestActionForLog === 'login' && current_user_id() < 1 && $submittedUsernameForLog !== '') {
            $actor = $submittedUsernameForLog;
        }
        $duration = (int)round((microtime(true) - $requestStarted) * 1000);

        if (($isFatal || $status >= 500) && empty($GLOBALS['LINNMS_ORIGINAL_ERROR_LOGGED'])) {
            $fatalMessage = $isFatal
                ? trim((string)($fatal['message'] ?? 'Fatal error tanpa pesan.'))
                : 'HTTP ' . $status . ' pada request aplikasi.';
            $fatalContext = [
                'duration_ms' => $duration,
                'http_status' => $status,
                'request_method' => $requestMethodForLog,
                'action' => $requestActionForLog,
                'request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
            ];
            if ($isFatal) {
                $fatalContext['php_error_type'] = (int)($fatal['type'] ?? 0);
                $fatalContext['file'] = (string)($fatal['file'] ?? '');
                $fatalContext['line'] = (int)($fatal['line'] ?? 0);
            }
            $db->logApplication(
                $userId,
                'LINNMS APP',
                'application_error',
                'error',
                $fatalMessage,
                $fatalContext,
                $actor,
                $_SERVER['REMOTE_ADDR'] ?? null
            );
            return;
        }

        if (
            $requestMethodForLog !== 'POST' ||
            $requestActionForLog === '' ||
            !in_array($requestActionForLog, $importantActionsForLog, true)
        ) {
            return;
        }

        $action = $requestActionForLog;
        $level = 'success';
        $message = $action === 'login'
            ? (current_user_id() > 0 ? 'Pengguna berhasil masuk.' : 'Percobaan login gagal.')
            : 'Aktivitas penting pengguna diproses.';
        $actionLog = $GLOBALS['LINNMS_ACTION_LOG'] ?? null;
        $logArea = 'LINNMS APP';
        if (is_array($actionLog)) {
            $actionArea = trim((string)($actionLog['area'] ?? ''));
            if ($actionArea !== '') $logArea = $actionArea;
            $actionLevel = strtolower(trim((string)($actionLog['level'] ?? 'error')));
            if (in_array($actionLevel, ['info','success','warning','error','critical'], true)) {
                $level = $actionLevel;
            }
            $actionMessage = (string)($actionLog['message'] ?? '');
            if ($actionMessage !== '') $message = $actionMessage;
        }

        $flashRows = is_array($_SESSION['flash'] ?? null) ? $_SESSION['flash'] : [];
        if ($flashRows !== [] && !in_array($action, ['login', 'logout'], true)) {
            $lastFlash = end($flashRows);
            if (is_array($lastFlash)) {
                $flashType = strtolower((string)($lastFlash['type'] ?? ''));
                if (!is_array($actionLog)) {
                    if ($flashType === 'error') $level = 'error';
                    elseif ($flashType === 'warning') $level = 'warning';
                    elseif ($flashType === 'success') $level = 'success';
                    $flashMessage = trim((string)($lastFlash['message'] ?? ''));
                    if ($flashMessage !== '') $message = $flashMessage;
                }
            }
        }
        if ($action === 'login' && current_user_id() < 1 && $level === 'success') $level = 'warning';

        $db->logApplication(
            $userId,
            $logArea,
            $action,
            $level,
            $message,
            [],
            $actor,
            $_SERVER['REMOTE_ADDR'] ?? null
        );
    } catch (Throwable) {}
});

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedAction = trim((string)($_POST['action'] ?? ''));
    $guestActions = [
        'login',
        'register_account',
        'register_otp_request',
        'register_otp_cancel',
        'register_otp_verify',
    ];
    if ($submittedAction === 'logout' && !is_logged_in()) {
        $GLOBALS['LINNMS_ACTION_LOG'] = [
            'area' => 'LINNMS APP',
            'level' => 'success',
            'message' => 'Logout dikonfirmasi; session login sudah tidak tersedia.',
        ];
        logout_user();
    }
    if (!is_logged_in() && !in_array($submittedAction, $guestActions, true)) {
        $GLOBALS['LINNMS_ACTION_LOG'] = [
            'area' => 'Keamanan',
            'level' => 'warning',
            'message' => 'Aksi ditolak karena session login tidak tersedia.',
        ];
        redirect_invalid_session_to_login();
    }

    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_device') {
        unset($_SESSION['old_snmp_device'], $_SESSION['old_olt'], $_SESSION['device_add_errors']);
        $submittedDriverId = trim((string)($_POST['driver_id'] ?? ''));
        $submittedApiOltType = str_starts_with($submittedDriverId, 'api_olt_type:')
            ? substr($submittedDriverId, strlen('api_olt_type:'))
            : '';
        if ($submittedApiOltType !== '' && array_key_exists($submittedApiOltType, selectable_api_olt_type_options())) {
            $_POST['olt_type'] = $submittedApiOltType;
            $_POST['driver_id'] = 'external_olt_api';
            $submittedDriver = monitoring_driver_definition('external_olt_api');
        } else {
            $submittedApiOltType = '';
            $submittedDriver = monitoring_driver_definition($submittedDriverId);
        }
        $submittedGroup = is_array($submittedDriver) ? (string)($submittedDriver['group'] ?? '') : '';
        $deviceAddErrors = [];
        if (!is_array($submittedDriver) || !in_array($submittedGroup, ['olt', 'router', 'switch'], true)) {
            $deviceAddErrors['driver_id'] = $submittedDriverId === '' ? 'Isi bidang ini.' : 'Jenis perangkat tidak valid.';
        }
        if (trim((string)($_POST['device_name'] ?? '')) === '') {
            $deviceAddErrors['device_name'] = 'Isi bidang ini.';
        }
        $submittedIp = trim((string)($_POST['ip_address'] ?? ''));
        if ($submittedIp === '') {
            $deviceAddErrors['ip_address'] = 'Isi bidang ini.';
        } elseif ($submittedApiOltType === '' && filter_var($submittedIp, FILTER_VALIDATE_IP) === false) {
            $deviceAddErrors['ip_address'] = validation_ip_message();
        }
        if (is_array($submittedDriver)) {
            $submittedPort = trim((string)($_POST['snmp_port'] ?? ''));
            if ($submittedPort === '') {
                $deviceAddErrors['snmp_port'] = 'Isi bidang ini.';
            } elseif (!ctype_digit($submittedPort) || (int)$submittedPort < 1 || (int)$submittedPort > 65535) {
                $deviceAddErrors['snmp_port'] = 'Masukkan port 1–65535.';
            }
            if (trim((string)($_POST['community'] ?? '')) === '') {
                $deviceAddErrors['community'] = 'Isi bidang ini.';
            }
            if (!empty($submittedDriver['telnet_cli']) && (string)($_POST['hybrid_status'] ?? '') === '1') {
                $submittedCliPort = trim((string)($_POST['ports'] ?? ''));
                if ($submittedCliPort === '') {
                    $deviceAddErrors['ports'] = 'Isi bidang ini.';
                } elseif (!ctype_digit($submittedCliPort) || (int)$submittedCliPort < 1 || (int)$submittedCliPort > 65535) {
                    $deviceAddErrors['ports'] = 'Masukkan port 1–65535.';
                }
                if (trim((string)($_POST['username'] ?? '')) === '') $deviceAddErrors['username'] = 'Isi bidang ini.';
                if (trim((string)($_POST['password'] ?? '')) === '') $deviceAddErrors['password'] = 'Isi bidang ini.';
            }
        }
        if ($deviceAddErrors !== []) {
            if (!empty($submittedDriver['telnet_cli']) && (string)($_POST['hybrid_status'] ?? '') === '1'
                && !isset($deviceAddErrors['password'])) {
                $deviceAddErrors['password'] = 'Masukkan ulang password.';
            }
            $oldDeviceInput = $_POST;
            unset($oldDeviceInput['csrf'], $oldDeviceInput['password']);
            $_SESSION[$submittedGroup === 'router' || $submittedGroup === 'switch' ? 'old_snmp_device' : 'old_olt'] = $oldDeviceInput;
            $_SESSION['device_add_errors'] = $deviceAddErrors;
            flash('error', 'Periksa bidang yang ditandai merah.');
            redirect('?page=add-device');
        }
        if (is_array($submittedDriver) && in_array((string)($submittedDriver['group'] ?? ''), ['router','switch'], true)) {
            unset($_SESSION['old_olt']);
            $action = (string)$submittedDriver['group'] === 'router' ? 'add_mikrotik' : 'add_switch';
            $_POST['action'] = $action;
        } else {
            unset($_SESSION['old_snmp_device']);
            $action = 'add_olt';
            $_POST['action'] = $action;
            $_POST['olt_name'] = trim((string)($_POST['device_name'] ?? ''));
        }
    }

    if ($action === 'add_mikrotik' || $action === 'add_switch') {
        $driverId = trim((string)($_POST['driver_id'] ?? ''));
        $definition = monitoring_driver_definition($driverId);
        $group = is_array($definition) ? (string)($definition['group'] ?? '') : '';
        $expectedGroup = $action === 'add_mikrotik' ? 'router' : 'switch';
        if (!is_logged_in()) redirect('?page=login');
        if ($group !== $expectedGroup) {
            flash('error', 'Jenis perangkat tidak valid.');
            redirect('?page=dashboard');
        }
        $addPage = 'add-device';
        $viewPage = $group === 'router' ? 'view-mikrotik' : 'view-switch';
        $publicDeviceLabel = $group === 'router' ? 'MikroTik' : 'Switch';
        try {
            $requestData = $_POST;
            unset($requestData['csrf']);
            $created = internal_api_request(current_user_id(), 'device.action',
                ['action' => $action, 'data' => $requestData], 90);
            if (empty($created['ok']) || (int)($created['data']['id'] ?? 0) < 1) {
                throw new RuntimeException('Perangkat gagal ditambahkan.');
            }
            unset($_SESSION['old_snmp_device']);
            flash('success', $publicDeviceLabel . ' berhasil ditambahkan.');
            redirect('?page=' . $viewPage . '&id=' . (int)$created['data']['id']);
        } catch (Throwable $e) {
            $GLOBALS['LINNMS_ACTION_LOG'] = [
                'area' => $publicDeviceLabel,
                'level' => 'error',
                'message' => (string)$e,
            ];
            $_SESSION['old_snmp_device'] = [
                'driver_id' => $driverId,
                'device_name' => trim((string)($_POST['device_name'] ?? '')),
                'ip_address' => trim((string)($_POST['ip_address'] ?? '')),
                'snmp_port' => trim((string)($_POST['snmp_port'] ?? '161')),
                'community' => trim((string)($_POST['community'] ?? '')),
            ];
            flash('error', $publicDeviceLabel . ' gagal ditambahkan.');
            redirect('?page=' . $addPage);
        }
    }

    if ($action === 'delete_device') {
        if (!is_logged_in()) redirect('?page=login');
        $rowId = valid_positive_id($_POST['device_id'] ?? null);
        try {
            $result = $rowId !== null ? internal_api_request(current_user_id(), 'device.action',
                ['action' => $action, 'data' => ['device_id' => $rowId]], 30) : [];
            $deleted = !empty($result['ok']);
            if (!$deleted) throw new RuntimeException('Perangkat tidak ditemukan atau gagal dihapus');
            flash('success', 'Perangkat berhasil dihapus');
        } catch (Throwable $e) {
            linnms_log_original_error($db, $e, $action);
            flash('error', 'Perangkat gagal dihapus');
        }
        redirect('?page=dashboard');
    }

    if ($action === 'update_device') {
        $rowId = valid_positive_id($_POST['device_row_id'] ?? null);
        $actorId = current_user_id();
        try {
            $editContext = $rowId !== null ? internal_api_request($actorId, 'page.shell.read',
                ['page' => 'edit-device', 'row_id' => $rowId]) : [];
        } catch (Throwable $e) {
            linnms_log_original_error($db, $e, $action);
            flash('error', 'Data perangkat tidak dapat dimuat.');
            redirect('?page=dashboard');
        }
        $editRow = is_array($editContext['editable_device'] ?? null) ? $editContext['editable_device'] : null;
        $editKind = is_array($editRow) ? device_edit_kind($editRow) : '';
        if ($rowId === null || $editKind === '') {
            flash('error', 'Perangkat tidak ditemukan.');
            redirect('?page=dashboard');
        }

        $editRowId = (int)($editRow['id'] ?? $rowId);
        $editPageUrl = public_page_url('edit-device', ['id' => $editRowId]);
        $currentDriverId = trim((string)device_driver_type($editRow));
        $_POST['driver_id'] = $currentDriverId;
        $publicDeviceLabel = match ($editKind) {
            'olt_snmp', 'olt_api' => 'OLT',
            default => (string)($editRow['device_type'] ?? '') === 'mikrotik' ? 'MikroTik' : 'Switch',
        };
        $viewUrl = match ($editKind) {
            'olt_snmp' => public_page_url('view-olt', ['id' => $editRowId]),
            'olt_api' => public_page_url('edit-api-olt', ['id' => $editRowId]),
            default => public_page_url(
                $publicDeviceLabel === 'MikroTik' ? 'view-mikrotik' : 'view-switch',
                ['id' => $editRowId]
            ),
        };
        if ($editKind === 'olt_api') {
            flash('error', 'OLT API diubah melalui halaman Edit Koneksi OLT.');
            redirect($viewUrl);
        }

        $oldEditInput = $_POST;
        unset($oldEditInput['password']);
        try {
            $requestData = $_POST;
            unset($requestData['csrf']);
            $result = internal_api_request($actorId, 'device.action',
                ['action' => 'update_device', 'data' => $requestData], 90);
        } catch (Throwable $e) {
            linnms_log_original_error($db, $e, $action);
            $_SESSION['old_edit_device'] = $oldEditInput;
            flash(
                'error',
                $e instanceof InvalidArgumentException
                    ? $e->getMessage()
                    : $publicDeviceLabel . ' gagal diperbarui.'
            );
            redirect($editPageUrl);
        }

        if (empty($result['ok'])) {
            $_SESSION['old_edit_device'] = $oldEditInput;
            flash(
                'error',
                (string)($result['data']['public_message'] ?? ($publicDeviceLabel . ' gagal diperbarui.'))
            );
            redirect($editPageUrl);
        }

        unset($_SESSION['old_edit_device']);
        flash('success', (string)($result['data']['public_message'] ?? ($publicDeviceLabel . ' berhasil diperbarui.')));
        redirect($viewUrl);
    }

    if ($action === 'add_olt') {
        $oltAddPage = 'add-device';
        $apiOltType = trim((string)($_POST['olt_type'] ?? ''));
        $isApiOltType = (string)($_POST['driver_id'] ?? '') === 'external_olt_api'
            && array_key_exists($apiOltType, selectable_api_olt_type_options());
        $driverId = $isApiOltType ? 'external_olt_api' : olt_driver_id_from_input($_POST);
        $definition = $isApiOltType ? monitoring_driver_definition($driverId) : ($driverId !== '' ? olt_driver_definition($driverId) : null);
        $safeOldInput = $_POST;

        if ($definition === null) {
            $_SESSION['old_olt'] = $safeOldInput;
            $GLOBALS['LINNMS_ACTION_LOG'] = [
                'level' => 'warning',
                'message' => 'Percobaan menambahkan tipe OLT yang tidak valid diblokir.',
            ];
            flash('error', 'Tipe OLT tidak valid. Silakan pilih kembali.');
            redirect('?page=' . $oltAddPage);
        }

        $supportFlags = $db->getOltDriverSupportFlags();
        $apiTypeSupportFlags = $db->getApiOltTypeSupportFlags();
        $userHasApiKey = $apiClients->forUser(current_user_id()) !== null;
        $driverAvailable = $isApiOltType
            ? ($userHasApiKey && api_olt_type_support_enabled($apiTypeSupportFlags, $apiOltType))
            : olt_driver_support_enabled($supportFlags, $driverId);
        if (!$driverAvailable) {
            $_SESSION['old_olt'] = $safeOldInput;
            $GLOBALS['LINNMS_ACTION_LOG'] = [
                'level' => 'warning',
                'message' => 'Percobaan menambahkan tipe OLT yang dinonaktifkan diblokir.',
            ];
            $unavailableLabel = $isApiOltType ? (string)(olt_type_options()[$apiOltType] ?? $apiOltType) : (string)$definition['label'];
            $unavailableReason = $isApiOltType && !$userHasApiKey
                ? ' belum tersedia.'
                : ' Segera hadir';
            flash('error', $unavailableLabel . $unavailableReason);
            redirect('?page=' . $oltAddPage);
        }

    }

    
    
    
    if (handle_auth_action($action)) {
        exit;
    }
    if (handle_onu_action($action)) {
        exit;
    }
    if (handle_onu_registration_action($action)) {
        exit;
    }
    if (handle_olt_action($action)) {
        exit;
    }
    if (handle_user_action($action)) {
        exit;
    }
    if (handle_settings_action($action)) {
        exit;
    }
    if (handle_telegram_action($action)) {
        exit;
    }
    if (handle_whatsapp_action($action)) {
        exit;
    }
    handle_api_action($action);
}

if (!is_logged_in()) {
    require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';
    $guestPage = internal_api_request(0, 'guest.page.read');
    $flashes = pull_flashes();
    $publicRegistrationEnabled = !empty($guestPage['public_registration_enabled']);
    $serviceContact = (array)($guestPage['service_contact'] ?? []);
    $otpRegistrationEnabled = $publicRegistrationEnabled;
    $registerPendingPhone = is_array($_SESSION['register_pending'] ?? null)
        ? trim((string)($_SESSION['register_pending']['phone'] ?? ''))
        : '';
    $registerPendingHasOtp = $registerPendingPhone !== ''
        && (int)($_SESSION['register_pending']['otp_challenge'] ?? 0) > 0;
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if (strtolower(trim((string)($_GET['page'] ?? ''))) === 'register') {
        if (!$publicRegistrationEnabled) redirect('?page=login');
        require project_path('view', 'register.php');
    } else {
        require project_path('view', 'login.php');
    }
    exit;
}

$user = $db->getUser(current_user_id());
if ($user === null || (int) $user['is_active'] !== 1) {
    logout_user();
}

$route = PageController::resolve((string) ($_GET['page'] ?? ''));
$page = $route['name'];
if ($page === 'settings-admin' && !is_admin()) redirect(public_page_url('settings'));
$pageTitle = $route['title'];
$pageLead = $route['lead'];
$pageView = PageController::view($page);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';
$pageShell = internal_api_request(current_user_id(), 'page.shell.read', [
    'page' => $page,
    'row_id' => valid_positive_id($_GET['id'] ?? null) ?? 0,
    'notif_page' => (int)($_GET['notif_page'] ?? 1),
    'log_page' => (int)($_GET['log_page'] ?? 1),
]);
$addOnuSidebarTotal = (int)($pageShell['sidebar_unregistered_onus'] ?? 0);
$layoutServiceContact = is_array($pageShell['service_contact'] ?? null) ? $pageShell['service_contact'] : [];
$editDeviceRow = is_array($pageShell['editable_device'] ?? null) ? $pageShell['editable_device'] : null;
$editApiOlt = is_array($pageShell['editable_api_olt'] ?? null) ? $pageShell['editable_api_olt'] : null;
$deviceTrafficSelected = is_array($pageShell['traffic_interfaces'] ?? null) ? $pageShell['traffic_interfaces'] : [];
$notificationDevices = is_array($pageShell['notification_devices'] ?? null) ? $pageShell['notification_devices'] : [];
$notificationSelectedIds = $pageShell['notification_selected_ids'] ?? null;
$oltGraphIntervalMinutes = (int)($pageShell['olt_graph_interval_minutes'] ?? 0);
$readerCount = (int)($pageShell['reader_count'] ?? LinnmsRepository::DEFAULT_READER_COUNT);
$writerCount = (int)($pageShell['writer_count'] ?? LinnmsRepository::DEFAULT_WRITER_COUNT);
$fastSyncOnuBatchSize = (int)($pageShell['fast_sync_onu_batch_size'] ?? LinnmsRepository::DEFAULT_FAST_SYNC_ONU_BATCH_SIZE);

$dashboardRead = null;
if ($page === 'dashboard') {
    require_once LINNMS_UI_ROOT . '/controllers/dashboard.php';
    $dashboardRead = dashboard_read_data(current_user_id(), $_GET);
}

$settings = $dashboardRead !== null
    ? (array)($dashboardRead['settings'] ?? [])
    : (array)($pageShell['user_settings'] ?? []);
$onuProblemSettings = (array)($pageShell['onu_problem_settings'] ?? []);
$telegramServiceSettings = (array)($pageShell['telegram_service_settings'] ?? []);
$telegramFeatureAllowed = $bridge->allowsTelegram();
$flashes = pull_flashes();
$serviceAccessState = $bridge->state();
$serviceExpiresAt = strtotime((string)($serviceAccessState['claims']['exp'] ?? ''));
$serviceWarningWindowSeconds = 7 * 86400;
$serviceWarningCheckedAt = time();
$serviceExpiryNotice = null;
$serviceExpiryDaysRemaining = null;
$serviceExpiryDateLabel = null;
if (
    $page === 'dashboard'
    && ($serviceAccessState['mode'] ?? '') === 'ready'
    && $serviceExpiresAt !== false
    && $serviceExpiresAt > $serviceWarningCheckedAt
    && ($serviceExpiresAt - $serviceWarningCheckedAt) <= $serviceWarningWindowSeconds
) {
    $serviceExpiryNotice = true;
    $serviceExpiryDaysRemaining = max(
        1,
        (int)ceil(($serviceExpiresAt - $serviceWarningCheckedAt) / 86400)
    );
    $serviceExpiryDateLabel = format_indonesian_date_short($serviceExpiresAt);
}
$stats = [];
$olts = [];
$mikrotikDevices = [];
$switchDevices = [];
$oltQuota = [];
$mikrotikQuota = [];
$switchQuota = [];
$dashboardDeviceRows = [];
if ($dashboardRead !== null) {
    $stats = (array)($dashboardRead['stats'] ?? []);
    $olts = (array)($dashboardRead['olts'] ?? []);
    $mikrotikDevices = (array)($dashboardRead['mikrotikDevices'] ?? []);
    $switchDevices = (array)($dashboardRead['switchDevices'] ?? []);
    $oltQuota = (array)($dashboardRead['oltQuota'] ?? []);
    $mikrotikQuota = (array)($dashboardRead['mikrotikQuota'] ?? []);
    $switchQuota = (array)($dashboardRead['switchQuota'] ?? []);
    $dashboardDeviceRows = (array)($dashboardRead['deviceRows'] ?? []);
    $dashboardDeviceRowCount = (int)($dashboardRead['deviceRowCount'] ?? 0);
    $activeOltScanRows = (array)($dashboardRead['activeOltScanRows'] ?? []);
    $latestOltScanStatuses = (array)($dashboardRead['latestOltScanStatuses'] ?? []);
} else {
    $stats = (array)($pageShell['dashboard_stats'] ?? []);
    $olts = (array)($pageShell['olts'] ?? []);
    $mikrotikDevices = (array)($pageShell['mikrotik_devices'] ?? []);
    $switchDevices = (array)($pageShell['switch_devices'] ?? []);
    $oltQuota = (array)($pageShell['olt_quota'] ?? []);
    $mikrotikQuota = (array)($pageShell['mikrotik_quota'] ?? []);
    $switchQuota = (array)($pageShell['switch_quota'] ?? []);
}
$accountTier = 'Default';
$accountQuotaClaims = [];
if (in_array($page, ['settings', 'settings-admin'], true)) {
    $accountAccessState = $serviceAccessState;
    $accountTierClaim = trim((string)($accountAccessState['claims']['tier'] ?? ''));
    if ($accountTierClaim !== '') $accountTier = $accountTierClaim;
    $accountQuotaClaims = (array)($accountAccessState['claims']['quota'] ?? []);
}
$profileServiceLimits = in_array($page, ['settings', 'settings-admin'], true) ? [
    'olt' => is_admin() ? (int)($accountQuotaClaims['q1'] ?? $oltQuota['limit']) : (int)$oltQuota['user_limit'],
    'mikrotik' => is_admin() ? (int)($accountQuotaClaims['q2'] ?? $mikrotikQuota['limit']) : (int)$mikrotikQuota['user_limit'],
    'switch' => is_admin() ? (int)($accountQuotaClaims['q3'] ?? $switchQuota['limit']) : (int)$switchQuota['user_limit'],
] : [];
$serviceContact = in_array($page, ['settings', 'settings-admin'], true)
    ? $layoutServiceContact
    : ['whatsapp' => '', 'email' => ''];
$onuBackupPreview = null;
if (in_array($page, ['settings', 'settings-admin'], true) && is_array($_SESSION['onu_backup_preview'] ?? null)) {
    $candidatePreview = $_SESSION['onu_backup_preview'];
    $isCurrentUser = (int)($candidatePreview['user_id'] ?? 0) === current_user_id();
    $isFresh = time() - (int)($candidatePreview['created_at'] ?? 0) <= OnuBackupService::PREVIEW_TTL_SECONDS;
    if ($isCurrentUser && $isFresh) $onuBackupPreview = $candidatePreview;
    else unset($_SESSION['onu_backup_preview']);
}
$oltDriverSupportFlags = (array)($pageShell['olt_driver_flags'] ?? []);
$apiOltTypeSupportFlags = (array)($pageShell['api_olt_type_flags'] ?? []);
$userHasExternalApiKey = $apiClients->forUser(current_user_id()) !== null;
$oltDriverCatalog = olt_driver_catalog();
$snmpDriverSupportFlags = (array)($pageShell['snmp_driver_flags'] ?? []);
$snmpDriverCatalog = snmp_driver_catalog();
$deviceDriverCatalog = monitoring_driver_catalog();
$apiOltTypeOptions = selectable_api_olt_type_options();
if ($page === 'add-device') {
    $deviceAddErrors = is_array($_SESSION['device_add_errors'] ?? null) ? $_SESSION['device_add_errors'] : [];
    unset($_SESSION['device_add_errors']);
    $deviceAddOldSnmp = is_array($_SESSION['old_snmp_device'] ?? null) ? $_SESSION['old_snmp_device'] : [];
    $deviceAddOldOlt = is_array($_SESSION['old_olt'] ?? null) ? $_SESSION['old_olt'] : [];
    unset($_SESSION['old_snmp_device'], $_SESSION['old_olt']);
    $deviceAddOld = $deviceAddOldSnmp !== [] ? $deviceAddOldSnmp : $deviceAddOldOlt;
    $deviceAddSelectedDriverId = trim((string)($deviceAddOld['driver_id'] ?? ''));
    if ($deviceAddSelectedDriverId === 'external_olt_api'
        && array_key_exists((string)($deviceAddOld['olt_type'] ?? ''), $apiOltTypeOptions)) {
        $deviceAddSelectedDriverId = 'api_olt_type:' . (string)$deviceAddOld['olt_type'];
    }
    $deviceAddSelectedDefinition = monitoring_driver_definition($deviceAddSelectedDriverId);
    $deviceAddCommunityDefault = trim((string)($deviceAddSelectedDefinition['default_snmp_community'] ?? 'public'));
    if ($deviceAddCommunityDefault === '') $deviceAddCommunityDefault = 'public';
    $deviceAddQuotaByGroup = [
        'olt' => !empty($oltQuota['can_add']),
        'router' => !empty($mikrotikQuota['can_add']),
        'switch' => !empty($switchQuota['can_add']),
    ];
    $deviceAddGroups = [];
    foreach ($deviceAddQuotaByGroup as $group => $quotaAllowed) {
        $deviceAddGroups[$group] = ['label' => strtoupper($group), 'rows' => []];
    }
    $deviceAddUnavailable = [];
    $deviceAddConnectionNotes = [];
    foreach ($deviceDriverCatalog as $driverId => $definition) {
        $group = (string)($definition['group'] ?? '');
        if (!array_key_exists($group, $deviceAddQuotaByGroup)) continue;
        $note = $definition['add_connection_note'] ?? null;
        if (is_array($note) && trim((string)($note['text'] ?? '')) !== '') $deviceAddConnectionNotes[] = $note;
        if ((string)($definition['handler'] ?? '') === 'external_api') {
            foreach ($apiOltTypeOptions as $apiType => $apiLabel) {
                $key = 'api_olt_type:' . $apiType;
                $enabled = $userHasExternalApiKey && api_olt_type_support_enabled($apiOltTypeSupportFlags, (string)$apiType);
                $row = [
                    'key' => $key,
                    'definition' => ['label' => $apiLabel, 'group' => $group, 'method' => 'api'],
                    'enabled' => $enabled,
                    'quota_allowed' => $deviceAddQuotaByGroup[$group],
                    'access_locked' => !device_access_allows($key),
                ];
                if ($enabled) $deviceAddGroups[$group]['rows'][] = $row;
                else $deviceAddUnavailable[] = $row;
            }
        }
        if (empty($definition['selectable'])) continue;
        $enabled = $group === 'olt'
            ? olt_driver_support_enabled($oltDriverSupportFlags, (string)$driverId)
            : snmp_driver_support_enabled($snmpDriverSupportFlags, (string)$driverId);
        $row = [
            'key' => (string)$driverId,
            'definition' => $definition,
            'enabled' => $enabled,
            'quota_allowed' => $deviceAddQuotaByGroup[$group],
            'access_locked' => !device_access_allows((string)$driverId),
        ];
        if ($enabled) $deviceAddGroups[$group]['rows'][] = $row;
        else $deviceAddUnavailable[] = $row;
    }
    $deviceAddSortByLabel = static function (array $left, array $right): int {
        $comparison = strnatcasecmp(
            (string)($left['definition']['label'] ?? ''),
            (string)($right['definition']['label'] ?? '')
        );
        return $comparison !== 0 ? $comparison : strcmp((string)$left['key'], (string)$right['key']);
    };
    foreach ($deviceAddGroups as &$deviceAddGroup) {
        usort($deviceAddGroup['rows'], $deviceAddSortByLabel);
    }
    unset($deviceAddGroup);
    usort($deviceAddUnavailable, $deviceAddSortByLabel);
}
$syncTimingSettings = $page === 'settings-admin' ? (array)($pageShell['sync_timing_settings'] ?? []) : [];
$onuMissingScanLimit = $page === 'settings-admin' ? (int)($pageShell['onu_missing_scan_limit'] ?? LinnmsRepository::DEFAULT_ONU_MISSING_SCAN_LIMIT) : LinnmsRepository::DEFAULT_ONU_MISSING_SCAN_LIMIT;
$managedDeviceUsers = $page === 'settings-admin' ? (array)($pageShell['managed_device_users'] ?? []) : [];
$oltDetail = null;
$oltSystem = [];
$oltSystemLive = null;
$oltPonRefs = [];
$oltPonModules = [];
$oltCards = [];
$oltPonNotice = '';

$oltPonFocusNumber = null;
$oltOnuStats = [
    'total' => 0,
    'online' => 0,
    'loss' => 0,
    'down' => 0,
    'problem' => 0,
    'power_down' => 0,
    'unregist' => 0,
    'unknown' => 0,
];

$oltViewId = $page === 'view-olt'
    ? valid_positive_id($_GET['id'] ?? null)
    : null;

if ($page === 'view-olt') {
    if ($oltViewId === null) {
        flash('warning', 'OLT tidak ditemukan.');
        redirect('?page=dashboard');
    }

    $oltSnapshot = (array)($pageShell['olt_snapshot'] ?? []);
    $oltDetail = is_array($oltSnapshot['detail'] ?? null) ? $oltSnapshot['detail'] : null;

    if ($oltDetail === null) {
        flash('warning', 'OLT tidak ditemukan.');
        redirect('?page=dashboard');
    }
    if (
        olt_monitoring_driver($oltDetail) === 'external_olt_api' &&
        empty($oltDetail['first_scan_started_at'])
    ) {
        flash(
            'warning',
            'Harap pindai OLT terlebih dahulu.'
        );
        redirect('?page=dashboard');
    }

    $oltPonSnapshot = (array)($oltSnapshot['pon'] ?? []);
    $oltCards = (array)($oltSnapshot['cards'] ?? []);
    $oltProfileSnapshots = (array)($oltSnapshot['profiles'] ?? []);
    $oltOnuTypes = (array)($oltSnapshot['onu_types'] ?? []);
    
    
    $oltPonOpticalSnapshot = $oltPonSnapshot;
    $oltDetail['pon_snapshot'] = $oltPonSnapshot;

    if ((int)($oltDetail['hybrid_status'] ?? 0) === 1 && monitoring_row_uses_handler($oltDetail, 'snmp_olt')) {
        $_SESSION['linnms_direct_realtime']['olt'][(string)$oltDetail['id']] = [
            'user_id' => current_user_id(),
            'row_id' => (int)$oltDetail['id'],
            'ip_address' => (string)$oltDetail['ip_address'],
            'ports' => (int)($oltDetail['ports'] ?? 23),
            'username' => (string)($oltDetail['username'] ?? ''),
            'password_enc' => (string)($oltDetail['password_enc'] ?? ''),
            'expires_at' => time() + 14400,
        ];
    }

    
    $oltPonFocusNumber = valid_positive_id($_GET['pon'] ?? null);

    if (monitoring_row_uses_handler($oltDetail, 'snmp_olt')) {
        $oltSystem = (array)($oltSnapshot['system'] ?? []);
        $oltSystemLive = [];
        $oltPonModules = (array)($oltSnapshot['modules'] ?? []);
    } elseif (monitoring_row_uses_handler($oltDetail, 'external_api')) {
        $oltSystem = olt_system_snapshot($oltDetail);
        $oltSystemLive = [];
    }

    $oltOnuStats = (array)($oltSnapshot['onu_stats'] ?? []);
}

$deviceDetail = null;
$deviceSystem = [];
$deviceInterfaces = [];
$interfaceRates = [];
$interfaceDetail = null;
$devicePageKind = in_array($page,['view-mikrotik','view-mikrotik-interface'],true) ? 'mikrotik' : ($page === 'view-switch' ? 'switch' : null);
if ($devicePageKind !== null) {
    $deviceRowId = valid_positive_id($_GET['id'] ?? null);
    if ($deviceRowId === null) {
        flash('warning', 'Perangkat tidak ditemukan.');
        redirect('?page=dashboard');
    }
    try {
        $snapshot = internal_api_request(current_user_id(), 'device.snapshot.read', [
            'row_id' => $deviceRowId,
            'kind' => $devicePageKind,
        ]);
        if (empty($snapshot['ok'])) throw new RuntimeException('Perangkat tidak ditemukan.');
        $inspection = (array)($snapshot['data'] ?? []);
        $deviceDetail = (array)($inspection['device'] ?? []);
        $deviceSystem = (array)($inspection['system'] ?? []);
        $deviceInterfaces = (array)($inspection['interfaces'] ?? []);
        $_SESSION['linnms_direct_realtime'][$devicePageKind][(string)$deviceRowId]=[
            'user_id'=>current_user_id(),
            'row_id'=>$deviceRowId,
            'device_type'=>(string)($deviceDetail['device_type']??''),
            'ip_address'=>(string)($deviceDetail['ip_address']??''),
            'snmp_port'=>(int)($deviceDetail['snmp_port']??161),
            'snmp_community'=>(string)($deviceDetail['snmp_community']??''),
            'expires_at'=>time()+14400,
        ];
        if($page==='view-mikrotik-interface'){
            $requestedInterface=trim((string)($_GET['interface']??''));
            foreach($deviceInterfaces as $interface){
                if((string)($interface['name']??'')===$requestedInterface){$interfaceDetail=$interface;break;}
            }
            if(!is_array($interfaceDetail)){
                flash('warning','Interface MikroTik tidak ditemukan.');
                redirect('?page=view-mikrotik&id='.$deviceRowId);
            }
        }
    } catch (Throwable $e) {
        linnms_log_original_error($db, $e, $devicePageKind . '_detail');
        flash('warning', 'Perangkat tidak ditemukan.');
        redirect('?page=dashboard');
    }
}

$onuDetail = null;
$onuLive = null;
$onuSnmpDetail = null;
$onuSnmpDetailMessage = '';
$onuIsProblem = false;
$onuProblemSince = null;
$onuProblemDescription = null;
$onuCliOptical = ['available' => false, 'enabled' => false, 'message' => ''];
$onuViewId = $page === 'view-onu' ? valid_positive_id($_GET['id'] ?? null) : null;

if ($page === 'view-onu' && $onuViewId !== null) {
    $onuSnapshot = (array)($pageShell['onu_snapshot'] ?? []);
    $onuDetail = is_array($onuSnapshot['detail'] ?? null) ? $onuSnapshot['detail'] : null;

    if ($onuDetail !== null) {
        foreach ((array)($onuSnapshot['problem_onus'] ?? []) as $problemOnu) {
            if (
                (string)($problemOnu['source'] ?? '') === (string)($onuDetail['source'] ?? '')
                && (int)($problemOnu['olt_id'] ?? 0) === (int)($onuDetail['olt_id'] ?? 0)
                && (string)($problemOnu['onu_index'] ?? '') === (string)($onuDetail['onu_index'] ?? '')
            ) {
                $onuIsProblem = true;
                $onuProblemSince = $problemOnu['problem_since'] ?? null;
                $onuProblemDescription = $problemOnu['problem_description'] ?? null;
                break;
            }
        }

        $isSnmpOnu = monitoring_row_uses_handler($onuDetail,'snmp_olt');
        
        
        $onuLanPorts = (array)($onuSnapshot['lan_ports'] ?? []);
        $onuLan = [
            'state' => $onuLanPorts !== [] ? 'ok' : 'empty',
            'ports' => $onuLanPorts,
            'captured_at_epoch' => 0,
        ];

        if ($isSnmpOnu) {
            $onuSnmpDetail = [
                'ok' => true,
                'source' => 'database_snapshot',
                'onu_index' => $onuDetail['onu_index'] ?? null,
                'physical' => $onuDetail['physical'] ?? null,
                'name' => $onuDetail['onu_name'] ?? null,
                'onu_serial' => $onuDetail['onu_serial'] ?? null,
                'mac' => $onuDetail['onu_serial'] ?? null,
                'status' => $onuDetail['onu_state'] ?? null,
                'auth_mode' => $onuDetail['auth_mode'] ?? null,
                'rx_power' => $onuDetail['rx_power'] ?? null,
                'tx_power' => $onuDetail['tx_power'] ?? null,
                'temperature' => $onuDetail['temperature'] ?? null,
                'distance' => $onuDetail['distance'] ?? null,
                'rtt' => $onuDetail['rtt'] ?? null,
                'ports' => [],
                'mac_table' => [],
            ];
        }

        if (!$isSnmpOnu) {
            $rawSnapshot = [];
            $rawJson = trim((string)($onuDetail['raw_json'] ?? ''));
            if ($rawJson !== '') {
                $decodedRaw = json_decode($rawJson, true);
                if (is_array($decodedRaw)) {
                    $rawSnapshot = is_array($decodedRaw['parsed'] ?? null)
                        ? $decodedRaw['parsed']
                        : $decodedRaw;
                }
            }
            $onuSnmpDetail = [
                'ok' => true,
                'source' => 'database_snapshot',
                'onu_index' => $onuDetail['onu_index'] ?? null,
                'physical' => $onuDetail['physical'] ?? ($rawSnapshot['pon'] ?? null),
                'name' => $onuDetail['onu_name'] ?? ($rawSnapshot['name'] ?? null),
                'onu_serial' => $onuDetail['onu_serial'] ?? ($rawSnapshot['serial'] ?? $rawSnapshot['mac'] ?? null),
                'mac' => $onuDetail['onu_serial'] ?? ($rawSnapshot['serial'] ?? $rawSnapshot['mac'] ?? null),
                'status' => $onuDetail['onu_state'] ?? null,
                'auth_mode' => $onuDetail['auth_mode'] ?? null,
                'rx_power' => $onuDetail['rx_power'] ?? ($rawSnapshot['rx_power'] ?? null),
                'tx_power' => $onuDetail['tx_power'] ?? ($rawSnapshot['tx_power'] ?? null),
                'temperature' => $onuDetail['temperature'] ?? ($rawSnapshot['temperature'] ?? null),
                'distance' => $onuDetail['distance'] ?? ($rawSnapshot['distance'] ?? null),
                'rtt' => $rawSnapshot['rtt'] ?? null,
                'ports' => is_array($rawSnapshot['ports'] ?? null) ? $rawSnapshot['ports'] : [],
                'mac_table' => [],
            ];
            if ($onuSnmpDetail['rx_power'] === null && $onuSnmpDetail['temperature'] === null) {
                $onuSnmpDetailMessage = 'Detail tambahan belum tersedia melalui metode koneksi ini.';
            }
        }
        $confirmedOnuState = $onuDetail['onu_state'] ?? null;
        if (is_array($onuLive)) {
            $onuLive['status'] = $confirmedOnuState;
            if (is_array($onuLive['onu'] ?? null)) {
                $onuLive['onu']['status'] = $confirmedOnuState;
            }
        }
        if (is_array($onuSnmpDetail)) {
            $onuSnmpDetail['status'] = $confirmedOnuState;
            $onuSnmpDetail['onu_state'] = $confirmedOnuState;
            $onuSnmpDetail['state'] = $confirmedOnuState;
        }

        $onuOltRow = $onuSnapshot['olt_row'] ?? null;
        if (is_array($onuOltRow)) {
            $onuOltDefinition = monitoring_driver_definition((string)device_driver_type($onuOltRow));
            $onuCliOptical['available'] = is_array($onuOltDefinition) && !empty($onuOltDefinition['telnet_cli']);
            $onuCliOptical['enabled'] = (int)($onuOltRow['hybrid_status'] ?? 0) === 1
                && trim((string)($onuOltRow['password_enc'] ?? '')) !== '';
            if (!$onuCliOptical['available']) {
                $onuCliOptical['message'] = 'Driver OLT ini belum mendukung pembacaan daya optik lewat CLI.';
            } elseif (!$onuCliOptical['enabled']) {
                $onuCliOptical['message'] = 'Aktifkan koneksi Telnet (CLI) pada halaman OLT agar daya optik ONU (RX/TX) dapat dibaca.';
            } else {
                $onuCliOptical['message'] = 'Daya optik dibaca lewat perintah CLI perangkat (Telnet).';
            }
        }
    }
}
$whatsappUserSettings = (array)($pageShell['whatsapp_user_settings'] ?? []);
$whatsappProviders = WhatsAppGateway::providerOptions();
$whatsappGatewayRegistry = WhatsAppGateway::providers();
$whatsappServiceSettings = (array)($pageShell['whatsapp_service_settings'] ?? []);
$whatsappFeatureAllowed = $bridge->allowsWhatsApp();
$whatsappHistoryPageSize = LinnmsRepository::NOTIFICATION_HISTORY_PAGE_SIZE;
$notificationHistory = (array)($pageShell['notification_history'] ?? []);
$whatsappHistoryTotal = $page === 'whatsapp' ? (int)($notificationHistory['total'] ?? 0) : 0;
$whatsappHistoryPages = max(1, (int)ceil($whatsappHistoryTotal / $whatsappHistoryPageSize));
$whatsappHistoryPage = $page === 'whatsapp' ? (int)($notificationHistory['page'] ?? 1) : 1;
$whatsappEvents = $page === 'whatsapp' ? (array)($notificationHistory['rows'] ?? []) : [];
$telegramHistoryPageSize = LinnmsRepository::NOTIFICATION_HISTORY_PAGE_SIZE;
$telegramHistoryTotal = $page === 'telegram' ? (int)($notificationHistory['total'] ?? 0) : 0;
$telegramHistoryPages = max(1, (int)ceil($telegramHistoryTotal / $telegramHistoryPageSize));
$telegramHistoryPage = $page === 'telegram' ? (int)($notificationHistory['page'] ?? 1) : 1;
$events = $page === 'telegram' ? (array)($notificationHistory['rows'] ?? []) : [];
$activityPageSize = LinnmsRepository::ACTIVITY_FEED_PAGE_SIZE;
$recentActivityPageSize = 50;
if ($dashboardRead !== null) {
    $recentActivitySearch = (string)($dashboardRead['recentActivitySearch'] ?? '');
    $recentActivityDevice = (string)($dashboardRead['recentActivityDevice'] ?? '');
    $recentActivityTotal = (int)($dashboardRead['recentActivityTotal'] ?? 0);
    $recentActivityPages = max(1, (int)($dashboardRead['recentActivityPages'] ?? 1));
    $recentActivityPage = max(1, (int)($dashboardRead['recentActivityPage'] ?? 1));
    $recentEvents = (array)($dashboardRead['recentEvents'] ?? []);
    $recent = $recentEvents;
    $recentActivityEmptyText = (string)($dashboardRead['recentActivityEmptyText'] ?? 'Belum ada aktivitas terbaru');
} else {
    $recentActivitySearch = '';
    $recentActivityDevice = '';
    $recentActivityTotal = 0;
    $recentActivityPages = 1;
    $recentActivityPage = 1;
    $recentEvents = [];
}
$activityFeed = [];
$activityTotal = 0;
$activityPages = 1;
$activityPage = 1;
if ($page === 'applogs') {
    $applicationLogs = (array)($pageShell['application_logs'] ?? []);
    $activityTotal = (int)($applicationLogs['total'] ?? 0);
    $activityPages = (int)($applicationLogs['pages'] ?? 1);
    $activityPage = (int)($applicationLogs['page'] ?? 1);
    $activityFeed = (array)($applicationLogs['rows'] ?? []);
}

require project_path('layouts', 'app.php');
