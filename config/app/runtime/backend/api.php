<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/paths.php';
require_once __DIR__ . '/helpers/BackendJson.php';
require_once __DIR__ . '/helpers/BackendResultCodes.php';
require_once __DIR__ . '/helpers/InternalApiAuth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    BackendJson::error('method_not_allowed', 'Method tidak didukung.', 405);
}

$rawBody = file_get_contents('php://input');
$rawBody = is_string($rawBody) ? $rawBody : '';

try {
    $userId = InternalApiAuth::verify($rawBody);
} catch (InternalApiAuthException $error) {
    BackendJson::error('unauthorized', $error->getMessage(), 401);
} catch (Throwable $error) {
    error_log('[LINNMS] Internal API auth: ' . $error->getMessage());
    BackendJson::error('internal_auth_unavailable', 'API internal tidak siap.', 503);
}

try {
    $request = BackendJson::decode($rawBody);
} catch (Throwable $error) {
    BackendJson::error('invalid_json', $error->getMessage(), 422);
}

$resource = trim((string)($request['resource'] ?? ''));
$params = is_array($request['params'] ?? null) ? $request['params'] : [];
if ($userId === 0 && !in_array($resource, ['auth.guest', 'guest.page.read'], true)) {
    BackendJson::error('unauthorized', 'Akses API internal ditolak.', 401);
}

