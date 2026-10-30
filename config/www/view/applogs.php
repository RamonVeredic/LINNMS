<?php
$feed = $activityFeed ?? [];
$activityTotal = max(0, (int)($activityTotal ?? 0));
$activityPage = max(1, (int)($activityPage ?? 1));
$activityPages = max(1, (int)($activityPages ?? 1));

$activityUrl = static function (int $targetPage): string {
    return public_page_url('applogs', ['log_page' => max(1, $targetPage)]);
};
?>
<section class="table-panel recent-activity-panel">
    <div class="table-toolbar table-filter-toolbar">
        <div class="left">
            <div class="table-search">
                <span><i class="bi bi-search" aria-hidden="true"></i></span>
                <input data-log-search="activityFeed" placeholder="Cari...">
            </div>
        </div>
    </div>
    <div class="list-scroll-viewport" role="region" aria-label="App Log" tabindex="0">
        <div class="public-log-list" id="activityFeed">
            <?php require project_path('view', 'partials', 'applogs', 'activity-feed.php'); ?>
        </div>
    </div>
    <?php render_numbered_pagination([
        'page' => $activityPage,
        'pages' => $activityPages,
        'url' => $activityUrl,
        'label' => 'Halaman aktivitas',
        'class' => 'recent-activity-pagination',
        'total' => $activityTotal,
    ]); ?>
</section>
