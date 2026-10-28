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
            <button class="button secondary channel-credential-edit" type="button" data-channel-credential-edit aria-expanded="false" <?= $telegramLocked ? 'disabled' : '' ?>>
                <i class="bi bi-pencil-square" aria-hidden="true"></i>
                <span>Edit koneksi</span>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <form
        id="telegramCredentialsForm"
        method="post"
        class="stack-form settings-form-narrow channel-credentials-form required-fields"
        data-channel-credentials-form
        data-credentials-saved="<?= $telegramCredentialsSaved ? '1' : '0' ?>"
        <?= $telegramCredentialsSaved ? 'hidden' : '' ?>
    >
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_telegram_user_bot">

        <div class="field"><label for="telegramBotToken">Token Bot</label><input id="telegramBotToken" type="password" name="telegram_bot_token" autocomplete="new-password" <?= $botConfigured ? '' : 'required' ?> <?= $telegramLocked ? 'disabled' : '' ?> value="" placeholder="<?= $botConfigured ? 'Kosongkan jika tidak diubah' : 'Token Bot' ?>"></div>

        <div class="field"><label for="telegramChatId">Chat ID</label><input id="telegramChatId" type="text" name="telegram_chat_id" inputmode="numeric" autocomplete="off" <?= $telegramConnected ? '' : 'required' ?> <?= $telegramLocked ? 'disabled' : '' ?> value="" placeholder="<?= $telegramConnected ? 'Kosongkan jika tidak diubah' : '-1001234567890' ?>" pattern="-?[0-9]{1,24}"></div>

        <div class="form-actions">
            <button class="button primary" type="submit" data-channel-credential-save <?= $telegramCredentialsSaved ? 'hidden' : '' ?> <?= $telegramLocked ? 'disabled' : '' ?>>
                <i class="bi bi-floppy" aria-hidden="true"></i> Simpan
            </button>
            <button class="button secondary" type="button" data-connection-test="telegram" data-connection-test-url="<?= h(public_app_url('connection-test.php')) ?>" <?= $telegramLocked ? 'disabled' : '' ?>>
                <i class="bi bi-send-check" aria-hidden="true"></i> Tes Koneksi
            </button>
        </div>
    </form>

    <?php if ($telegramCredentialsSaved): ?>
    <form method="post" class="form-actions channel-save-actions">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="telegram_disable">
        <button class="button button-danger" type="submit" <?= $telegramLocked ? 'disabled' : '' ?>><i class="bi bi-plug" aria-hidden="true"></i> Putuskan Telegram</button>
   </form>
    <?php endif; ?>
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
require project_path('pages', 'partials', 'channel-history.php');
?>
<?php endif; ?>
