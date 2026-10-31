<?php

declare(strict_types=1);

trait LinnmsSchemaTrait
{
    public function ensureSchema(array $defaultAdmin): void
    {
        if (!defined('LINNMS_DATABASE_MIGRATION') || LINNMS_DATABASE_MIGRATION !== true) {
            throw new LogicException('Perubahan skema hanya boleh dijalankan melalui database/migrate.php.');
        }
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(80) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            display_name VARCHAR(120) NULL,
            phone_number VARCHAR(24) NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'user',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            olt_limit BIGINT UNSIGNED NOT NULL DEFAULT 1,
            mikrotik_limit BIGINT UNSIGNED NOT NULL DEFAULT 1,
            switch_limit BIGINT UNSIGNED NOT NULL DEFAULT 1,
            last_login_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_users_role_active(role,is_active),
            INDEX idx_users_phone(phone_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        
        
        $this->createTenantTables();
        if ($this->pdo->query("SHOW COLUMNS FROM notification_history LIKE 'message'")->fetchColumn() === false) {
            $this->pdo->exec('ALTER TABLE notification_history ADD COLUMN message LONGTEXT NULL AFTER provider');
        }
        if ($this->pdo->query("SHOW COLUMNS FROM notification_history LIKE 'device_name'")->fetchColumn() === false) {
            $this->pdo->exec("ALTER TABLE notification_history ADD COLUMN device_name VARCHAR(500) NOT NULL DEFAULT '' AFTER message");
        }
        if ($this->pdo->query("SHOW COLUMNS FROM notification_history LIKE 'category'")->fetchColumn() === false) {
            $this->pdo->exec("ALTER TABLE notification_history ADD COLUMN category VARCHAR(80) NOT NULL DEFAULT '' AFTER device_name");
        }
        if ($this->pdo->query("SHOW COLUMNS FROM notification_history LIKE 'status'")->fetchColumn() === false) {
            $this->pdo->exec("ALTER TABLE notification_history ADD COLUMN status VARCHAR(16) NULL DEFAULT NULL AFTER category");
        }
        $statusColumn = $this->pdo->query("SHOW COLUMNS FROM notification_history LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        if ($statusColumn !== false) {
            $statusNullable = strtoupper((string)($statusColumn['Null'] ?? '')) === 'YES';
            $statusDefault = $statusColumn['Default'] ?? null;
            if (!$statusNullable || $statusDefault !== null) {
                $this->pdo->exec("ALTER TABLE notification_history MODIFY COLUMN status VARCHAR(16) NULL DEFAULT NULL");
            }
        }
        if ($this->pdo->query("SHOW COLUMNS FROM notification_history LIKE 'event_id'")->fetchColumn() === false) {
            $this->pdo->exec("ALTER TABLE notification_history ADD COLUMN event_id BIGINT UNSIGNED NULL AFTER status");
        }
        if ($this->pdo->query("SHOW COLUMNS FROM notification_history LIKE 'attempts'")->fetchColumn() === false) {
            $this->pdo->exec("ALTER TABLE notification_history ADD COLUMN attempts INT UNSIGNED NOT NULL DEFAULT 1 AFTER event_id");
        }
        if ($this->pdo->query("SHOW INDEX FROM notification_history WHERE Key_name='idx_notification_history_event'")->fetchColumn() === false) {
            $this->pdo->exec('ALTER TABLE notification_history ADD INDEX idx_notification_history_event(event_id)');
        }
        $firstEventHistoryId = $this->pdo->query("SELECT MIN(id) FROM notification_history WHERE event_id IS NOT NULL")->fetchColumn();
        if ($firstEventHistoryId !== false && $firstEventHistoryId !== null) {
            // Baris lama tidak punya status pengiriman; jangan pernah dianggap "sent".
            $this->pdo->exec("UPDATE notification_history SET status=NULL WHERE status='sent' AND event_id IS NULL AND id < " . (int)$firstEventHistoryId);
        }

        $this->ensureAppSettings();
        $this->ensureOnuTrafficSamplesTable();
        $this->ensureSyncPipelineTable();
        $this->ensureOltScanRequestsTable();
        $this->ensureUserSettingsForAllUsers();
        $this->pdo->exec("UPDATE user_settings SET setting_value=CONCAT('-', setting_value)
            WHERE setting_key='onu_rx_problem_threshold_dbm'
              AND setting_value REGEXP '^[0-9]+([.][0-9]+)?$'
              AND CAST(setting_value AS DECIMAL(10,2)) > 0");
    }

    
    
    private function ensureOnuTrafficSamplesTable(): void
    {
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS graph_onu_samples (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                device_id bigint unsigned NOT NULL,
                onu_id bigint unsigned NOT NULL,
                port_ref varchar(60) NOT NULL DEFAULT '',
                rx_bps decimal(20,2) NOT NULL DEFAULT 0,
                tx_bps decimal(20,2) NOT NULL DEFAULT 0,
                sampled_at datetime NOT NULL,
                created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_graph_onu_sample (device_id,onu_id,sampled_at),
                KEY idx_graph_onu_range (device_id,onu_id,sampled_at),
                CONSTRAINT fk_graph_onu_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE,
                CONSTRAINT fk_graph_onu_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) { error_log('Tabel graph_onu_samples: ' . $e->getMessage()); }
    }

    private function createTenantTables(): void
    {
        $sql = [];
        $sql[] = "CREATE TABLE IF NOT EXISTS user_settings (
            user_id BIGINT UNSIGNED NOT NULL,
            setting_key VARCHAR(100) NOT NULL,
            setting_value VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(user_id,setting_key),
            CONSTRAINT fk_user_settings_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $sql[] = "CREATE TABLE IF NOT EXISTS user_sync_states (
            user_id BIGINT UNSIGNED PRIMARY KEY,
            last_sync_at DATETIME NULL,
            last_api_sync_at DATETIME NULL,
            last_sync_status VARCHAR(20) NULL,
            last_sync_message TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_user_sync_states_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[] = "CREATE TABLE IF NOT EXISTS devices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            device_type VARCHAR(50) NOT NULL DEFAULT 'olt',
            type VARCHAR(100) NULL,
            source VARCHAR(30) NOT NULL DEFAULT 'api',
            olt_id BIGINT NULL,
            name VARCHAR(255) NULL,
            ip_address VARCHAR(255) NULL,
            snmp_port INT UNSIGNED NOT NULL DEFAULT 161,
            snmp_community TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_devices_user_source_olt(user_id,device_type,source,olt_id),
            INDEX idx_devices_user_type_present(user_id,device_type),
            INDEX idx_devices_user_type_name(user_id,device_type,name),
            CONSTRAINT fk_devices_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[] = "CREATE TABLE IF NOT EXISTS notification_device_targets (
            user_id BIGINT UNSIGNED NOT NULL,
            device_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY(user_id,device_id),
            INDEX idx_notification_target_device(device_id),
            CONSTRAINT fk_notification_target_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_notification_target_device FOREIGN KEY(device_id) REFERENCES devices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[] = "CREATE TABLE IF NOT EXISTS ignored_api_olts (
            user_id BIGINT UNSIGNED NOT NULL,
            olt_id BIGINT NOT NULL,
            name VARCHAR(255) NULL,
            deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(user_id,olt_id),
            CONSTRAINT fk_ignored_api_olts_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[] = "CREATE TABLE IF NOT EXISTS onus (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            olt_device_id BIGINT UNSIGNED NOT NULL,
            pon_id BIGINT UNSIGNED NULL,
            onu_index VARCHAR(255) NOT NULL,
            onu_type VARCHAR(255) NULL,
            actual_type VARCHAR(64) NULL,
            onu_serial VARCHAR(255) NULL,
            onu_name VARCHAR(255) NULL,
            onu_location VARCHAR(255) NULL,
            auth_confirmed_once TINYINT(1) NOT NULL DEFAULT 0,
            onu_state VARCHAR(100) NULL,
            auth_mode VARCHAR(20) NULL,
            rx_power DOUBLE NULL,
            detail_json LONGTEXT NULL,
            port_lan_json LONGTEXT NULL,
            is_present TINYINT(1) NOT NULL DEFAULT 1,
            missing_at TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_onus_olt_device_index(olt_device_id,onu_index),
            INDEX idx_onus_user_state(user_id,onu_state),
            INDEX idx_onus_user_device(user_id,olt_device_id),
            CONSTRAINT fk_onus_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_onus_olt_device FOREIGN KEY(olt_device_id) REFERENCES devices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        
        
        
        
        
        
        
        
        $sql[] = "CREATE TABLE IF NOT EXISTS olt_cards (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            olt_device_id BIGINT UNSIGNED NOT NULL,
            card_key VARCHAR(60) NOT NULL,
            slot_number INT NOT NULL DEFAULT 0,
            entity_index INT UNSIGNED NOT NULL DEFAULT 0,
            entity VARCHAR(30) NOT NULL DEFAULT '',
            model VARCHAR(120) NOT NULL DEFAULT '',
            label VARCHAR(80) NOT NULL DEFAULT '',
            description VARCHAR(191) NOT NULL DEFAULT '',
            label_updated_at DATETIME NULL,
            serial_number VARCHAR(120) NOT NULL DEFAULT '',
            hardware_version VARCHAR(60) NOT NULL DEFAULT '',
            software_version VARCHAR(60) NOT NULL DEFAULT '',
            role VARCHAR(20) NOT NULL DEFAULT 'other',
            port_count INT NOT NULL DEFAULT 0,
            ports_json TEXT NULL,
            versions_json TEXT NULL,
            cpu_percent DECIMAL(5,1) NULL,
            memory_percent DECIMAL(5,1) NULL,
            temperature DECIMAL(6,2) NULL,
            is_present TINYINT(1) NOT NULL DEFAULT 1,
            seen_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_olt_cards_device_key(olt_device_id,card_key),
            INDEX idx_olt_cards_user_device(user_id,olt_device_id),
            CONSTRAINT fk_olt_cards_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_olt_cards_device FOREIGN KEY(olt_device_id) REFERENCES devices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        
        
        $sql[] = "CREATE TABLE IF NOT EXISTS device_credentials (
            device_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            hybrid_status TINYINT(1) NOT NULL DEFAULT 0,
            ports SMALLINT UNSIGNED NULL DEFAULT NULL,
            username VARCHAR(190) NULL,
            password_enc TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_device_credentials_user(user_id),
            CONSTRAINT fk_device_credentials_device FOREIGN KEY(device_id) REFERENCES devices(id) ON DELETE CASCADE,
            CONSTRAINT fk_device_credentials_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        
        $sql[] = "CREATE TABLE IF NOT EXISTS device_systems (
            device_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            system_json LONGTEXT NULL,
            system_seen_at DATETIME NULL,
            interfaces_json LONGTEXT NULL,
            raw_json LONGTEXT NULL,
            model VARCHAR(191) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_device_systems_user(user_id),
            CONSTRAINT fk_device_systems_device FOREIGN KEY(device_id) REFERENCES devices(id) ON DELETE CASCADE,
            CONSTRAINT fk_device_systems_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[] = "CREATE TABLE IF NOT EXISTS device_scan_state (
            device_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            first_scan_approval_requested_at DATETIME NULL,
            first_scan_approved_at DATETIME NULL,
            first_scan_approved_by BIGINT UNSIGNED NULL,
            first_scan_started_at DATETIME NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_device_scan_state_user(user_id),
            CONSTRAINT fk_device_scan_state_device FOREIGN KEY(device_id) REFERENCES devices(id) ON DELETE CASCADE,
            CONSTRAINT fk_device_scan_state_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        
        $sql[] = "CREATE TABLE IF NOT EXISTS device_status (
            device_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            driver_status VARCHAR(30) NULL,
            driver_error TEXT NULL,
            total_onus INT NULL,
            is_present TINYINT(1) NOT NULL DEFAULT 1,
            seen_at DATETIME NULL,
            config_changed_at DATETIME NULL,
            cli_status VARCHAR(16) NOT NULL DEFAULT '',
            cli_seen_at DATETIME NULL,
            cli_fail_count INT NOT NULL DEFAULT 0,
            cli_down_logged TINYINT(1) NOT NULL DEFAULT 0,
            snmp_fail_count INT NOT NULL DEFAULT 0,
            snmp_down_logged TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_device_status_user_present(user_id,is_present),
            INDEX idx_device_status_user_seen(user_id,seen_at),
            CONSTRAINT fk_device_status_device FOREIGN KEY(device_id) REFERENCES devices(id) ON DELETE CASCADE,
            CONSTRAINT fk_device_status_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[] = "CREATE TABLE IF NOT EXISTS change_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            entity_type VARCHAR(20) NOT NULL,
            entity_key VARCHAR(300) NOT NULL,
            field_name VARCHAR(80) NOT NULL,
            old_value TEXT NULL,
            new_value TEXT NULL,
            message TEXT NOT NULL,
            event_source VARCHAR(30) NULL,
            event_olt_id BIGINT UNSIGNED NULL,
            event_onu_index VARCHAR(120) NULL,
            event_onu_name VARCHAR(255) NULL,
            event_onu_serial VARCHAR(255) NULL,
            event_onu_location VARCHAR(255) NULL,
            event_details_json LONGTEXT NULL,
            notification_ready_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
            notified_at DATETIME NULL,
            notification_error TEXT NULL,
            notification_attempts INT UNSIGNED NOT NULL DEFAULT 0,
            last_notification_attempt_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_events_user_created(user_id,created_at),
            INDEX idx_events_user_notify(user_id,notified_at),
            CONSTRAINT fk_events_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[] = "CREATE TABLE IF NOT EXISTS sync_entitas (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            entity_type VARCHAR(20) NOT NULL,
            entity_key VARCHAR(300) NOT NULL,
            device_id BIGINT UNSIGNED NULL,
            onu_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_monitor_entity(user_id,entity_type,entity_key),
            INDEX idx_monitor_entity_device(device_id),
            INDEX idx_monitor_entity_onu(onu_id),
            CONSTRAINT fk_monitor_entity_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_monitor_entity_device FOREIGN KEY(device_id) REFERENCES devices(id) ON DELETE SET NULL,
            CONSTRAINT fk_monitor_entity_onu FOREIGN KEY(onu_id) REFERENCES onus(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[] = "CREATE TABLE IF NOT EXISTS sync_state (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            monitor_entity_id BIGINT UNSIGNED NOT NULL,
            field_name VARCHAR(80) NOT NULL,
            confirmed_value VARCHAR(100) NOT NULL,
            candidate_value VARCHAR(100) NULL,
            candidate_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
            candidate_since DATETIME NULL,
            confirmed_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_monitor_field_state(monitor_entity_id,field_name),
            CONSTRAINT fk_sync_state_entity FOREIGN KEY(monitor_entity_id) REFERENCES sync_entitas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[] = "CREATE TABLE IF NOT EXISTS notification_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            gateway VARCHAR(20) NOT NULL,
            provider VARCHAR(32) NOT NULL DEFAULT '',
            message LONGTEXT NULL,
            device_name VARCHAR(500) NOT NULL DEFAULT '',
            category VARCHAR(80) NOT NULL DEFAULT '',
            status VARCHAR(16) NULL DEFAULT NULL,
            event_id BIGINT UNSIGNED NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 1,
            send_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notification_history_user_gateway_created(user_id,gateway,created_at),
            INDEX idx_notification_history_event(event_id),
            CONSTRAINT fk_notification_history_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[] = "CREATE TABLE IF NOT EXISTS sync_jobs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            available_at DATETIME NOT NULL,
            locked_at DATETIME NULL,
            worker_token VARCHAR(64) NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sync_jobs_user(user_id),
            INDEX idx_sync_jobs_claim(status,available_at,id),
            INDEX idx_sync_jobs_locked(status,locked_at),
            CONSTRAINT fk_sync_jobs_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[] = "CREATE TABLE IF NOT EXISTS app_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NULL,
            username VARCHAR(80) NULL,
            action_name VARCHAR(120) NOT NULL DEFAULT 'activity',
            level VARCHAR(20) NOT NULL DEFAULT 'info',
            message LONGTEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_app_logs_created(created_at),
            INDEX idx_app_logs_user_created(user_id,created_at),
            INDEX idx_app_logs_level_created(level,created_at),
            CONSTRAINT fk_app_logs_user_v2 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[] = "CREATE TABLE IF NOT EXISTS login_attempts (
            username_key VARCHAR(80) NOT NULL,
            ip_address VARCHAR(64) NOT NULL,
            failure_count INT UNSIGNED NOT NULL DEFAULT 0,
            window_started_at DATETIME NOT NULL,
            blocked_until DATETIME NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(username_key,ip_address),
            INDEX idx_login_attempts_updated(updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[] = 'CREATE TABLE IF NOT EXISTS graph_interface_samples (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            device_id bigint(20) unsigned NOT NULL,
            port_ref varchar(160) NOT NULL,
            rx_bps decimal(20,2) NOT NULL DEFAULT 0,
            tx_bps decimal(20,2) NOT NULL DEFAULT 0,
            sampled_at datetime NOT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            UNIQUE KEY uq_graph_interface_sample (device_id,port_ref,sampled_at),
            KEY idx_graph_interface_range (device_id,port_ref,sampled_at),
            CONSTRAINT fk_graph_interface_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE,
            CONSTRAINT fk_graph_interface_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )';

        $sql[] = 'CREATE TABLE IF NOT EXISTS device_traffic_interfaces (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            device_id bigint(20) unsigned NOT NULL,
            if_index int(10) unsigned NOT NULL DEFAULT 0,
            if_ref varchar(160) NOT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            UNIQUE KEY uq_device_traffic_interface (device_id,if_ref),
            KEY idx_device_traffic_interface_user (user_id),
            CONSTRAINT fk_device_traffic_interface_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE,
            CONSTRAINT fk_device_traffic_interface_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $sql[] = "CREATE TABLE IF NOT EXISTS runtime_state (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            subject VARCHAR(80) NOT NULL DEFAULT '',
            secret_blob TEXT NULL,
            node_ref VARCHAR(120) NOT NULL DEFAULT '',
            relay VARCHAR(500) NULL,
            state_blob LONGTEXT NULL,
            rev BIGINT UNSIGNED NOT NULL DEFAULT 0,
            valid_until DATETIME NULL,
            mode VARCHAR(40) NOT NULL DEFAULT 'pending',
            opened_at DATETIME NULL,
            touched_at DATETIME NULL,
            due_at DATETIME NULL,
            note VARCHAR(500) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[] = 'CREATE TABLE IF NOT EXISTS onu_service_configs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            olt_device_id bigint(20) unsigned NOT NULL,
            onu_index varchar(64) NOT NULL,
            service_name varchar(64) NOT NULL DEFAULT \'\',
            vlan int(11) DEFAULT NULL,
            tcont_index int(11) DEFAULT NULL,
            tcont_profile varchar(64) NOT NULL DEFAULT \'\',
            traffic_profile varchar(64) NOT NULL DEFAULT \'\',
            config_json longtext DEFAULT NULL,
            source varchar(16) NOT NULL DEFAULT \'fast\',
            synced_at datetime NOT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (id),
            UNIQUE KEY uq_onu_service_config (user_id,olt_device_id,onu_index),
            KEY idx_onu_service_device (olt_device_id),
            CONSTRAINT fk_onu_service_device FOREIGN KEY (olt_device_id) REFERENCES devices (id) ON DELETE CASCADE,
            CONSTRAINT fk_onu_service_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )';

        $sql[] = "CREATE TABLE IF NOT EXISTS pons (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            olt_device_id BIGINT UNSIGNED NOT NULL,
            card_id BIGINT UNSIGNED NULL,
            port_ref VARCHAR(120) NOT NULL DEFAULT '',
            pon_number INT UNSIGNED NOT NULL DEFAULT 0,
            pon_type VARCHAR(16) NULL,
            slot INT NULL,
            label VARCHAR(120) NOT NULL DEFAULT '',
            description VARCHAR(191) NOT NULL DEFAULT '',
            admin_state VARCHAR(16) NULL,
            link_status VARCHAR(16) NULL,
            flow_control VARCHAR(16) NULL,
            autoneg VARCHAR(16) NULL,
            speed VARCHAR(16) NULL,
            duplex VARCHAR(16) NULL,
            is_present TINYINT(1) NOT NULL DEFAULT 1,
            seen_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_pons_device_port(olt_device_id,port_ref),
            INDEX idx_pons_user_device(user_id,olt_device_id),
            INDEX idx_pons_card(card_id),
            CONSTRAINT fk_pons_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_pons_device FOREIGN KEY(olt_device_id) REFERENCES devices(id) ON DELETE CASCADE,
            CONSTRAINT fk_pons_card FOREIGN KEY(card_id) REFERENCES olt_cards(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[] = "CREATE TABLE IF NOT EXISTS pon_modules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            pon_id BIGINT UNSIGNED NOT NULL,
            source VARCHAR(30) NOT NULL DEFAULT 'snmp',
            sampled_at DATETIME NOT NULL,
            tx_power DECIMAL(6,3) NULL,
            temperature DECIMAL(6,2) NULL,
            voltage DECIMAL(6,3) NULL,
            current DECIMAL(7,2) NULL,
            module_type VARCHAR(40) NULL,
            module_json JSON NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_pon_modules_pon(pon_id),
            INDEX idx_pon_modules_pon_time(pon_id,sampled_at),
            CONSTRAINT fk_pon_modules_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_pon_modules_pon FOREIGN KEY(pon_id) REFERENCES pons(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[] = "CREATE TABLE IF NOT EXISTS uplink_port (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            olt_device_id BIGINT UNSIGNED NOT NULL,
            port_ref VARCHAR(120) NOT NULL DEFAULT '',
            if_index INT UNSIGNED NULL,
            port_type VARCHAR(16) NULL,
            slot INT NULL,
            port_number INT NULL,
            label VARCHAR(120) NOT NULL DEFAULT '',
            admin_state VARCHAR(16) NULL,
            link_status VARCHAR(16) NULL,
            speed_mbps INT NULL,
            description VARCHAR(191) NULL,
            duplex VARCHAR(16) NULL,
            negotiation VARCHAR(16) NULL,
            flow_control VARCHAR(16) NULL,
            connector VARCHAR(16) NULL,
            vlan_mode VARCHAR(16) NULL,
            pvid INT NULL,
            tagged_vlan VARCHAR(191) NULL,
            untagged_vlan VARCHAR(191) NULL,
            is_present TINYINT(1) NOT NULL DEFAULT 1,
            seen_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_uplink_device_port(olt_device_id,port_ref),
            INDEX idx_uplink_user_device(user_id,olt_device_id),
            CONSTRAINT fk_uplink_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_uplink_device FOREIGN KEY(olt_device_id) REFERENCES devices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[] = "CREATE TABLE IF NOT EXISTS olt_profiles (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            olt_device_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(24) NOT NULL,
            name VARCHAR(128) NOT NULL,
            data_json LONGTEXT NULL,
            source VARCHAR(30) NOT NULL DEFAULT 'cli',
            seen_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_olt_profiles_entry(olt_device_id,kind,name),
            INDEX idx_olt_profiles_user_device(user_id,olt_device_id),
            CONSTRAINT fk_olt_profiles_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_olt_profiles_device FOREIGN KEY(olt_device_id) REFERENCES devices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[] = "CREATE TABLE IF NOT EXISTS olt_vlans (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            olt_device_id BIGINT UNSIGNED NOT NULL,
            vlan_number SMALLINT UNSIGNED NOT NULL,
            name VARCHAR(32) NOT NULL DEFAULT '',
            related_ports LONGTEXT NULL,
            UNIQUE KEY uq_olt_vlans_device_number(olt_device_id,vlan_number),
            CONSTRAINT fk_olt_vlans_device FOREIGN KEY(olt_device_id) REFERENCES devices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[] = "CREATE TABLE IF NOT EXISTS olt_onu_types (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            olt_device_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(64) NOT NULL,
            speed VARCHAR(64) NOT NULL DEFAULT '',
            pon_type VARCHAR(8) NOT NULL DEFAULT '',
            description VARCHAR(255) NOT NULL DEFAULT '',
            fields_json LONGTEXT NULL,
            UNIQUE KEY uq_olt_onu_types_device_name(olt_device_id,name),
            INDEX idx_olt_onu_types_device_pon(olt_device_id,pon_type),
            CONSTRAINT fk_olt_onu_types_device FOREIGN KEY(olt_device_id) REFERENCES devices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        foreach ($sql as $statement) $this->pdo->exec($statement);
    }
}
