<?php
$oltTabActions = is_array($oltTabActions ?? null) ? $oltTabActions : [];
if ($oltTabActions !== []):
?>
<div class="olt-tab-actions" role="toolbar">
    <?php foreach ($oltTabActions as $oltTabAction):
        if (!is_array($oltTabAction)) continue;
        $oltTabActionType = (string)($oltTabAction['type'] ?? 'modal');
        $oltTabActionValue = (string)($oltTabAction['value'] ?? '');
        $oltTabActionLabel = (string)($oltTabAction['label'] ?? 'Add');
        if ($oltTabActionValue === '') continue;
    ?>
        <button type="button" class="button secondary small" <?= $oltTabActionType === 'profile'
            ? 'data-olt-speed-add="' . h($oltTabActionValue) . '"'
            : 'data-olt-profile-modal-open="' . h($oltTabActionValue) . '"' ?>><i class="bi bi-plus-circle" aria-hidden="true"></i> <?= h($oltTabActionLabel) ?></button>
    <?php endforeach; ?>
</div>
<?php endif; ?>
