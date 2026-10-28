<?php

$addOnuView = add_onu_modal_view($addOnuModalData, 'hiosofamily', 'Registrasi ONU', 'Hioso', (array)($addOnuFamilyGroups['hiosofamily'] ?? []));
require __DIR__ . '/../../modal-shell.php';
