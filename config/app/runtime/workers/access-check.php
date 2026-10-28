<?php

declare(strict_types=1);

function linnms_worker_bridge(LinnmsRepository $db): RuntimeBridge
{
    require_once project_path('app', 'CanonicalJson.php');
    require_once project_path('app', 'RuntimeBridge.php');

    $config = require '/config/app/config/service.php';
    $bridge = new RuntimeBridge($db->pdo(), (array)$config);
    $GLOBALS['bridge'] = $bridge;
    return $bridge;
}


function linnms_worker_access_allowed(LinnmsRepository $db): bool
{
    try {
        return (linnms_worker_bridge($db)->state()['mode'] ?? '') === 'ready';
    } catch (Throwable $error) {
        fwrite(STDERR, '[worker] Pemeriksaan akses gagal: ' . $error->getMessage() . PHP_EOL);
        return false;
    }
}

function linnms_worker_telegram_allowed(LinnmsRepository $db): bool
{
    try {
        return linnms_worker_bridge($db)->allowsTelegram();
    } catch (Throwable $error) {
        fwrite(STDERR, '[worker] Pemeriksaan fitur Telegram gagal: ' . $error->getMessage() . PHP_EOL);
        return false;
    }
}

function linnms_worker_whatsapp_allowed(LinnmsRepository $db): bool
{
    try {
        return linnms_worker_bridge($db)->allowsWhatsApp();
    } catch (Throwable $error) {
        fwrite(STDERR, '[worker] Pemeriksaan fitur WhatsApp gagal: ' . $error->getMessage() . PHP_EOL);
        return false;
    }
}
