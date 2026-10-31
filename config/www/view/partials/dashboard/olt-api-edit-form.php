<?php

$editApiOltId = (int)($editApiOlt['id'] ?? 0);
$editApiOltRaw = json_decode((string)($editApiOlt['raw_json'] ?? ''), true);
if (!is_array($editApiOltRaw)) $editApiOltRaw = [];
$editApiOltPort = (int)($editApiOlt['snmp_port'] ?? 0);
if ($editApiOltPort < 1 || $editApiOltPort > 65535) {
    $candidatePort = $editApiOltRaw['port'] ?? $editApiOltRaw['snmp_port'] ?? null;
    $editApiOltPort = is_numeric($candidatePort) ? (int)$candidatePort : 0;
}
$editApiOltCommunitySaved = !empty($editApiOlt['community_saved']);
$editApiOltType = trim((string)($editApiOlt['type'] ?? ''));
$editApiOltTypeLabel = (string)(olt_type_options()[$editApiOltType] ?? $editApiOltType);
?>
<form method="post" class="form-grid two device-create-grid required-fields">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="update_api_olt">
    <input type="hidden" name="olt_row_id" value="<?= $editApiOltId ?>">

    <div class="field">
        <label for="editApiOltType">Perangkat</label>
        <input id="editApiOltType" value="<?= h($editApiOltTypeLabel) ?>" readonly>
    </div>

    <div class="field">
        <label for="editApiOltName">Nama OLT</label>
        <input id="editApiOltName" name="olt_name" required value="<?= h((string)($editApiOlt['name'] ?? '')) ?>" placeholder="Masukkan Nama OLT">
    </div>

    <div class="field">
        <label for="editApiOltHost">Host</label>
        <input id="editApiOltHost" name="ip_address" required value="<?= h((string)($editApiOlt['ip_address'] ?? '')) ?>" placeholder="Masukkan IP Address"
            inputmode="decimal" pattern="(?:(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])\.){3}(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])">
    </div>

    <div class="field">
        <label for="editApiOltPort">Port SNMP</label>
        <input id="editApiOltPort" type="number" min="1" max="65535" name="snmp_port" required value="<?= $editApiOltPort > 0 ? $editApiOltPort : '' ?>">
    </div>

    <div class="field span-2">
        <label for="editApiOltCommunity">SNMP Community</label>
        <input id="editApiOltCommunity" name="community" type="password"
            <?= $editApiOltCommunitySaved ? 'placeholder="Kosongkan jika tidak diubah"' : 'required' ?>
            autocomplete="new-password" spellcheck="false">
    </div>

    <div class="span-2 form-actions device-create-actions">
        <button class="button primary" type="submit"><i class="bi bi-floppy" aria-hidden="true"></i> Simpan</button>
        <button class="button secondary" type="button" data-connection-test="device"
            data-connection-test-url="<?= h(public_app_url('connection-test.php')) ?>"
            data-connection-test-csrf="<?= h(csrf_token()) ?>">
            <i class="bi bi-plug" aria-hidden="true"></i> Tes Koneksi
        </button>
        <a class="button secondary" href="<?= h(public_page_url('dashboard')) ?>">Batal</a>
        <span class="connection-test-result text-muted" data-connection-test-result role="status" aria-live="polite" hidden></span>
    </div>
</form>
