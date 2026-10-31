<?php
$pageVariables = get_defined_vars();
$telegramServiceSettings = is_array($pageVariables['telegramServiceSettings'] ?? null)
    ? $pageVariables['telegramServiceSettings']
    : [];
$telegramAccessAllowed = !empty($pageVariables['telegramFeatureAllowed']);
$settings = is_array($pageVariables['settings'] ?? null) ? $pageVariables['settings'] : [];
$events = is_array($pageVariables['events'] ?? null) ? $pageVariables['events'] : [];
$telegramHistoryPage = max(1, (int)($pageVariables['telegramHistoryPage'] ?? 1));
$telegramHistoryPages = max(1, (int)($pageVariables['telegramHistoryPages'] ?? 1));
$telegramHistoryTotal = max(0, (int)($pageVariables['telegramHistoryTotal'] ?? 0));
unset($pageVariables);

$service = $telegramServiceSettings;
$serviceEnabled = (int)($service['enabled'] ?? 0) === 1;
$telegramAvailable = $telegramAccessAllowed && $serviceEnabled;
$telegramLocked = !$telegramAvailable;

if (!$telegramAvailable) {
    $telegramAccessMessage = !$telegramAccessAllowed ? 'Tidak diizinkan.' : 'Belum tersedia.';
    ?>
    <section class="panel channel-panel">
        <p class="settings-empty"><?= h($telegramAccessMessage) ?></p>
    </section>
    <?php
    return;
}

$botToken = trim((string)($settings['telegram_bot_token'] ?? ''));
$botConfigured = $botToken !== '';
$chatId = trim((string)($settings['telegram_chat_id'] ?? ''));
$telegramConnected = $chatId !== '';
$telegramCredentialsSaved = $botConfigured && $telegramConnected;
?>

<section class="panel channel-panel">
    <?php if ($telegramCredentialsSaved): ?>
    <div class="channel-credential-summary">
        <span class="channel-credential-status-icon" aria-hidden="true"><i class="bi bi-check2"></i></span>
        <div>
            <strong>Bot Telegram terhubung</strong>
        </div>
        <div class="channel-credential-actions">
            <div class="channel-credential-menu" data-channel-credential-menu>
                <button class="icon-button channel-credential-menu-toggle" type="button" data-channel-credential-menu-toggle aria-label="Aksi koneksi Telegram" aria-haspopup="menu" aria-expanded="false" <?= $telegramLocked ? 'disabled' : '' ?>>
                    <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                </button>
                <div class="channel-credential-menu-list" data-channel-credential-menu-list role="menu" hidden>
                    <button type="button" role="menuitem" data-channel-credential-edit <?= $telegramLocked ? 'disabled' : '' ?>>
                        <i class="bi bi-pencil-square" aria-hidden="true"></i> Edit
                    </button>
                    <form method="post" class="channel-credential-menu-form">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="telegram_disable">
                        <button type="submit" role="menuitem" class="channel-credential-menu-danger" <?= $telegramLocked ? 'disabled' : '' ?>>
                            <i class="bi bi-plug" aria-hidden="true"></i> Putuskan
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div id="telegramCredentialsModal" class="<?= $telegramCredentialsSaved ? 'modal channel-credential-modal' : 'channel-credential-inline' ?>"<?= $telegramCredentialsSaved ? ' data-channel-credential-modal hidden aria-hidden="true"' : '' ?>>
        <div class="<?= $telegramCredentialsSaved ? 'modal-card channel-credential-modal-card' : '' ?>"<?= $telegramCredentialsSaved ? ' role="dialog" aria-modal="true" aria-labelledby="telegramCredentialsModalTitle"' : '' ?>>
            <?php if ($telegramCredentialsSaved): ?>
            <div class="channel-credential-modal-head modal-header">
                <h2 id="telegramCredentialsModalTitle">Edit Koneksi Telegram</h2>
                <button class="icon-button modal-close-button" type="button" data-channel-credential-modal-close aria-label="Tutup"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <?php endif; ?>
    <form
        id="telegramCredentialsForm"
        method="post"
        class="stack-form settings-form-narrow channel-credentials-form required-fields"
        data-channel-credentials-form
        data-credentials-saved="<?= $telegramCredentialsSaved ? '1' : '0' ?>"
    >
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_telegram_user_bot">

        <div class="field"><label for="telegramBotToken">Token Bot</label>
        <input id="telegramBotToken" type="password" name="telegram_bot_token" autocomplete="new-password" <?= $botConfigured ? '' : 'required' ?> <?= $telegramLocked ? 'disabled' : '' ?> value="" placeholder="<?= $botConfigured ? 'Kosongkan jika tidak diubah' : 'Masukkan Token Bot' ?>">
    </div>

        <div class="field"><label for="telegramChatId">Chat ID</label><input id="telegramChatId" type="text" name="telegram_chat_id" inputmode="numeric" autocomplete="off" <?= $telegramConnected ? '' : 'required' ?> <?= $telegramLocked ? 'disabled' : '' ?> value="" placeholder="<?= $telegramConnected ? 'Kosongkan jika tidak diubah' : 'Masukkan Chat ID' ?>" pattern="-?[0-9]{1,24}"></div>

        <div class="form-actions">
            <button class="button primary" type="submit" data-channel-credential-save <?= $telegramCredentialsSaved ? 'hidden' : '' ?> <?= $telegramLocked ? 'disabled' : '' ?>>
                <i class="bi bi-floppy" aria-hidden="true"></i> Simpan
            </button>
            <button class="button secondary" type="button" data-connection-test="telegram" data-connection-test-url="<?= h(public_app_url('connection-test.php')) ?>" <?= $telegramLocked ? 'disabled' : '' ?>>
                <i class="bi bi-send-check" aria-hidden="true"></i> Tes Koneksi
            </button>
        </div>
    </form>
        </div>
    </div>
</section>

<?php if ($telegramCredentialsSaved): ?>
<?php


$telegramHistoryUrl = static function (int $targetPage): string {
    return public_page_url('telegram', ['notif_page' => max(1, $targetPage)]);
};
?>
<?php
$channelHistoryPage = $telegramHistoryPage;
$channelHistoryPages = $telegramHistoryPages;
$channelHistoryTotal = $telegramHistoryTotal;
$channelHistoryEvents = $events;
$channelHistoryUrl = $telegramHistoryUrl;
require project_path('view', 'partials', 'channel-history.php');
?>
<?php endif; ?>
