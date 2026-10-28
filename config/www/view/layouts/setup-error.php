<?php

declare(strict_types=1);

if (!class_exists('LinnmsDefaults')) require_once project_path('config', 'config_default.php');

?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Aplikasi belum tersedia</title>
    <link rel="icon" type="image/x-icon" href="<?= h(asset_url('assets/icon.ico', (string)(@filemtime(LINNMS_UI_ROOT . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'icon.ico') ?: 0))) ?>">
    <link rel="stylesheet" href="<?= h(asset_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css', '1.13.1')) ?>">
    <?php foreach (['assets/css/app.css','assets/pages/login/login.css','assets/css/responsif.css'] as $styleAsset):
        $stylePath = LINNMS_UI_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $styleAsset);
    ?>
        <link rel="stylesheet" href="<?= h(asset_url($styleAsset, ($appVersion ?? 'setup') . '-' . (string)(@filemtime($stylePath) ?: 0))) ?>">
    <?php endforeach; ?>
</head>
<body class="login-body">
<main class="login-shell">
    <section class="login-card">
        <div class="login-brand">
            <i class="brand-icon bi bi-boxes" aria-hidden="true"></i>
            <span class="brand-copy">
                <strong><?= h(LinnmsDefaults::APP_IDENTITY['name']) ?></strong>
                <small>Network Management</small>
            </span>
        </div>

        <h1>Aplikasi belum tersedia</h1>
        <p class="lead">Silakan coba kembali.</p>

    </section>
</main>
</body>
</html>
