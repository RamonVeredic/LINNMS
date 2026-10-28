<section class="panel">
    <form method="post" data-device-delete-form>
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete_device">
        <input type="hidden" name="device_id" value="<?= (int)$deviceDetail['id'] ?>">
        <button class="button button-danger"><i class="bi bi-trash3"></i> Hapus</button>
    </form>
</section>
