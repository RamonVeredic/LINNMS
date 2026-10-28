<?php

declare(strict_types=1);

require_once dirname(LINNMS_ROOT) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'session.php';
linnms_start_managed_session();

require_once project_path('src', 'helpers.php');
require_once project_path('src', 'RepositoryTraits', 'LinnmsSchemaTrait.php');
require_once project_path('src', 'RepositoryTraits', 'LinnmsSettingTrait.php');
require_once project_path('src', 'RepositoryTraits', 'LinnmsAuthTrait.php');
require_once project_path('src', 'RepositoryTraits', 'LinnmsUserTrait.php');
require_once project_path('src', 'RepositoryTraits', 'LinnmsMonitorTrait.php');
require_once project_path('src', 'RepositoryTraits', 'LinnmsOltTrait.php');
require_once project_path('src', 'RepositoryTraits', 'LinnmsOltCliTrafficTrait.php');
require_once project_path('src', 'RepositoryTraits', 'LinnmsOnuTrait.php');
require_once project_path('src', 'RepositoryTraits', 'LinnmsOnuRegistrationTrait.php');
require_once project_path('src', 'RepositoryTraits', 'LinnmsDeviceTrait.php');
require_once project_path('src', 'RepositoryTraits', 'LinnmsDeviceStoreTrait.php');
require_once project_path('src', 'RepositoryTraits', 'LinnmsChangeEventTrait.php');
require_once project_path('src', 'RepositoryTraits', 'LinnmsSyncTrait.php');
require_once project_path('src', 'RepositoryTraits', 'LinnmsLogTrait.php');
require_once project_path('src', 'RepositoryTraits', 'LinnmsDashboardTrait.php');
require_once project_path('src', 'RepositoryTraits', 'LinnmsTelegramTrait.php');
require_once project_path('src', 'RepositoryTraits', 'LinnmsWhatsAppTrait.php');
require_once project_path('src', 'RepositoryTraits', 'LinnmsSupportTrait.php');
require_once project_path('src', 'LinnmsRepository.php');
require_once project_path('vendor', 'driver', 'olt', 'zetset', 'ApiClient.php');
require_once project_path('src', 'WhatsAppGateway.php');
require_once project_path('app', 'ApiRequestLogger.php');
require_once project_path('app', 'CredentialCipher.php');
require_once project_path('app', 'OnuBackupService.php');
require_once project_path('vendor', 'driver', 'olt', 'zetset', 'ApiClientFactory.php');
require_once project_path('app', 'CanonicalJson.php');
require_once project_path('app', 'RuntimeBridge.php');

$config = require project_path('config', 'defaults.php');
$db = new LinnmsRepository((array)$config['database']);
$channelConfig = require '/config/app/config/service.php';
$bridge = new RuntimeBridge($db->pdo(), (array)$channelConfig);
$apiClients = new ApiClientFactory($config, new ApiRequestLogger($db));
$credentialCipher = new CredentialCipher((string) $config['credential_key']);
