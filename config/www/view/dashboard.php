<div class="stats summary-stats dashboard-summary-stats">
    <!--KONSISTEN BUTTON-->
    <button class="stat-card accent-blue">
        <span class="text-primary">Total Perangkat</span>
        <strong data-dashboard-stat="total_devices"><?= $dashboardRead['totalDevices'] ?></strong>
    </button>
    <button class="stat-card accent-teal">
        <span class="text-cyan">Total ONU</span>
        <strong data-dashboard-stat="total_onus"><?= (int)($stats['total_onus'] ?? 0) ?></strong>
    </button>
    <button type="button" class="stat-card accent-green" data-dashboard-detail="online_onus" data-detail-title="Online" data-detail-icon="bi-wifi" data-detail-accent="green" aria-haspopup="dialog">
        <span class="success">Online</span>
        <strong data-dashboard-stat="online_onus"><?= (int)($stats['online_onus'] ?? 0) ?></strong>
    </button>
    <button type="button" class="stat-card accent-down" data-dashboard-detail="down_onus" data-detail-title="Offline" data-detail-icon="bi-arrow-down-right" data-detail-accent="down" aria-haspopup="dialog">
        <span class="text-yellow">Offline</span>
        <strong data-dashboard-stat="down_onus">
            <?= (int)($stats['down_onus'] ?? 0) ?>
        </strong>
    </button>
    <?php if ($dashboardRead['hasZte']): ?>
    <button type="button" class="stat-card accent-amber" data-dashboard-detail="power_down_onus" data-detail-title="DyingGasp" data-detail-icon="bi-plug" data-detail-accent="amber" aria-haspopup="dialog">
        <span class="warning">DyingGasp</span>
        <strong data-dashboard-stat="power_down_onus"><?= (int)($stats['power_down_onus'] ?? 0) ?></strong>
    </button>
    <?php endif; ?>
    <button type="button" class="stat-card accent-lost" data-dashboard-detail="loss_onus" data-detail-title="Lost" data-detail-icon="bi-broadcast" data-detail-accent="lost" aria-haspopup="dialog">
        <span class="text-orange">Lost</span>
        <strong data-dashboard-stat="loss_onus"><?= (int)($stats['loss_onus'] ?? 0) ?></strong>
    </button>
    <button type="button" class="stat-card accent-red" data-dashboard-detail="problem_onus" data-detail-title="Bermasalah" data-detail-icon="bi-exclamation-triangle" data-detail-accent="red" aria-haspopup="dialog">
        <span class="danger">Bermasalah</span>
        <strong data-dashboard-stat="problem_onus"><?= (int)($stats['problem_onus'] ?? 0) ?></strong>
    </button>
</div>
<main>
  <section class="table-panel dashboard-olt-panel" id="dashboard-devices">
    <div class="table-toolbar">
        <div class="table-search">
            <span><i class="bi bi-search" aria-hidden="true"></i></span>
            <input data-table-search="devicesTable" type="search" aria-label="Cari perangkat" placeholder="Cari...">
        </div>
        <button class="button primary dashboard-add-device-button" type="button" data-device-add-open aria-haspopup="dialog" aria-controls="deviceAddModal"><i class="bi bi-plus-circle" aria-hidden="true"></i>Add</button>
    </div>
    <?php require project_path('view', 'partials', 'dashboard', 'device-table.php'); ?>
</section>
<section class="table-panel recent-activity-panel">
    <div class="recent-activity-head">
        <h2>Aktivitas Terbaru</h2>
        <form class="table-toolbar table-filter-toolbar recent-activity-toolbar" method="get" data-recent-activity-filter-form>
            <div class="left">
                <div class="table-search">
                    <span><i class="bi bi-search" aria-hidden="true"></i></span>
                    <input type="search" name="activity_q" value="<?= h($recentActivitySearch) ?>" placeholder="Cari..." aria-label="Cari aktivitas terbaru" data-recent-activity-search>
                </div>
            </div>
            <div class="right">
                <select name="activity_device" aria-label="Filter perangkat aktivitas terbaru" data-recent-activity-filter data-select-icon="bi bi-funnel" data-select-bullet="cyan" data-select-search="Cari ...">
                    <option value="" data-select-heading>Semua</option>
                    <?php foreach ($dashboardRead['recentActivityDevices'] as $activityDevice): ?>
                        <option value="<?= h($activityDevice['name']) ?>" <?= $activityDevice['ip'] !== '' ? 'data-meta="' . h($activityDevice['ip']) . '"' : '' ?> <?= $recentActivityDevice === $activityDevice['name'] ? 'selected' : '' ?>><?= h($activityDevice['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" hidden>Filter</button>
        </form>
    </div>
    <div class="recent-activity-results list-scroll-viewport" data-recent-activity-results role="region" aria-label="Daftar aktivitas terbaru" tabindex="0">
        <div
            class="public-log-list"
            data-recent-activity-list
            data-activity-page="<?= (int)$recentActivityPage ?>"
            data-activity-pages="<?= (int)$recentActivityPages ?>"
            aria-live="polite"
            aria-atomic="false">
            <?php require project_path('view', 'partials', 'dashboard', 'recent-activity.php'); ?>
        </div>
    </div>
    <?php render_numbered_pagination([
        'page' => $recentActivityPage,
        'pages' => $recentActivityPages,
        'url' => $dashboardRead['recentActivityUrl'],
        'label' => 'Halaman aktivitas',
        'goto' => 'data-activity-goto',
        'class' => 'recent-activity-pagination',
        'total' => $recentActivityTotal,
    ]); ?>
</section>
  
</main>



<?php require project_path('view', 'partials', 'device-category-detail-modal.php'); ?>
<div id="deviceAddModal" class="modal device-add-modal" data-device-add-modal<?= $deviceAddModalOpen ? ' data-open-on-load="1"' : '' ?> hidden aria-hidden="true">
    <div class="modal-card device-add-modal-card" role="dialog" aria-modal="true" aria-labelledby="deviceAddModalTitle">
        <header class="device-add-modal-head modal-header">
            <h2 id="deviceAddModalTitle">Tambah Perangkat</h2>
            <button class="icon-button modal-close-button" type="button" data-device-add-close aria-label="Tutup modal tambah perangkat"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </header>
        <div class="device-add-modal-body" data-device-add-body>
            <p class="text-muted" role="status">Memuat formulir...</p>
        </div>
    </div>
</div>
<div id="deviceEditModal" class="modal device-add-modal device-edit-modal" data-device-edit-modal data-open-device-id="<?= (int)(valid_positive_id($_GET['edit_device'] ?? null) ?? 0) ?>" hidden aria-hidden="true">
    <div class="modal-card device-add-modal-card" role="dialog" aria-modal="true" aria-labelledby="deviceEditModalTitle">
        <header class="device-add-modal-head modal-header">
            <h2 id="deviceEditModalTitle" data-device-edit-title>Edit Perangkat</h2>
            <button class="icon-button modal-close-button" type="button" data-device-edit-close aria-label="Tutup modal edit perangkat"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </header>
        <div class="device-add-modal-body" data-device-edit-body>
            <p class="text-muted" role="status">Memuat perangkat...</p>
        </div>
    </div>
</div>
