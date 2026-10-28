<?php



$editRowId = valid_positive_id($_GET['id'] ?? null);
if (!is_array($editDeviceRow) || device_edit_kind($editDeviceRow) === '') {
    flash('error', 'Perangkat tidak ditemukan.');
    redirect(public_page_url('dashboard'));
}

$deviceEdit = device_edit_view_model(
    $editDeviceRow,
    is_array($pageShell['olt_driver_flags'] ?? null) ? $pageShell['olt_driver_flags'] : [],
    is_array($pageShell['snmp_driver_flags'] ?? null) ? $pageShell['snmp_driver_flags'] : []
);


$oldEditDevice = is_array($_SESSION['old_edit_device'] ?? null) ? $_SESSION['old_edit_device'] : [];
if ($oldEditDevice !== []) {
    $editTextFields = [
        'device_name',
        'ip_address',
        'driver_id',
        'snmp_port',
        'community',
        'ports',
        'username',
    ];
    foreach ($editTextFields as $editField) {
        if (array_key_exists($editField, $oldEditDevice)) {
            $deviceEdit['form'][$editField] = (string)$oldEditDevice[$editField];
        }
    }
    $deviceEdit['form']['hybrid_status'] = (string)($oldEditDevice['hybrid_status'] ?? '0');
}
unset($_SESSION['old_edit_device']);

$deviceEdit['cancel_url'] = device_edit_view_url($deviceEdit);
require project_path('pages', 'partials', 'edit-device', 'device-edit-form.php');
