<?php
$onuTypeManager = $oltOnuTypeManager;
$oltOnuTypeModalId = 'oltOnuTypeModal-' . $onuTypeManager['device_id'];
$oltOnuTypePonGroups = $onuTypeManager['groups'];
?>
<div class="olt-profile-head olt-onu-type-heading">
    <?php $oltTabActions = [['type' => 'modal', 'value' => $oltOnuTypeModalId, 'label' => 'Add']]; require __DIR__ . '/../tab-actions.php'; ?>
</div>
<?php if (array_sum(array_map('count', $oltOnuTypePonGroups)) === 0): ?>
    <div class="olt-empty"><i class="bi bi-inbox" aria-hidden="true"></i><strong>Data tidak tersedia</strong></div>
<?php else: ?>
    <?php foreach ($oltOnuTypePonGroups as $oltOnuTypePon => $oltOnuTypeRows): ?>
        <?php if ($oltOnuTypeRows === []) {
            continue;
        } ?>
        <section class="olt-onu-type-group" data-onu-type-group="<?= h($oltOnuTypePon) ?>">
            <div class="tittle-subtitle mb-5">
                <h3 class="olt-onu-type-group-title"><?= h(strtoupper($oltOnuTypePon)) ?></h3>
            </div>

            <div class="olt-onu-type-list olt-onu-type-device-list p-2">
                <?php foreach ($oltOnuTypeRows as $onuTypeRow): ?>
                    <?php
                    $onuTypeValues = (array)($onuTypeRow['data'] ?? []);
                    $onuTypeName = $onuTypeRow['name'];
                    $onuTypeIsCreate = false;
                    ?>
                    <details class="olt-onu-type-item olt-onu-type-device-item">
                        <summary><span class="olt-onu-type-summary-text"><strong><?= h($onuTypeName) ?></strong><span class="olt-onu-type-pon"><?= h(strtoupper((string)($onuTypeValues['pon_type'] ?? '')) ?: '-') ?></span></span><i class="bi bi-chevron-down" aria-hidden="true"></i></summary>
                        <div class="olt-onu-type-body">
                            <div class="olt-onu-type-actions">
                            <form method="post" data-onu-type-editor>
                                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action" value="save_olt_profile">
                                <input type="hidden" name="kind" value="onu_type">
                                <input type="hidden" name="profile_scope" value="<?= h($onuTypeManager['scope']) ?>">
                                <input type="hidden" name="olt_row_id" value="<?= (int)$oltDetail['id'] ?>">
                                <input type="hidden" name="name" value="<?= h($onuTypeName) ?>">
                                <input type="hidden" name="operation" value="edit">
                                <fieldset disabled data-onu-type-edit-fields>
                                    <?php require __DIR__ . '/onu-type-fields.php'; ?>
                                </fieldset>
                                <div class="olt-onu-type-inline-actions">
                                    <button type="button" class="button secondary small" data-onu-type-edit><i class="bi bi-pencil" aria-hidden="true"></i> Edit</button>
                                    <button type="button" class="button secondary small" data-onu-type-cancel hidden>Batal</button>
                                    <button type="submit" class="button primary small" data-onu-type-save hidden><i class="bi bi-check2" aria-hidden="true"></i> Simpan</button>
                                </div>
                            </form>
                            <form method="post" data-onu-type-delete class="olt-onu-type-delete-form">
                                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_olt_profile">
                                <input type="hidden" name="kind" value="onu_type">
                                <input type="hidden" name="profile_scope" value="<?= h($onuTypeManager['scope']) ?>">
                                <input type="hidden" name="olt_row_id" value="<?= (int)$oltDetail['id'] ?>">
                                <input type="hidden" name="name" value="<?= h($onuTypeName) ?>">
                                <button type="submit" class="button small button-danger"><i class="bi bi-trash" aria-hidden="true"></i> Hapus</button>
                            </form>
                            </div>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
<div id="<?= h($oltOnuTypeModalId) ?>" class="modal olt-onu-type-device-modal" hidden aria-hidden="true" data-olt-profile-modal>
    <div class="modal-card olt-onu-type-modal-card" role="dialog" aria-modal="true" aria-labelledby="<?= h($oltOnuTypeModalId) ?>Title">
        <div class="olt-onu-type-modal-heading">
            <h2 id="<?= h($oltOnuTypeModalId) ?>Title">Tambah ONU Type</h2><button type="button" class="icon-button" data-olt-profile-modal-close aria-label="Tutup modal"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <form method="post" data-onu-type-create class="olt-onu-type-device-create">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_olt_profile">
            <input type="hidden" name="kind" value="onu_type">
            <input type="hidden" name="profile_scope" value="<?= h($onuTypeManager['scope']) ?>">
            <input type="hidden" name="olt_row_id" value="<?= (int)$oltDetail['id'] ?>">
            <input type="hidden" name="operation" value="create">
            <?php $onuTypeValues = ['pon_type' => 'gpon'];
            $onuTypeIsCreate = true;
            require __DIR__ . '/onu-type-fields.php'; ?>
            <div class="olt-onu-type-modal-actions"><button type="submit" class="button secondary"><i class="bi bi-plus-circle" aria-hidden="true"></i> Add</button></div>
        </form>
    </div>
</div>
