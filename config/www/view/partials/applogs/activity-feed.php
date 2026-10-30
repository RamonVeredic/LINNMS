<?php
$feed = $feed ?? [];
?>
<?php if ($feed === []): ?>
    <div class="public-log-empty"><div class="empty-state"><i class="bi bi-clock-history" aria-hidden="true"></i><span>Belum ada aktivitas terbaru</span></div></div>
<?php else: foreach ($feed as $event):
    $entryTime = format_feed_time($event['created_at'] ?? null);
    $level = strtolower(trim((string)($event['level'] ?? 'info')));
    $levelText = $level === 'critical' ? 'kritikal' : $level;
    $action = trim((string)($event['action_name'] ?? 'activity'));
    $message = trim((string)($event['message'] ?? ''));
    $username = trim((string)($event['display_actor'] ?? $event['username'] ?? ''));
    $showUsername = is_admin() && $username !== '' && $username !== 'System'
        && (string)($event['actor_role'] ?? '') !== 'admin';
    $logText = $levelText . ' - ' . ($showUsername ? $username . ', ' : '') . $action . ', ' . $message;
?>
        <div class="public-log-entry" data-log-row data-log-text="<?= h(strtolower($logText . ' ' . $entryTime)) ?>">
            <div class="public-log-message" data-log-expandable>
                <span class="public-log-text" data-log-expand-text><?= h($logText) ?></span>
                <button class="public-log-toggle" type="button" data-log-expand-toggle aria-label="Tampilkan log selengkapnya" title="Tampilkan selengkapnya" aria-expanded="false" hidden><i class="bi bi-chevron-down" aria-hidden="true"></i></button>
            </div>
            <time class="public-log-time"><?= h($entryTime) ?></time>
        </div>
<?php endforeach; endif; ?>
