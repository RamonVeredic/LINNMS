<?php
if (!is_array($oltDetail)) {
    ?>
    <section class="panel">
        <div class="empty-state">OLT tidak ditemukan.</div>
    </section>
    <?php
    return;
}



$oltDetailViewModelPath = project_path('view', 'shared', 'models', 'OltDetailViewModel.php');
if (is_file($oltDetailViewModelPath)) require_once $oltDetailViewModelPath;
require_once __DIR__ . '/partials/olt/view.php';
require_once LINNMS_UI_ROOT . '/controllers/olt/view-olt.php';
$oltReadData = olt_view_read_data((int)current_user_id(), (int)$oltDetail['id']);
extract($oltReadData, EXTR_OVERWRITE);
$oltVlanPreferAdminState = empty($oltViewCapabilities['vlan_device']);
$oltOnuTypeManager = OltDetailViewModel::onuTypeManager(
    (int)$oltDetail['id'], $oltViewCapabilities,
    (array)($oltOnuTypes ?? [])
);
$oltViewTemplate = is_array($oltViewDefinition) ? trim((string)($oltViewDefinition['olt_view_template'] ?? '')) : '';
if ($oltViewTemplate !== '' && preg_match('~^view-olt/partials/vendor/(external|hioso|zte)\.php$~i', $oltViewTemplate) === 1) {
    $oltViewPath = __DIR__ . '/partials/olt/vendor/' . basename($oltViewTemplate);
    if (is_file($oltViewPath)) {
        require $oltViewPath;
        return;
    }
}

?>
<section class="panel">
    <div class="empty-state">Tampilan OLT belum tersedia.</div>
</section>
<?php
return;
