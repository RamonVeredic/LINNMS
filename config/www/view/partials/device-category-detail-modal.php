<div id="dashboardDetailModal" class="modal dashboard-detail-modal" hidden aria-hidden="true">
    <div class="dashboard-detail-modal-shell" role="dialog" aria-modal="true" aria-labelledby="dashboardDetailModalTitle">
        <div class="modal-head dashboard-detail-head">
            <div class="dashboard-detail-title">
                <span class="dashboard-detail-icon" data-detail-icon aria-hidden="true"><i class="bi bi-exclamation-triangle"></i></span>
                <div class="dashboard-detail-title-text">
                    <h2 id="dashboardDetailModalTitle" data-detail-title>Detail Kategori</h2>
                    <p class="dashboard-detail-subtitle" data-detail-subtitle></p>
                </div>
            </div>
            <button class="icon-button" type="button" data-detail-close aria-label="Tutup modal"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="modal-card dashboard-detail-modal-card">
            <div class="modal-body dashboard-detail-body">
                <div class="dashboard-detail-toolbar">
                    <div class="table-search dashboard-detail-search">
                        <span><i class="bi bi-search" aria-hidden="true"></i></span>
                        <input type="search" data-detail-search placeholder="Cari ..." aria-label="Cari dalam daftar">
                    </div>
                    <span class="dashboard-detail-filter-shell" data-detail-device-shell hidden>
                        <select
                            data-detail-device
                            data-select-icon="bi bi-funnel"
                            data-select-bullet="cyan"
                            data-select-search="Cari ..."
                            data-select-label="Semua Perangkat"
                            aria-label="Filter perangkat"
                        ><option value="">Semua Perangkat</option></select>
                    </span>
                </div>
                <div class="dashboard-detail-list" data-detail-list></div>
                <div class="dashboard-detail-foot" data-detail-foot hidden>
                    <nav class="table-pagination pagination-numbered dashboard-detail-pagination" data-detail-pagination aria-label="Halaman daftar kategori"></nav>
                </div>
            </div>
        </div>
    </div>
</div>
