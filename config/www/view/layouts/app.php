 <?php
    $pageTitle = $pageTitle ?? 'Dashboard';
    $page = $page ?? 'dashboard';
    $pageLead = $pageLead ?? '';
    $pageView = $pageView ?? '';
    $flashes = $flashes ?? [];
    $serviceAccessState = is_array($serviceAccessState ?? null) ? $serviceAccessState : [];
    $serviceExpiresAt = $serviceExpiresAt ?? false;
    $serviceExpiryTimestamp = (($serviceAccessState['mode'] ?? '') === 'ready' && $serviceExpiresAt !== false)
        ? (int)$serviceExpiresAt
        : 0;
    $expiredPageUrl = public_app_url('expired.html');
    $serviceWhatsapp = normalize_phone_number($layoutServiceContact['whatsapp'] ?? '');
    if ($serviceWhatsapp !== '') $expiredPageUrl .= '?wa=' . rawurlencode($serviceWhatsapp);
    ?>

<!doctype html>
<html lang="id">

<head>
    <script>document.documentElement.classList.add('js');</script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">

    <title><?= h($pageTitle) ?> &middot; <?= h($appName) ?></title>
    <link
        rel="icon"
        type="image/x-icon"
        href="<?= h(asset_url('assets/icon.ico', (string)(@filemtime(LINNMS_UI_ROOT . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'icon.ico') ?: 0))) ?>">
    <link
        rel="stylesheet"
        href="<?= h(asset_url('assets/icon/bootstrap-icons/bootstrap-icons.min.css', '1.13.1')) ?>">
    <?php
    $pageAssets = page_asset_manifest($page);
    $oltTerminalEnabled = $page === 'view-olt' && (int)($oltDetail['hybrid_status'] ?? 0) === 1;
    $styleAssets = ['assets/css/linnms-main.min.css'];
    if ($oltTerminalEnabled) {
        $styleAssets[] = 'assets/css/page/view-olt/xterm.css';
    }
    foreach ($pageAssets['styles'] as $pageStyle) $styleAssets[] = $pageStyle;
    $styleAssets[] = 'assets/css/responsif.css';
    $styleAssets[] = 'assets/css/linnms-termplet.css';
    foreach (array_values(array_unique($styleAssets)) as $styleAsset):
        $stylePath = LINNMS_UI_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $styleAsset);

            if (!is_file($stylePath)) continue;
            $styleVersion = $appVersion . '-' . (string)(@filemtime($stylePath) ?: 0);
    ?>
        <link rel="stylesheet" href="<?= h(asset_url($styleAsset, $styleVersion)) ?>">
    <?php endforeach; ?>
</head>

