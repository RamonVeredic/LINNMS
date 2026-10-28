<?php

$oltVlanModalId = (string)($oltVlanModalId ?? 'oltVlanModal');
$oltVlanOltRowId = (int)($oltVlanOltRowId ?? $oltRowId ?? 0);
?>
<div id="<?= h($oltVlanModalId) ?>" class="modal" hidden aria-hidden="true" data-olt-profile-modal>
    <div class="modal-card olt-vlan-modal-card" role="dialog" aria-modal="true" aria-labelledby="<?= h($oltVlanModalId) ?>Title">
        <div class="olt-onu-type-modal-heading">
            <h2 id="<?= h($oltVlanModalId) ?>Title">Tambah VLAN</h2>
            <button class="icon-button" type="button" data-olt-profile-modal-close aria-label="Tutup modal"><i class="ri-close-line" aria-hidden="true"></i></button>
        </div>
        <form method="post" class="olt-onu-type-modal-form">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_olt_profile">
            <input type="hidden" name="olt_row_id" value="<?= (int)$oltVlanOltRowId ?>">
            <input type="hidden" name="kind" value="vlan">

            <div class="olt-onu-type-row">
                <span class="olt-onu-type-label">VLAN ID</span>
                <input type="number" name="vlan_id" min="1" max="4095" required autocomplete="off" placeholder="1-4095" data-olt-onu-type-name>
            </div>

            <div class="olt-onu-type-row">
                <span class="olt-onu-type-label">Nama</span>
                <input type="text" name="name" maxlength="32" autocomplete="off" placeholder="VLAN0100">
            </div>

            <div class="olt-onu-type-modal-actions">
                <button class="button secondary" type="button" data-olt-profile-modal-close>Batal</button>
                <button class="button primary" type="submit"><i class="ri-save-3-line" aria-hidden="true"></i>Simpan</button>
            </div>
        </form>
    </div>
</div>
