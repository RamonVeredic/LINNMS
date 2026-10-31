<?php

$addOnuView = is_array($addOnuView ?? null) ? $addOnuView : [];
extract($addOnuView);
$addOnuFieldPrefix = $addOnuModalId . '-field-';
?>
<div id="<?= h($addOnuModalId) ?>" class="modal add-onu-register-modal" hidden aria-hidden="true"
     data-add-onu-modal data-family="<?= h($addOnuModalFamily) ?>">
    <div class="modal-card add-onu-register-modal-card" role="dialog" aria-modal="true" aria-labelledby="<?= h($addOnuModalId) ?>Title">
        <div class="add-onu-modal-heading modal-header">
            <div>
                <div class="add-onu-modal-title-row">
                    <h2 id="<?= h($addOnuModalId) ?>Title"><?= h($addOnuModalTitle) ?></h2>
                </div>

            </div>
            <div class="add-onu-modal-heading-actions">
                <button class="icon-button modal-close-button" type="button" data-add-onu-modal-close aria-label="Tutup modal"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
        </div>

        <form id="<?= h($addOnuModalId) ?>Form" method="post" class="add-onu-register-modal-form" data-add-onu-register-form>
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="register_onu">
            <input type="hidden" name="onu_row_id" value="">
            <input type="hidden" name="onu_family" value="<?= h($addOnuModalFamily) ?>">
            <script type="application/json" data-add-onu-vlan-map><?= json_for_html_script((array)($addOnuModalVlanByOlt ?? [])) ?></script>
            <script type="application/json" data-add-onu-profile-map><?= json_for_html_script((array)($addOnuModalProfileByOlt ?? [])) ?></script>
            <script type="application/json" data-add-onu-device-map><?= json_for_html_script((array)($addOnuModalDeviceByOlt ?? [])) ?></script>

            <section class="add-onu-modal-section">
                <div class="add-onu-modal-section-label"><h3>ONU</h3></div>
                <div class="add-onu-modal-section-body">
                    <input type="hidden" name="olt_device_id" value="" data-add-onu-olt>
                    <div class="add-onu-form-grid">
                        <div class="field"><label for="<?= h($addOnuFieldPrefix) ?>card">Card</label><input id="<?= h($addOnuFieldPrefix) ?>card" class="mono" data-add-onu-card readonly aria-readonly="true"></div>
                        <div class="field"><label for="<?= h($addOnuFieldPrefix) ?>pon">PON</label><input id="<?= h($addOnuFieldPrefix) ?>pon" class="mono" data-add-onu-pon readonly aria-readonly="true"></div>
                    </div>
                    <fieldset class="field add-onu-choice-field" data-onu-capability="onu_id">
                        <legend>ONU ID</legend>
                        <div class="add-onu-segmented" data-add-onu-idmode role="group" aria-label="Mode ONU ID">
                            <button type="button" class="is-active" data-onu-id-mode="auto">Auto</button>
                            <button type="button" data-onu-id-mode="custom">Custom</button>
                        </div>
                        <input id="<?= h($addOnuFieldPrefix) ?>onu-id" class="mono add-onu-id-custom" name="onu_id_custom" inputmode="numeric" placeholder="Masukkan Nomor ONU" aria-label="Nomor ONU" hidden data-add-onu-id-custom>
                    </fieldset>
                    <div class="add-onu-form-grid">
                        <div class="field"><label for="<?= h($addOnuFieldPrefix) ?>serial">Serial Number</label><input id="<?= h($addOnuFieldPrefix) ?>serial" class="mono" name="onu_serial" data-add-onu-serial readonly aria-readonly="true" required></div>
                        <div class="field" data-onu-capability="onu_type">
                            <label for="<?= h($addOnuFieldPrefix) ?>type">ONU Type</label>
                            <div class="add-onu-input-row">
                                <select id="<?= h($addOnuFieldPrefix) ?>type" name="onu_type" data-add-onu-onu-type>
                                    <option value="">Pilih Type</option>
                                    <?php foreach ($addOnuModalTypeOptions as $addOnuModalType): ?><option value="<?= h($addOnuModalType) ?>"><?= h($addOnuModalType) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                        </div>
 <div class="field" data-onu-capability="vlan" data-add-onu-primary-vlan="1">
                        <label for="<?= h($addOnuFieldPrefix) ?>service1-vlan">VLAN ID</label>
                        <div class="add-onu-input-row">
                            <select id="<?= h($addOnuFieldPrefix) ?>service1-vlan" name="service1_vlan" data-add-onu-service-vlan>
                                <option value="">Pilih VLAN</option>
                                <?php foreach ($addOnuModalVlanOptions as $addOnuModalVlan): ?><option value="<?= (int)$addOnuModalVlan ?>"><?= (int)$addOnuModalVlan ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    </div>
                    <div class="add-onu-service-head add-onu-toggle-row" data-onu-capability="veip"><label class="add-onu-switch"><input type="checkbox" role="switch" name="use_veip" value="1" checked aria-label="Gunakan VEIP"><span class="toggle-switch" aria-hidden="true"></span></label><h4>Gunakan VEIP</h4></div>
                </div>
            </section>

            <section class="add-onu-modal-section">
                <div class="add-onu-modal-section-label"><h3>Informasi</h3></div>
                <div class="add-onu-modal-section-body">
                    <div class="add-onu-form-grid">
                        <div class="field">
                            <label for="<?= h($addOnuFieldPrefix) ?>name">Nama</label>
                            <input id="<?= h($addOnuFieldPrefix) ?>name" name="onu_name" maxlength="127" required pattern=".*\S.*" data-add-onu-name autocomplete="off" placeholder="Masukkan Nama">
                        </div>
                        <div class="field">
                            <label for="<?= h($addOnuFieldPrefix) ?>location">Lokasi</label>
                            <input id="<?= h($addOnuFieldPrefix) ?>location" name="onu_location" maxlength="255" data-add-onu-location autocomplete="off" placeholder="Masukkan Lokasi (opsional)">
                        </div>
                    </div>
                </div>
            </section>

            <section class="add-onu-modal-section" data-add-onu-service-section data-onu-capability="wan">
                <div class="add-onu-modal-section-label"><h3>WAN Service 1</h3></div>
                <div class="add-onu-modal-section-body">
                    <div class="add-onu-tabs" role="tablist" aria-label="Metode konfigurasi WAN" data-add-onu-wan-tabs>
                        <button type="button" class="is-active" role="tab" aria-selected="true" data-onu-wan-tab="ont">Setup via ONT</button>
                        <button type="button" role="tab" aria-selected="false" data-onu-wan-tab="omci-wan">OMCi WAN-IP (DHCP)</button>
                        <button type="button" role="tab" aria-selected="false" data-onu-wan-tab="omci-pppoe">OMCi PPPoE</button>
                        <button type="button" role="tab" aria-selected="false" data-onu-wan-tab="omci-static">OMCi Static</button>
                    </div>
                    <input type="hidden" name="wan_method" value="ont" data-add-onu-wan-method>
                    <div class="field" data-add-onu-wan-profile-field hidden>
                        <label for="<?= h($addOnuFieldPrefix) ?>wan-vlan-profile">VLAN Profile WAN</label>
                        <select id="<?= h($addOnuFieldPrefix) ?>wan-vlan-profile" name="wan_vlan_profile" data-add-onu-wan-vlan-profile disabled>
                            <option value="">Pilih Profile</option>
                        </select>
                    </div>
                    <div class="field" data-add-onu-wan-pppoe-field hidden><label for="<?= h($addOnuFieldPrefix) ?>wan-username">Username PPPoE</label><input id="<?= h($addOnuFieldPrefix) ?>wan-username" name="wan_username" autocomplete="off" maxlength="64" disabled></div>
                    <div class="field" data-add-onu-wan-pppoe-field hidden><label for="<?= h($addOnuFieldPrefix) ?>wan-password">Password PPPoE</label><input id="<?= h($addOnuFieldPrefix) ?>wan-password" name="wan_password" type="password" autocomplete="new-password" maxlength="64" disabled></div>
                    <div class="field" data-add-onu-wan-static-field hidden><label for="<?= h($addOnuFieldPrefix) ?>wan-ip-profile">IP Profile</label><select id="<?= h($addOnuFieldPrefix) ?>wan-ip-profile" name="wan_ip_profile" data-add-onu-wan-ip-profile disabled><option value="">Pilih Profile</option></select></div>
                    <div class="field" data-add-onu-wan-static-field hidden><label for="<?= h($addOnuFieldPrefix) ?>wan-ip-address">Alamat IP</label><input id="<?= h($addOnuFieldPrefix) ?>wan-ip-address" name="wan_ip_address" inputmode="decimal" disabled></div>
                    <div class="field" data-add-onu-wan-static-field hidden><label for="<?= h($addOnuFieldPrefix) ?>wan-mask">Subnet Mask</label><input id="<?= h($addOnuFieldPrefix) ?>wan-mask" name="wan_mask" inputmode="decimal" disabled></div>

                    <?php for ($addOnuSvcNo = 1; $addOnuSvcNo <= 4; $addOnuSvcNo++): ?>
                        <article class="add-onu-service-card<?= $addOnuSvcNo === 1 ? ' is-open' : '' ?>" data-add-onu-service-card="<?= $addOnuSvcNo ?>">
                            <div class="add-onu-service-head">
                                <label class="add-onu-switch">
                                    <input type="checkbox" role="switch" name="service<?= $addOnuSvcNo ?>_enabled" value="1" <?= $addOnuSvcNo === 1 ? 'checked' : '' ?> data-add-onu-service-toggle aria-label="Aktifkan Service <?= $addOnuSvcNo ?>">
                                    <span class="toggle-switch" aria-hidden="true"></span>
                                </label>
                                <h4>Service <?= $addOnuSvcNo ?></h4>
                                <span class="add-onu-service-no">#0<?= $addOnuSvcNo ?></span>
                            </div>
                            <div class="add-onu-service-body">
                                <fieldset class="field add-onu-choice-field">
                                    <legend>VLAN Mode</legend>
                                    <div class="add-onu-segmented" role="group" aria-label="VLAN Mode">
                                        <button type="button" class="is-active" data-onu-seg="vlan_mode:1">Tag</button>
                                        <button type="button" data-onu-seg="vlan_mode:2">Untag</button>
                                        <button type="button" data-onu-seg="vlan_mode:3">QinQ</button>
                                    </div>
                                </fieldset>
                                <fieldset class="field add-onu-choice-field">
                                    <legend>Service Type</legend>
                                    <div class="add-onu-segmented" role="group" aria-label="Service Type">
                                        <button type="button" class="is-active" data-onu-seg="service_type:internet">Internet</button>
                                        <button type="button" data-onu-seg="service_type:tr069">TR069</button>
                                        <button type="button" data-onu-seg="service_type:iptv">IPTV</button>
                                        <button type="button" data-onu-seg="service_type:bridge">Bridge</button>
                                    </div>
                                </fieldset>
                                <div class="field" data-add-onu-iptv-field hidden>
                                    <label for="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-mvlan">Multicast VLAN (IPTV)</label>
                                    <select id="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-mvlan" name="service<?= $addOnuSvcNo ?>_mvlan" data-add-onu-service-vlan disabled><option value="">Pilih VLAN</option><?php foreach ($addOnuModalVlanOptions as $addOnuModalVlan): ?><option value="<?= (int)$addOnuModalVlan ?>"><?= (int)$addOnuModalVlan ?></option><?php endforeach; ?></select>
                                </div>
                                <div class="field" data-add-onu-iptv-field hidden>
                                    <label for="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-lan">Port LAN IPTV</label>
                                    <input id="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-lan" name="service<?= $addOnuSvcNo ?>_lan_ports" inputmode="numeric" placeholder="Masukkan Port LAN" disabled>
                                </div>
                                <?php if ($addOnuSvcNo > 1): ?>
                                    <div class="field" data-add-onu-primary-vlan="<?= $addOnuSvcNo ?>">
                                        <label for="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-vlan">VLAN ID</label>
                                        <div class="add-onu-input-row">
                                            <select id="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-vlan" name="service<?= $addOnuSvcNo ?>_vlan" data-add-onu-service-vlan>
                                                <option value="">Pilih VLAN</option>
                                                <?php foreach ($addOnuModalVlanOptions as $addOnuModalVlan): ?><option value="<?= (int)$addOnuModalVlan ?>"><?= (int)$addOnuModalVlan ?></option><?php endforeach; ?>
                                            </select>
                                            <button class="icon-button" type="button" data-add-onu-type-clear aria-label="Kosongkan VLAN"><i class="bi bi-x-circle" aria-hidden="true"></i></button>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <div class="field" data-add-onu-qinq-field hidden>
                                    <label for="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-customer-vlan">VLAN pelanggan (inner)</label>
                                    <input id="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-customer-vlan" name="service<?= $addOnuSvcNo ?>_customer_vlan" type="number" min="1" max="4094" inputmode="numeric" disabled>
                                </div>
                                <div class="field" data-add-onu-qinq-field hidden>
                                    <label for="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-svlan">VLAN layanan (outer)</label>
                                    <select id="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-svlan" name="service<?= $addOnuSvcNo ?>_svlan" data-add-onu-service-vlan disabled>
                                        <option value="">Pilih VLAN</option>
                                        <?php foreach ($addOnuModalVlanOptions as $addOnuModalVlan): ?><option value="<?= (int)$addOnuModalVlan ?>"><?= (int)$addOnuModalVlan ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="add-onu-service-profiles">
                                    <div class="field">
                                        <label for="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-download">DOWNLOAD <i class="bi bi-exclamation-circle add-onu-req" aria-hidden="true"></i></label>
                                        <select id="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-download" name="service<?= $addOnuSvcNo ?>_download" data-add-onu-service-download>
                                            <option value="">Pilih Profile</option>
                                            <?php foreach ($addOnuModalDownloadOptions as $addOnuModalDownload): ?><option value="<?= h($addOnuModalDownload) ?>"><?= h($addOnuModalDownload) ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="field">
                                        <label for="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-upload">UPLOAD <i class="bi bi-cloud-upload add-onu-req" aria-hidden="true"></i></label>
                                        <select id="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-upload" name="service<?= $addOnuSvcNo ?>_upload" data-add-onu-service-upload>
                                            <option value="">Pilih Profile</option>
                                            <?php foreach ($addOnuModalUploadOptions as $addOnuModalUpload): ?><option value="<?= h($addOnuModalUpload) ?>"><?= h($addOnuModalUpload) ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <?php if ($addOnuSvcNo > 1): ?>
                                    <div class="field"><label for="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-wan-method">Metode WAN Service <?= $addOnuSvcNo ?></label><select id="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-wan-method" name="service<?= $addOnuSvcNo ?>_wan_method" data-add-onu-service-wan-method><option value="ont">Setup via ONT</option><option value="omci-wan">OMCi DHCP</option><option value="omci-pppoe">OMCi PPPoE</option><option value="omci-static">OMCi Static</option></select></div>
                                    <div class="field" data-add-onu-service-wan-field="profile" hidden><label for="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-wan-profile">VLAN Profile WAN</label><select id="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-wan-profile" name="service<?= $addOnuSvcNo ?>_wan_vlan_profile" data-add-onu-wan-vlan-profile disabled><option value="">Pilih Profile</option></select></div>
                                    <div class="field" data-add-onu-service-wan-field="pppoe" hidden><label for="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-wan-username">Username PPPoE</label><input id="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-wan-username" name="service<?= $addOnuSvcNo ?>_wan_username" maxlength="64" disabled></div>
                                    <div class="field" data-add-onu-service-wan-field="pppoe" hidden><label for="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-wan-password">Password PPPoE</label><input id="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-wan-password" name="service<?= $addOnuSvcNo ?>_wan_password" type="password" maxlength="64" autocomplete="new-password" disabled></div>
                                    <div class="field" data-add-onu-service-wan-field="static" hidden><label for="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-wan-ip-profile">IP Profile</label><select id="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-wan-ip-profile" name="service<?= $addOnuSvcNo ?>_wan_ip_profile" data-add-onu-wan-ip-profile disabled><option value="">Pilih Profile</option></select></div>
                                    <div class="field" data-add-onu-service-wan-field="static" hidden><label for="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-wan-ip-address">Alamat IP</label><input id="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-wan-ip-address" name="service<?= $addOnuSvcNo ?>_wan_ip_address" inputmode="decimal" disabled></div>
                                    <div class="field" data-add-onu-service-wan-field="static" hidden><label for="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-wan-mask">Subnet Mask</label><input id="<?= h($addOnuFieldPrefix) ?>service<?= $addOnuSvcNo ?>-wan-mask" name="service<?= $addOnuSvcNo ?>_wan_mask" inputmode="decimal" disabled></div>
                                <?php endif; ?>
                                <fieldset class="field add-onu-choice-field">
                                    <legend>WAN Mode</legend>
                                    <div class="add-onu-segmented" role="group" aria-label="WAN Mode">
                                        <button type="button" class="is-active" data-onu-seg="wan_mode:ont">Setup via ONT</button>
                                    </div>
                                </fieldset>
                            </div>
                        </article>
                    <?php endfor; ?>

                    <div class="add-onu-service-row">
                        <div class="field">
                            <label for="<?= h($addOnuFieldPrefix) ?>service-name">Nama Layanan</label>
                            <input id="<?= h($addOnuFieldPrefix) ?>service-name" name="service_name" maxlength="32" data-add-onu-service-name autocomplete="off" placeholder="Masukkan Nama Layanan">
                        </div>
                        <div class="field">
                            <label for="<?= h($addOnuFieldPrefix) ?>service-index">Index awal T-CONT / GEMPORT</label>
                            <input id="<?= h($addOnuFieldPrefix) ?>service-index" name="service_index" type="number" min="1" max="32" value="2">
                        </div>
                    </div>
                </div>
            </section>
            <section class="add-onu-modal-section" data-onu-capability="tr069">
                <div class="add-onu-modal-section-body">
                    <div class="add-onu-service-head add-onu-toggle-row"><label class="add-onu-switch"><input type="checkbox" role="switch" name="use_tr069" value="1" data-add-onu-tr069-toggle aria-label="Gunakan TR069"><span class="toggle-switch" aria-hidden="true"></span></label><h4>Gunakan TR069 <span class="add-onu-optional">opsional</span></h4></div>
                    <div class="field" data-add-onu-tr069-field hidden><label for="<?= h($addOnuFieldPrefix) ?>tr069-acs">Alamat ACS <span class="add-onu-optional">opsional</span></label><input id="<?= h($addOnuFieldPrefix) ?>tr069-acs" name="tr069_acs" maxlength="255" autocomplete="off" placeholder="Masukkan Alamat ACS" disabled></div>
                    <div class="field" data-add-onu-tr069-field hidden><label for="<?= h($addOnuFieldPrefix) ?>tr069-username">Username ACS</label><input id="<?= h($addOnuFieldPrefix) ?>tr069-username" name="tr069_username" maxlength="64" autocomplete="off" disabled></div>
                    <div class="field" data-add-onu-tr069-field hidden><label for="<?= h($addOnuFieldPrefix) ?>tr069-password">Password ACS</label><input id="<?= h($addOnuFieldPrefix) ?>tr069-password" name="tr069_password" type="password" maxlength="64" autocomplete="new-password" disabled></div>
                </div>
            </section>


            <div class="add-onu-modal-actions">
                <button class="button primary" type="submit"><i class="bi bi-floppy" aria-hidden="true"></i>Simpan</button>
            </div>
        </form>
    </div>
</div>