<body data-session-remaining="<?= linnms_session_idle_remaining() ?>" data-session-login="<?= h(public_page_url('login')) ?>" data-live-url="<?= h(public_app_url('live.php')) ?>" data-service-expires-at="<?= $serviceExpiryTimestamp ?>" data-expired-page="<?= h($expiredPageUrl) ?>">
    <div class="app-shell">
        <aside class="sidebar" id="appSidebar" aria-label="Navigasi utama">
            <div class="brand">
                <i class="brand-icon bi bi-boxes" aria-hidden="true"></i>
                <span class="brand-copy">
                    <strong><?= h($appName) ?></strong>
                    <small>Network Management</small>
                </span>
            </div>
            <div class="sidebar-scroll">
                <div class="nav-label">DASHBOARD</div>
                <nav>
                    <a class="<?= in_array(     $page,     ['dashboard', 'view-olt', 'add-device', 'view-mikrotik', 'view-mikrotik-interface', 'view-switch'],     true ) ? 'active' : '' ?>" href="<?= h(public_page_url('dashboard')) ?>">
                        <i class="bi bi-speedometer2" aria-hidden="true"></i> Dashboard</a>
                </nav>

                <div class="nav-label">MANAGEMENT</div>
                <nav>
                    <a class="<?= nav_active($page, 'add-onu') ?>" href="<?= h(public_page_url('add-onu')) ?>">
                        <i class="bi bi-plus-circle" aria-hidden="true"></i><span class="sidebar-nav-label">Add ONU<?php if ($addOnuSidebarTotal > 0): ?><span class="sidebar-count"><?= $addOnuSidebarTotal ?></span><?php endif; ?></span></a>
                    <a class="<?= in_array($page, ['onus', 'view-onu'], true) ? 'active' : '' ?>" href="<?= h(public_page_url('onus')) ?>">
                        <i class="bi bi-router" aria-hidden="true"></i>All ONU</a>
                </nav>
                <div class="nav-label">NOTIFIKASI</div>
                <?php
                $telegramSidebarAccessAllowed = !empty($telegramFeatureAllowed);
                $whatsappSidebarAccessAllowed = !empty($whatsappFeatureAllowed);
                $telegramSidebarActive = $telegramSidebarAccessAllowed && (int)($telegramServiceSettings['enabled'] ?? 0) === 1;
                $whatsappSidebarActive = $whatsappSidebarAccessAllowed && (int)($whatsappServiceSettings['enabled'] ?? 0) === 1;
                ?>
                <nav>
                    <a class="<?= nav_active($page, 'notification') ?>" href="<?= h(public_page_url('notification')) ?>">
                        <i class="bi bi-bell" aria-hidden="true"></i>Notifikasi</a>
                    <?php if ($telegramSidebarActive): ?>
                        <a class="<?= nav_active($page, 'telegram') ?>" href="<?= h(public_page_url('telegram')) ?>">
                            <i class="bi bi-telegram" aria-hidden="true"></i>Telegram</a>
                    <?php elseif ($telegramSidebarAccessAllowed): ?>
                        <span class="nav-disabled" aria-disabled="true" title="Belum tersedia">
                            <i class="bi bi-telegram" aria-hidden="true"></i>Telegram
                        </span>
                    <?php else: ?>
                        <span class="nav-disabled nav-access-disabled" aria-disabled="true" title="Tidak diizinkan">
                            <i class="bi bi-telegram" aria-hidden="true"></i>Telegram
                            <span class="nav-lock-status"><i class="bi bi-lock-fill success" aria-hidden="true"></i></span>
                        </span>
                    <?php endif; ?>
                    <?php if ($whatsappSidebarActive): ?>
                        <a class="<?= nav_active($page, 'whatsapp') ?>" href="<?= h(public_page_url('whatsapp')) ?>">
                            <i class="bi bi-whatsapp" aria-hidden="true"></i>WhatsApp</a>
                    <?php elseif ($whatsappSidebarAccessAllowed): ?>
                        <span class="nav-disabled" aria-disabled="true" title="Belum tersedia">
                            <i class="bi bi-whatsapp" aria-hidden="true"></i>WhatsApp
                        </span>
                    <?php else: ?>
                        <span class="nav-disabled nav-access-disabled" aria-disabled="true" title="Tidak diizinkan">
                            <i class="bi bi-whatsapp" aria-hidden="true"></i>WhatsApp
                            <span class="nav-lock-status"><i class="bi bi-lock-fill success" aria-hidden="true"></i></span>
                        </span>
                    <?php endif; ?>
                </nav>

                <div class="nav-label">AKUN</div>
                <nav>
                    <a class="<?= in_array($page, ['settings', 'settings-admin'], true) ? 'active' : '' ?>" href="<?= h(public_page_url(is_admin() ? 'settings-admin' : 'settings')) ?>">
                        <i class="bi bi-sliders2" aria-hidden="true"></i> Pengaturan</a>
                    <a class="<?= nav_active($page, 'applogs') ?>" href="<?= h(public_page_url('applogs')) ?>">
                        <i class="bi bi-list-columns-reverse" aria-hidden="true"></i>App Log</a>
                </nav>
            </div>
        </aside>
        <button class="sidebar-backdrop" type="button" aria-label="Tutup menu navigasi" hidden></button>
        <div class="container">
            <header class="topbar">
                <div class="topbar-left">
                    <button class="mobile-menu-button" type="button" data-sidebar-open aria-controls="appSidebar" aria-expanded="false" aria-label="Buka menu navigasi">
                        <i class="bi bi-list" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="topbar-right">
                    <span class="status mono account-idle-badge" aria-label="Status: idle">
                        <span class="status-dot success is-pulsing" aria-hidden="true"></span>
                        idle
                    </span>
                    <div class="account-menu" data-account-menu>
                        <button class="topbar-profile-link" type="button" data-account-menu-toggle aria-haspopup="menu" aria-expanded="false" aria-controls="accountDropdown" aria-label="Menu akun">
                            <span class="user-chip"><i class="bi bi-person-circle" aria-hidden="true"></i></span>
                        </button>
                        <div class="account-dropdown" id="accountDropdown" data-account-menu-dropdown role="menu" hidden>
                            <div class="account-dropdown-identity">
                                <strong><?= h(current_display_name()) ?></strong>
                                <small>@<?= h(current_username()) ?></small>
                            </div>
                            <a class="account-dropdown-item" href="<?= h(public_settings_url()) ?>" role="menuitem">
                                <i class="bi bi-sliders2" aria-hidden="true"></i>
                                <span>Pengaturan</span>
                            </a>
                            <form method="post" class="account-dropdown-form" role="none">
                                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action" value="logout">
                                <button class="account-dropdown-item account-dropdown-logout" type="submit" role="menuitem">
                                    <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                                    <span>Keluar</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>
            <div class="content">
                <div class="page-head">
                    <div>
                        <p class="breadcrumb"><?= h($appName . ' / ' . $pageTitle) ?></p>
                        <h1><?= h($pageTitle) ?></h1>
                        <p><?= h($pageLead) ?></p>
                    </div>
                    <div class="page-actions"></div>
                </div>
                <div class="rule"></div>
                <?php if (($serviceExpiryNotice ?? false) === true): ?>
                    <aside
                        class="service-expiry-notice"
                        role="status"
                        aria-label="Peringatan masa aktif layanan"
                        data-service-expiry-notice="<?= h(date('Y-m-d', $serviceExpiresAt)) ?>"
                    >
                        <span class="service-expiry-notice-icon" aria-hidden="true">
                            <i class="bi bi-calendar-event"></i>
                        </span>
                        <div>
                            <strong>Tinggal <?= (int)$serviceExpiryDaysRemaining ?> hari lagi</strong>
                            <span>
                                Masa aktif layanan akan jatuh tempo
                                <b><?= h((string)$serviceExpiryDateLabel) ?></b>.
                                Perpanjang sekarang sebelum layanan terputus.
                            </span>
                            <a class="service-expiry-pay" href="https://wa.me/<?= h($serviceWhatsapp) ?>" target="_blank" rel="noopener noreferrer">Perpanjang</a>
                        </div>
                        <button
                            class="service-expiry-notice-close"
                            type="button"
                            data-service-expiry-dismiss
                            aria-label="Tutup informasi"
                        >
                            <span class="service-expiry-close-glyph" aria-hidden="true">&times;</span>
                        </button>
                    </aside>
                <?php endif; ?>
                <?php require $pageView; ?>
            </div>
            <?php render_flash_toast($flashes); ?>
            <footer class="app-footer" aria-label="APP Footer">
                <a class="app-footer-link" href="<?= h(public_page_url('dashboard')) ?>">
                    <span class="brandText">
                        <?= h($appName) ?><?php if ($appVersion !== ''): ?> V.<?= h($appVersion) ?><?php endif; ?>
                    </span>
                </a>
            </footer>
        </div>
    </div>
    <?php
    $scriptAssets = ['assets/js/toast.js', 'assets/js/app.js', 'assets/js/ajax/client.js'];
    if (in_array($page, ['notification', 'settings-admin'], true)) $scriptAssets[] = 'assets/js/instant-toggle.js';
    if ($serviceExpiryTimestamp > 0) $scriptAssets[] = 'assets/js/service-expiry.js';
    if (in_array($page, ['view-olt', 'view-mikrotik', 'view-mikrotik-interface', 'view-switch'], true)) {
        $scriptAssets[] = 'assets/components/traffic-record/traffic-record.js';
    }
    if (($page === 'view-onu' && !empty($onuViewCapabilities['onu_traffic']))
        || in_array($page, ['view-olt', 'view-mikrotik', 'view-switch'], true)) {
        $scriptAssets[] = 'assets/js/chart.umd.js';
    }
    $pageScriptCandidates = $pageAssets['scripts'];
    if ($oltTerminalEnabled) {
        $pageScriptCandidates = array_merge(['assets/js/page/view-olt/xterm.js', 'assets/js/page/view-olt/addon-fit.js'], $pageScriptCandidates);
    }
    foreach (array_values(array_unique($pageScriptCandidates)) as $pageScript) {
        $pageScriptPath = LINNMS_UI_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pageScript);
        if (is_file($pageScriptPath)) {
            $scriptAssets[] = $pageScript;
        }
    }
    $scriptAssets = array_values(array_unique($scriptAssets));
    ?>
    <?php foreach ($scriptAssets as $scriptAsset): ?>
        <?php
        $scriptPath = LINNMS_UI_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $scriptAsset);
        $scriptVersion = $appVersion . '-' . (string)(@filemtime($scriptPath) ?: 0);
        ?>
        <script src="<?= h(asset_url($scriptAsset, $scriptVersion)) ?>"></script>
    <?php endforeach; ?>
</body>
</html>
