<?php
$editRowId = valid_positive_id($_GET['id'] ?? null);
if (
    $editApiOlt === null ||
    (string)($editApiOlt['source'] ?? '') !== 'api' ||
    olt_monitoring_driver($editApiOlt) !== 'external_olt_api'
) {
    flash('error', 'OLT API tidak ditemukan.');
    redirect(public_page_url('dashboard'));
}

$raw = json_decode((string)($editApiOlt['raw_json'] ?? ''), true);
if (!is_array($raw)) $raw = [];
$snmpPort = (int)($editApiOlt['snmp_port'] ?? 0);
if ($snmpPort < 1 || $snmpPort > 65535) {
    $candidatePort = $raw['port'] ?? $raw['snmp_port'] ?? null;
    $snmpPort = is_numeric($candidatePort) ? (int)$candidatePort : 0;
}
$community = trim((string)($raw['community'] ?? ''));
$oltType = trim((string)($editApiOlt['type'] ?? ''));
?>
<section class="panel">
    <div class="panel-heading">
        <div>
            <h2>Edit Koneksi OLT Zetset</h2>
            <p>Perubahan dikirim langsung ke Zetset API.</p>
        </div>
    </div>

    <form method="post" class="form-grid two required-fields">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_api_olt">
        <input type="hidden" name="olt_row_id" value="<?= (int)$editApiOlt['id'] ?>">

        <div class="field"><label for="editApiOltName">Nama OLT</label><input id="editApiOltName" name="olt_name" required value="<?= h((string)($editApiOlt['name'] ?? '')) ?>"></div>

        <div class="field"><label for="editApiOltModel">Model</label><input id="editApiOltModel" value="<?= h((string)(olt_type_options()[$oltType] ?? $oltType)) ?>" disabled></div>

        <div class="field"><label for="editApiOltHost">IP / Host</label><input id="editApiOltHost" name="ip_address" required value="<?= h((string)($editApiOlt['ip_address'] ?? '')) ?>"></div>

        <div class="field"><label for="editApiOltPort">Port SNMP</label><input id="editApiOltPort" type="number" min="1" max="65535" name="snmp_port" required value="<?= $snmpPort > 0 ? $snmpPort : '' ?>"></div>

        <div class="field span-2"><label for="editApiOltCommunity">SNMP Community</label><input id="editApiOltCommunity" name="community" required value="<?= h($community) ?>" autocomplete="off" spellcheck="false"></div>

        <div class="span-2 form-actions">
            <button class="button primary" type="submit"><i class="bi bi-floppy"></i> Simpan</button>
            <button class="button secondary" type="button" data-connection-test="device"
                data-connection-test-url="<?= h(public_app_url('connection-test.php')) ?>"
                data-connection-test-csrf="<?= h(csrf_token()) ?>">
                <i class="bi bi-plug"></i> Tes Koneksi API
            </button>
            <a class="button secondary" href="<?= h(public_page_url('dashboard')) ?>">Batal</a>
            <span class="connection-test-result text-muted" data-connection-test-result role="status" aria-live="polite" hidden></span>
        </div>
    </form>
</section>
