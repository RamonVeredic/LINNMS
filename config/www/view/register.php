<?php
$oldRegister = is_array($_SESSION['old_register'] ?? null) ? $_SESSION['old_register'] : [];
$registerValidation = is_array($_SESSION['register_validation'] ?? null) ? $_SESSION['register_validation'] : [];
unset($_SESSION['register_validation']);
$registerValidationField = trim((string)($registerValidation['field'] ?? ''));
$registerValidationMessage = trim((string)($registerValidation['message'] ?? ''));
$registerValidationAttributes = static function (string $field) use ($registerValidationField, $registerValidationMessage): string {
    if ($field !== $registerValidationField || $registerValidationMessage === '') return '';

    return ' data-register-error="' . h($registerValidationMessage) . '" aria-invalid="true"';
};
$publicRegistrationEnabled = !empty($publicRegistrationEnabled);
$otpRegistrationEnabled = !empty($otpRegistrationEnabled);
$pendingRegister = is_array($_SESSION['register_pending'] ?? null) ? $_SESSION['register_pending'] : null;
$otpLength = (int)($registrationRules['otp_length'] ?? 0);
$otpTtlMinutes = max(1, (int)ceil((int)($registrationRules['otp_ttl_seconds'] ?? 0) / 60));
$minPasswordLength = (int)($registrationRules['min_password_length'] ?? 0);
$pendingPhone = $pendingRegister !== null ? trim((string)($pendingRegister['phone'] ?? '')) : '';
$otpPhoneMasked = $pendingPhone !== '' ? mask_phone_number($pendingPhone) : '';
$otpCooldown = is_array($_SESSION['register_otp_cooldown'] ?? null) ? $_SESSION['register_otp_cooldown'] : [];
$otpCooldownPhone = trim((string)($otpCooldown['phone'] ?? ''));
$otpCooldownWait = max(0, (int)($otpCooldown['until'] ?? 0) - time());
$otpResendWait = max(0, (int)($pendingRegister['otp_requested_at'] ?? 0) + 60 - time());
if ($otpCooldownPhone !== '' && $otpCooldownPhone === normalize_phone_number($pendingPhone)) {
    $otpResendWait = max($otpResendWait, $otpCooldownWait);
}
$requestedStep = strtolower(trim((string)($_GET['step'] ?? '')));

$registrationStep = 'form';
if ($otpRegistrationEnabled && $pendingRegister !== null) {
    $pendingHasOtp = !empty($registerPendingHasOtp ?? false);
    if ($requestedStep === 'form') {
        $registrationStep = 'form';
    } elseif ($pendingPhone === '' || $requestedStep === 'wa') {
        $registrationStep = 'wa';
    } elseif ($requestedStep === 'otp' || $pendingHasOtp) {
        $registrationStep = 'otp';
    } else {
        $registrationStep = 'wa';
    }
}
if ($registrationStep === 'form') {

    $_SESSION['register_form_started_at'] = time();
}
$serviceContact = is_array($serviceContact ?? null) ? $serviceContact : ['whatsapp' => '', 'email' => ''];
$serviceWhatsapp = trim((string)($serviceContact['whatsapp'] ?? ''));
$serviceEmail = trim((string)($serviceContact['email'] ?? ''));
$serviceWhatsappDigits = preg_replace('/\D+/', '', $serviceWhatsapp) ?? '';
if (str_starts_with($serviceWhatsappDigits, '0')) {
    $serviceWhatsappDigits = '62' . substr($serviceWhatsappDigits, 1);
}
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Daftar &middot; <?= h($appName) ?></title>
    <link rel="icon" type="image/x-icon" href="<?= h(asset_url('assets/icon.ico', (string)(@filemtime(LINNMS_UI_ROOT . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'icon.ico') ?: 0))) ?>">
    <link rel="stylesheet" href="<?= h(asset_url('assets/icon/bootstrap-icons/bootstrap-icons.min.css', '1.13.1')) ?>"><?php foreach (array_merge(['assets/css/linnms-main.min.css'], page_asset_manifest('register')['styles'], ['assets/css/responsif.css', 'assets/css/linnms-termplet.css']) as $styleAsset): $stylePath = LINNMS_UI_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $styleAsset); ?>
        <link rel="stylesheet" href="<?= h(asset_url($styleAsset, $appVersion . '-' . (string)(@filemtime($stylePath) ?: 0))) ?>"><?php endforeach; ?>
</head>

