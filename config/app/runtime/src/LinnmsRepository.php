<?php

declare(strict_types=1);
require_once __DIR__ . '/../config/config_default.php';

final class LinnmsRepository
{

    use LinnmsSchemaTrait;
    use LinnmsSettingTrait;
    use LinnmsAuthTrait;
    use LinnmsUserTrait;
    use LinnmsMonitorTrait;
    use LinnmsOltTrait;
    use LinnmsOltCliTrafficTrait;
    use LinnmsOnuTrait;
    use LinnmsOnuRegistrationTrait;
    use LinnmsDeviceTrait;
    use LinnmsDeviceStoreTrait;
    use LinnmsChangeEventTrait;
    use LinnmsSyncTrait;
    use LinnmsLogTrait;
    use LinnmsDashboardTrait;
    use LinnmsTelegramTrait;
    use LinnmsWhatsAppTrait;
    use LinnmsSupportTrait;

    private PDO $pdo;
    private string $databaseName;
    private ?array $appIdentityMemory = null;
    private array $monitorEntityIds = [];
    private ?bool $supportsSkipLocked = null;
    private array $appSettingMemory = [];
    private bool $oltScanTableEnsured = false;
    private array $rxDetectionMemory = [];
    private const APP_IDENTITY_CACHE_TTL = LinnmsDefaults::APP_IDENTITY_CACHE_TTL;
    private const OLT_PON_TX_POWER_THRESHOLD_DBM = LinnmsDefaults::OLT_PON_TX_POWER_THRESHOLD_DBM;
    private const ONU_REBOOT_TIMEOUT_SECONDS = LinnmsDefaults::ONU_REBOOT_TIMEOUT_SECONDS;
    private const LOGIN_FAILURE_LIMIT = LinnmsDefaults::LOGIN_FAILURE_LIMIT;
    private const LOGIN_WINDOW_MINUTES = LinnmsDefaults::LOGIN_WINDOW_MINUTES;
    private const LOGIN_MAX_BACKOFF_SECONDS = LinnmsDefaults::LOGIN_MAX_BACKOFF_SECONDS;
    public const DEFAULT_OLT_LIMIT = LinnmsDefaults::DEFAULT_OLT_LIMIT;
    public const DEFAULT_MIKROTIK_LIMIT = LinnmsDefaults::DEFAULT_MIKROTIK_LIMIT;
    public const DEFAULT_SWITCH_LIMIT = LinnmsDefaults::DEFAULT_SWITCH_LIMIT;
    public const ONU_PROBLEM_AFTER_HOURS = LinnmsDefaults::ONU_PROBLEM_AFTER_HOURS;
    public const ONU_RX_DETECTION_DEFAULT_DBM = LinnmsDefaults::ONU_RX_DETECTION_DEFAULT_DBM;
    public const ONU_RX_DETECTION_MIN_DBM = LinnmsDefaults::ONU_RX_DETECTION_MIN_DBM;
    public const ONU_RX_DETECTION_MAX_DBM = LinnmsDefaults::ONU_RX_DETECTION_MAX_DBM;
    public const ONU_RX_PROBLEM_DEFAULT_DBM = LinnmsDefaults::ONU_RX_PROBLEM_DEFAULT_DBM;
    public const ONU_RX_PROBLEM_MIN_DBM = LinnmsDefaults::ONU_RX_PROBLEM_MIN_DBM;
    public const ONU_RX_PROBLEM_MAX_DBM = LinnmsDefaults::ONU_RX_PROBLEM_MAX_DBM;
    public const WHATSAPP_OTP_LENGTH = LinnmsDefaults::WHATSAPP_OTP_LENGTH;
    public const WHATSAPP_OTP_TTL_SECONDS = LinnmsDefaults::WHATSAPP_OTP_TTL_SECONDS;
    public const REGISTRATION_MIN_PASSWORD_LENGTH = LinnmsDefaults::REGISTRATION_MIN_PASSWORD_LENGTH;
    public const REGISTRATION_MIN_FILL_SECONDS = LinnmsDefaults::REGISTRATION_MIN_FILL_SECONDS;
    public const REGISTRATION_FORM_MAX_AGE_SECONDS = LinnmsDefaults::REGISTRATION_FORM_MAX_AGE_SECONDS;
    public const REGISTRATION_THROTTLE_LIMIT = LinnmsDefaults::REGISTRATION_THROTTLE_LIMIT;
    public const REGISTRATION_THROTTLE_WINDOW_MINUTES = LinnmsDefaults::REGISTRATION_THROTTLE_WINDOW_MINUTES;
    public const DEFAULT_ONU_MISSING_SCAN_LIMIT = LinnmsDefaults::DEFAULT_ONU_MISSING_SCAN_LIMIT;
    public const MIN_ONU_MISSING_SCAN_LIMIT = LinnmsDefaults::MIN_ONU_MISSING_SCAN_LIMIT;
    public const MAX_ONU_MISSING_SCAN_LIMIT = LinnmsDefaults::MAX_ONU_MISSING_SCAN_LIMIT;
    public const DEFAULT_READER_COUNT = 3;
    public const MAX_READER_COUNT = 6;
    public const DEFAULT_FAST_SYNC_ONU_BATCH_SIZE = 20;
    public const MIN_FAST_SYNC_ONU_BATCH_SIZE = 1;
    public const MAX_FAST_SYNC_ONU_BATCH_SIZE = 100;
    public const DEFAULT_WRITER_COUNT = 1;
    public const MAX_WRITER_COUNT = 3;
    public const DEFAULT_SYNC_FAST_INTERVAL_MINUTES = LinnmsDefaults::DEFAULT_SYNC_FAST_INTERVAL_MINUTES;
    public const MIN_SYNC_FAST_INTERVAL_MINUTES = LinnmsDefaults::MIN_SYNC_FAST_INTERVAL_MINUTES;
    public const MAX_SYNC_FAST_INTERVAL_MINUTES = LinnmsDefaults::MAX_SYNC_FAST_INTERVAL_MINUTES;
    public const DEFAULT_OLT_GRAPH_INTERVAL_MINUTES = LinnmsDefaults::DEFAULT_OLT_GRAPH_INTERVAL_MINUTES;
    public const GRAPH_SAMPLE_LIMIT = LinnmsDefaults::GRAPH_SAMPLE_LIMIT;
    public const MIN_OLT_GRAPH_INTERVAL_MINUTES = LinnmsDefaults::MIN_OLT_GRAPH_INTERVAL_MINUTES;
    public const MAX_OLT_GRAPH_INTERVAL_MINUTES = LinnmsDefaults::MAX_OLT_GRAPH_INTERVAL_MINUTES;
    public const DEFAULT_SYNC_DISCOVERY_INTERVAL_MINUTES = LinnmsDefaults::DEFAULT_SYNC_DISCOVERY_INTERVAL_MINUTES;
    public const MIN_SYNC_DISCOVERY_INTERVAL_MINUTES = LinnmsDefaults::MIN_SYNC_DISCOVERY_INTERVAL_MINUTES;
    public const MAX_SYNC_DISCOVERY_INTERVAL_MINUTES = LinnmsDefaults::MAX_SYNC_DISCOVERY_INTERVAL_MINUTES;
    public const DEFAULT_SYNC_MIDDLE_INTERVAL_MINUTES = LinnmsDefaults::DEFAULT_SYNC_MIDDLE_INTERVAL_MINUTES;
    public const MIN_SYNC_MIDDLE_INTERVAL_MINUTES = LinnmsDefaults::MIN_SYNC_MIDDLE_INTERVAL_MINUTES;
    public const MAX_SYNC_MIDDLE_INTERVAL_MINUTES = LinnmsDefaults::MAX_SYNC_MIDDLE_INTERVAL_MINUTES;
    public const DEFAULT_SYNC_FAST_DEADLINE_SECONDS = LinnmsDefaults::DEFAULT_SYNC_FAST_DEADLINE_SECONDS;
    public const DEFAULT_SYNC_DISCOVERY_DEADLINE_SECONDS = LinnmsDefaults::DEFAULT_SYNC_DISCOVERY_DEADLINE_SECONDS;
    public const DEFAULT_SYNC_MIDDLE_DEADLINE_SECONDS = LinnmsDefaults::DEFAULT_SYNC_MIDDLE_DEADLINE_SECONDS;
    public const MIN_SYNC_DEADLINE_SECONDS = LinnmsDefaults::MIN_SYNC_DEADLINE_SECONDS;
    public const MAX_SYNC_DEADLINE_SECONDS = LinnmsDefaults::MAX_SYNC_DEADLINE_SECONDS;
    public const DEFAULT_SNMP_REQUEST_TIMEOUT_MS = LinnmsDefaults::DEFAULT_SNMP_REQUEST_TIMEOUT_MS;
    public const MAX_SNMP_REQUEST_TIMEOUT_MS = LinnmsDefaults::MAX_SNMP_REQUEST_TIMEOUT_MS;
    public const NOTIFICATION_HISTORY_LIMIT = LinnmsDefaults::NOTIFICATION_HISTORY_LIMIT;
    public const NOTIFICATION_HISTORY_PAGE_SIZE = LinnmsDefaults::NOTIFICATION_HISTORY_PAGE_SIZE;
    public const APPLICATION_LOG_PAGE_SIZE = LinnmsDefaults::APPLICATION_LOG_PAGE_SIZE;
    public const ACTIVITY_FEED_LIMIT = LinnmsDefaults::ACTIVITY_FEED_LIMIT;
    public const ACTIVITY_FEED_PAGE_SIZE = LinnmsDefaults::ACTIVITY_FEED_PAGE_SIZE;
    private const RECENT_ACTIVITY_LABELS = [
        'status onu' => ['onu_state', 'is_present'],
        'bermasalah' => ['problem_offline', 'problem_rx_power', 'problem_rx_recovered'],
        'Rx power' => ['rx_power'],
        'aktivitas onu' => ['onu_name', 'onu_location', 'onu_edit', 'auth_mode', 'onu_registration', 'deleted'],
    ];
    public function __construct(array $config)
    {
        foreach (['name', 'user', 'password', 'charset'] as $key) {
            if (!array_key_exists($key, $config)) {
                throw new RuntimeException("Konfigurasi database '{$key}' belum diatur di config/database.php.");
            }
        }

        $socket = trim((string) ($config['socket'] ?? ''));
        $host = trim((string) ($config['host'] ?? ''));
        $port = (int) ($config['port'] ?? 3306);
        $name = trim((string) $config['name']);
        $charset = trim((string) $config['charset']);
        $user = trim((string) $config['user']);
        $password = (string) $config['password'];

        if (($socket === '' && $host === '') || $name === '' || $charset === '' || $user === '') {
            throw new RuntimeException('Konfigurasi database di config/database.php tidak lengkap.');
        }
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('DB_PORT tidak valid.');
        }

        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new RuntimeException('DB_NAME hanya boleh berisi huruf, angka, dan underscore.');
        }
        $this->databaseName = $name;

        $dsn = $socket !== ''
            ? "mysql:unix_socket={$socket};dbname={$name};charset={$charset}"
            : "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        try {
            $this->pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Database LINNMS belum tersedia atau koneksi gagal. Jalankan migrasi database terlebih dahulu. Detail: ' . $e->getMessage());
        }
    }
}
