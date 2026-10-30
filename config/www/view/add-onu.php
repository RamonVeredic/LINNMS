<section class="panel add-onu-panel">

    <form method="get" action="<?= h(public_page_url('add-onu')) ?>"  data-add-onu-search-form>
        <input type="hidden" name="page" value="add-onu">
        <div class="table-search">
            <span><i class="bi bi-search" aria-hidden="true"></i></span>
            <input type="search" name="q" value="" placeholder="Cari ..." aria-label="Cari ONU belum terdaftar">
        </div>

    </form>
    <div data-add-onu-results aria-live="polite">
    <?php if ($addOnuPage['pending_onus'] === []): ?>
        <div class="add-onu-empty empty-state">
            <i class="bi bi-inbox" aria-hidden="true"></i>
            <span>Tidak tersedia</span>
        </div>
    <?php else: ?>
        <div class="add-onu-groups">

        <?php foreach ($addOnuPage['groups'] as $group): ?>
            <?php
            if ($group['onus'] === []) continue;
            $addOnuFamily = (string)($group['family'] ?? 'external');
            require __DIR__ . '/partials/add-onu/group.php'; ?>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>


<?php


foreach ($addOnuPage['modal_views'] as $addOnuView):
    require __DIR__ . '/partials/add-onu/modal-shell.php';
endforeach;
?>
    </div>
</section>