<body class="login-body registration-body">
    <main class="login-shell registration-shell">
        <section class="login-card registration-card">
            <div class="login-brand">
                <i class="brand-icon bi bi-boxes" aria-hidden="true"></i>
                <span class="brand-copy"><strong><?= h($appName) ?></strong><small>Network Management</small></span>
            </div>
            <?php foreach (($flashes ?? []) as $registerFlash): ?>
                <?php
                if (!is_array($registerFlash)) continue;
                $registerFlashMessage = trim((string)($registerFlash['message'] ?? ''));
                if ($registerFlashMessage === '' || $registerFlashMessage === $registerValidationMessage) continue;
                $registerFlashType = strtolower(trim((string)($registerFlash['type'] ?? 'info')));
                if (!in_array($registerFlashType, ['success', 'error', 'warning'], true)) $registerFlashType = 'warning';
                ?>
                <div class="alert <?= h($registerFlashType) ?>" role="<?= $registerFlashType === 'error' ? 'alert' : 'status' ?>"><?= h($registerFlashMessage) ?></div>
            <?php endforeach; ?>
            <div class="alert danger" role="alert" data-register-status hidden></div>
            <div class="onu-search-status registration-loading" data-register-loading role="status" aria-label="Pendaftaran sedang diproses" hidden><span class="onu-loading-badge"><i class="bi bi-arrow-repeat sync-loading-spinner" aria-hidden="true"></i></span></div>
            <?php if ($publicRegistrationEnabled): ?>
                <div data-register-stage="form" <?= $registrationStep !== 'form' ? 'hidden' : '' ?>>
                <header class="registration-heading">
                    <p class="eyebrow">Pendaftaran</p>
                    <h1>Daftar akun</h1>
                </header>
                <form method="post" class="login-form registration-form" data-register-form="account">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="register_account">
                    <div class="field registration-field">
                        <label for="registerDisplayName">Nama lengkap</label>
                        <input id="registerDisplayName" name="display_name" minlength="2" maxlength="120" value="<?= h((string)($oldRegister['display_name'] ?? ($pendingRegister['display_name'] ?? ''))) ?>" autocomplete="name" required <?= $registrationStep === 'form' ? 'autofocus' : '' ?> placeholder="Contoh: Budi Santoso"<?= $registerValidationAttributes('display_name') ?>>
                    </div>
                    <div class="field registration-field">
                        <label for="registerUsername">Username</label>
                        <input id="registerUsername" name="username" maxlength="80" value="<?= h((string)($oldRegister['username'] ?? ($pendingRegister['username'] ?? ''))) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required placeholder="Nama pengguna untuk masuk"<?= $registerValidationAttributes('username') ?>>
                    </div>
                    <div class="field registration-field">
                        <label for="registerPassword">Password</label>
                        <input id="registerPassword" type="password" name="password" minlength="<?= (int)$minPasswordLength ?>" maxlength="200" autocomplete="new-password" required placeholder="Minimal <?= (int)$minPasswordLength ?> karakter"<?= $registerValidationAttributes('password') ?>>
                    </div>
                    <div class="field registration-field">
                        <label for="registerPasswordConfirm">Konfirmasi password</label>
                        <input id="registerPasswordConfirm" type="password" name="password_confirm" minlength="<?= (int)$minPasswordLength ?>" maxlength="200" autocomplete="new-password" required placeholder="Ketik ulang password"<?= $registerValidationAttributes('password_confirm') ?>>
                    </div>
                    <label class="registration-honeypot" aria-hidden="true"><span>Website</span><input name="website" tabindex="-1" autocomplete="off"></label>
                    <button class="button primary login-button registration-submit" type="submit">Daftar</button>
                </form>
                </div>
                <div data-register-stage="wa" <?= $registrationStep !== 'wa' ? 'hidden' : '' ?>>
                <header class="registration-heading">
                    <p class="eyebrow">Verifikasi WhatsApp</p>
                </header>
                <form method="post" class="login-form registration-form" data-register-form="phone">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="register_otp_request">
                    <div class="field registration-field">
                        <label for="registerPhone">Nomor WhatsApp</label>
                        <input id="registerPhone" name="phone_number" type="tel" inputmode="numeric" value="<?= h($pendingPhone !== '' ? $pendingPhone : (string)($oldRegister['phone_number'] ?? '')) ?>" autocomplete="tel" required pattern="(0|62|8)[0-9]{8,15}" <?= $registrationStep === 'wa' ? 'autofocus' : '' ?> placeholder="Contoh: 08123456789"<?= $registerValidationAttributes('phone_number') ?>>
                    </div>
                    <button class="button primary login-button registration-submit" type="submit" data-otp-request data-otp-rate-phone="<?= h($otpCooldownPhone) ?>" data-otp-rate-wait="<?= $otpCooldownWait ?>"><i class="bi bi-whatsapp" aria-hidden="true"></i><span data-otp-request-label>Kirim kode</span></button>
                </form>
                <div class="registration-otp-actions">
                    <a class="registration-text-action" href="?page=register&amp;step=form" data-register-go="form"><i class="bi bi-arrow-left" aria-hidden="true"></i> Ubah data akun</a>
                    <form method="post" data-register-form="cancel">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="register_otp_cancel">
                        <button class="registration-text-action button-danger" type="submit">Batalkan</button>
                    </form>
                </div>
                </div>
                <div data-register-stage="otp" <?= $registrationStep !== 'otp' ? 'hidden' : '' ?>>
                <header class="registration-heading">
                    <p class="eyebrow">Verifikasi WhatsApp</p>
                    <p>Kode dikirim ke <strong data-register-phone-mask><?= h($otpPhoneMasked) ?></strong>. Berlaku <?= (int)$otpTtlMinutes ?> menit.</p>
                </header>
                <form method="post" class="login-form registration-form" data-register-form="verify">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="register_otp_verify">
                    <div class="field registration-field registration-otp-field">
                        <label for="registerOtp">Kode verifikasi</label>
                        <input id="registerOtp" name="otp_code" inputmode="numeric" autocomplete="one-time-code" minlength="<?= (int)$otpLength ?>" maxlength="<?= (int)$otpLength ?>" pattern="[0-9]{<?= (int)$otpLength ?>}" required <?= $registrationStep === 'otp' ? 'autofocus' : '' ?> placeholder="<?= str_repeat('0', (int)$otpLength) ?>"<?= $registerValidationAttributes('otp_code') ?>>
                    </div>
                    <button class="button primary login-button registration-submit" type="submit"><i class="bi bi-check2-circle" aria-hidden="true"></i><span>Buat akun</span></button>
                </form>
                <form method="post" class="login-form login-form-inline" data-register-form="resend">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="register_otp_request">
                    <button class="button secondary registration-resend" type="submit" data-otp-resend data-otp-wait-seconds="<?= $otpResendWait ?>" <?= $otpResendWait > 0 ? 'disabled' : '' ?>>
                        <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                        <span data-otp-resend-label><?= $otpResendWait > 0 ? 'Kirim ulang kode (' . $otpResendWait . ' dtk)' : 'Kirim ulang kode' ?></span>
                    </button>
                </form>
                <div class="registration-otp-actions">
                    <a class="registration-text-action" href="?page=register&amp;step=wa" data-register-go="wa"><i class="bi bi-arrow-left" aria-hidden="true"></i> Ganti nomor</a>
                    <form method="post" data-register-form="cancel">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="register_otp_cancel">
                        <button class="registration-text-action button-danger" type="submit">Mulai ulang</button>
                    </form>
                </div>
                </div>
            <?php else: ?>
                <div class="registration-unavailable">
                    <p class="eyebrow">Informasi Pendaftaran</p>
                    <h1>Pendaftaran Tidak Tersedia</h1>
                    <p>Silakan hubungi layanan melalui kontak berikut.</p>
                    <div class="registration-contact-list">
                        <?php if ($serviceWhatsapp !== '' && $serviceWhatsappDigits !== ''): ?>
                            <a href="https://wa.me/<?= h($serviceWhatsappDigits) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-whatsapp" aria-hidden="true"></i><span>WhatsApp</span><span><?= h($serviceWhatsapp) ?></span></a>
                        <?php endif; ?>
                        <?php if ($serviceEmail !== ''): ?>
                            <a href="mailto:<?= h($serviceEmail) ?>"><i class="bi bi-envelope" aria-hidden="true"></i><span>Email</span><span><?= h($serviceEmail) ?></span></a>
                        <?php endif; ?>
                        <?php if (($serviceWhatsapp === '' || $serviceWhatsappDigits === '') && $serviceEmail === ''): ?>
                            <div class="registration-contact-empty"><i class="bi bi-info-circle" aria-hidden="true"></i><span>Kontak layanan belum tersedia.</span></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="default-login">
                <p>Sudah memiliki akun? <a class="text-primary fw-bold" href="<?= h(public_page_url('login')) ?>">Masuk di sini</a></p>
            </div>
        </section>
    </main>
    <?php $appScriptPath = LINNMS_UI_ROOT . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'app.js'; ?>
    <script src="<?= h(asset_url('assets/js/app.js', $appVersion . '-' . (string)(@filemtime($appScriptPath) ?: 0))) ?>"></script>
    <?php $ajaxScriptPath = LINNMS_UI_ROOT . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'ajax' . DIRECTORY_SEPARATOR . 'client.js'; ?>
    <script src="<?= h(asset_url('assets/js/ajax/client.js', $appVersion . '-' . (string)(@filemtime($ajaxScriptPath) ?: 0))) ?>"></script>
    <?php $registerScriptAsset = page_asset_manifest('register')['scripts'][0];
    $registerScriptPath = LINNMS_UI_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $registerScriptAsset); ?>
    <script src="<?= h(asset_url($registerScriptAsset, $appVersion . '-' . (string)(@filemtime($registerScriptPath) ?: 0))) ?>"></script>
</body>

</html>
