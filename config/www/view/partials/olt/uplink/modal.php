<?php
$oltUplinkModalId = (string)($oltUplinkModalId ?? 'oltUplinkModal');
$oltUplinkRowId = (int)($oltDetail['id'] ?? 0);
$oltUplinkPortDetails = is_array($oltUplinkPortDetails ?? null) ? $oltUplinkPortDetails : [];
foreach ($oltUplinkPortDetails as &$uplinkEntry) {
    if (!is_array($uplinkEntry) || !is_array($uplinkEntry['optics'] ?? null)) continue;
    foreach (array_keys($uplinkEntry['optics']) as $opticField) {
        $opticValue = $uplinkEntry['optics'][$opticField];
        if ($opticValue === null && array_key_exists($opticField . '_raw', $uplinkEntry['optics'])) {
            $opticValue = $uplinkEntry['optics'][$opticField . '_raw'];
        }
        if ($opticValue === null || $opticValue === '' || (is_string($opticValue) && in_array(strtolower(trim($opticValue)), ['na', 'n/a'], true))) {
            unset($uplinkEntry['optics'][$opticField]);
        } else {
            $uplinkEntry['optics'][$opticField] = $opticValue;
        }
    }
}
unset($uplinkEntry);


$oltUplinkPortRows = [
    'admin_state' => ['label' => 'Interface', 'suffix' => ''],
    'description' => ['label' => 'Description', 'suffix' => ''],
    'speed' => ['label' => 'Speed', 'suffix' => ''],
    'duplex' => ['label' => 'Duplex', 'suffix' => ''],
    'medium' => ['label' => 'Connector', 'suffix' => ''],
    'negotiation' => ['label' => 'Negotiation', 'suffix' => ''],
    'flow_control' => ['label' => 'Flow Control', 'suffix' => ''],
];
$oltUplinkOpticalRows = [
    'vendor' => ['label' => 'Vendor Name', 'suffix' => ''],
    'vendor_pn' => ['label' => 'Vendor PN', 'suffix' => ''],
    'vendor_sn' => ['label' => 'Vendor SN', 'suffix' => ''],
    'module_type' => ['label' => 'Module Type', 'suffix' => ''],
    'connector' => ['label' => 'Connector', 'suffix' => ''],
    'temperature' => ['label' => 'Temperature', 'suffix' => ' °C'],
    'voltage' => ['label' => 'Supply Voltage', 'suffix' => ' V'],
    'tx_power' => ['label' => 'TX Power', 'suffix' => ' dBm'],
    'rx_power' => ['label' => 'RX Power', 'suffix' => ' dBm'],
    'current' => ['label' => 'TX Bias Current', 'suffix' => ' mA'],
    'wavelength' => ['label' => 'Wavelength', 'suffix' => ' NM'],
    'trans_distance' => ['label' => 'Trans Distance', 'suffix' => ''],
];
$oltUplinkVlanModeLabels = ['hybrid' => 'Hybrid', 'access' => 'Access', 'trunk' => 'Trunk'];
?>
<div id="<?= h($oltUplinkModalId) ?>" class="modal olt-detail-modal" hidden aria-hidden="true" data-olt-uplink-modal data-olt-uplink-row-id="<?= $oltUplinkRowId ?>">
  <div class="modal-card olt-uplink-modal-card" role="dialog" aria-modal="true" aria-labelledby="<?= h($oltUplinkModalId) ?>-title">
    <div class="olt-uplink-modal-head modal-header"><h2 id="<?= h($oltUplinkModalId) ?>-title" data-uplink-modal-title>Uplink</h2><button class="icon-button modal-close-button" type="button" data-olt-uplink-close aria-label="Tutup modal"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>" data-uplink-csrf>
    <div class="olt-uplink-modal-body">
      <nav class="olt-uplink-tabs" role="tablist" aria-label="Detail uplink">
        <button type="button" class="is-active" role="tab" aria-selected="true" data-uplink-tab="port">Port</button>
        <button type="button" role="tab" aria-selected="false" data-uplink-tab="vlan">VLAN</button>
        <button type="button" role="tab" aria-selected="false" data-uplink-tab="optical">Modul</button>
      </nav>
      <section class="olt-uplink-tab-panel" role="tabpanel" data-uplink-panel="port">
        <div class="olt-uplink-detail-grid">
          <div class="olt-uplink-row"><span>Interface Status</span><label class="pon-status-switch"><input type="checkbox" role="switch" class="toggle-switch" data-uplink-config-toggle="admin_state"><span data-uplink-toggle-label="admin_state">Disable</span></label></div>
          <div class="olt-uplink-row"><span>Interface Graph</span><label class="pon-catat-switch"><input type="checkbox" role="switch" class="toggle-switch" aria-label="Interface Graph" data-traffic-record data-uplink-graph-toggle data-device-id="<?= $oltUplinkRowId ?>" data-if-ref="" data-csrf="<?= h(csrf_token()) ?>"></label></div>
          <div class="olt-uplink-row"><span>Flow Control</span><label class="pon-status-switch"><input type="checkbox" role="switch" class="toggle-switch" data-uplink-config-toggle="flow_control"><span data-uplink-toggle-label="flow_control">Disable</span></label></div>
          <label class="olt-uplink-row"><span>Description</span><input class="olt-uplink-control" type="text" data-uplink-input="description" maxlength="128"></label>
          <label class="olt-uplink-row"><span>Auto Negotiation</span><select class="olt-uplink-control" data-uplink-input="negotiation"><option value="">N/A</option><option value="Auto-Negotiate">Auto</option><option value="Disable">Manual</option></select></label>
          <label class="olt-uplink-row"><span>Speed</span><select class="olt-uplink-control" data-uplink-input="speed"><option value="">N/A</option><option value="100M">100M</option><option value="1G">1G</option><option value="10G">10G</option></select></label>
          <label class="olt-uplink-row"><span>Duplex</span><select class="olt-uplink-control" data-uplink-input="duplex"><option value="">N/A</option><option value="Full">Full</option><option value="Half">Half</option></select></label>
          <div class="olt-uplink-row"><span>Connector</span><strong data-uplink-field="medium">N/A</strong></div>
        </div>
        <section class="olt-uplink-graph" data-uplink-graph hidden>
          <div class="pon-traffic" data-traffic data-traffic-mode="uplink" data-traffic-row-id="<?= $oltUplinkRowId ?>" data-traffic-if-ref="">
            <div class="pon-traffic-grid" data-traffic-grid></div>
            <p class="text-muted" data-traffic-state aria-live="polite" hidden></p>
          </div>
        </section>
      </section>
      <section class="olt-uplink-tab-panel" role="tabpanel" data-uplink-panel="optical" hidden>
        <div class="olt-uplink-detail-grid"><?php foreach($oltUplinkOpticalRows as $field=>$meta): ?><div class="olt-uplink-row"><span><?= h($meta['label']) ?></span><strong data-uplink-field="<?= h($field) ?>">_</strong></div><?php endforeach; ?></div>
      </section>
      <section class="olt-uplink-tab-panel" role="tabpanel" data-uplink-panel="vlan" hidden>
        <div class="olt-uplink-detail-grid">
          <label class="olt-uplink-row"><span>Mode</span><select data-uplink-input="mode"><?php foreach ($oltUplinkVlanModeLabels as $value => $label): ?><option value="<?= h($value) ?>"><?= h($label) ?></option><?php endforeach; ?></select></label>
          <div class="olt-uplink-row"><span>Allowed VLAN</span><div class="olt-uplink-segmented" role="group" aria-label="Jenis Allowed VLAN"><button type="button" class="is-active" data-uplink-vlan-kind="trunk">Trunk</button><button type="button" data-uplink-vlan-kind="access">Access</button></div></div>
          <label class="olt-uplink-row olt-uplink-row-wide"><span data-uplink-allowed-label>Allowed VLAN Trunk</span><input type="text" inputmode="numeric" data-uplink-allowed-vlan placeholder="1-4095"></label>
          <label class="olt-uplink-row"><span>PVID</span><input type="number" min="1" max="4095" data-uplink-input="pvid"></label>
          <div class="olt-uplink-row"><span>Trunk VLAN</span><strong data-uplink-field="tagged_vlan">N/A</strong></div>
          <div class="olt-uplink-row"><span>Access VLAN</span><strong data-uplink-field="untagged_vlan">N/A</strong></div>
        </div>
      </section>
      <div class="olt-uplink-modal-actions"><button class="button secondary" type="button" data-olt-uplink-close>Tutup</button></div>
    </div>
  </div>
</div>
<script type="application/json" data-olt-uplink-data><?= json_for_html_script($oltUplinkPortDetails ?? []) ?></script>
