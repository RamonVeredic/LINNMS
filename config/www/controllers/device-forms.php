<?php

declare(strict_types=1);

function device_add_form_result(int $userId): array
{
    try {
        $pageShell = internal_api_request($userId, 'page.shell.read', ['page' => 'dashboard']);
    } catch (Throwable $error) {
        error_log('[LINNMS] Form tambah perangkat: ' . $error->getMessage());
        return ['status' => 502, 'payload' => ['ok' => false, 'message' => 'Data perangkat tidak dapat dimuat.']];
    }
    extract(device_add_view_model($pageShell), EXTR_SKIP);
    ob_start();
    require project_path('view', 'partials', 'dashboard', 'device-add-form.php');
    $html = (string)ob_get_clean();
    return ['status' => 200, 'payload' => [
        'ok' => true,
        'title' => 'Tambah Perangkat',
        'html' => $html,
    ]];
}

function device_edit_form_result(int $userId, int $rowId): array
{
    if ($rowId < 1) return ['status' => 422, 'payload' => ['ok' => false, 'message' => 'Perangkat tidak valid.']];
    try {
        $context = internal_api_request($userId, 'page.shell.read', ['page' => 'device-edit-form', 'row_id' => $rowId]);
    } catch (Throwable $error) {
        error_log('[LINNMS] Form edit perangkat: ' . $error->getMessage());
        return ['status' => 502, 'payload' => ['ok' => false, 'message' => 'Data perangkat tidak dapat dimuat.']];
    }
    $row = is_array($context['editable_device'] ?? null) ? $context['editable_device'] : null;
    if ($row === null || device_edit_kind($row) === '') {
        return ['status' => 404, 'payload' => ['ok' => false, 'message' => 'Perangkat tidak ditemukan.']];
    }
    if (device_edit_kind($row) === 'olt_api') {
        $editApiOlt = $row;
        ob_start();
        require project_path('view', 'partials', 'dashboard', 'olt-api-edit-form.php');
        $html = (string)ob_get_clean();
        return ['status' => 200, 'payload' => [
            'ok' => true,
            'title' => 'Edit OLT',
            'html' => $html,
        ]];
    }
    $deviceEdit = device_edit_view_model(
        $row,
        is_array($context['olt_driver_flags'] ?? null) ? $context['olt_driver_flags'] : [],
        is_array($context['snmp_driver_flags'] ?? null) ? $context['snmp_driver_flags'] : []
    );
    $deviceEdit = device_edit_apply_old_input($deviceEdit, $rowId);
    ob_start();
    require project_path('view', 'partials', 'dashboard', 'device-edit-form.php');
    $html = (string)ob_get_clean();
    return ['status' => 200, 'payload' => [
        'ok' => true,
        'title' => (string)$deviceEdit['title'],
        'html' => $html,
    ]];
}
