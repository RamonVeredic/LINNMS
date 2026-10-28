<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="icon" type="image/x-icon" href="<?= h(asset_url('assets/icon.ico', 'a')) ?>">
    <link rel="stylesheet" href="<?= h(asset_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css', '1.13.1')) ?>"><?php foreach (array_merge(['assets/css/app.css'], page_asset_manifest('access')['styles'], ['assets/css/responsif.css', 'assets/css/toast.css']) as $a): ?>
        <link rel="stylesheet" href="<?= h(asset_url($a, 'v-' . (string)(@filemtime(LINNMS_UI_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $a)) ?: 0))) ?>"><?php endforeach; ?><title>Aktivasi · <?= h($appName) ?></title>
</head>

<body class="login-body">
    <main class="login-shell">
        <section class="login-card">
            <div class="login-brand"><i class="brand-icon bi bi-boxes"></i><span class="brand-copy"><strong><?= h($appName) ?></strong><small>Network Management</small></span></div>
            <p class="eyebrow">Verifikasi Akun</p>
            <h1>Aktivasi Aplikasi</h1>
            <p class="lead">Masukkan username dan key</p>
            <form method="post" action="<?= h(public_app_url('activate')) ?>" class="login-form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="access_submit"><div class="field"><label for="accessUsername">Username</label><input id="accessUsername" name="account" autocomplete="username" required autofocus placeholder="username" value="<?= h($presetUser ?? '') ?>"></div><div class="field"><label for="accessToken">Key</label><input id="accessToken" name="token" autocomplete="off" required placeholder="LINNMS1-XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX"></div><button class="button primary login-button" type="submit">Submit</button></form>
        </section>
    </main><?php render_flash_toast($flashes ?? []); ?><script src="<?= h(asset_url('assets/js/toast.js', 'a')) ?>"></script>
    <script src="<?= h(asset_url('assets/js/app.js', 'a')) ?>"></script>
</body>

</html>
