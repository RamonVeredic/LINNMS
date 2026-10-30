<?php
$oltSpeedModalId = 'oltSpeedProfileModal';
?>
<div id="<?= h($oltSpeedModalId) ?>" class="modal" data-olt-speed-modal hidden aria-hidden="true" data-olt-speed-row-id="<?= (int)($oltRowId ?? 0) ?>" data-olt-speed-modal-id="<?= h($oltSpeedModalId) ?>">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="<?= h($oltSpeedModalId) ?>-title">
        <div class="olt-pon-modal-heading modal-header">
            <div class="olt-pon-modal-title"><h2 id="<?= h($oltSpeedModalId) ?>-title" data-speed-title>Tambah Profil</h2></div>
            <button class="icon-button modal-close-button" type="button" data-olt-speed-close aria-label="Tutup modal"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <form class="olt-uplink-form" data-olt-speed-form>
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" data-speed-kind value="traffic">
            <div class="choice-check-row" role="radiogroup" aria-label="Jenis profil WAN IP" data-speed-profile-choices="wan" hidden>
                <label class="choice-check-card"><input type="radio" name="olt_speed_profile_choice" value="vlan_profile" data-speed-profile-choice><i class="choice-check-mark" aria-hidden="true"><i class="bi bi-check-lg"></i></i><span>VLAN Profile</span></label>
                <label class="choice-check-card"><input type="radio" name="olt_speed_profile_choice" value="ip" data-speed-profile-choice><i class="choice-check-mark" aria-hidden="true"><i class="bi bi-check-lg"></i></i><span>IP Profile</span></label>
            </div>
            <div class="choice-check-row" role="radiogroup" aria-label="Jenis profil Speed" data-speed-profile-choices="speed" hidden>
                <label class="choice-check-card"><input type="radio" name="olt_speed_profile_choice" value="traffic" data-speed-profile-choice><i class="choice-check-mark" aria-hidden="true"><i class="bi bi-check-lg"></i></i><span>Traffic Profile</span></label>
                <label class="choice-check-card"><input type="radio" name="olt_speed_profile_choice" value="tcont" data-speed-profile-choice><i class="choice-check-mark" aria-hidden="true"><i class="bi bi-check-lg"></i></i><span>T-CONT Profile</span></label>
            </div>
            <label class="olt-uplink-row"><span>Nama Profil</span><input class="olt-uplink-control" type="text" data-speed-input="name" maxlength="64" required></label>
            <div data-speed-fields="traffic">
                <label class="olt-uplink-row"><span>SIR (kbps)</span><input class="olt-uplink-control" type="number" min="1" data-speed-input="sir_kbps"></label>
                <label class="olt-uplink-row"><span>PIR (kbps)</span><input class="olt-uplink-control" type="number" min="1" data-speed-input="pir_kbps"></label>
                <label class="olt-uplink-row"><span>CBS (kbytes)</span><input class="olt-uplink-control" type="text" data-speed-input="cbs_kbytes" placeholder="Default"></label>
                <label class="olt-uplink-row"><span>PBS (kbytes)</span><input class="olt-uplink-control" type="text" data-speed-input="pbs_kbytes" placeholder="Default"></label>
            </div>
            <div data-speed-fields="vlan_profile" hidden>
                <label class="olt-uplink-row"><span>Tag Mode</span><select class="olt-uplink-control" data-speed-input="tag_mode"><option value="tag">tag</option><option value="vlan-stacking">vlan-stacking</option></select></label>
                <label class="olt-uplink-row"><span>CVLAN</span><input class="olt-uplink-control" type="number" min="1" max="4095" data-speed-input="cvlan"></label>
            </div>
            <div data-speed-fields="ip" hidden>
                <label class="olt-uplink-row"><span>Gateway</span><input class="olt-uplink-control" type="text" data-speed-input="gateway" placeholder="10.0.0.1"></label>
                <label class="olt-uplink-row"><span>Primary DNS</span><input class="olt-uplink-control" type="text" data-speed-input="primary_dns" placeholder="8.8.8.8"></label>
                <label class="olt-uplink-row"><span>Secondary DNS</span><input class="olt-uplink-control" type="text" data-speed-input="secondary_dns" placeholder="8.8.4.4"></label>
            </div>
            <div data-speed-fields="tcont" hidden>
                <label class="olt-uplink-row"><span>Type</span><input class="olt-uplink-control" type="number" min="1" max="5" data-speed-input="type"></label>
                <label class="olt-uplink-row"><span>Fixed (kbps)</span><input class="olt-uplink-control" type="number" min="1" data-speed-input="fbw_kbps"></label>
                <label class="olt-uplink-row"><span>Assured (kbps)</span><input class="olt-uplink-control" type="number" min="0" data-speed-input="abw_kbps" placeholder="0"></label>
                <label class="olt-uplink-row"><span>Maximum (kbps)</span><input class="olt-uplink-control" type="number" min="0" data-speed-input="mbw_kbps" placeholder="0"></label>
            </div>
            <div class="olt-uplink-modal-actions">
                <button class="button secondary" type="button" data-olt-speed-close>Tutup</button>
                <button class="button primary" type="submit">Simpan</button>
            </div>
        </form>
    </div>
</div>
