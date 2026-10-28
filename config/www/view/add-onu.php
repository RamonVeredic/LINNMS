<?php
require_once __DIR__ . '/partials/add-onu/view.php';
require_once LINNMS_UI_ROOT . '/controllers/olt/add-onu.php';
$addOnuRead = add_onu_read_data((int)current_user_id());
$pendingOnus = is_array($addOnuRead['pending_onus'] ?? null) ? $addOnuRead['pending_onus'] : [];
$onusByOltDevice = is_array($addOnuRead['groups'] ?? null) ? $addOnuRead['groups'] : [];
$addOnuFamilyGroups = is_array($addOnuRead['family_groups'] ?? null) ? $addOnuRead['family_groups'] : [];
$addOnuFamilies = is_array($addOnuRead['families'] ?? null) ? $addOnuRead['families'] : [];
$addOnuModalData = is_array($addOnuRead['modal_data'] ?? null) ? $addOnuRead['modal_data'] : [];
$addOnuFamilyDir = static fn(string $family): string => match ($family) {
    'hiosofamily' => 'hioso',
    'ztefamily' => 'zte',
    default => 'external',
};

?>

<section class="panel add-onu-panel">

    <form method="get" action="<?= h(public_page_url('add-onu')) ?>"  data-add-onu-search-form>
        <input type="hidden" name="page" value="add-onu">
        <div class="table-search">
            <span><i class="bi bi-search" aria-hidden="true"></i></span>
            <input type="search" name="q" value="" placeholder="Cari ..." aria-label="Cari ONU belum terdaftar">
        </div>

    </form>
    <div data-add-onu-results aria-live="polite">
    <?php if ($pendingOnus === []): ?>
        <div class="add-onu-empty empty-state">
            <i class="bi bi-inbox" aria-hidden="true"></i>
            <span>Tidak tersedia</span>
        </div>
    <?php else: ?>
        <div class="add-onu-groups">

        <?php foreach ($onusByOltDevice as $group): ?>
            <?php
            if ($group['onus'] === []) continue;
            $addOnuGroupFamily = (string)($group['family'] ?? 'external');
            $addOnuGroupFile = __DIR__ . '/partials/add-onu/families/' . $addOnuFamilyDir($addOnuGroupFamily) . '/group.php';
            if (!is_file($addOnuGroupFile)) $addOnuGroupFile = __DIR__ . '/partials/add-onu/families/external/group.php';
            require $addOnuGroupFile; ?>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>


<?php


foreach ($addOnuFamilies as $addOnuModalFamily):
    $addOnuModalFile = __DIR__ . '/partials/add-onu/families/' . $addOnuFamilyDir((string)$addOnuModalFamily) . '/modal.php';
    if (!is_file($addOnuModalFile)) $addOnuModalFile = __DIR__ . '/partials/add-onu/families/external/modal.php';
    require $addOnuModalFile;
endforeach;
?>
    </div>
</section>
