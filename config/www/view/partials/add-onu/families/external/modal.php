<?php

$addOnuView = add_onu_modal_view($addOnuModalData, 'external', 'Registrasi ONU', '', (array)($addOnuFamilyGroups['external'] ?? []));
require __DIR__ . '/../../modal-shell.php';
