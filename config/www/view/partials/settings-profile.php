<?php
$oltServiceLimit = (int)($profileServiceLimits['olt'] ?? LinnmsRepository::DEFAULT_OLT_LIMIT);
$mikrotikServiceLimit = (int)($profileServiceLimits['mikrotik'] ?? LinnmsRepository::DEFAULT_MIKROTIK_LIMIT);
$switchServiceLimit = (int)($profileServiceLimits['switch'] ?? LinnmsRepository::DEFAULT_SWITCH_LIMIT);
$oltServiceLimitLabel = $oltServiceLimit === 0 ? 'Unlimited' : (string)$oltServiceLimit;
$mikrotikServiceLimitLabel = $mikrotikServiceLimit === 0 ? 'Unlimited' : (string)$mikrotikServiceLimit;
$switchServiceLimitLabel = $switchServiceLimit === 0 ? 'Unlimited' : (string)$switchServiceLimit;
$accountTier = trim((string)($accountTier ?? ''));
if ($accountTier === '') $accountTier = '-';
?>
    <section class="panel profile-page" aria-labelledby="profileTitle">
        <header class="profile-header">
            <div class="profile-identity">
                <div class="profile-avatar"><i class="bi bi-person" aria-hidden="true"></i></div>
                <div>
                    <span class="profile-eyebrow">Profil Pengguna</span>
                    <h2 id="profileTitle"><?= h(current_display_name()) ?></h2>
                    <p>Username: <?= h(current_username()) ?></p>
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
                    <div><span class="profile-information-label">Username</span><span class="profile-information-value"><?= h($profileText($user['username'] ?? null)) ?></span></div>
                    <div><span class="profile-information-label">Kontak</span><span class="profile-information-value"><?= h($profileText($user['phone_number'] ?? null)) ?></span></div>

                    <div><span class="profile-information-label">Email</span><span class="profile-information-value"><?= h($profileText($user['email'] ?? null)) ?></span></div>
                    <div><span class="profile-information-label">Deskripsi</span><span class="profile-information-value"><?= h($profileText($user['company'] ?? null)) ?></span></div>

                    <div><span class="profile-information-label">Tier</span><span class="profile-information-value"><?= h($accountTier) ?></span></div>
                    <div><span class="profile-information-label">OLT</span><span class="profile-information-value"><?= h($oltServiceLimitLabel) ?></span></div>
                    <div><span class="profile-information-label">Switch</span><span class="profile-information-value"><?= h($switchServiceLimitLabel) ?></span></div>
                    <div><span class="profile-information-label">Router</span><span class="profile-information-value"><?= h($mikrotikServiceLimitLabel) ?></span></div>
                    <div><span class="profile-information-label">Terdaftar</span><span class="profile-information-value"><?= h(format_feed_time($user['created_at'] ?? null)) ?></span></div>
                    <div><span class="profile-information-label">Terakhir login</span><span class="profile-information-value"><?= h(format_feed_time($user['last_login_at'] ?? null)) ?></span></div>

                    <div><span class="profile-information-label">Aplikasi</span><span class="profile-information-value"><?= h($appName) ?></span></div>
                    <div><span class="profile-information-label">Versi</span><span class="profile-information-value"><?= h($appVersion !== '' ? $appVersion : '-') ?></span></div>
                </div>
            </div>
        </div>

        <div class="modal settings-edit-modal" id="profileEditPanel" data-settings-modal hidden>
            <div class="modal-card settings-edit-dialog" role="dialog" aria-modal="true" aria-labelledby="profileEditTitle" tabindex="-1">
                <div class="panel-heading tittle-subtitle settings-edit-heading">
                    <h2 id="profileEditTitle">Edit Profil</h2>
                    <button class="button secondary" type="button" data-settings-modal-close aria-label="Tutup edit profil"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <form method="post" class="stack-form">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="form-grid">
                        <div class="field"><label for="profileDisplayName">Nama</label><input id="profileDisplayName" name="display_name" maxlength="60" required value="<?= h((string) ($user['display_name'] ?? '')) ?>"></div>
                        <div class="field"><label for="profileUsername">Username</label><input class="muted" id="profileUsername" value="<?= h((string) ($user['username'] ?? '')) ?>" readonly autocomplete="username"></div>
                        <div class="field"><label for="profileEmail">Email</label><input id="profileEmail" name="email" type="email" maxlength="190" autocomplete="email" value="<?= h((string) ($user['email'] ?? '')) ?>"></div>
                        <div class="field"><label for="profileDescription">Deskripsi</label><input id="profileDescription" name="company" maxlength="190" value="<?= h((string) ($user['company'] ?? '')) ?>"></div>
                        <div class="field"><label for="profileContact">Kontak</label><input id="profileContact" name="phone_number" type="tel" maxlength="24" autocomplete="tel" value="<?= h((string) ($user['phone_number'] ?? '')) ?>"></div>
                        <div class="field"><label for="profileCurrentPassword">Password Saat Ini</label><input id="profileCurrentPassword" type="password" name="current_password" required autocomplete="current-password"></div>
                        <div class="field"><label for="profileNewPassword">Password Baru</label><input id="profileNewPassword" type="password" name="new_password" minlength="8" autocomplete="new-password"></div>
                    </div>
                    <div class="form-actions"><button class="button primary"><i class="bi bi-floppy" aria-hidden="true"></i> Simpan</button></div>
                </form>
            </div>
        </div>

        <?php require project_path('pages', 'partials', 'settings-onu-backup.php'); ?>
    </section>
