<?php

if (!is_array($deviceEdit ?? null)) return;
$editForm = (array)($deviceEdit['form'] ?? []);
$editOptions = (array)($deviceEdit['driver_options'] ?? []);
$editCurrentDriverId = (string)($deviceEdit['type'] ?? '');
$editCurrentOption = is_array($editOptions[$editCurrentDriverId] ?? null) ? $editOptions[$editCurrentDriverId] : [];
$editCurrentLabel = (string)($deviceEdit['driver_label'] ?? $editCurrentOption['label'] ?? $editCurrentDriverId);
$editMethod = (string)($editCurrentOption['method'] ?? $deviceEdit['method'] ?? 'snmp');
$editTelnetCli = !empty($editCurrentOption['telnet_cli']) || !empty($deviceEdit['show_telnet_fields']);
$editCommunityDefault = (string)($editCurrentOption['default_snmp_community'] ?? 'public');
$editHasSnmpFields = $editMethod === 'snmp';
$editHasTelnetFields = $editTelnetCli;
?>
    <form method="post" class="form-grid two device-create-grid required-fields" data-device-edit-form>
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_device">
        <input type="hidden" name="device_row_id" value="<?= (int)($deviceEdit['row_id'] ?? 0) ?>">

        <div class="field">
            <label for="editDeviceDriverLabel">Perangkat</label>
            <input id="editDeviceDriverLabel" value="<?= h($editCurrentLabel) ?>" readonly>
            <input id="editDeviceDriver" type="hidden" name="driver_id" value="<?= h($editCurrentDriverId) ?>"
                data-edit-device-type
                data-method="<?= h($editMethod) ?>"
                data-telnet-cli="<?= $editTelnetCli ? '1' : '0' ?>"
                data-community-default="<?= h($editCommunityDefault) ?>"
                data-enabled="1">
        </div>

        <div class="field">
            <label for="editDeviceName">Nama Perangkat</label>
            <input id="editDeviceName" name="device_name" required value="<?= h((string)($editForm['device_name'] ?? '')) ?>" placeholder="Nama perangkat">
        </div>

        <div class="field">
            <label for="editDeviceHost">Host</label>
            <input id="editDeviceHost" name="ip_address" required value="<?= h((string)($editForm['ip_address'] ?? '')) ?>" placeholder="192.168.1.1"
                inputmode="decimal" pattern="(?:(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])\.){3}(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])">
        </div>

        <?php if ($editHasSnmpFields): ?>
        <div class="device-method-fields" data-device-method-fields="snmp" hidden>
            <div class="field">
                <label for="editDeviceSnmpPort">Port SNMP</label>
                <input id="editDeviceSnmpPort" type="number" min="1" max="65535" name="snmp_port"
                    value="<?= h((string)($editForm['snmp_port'] ?? '161')) ?>" required>
            </div>
            <div class="field device-community-field">
                <label for="editDeviceCommunity">SNMP Community</label>
                <input id="editDeviceCommunity" type="text" name="community"
                    value="<?= h((string)($editForm['community'] ?? '')) ?>"
                    data-snmp-community autocomplete="off" spellcheck="false"
                    <?= !empty($deviceEdit['has_community']) ? 'placeholder="Kosongkan jika tidak diubah"' : 'required' ?>>
            </div>
        </div>
        <?php endif; ?>


        <?php if ($editHasTelnetFields): ?>
        <div class="device-method-fields" data-device-method-fields="telnet" hidden>
            <div class="device-telnet-row span-2">
                <label class="toggle-card device-telnet-toggle">
                    <span><strong>Telnet</strong><small>Opsional</small></span>
                    <input type="checkbox" name="hybrid_status" value="1" data-cli-toggle
                        aria-controls="deviceEditTelnetDetails"
                        <?= (int)($editForm['hybrid_status'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <i class="toggle-switch" aria-hidden="true"></i>
                </label>

                <div class="device-telnet-details" id="deviceEditTelnetDetails" data-cli-details
                    <?= (int)($editForm['hybrid_status'] ?? 0) === 1 ? '' : 'hidden' ?>>
                    <div class="field">
                        <label for="editDeviceCliPort">Port</label>
                        <input id="editDeviceCliPort" type="number" min="1" max="65535" name="ports"
                            value="<?= h((string)($editForm['ports'] ?? '23')) ?>" data-cli-field required>
                    </div>
                    <div class="field">
                        <label for="editDeviceCliUsername">Username</label>
                        <input id="editDeviceCliUsername" name="username"
                            value="<?= h((string)($editForm['username'] ?? '')) ?>"
                            data-cli-field autocomplete="username" required>
                    </div>
                    <div class="field">
                        <label for="editDeviceCliPassword">Password</label>
                        <input id="editDeviceCliPassword" type="password" name="password" data-cli-field autocomplete="new-password" required
                            data-secret-stored="<?= !empty($deviceEdit['has_password']) ? '1' : '0' ?>"
                            placeholder="<?= !empty($deviceEdit['has_password']) ? 'Kosongkan jika tidak diubah' : 'Password' ?>">
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="span-2 form-actions device-create-actions">
            <button class="button primary" type="submit" data-edit-device-submit>
                <i class="bi bi-floppy" aria-hidden="true"></i> Simpan
            </button>
            <button class="button secondary" type="button" data-connection-test="device"
                data-connection-test-url="<?= h(public_app_url('connection-test.php')) ?>">
                <i class="bi bi-plug" aria-hidden="true"></i> Tes Koneksi
            </button>
            <?php if (trim((string)($deviceEditCancelUrl ?? '')) !== ''): ?>
                <a class="button secondary" href="<?= h((string)$deviceEditCancelUrl) ?>">Batal</a>
            <?php endif; ?>
            <span class="connection-test-result" data-connection-test-result role="status" aria-live="polite" hidden></span>
        </div>
    </form>
