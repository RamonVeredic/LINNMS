<?php
require_once __DIR__ . '/partials/onu-list/view.php';
require_once LINNMS_UI_ROOT . '/controllers/olt/onu-list.php';
$onuListFilters = onu_list_filters_from_request();
$onuList = onu_list_read_data((int)current_user_id(), $onuListFilters, max(1, (int)($_GET['p'] ?? 1)), true);
$onuSummary = is_array($onuList['summary'] ?? null) ? $onuList['summary'] : [];
$hasZte = !empty($onuList['hasZte']);
$totalOnus = (int)($onuSummary['total'] ?? 0);
$online = (int)($onuSummary['online'] ?? 0);
$offline = (int)($onuSummary['down'] ?? 0);
$powerDown = (int)($onuSummary['power_down'] ?? 0);
$loss = (int)($onuSummary['loss'] ?? 0);
$problem = (int)($onuSummary['problem'] ?? 0);
$olts = is_array($onuList['olts'] ?? null) ? $onuList['olts'] : [];
$onus = $onuList['rows'];
$onuTablePages = $onuList['pages'];
$onuPonOptions = array_values(array_filter(is_array($onuList['pon_options'] ?? null) ? $onuList['pon_options'] : [],'is_array'
));
?>
<div class="stats summary-stats onu-summary-stats">
    <div class="stat-card accent-teal">
        <span class="text-cyan">Total ONU</span>
        <strong data-onu-stat="total"><?= $totalOnus ?></strong>
    </div>
    <button type="button" class="stat-card accent-green" data-dashboard-detail="online_onus" data-detail-title="ONU Online" data-detail-icon="bi-wifi" data-detail-accent="green" aria-haspopup="dialog">
        <span class="success">Online</span>
        <strong data-onu-stat="online"><?= $online ?></strong>
    </button>
    <button type="button" class="stat-card accent-down" data-dashboard-detail="down_onus" data-detail-title="ONU Offline" data-detail-icon="bi-arrow-down-right" data-detail-accent="down" aria-haspopup="dialog">
        <span class="text-yellow">Offline</span>
        <strong data-onu-stat="down"><?= $offline ?></strong>
    </button>
    <?php if ($hasZte): ?>
    <button type="button" class="stat-card accent-amber" data-dashboard-detail="power_down_onus" data-detail-title="DyingGasp" data-detail-icon="bi-plug" data-detail-accent="amber" aria-haspopup="dialog">
        <span class="warning">DyingGasp</span>
        <strong data-onu-stat="power_down"><?= $powerDown ?></strong>
    </button>
    <?php endif; ?>
    <button type="button" class="stat-card accent-lost" data-dashboard-detail="loss_onus" data-detail-title="Lost" data-detail-icon="bi-broadcast" data-detail-accent="lost" aria-haspopup="dialog">
        <span class="text-orange">Lost</span>
        <strong data-onu-stat="loss"><?= $loss ?></strong>
    </button>
    <button type="button" class="stat-card accent-red" data-dashboard-detail="problem_onus" data-detail-title="Bermasalah" data-detail-icon="bi-exclamation-triangle" data-detail-accent="red" aria-haspopup="dialog">
        <span class="danger">Bermasalah</span>
        <strong data-onu-stat="problem"><?= $problem ?></strong>
    </button>
</div>

