<form method="post" id="<?= h($ponModalId) ?>-status-form" class="olt-pon-status-form">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="olt_row_id" value="<?= (int)$oltDetail['id'] ?>">
    <input type="hidden" name="olt_port_number" value="<?= $ponNumber > 0 ? $ponNumber : 1 ?>">
    <input type="hidden" name="olt_port_ref" value="<?= h($ponRef) ?>">
</form>
