<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'paths.php';
require_once project_path('src', 'helpers.php');
require_once project_path('src', 'load-repository.php');
require_once project_path('vendor', 'driver', 'olt', 'zetset', 'ApiClient.php');
require_once project_path('src', 'TelegramNotifier.php');
require_once project_path('src', 'WhatsAppGateway.php');
require_once project_path('app', 'ApiRequestLogger.php');
require_once project_path('vendor', 'driver', 'olt', 'zetset', 'ApiClientFactory.php');
require_once project_path('src', 'SyncService.php');

ensure_data_directory(lock_path());
$lock = fopen(lock_path('notify.lock'), 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$failed = 0;

try {
    $config = require project_path('config', 'defaults.php');
    $db = new LinnmsRepository((array)$config['database']);
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'access-check.php';
    if (!linnms_worker_access_allowed($db)) exit(0);
    linnms_register_php_error_logging($db);
    
    
    
    $bridge = linnms_worker_bridge($db);
    $clients = new ApiClientFactory($config, new ApiRequestLogger($db));
    $service = new SyncService($config, $db, new TelegramNotifier(), $clients, $bridge);

    $telegramAvailable = linnms_worker_telegram_allowed($db)
        && (int)($db->getTelegramServiceSettings()['enabled'] ?? 0) === 1;
    $whatsappAvailable = linnms_worker_whatsapp_allowed($db)
        && (int)($db->getWhatsAppNotificationServiceSettings()['enabled'] ?? 0) === 1;
    if (!$telegramAvailable && !$whatsappAvailable) {
        exit(0);
    }

    $users = [];
    if ($telegramAvailable) {
        foreach ($db->getTelegramNotifiableUsers() as $user) $users[(int)$user['id']] = $user;
    }
    if ($whatsappAvailable) {
        foreach ($db->getWhatsAppNotifiableUsers() as $user) $users[(int)$user['id']] = $user;
    }

    foreach ($users as $user) {
        $userId = (int)($user['id'] ?? 0);
        if ($userId < 1) continue;

        try {
            $result = $service->sendPendingTelegramChanges($userId);
            $username = (string)($user['username'] ?? ('user-' . $userId));

            $status = (string)($result['status'] ?? 'kosong');
            $pending = (int)($result['pending'] ?? 0);
            $sent = (int)($result['sent'] ?? 0);
            $failedEvents = (int)($result['failed'] ?? 0);
            $skipped = (int)($result['skipped'] ?? 0);
            $superseded = (int)($result['superseded'] ?? 0);
            $sendError = trim((string)($result['error'] ?? ''));
            if ($failedEvents > 0) {
                $failed++;
            }

        } catch (Throwable $e) {
            $failed++;
            try {
                $db->logApplication(null, 'System', 'scheduled_notification', 'error', 'Pengguna #' . $userId . ': Telegram: ' . (string)$e);
            } catch (Throwable) {
            }
            $username = (string)($user['username'] ?? ('user-' . $userId));
            fwrite(
                STDERR,
                "User : {$username} | diperiksa=1 | antrean=0 | terkirim=0 | gagal=0 | dilewati=0 | digabung=0 | status=error | error=1\n"
            );
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . "\n");
    $failed++;
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

exit($failed > 0 ? 1 : 0);
