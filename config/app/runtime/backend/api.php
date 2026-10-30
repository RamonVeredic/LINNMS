<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/paths.php';
require_once __DIR__ . '/helpers/ApiJson.php';
require_once __DIR__ . '/helpers/ActionResultCodes.php';
require_once __DIR__ . '/helpers/InternalApiAuth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    ApiJson::error('method_not_allowed', 'Method tidak didukung.', 405);
}

$rawBody = file_get_contents('php://input');
$rawBody = is_string($rawBody) ? $rawBody : '';

try {
    $userId = InternalApiAuth::verify($rawBody);
} catch (InternalApiAuthException $error) {
    ApiJson::error('unauthorized', $error->getMessage(), 401);
} catch (Throwable $error) {
    error_log('[LINNMS] Internal API auth: ' . $error->getMessage());
    ApiJson::error('internal_auth_unavailable', 'API internal tidak siap.', 503);
}

try {
    $request = ApiJson::decode($rawBody);
} catch (Throwable $error) {
    ApiJson::error('invalid_json', $error->getMessage(), 422);
}

$resource = trim((string)($request['resource'] ?? ''));
$params = is_array($request['params'] ?? null) ? $request['params'] : [];
if ($userId === 0 && !in_array($resource, ['auth.guest', 'guest.page.read', 'app.log.write', 'app.entry.read', 'app.entry.action', 'app.bootstrap.read'], true)) {
    ApiJson::error('unauthorized', 'Akses API internal ditolak.', 401);
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
    require_once __DIR__ . '/services/OnuRegistrationFeedback.php';
    require_once __DIR__ . '/services/OltActionService.php';
    require_once __DIR__ . '/services/OltTerminalActionService.php';
    require_once __DIR__ . '/controllers/DashboardReadController.php';
    require_once __DIR__ . '/controllers/ConnectionTestController.php';
    require_once __DIR__ . '/controllers/OltViewReadController.php';
    require_once __DIR__ . '/controllers/OnuViewReadController.php';
    require_once __DIR__ . '/controllers/OnuListReadController.php';
    require_once __DIR__ . '/controllers/AddOnuReadController.php';

    $db = new LinnmsRepository((array)$config['database']);
    $actionResult = static function (array $result) use ($db, $userId, $resource, $params): array {
        return ActionResultCodes::normalize($result, $db, $userId,
            trim((string)($params['action'] ?? $resource)));
    };
    $GLOBALS['bridge'] = new RuntimeBridge($db->pdo(), (array)(require '/config/app/config/service.php'));
    $driverCatalog = require dirname(LINNMS_ROOT) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'monitoring-drivers.php';
    if (!is_array($driverCatalog)) {
        throw new RuntimeException('Katalog driver tidak valid.');
    }

    if ($resource === 'app.bootstrap.read') {
        $publicCatalog = [];
        foreach ($driverCatalog as $driverId => $definition) {
            if (!is_array($definition)) continue;
            unset($definition['driver_class'], $definition['driver_file'], $definition['cli_terminal_class']);
            $publicCatalog[$driverId] = $definition;
        }
        $providerCatalog = [];
        foreach (WhatsAppGateway::providers() as $providerId => $definition) {
            if (!is_array($definition)) continue;
            $providerCatalog[$providerId] = [
                'label' => (string)($definition['label'] ?? $providerId),
                'verified' => !empty($definition['verified']),
                'info' => (string)($definition['info'] ?? ''),
                'info_url' => (string)($definition['info_url'] ?? ''),
                'fields' => (array)($definition['fields'] ?? []),
                'target' => (array)($definition['target'] ?? []),
                'groups_available' => trim((string)($definition['groups']['url'] ?? '')) !== '',
                'token_generator' => ['enabled' => !empty($definition['token_generator']['enabled'])],
            ];
        }
        ApiJson::ok([
            'driver_catalog' => $publicCatalog,
            'device_access_permissions' => $GLOBALS['bridge']->devicePermissions(),
            'olt_type_options' => olt_type_options(),
            'whatsapp_providers' => $providerCatalog,
            'onu_backup_limits' => [
                'max_file_bytes' => OnuBackupService::MAX_FILE_BYTES,
                'preview_ttl_seconds' => OnuBackupService::PREVIEW_TTL_SECONDS,
            ],
        ]);
    }

    if ($resource === 'app.entry.read') {
        $access = $GLOBALS['bridge']->state();
        $mode = (string)($access['mode'] ?? 'pending');
        if ($mode === 'ended') $GLOBALS['bridge']->cleanupDeadline();
        $subject = trim((string)($access['row']['subject'] ?? ''));
        if ($mode === 'ready' && $subject !== '') $db->synchronizeVerifiedAdmin($subject);
        $identity = $mode === 'ready' ? $db->getAppIdentity() : LinnmsDefaults::APP_IDENTITY;
        ApiJson::ok([
            'mode' => $mode,
            'subject' => $subject,
            'profile_exists' => $mode === 'ready' && $db->usernameExists($subject),
            'identity' => $identity,
            'service_whatsapp' => $mode === 'ended' ? normalize_phone_number($db->getServiceContact()['whatsapp'] ?? '') : '',
            'session_user' => $userId > 0 ? $db->getUser($userId) : null,
        ]);
    }

    if ($resource === 'app.entry.action') {
        $operation = (string)($params['action'] ?? '');
        $input = is_array($params['data'] ?? null) ? $params['data'] : [];
        $mode = (string)($GLOBALS['bridge']->state()['mode'] ?? 'pending');
        try {
            if ($operation === 'access_submit' && in_array($mode, ['pending', 'fault'], true)) {
                $GLOBALS['bridge']->open((string)($input['account'] ?? ''), (string)($input['token'] ?? ''), $db);
                ApiJson::ok(['completed' => true]);
            }
            if ($operation === 'setup_submit' && $mode === 'ready') {
                $createdId = $GLOBALS['bridge']->createProfile($db, $input);
                $createdUser = $db->getUser($createdId);
                if (!is_array($createdUser)) throw new RuntimeException('Akun yang baru dibuat belum dapat dimuat.');
                ApiJson::ok(['user' => $createdUser]);
            }
        } catch (InvalidArgumentException | RuntimeException $error) {
            ApiJson::error('entry_failed', $error->getMessage(), 422);
        }
        ApiJson::error('forbidden', 'Aksi tidak tersedia.', 403);
    }

    if ($resource === 'guest.page.read') {
        ApiJson::ok([
            'public_registration_enabled' => $GLOBALS['bridge']->allowsPublicRegistration(),
            'service_contact' => $db->getServiceContact(),
            'registration_rules' => [
                'otp_length' => LinnmsRepository::WHATSAPP_OTP_LENGTH,
                'otp_ttl_seconds' => LinnmsRepository::WHATSAPP_OTP_TTL_SECONDS,
                'min_password_length' => LinnmsRepository::REGISTRATION_MIN_PASSWORD_LENGTH,
                'min_fill_seconds' => LinnmsRepository::REGISTRATION_MIN_FILL_SECONDS,
            ],
        ]);
    }

    if ($resource === 'app.log.write') {
        $db->logApplication(
            $userId > 0 ? $userId : null,
            mb_substr(trim((string)($params['area'] ?? 'LINNMS APP')), 0, 120),
            mb_substr(trim((string)($params['action'] ?? 'application_error')), 0, 120),
            mb_substr(trim((string)($params['level'] ?? 'info')), 0, 20),
            mb_substr((string)($params['message'] ?? ''), 0, 4000),
            is_array($params['context'] ?? null) ? $params['context'] : [],
            mb_substr(trim((string)($params['actor'] ?? '')), 0, 120) ?: null,
            mb_substr(trim((string)($params['ip_address'] ?? '')), 0, 64) ?: null
        );
        ApiJson::ok(['written' => true]);
    }

    if ($resource === 'dashboard.read') {
        $controller = new DashboardReadController(new DashboardReadService($db, $driverCatalog));
        ApiJson::ok($controller->read($userId, $params), [
            'resource' => $resource,
            'generated_at' => gmdate('c'),
        ]);
    }

    if ($resource === 'activity.read') {
        ApiJson::ok((new ActivityReadService($db))->read($userId, $params));
    }

    if ($resource === 'page.shell.read') {
        $shell = (new PageShellReadService($db))->read($userId, $params);
        $shell['telegram_feature_allowed'] = $GLOBALS['bridge']->allowsTelegram();
        $shell['whatsapp_feature_allowed'] = $GLOBALS['bridge']->allowsWhatsApp();
        $accessState = $GLOBALS['bridge']->state();
        $shell['service_access_state'] = [
            'mode' => (string)($accessState['mode'] ?? ''),
            'claims' => [
                'exp' => (string)($accessState['claims']['exp'] ?? ''),
                'tier' => (string)($accessState['claims']['tier'] ?? ''),
                'quota' => is_array($accessState['claims']['quota'] ?? null) ? $accessState['claims']['quota'] : [],
            ],
        ];
        $shell['external_api_available'] = (new ApiClientFactory((array)$config, new ApiRequestLogger($db)))
            ->forUser($userId) !== null;
        ApiJson::ok($shell);
    }

    if ($resource === 'session.user.read') {
        ApiJson::ok(['user' => $db->getUser($userId)]);
    }

    if ($resource === 'olt.terminal.action') {
        require_once project_path('app', 'OltTerminalBrokerClient.php');
        $service = new OltTerminalActionService($db, new CredentialCipher((string)($config['credential_key'] ?? '')));
        ApiJson::ok($service->execute($userId, $params));
    }

    if ($resource === 'data.snapshot.read') {
        ApiJson::ok((new DataSnapshotReadService($db))->read($userId, $params));
    }

    if ($resource === 'device.snapshot.read') {
        ApiJson::ok((new DeviceSnapshotReadService($db))->read($userId,
            (int)($params['row_id'] ?? 0), (string)($params['kind'] ?? '')));
    }

    if ($resource === 'whatsapp.fields.read') {
        ApiJson::ok((new WhatsAppFieldsReadService($db))->read($userId, trim((string)($params['provider'] ?? ''))));
    }

    if ($resource === 'dashboard.detail.read') {
        ApiJson::ok((new DashboardDetailReadService($db))->read($userId, $params));
    }

    if ($resource === 'device.realtime.read') {
        require_once project_path('vendor', 'driver', 'TrafficService.php');
        require_once project_path('app', 'DeviceRealtimeService.php');
        ApiJson::ok((new DeviceRealtimeReadService($db, (array)$config,
            new CredentialCipher((string)($config['credential_key'] ?? ''))))->read($userId, $params));
    }

    if ($resource === 'live.device.action') {
        ApiJson::ok((new LiveDeviceActionService($db, new SnmpOltService($db, (array)$config,
            new CredentialCipher((string)($config['credential_key'] ?? '')), new OltSnmpDriverFactory())))
            ->execute($userId, (string)($params['type'] ?? ''),
                is_array($params['data'] ?? null) ? $params['data'] : []));
    }

    if ($resource === 'live.traffic') {
        require_once project_path('app', 'OnuTrafficLiveStore.php');
        ApiJson::ok((new LiveTrafficService($db, (array)$config))
            ->execute($userId, (string)($params['type'] ?? ''),
                is_array($params['input'] ?? null) ? $params['input'] : []));
    }

    if ($resource === 'traffic.history.read') {
        ApiJson::ok((new TrafficHistoryReadService($db))->read($userId,
            (string)($params['type'] ?? ''), is_array($params['input'] ?? null) ? $params['input'] : []));
    }

    if ($resource === 'auth.guest') {
        if ($userId !== 0) ApiJson::error('unauthorized', 'Akses API internal ditolak.', 401);
        $bridge = new RuntimeBridge($db->pdo(), (array)(require '/config/app/config/service.php'));
        ApiJson::ok($actionResult((new AuthGuestActionService($db, $bridge))
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
        ApiJson::ok($actionResult($service->execute($userId, (string)($params['action'] ?? ''),
            is_array($params['data'] ?? null) ? $params['data'] : [])));
    }

    if ($resource === 'onu.registration.action') {
        $action = (string)($params['action'] ?? '');
        $service = new OnuRegistrationActionService(
            $db,
            new SnmpOltService($db, (array)$config,
                new CredentialCipher((string)($config['credential_key'] ?? '')),
                new OltSnmpDriverFactory())
        );
        ApiJson::ok(OnuRegistrationFeedback::decorate($actionResult($service->execute($userId, $action,
            is_array($params['data'] ?? null) ? $params['data'] : [])), $action));
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
        ApiJson::ok($actionResult($service->execute($userId, (string)($params['action'] ?? ''),
            is_array($params['data'] ?? null) ? $params['data'] : [])));
    }

    if ($resource === 'device.action') {
        $service = new DeviceManagementActionService($db, new SnmpDeviceService($db, (array)$config),
            new SnmpOltService($db, (array)$config,
                new CredentialCipher((string)($config['credential_key'] ?? '')), new OltSnmpDriverFactory()));
        ApiJson::ok($actionResult($service->execute($userId, (string)($params['action'] ?? ''),
            is_array($params['data'] ?? null) ? $params['data'] : [])));
    }

    if ($resource === 'olt.view.read') {
        $serviceConfig = require '/config/app/config/service.php';
        $GLOBALS['bridge'] = new RuntimeBridge($db->pdo(), (array)$serviceConfig);
        $controller = new OltViewReadController(new OltViewReadService($db));
        ApiJson::ok($controller->read($userId, $params));
    }

    if ($resource === 'onu.view.read') {
        $serviceConfig = require '/config/app/config/service.php';
        $GLOBALS['bridge'] = new RuntimeBridge($db->pdo(), (array)$serviceConfig);
        $controller = new OnuViewReadController(new OnuViewReadService($db));
        ApiJson::ok($controller->read($userId, $params));
    }

    if ($resource === 'onu.list.read') {
        $controller = new OnuListReadController(new OnuListReadService($db));
        ApiJson::ok($controller->read($userId, $params));
    }

    if ($resource === 'onu.add.read') {
        $controller = new AddOnuReadController(new AddOnuReadService($db));
        ApiJson::ok($controller->read($userId));
    }

    if ($resource === 'external_olt.action') {
        $controller = new DriverExternalActionService(
            $db,
            (array)$config,
            new ApiClientFactory($config, new ApiRequestLogger($db))
        );
        ApiJson::ok($actionResult($controller->execute($userId, (string)($params['action'] ?? ''),
            is_array($params['data'] ?? null) ? $params['data'] : [])));
    }

    if ($resource === 'user.action') {
        ApiJson::ok($actionResult((new UserActionService($db))->execute($userId, (string)($params['action'] ?? ''),
            is_array($params['data'] ?? null) ? $params['data'] : [])));
    }

    if ($resource === 'settings.action') {
        ApiJson::ok($actionResult((new SettingsActionService($db))->execute($userId, (string)($params['action'] ?? ''),
            is_array($params['data'] ?? null) ? $params['data'] : [])));
    }

    if ($resource === 'telegram.action') {
        $bridge = new RuntimeBridge($db->pdo(), (array)(require '/config/app/config/service.php'));
        ApiJson::ok($actionResult((new TelegramActionService($db, new TelegramNotifier(), $bridge))
            ->execute($userId, (string)($params['action'] ?? ''),
                is_array($params['data'] ?? null) ? $params['data'] : [])));
    }

    if ($resource === 'whatsapp.action') {
        $bridge = new RuntimeBridge($db->pdo(), (array)(require '/config/app/config/service.php'));
        ApiJson::ok($actionResult((new WhatsAppActionService($db, $bridge))
            ->execute($userId, (string)($params['action'] ?? ''),
                is_array($params['data'] ?? null) ? $params['data'] : [])));
    }

    if ($resource === 'connection_test') {
        $type = is_scalar($params['type'] ?? null) ? trim((string)$params['type']) : '';
        $data = is_array($params['data'] ?? null) ? $params['data'] : [];
        $controller = new ConnectionTestController($db, (array)$config, $GLOBALS['bridge']);
        $result = $controller->test($userId, $type, $data);
        ApiJson::ok($type === 'device' ? $result
            : ActionResultCodes::normalize($result, $db, $userId, 'connection_test_' . $type));
    }

    ApiJson::error('unknown_resource', 'Resource API tidak dikenal.', 404);
} catch (Throwable $error) {
    error_log('[LINNMS] Internal API: ' . $error->getMessage());
    ApiJson::error('internal_error', 'Data tidak dapat diproses.', 500);
}
