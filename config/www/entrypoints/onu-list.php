<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/paths.php';
require_once LINNMS_UI_ROOT . '/app/bootstrap.php';
require_once project_path('view', 'bootstrap.php');
require_once project_path('view', 'partials', 'onu-list', 'view.php');
require_once LINNMS_UI_ROOT . '/controllers/olt/onu-list.php';
require_once LINNMS_UI_ROOT . '/app/application-log.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$respond = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (linnms_enforce_idle_session(false)) {
    $respond(['ok' => false, 'message' => 'Sesi aplikasi telah berakhir.'], 401);
}
if (!is_logged_in()) {
    $respond(['ok' => false, 'message' => 'Sesi aplikasi telah berakhir.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $respond(['ok' => false, 'message' => 'Method tidak didukung.'], 405);
}

$userId = current_user_id();
session_write_close();

try {
    $filters = onu_list_filters_from_request();
    $pageNo = max(1, (int)($_GET['p'] ?? 1));
    $list = onu_list_read_data($userId, $filters, $pageNo);
    $onus = $list['rows'];

    ob_start();
    require project_path('view', 'partials', 'onu-list', 'table-rows.php');
    $rowsHtml = (string)ob_get_clean();

    ob_start();
    render_numbered_pagination([
        'page' => $list['page'],
        'pages' => $list['pages'],
        'total' => $list['total'],
        'url' => static fn(int $p): string => onu_list_filter_url($filters, $p),
        'goto' => 'data-onu-goto',
        'label' => 'Halaman ONU',
        'class' => 'onu-pagination',
    ]);
    $paginationHtml = (string)ob_get_clean();

    $respond([
        'ok' => true,
        'rows_html' => $rowsHtml,
        'pagination_html' => $paginationHtml,
        'stats' => $list['stats'],
        'total' => $list['total'],
        'page' => $list['page'],
        'pages' => $list['pages'],
        'url' => onu_list_filter_url($filters, $list['page']),
    ]);
} catch (Throwable $e) {
    application_log_error($e, 'onu_list');
    error_log('ONU list failed: ' . $e->getMessage());
    $respond(['ok' => false, 'message' => 'Data ONU tidak dapat dimuat.'], 502);
}
