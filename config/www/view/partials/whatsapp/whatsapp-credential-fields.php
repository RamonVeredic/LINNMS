<?php

declare(strict_types=1);

$whatsappDefinition = is_array($whatsappDefinition ?? null) ? $whatsappDefinition : [];
$whatsappUserSettings = is_array($whatsappUserSettings ?? null) ? $whatsappUserSettings : [];

$whatsappFieldOrder = ['base_url', 'device'];
$whatsappDefaultLabels = [
    'base_url' => 'URL API / Endpoint',
    'token' => 'Token / API Key',
    'device' => 'Device ID',
];
$whatsappFieldLimits = ['base_url' => 190, 'token' => 120, 'device' => 120];
$whatsappFieldPlaceholders = [
    'base_url' => 'http://192.0.0.2:21465',
    'device' => 'device-id',
];

$whatsappFields = (array)($whatsappDefinition['fields'] ?? []);
$whatsappTokenGenerated = !empty($whatsappDefinition['token_generator']['enabled']);
foreach ($whatsappFieldOrder as $whatsappFieldKey):
    if (!array_key_exists($whatsappFieldKey, $whatsappFields)) {
        continue;
    }
    $whatsappField = (array)$whatsappFields[$whatsappFieldKey];
    $whatsappFieldSaved = trim((string)($whatsappUserSettings[$whatsappFieldKey] ?? '')) !== '';

    $whatsappFieldRequired = !empty($whatsappField['required']) && !$whatsappFieldSaved;
    $whatsappFieldLabel = trim((string)($whatsappField['label'] ?? '')) ?: $whatsappDefaultLabels[$whatsappFieldKey];
    ?>
    <div class="field" data-whatsapp-field="<?= h($whatsappFieldKey) ?>">
        <label for="whatsappField<?= h(ucfirst($whatsappFieldKey)) ?>"><?= h($whatsappFieldLabel) ?></label>
        <input
            id="whatsappField<?= h(ucfirst($whatsappFieldKey)) ?>"
            type="<?= $whatsappFieldKey === 'token' ? 'password' : 'text' ?>"
            name="<?= h('whatsapp_' . $whatsappFieldKey) ?>"
            autocomplete="off"
            value=""
            maxlength="<?= (int)($whatsappFieldLimits[$whatsappFieldKey] ?? 120) ?>"
            data-whatsapp-field-saved="<?= $whatsappFieldSaved ? '1' : '0' ?>"
            <?= $whatsappFieldKey === 'token' ? 'data-whatsapp-token-input' : '' ?>
            <?= $whatsappFieldRequired ? 'required' : '' ?>
            placeholder="<?= h($whatsappFieldPlaceholders[$whatsappFieldKey] ?? $whatsappFieldLabel) ?>"
        >
        <small class="field-help"><?= h(trim((string)($whatsappField['hint'] ?? ''))) ?></small>
    </div>
    <?php
endforeach;

if (!empty($whatsappDefinition['token_generator']['enabled'])):
?>
    <div class="field" data-whatsapp-generator-fields><label for="whatsappGeneratorSession">Nama Session</label><input id="whatsappGeneratorSession" name="whatsapp_generator_session" autocomplete="off" maxlength="80" placeholder="Masukkan nama session" data-whatsapp-generator-session></div>
    <div class="field" data-whatsapp-generator-fields><label for="whatsappGeneratorSecret">Secret Key</label><input id="whatsappGeneratorSecret" type="password" name="whatsapp_generator_secret" autocomplete="off" maxlength="120" placeholder="Masukkan secret key" data-whatsapp-generator-secret></div>
<?php
endif;

if (array_key_exists('token', $whatsappFields)):
    $whatsappFieldKey = 'token';
    $whatsappField = (array)$whatsappFields[$whatsappFieldKey];
    $whatsappFieldSaved = trim((string)($whatsappUserSettings[$whatsappFieldKey] ?? '')) !== '';
    $whatsappFieldRequired = !empty($whatsappField['required']) && !$whatsappFieldSaved;
    $whatsappFieldLabel = trim((string)($whatsappField['label'] ?? '')) ?: $whatsappDefaultLabels[$whatsappFieldKey];
?>
    <div class="field" data-whatsapp-field="token">
        <label for="whatsappFieldToken"><?= h($whatsappFieldLabel) ?></label>
        <div class="input-button-group" data-whatsapp-token-group>
            <input id="whatsappFieldToken" type="text" name="whatsapp_token" autocomplete="off" value=""
                maxlength="<?= (int)$whatsappFieldLimits['token'] ?>"
                data-whatsapp-field-saved="<?= $whatsappFieldSaved ? '1' : '0' ?>"
                data-whatsapp-token-input <?= $whatsappFieldRequired ? 'required' : '' ?>
                <?= $whatsappTokenGenerated ? 'readonly' : '' ?>
                placeholder="<?= h($whatsappFieldLabel) ?>">
            <?php if ($whatsappTokenGenerated): ?>
                <button class="button secondary" type="button" data-whatsapp-generate-token><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i>Buat</button>
            <?php endif; ?>
        </div>
        <small class="field-help"><?= h(trim((string)($whatsappField['hint'] ?? ''))) ?></small>
    </div>
<?php
endif;

unset(
    $whatsappFieldOrder,
    $whatsappDefaultLabels,
    $whatsappFieldLimits,
    $whatsappFields,
    $whatsappFieldKey,
    $whatsappField,
    $whatsappFieldSaved,
    $whatsappFieldRequired,
    $whatsappFieldLabel
);
