<section class="profile-onu-backup" id="onu-backup" aria-labelledby="onuBackupTitle">
   
    <div class="onu-backup-content">
        <div class="onu-backup-actions">
            <form method="post" class="onu-backup-action-form">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="export_onu_backup">
                <button class="button secondary" type="submit"><i class="bi bi-download" aria-hidden="true"></i><span class="btn-text">Ekspor ONU</span></button>
            </form>
            <form method="post" enctype="multipart/form-data" class="onu-backup-action-form" data-onu-backup-import-form>
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="preview_onu_backup">
                <input class="onu-backup-file-input" type="file" name="onu_backup_file" accept=".csv,text/csv" required data-onu-backup-file>
                <button class="button primary" type="button" data-onu-backup-file-trigger>
                    <i class="bi bi-upload" aria-hidden="true"></i><span class="btn-text" data-onu-backup-import-text>Impor ONU</span>
                </button>
            </form>
        </div>
        <p class="onu-backup-import-status" data-onu-backup-import-status aria-live="polite" hidden></p>

        <?php if (is_array($onuBackupPreview ?? null)):
            $backupSummary = is_array($onuBackupPreview['summary'] ?? null) ? $onuBackupPreview['summary'] : [];
            $backupRows = is_array($onuBackupPreview['rows'] ?? null) ? $onuBackupPreview['rows'] : [];
        ?>
            <div class="onu-backup-preview">
                <div class="profile-information-heading onu-backup-preview-heading">
                    <h2>Preview Impor</h2>
                </div>
                <div class="onu-backup-summary" aria-label="Ringkasan preview impor">
                    <div><span>Total</span><strong><?= (int)($backupSummary['total'] ?? 0) ?></strong></div>
                    <div class="is-ready"><span>Siap</span><strong><?= (int)($backupSummary['ready'] ?? 0) ?></strong></div>
                    <div><span>Sudah sama</span><strong><?= (int)($backupSummary['unchanged'] ?? 0) ?></strong></div>
                    <div class="is-warning"><span>Tidak ditemukan</span><strong><?= (int)($backupSummary['not_found'] ?? 0) ?></strong></div>
                    <div class="is-warning"><span>Duplikat/Ambigu</span><strong><?= (int)($backupSummary['duplicate'] ?? 0) + (int)($backupSummary['ambiguous'] ?? 0) ?></strong></div>
                    <div class="is-error"><span>Tidak valid</span><strong><?= (int)($backupSummary['invalid'] ?? 0) ?></strong></div>
                </div>

                <div class="table-wrap onu-backup-preview-table">
                    <table>
                        <thead><tr><th>Baris</th><th>MAC</th><th>Nama</th><th>Alamat</th><th>Hasil</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($backupRows, 0, 100) as $backupRow):
                            $backupStatus = (string)($backupRow['status'] ?? 'invalid');
                            $backupStatusLabel = match ($backupStatus) {
                                'ready' => 'Siap',
                                'unchanged' => 'Sudah sama',
                                'not_found' => 'Tidak ditemukan',
                                'duplicate' => 'Duplikat',
                                'ambiguous' => 'Ambigu',
                                default => 'Tidak valid',
                            };
                        ?>
                            <tr>
                                <td><?= (int)($backupRow['line'] ?? 0) ?></td>
                                <td class="mono uppercase"><?= h(display_text($backupRow['mac_address'] ?? null, '-')) ?></td>
                                <td><?= h(display_text($backupRow['nama'] ?? null, '-')) ?></td>
                                <td><?= h(display_text($backupRow['alamat'] ?? null, '-')) ?></td>
                                <td><span class="onu-backup-status is-<?= h($backupStatus) ?>"><?= h($backupStatusLabel) ?></span><small><?= h((string)($backupRow['message'] ?? '')) ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="onu-backup-confirm-actions">
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="confirm_onu_backup">
                        <input type="hidden" name="preview_token" value="<?= h((string)($onuBackupPreview['token'] ?? '')) ?>">
                        <button class="button primary" type="submit" <?= (int)($backupSummary['ready'] ?? 0) < 1 ? 'disabled' : '' ?> onclick="return confirm('Perbarui nama dan alamat ONU yang cocok berdasarkan MAC?')">
                            <i class="bi bi-check2-circle" aria-hidden="true"></i> Konfirmasi Impor
                        </button>
                    </form>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="cancel_onu_backup">
                        <button class="button secondary" type="submit"><i class="bi bi-x-lg" aria-hidden="true"></i> Batalkan</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
