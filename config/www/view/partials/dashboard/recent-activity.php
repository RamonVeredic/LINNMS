<?php if ($recent === []): ?>
    <div class="public-log-empty"><div class="empty-state"><i class="bi bi-clock-history" aria-hidden="true"></i><span><?= h($recentActivityEmptyText) ?></span></div></div>
    <?php else: foreach ($recent as $event): $view = $event['view']; ?>
        <div class="public-log-entry" data-log-row>
            <div class="public-log-message" data-log-expandable>
                <span class="public-log-text" data-log-expand-text><?php if ($view['pon_event'] !== null): ?><span class="text-cyan"><?= h((string)($view['pon_event']['name'] ?? '')) ?></span> <span class="text-cyan mono">pon <?= h((string)($view['pon_event']['ref'] ?? '')) ?></span> status <?= h((string)($view['pon_event']['status'] ?? '')) ?> power tx <?= h((string)($view['pon_event']['old_power'] ?? '')) ?>   <span class="text-cyan me-2"> ➠</span> <?= h((string)($view['pon_event']['new_power'] ?? '')) ?> <span class="<?= h((string)($view['pon_event']['note_class'] ?? '')) ?>"><?= h((string)($view['pon_event']['note'] ?? '')) ?></span><?php else: ?><?php if ($view['subject'] !== ''): ?><span class="text-cyan"><?= h($view['subject']) ?></span> <?php endif; ?><?php if ($view['show_identity']): ?>Nama : <?= h((string)($event['name'] ?? '')) ?>, Serial : <span class="mono uppercase"><?= h($view['serial']) ?></span>, <?php endif; ?><?php if ($view['old_power'] !== '' && $view['new_power'] !== ''): ?><?= h($view['old_power']) ?><span class="text-cyan em-2">➠</span> <span class="recent-rx-value-<?= h($view['power_trend']) ?>"><?= h($view['new_power']) ?></span><?php else: ?><span class="<?= h($view['detail_class']) ?>"><?= h((string)($event['detail'] ?? '')) ?></span><?php endif; ?><?php if ($view['show_serial']): ?> <span class="mono uppercase">&mdash; Serial <?= h($view['serial']) ?></span><?php endif; ?><?php endif; ?></span>
                <button class="public-log-toggle" type="button" data-log-expand-toggle aria-label="Tampilkan log selengkapnya" title="Tampilkan selengkapnya" aria-expanded="false" hidden><i class="bi bi-chevron-down" aria-hidden="true"></i></button>
            </div>
            <time class="public-log-time"><?= h($view['time']) ?></time>
        </div>
<?php endforeach;
endif; ?>


