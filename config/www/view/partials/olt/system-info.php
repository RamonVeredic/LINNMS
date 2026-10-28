<?php

$systemInfoData = is_array($oltSystem ?? null) ? $oltSystem : [];
$systemInfoText = static function (mixed $value): string {
    if (is_bool($value)) return $value ? 'Ya' : 'Tidak';
    if (is_int($value) || is_float($value)) return is_finite((float)$value) ? (string)$value : '-';
    if (!is_string($value)) return '-';
    $text = trim($value);
    return $text !== '' && !in_array(strtolower($text), ['n/a', 'na', 'null', '...'], true) ? $text : '-';
};
$systemInfoFields = [
    'system_name' => ['Nama Perangkat', ''],
    'location' => ['Lokasi', ''],
    'model' => ['Model', ''],
    'vendor' => ['Vendor', ''],
    'software_version' => ['Software', ''],
    'serial' => ['Serial Number', 'mono'],
    'mac' => ['MAC', 'mono'],
    'ip_address' => ['IP', 'mono'],
    'current_time' => ['Device Time', ''],
];
$deviceOverviewDetails = [];
foreach ($systemInfoFields as $systemInfoKey => [$systemInfoLabel, $systemInfoClass]) {
    $deviceOverviewDetails[] = [
        'label' => $systemInfoLabel,
        'value' => $systemInfoKey === 'model'
            ? display_model_chars($systemInfoText($systemInfoData[$systemInfoKey] ?? null), '-')
            : $systemInfoText($systemInfoData[$systemInfoKey] ?? null),
        'class' => $systemInfoClass,
    ];
}
$deviceDetailPanelId = 'olt-system-info';
require __DIR__ . '/../../partials/device-detail-panel.php';
