<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/paths.php';
require project_path('app', 'bootstrap.php');

$force = in_array('--force', $argv ?? [], true);
$daemon = in_array('--daemon', $argv ?? [], true);

if (!$daemon) {
    $changed = $bridge->refresh($force);
    if ($force) {
        $state = $bridge->state();
        $status = (string)($state['mode'] ?? 'unknown');
        fwrite(
            STDOUT,
            ($changed ? 'State diperbarui.' : 'Koneksi gagal diperbarui.')
            . ' status=' . $status . PHP_EOL
        );
    }
    exit(0);
}

$socketPath = $bridge->refreshScheduleSignalPath();
@unlink($socketPath);
$errno = 0;
$error = '';
$server = @stream_socket_server(
    'unix://' . $socketPath,
    $errno,
    $error,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
);
if (!is_resource($server)) {
    fwrite(STDERR, '[worker] Kanal pembaruan jadwal tidak dapat dibuat: ' . ($error !== '' ? $error : (string)$errno) . PHP_EOL);
    exit(1);
}
@chmod($socketPath, 0660);
register_shutdown_function(static function () use ($server, $socketPath): void {
    if (is_resource($server)) @fclose($server);
    @unlink($socketPath);
});

while (true) {
    $waitSeconds = $bridge->secondsUntilRefresh();
    if ($waitSeconds !== null && $waitSeconds <= 0) {
        $bridge->refresh(false);
        continue;
    }

    $read = [$server];
    $write = [];
    $except = [];
    $ready = @stream_select($read, $write, $except, $waitSeconds);
    if ($ready === false) {
        fwrite(STDERR, '[worker] Penantian jadwal pembaruan terganggu.' . PHP_EOL);
        exit(1);
    }
    if ($ready === 0) {
        $bridge->refresh(false);
        continue;
    }

    $connection = @stream_socket_accept($server, 0);
    if (is_resource($connection)) {
        @fread($connection, 64);
        @fclose($connection);
    }
}
