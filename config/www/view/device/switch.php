<?php
if (!is_array($deviceDetail) || (string)($deviceDetail['device_type'] ?? '') !== 'cisco_catalyst_2960s') return;
$deviceViewKind = 'switch';
require __DIR__ . '/partials/format-vlans.php';
require __DIR__ . '/partials/device-view.php';
