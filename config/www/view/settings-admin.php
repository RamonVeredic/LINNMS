<div class="settings-page" data-profile-page>
    <?php require project_path('pages', 'partials', 'settings-profile.php'); ?>
    <main class="panel settings-layout" id="sync" aria-label="Pengaturan aplikasi">
        <header><h2>Konfigurasi</h2></header>
            <?php
            $discoveryIntervalMinutes = (int)($syncTimingSettings['discovery_interval_minutes'] ?? LinnmsRepository::DEFAULT_SYNC_DISCOVERY_INTERVAL_MINUTES);
            $discoveryIntervalHours = max(1, (int)ceil($discoveryIntervalMinutes / 60));
            $middleIntervalMinutes = (int)($syncTimingSettings['middle_interval_minutes'] ?? LinnmsRepository::DEFAULT_SYNC_MIDDLE_INTERVAL_MINUTES);
            $middleIntervalHours = max(1, (int)ceil($middleIntervalMinutes / 60));
            $timingFields = [
                ['key' => 'sync_fast_interval_minutes', 'id' => 'settingFastInterval', 'label' => 'Interval Fast Sync', 'value' => (int)($syncTimingSettings['fast_interval_minutes'] ?? LinnmsRepository::DEFAULT_SYNC_FAST_INTERVAL_MINUTES), 'min' => LinnmsRepository::MIN_SYNC_FAST_INTERVAL_MINUTES, 'max' => LinnmsRepository::MAX_SYNC_FAST_INTERVAL_MINUTES, 'step' => 1, 'unit' => 'menit', 'help' => 'Jeda antar sinkronisasi cepat untuk memperbarui status perangkat dan ONU'],
                ['key' => 'olt_graph_interval_minutes', 'id' => 'settingOltGraphInterval', 'label' => 'Interval Sampel Trafik OLT', 'value' => $oltGraphIntervalMinutes, 'min' => LinnmsRepository::MIN_OLT_GRAPH_INTERVAL_MINUTES, 'max' => LinnmsRepository::MAX_OLT_GRAPH_INTERVAL_MINUTES, 'step' => 1, 'unit' => 'menit', 'help' => 'Jeda pengambilan data trafik OLT untuk grafik'],
                ['key' => 'sync_discovery_interval_minutes', 'id' => 'settingDiscoveryInterval', 'label' => 'Interval Low Sync', 'value' => $discoveryIntervalHours, 'min' => 1, 'max' => intdiv(LinnmsRepository::MAX_SYNC_DISCOVERY_INTERVAL_MINUTES, 60), 'step' => 1, 'unit' => 'jam', 'help' => 'Jeda penemuan ulang perangkat dan ONU melalui sinkronisasi menyeluruh'],
                ['key' => 'sync_middle_interval_minutes', 'id' => 'settingMiddleInterval', 'label' => 'Interval Middle Sync', 'value' => $middleIntervalHours, 'min' => 1, 'max' => intdiv(LinnmsRepository::MAX_SYNC_MIDDLE_INTERVAL_MINUTES, 60), 'step' => 1, 'unit' => 'jam', 'help' => 'Jeda sinkronisasi tingkat menengah untuk memperbarui data yang tidak diambil Fast Sync'],
                ['key' => 'sync_fast_deadline_seconds', 'id' => 'settingFastDeadline', 'label' => 'Durasi Fast Sync', 'value' => (int)($syncTimingSettings['fast_deadline_seconds'] ?? LinnmsRepository::DEFAULT_SYNC_FAST_DEADLINE_SECONDS), 'min' => LinnmsRepository::MIN_SYNC_DEADLINE_SECONDS, 'max' => LinnmsRepository::MAX_SYNC_DEADLINE_SECONDS, 'step' => 1, 'unit' => 'detik', 'help' => 'Batas waktu pengerjaan satu Fast Sync sebelum dianggap melewati tenggat'],
                ['key' => 'sync_discovery_deadline_seconds', 'id' => 'settingDiscoveryDeadline', 'label' => 'Batas Durasi Low Sync', 'value' => (int)($syncTimingSettings['discovery_deadline_seconds'] ?? LinnmsRepository::DEFAULT_SYNC_DISCOVERY_DEADLINE_SECONDS), 'min' => LinnmsRepository::MIN_SYNC_DEADLINE_SECONDS, 'max' => LinnmsRepository::MAX_SYNC_DEADLINE_SECONDS, 'step' => 1, 'unit' => 'detik', 'help' => 'Batas waktu pengerjaan satu Low Sync sebelum dianggap melewati tenggat'],
                ['key' => 'sync_middle_deadline_seconds', 'id' => 'settingMiddleDeadline', 'label' => 'Batas Durasi Middle Sync', 'value' => (int)($syncTimingSettings['middle_deadline_seconds'] ?? LinnmsRepository::DEFAULT_SYNC_MIDDLE_DEADLINE_SECONDS), 'min' => LinnmsRepository::MIN_SYNC_DEADLINE_SECONDS, 'max' => LinnmsRepository::MAX_SYNC_DEADLINE_SECONDS, 'step' => 1, 'unit' => 'detik', 'help' => 'Batas waktu pengerjaan satu Middle Sync sebelum dianggap melewati tenggat'],
                ['key' => 'snmp_request_timeout_ms', 'id' => 'settingSnmpTimeout', 'label' => 'Batas Waktu SNMP', 'value' => (int)($syncTimingSettings['snmp_request_timeout_ms'] ?? LinnmsRepository::DEFAULT_SNMP_REQUEST_TIMEOUT_MS), 'min' => 0, 'max' => LinnmsRepository::MAX_SNMP_REQUEST_TIMEOUT_MS, 'step' => 100, 'unit' => 'ms', 'help' => 'Lama maksimum menunggu jawaban untuk setiap permintaan SNMP'],
                ['key' => 'onu_missing_scan_limit', 'id' => 'settingOnuMissing', 'label' => 'Batas Scan Onu', 'value' => (int)$onuMissingScanLimit, 'min' => LinnmsRepository::MIN_ONU_MISSING_SCAN_LIMIT, 'max' => LinnmsRepository::MAX_ONU_MISSING_SCAN_LIMIT, 'step' => 1, 'unit' => 'kali', 'help' => 'Jumlah scan berturut-turut tanpa menemukan ONU sebelum ONU dianggap hilang'],
                ['key' => 'reader_count', 'id' => 'settingReaderCount', 'label' => 'Jumlah Reader', 'value' => $readerCount, 'min' => 1, 'max' => LinnmsRepository::MAX_READER_COUNT, 'step' => 1, 'unit' => 'proses', 'help' => 'Jumlah proses yang membaca perangkat dan mengumpulkan hasil sinkronisasi secara paralel'],
                ['key' => 'fast_sync_onu_batch_size', 'id' => 'settingFastOnuBatchSize', 'label' => 'ONU per Batch Fast Sync', 'value' => $fastSyncOnuBatchSize, 'min' => LinnmsRepository::MIN_FAST_SYNC_ONU_BATCH_SIZE, 'max' => LinnmsRepository::MAX_FAST_SYNC_ONU_BATCH_SIZE, 'step' => 1, 'unit' => 'ONU', 'help' => 'Jumlah maksimum ONU yang dibaca sebelum hasil dikirim ke writer dan reader memberi giliran ke user lain'],
                ['key' => 'writer_count', 'id' => 'settingWriterCount', 'label' => 'Jumlah Writer', 'value' => $writerCount, 'min' => 1, 'max' => LinnmsRepository::MAX_WRITER_COUNT, 'step' => 1, 'unit' => 'proses', 'help' => 'Jumlah proses yang menyimpan hasil sinkronisasi ke database secara paralel'],
            ];
            ?>

        <div class="settings-columns" data-settings-columns>
            <div class="settings-column" data-settings-column>
                <section>
                    <span class="settings-anchor-alias" id="cache" aria-hidden="true"></span>
                    <span class="settings-anchor-alias" id="onu-missing" aria-hidden="true"></span>
                    <header class="tittle-subtitle"><h3>Sinkronisasi</h3></header>
                    <div class="settings-body">
                        <ul class="settings-list">
                        <?php foreach ($timingFields as $field): ?>
                            <li>
                            <form method="post" class="settings-row" data-setting-edit-form>
                                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action" value="save_numeric_setting">
                                <input type="hidden" name="setting_key" value="<?= h($field['key']) ?>">
                                <div class="label">
                                    <div class="label-title">
                                        <label for="<?= h($field['id']) ?>"><?= h($field['label']) ?></label>
                                        <button class="icon-button settings-help-button" type="button" data-setting-help="<?= h($field['help']) ?>" aria-label="Bantuan <?= h($field['label']) ?>" aria-controls="settingsHelpTooltip" aria-expanded="false"><i class="bi bi-question-circle" aria-hidden="true"></i></button>
                                        <button class="icon-button" type="button" data-setting-edit data-target="<?= h($field['id']) ?>" aria-controls="<?= h($field['id']) ?>" aria-pressed="false" data-setting-label="<?= h($field['label']) ?>" aria-label="Edit <?= h($field['label']) ?>" title="Edit <?= h($field['label']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                        <button class="icon-button" type="button" data-setting-cancel data-target="<?= h($field['id']) ?>" hidden aria-label="Batal mengedit <?= h($field['label']) ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                    </div>
                                </div>
                                <div class="control">
                                    <span class="settings-number-shell">
                                        <input id="<?= h($field['id']) ?>" type="number" name="setting_value" min="<?= (int)$field['min'] ?>" max="<?= (int)$field['max'] ?>" step="<?= (int)$field['step'] ?>" required value="<?= (int)$field['value'] ?>" inputmode="numeric" disabled data-setting-edit-control>
                                        <span><?= h($field['unit']) ?></span>
                                    </span>
                                </div>
                            </form>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    </div>
                </section>

                <section>
                    <header class="tittle-subtitle"><h3>Notifikasi</h3></header>
                    <div class="settings-body">
                        <ul class="settings-list">
                            <?php foreach ([
                                ['label' => 'Telegram', 'allowed' => $telegramFeatureAllowed],
                                ['label' => 'WhatsApp', 'allowed' => $whatsappFeatureAllowed],
                            ] as $channel): ?>
                                <li>
                                    <div class="settings-row<?= !$channel['allowed'] ? ' is-access-locked' : '' ?>"><span class="label"><?= h($channel['label']) ?></span><span class="control"><input type="checkbox" role="switch" <?= $channel['allowed'] ? 'checked' : '' ?> disabled aria-label="<?= h($channel['label'] . ($channel['allowed'] ? ' aktif' : ' tidak diizinkan')) ?>"><?php if ($channel['allowed']): ?><span class="toggle-switch" aria-hidden="true"></span><?php else: ?><span class="toggle-disabled-lock" aria-hidden="true"><i class="bi bi-lock-fill"></i></span><?php endif; ?></span></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </section>
            </div>
            <div class="settings-column" data-settings-column>
                <section>
                    <header class="tittle-subtitle"><h3>Device</h3></header>
                    <form method="post" data-driver-support-form>
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_device_driver_support">
                        <div class="settings-body">
                            <ul class="settings-list">
                                <?php foreach (['olt', 'router', 'switch'] as $driverGroup): ?>
                                    <?php foreach ($deviceDriverCatalog as $driverId => $definition): ?>
                                        <?php if ((string)($definition['group'] ?? '') !== $driverGroup || (string)$driverId === 'external_olt_api') continue; ?>
                                        <?php $driverAccessLocked = !device_access_allows((string)$driverId);
                                        $driverEnabled = !$driverAccessLocked && ($driverGroup === 'olt' ? olt_driver_support_enabled($oltDriverSupportFlags, (string)$driverId) : snmp_driver_support_enabled($snmpDriverSupportFlags, (string)$driverId)); ?>
                                        <li>
                                            <label class="settings-row<?= $driverAccessLocked ? ' is-access-locked' : '' ?>" <?= $driverAccessLocked ? 'title="' . h(device_access_lock_message()) . '"' : '' ?>>
                                                <span class="label"><?= h((string)($definition['label'] ?? $driverId)) ?></span>
                                                <span class="control"><input type="checkbox" role="switch" name="driver_support[<?= h((string)$driverId) ?>]" value="1" <?= $driverEnabled ? 'checked' : '' ?> <?= $driverAccessLocked ? 'disabled' : '' ?>><?php if ($driverAccessLocked): ?><span class="toggle-disabled-lock" title="<?= h(device_access_lock_message()) ?>" aria-hidden="true"><i class="bi bi-lock-fill"></i></span><?php else: ?><span class="toggle-switch" aria-hidden="true"></span><?php endif; ?></span>
                                            </label>
                                        </li>
                                    <?php endforeach; ?>
                                    <?php if ($driverGroup === 'olt'): ?>
                                        <?php foreach ($apiOltTypeOptions as $oltType => $oltTypeLabel): ?>
                                            <?php $oltTypeLocked = !device_access_allows('api_olt_type:' . (string)$oltType); ?>
                                            <li>
                                                <label class="settings-row<?= $oltTypeLocked ? ' is-access-locked' : '' ?>" <?= $oltTypeLocked ? 'title="' . h(device_access_lock_message()) . '"' : '' ?>><span class="label"><?= h((string)$oltTypeLabel) ?></span><span class="control"><input type="checkbox" role="switch" name="api_olt_type_support[<?= h((string)$oltType) ?>]" value="1" <?= !$oltTypeLocked && api_olt_type_support_enabled($apiOltTypeSupportFlags, (string)$oltType) ? 'checked' : '' ?> <?= $oltTypeLocked ? 'disabled' : '' ?>><?php if ($oltTypeLocked): ?><span class="toggle-disabled-lock" title="<?= h(device_access_lock_message()) ?>" aria-hidden="true"><i class="bi bi-lock-fill"></i></span><?php else: ?><span class="toggle-switch" aria-hidden="true"></span><?php endif; ?></span></label>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </form>
                </section>
                <section id="user-limits">
                    <header class="tittle-subtitle"><h3>Users Device Limit</h3></header>
                    <div class="settings-body">
                        <?php if ($managedDeviceUsers === []): ?>
                            <p class="empty">Pengguna belum tersedia</p>
                        <?php else: ?>
                            <ul class="settings-list">
                                <?php foreach ($managedDeviceUsers as $managedUser): ?>
                                    <?php $managedUserId = (int)$managedUser['id']; ?>
                                    <li>
                                        <div class="settings-row">
                                            <span class="label"><?= h((string)$managedUser['username']) ?><small>OLT <?= (int)$managedUser['olt_limit'] ?> · Router <?= (int)$managedUser['mikrotik_limit'] ?> · Switch <?= (int)$managedUser['switch_limit'] ?></small></span>
                                            <span class="control"><button class="button secondary" type="button" data-settings-modal-open="userLimitEditPanel" data-limit-edit-open data-limit-user-id="<?= $managedUserId ?>" data-limit-name="<?= h((string)$managedUser['username']) ?>" data-limit-olt="<?= (int)$managedUser['olt_limit'] ?>" data-limit-mikrotik="<?= (int)$managedUser['mikrotik_limit'] ?>" data-limit-switch="<?= (int)$managedUser['switch_limit'] ?>" aria-expanded="false" aria-controls="userLimitEditPanel" aria-haspopup="dialog"><i class="bi bi-pencil-square" aria-hidden="true"></i> Edit</button></span>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>
    </main>
    <?php if ($managedDeviceUsers !== []): ?>
        <div class="modal settings-edit-modal" id="userLimitEditPanel" data-settings-modal hidden>
            <div class="modal-card settings-edit-dialog" role="dialog" aria-modal="true" aria-labelledby="userLimitEditTitle" tabindex="-1">
                <div class="panel-heading tittle-subtitle settings-edit-heading">
                    <h2 id="userLimitEditTitle" data-limit-edit-title>Edit Limit Perangkat</h2>
                    <button class="button secondary" type="button" data-settings-modal-close aria-label="Tutup edit limit"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <form method="post" class="stack-form">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_user_device_limits">
                    <input type="hidden" name="user_id" value="">
                    <div class="form-grid">
                        <?php foreach ([
                            ['key' => 'olt_limit', 'label' => 'OLT', 'limit' => (int)$oltQuota['limit']],
                            ['key' => 'mikrotik_limit', 'label' => 'MikroTik', 'limit' => (int)$mikrotikQuota['limit']],
                            ['key' => 'switch_limit', 'label' => 'Switch', 'limit' => (int)$switchQuota['limit']],
                        ] as $field): ?>
                            <?php $inputId = 'userLimit_' . $field['key']; ?>
                            <div class="field">
                                <label for="<?= h($inputId) ?>"><?= h($field['label']) ?><?= $field['limit'] > 0 ? ' (maks. ' . $field['limit'] . ')' : ' (0 = tanpa batas)' ?></label>
                                <input id="<?= h($inputId) ?>" type="number" name="<?= h($field['key']) ?>" min="<?= $field['limit'] > 0 ? 1 : 0 ?>" <?= $field['limit'] > 0 ? 'max="' . $field['limit'] . '"' : '' ?> step="1" required inputmode="numeric">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-actions"><button class="button primary" type="submit"><i class="bi bi-floppy" aria-hidden="true"></i> Simpan</button></div>
                </form>
            </div>
        </div>
    <?php endif; ?>
    <?php require project_path('pages', 'partials', 'settings-service-contact.php'); ?>
</div>
