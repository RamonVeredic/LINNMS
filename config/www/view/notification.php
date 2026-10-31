<main id="notification-settings" class="panel settings-layout">
    <header>
        <h2>Notifikasi</h2>
    </header>

    <div class="settings-columns" data-settings-columns>
        <div class="settings-column" data-settings-column>
            <section>
                <header class="tittle-subtitle"><h3>Service</h3></header>
                <div class="settings-body">
                    <ul class="settings-list">
                    <li>
                    <form id="telegramMasterSwitchForm" method="post" data-instant-toggle>
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="telegram_toggle_notifications">
                        <input type="hidden" name="telegram_enabled" value="0">
                        <div class="settings-row channel-master-switch<?= !$notificationView['telegram_allowed'] ? ' is-access-locked' : '' ?>"<?= !$notificationView['telegram_allowed'] ? ' aria-disabled="true"' : '' ?>>
                            <span class="label"><span>Telegram</span><?php if ($notificationView['telegram_message'] !== ''): ?><small><?= h($notificationView['telegram_message']) ?></small><?php endif; ?></span>
                            <label class="control"><input type="checkbox" role="switch" name="telegram_enabled" value="1" <?= $notificationView['telegram_enabled'] ? 'checked' : '' ?> <?= !$notificationView['telegram_available'] ? 'disabled' : '' ?> data-instant-toggle-input aria-label="Aktifkan pengiriman notifikasi Telegram"><i class="toggle-switch" aria-hidden="true"></i></label>
                        </div>
                        <noscript><?php if ($notificationView['telegram_available']): ?><button class="button primary" type="submit">Simpan</button><?php elseif ($notificationView['telegram_message'] !== ''): ?><small><?= h($notificationView['telegram_message']) ?></small><?php endif; ?></noscript>
                    </form>
                    </li>
                    <li>
                    <form id="whatsappToggleForm" method="post" data-instant-toggle>
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="whatsapp_toggle_notifications">
                        <input type="hidden" name="whatsapp_enabled" value="0">
                        <div class="settings-row channel-master-switch<?= !$notificationView['whatsapp_allowed'] ? ' is-access-locked' : '' ?>"<?= !$notificationView['whatsapp_allowed'] ? ' aria-disabled="true"' : '' ?>>
                            <span class="label"><span>WhatsApp</span><?php if ($notificationView['whatsapp_message'] !== ''): ?><small><?= h($notificationView['whatsapp_message']) ?></small><?php endif; ?></span>
                            <label class="control"><input type="checkbox" role="switch" name="whatsapp_enabled" value="1" <?= $notificationView['whatsapp_enabled'] ? 'checked' : '' ?> <?= !$notificationView['whatsapp_available'] ? 'disabled' : '' ?> data-instant-toggle-input aria-label="Aktifkan pengiriman notifikasi WhatsApp"><i class="toggle-switch" aria-hidden="true"></i></label>
                        </div>
                        <noscript><?php if ($notificationView['whatsapp_available']): ?><button class="button primary" type="submit">Simpan</button><?php elseif ($notificationView['whatsapp_message'] !== ''): ?><small><?= h($notificationView['whatsapp_message']) ?></small><?php endif; ?></noscript>
                    </form>
                    </li>
                    </ul>
                </div>
            </section>
            <section>
                <header class="tittle-subtitle"><h3>Batas Deteksi</h3></header>
                <div class="settings-body">
                    <ul class="settings-list">
                    <?php foreach ($notificationView['thresholds'] as $threshold): ?>
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
            <section id="notification-devices">
                <header class="tittle-subtitle"><h3>Device</h3></header>
                <div class="settings-body">
                    <?php if ($notificationDevices === []): ?>
                        <div class="empty empty-state"><i class="bi bi-inbox" aria-hidden="true"></i><span>Belum ada perangkat</span></div>
                    <?php else: ?>
                        <ul class="settings-list">
                            <?php foreach ($notificationDevices as $device): ?>
                                <?php $deviceId = (int)$device['id']; ?>
                                <li>
                                    <form method="post" data-notification-device-form>
                                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="save_notification_devices">
                                        <div class="settings-row">
                                            <span class="label"><span><?= h(trim((string)($device['name'] ?? '')) ?: ('Perangkat #' . $deviceId)) ?></span></span>
                                            <label class="control"><input type="checkbox" role="switch" name="notification_device_ids[]" value="<?= $deviceId ?>" data-notification-device-checkbox aria-label="Notifikasi <?= h(trim((string)($device['name'] ?? '')) ?: ('Perangkat #' . $deviceId)) ?>" <?= $notificationView['selected_map'] === null || isset($notificationView['selected_map'][$deviceId]) ? 'checked' : '' ?>><i class="toggle-switch" aria-hidden="true"></i></label>
                                        </div>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>

            <section>
                <header class="tittle-subtitle"><h3>Jenis Notifikasi</h3></header>
                <div class="settings-body">
                    <ul class="settings-list">
                    <?php foreach ($notificationView['toggles'] as $toggle): ?>
                        <li>
                        <form method="post" data-instant-toggle>
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="save_notification_setting">
                            <input type="hidden" name="setting_key" value="<?= h($toggle['key']) ?>">
                            <input type="hidden" name="setting_value" value="0">
                            <div class="settings-row"><span class="label"><span><?= h($toggle['label']) ?></span></span><label class="control"><input type="checkbox" role="switch" name="setting_value" value="1" <?= $toggle['enabled'] ? 'checked' : '' ?> data-instant-toggle-input aria-label="Notifikasi <?= h($toggle['label']) ?>"><i class="toggle-switch" aria-hidden="true"></i></label></div>
                        </form>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        </div>
    </div>
</main>
