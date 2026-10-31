<div class="table-wrap">
    <table id="devicesTable">
        <thead><tr><th>No.</th><th>Jenis</th><th>Nama</th><th>Model</th><th>Sync</th><th></th></tr></thead>
        <tbody>
        <?php if ($dashboardDeviceRowCount === 0): ?><tr><td colspan="6" class="empty-cell"><div class="empty-state"><i class="bi bi-inbox" aria-hidden="true"></i><span>Belum ada perangkat</span></div></td></tr><?php endif; ?>

        <?php foreach ($dashboardDeviceRows as $deviceRow): ?>
            <tr data-href="<?= h((string)($deviceRow['row_url'] ?? '')) ?>" data-device-row-id="<?= (int)($deviceRow['id'] ?? 0) ?>" tabindex="0" role="link" onclick="if(!event.target.closest('a,button,input,form,[data-device-action-menu]'))location.href=this.dataset.href">
                <td><?= (int)($deviceRow['number'] ?? 0) ?></td>
                <td><div class="device-type-cell"><span class="device-type"><?= h((string)($deviceRow['type_label'] ?? '')) ?></span><span class="device-type-count"><?= h((string)($deviceRow['type_count'] ?? '')) ?></span></div></td>
                <td><div class="device-name-cell"><span class="device-name"><?= h((string)($deviceRow['name'] ?? '')) ?></span><span class="device-address mono"><?= h((string)($deviceRow['address'] ?? '')) ?></span></div></td>
                <td><div class="device-model-cell"><span class="device-model"><?= h((string)($deviceRow['model'] ?? '')) ?></span><?php if (trim((string)($deviceRow['system_name'] ?? '')) !== ''): ?><span class="device-system-name"><?= h((string)$deviceRow['system_name']) ?></span><?php endif; ?></div></td>
                <td class="mono conn-cell" title="<?= h((string)($deviceRow['sync']['title'] ?? 'Terakhir sinkronisasi')) ?>"><span class="conn-time<?= !empty($deviceRow['sync']['pending']) ? ' is-pending' : '' ?>"<?= !empty($deviceRow['sync']['pending']) ? ' role="status" aria-label="' . h((string)($deviceRow['sync']['aria'] ?? 'Menunggu sinkronisasi pertama')) . '"' : '' ?>><?php if (!empty($deviceRow['sync']['pending'])): ?><?php if (!empty($deviceRow['sync']['active'])): ?><span class="sync-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)($deviceRow['sync']['progress'] ?? 0) ?>" aria-label="<?= h((string)($deviceRow['sync']['phase'] ?? '') !== '' ? (string)$deviceRow['sync']['phase'] : 'Sinkronisasi OLT sedang berjalan') ?>"><span class="sync-progress-fill" style="width:<?= (int)($deviceRow['sync']['progress'] ?? 0) ?>%"></span></span><?php else: ?>Belum tersinkron<?php endif; ?><?php elseif (!empty($deviceRow['sync']['failed'])): ?><span class="danger"><?= h((string)($deviceRow['sync']['error'] ?? 'Sinkronisasi gagal')) ?></span><?php elseif (!empty($deviceRow['sync']['has_synced'])): ?><?= h((string)($deviceRow['sync']['time'] ?? '')) ?><?php else: ?>Belum tersinkron<?php endif; ?></span><?php if (empty($deviceRow['sync']['pending']) && $deviceRow['connections'] !== []): ?><div class="conn-chips"><?php foreach ($deviceRow['connections'] as $connection): ?><span class="conn-chip <?= h((string)($connection['class'] ?? '')) ?>" data-connection-state="<?= h((string)($connection['state'] ?? '')) ?>"<?= trim((string)($connection['title'] ?? '')) !== '' ? ' title="' . h((string)$connection['title']) . '"' : '' ?>><span class="conn-dot"></span><?= h((string)($connection['label'] ?? '')) ?></span><?php endforeach; ?></div><?php endif; ?></td>
                <td><div class="row-actions device-table-actions">
                    <?php if ($deviceRow['scan'] !== null): ?>
                        <form method="post"<?= !empty($deviceRow['scan']['discovery']) ? ' data-dashboard-discovery' : '' ?><?= !empty($deviceRow['scan']['discovery']) && !empty($deviceRow['scan']['disabled']) ? ' data-discovery-active="true"' : '' ?>>
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="<?= h((string)($deviceRow['scan']['action'] ?? '')) ?>">
                            <input type="hidden" name="<?= h((string)($deviceRow['scan']['field'] ?? '')) ?>" value="<?= (int)($deviceRow['scan']['value'] ?? 0) ?>">
                            <button class="icon-button scan" type="submit" title="<?= h((string)($deviceRow['scan']['title'] ?? 'Pindai')) ?>"<?= !empty($deviceRow['scan']['disabled']) ? ' disabled' : '' ?>><i class="bi bi-search"></i></button>
                        </form>
                    <?php endif; ?>
                    <?php if (trim((string)($deviceRow['edit_url'] ?? '')) !== '' || $deviceRow['delete'] !== []): ?>
                        <div class="device-action-dropdown" data-device-action-menu>
                            <button class="icon-button device-action-trigger" type="button" aria-label="Aksi untuk <?= h((string)($deviceRow['name'] ?? 'perangkat')) ?>" aria-haspopup="menu" aria-expanded="false" aria-controls="device-action-menu-<?= (int)($deviceRow['id'] ?? 0) ?>" data-device-action-trigger><i class="bi bi-three-dots-vertical" aria-hidden="true"></i></button>
                            <div class="device-action-menu" id="device-action-menu-<?= (int)($deviceRow['id'] ?? 0) ?>" role="menu" aria-label="Aksi perangkat" data-device-action-list hidden>
                                <?php if (trim((string)($deviceRow['edit_url'] ?? '')) !== ''): ?>
                                    <a href="<?= h((string)$deviceRow['edit_url']) ?>" role="menuitem"<?= !empty($deviceRow['edit_modal']) ? ' data-device-edit-open data-device-id="' . (int)$deviceRow['id'] . '"' : '' ?>><i class="bi bi-pencil-square" aria-hidden="true"></i>Edit</a>
                                <?php endif; ?>
                                <?php if ($deviceRow['delete'] !== []): ?>
                                    <form method="post" data-device-delete-form>
                                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="<?= h((string)($deviceRow['delete']['action'] ?? '')) ?>">
                                        <input type="hidden" name="<?= h((string)($deviceRow['delete']['field'] ?? '')) ?>" value="<?= (int)($deviceRow['delete']['value'] ?? 0) ?>">
                                        <button type="submit" class="button-danger" role="menuitem"><i class="bi bi-trash3" aria-hidden="true"></i><?= h((string)($deviceRow['delete']['title'] ?? 'Hapus perangkat')) ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
