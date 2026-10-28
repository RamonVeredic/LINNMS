<td class="interface-record-cell">
    <label class="pon-catat-switch" title="Simpan riwayat trafik interface ini">
        <input type="checkbox" role="switch" data-traffic-record
               data-device-id="<?= (int)($deviceDetail['id'] ?? 0) ?>"
               data-if-ref="<?= h($interfaceName) ?>"
               data-csrf="<?= h(csrf_token()) ?>"<?= isset($deviceTrafficSelected[$interfaceName]) ? ' checked' : '' ?>>
    </label>
</td>
