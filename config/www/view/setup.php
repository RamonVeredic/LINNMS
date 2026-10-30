<?php
$presetUser = trim((string)($presetUser ?? ''));
$presetDisplayName = trim((string)($presetDisplayName ?? ''));
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="icon" type="image/x-icon" href="<?= h(asset_url('assets/icon.ico', 'p')) ?>">
    <link rel="stylesheet" href="<?= h(asset_url('assets/icon/bootstrap-icons/bootstrap-icons.min.css', '1.13.1')) ?>"><?php foreach (array_merge(['assets/css/linnms-main.min.css'], page_asset_manifest('setup')['styles'], ['assets/css/responsif.css', 'assets/css/linnms-termplet.css']) as $a): ?>
        <link rel="stylesheet" href="<?= h(asset_url($a, 'v-' . (string)(@filemtime(LINNMS_UI_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $a)) ?: 0))) ?>"><?php endforeach; ?><title>Setup Akun · <?= h($appName) ?></title>
</head>

<body class="login-body">
    <main class="login-shell">
        <section class="login-card">
            <div class="login-brand"><i class="brand-icon bi bi-boxes"></i><span class="brand-copy"><strong><?= h($appName) ?></strong><small>Network Management</small></span></div>
            <div class="eyebrow">Setup Akun</div>
            <h1>Lengkapi Akun Anda</h1>
            <form method="post" action="<?= h(public_app_url('setup')) ?>" class="login-form">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="setup_submit">
                <div class="field"><label for="setupUsername">Username</label><input id="setupUsername" class="text-muted" value="<?= h($presetUser) ?>" readonly autocomplete="username"></div>
                <div class="field"><label for="setupDisplayName">Nama pengguna</label><input id="setupDisplayName" name="display_name" required autofocus autocomplete="name" placeholder="Nama pengguna" value="<?= h($presetDisplayName) ?>"></div>
                <div class="field"><label for="setupPassword">Password baru</label><input id="setupPassword" type="password" name="password" minlength="8" required autocomplete="new-password" placeholder="Password baru"></div><div class="field"><label for="setupPasswordConfirm">Ulangi password</label><input id="setupPasswordConfirm" type="password" name="password_confirm" minlength="8" required autocomplete="new-password" placeholder="Ulangi password"></div><button class="button primary login-button" type="submit">Simpan</button></form>
        </section>
    </main><?php render_flash_toast($flashes ?? []); ?><script src="<?= h(asset_url('assets/js/toast.js', 'p')) ?>"></script>
    <script src="<?= h(asset_url('assets/js/app.js', 'p')) ?>"></script>
</body>

</html>
