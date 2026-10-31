<?php
$pageVariables = get_defined_vars();
$whatsappUserSettings = is_array($pageVariables['whatsappUserSettings'] ?? null)
    ? $pageVariables['whatsappUserSettings']
    : [];
$whatsappServiceSettings = is_array($pageVariables['whatsappServiceSettings'] ?? null)
    ? $pageVariables['whatsappServiceSettings']
    : [];
$whatsappAccessAllowed = !empty($pageVariables['whatsappFeatureAllowed']);
$whatsappProviders = is_array($pageVariables['whatsappProviders'] ?? null) ? $pageVariables['whatsappProviders'] : [];
$whatsappGatewayRegistry = is_array($pageVariables['whatsappGatewayRegistry'] ?? null) ? $pageVariables['whatsappGatewayRegistry'] : [];
$whatsappEvents = is_array($pageVariables['whatsappEvents'] ?? null) ? $pageVariables['whatsappEvents'] : [];
$whatsappHistoryPage = max(1, (int)($pageVariables['whatsappHistoryPage'] ?? 1));
$whatsappHistoryPages = max(1, (int)($pageVariables['whatsappHistoryPages'] ?? 1));
$whatsappHistoryTotal = max(0, (int)($pageVariables['whatsappHistoryTotal'] ?? 0));
unset($pageVariables);

$whatsappServiceEnabled = (int)($whatsappServiceSettings['enabled'] ?? 0) === 1;
$whatsappAvailable = $whatsappAccessAllowed && $whatsappServiceEnabled;
if (!$whatsappAvailable) {
    $whatsappAccessMessage = !$whatsappAccessAllowed ? 'Tidak diizinkan.' : 'Belum tersedia.';
    ?>
    <section class="panel channel-panel">
        <p class="settings-empty"><?= h($whatsappAccessMessage) ?></p>
    </section>
    <?php
    return;
}

$whatsappProvider = trim((string)($whatsappUserSettings['provider'] ?? ''));
if ($whatsappProvider === '' || !array_key_exists($whatsappProvider, $whatsappProviders)) {
    $whatsappProvider = (string)(array_key_first($whatsappProviders) ?: 'fonnte');
}
$whatsappDefinition = is_array($whatsappGatewayRegistry[$whatsappProvider] ?? null) ? $whatsappGatewayRegistry[$whatsappProvider] : [];
$whatsappProviderLabel = (string)($whatsappDefinition['label'] ?? $whatsappProvider);
$whatsappTokenSaved = trim((string)($whatsappUserSettings['token'] ?? '')) !== '';
$whatsappBaseUrlSaved = trim((string)($whatsappUserSettings['base_url'] ?? '')) !== '';
$whatsappTargetSaved = trim((string)($whatsappUserSettings['target'] ?? '')) !== '';
$whatsappTargetType = (string)($whatsappUserSettings['target_type'] ?? 'phone') === 'group' ? 'group' : 'phone';
$whatsappSupportsGroups = in_array('group', (array)($whatsappDefinition['target']['types'] ?? ['phone', 'group']), true);
$whatsappSupportsGroupListing = !empty($whatsappDefinition['groups_available']);
$whatsappGroupHint = trim((string)($whatsappDefinition['target']['group_hint'] ?? ''));
if ($whatsappGroupHint === '') {
    $whatsappGroupHint = 'Tempel Group ID dari dashboard gateway Anda (umumnya berakhiran @g.us).';
}
$whatsappInfoUrl = trim((string)($whatsappDefinition['info_url'] ?? ''));
$whatsappNeedsLabels = [];
foreach ((array)($whatsappDefinition['fields'] ?? []) as $needsKey => $needsField) {
    $whatsappNeedsLabels[] = (string)(((array)$needsField)['label'] ?? $needsKey);
}
$whatsappNeedsText = $whatsappNeedsLabels !== []
    ? $whatsappProviderLabel . ' memerlukan: ' . implode(', ', $whatsappNeedsLabels) . ' + tujuan pengiriman.'
    : $whatsappProviderLabel . ' tidak memerlukan kredensial tambahan; cukup tentukan tujuan pengiriman.';
$whatsappConnected = ($whatsappTokenSaved || $whatsappBaseUrlSaved) && $whatsappTargetSaved;

?>

