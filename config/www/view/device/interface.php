<?php
if(!is_array($deviceDetail)||!is_array($interfaceDetail))return;
$interfaceName=(string)($interfaceDetail['name']??'');
$adminUp=strtolower((string)($interfaceDetail['admin_status']??''))==='up';
$operUp=strtolower((string)($interfaceDetail['oper_status']??''))==='up';
$interfaceRate=is_array($interfaceRates[(string)(int)($interfaceDetail['if_index']??0)]??null)?$interfaceRates[(string)(int)($interfaceDetail['if_index']??0)]:[];
$formatRate=static function(mixed $value):string{if(!is_numeric($value)||(float)$value<0)return 'N/A';$rate=(float)$value;$units=['bps','Kbps','Mbps','Gbps','Tbps'];$unit=0;while($rate>=1000&&$unit<count($units)-1){$rate/=1000;$unit++;}return ($rate>=100?number_format($rate,0):number_format($rate,2)).' '.$units[$unit];};
?>
<section class="panel device-detail-hero" data-device-view data-device-id="<?= (int)$deviceDetail['id'] ?>" data-device-ref="<?= h(public_resource_token('mikrotik', (int)$deviceDetail['id'])) ?>" data-device-type="mikrotik" data-interface-name="<?= h($interfaceName) ?>" data-traffic-active="<?= $operUp ? 'true' : 'false' ?>">
    <div class="device-detail-hero-main">
        <div class="device-detail-icon"><i class="bi bi-ethernet" aria-hidden="true"></i></div>
        <div><h2><?= h($interfaceName) ?></h2><p><?= h(display_text($deviceDetail['name']??null)) ?> &middot; <?= h((string)$deviceDetail['ip_address']) ?></p></div>
    </div>
    <div class="device-detail-hero-meta">
        <div><span>Operasional</span><span class="device-meta-value status <?= $operUp ? 'success' : 'danger' ?>"><?= $operUp?'UP':'DOWN' ?></span></div>
        <div><span>Kecepatan</span><span class="device-meta-value"><?= (int)($interfaceDetail['speed_mbps']??0) ?> Mbps</span></div>
    </div>
</section>

<?php if(!$adminUp):?><div class="notice"><i class="bi bi-info-circle" aria-hidden="true"></i> Interface ini dinonaktifkan secara administratif sehingga trafik dapat bernilai nol.</div><?php endif;?>

<section class="panel mikrotik-traffic-panel">
    <div class="panel-heading"><div><h2>Download dan Upload</h2><p>TX/RX berjalan setiap detik hanya saat halaman aktif.</p></div></div>
    <div class="mikrotik-traffic-chart">
        <div class="mikrotik-traffic-legend">
            <div class="is-rx"><span><i></i>DOWNLOAD / RX</span><span class="traffic-value" data-device-rx><?= h($formatRate($interfaceRate['rx_bps']??null)) ?></span></div>
            <div class="is-tx"><span><i></i>UPLOAD / TX</span><span class="traffic-value" data-device-tx><?= h($formatRate($interfaceRate['tx_bps']??null)) ?></span></div>
            <small data-traffic-poll-state>TX/RX aktif saat halaman digunakan</small>
        </div>
        <div class="mikrotik-traffic-canvas-wrap"><canvas data-mikrotik-traffic-chart aria-label="Grafik download dan upload interface <?= h($interfaceName) ?>"></canvas></div>
        <div class="mikrotik-traffic-chart-footer"><span>60 detik terakhir</span><span><?= h($interfaceName) ?></span></div>
    </div>
</section>

<section class="panel">
    <div class="panel-heading"><div><h2>Detail Interface</h2><p>Informasi interface dari IF-MIB.</p></div></div>
    <div class="device-system-info-list">
        <div><span>Index</span><span class="device-info-value"><?= (int)($interfaceDetail['if_index']??0) ?></span></div>
        <div><span>Nama</span><span class="device-info-value"><?= h($interfaceName) ?></span></div>
        <div><span>Alias</span><span class="device-info-value"><?= h(display_text($interfaceDetail['alias']??null)) ?></span></div>
        <div><span>Admin</span><span class="device-info-value"><?= $adminUp?'Enabled':'Disabled' ?></span></div>
        <div><span>Operasional</span><span class="device-info-value"><?= h(strtoupper((string)($interfaceDetail['oper_status']??'unknown'))) ?></span></div>
        <div><span>MAC</span><span class="device-info-value mono"><?= h(display_text($interfaceDetail['mac']??null)) ?></span></div>
    </div>
</section>
