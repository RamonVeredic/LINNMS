<section class="table-panel recent-activity-panel channel-history-panel">
    <div class="channel-history-head">
        <h2>Riwayat Notifikasi</h2>
    </div>
    <div class="list-scroll-viewport" role="region" aria-label="Riwayat notifikasi" tabindex="0">
        <div class="public-log-list">
        <?php if ($channelHistoryEvents === []): ?>
            <div class="public-log-empty"><div class="empty-state"><i class="bi bi-clock-history" aria-hidden="true"></i><span>Belum ada riwayat notifikasi</span></div></div>
        <?php else: foreach ($channelHistoryEvents as $event): ?>
            <?php
                $notificationTime = (string)(($event['send_at'] ?? '') ?: ($event['created_at'] ?? ''));
                $message = trim((string)($event['message'] ?? ''));
                $deviceName = trim((string)($event['device_name'] ?? ''));
                $detail = $message;
                if ($deviceName !== '' && $detail !== '') {
                    $firstLine = trim(strtok($detail, "\n"));
                    if ($firstLine !== '' && str_contains($deviceName, $firstLine)) {
                        $detail = trim(substr($detail, strlen($firstLine)));
                    }
                }
                if ($message === '') $detail = 'Isi pesan lama tidak tersedia.';
                $notificationStatus = strtolower(trim((string)($event['status'] ?? '')));
                $statusMeta = [
                    'sent' => ['label' => 'Terkirim', 'class' => 'text-success'],
                    'pending' => ['label' => 'Pending', 'class' => 'text-warning'],
                    'failed' => ['label' => 'Gagal', 'class' => 'text-danger'],
                ][$notificationStatus] ?? null;
            ?>
            <div class="public-log-entry" data-log-row>
                <div class="public-log-message" data-log-expandable>
                    <div class="public-log-text" data-log-expand-text><?php if ($deviceName !== ''): ?><span class="text-cyan"><?= h($deviceName) ?></span> <?php endif; ?><?= h($detail) ?><?php if ($statusMeta !== null): ?> <span class="public-log-status <?= h($statusMeta['class']) ?>"><?= h($statusMeta['label']) ?></span><?php endif; ?></div>
                    <button class="public-log-toggle" type="button" data-log-expand-toggle aria-label="Tampilkan pesan selengkapnya" title="Tampilkan selengkapnya" aria-expanded="false" hidden><i class="bi bi-chevron-down" aria-hidden="true"></i></button>
                </div>
                <time class="public-log-time" datetime="<?= h($notificationTime) ?>"><?= h(format_feed_time($notificationTime)) ?></time>
            </div>
        <?php endforeach; endif; ?>
        </div>
    </div>
    <?php render_numbered_pagination([
        'page' => $channelHistoryPage,
        'pages' => $channelHistoryPages,
        'url' => $channelHistoryUrl,
        'label' => 'Halaman riwayat',
        'class' => 'recent-activity-pagination',
        'total' => $channelHistoryTotal,
    ]); ?>
</section>
