<section class="panel channel-history-panel">
    <div class="panel-heading">
        <div class="panel-title-stack">
            <h2>Riwayat Notifikasi</h2>
            <p class="page-info-top"><?= h(page_info_top($channelHistoryPage, $channelHistoryPages)) ?></p>
        </div>
    </div>
    <div class="channel-history-list">
        <?php if ($channelHistoryEvents === []): ?>
            <div class="channel-history-empty">Belum ada riwayat notifikasi</div>
        <?php else: foreach ($channelHistoryEvents as $event): ?>
            <?php $notificationTime = (string)(($event['send_at'] ?? '') ?: ($event['created_at'] ?? '')); ?>
            <article class="channel-history-item is-sent">
                <span class="status success">Sukses</span>
                <div class="public-log-message"><p class="public-log-text">Notifikasi</p></div>
                <time class="channel-history-time" datetime="<?= h($notificationTime) ?>"><?= h(format_feed_time($notificationTime)) ?></time>
            </article>
        <?php endforeach; endif; ?>
    </div>
    <?php render_numbered_pagination([
        'page' => $channelHistoryPage,
        'pages' => $channelHistoryPages,
        'url' => $channelHistoryUrl,
        'label' => 'Halaman riwayat',
        'class' => 'channel-history-pagination',
        'total' => $channelHistoryTotal,
    ]); ?>
</section>
