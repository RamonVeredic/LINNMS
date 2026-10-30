<?php

$historyEntries = is_array($onuView['historyEntries'] ?? null) ? $onuView['historyEntries'] : [];
$historyEntries = array_slice($historyEntries, 0, 4);
$historyEntries = array_reverse($historyEntries);
?>
<section class="panel onu-side-panel onu-connectivity-panel onu-preview-history" aria-labelledby="onuHistoryTitle">
    <header class="tittle-subtitle"><h3 id="onuHistoryTitle">Riwayat</h3></header>
    <?php if ($historyEntries === []): ?>
        <p class="onu-status-timeline-empty-message">Belum ada riwayat.</p>
    <?php else: ?>
    <ul class="onu-status-timeline">
        <?php foreach ($historyEntries as $historyEntry): ?>
            <?php
                $historyAt = (int)($historyEntry['at'] ?? 0);
                $historyLabel = trim((string)($historyEntry['label'] ?? ''));
                $historyOld = trim((string)($historyEntry['label_old'] ?? ''));
                $historyDuration = trim((string)($historyEntry['duration'] ?? ''));
                $historyOngoing = !empty($historyEntry['ongoing']);
                $historyKind = (string)($historyEntry['kind'] ?? '') === 'status' ? 'is-status' : 'is-signal';
                $historyTone = ($historyEntry['tone'] ?? '') === 'bad' ? 'is-bad' : 'is-ok';
            ?>
            <li class="<?= h($historyTone . ' ' . $historyKind) ?>">
                <span class="onu-status-timeline-value">
                    <?php if ($historyOld !== ''): ?><span class="onu-status-timeline-old"><?= h($historyOld) ?></span><i class="bi bi-arrow-right" aria-hidden="true"></i><?php endif; ?>
                    <strong><?= h($historyLabel !== '' ? $historyLabel : '—') ?></strong>
                    <?php if ($historyDuration !== ''): ?><small><?= $historyOngoing ? 'Berlangsung ' : 'Selama ' ?><?= h($historyDuration) ?></small><?php endif; ?>
                </span>
                <?php if ($historyAt > 0): ?>
                    <time datetime="<?= h(date('c', $historyAt)) ?>"><?= h(date('d M', $historyAt)) ?><span><?= h(date('H:i', $historyAt)) ?></span></time>
                <?php else: ?>
                    <time class="onu-status-timeline-empty">&mdash;</time>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
