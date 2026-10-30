<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';

function linnms_onu_registration_redirect(string $page, int $rowId = 0): never
{
    redirect(public_page_url($page, $rowId > 0 ? ['id' => $rowId] : []));
}

function handle_onu_registration_action(string $action): bool
{
    if (!in_array($action, ['register_onu', 'pre_register_onu', 'unregister_onu', 'replace_onu_registration'], true)) return false;
    if (!is_logged_in()) redirect('?page=login');
    $rowId = valid_positive_id($_POST['onu_row_id'] ?? null) ?? 0;
    $data = $_POST;
    unset($data['action'], $data['csrf']);
    try {
        $result = internal_api_request(current_user_id(), 'onu.registration.action',
            ['action' => $action, 'data' => $data], 180);
    } catch (Throwable $error) {
        error_log('[LINNMS] ONU registration API: ' . $error->getMessage());
        $result = ['ok' => false, 'code' => 'internal'];
    }
    $code = (string)linnms_action_reason($result, 'internal');
    $resultData = is_array($result['data'] ?? null) ? $result['data'] : [];
    $targetRowId = (int)($resultData['row_id'] ?? $rowId);
    $notices = is_array($resultData['notices'] ?? null) ? $resultData['notices'] : [];
    if ($notices === []) $notices = [['level' => 'error', 'message' => 'Registrasi ONU gagal.']];
    foreach ($notices as $notice) {
        if (!is_array($notice)) continue;
        flash((string)($notice['level'] ?? 'error'), (string)($notice['message'] ?? 'Registrasi ONU gagal.'));
    }
    if (!empty($result['ok'])) {
        linnms_onu_registration_redirect($action === 'unregister_onu' ? 'add-onu' : 'view-onu', $targetRowId);
    }
    if ($action === 'unregister_onu') {
        linnms_onu_registration_redirect($code === 'onu_invalid' || $code === 'onu_not_found' ? 'onus' : 'view-onu', $targetRowId);
    }
    if ($action === 'replace_onu_registration') {
        linnms_onu_registration_redirect($targetRowId > 0 ? 'view-onu' : 'onus', $targetRowId);
    }
    if ($code === 'already_registered' || $code === 'profile_after_device') {
        linnms_onu_registration_redirect('view-onu', $targetRowId);
    }
    linnms_onu_registration_redirect('add-onu');
}