<section class="table-panel onu-list-panel">
    <div class="table-toolbar table-filter-toolbar onu-list-toolbar">
        <div class="left">
            <div class="table-search">
                <span><i class="bi bi-search" aria-hidden="true"></i></span>
                <input data-table-search="onusTable" type="search" aria-label="Cari ONU" placeholder="Cari ...">
            </div>
        </div>
        <div class="right">
            <select data-table-filter="onusTable" data-column="0" data-row-attribute="filterOlt" data-select-icon="bi bi-pc-horizontal" data-select-bullet="cyan" data-select-search="Cari..." data-onu-olt-filter aria-label="Filter OLT">
                <option value="" data-select-heading>Semua</option>
                <?php foreach ($olts as $olt): ?>
                    <?php
                    $oltFilterName = display_text($olt['name'] ?? null);
                    $oltFilterIp = trim((string)($olt['ip_address'] ?? ''));
                    ?>
                    <option
                        value="<?= (int)($olt['id'] ?? 0) ?>"
                        <?= $oltFilterIp !== '' ? 'data-meta="' . h($oltFilterIp) . '"' : '' ?>
                        data-search="<?= h(strtolower($oltFilterName . ' ' . $oltFilterIp)) ?>"
                    ><?= h($oltFilterName) ?></option>
                <?php endforeach; ?>
            </select>
            <select
                data-table-filter="onusTable"
                data-column="1"
                data-row-attribute="filterPon"
                data-select-icon="bi bi-broadcast-pin"
                data-select-bullet="cyan"
                data-select-search="Cari ..."
                data-onu-pon-filter
                aria-label="Filter PON"
            >
                <option value="" data-select-heading>Semua PON</option>
                <?php foreach ($onuPonOptions as $onuPonOption): ?>
                    <?php
                    $onuPonPath = trim((string)($onuPonOption['path'] ?? ''));
                    $onuPonKey = trim((string)($onuPonOption['key'] ?? ''));
                    $onuPonOltName = trim((string)($onuPonOption['olt_name'] ?? ''));
                    $onuPonDescription = trim((string)($onuPonOption['description'] ?? ''));
                    $onuPonLabel = implode(' - ', array_filter([$onuPonPath, $onuPonDescription], 'strlen'));
                    $onuPonDeviceId = (int)(explode('|', $onuPonKey, 2)[0] ?? 0);
                    ?>
                    <option
                        value="<?= h($onuPonKey) ?>"
                        data-olt-device-id="<?= $onuPonDeviceId ?>"
                        <?= $onuPonOltName !== '' ? 'data-meta="' . h($onuPonOltName) . '"' : '' ?>
                        data-search="<?= h(strtolower($onuPonPath . ' ' . $onuPonDescription . ' ' . $onuPonOltName)) ?>"
                    ><?= h($onuPonLabel) ?></option>
                <?php endforeach; ?>
            </select>
            <select
                data-table-filter="onusTable"
                data-column="4"
                data-row-attribute="filterStatus"
                data-select-icon="bi bi-activity"
                data-select-search="Cari ..."
                data-match="token"
                aria-label="Filter status ONU"
            >
                <option value="" data-select-heading>Semua</option>
                <option data-icon="bi bi-check-circle" data-api-value="online">Online</option>
                <option data-icon="bi bi-x-circle" data-api-value="down">Offline</option>
                <?php if ($hasZte): ?><option data-icon="bi bi-plug" data-api-value="powerdown">DyingGasp</option><?php endif; ?>
                <option data-icon="bi bi-exclamation-triangle" data-api-value="loss">Lost</option>
                <option data-icon="bi bi-exclamation-octagon" data-api-value="problem">Bermasalah</option>
            </select>
        </div>
    </div>

    <div class="onu-table-head" data-onu-table-head aria-hidden="true">
        <table id="onusTableHeader">
            <colgroup>
                <col class="onu-col-olt">
                <col class="onu-col-index">
                <col class="onu-col-name">
                <col class="onu-col-location">
                <col class="onu-col-serial">
                <col class="onu-col-rx">
            </colgroup>
            <thead>
            <tr>
                <th>OLT</th>
                <th>Indeks</th>
                <th>Nama ONU</th>
                <th>Lokasi</th>
                <th>Serial ONU</th>
                <th>RX Power</th>
            </tr>
            </thead>
        </table>
    </div>
    <div class="table-wrap list-scroll-viewport" role="region" aria-label="Daftar ONU" tabindex="0">
        <table data-onu-server-search id="onusTable" data-table-page-size="50" data-table-item-label="ONU">
            <colgroup>
                <col class="onu-col-olt">
                <col class="onu-col-index">
                <col class="onu-col-name">
                <col class="onu-col-location">
                <col class="onu-col-serial">
                <col class="onu-col-rx">
            </colgroup>
            <thead class="onu-table-accessible-head">
                <tr><th scope="col">OLT</th><th scope="col">Indeks</th><th scope="col">Nama ONU</th><th scope="col">Lokasi</th><th scope="col">Serial ONU</th><th scope="col">RX Power</th></tr>
            </thead>
            <tbody data-onu-table-body aria-live="polite">
                <?php require __DIR__ . '/partials/onu-list/table-rows.php'; ?>
            </tbody>
        </table>
    </div>

    <div class="table-footer onu-table-footer" data-table-footer="onusTable">
        <?php render_numbered_pagination([
            'page' => $onuList['page'],
            'pages' => $onuList['pages'],
            'total' => $onuList['total'],
            'url' => static fn(int $p): string => onu_list_filter_url($onuListFilters, $p),
            'goto' => 'data-onu-goto',
            'label' => 'Halaman ONU',
            'class' => 'onu-pagination',
        ]); ?>
    </div>
</section>
<?php require project_path('view', 'partials', 'device-category-detail-modal.php'); ?>
