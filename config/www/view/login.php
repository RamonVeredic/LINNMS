<?php $oldLoginUsername = (string)($_SESSION['old_login_username'] ?? ''); unset($_SESSION['old_login_username']); ?>
<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login &middot; <?= h($appName) ?></title><link rel="icon" type="image/x-icon" href="<?= h(asset_url('assets/icon.ico', (string)(@filemtime(LINNMS_UI_ROOT.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'icon.ico') ?: 0))) ?>"><link rel="stylesheet" href="<?= h(asset_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css', '1.13.1')) ?>"><?php foreach (array_merge(['assets/css/app.css'], page_asset_manifest('login')['styles'], ['assets/css/responsif.css', 'assets/css/toast.css']) as $styleAsset): $stylePath=LINNMS_UI_ROOT.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$styleAsset); ?><link rel="stylesheet" href="<?= h(asset_url($styleAsset,$appVersion.'-'.(string)(@filemtime($stylePath)?:0))) ?>"><?php endforeach; ?></head>
<body class="login-body"><main class="login-shell"><section class="login-card">
    <div class="login-brand">
        <i class="brand-icon bi bi-boxes" aria-hidden="true"></i>
        <span class="brand-copy">
            <strong><?= h($appName) ?></strong>
            <small>Network Management</small>
        </span>
    </div>
    <p class="eyebrow">Login</p><h1>Masuk ke <?= h($appName) ?></h1>
    <?php if (!empty($_SESSION['registration_completed'])): unset($_SESSION['registration_completed']); ?>
        <div class="alert success" role="status">Pendaftaran berhasil. Silakan masuk menggunakan akun baru Anda.</div>
    <?php endif; ?>
    <form method="post" class="login-form required-fields">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="login">
        <div class="field"><label for="loginUsername">Username</label><input id="loginUsername" name="username" value="<?= h($oldLoginUsername) ?>" autocomplete="username" required autofocus placeholder="Username"></div>
        <div class="field"><label for="loginPassword">Password</label><input id="loginPassword" type="password" name="password" autocomplete="current-password" required placeholder="Password"></div>
        <button class="button primary login-button">Masuk</button>
    </form>
    <?php if (!empty($publicRegistrationEnabled)): ?>
        <div class="default-login"><p>
            Belum memiliki akun?
            <a class="text-primary-bold" href="<?= h(public_page_url('register')) ?>">Daftar akun baru</a>
        </p></div>
    <?php endif; ?>
</section></main>
<?php render_flash_toast($flashes ?? []); ?>
<?php $noticeScriptPath = LINNMS_UI_ROOT . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'toast.js'; ?>
<script src="<?= h(asset_url('assets/js/toast.js', $appVersion . '-' . (string)(@filemtime($noticeScriptPath) ?: 0))) ?>"></script>
<?php $appScriptPath = LINNMS_UI_ROOT . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'app.js'; ?>
<script src="<?= h(asset_url('assets/js/app.js', $appVersion . '-' . (string)(@filemtime($appScriptPath) ?: 0))) ?>"></script>
</body>
</html>
