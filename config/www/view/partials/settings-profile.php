<?php
$oltServiceLimit = (int)($profileServiceLimits['olt'] ?? 0);
$mikrotikServiceLimit = (int)($profileServiceLimits['mikrotik'] ?? 0);
$switchServiceLimit = (int)($profileServiceLimits['switch'] ?? 0);
$oltServiceLimitLabel = $oltServiceLimit === 0 ? 'Unlimited' : (string)$oltServiceLimit;
$mikrotikServiceLimitLabel = $mikrotikServiceLimit === 0 ? 'Unlimited' : (string)$mikrotikServiceLimit;
$switchServiceLimitLabel = $switchServiceLimit === 0 ? 'Unlimited' : (string)$switchServiceLimit;
?>
    <section class="panel profile-page" aria-labelledby="profileTitle">
        <header class="profile-header">
            <div class="profile-identity">
                <div class="profile-avatar"><i class="bi bi-person" aria-hidden="true"></i></div>
                <div>
                    <span class="profile-eyebrow">Profil Pengguna</span>
                    <div id="profileTitle"><?= h(current_display_name()) ?></div>
                </div>
            </div>
          
                <button class="button secondary" type="button" data-profile-edit-open data-settings-modal-open="profileEditPanel" aria-expanded="false" aria-controls="profileEditPanel" aria-haspopup="dialog">
                    <i class="bi bi-pencil-square" aria-hidden="true"></i> Edit
                </button>
           
        </header>

        <div class="profile-information">
            <div class="profile-information-group">
                <div class="profile-information-heading">
                    <h2>Detail Akun</h2>
                </div>
                <div class="profile-information-list">
                    <?php $profileText = static fn($v): string => trim((string)($v ?? '')) !== '' ? (string)$v : '-'; ?>
                    <div class="profile-info-username"><span class="profile-information-label">Username</span><span class="profile-information-value"><?= h($profileText($user['username'] ?? null)) ?></span></div>
                    <div class="profile-info-whatsapp"><span class="profile-information-label">WhatsApp</span><span class="profile-information-value"><?= h($profileText($user['phone_number'] ?? null)) ?></span></div>
                    <div class="profile-info-olt"><span class="profile-information-label">OLT</span><span class="profile-information-value"><?= h($oltServiceLimitLabel) ?></span></div>
                    <div class="profile-info-switch"><span class="profile-information-label">Switch</span><span class="profile-information-value"><?= h($switchServiceLimitLabel) ?></span></div>
                    <div class="profile-info-router"><span class="profile-information-label">Router</span><span class="profile-information-value"><?= h($mikrotikServiceLimitLabel) ?></span></div>
                    <div class="profile-info-registered"><span class="profile-information-label">Terdaftar</span><span class="profile-information-value"><?= h(format_feed_time($user['created_at'] ?? null)) ?></span></div>
                    <div class="profile-info-last"><span class="profile-information-label">Terakhir Login</span><span class="profile-information-value"><?= h(format_feed_time($user['last_login_at'] ?? null)) ?></span></div>
                </div>
            </div>
        </div>

        <div class="modal settings-edit-modal" id="profileEditPanel" data-settings-modal hidden>
            <div class="modal-card settings-edit-dialog" role="dialog" aria-modal="true" aria-labelledby="profileEditTitle" tabindex="-1">
                <div class="panel-heading tittle-subtitle settings-edit-heading modal-header">
                    <h2 id="profileEditTitle">Edit Profil</h2>
                    <button class="icon-button modal-close-button" type="button" data-settings-modal-close aria-label="Tutup edit profil"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <form method="post" class="stack-form">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="form-grid">
                        <div class="field"><label for="profileDisplayName">Nama</label><input id="profileDisplayName" name="display_name" maxlength="60" required value="<?= h((string) ($user['display_name'] ?? '')) ?>"></div>
                        <div class="field"><label for="profileUsername">Username</label><input class="muted" id="profileUsername" value="<?= h((string) ($user['username'] ?? '')) ?>" readonly autocomplete="username"></div>
                        <div class="field"><label for="profileContact">WhatsApp</label><input id="profileContact" name="phone_number" type="tel" maxlength="24" autocomplete="tel" value="<?= h((string) ($user['phone_number'] ?? '')) ?>"></div>
                        <div class="field"><label for="profileCurrentPassword">Password Saat Ini</label><input id="profileCurrentPassword" type="password" name="current_password" required autocomplete="current-password"></div>
                        <div class="field"><label for="profileNewPassword">Password Baru</label><input id="profileNewPassword" type="password" name="new_password" minlength="8" autocomplete="new-password"></div>
                    </div>
                    <div class="form-actions"><button class="button primary"><i class="bi bi-floppy" aria-hidden="true"></i> Simpan</button></div>
                </form>
            </div>
        </div>

        <?php require project_path('view', 'partials', 'settings-onu-backup.php'); ?>
    </section>