<section class="panel channel-panel">
    <?php if ($whatsappConnected): ?>
        <div class="channel-credential-summary">
            <span class="channel-credential-status-icon" aria-hidden="true"><i class="bi bi-check2"></i></span>
            <div>
                <strong>Terhubung</strong>
                <span class="channel-credential-meta"><?= h($whatsappProviderLabel) ?> &middot; <?= $whatsappTargetType === 'group' ? 'grup' : 'nomor' ?> <?= h((string)$whatsappUserSettings['target']) ?></span>
            </div>
            <div class="channel-credential-actions">
                <div class="channel-credential-menu" data-channel-credential-menu>
                    <button class="icon-button channel-credential-menu-toggle" type="button" data-channel-credential-menu-toggle aria-label="Aksi koneksi WhatsApp" aria-haspopup="menu" aria-expanded="false">
                        <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                    </button>
                    <div class="channel-credential-menu-list" data-channel-credential-menu-list role="menu" hidden>
                        <button type="button" role="menuitem" data-channel-credential-edit>
                            <i class="bi bi-pencil-square" aria-hidden="true"></i> Edit
                        </button>
                        <form method="post" class="channel-credential-menu-form">
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="whatsapp_disable">
                            <button type="submit" role="menuitem" class="channel-credential-menu-danger">
                                <i class="bi bi-plug" aria-hidden="true"></i> Putuskan
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div id="whatsappCredentialsModal" class="<?= $whatsappConnected ? 'modal channel-credential-modal' : 'channel-credential-inline' ?>"<?= $whatsappConnected ? ' data-channel-credential-modal hidden aria-hidden="true"' : '' ?>>
        <div class="<?= $whatsappConnected ? 'modal-card channel-credential-modal-card' : '' ?>"<?= $whatsappConnected ? ' role="dialog" aria-modal="true" aria-labelledby="whatsappCredentialsModalTitle"' : '' ?>>
            <?php if ($whatsappConnected): ?>
            <div class="channel-credential-modal-head modal-header">
                <h2 id="whatsappCredentialsModalTitle">Edit Koneksi WhatsApp</h2>
                <button class="icon-button modal-close-button" type="button" data-channel-credential-modal-close aria-label="Tutup"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <?php endif; ?>
    <form
        id="whatsappCredentialsForm"
        method="post"
        class="stack-form settings-form-narrow channel-credentials-form required-fields"
        data-channel-credentials-form data-channel-edit-focus="[name='whatsapp_target']:not(:disabled)"
        data-whatsapp-credentials-form data-whatsapp-supports-group-listing="<?= $whatsappSupportsGroupListing ? '1' : '0' ?>"
        data-credentials-saved="<?= $whatsappConnected ? '1' : '0' ?>">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_whatsapp_connection">
        <input type="hidden" name="whatsapp_target_type" value="<?= h($whatsappTargetType) ?>" data-whatsapp-target-type-input>

        <div class="field">
            <label for="whatsappProvider">Gateway</label>
            <select id="whatsappProvider" name="whatsapp_provider" data-whatsapp-provider>
                <?php foreach ($whatsappProviders as $providerKey => $providerOptionLabel): ?>
                    <option value="<?= h((string)$providerKey) ?>" <?= (string)$providerKey === $whatsappProvider ? 'selected' : '' ?>><?= h((string)$providerOptionLabel) ?><?= !empty($whatsappGatewayRegistry[$providerKey]['verified']) ? ' - OK' : '' ?></option>
                <?php endforeach; ?>
            </select>
            <small class="field-help" data-whatsapp-provider-info><?= h((string)($whatsappDefinition['info'] ?? '')) ?></small>
            <small class="field-help-link">
                Situs <span data-whatsapp-provider-label><?= h($whatsappProviderLabel) ?></span>:
                <a class="text-primary" data-whatsapp-provider-link href="<?= h($whatsappInfoUrl !== '' ? $whatsappInfoUrl : '#') ?>" target="_blank" rel="noopener noreferrer" <?= $whatsappInfoUrl === '' ? 'hidden style="display:none"' : '' ?>><?= h($whatsappInfoUrl) ?></a>
            </small>
        </div>

        <div class="field whatsapp-target-type" data-whatsapp-target-type-fieldset>
            <div class="choice-check-row">
                <label class="choice-check-card"><input type="radio" name="whatsapp_target_type_choice" value="phone" <?= $whatsappTargetType === 'phone' ? 'checked' : '' ?> data-whatsapp-target-type><i class="choice-check-mark" aria-hidden="true"><i class="bi bi-check-lg"></i></i><span>Pribadi</span></label>
                <label class="choice-check-card"><input type="radio" name="whatsapp_target_type_choice" value="group" <?= $whatsappTargetType === 'group' ? 'checked' : '' ?> <?= $whatsappSupportsGroups ? '' : 'disabled' ?> data-whatsapp-target-type><i class="choice-check-mark" aria-hidden="true"><i class="bi bi-check-lg"></i></i><span>Grup</span></label>
            </div>
        </div>

        <div data-whatsapp-credential-fields>
            <?php require project_path('view', 'partials', 'whatsapp', 'whatsapp-credential-fields.php'); ?>
        </div>

        <div class="field" data-whatsapp-phone-field <?= $whatsappTargetType === 'group' ? 'hidden' : '' ?>>
            <label for="whatsappTarget">Nomor Tujuan</label>
            <input id="whatsappTarget" type="text" name="whatsapp_target" autocomplete="off" maxlength="120" value=""
                data-whatsapp-target-saved="<?= $whatsappTargetSaved && $whatsappTargetType === 'phone' ? '1' : '0' ?>"
                <?= $whatsappTargetType === 'group' ? 'disabled' : '' ?>
                <?= !$whatsappTargetSaved && $whatsappTargetType === 'phone' ? 'required' : '' ?>
                pattern="(0|62|8)[0-9]{8,15}" placeholder="Masukkan Nomor Tujuan" data-whatsapp-target-input>
        </div>

        <div class="field" data-whatsapp-group-field <?= $whatsappTargetType === 'phone' ? 'hidden' : '' ?>>
            <label for="whatsappGroup">Grup WhatsApp</label>
            <div class="input-button-group">
                <select id="whatsappGroup" name="whatsapp_target" data-whatsapp-group-select data-select-search="Cari grup WhatsApp"
                    <?= $whatsappTargetType === 'phone' ? 'disabled' : '' ?>
                    <?= !$whatsappTargetSaved && $whatsappTargetType === 'group' ? 'required' : '' ?>>
                    <option  value="">Minta daftar group</option>
                    <?php if ($whatsappTargetSaved && $whatsappTargetType === 'group'): ?>
                        <option value="<?= h((string)$whatsappUserSettings['target']) ?>" selected><?= h((string)$whatsappUserSettings['target']) ?> (tersimpan)</option>
                    <?php endif; ?>
                    <option value="__manual__">Group ID manual</option>
                </select>
                <button class="button secondary" type="button" data-whatsapp-load-groups <?= $whatsappSupportsGroupListing ? '' : 'hidden' ?>>
                    <i class="bi bi-arrow-down-circle" aria-hidden="true"></i>Minta</button>
            </div>
           
            <label for="whatsappGroupManual" data-whatsapp-group-manual-label hidden>Group ID manual</label>
            <input id="whatsappGroupManual" type="text" name="whatsapp_target" autocomplete="off" maxlength="120"
                pattern="([A-Za-z0-9_.:\-]{3,80}@[A-Za-z0-9.\-]{2,20}|[0-9]{6,30})"
                placeholder="Masukkan Group ID"
                data-whatsapp-group-manual hidden disabled>
        </div>

        <div class="form-actions whatsapp-connection-actions" data-whatsapp-connection-actions>
            <button class="button primary" type="submit" data-channel-credential-save>
                <i class="bi bi-floppy" aria-hidden="true"></i> Simpan
            </button>
            <button class="button secondary" type="button" data-connection-test="whatsapp" data-connection-test-url="<?= h(public_app_url('connection-test.php')) ?>">
                <i class="bi bi-send-check" aria-hidden="true"></i> Tes Koneksi
            </button>
        </div>
    </form>
        </div>
    </div>

</section>

<?php if ($whatsappConnected): ?>
    <?php
    $whatsappHistoryUrl = static function (int $targetPage): string {
        return public_page_url('whatsapp', ['notif_page' => max(1, $targetPage)]);
    };
    ?>
    <?php
$channelHistoryPage = $whatsappHistoryPage;
$channelHistoryPages = $whatsappHistoryPages;
$channelHistoryTotal = $whatsappHistoryTotal;
$channelHistoryEvents = $whatsappEvents;
$channelHistoryUrl = $whatsappHistoryUrl;
require project_path('view', 'partials', 'channel-history.php');
?>
<?php endif; ?>
