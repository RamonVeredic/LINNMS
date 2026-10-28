<?php
if (!is_array($deviceDetail) || (string)($deviceDetail['device_type'] ?? '') !== 'mikrotik') return;
$deviceViewKind = 'mikrotik';
require __DIR__ . '/partials/device-view.php';
