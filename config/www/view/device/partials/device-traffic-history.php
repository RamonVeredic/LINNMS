<?php
$deviceTrafficHistoryRefs = array_keys($deviceTrafficSelected);
?>
<section class="pon-traffic device-traffic-history" data-traffic data-traffic-mode="device"
         data-traffic-device-id="<?= (int)$deviceDetail['id'] ?>">
    <div class="panel-heading">
        <div>
            <h2><i class="bi bi-graph-up-arrow" aria-hidden="true"></i> Riwayat Trafik</h2>
            <p>Data dari interface yang dicatat. Grafik menampilkan 12 titik terakhir saat halaman dibuka.</p>
        </div>
    </div>
        <div class="notice" data-device-traffic-empty<?= $deviceTrafficHistoryRefs !== [] ? ' hidden' : '' ?>><i class="bi bi-info-circle" aria-hidden="true"></i> Belum ada interface yang dicatat. Aktifkan kolom <strong>Catat</strong> pada interface untuk mulai menyimpan riwayat trafik.</div>
        <div class="pon-traffic-ranges" role="group" aria-label="Rentang trafik"<?= $deviceTrafficHistoryRefs === [] ? ' hidden' : '' ?>>
            <?php foreach ([180 => '3m', 1800 => '30m', 3600 => '1H', 86400 => '1d'] as $deviceTrafficRange => $deviceTrafficRangeLabel): ?>
                <button type="button" class="pon-traffic-range<?= $deviceTrafficRange === 180 ? ' is-active' : '' ?>" data-traffic-range="<?= (int)$deviceTrafficRange ?>"><?= h($deviceTrafficRangeLabel) ?></button>
            <?php endforeach; ?>
        </div>
        <div class="pon-traffic-grid" data-traffic-grid<?= $deviceTrafficHistoryRefs === [] ? ' hidden' : '' ?>>
            <?php foreach ($deviceTrafficHistoryRefs as $deviceTrafficHistoryRef): ?>
                <?php
                $trafficCardRef = (string)$deviceTrafficHistoryRef;
                $trafficCardTitle = (string)$deviceTrafficHistoryRef;
                $trafficCardIcon = 'bi-ethernet';
                $trafficCardRole = 'device';
                require project_path('pages', 'partials', 'traffic-card.php');
                ?>
            <?php endforeach; ?>
        </div>
        <p class="text-muted" data-traffic-state aria-live="polite" hidden></p>
</section>
