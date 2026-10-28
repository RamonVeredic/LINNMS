<?php
$pageVariables = get_defined_vars();
$settings = is_array($pageVariables['settings'] ?? null) ? $pageVariables['settings'] : [];
$onuProblemSettings = is_array($pageVariables['onuProblemSettings'] ?? null) ? $pageVariables['onuProblemSettings'] : [];
$telegramServiceSettings = is_array($pageVariables['telegramServiceSettings'] ?? null) ? $pageVariables['telegramServiceSettings'] : [];
$whatsappServiceSettings = is_array($pageVariables['whatsappServiceSettings'] ?? null) ? $pageVariables['whatsappServiceSettings'] : [];
$notifTelegramEnabled = (int)($settings['telegram_enabled'] ?? 0) === 1;
$notifTelegramAccessAllowed = !empty($telegramFeatureAllowed);
$notifTelegramAvailable = $notifTelegramAccessAllowed && (int)($telegramServiceSettings['enabled'] ?? 0) === 1;
$whatsappUserSettings = is_array($pageVariables['whatsappUserSettings'] ?? null)
    ? $pageVariables['whatsappUserSettings']
    : [];
unset($pageVariables);
$notifWhatsAppEnabled = (int)($whatsappUserSettings['enabled'] ?? 0) === 1;
$notifWhatsAppAccessAllowed = !empty($whatsappFeatureAllowed);
$notifWhatsAppAvailable = $notifWhatsAppAccessAllowed && (int)($whatsappServiceSettings['enabled'] ?? 0) === 1;
$notifTelegramAccessMessage = !$notifTelegramAccessAllowed ? 'Tidak diizinkan.' : (!$notifTelegramAvailable ? 'Belum tersedia.' : '');
$notifWhatsAppAccessMessage = !$notifWhatsAppAccessAllowed ? 'Tidak diizinkan.' : (!$notifWhatsAppAvailable ? 'Belum tersedia.' : '');
?>
<main id="notification-settings" class="panel settings-layout">
    <header>
        <h2>Notifikasi</h2>
        <p>Atur saluran, batas deteksi, perangkat, dan jenis pesan yang dikirim.</p>
    </header>

    <div class="settings-columns" data-settings-columns>
        <div class="settings-column" data-settings-column>
            <section>
                <header class="tittle-subtitle"><h3>Saluran Pengiriman</h3></header>
                <div class="settings-body">
                    <ul class="settings-list">
                    <li>
                    <form id="telegramMasterSwitchForm" method="post" data-instant-toggle>
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="telegram_toggle_notifications">
                        <input type="hidden" name="telegram_enabled" value="0">
                        <label class="settings-row channel-master-switch<?= !$notifTelegramAccessAllowed ? ' is-access-locked' : '' ?>"<?= !$notifTelegramAccessAllowed ? ' aria-disabled="true"' : '' ?>>
                            <span class="label"><span>Telegram</span><?php if ($notifTelegramAccessMessage !== ''): ?><small><?= h($notifTelegramAccessMessage) ?></small><?php endif; ?></span>
                            <span class="control"><input type="checkbox" role="switch" name="telegram_enabled" value="1" <?= $notifTelegramEnabled ? 'checked' : '' ?> <?= !$notifTelegramAvailable ? 'disabled' : '' ?> data-instant-toggle-input aria-label="Aktifkan pengiriman notifikasi Telegram"><i class="toggle-switch" aria-hidden="true"></i></span>
                        </label>
                        <noscript><?php if ($notifTelegramAvailable): ?><button class="button primary" type="submit">Simpan</button><?php elseif ($notifTelegramAccessMessage !== ''): ?><small><?= h($notifTelegramAccessMessage) ?></small><?php endif; ?></noscript>
                    </form>
                    </li>
                    <li>
                    <form id="whatsappToggleForm" method="post" data-instant-toggle>
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="whatsapp_toggle_notifications">
                        <input type="hidden" name="whatsapp_enabled" value="0">
                        <label class="settings-row channel-master-switch<?= !$notifWhatsAppAccessAllowed ? ' is-access-locked' : '' ?>"<?= !$notifWhatsAppAccessAllowed ? ' aria-disabled="true"' : '' ?>>
                            <span class="label"><span>WhatsApp</span><?php if ($notifWhatsAppAccessMessage !== ''): ?><small><?= h($notifWhatsAppAccessMessage) ?></small><?php endif; ?></span>
                            <span class="control"><input type="checkbox" role="switch" name="whatsapp_enabled" value="1" <?= $notifWhatsAppEnabled ? 'checked' : '' ?> <?= !$notifWhatsAppAvailable ? 'disabled' : '' ?> data-instant-toggle-input aria-label="Aktifkan pengiriman notifikasi WhatsApp"><i class="toggle-switch" aria-hidden="true"></i></span>
                        </label>
                        <noscript><?php if ($notifWhatsAppAvailable): ?><button class="button primary" type="submit">Simpan</button><?php elseif ($notifWhatsAppAccessMessage !== ''): ?><small><?= h($notifWhatsAppAccessMessage) ?></small><?php endif; ?></noscript>
                    </form>
                    </li>
                    </ul>
                </div>
            </section>
            <?php
            $notificationThresholds = [
                ['key' => 'problem_threshold', 'id' => 'notificationProblemThreshold', 'label' => 'Batas RX Bermasalah', 'value' => LinnmsRepository::clampOnuRxProblemLimit($onuProblemSettings['rx_power_threshold_dbm'] ?? null), 'min' => LinnmsRepository::ONU_RX_PROBLEM_MIN_DBM, 'max' => LinnmsRepository::ONU_RX_PROBLEM_MAX_DBM, 'unit' => 'dBm'],
                ['key' => 'signal_threshold_dbm', 'id' => 'notificationSignalThreshold', 'label' => 'Perubahan RX Power', 'value' => LinnmsRepository::clampOnuRxDetection($settings['signal_threshold_dbm'] ?? null), 'min' => LinnmsRepository::ONU_RX_DETECTION_MIN_DBM, 'max' => LinnmsRepository::ONU_RX_DETECTION_MAX_DBM, 'unit' => 'dBm'],
            ];
            ?>
            <section>
                <header class="tittle-subtitle"><h3>Batas Deteksi</h3></header>
                <div class="settings-body">
                    <ul class="settings-list">
                    <?php foreach ($notificationThresholds as $threshold): ?>
                        <li>
                        <form method="post" class="settings-row" data-notification-edit-form>
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="save_notification_setting">
                            <input type="hidden" name="setting_key" value="<?= h($threshold['key']) ?>">
                            <div class="label">
                                <span class="label-title">
                                    <label for="<?= h($threshold['id']) ?>"><?= h($threshold['label']) ?></label>
                                    <button class="icon-button" type="button" data-notification-edit data-target="<?= h($threshold['id']) ?>" aria-controls="<?= h($threshold['id']) ?>" aria-pressed="false" data-notification-label="<?= h($threshold['label']) ?>" aria-label="Edit <?= h($threshold['label']) ?>" title="Edit <?= h($threshold['label']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                    <button class="icon-button" type="button" data-notification-cancel data-target="<?= h($threshold['id']) ?>" hidden aria-label="Batal mengedit <?= h($threshold['label']) ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                </span>
                            </div>
                            <div class="control">
                                <span class="settings-number-shell">
                                    <input id="<?= h($threshold['id']) ?>" type="number" name="setting_value" step="0.1" min="<?= h(number_format((float)$threshold['min'], 1, '.', '')) ?>" max="<?= h(number_format((float)$threshold['max'], 1, '.', '')) ?>" required value="<?= h(number_format((float)$threshold['value'], 2, '.', '')) ?>" inputmode="decimal" disabled data-notification-edit-control>
                                    <span><?= h($threshold['unit']) ?></span>
                                </span>
                            </div>
                        </form>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                    <p class="settings-hint mb-5"><i class="bi bi-info-circle" aria-hidden="true"></i><span>Contoh perubahan 1.0 dB: dari -23.0 ke -24.0 belum memicu notifikasi; nilai -24.1 sudah memicu.</span></p>
                </div>
            </section>

        </div>
        <div class="settings-column" data-settings-column>
            <?php
            $notificationSelectedMap = $notificationSelectedIds === null ? null : array_fill_keys($notificationSelectedIds, true);
            ?>
            <section id="notification-devices">
                <header class="tittle-subtitle"><h3>Device</h3></header>
                <div class="settings-body">
                    <?php if ($notificationDevices === []): ?>
                        <p class="empty">Perangkaat belum tersedia</p>
                    <?php else: ?>
                        <ul class="settings-list">
                            <?php foreach ($notificationDevices as $device): ?>
                                <?php $deviceId = (int)$device['id']; ?>
                                <li>
                                    <form method="post" data-notification-device-form>
                                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="save_notification_devices">
                                        <label class="settings-row">
                                            <span class="label"><span><?= h(trim((string)($device['name'] ?? '')) ?: ('Perangkat #' . $deviceId)) ?></span></span>
                                            <span class="control"><input type="checkbox" role="switch" name="notification_device_ids[]" value="<?= $deviceId ?>" data-notification-device-checkbox <?= $notificationSelectedMap === null || isset($notificationSelectedMap[$deviceId]) ? 'checked' : '' ?>><i class="toggle-switch" aria-hidden="true"></i></span>
                                        </label>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>

            <section>
                <header class="tittle-subtitle"><h3>Jenis Notifikasi</h3></header>
                <?php
                $notificationToggles = [
                    ['key' => 'notify_onu_signal', 'label' => 'RX Power', 'enabled' => (int)($settings['notify_onu_signal'] ?? 1) === 1],
                    ['key' => 'notify_onu_state', 'label' => 'Status ONU', 'enabled' => (int)($settings['notify_onu_state'] ?? 1) === 1],
                    ['key' => 'notify_device_status', 'label' => 'Device', 'enabled' => (int)($settings['notify_device_status'] ?? 1) === 1],
                    ['key' => 'notify_interface_changes', 'label' => 'Interface', 'enabled' => (int)($settings['notify_interface_changes'] ?? $settings['notify_device_status'] ?? 1) === 1],
                    ['key' => 'notify_pppoe_status', 'label' => 'PPPoE', 'enabled' => (int)($settings['notify_pppoe_status'] ?? $settings['notify_interface_changes'] ?? $settings['notify_device_status'] ?? 1) === 1],
                    ['key' => 'notify_onu_problem', 'label' => 'Bermasalah', 'enabled' => (int)($settings['notify_onu_problem'] ?? 1) === 1],
                    ['key' => 'notify_onu_details', 'label' => 'Aktivitas ONU', 'enabled' => (int)($settings['notify_onu_details'] ?? 1) === 1],
                ];
                ?>
                <div class="settings-body">
                    <ul class="settings-list">
                    <?php foreach ($notificationToggles as $toggle): ?>
                        <li>
                        <form method="post" data-instant-toggle>
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="save_notification_setting">
                            <input type="hidden" name="setting_key" value="<?= h($toggle['key']) ?>">
                            <input type="hidden" name="setting_value" value="0">
                            <label class="settings-row"><span class="label"><span><?= h($toggle['label']) ?></span></span><span class="control"><input type="checkbox" role="switch" name="setting_value" value="1" <?= $toggle['enabled'] ? 'checked' : '' ?> data-instant-toggle-input><i class="toggle-switch" aria-hidden="true"></i></span></label>
                        </form>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        </div>
    </div>
</main>