try {
    $config = require project_path('config', 'defaults.php');
    require_once project_path('src', 'load-repository.php');
    require_once project_path('src', 'helpers.php');
    require_once project_path('vendor', 'driver', 'olt', 'zetset', 'ApiClient.php');
    require_once project_path('vendor', 'driver', 'olt', 'zetset', 'ApiClientFactory.php');
    require_once project_path('app', 'ApiRequestLogger.php');
    require_once project_path('app', 'CredentialCipher.php');
    require_once project_path('app', 'SnmpOltService.php');
    require_once project_path('app', 'SnmpDeviceService.php');
    require_once project_path('app', 'OnuBackupService.php');
    require_once project_path('app', 'CanonicalJson.php');
    require_once project_path('app', 'RuntimeBridge.php');
    require_once project_path('src', 'TelegramNotifier.php');
    require_once project_path('src', 'WhatsAppGateway.php');
    require_once project_path('vendor', 'driver', 'SnmpDeviceDriver.php');
    require_once project_path('vendor', 'driver', 'SnmpDriverFactory.php');
    require_once project_path('vendor', 'driver', 'olt', 'OltAware.php');
    require_once __DIR__ . '/services/ActivityReadService.php';
    require_once __DIR__ . '/services/DashboardReadService.php';
    require_once __DIR__ . '/services/DeviceConnectionTestService.php';
    require_once __DIR__ . '/services/DeviceManagementActionService.php';
    require_once __DIR__ . '/services/DeviceSnapshotReadService.php';
    require_once __DIR__ . '/services/GraphSampleWriteService.php';
    require_once __DIR__ . '/services/TrafficHistoryReadService.php';
    require_once __DIR__ . '/services/OltSyncOrchestrator.php';
    require_once __DIR__ . '/services/OltViewReadService.php';
    require_once __DIR__ . '/services/OnuViewReadService.php';
    require_once __DIR__ . '/services/OnuListReadService.php';
    require_once __DIR__ . '/services/AddOnuReadService.php';
    require_once __DIR__ . '/services/PageShellReadService.php';
    require_once __DIR__ . '/services/DataSnapshotReadService.php';
    require_once __DIR__ . '/services/WhatsAppFieldsReadService.php';
    require_once __DIR__ . '/services/DashboardDetailReadService.php';
    require_once __DIR__ . '/live/DeviceRealtimeReadService.php';
    require_once __DIR__ . '/services/LiveDeviceActionService.php';
    require_once __DIR__ . '/live/LiveTrafficService.php';
    require_once __DIR__ . '/services/DriverExternalActionService.php';
    require_once __DIR__ . '/services/UserActionService.php';
    require_once __DIR__ . '/services/SettingsActionService.php';
    require_once __DIR__ . '/services/TelegramActionService.php';
    require_once __DIR__ . '/services/WhatsAppActionService.php';
    require_once __DIR__ . '/services/AuthGuestActionService.php';
    require_once __DIR__ . '/services/OnuActionService.php';
    require_once __DIR__ . '/services/OnuRegistrationActionService.php';
    require_once __DIR__ . '/services/OltActionService.php';
    require_once __DIR__ . '/controllers/DashboardReadController.php';
    require_once __DIR__ . '/controllers/ConnectionTestController.php';
    require_once __DIR__ . '/controllers/OltViewReadController.php';
    require_once __DIR__ . '/controllers/OnuViewReadController.php';
    require_once __DIR__ . '/controllers/OnuListReadController.php';
    require_once __DIR__ . '/controllers/AddOnuReadController.php';

    $db = new LinnmsRepository((array)$config['database']);
    $actionResult = static function (array $result) use ($db, $userId, $resource, $params): array {
        return BackendResultCodes::normalize($result, $db, $userId,
            trim((string)($params['action'] ?? $resource)));
    };
    $GLOBALS['bridge'] = new RuntimeBridge($db->pdo(), (array)(require '/config/app/config/service.php'));
    $driverCatalog = require dirname(LINNMS_ROOT) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'monitoring-drivers.php';
    if (!is_array($driverCatalog)) {
        throw new RuntimeException('Katalog driver tidak valid.');
    }

    if ($resource === 'guest.page.read') {
        BackendJson::ok([
            'public_registration_enabled' => $GLOBALS['bridge']->allowsPublicRegistration(),
            'service_contact' => $db->getServiceContact(),
        ]);
    }

    if ($resource === 'dashboard.read') {
        $controller = new DashboardReadController(new DashboardReadService($db, $driverCatalog));
        BackendJson::ok($controller->read($userId, $params), [
            'resource' => $resource,
            'generated_at' => gmdate('c'),
        ]);
    }

    if ($resource === 'activity.read') {
        BackendJson::ok((new ActivityReadService($db))->read($userId, $params));
    }

    if ($resource === 'page.shell.read') {
        BackendJson::ok((new PageShellReadService($db))->read($userId, $params));
    }

    if ($resource === 'data.snapshot.read') {
        BackendJson::ok((new DataSnapshotReadService($db))->read($userId, $params));
    }

    if ($resource === 'device.snapshot.read') {
        BackendJson::ok((new DeviceSnapshotReadService($db))->read($userId,
            (int)($params['row_id'] ?? 0), (string)($params['kind'] ?? '')));
    }

    if ($resource === 'whatsapp.fields.read') {
        BackendJson::ok((new WhatsAppFieldsReadService($db))->read($userId, trim((string)($params['provider'] ?? ''))));
    }

    if ($resource === 'dashboard.detail.read') {
        BackendJson::ok((new DashboardDetailReadService($db))->read($userId, $params));
    }

    if ($resource === 'device.realtime.read') {
        require_once project_path('vendor', 'driver', 'TrafficService.php');
        require_once project_path('app', 'DeviceRealtimeService.php');
        BackendJson::ok((new DeviceRealtimeReadService($db, (array)$config,
            new CredentialCipher((string)($config['credential_key'] ?? ''))))->read($userId, $params));
    }

    if ($resource === 'live.device.action') {
        BackendJson::ok((new LiveDeviceActionService($db, new SnmpOltService($db, (array)$config,
            new CredentialCipher((string)($config['credential_key'] ?? '')), new OltSnmpDriverFactory())))
            ->execute($userId, (string)($params['type'] ?? ''),
                is_array($params['data'] ?? null) ? $params['data'] : []));
    }

    if ($resource === 'live.traffic') {
        require_once project_path('app', 'OnuTrafficLiveStore.php');
        BackendJson::ok((new LiveTrafficService($db, (array)$config))
            ->execute($userId, (string)($params['type'] ?? ''),
                is_array($params['input'] ?? null) ? $params['input'] : []));
    }

    if ($resource === 'traffic.history.read') {
        BackendJson::ok((new TrafficHistoryReadService($db))->read($userId,
            (string)($params['type'] ?? ''), is_array($params['input'] ?? null) ? $params['input'] : []));
    }

    if ($resource === 'auth.guest') {
        if ($userId !== 0) BackendJson::error('unauthorized', 'Akses API internal ditolak.', 401);
        $bridge = new RuntimeBridge($db->pdo(), (array)(require '/config/app/config/service.php'));
        BackendJson::ok($actionResult((new AuthGuestActionService($db, $bridge))
            ->execute((string)($params['action'] ?? ''),
                is_array($params['data'] ?? null) ? $params['data'] : [])));
    }

    if ($resource === 'onu.action') {
        $service = new OnuActionService(
            $db,
            new SnmpOltService($db, (array)$config,
                new CredentialCipher((string)($config['credential_key'] ?? '')),
                new OltSnmpDriverFactory()),
            new OnuBackupService($db)
        );
        BackendJson::ok($actionResult($service->execute($userId, (string)($params['action'] ?? ''),
            is_array($params['data'] ?? null) ? $params['data'] : [])));
    }

    if ($resource === 'onu.registration.action') {
        $service = new OnuRegistrationActionService(
            $db,
            new SnmpOltService($db, (array)$config,
                new CredentialCipher((string)($config['credential_key'] ?? '')),
                new OltSnmpDriverFactory())
        );
        BackendJson::ok($actionResult($service->execute($userId, (string)($params['action'] ?? ''),
            is_array($params['data'] ?? null) ? $params['data'] : [])));
    }

    if ($resource === 'olt.action') {
        $service = new OltActionService(
            $db,
            new SnmpOltService($db, (array)$config,
                new CredentialCipher((string)($config['credential_key'] ?? '')),
                new OltSnmpDriverFactory()),
            new ApiClientFactory((array)$config, new ApiRequestLogger($db)),
            (array)$config
        );
        BackendJson::ok($actionResult($service->execute($userId, (string)($params['action'] ?? ''),
            is_array($params['data'] ?? null) ? $params['data'] : [])));
    }

    if ($resource === 'device.action') {
        $service = new DeviceManagementActionService($db, new SnmpDeviceService($db, (array)$config),
            new SnmpOltService($db, (array)$config,
                new CredentialCipher((string)($config['credential_key'] ?? '')), new OltSnmpDriverFactory()));
        BackendJson::ok($actionResult($service->execute($userId, (string)($params['action'] ?? ''),
            is_array($params['data'] ?? null) ? $params['data'] : [])));
    }

    if ($resource === 'olt.view.read') {
        $serviceConfig = require '/config/app/config/service.php';
        $GLOBALS['bridge'] = new RuntimeBridge($db->pdo(), (array)$serviceConfig);
        require_once project_path('view', 'shared', 'models', 'OltDetailViewModel.php');
        $controller = new OltViewReadController(new OltViewReadService($db));
        BackendJson::ok($controller->read($userId, $params));
    }

    if ($resource === 'onu.view.read') {
        $serviceConfig = require '/config/app/config/service.php';
        $GLOBALS['bridge'] = new RuntimeBridge($db->pdo(), (array)$serviceConfig);
        require_once project_path('view', 'shared', 'models', 'OltDetailViewModel.php');
        $controller = new OnuViewReadController(new OnuViewReadService($db));
        BackendJson::ok($controller->read($userId, $params));
    }

    if ($resource === 'onu.list.read') {
        $controller = new OnuListReadController(new OnuListReadService($db));
        BackendJson::ok($controller->read($userId, $params));
    }

    if ($resource === 'onu.add.read') {
        $controller = new AddOnuReadController(new AddOnuReadService($db));
        BackendJson::ok($controller->read($userId));
    }

    if ($resource === 'external_olt.action') {
        $controller = new DriverExternalActionService(
            $db,
            (array)$config,
            new ApiClientFactory($config, new ApiRequestLogger($db))
        );
        BackendJson::ok($actionResult($controller->execute($userId, (string)($params['action'] ?? ''),
            is_array($params['data'] ?? null) ? $params['data'] : [])));
    }

    if ($resource === 'user.action') {
        BackendJson::ok($actionResult((new UserActionService($db))->execute($userId, (string)($params['action'] ?? ''),
            is_array($params['data'] ?? null) ? $params['data'] : [])));
    }

    if ($resource === 'settings.action') {
        BackendJson::ok($actionResult((new SettingsActionService($db))->execute($userId, (string)($params['action'] ?? ''),
            is_array($params['data'] ?? null) ? $params['data'] : [])));
    }

    if ($resource === 'telegram.action') {
        $bridge = new RuntimeBridge($db->pdo(), (array)(require '/config/app/config/service.php'));
        BackendJson::ok($actionResult((new TelegramActionService($db, new TelegramNotifier(), $bridge))
            ->execute($userId, (string)($params['action'] ?? ''),
                is_array($params['data'] ?? null) ? $params['data'] : [])));
    }

    if ($resource === 'whatsapp.action') {
        $bridge = new RuntimeBridge($db->pdo(), (array)(require '/config/app/config/service.php'));
        BackendJson::ok($actionResult((new WhatsAppActionService($db, $bridge))
            ->execute($userId, (string)($params['action'] ?? ''),
                is_array($params['data'] ?? null) ? $params['data'] : [])));
    }

    if ($resource === 'connection_test') {
        $type = is_scalar($params['type'] ?? null) ? trim((string)$params['type']) : '';
        $data = is_array($params['data'] ?? null) ? $params['data'] : [];
        $controller = new ConnectionTestController($db, (array)$config, $GLOBALS['bridge']);
        $result = $controller->test($userId, $type, $data);
        BackendJson::ok($type === 'device' ? $result
            : BackendResultCodes::normalize($result, $db, $userId, 'connection_test_' . $type));
    }

    BackendJson::error('unknown_resource', 'Resource API tidak dikenal.', 404);
} catch (Throwable $error) {
    error_log('[LINNMS] Internal API: ' . $error->getMessage());
    BackendJson::error('internal_error', 'Data tidak dapat diproses.', 500);
}
