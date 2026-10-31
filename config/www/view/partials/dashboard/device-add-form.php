<?php
$old = $deviceAddOld;
$name = (string)($old['device_name'] ?? $old['olt_name'] ?? '');
$fieldError = static fn(string $field): string => trim((string)($deviceAddErrors[$field] ?? ''));
$errorAttributes = static fn(string $field): string => $fieldError($field) !== ''
    ? ' aria-invalid="true" aria-describedby="add-error-' . h($field) . '"' : '';
$errorTip = static fn(string $field): string => $fieldError($field) !== ''
    ? '<small class="device-field-error" id="add-error-' . h($field) . '">' . h($fieldError($field)) . '</small>' : '';
?>
<form method="post" class="form-grid two device-create-grid required-fields" data-device-create-form novalidate>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="add_device">

    <div class="field">
        <label for="addDeviceDriver">Type</label>
        <select id="addDeviceDriver" name="driver_id" data-device-type data-select-search="Cari ..." data-select-label="Pilih perangkat" required<?= $errorAttributes('driver_id') ?>>
            <option value="" disabled data-select-placeholder <?= $deviceAddSelectedDriverId === '' ? 'selected' : '' ?>>Pilih perangkat</option>
            <?php foreach ($deviceAddGroups as $group): ?>
            <optgroup label="<?= h((string)$group['label']) ?>" class="text-cyan fw-bold" data-device-type-group>
                <?php foreach ($group['rows'] as $row): $d = $row['definition']; ?>
                <option value="<?= h($row['key']) ?>"
                    data-method="<?= h((string)$d['method']) ?>"
                    data-community-default="<?= h((string)($d['default_snmp_community'] ?? 'public')) ?>"
                    data-telnet-cli="<?= !empty($d['telnet_cli']) ? '1' : '0' ?>"
                    data-quota-allowed="<?= !empty($row['quota_allowed']) ? '1' : '0' ?>"
                    data-enabled="1"
                    <?= $deviceAddSelectedDriverId === $row['key'] ? 'selected' : '' ?>><?= h((string)$d['label']) ?></option>
                <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
            <optgroup label="TIDAK TERSEDIA" class="danger fw-bold" data-device-type-group>
                <?php foreach ($deviceAddUnavailable as $row): $d = $row['definition']; ?>
                    <option value="<?= h($row['key']) ?>"
                        data-method="<?= h((string)$d['method']) ?>"
                        data-enabled="0"
                        <?= !empty($row['access_locked']) ? 'data-icon="bi bi-lock-fill success"' : '' ?>
                        disabled
                        <?= $deviceAddSelectedDriverId === $row['key'] ? 'selected' : '' ?>><?= h((string)$d['label']) ?></option>
                <?php endforeach; ?>
            </optgroup>
        </select>
        <?= $errorTip('driver_id') ?>
    </div>

    <div class="field">
        <label for="addDeviceName">Nama Perangkat</label>
        <input id="addDeviceName" name="device_name" required value="<?= h($name) ?>" placeholder="Masukkan Nama Perangkat"<?= $errorAttributes('device_name') ?>>
        <?= $errorTip('device_name') ?>
    </div>

    <div class="field">
        <label for="addDeviceHost">Host</label>
        <input id="addDeviceHost" name="ip_address" required value="<?= h($old['ip_address'] ?? '') ?>" placeholder="Masukkan IP Address"<?= $errorAttributes('ip_address') ?>
            inputmode="decimal" pattern="(?:(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])\.){3}(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])">
        <?= $errorTip('ip_address') ?>
    </div>

    <div class="device-method-fields" data-device-method-fields="snmp" hidden>
        <div class="field">
            <label for="addDeviceSnmpPort">Snmp Port</label>
            <input id="addDeviceSnmpPort" type="number" min="1" max="65535" name="snmp_port" value="<?= h($old['snmp_port'] ?? '161') ?>" required<?= $errorAttributes('snmp_port') ?>>
            <?= $errorTip('snmp_port') ?>
        </div>
        <div class="field device-community-field">
            <label for="addDeviceCommunity">Community</label>
            <input id="addDeviceCommunity" type="text" name="community"
                value="<?= h($old['community'] ?? $deviceAddCommunityDefault) ?>"
                data-snmp-community
                data-driver-default="<?= h($deviceAddCommunityDefault) ?>"
                required autocomplete="off" spellcheck="false"<?= $errorAttributes('community') ?>>
            <?= $errorTip('community') ?>
        </div>
    </div>

    <div class="device-method-fields" data-device-method-fields="telnet" hidden>
        <div class="device-telnet-row span-2">
            <label class="toggle-card device-telnet-toggle">
                <span><strong>Telnet</strong><small class="text-muted">( Opsional )</small></span>
                <input type="checkbox" name="hybrid_status" value="1" data-cli-toggle <?= !empty($old['hybrid_status']) ? 'checked' : '' ?>
                    aria-controls="deviceTelnetDetails" autocomplete="off">
                <i class="toggle-switch" aria-hidden="true"></i>
            </label>

            <div class="device-telnet-details" id="deviceTelnetDetails" data-cli-details hidden>
                <div class="field">
                    <label for="addDeviceCliPort">Telnet Port</label>
                    <input id="addDeviceCliPort" type="number" min="1" max="65535" name="ports"
                        value="<?= h($old['ports'] ?? '23') ?>" data-cli-field required<?= $errorAttributes('ports') ?>>
                    <?= $errorTip('ports') ?>
                </div>
                <div class="field">
                    <label for="addDeviceCliUsername">Username</label>
                    <input id="addDeviceCliUsername" name="username" value="<?= h($old['username'] ?? '') ?>"
                        data-cli-field autocomplete="username" placeholder="Masukkan Username" required<?= $errorAttributes('username') ?>>
                    <?= $errorTip('username') ?>
                </div>
                <div class="field">
                    <label for="addDeviceCliPassword">Password</label>
                    <input id="addDeviceCliPassword" type="password" name="password" data-cli-field
                        autocomplete="new-password" placeholder="Masukkan Password" required<?= $errorAttributes('password') ?>>
                    <?= $errorTip('password') ?>
                </div>
            </div>
        </div>
    </div>

    <div class="span-2 form-actions device-create-actions">
        <button class="button primary" type="submit" data-device-submit>
            <i class="bi bi-floppy" aria-hidden="true"></i> Simpan
        </button>
        <button class="button secondary" type="button" data-connection-test="device"
            data-connection-test-url="<?= h(public_app_url('connection-test.php')) ?>" hidden>
            <i class="bi bi-plug" aria-hidden="true"></i> Tes Koneksi
        </button>
        <span class="connection-test-result text-muted" data-connection-test-result role="status" aria-live="polite" hidden></span>
    </div>
    <div class="span-2 device-add-tip" data-device-add-tip hidden>
        <i class="bi bi-lightbulb" aria-hidden="true"></i>
        <div>
            <div class="text-cyan fw-bold">INFORMASI</div>
            <p><span class="text-cyan">✧</span> <b class="uppercase" data-device-tip-name></b> <span data-device-tip-message></span></p>
            <div data-device-tip-extra></div>
        </div>
    </div>
</form>
