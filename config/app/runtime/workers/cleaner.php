<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'paths.php';
require_once project_path('src', 'helpers.php');
require_once project_path('src', 'load-repository.php');
require_once project_path('app', 'CanonicalJson.php');
require_once project_path('app', 'RuntimeBridge.php');

$config = require project_path('config', 'defaults.php');
$db = new LinnmsRepository((array)$config['database']);
$serviceConfig = require '/config/app/config/service.php';
$deadlinePath = '/config/app/data/core/expired-delete-at';
$pollInterval = 15;

$removeTree = static function (string $directory): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        if ($item->isLink() || $item->isFile()) {
            if (!@unlink($path)) throw new RuntimeException('Tidak dapat menghapus berkas driver.');
        } elseif ($item->isDir() && !@rmdir($path)) {
            throw new RuntimeException('Tidak dapat menghapus direktori driver.');
        }
    }
    if (!@rmdir($directory)) throw new RuntimeException('Folder driver tidak dapat dihapus.');
};

while (true) {
    try {
        $bridge = new RuntimeBridge($db->pdo(), (array)$serviceConfig);
        $state = $bridge->state();
        if (($state['mode'] ?? '') !== 'ended') {
            if (($state['mode'] ?? '') === 'ready' && is_file($deadlinePath)) @unlink($deadlinePath);
            sleep($pollInterval);
            continue;
        }

        $deleteAt = $bridge->cleanupDeadline();
        if ($deleteAt === null) {
            fwrite(STDERR, "[cleaner] Menunggu pemeriksaan berikutnya.\n");
            sleep($pollInterval);
            continue;
        }

        if (time() < $deleteAt) {
            sleep(min($pollInterval, max(1, $deleteAt - time())));
            continue;
        }

        $driverPath = project_path('vendor', 'driver');
        if (!is_dir($driverPath)) {
            fwrite(STDOUT, '[cleaner] Folder driver sudah tidak tersedia' . PHP_EOL);
            exit(0);
        }
        $vendorPath = realpath(project_path('vendor'));
        if ($vendorPath === false || is_link($driverPath) || realpath($driverPath) !== $vendorPath . '/driver') {
            throw new RuntimeException('Folder driver bukan direktori aplikasi yang aman untuk dihapus.');
        }

        $removeTree($driverPath);
        fwrite(STDOUT, '[cleaner] Pembersihan selesai' . PHP_EOL);
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, '[cleaner] ' . $error->getMessage() . PHP_EOL);
        sleep($pollInterval);
    }
}
