<?php

$addOnuView = add_onu_modal_view($addOnuModalData, 'ztefamily', 'Registrasi ONU', 'ZTE', (array)($addOnuFamilyGroups['ztefamily'] ?? []));
require __DIR__ . '/../../modal-shell.php';
