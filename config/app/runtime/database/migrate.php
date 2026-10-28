<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('LINNMS_DATABASE_MIGRATION', true);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'paths.php';
require_once project_path('src', 'helpers.php');
require_once project_path('src', 'load-repository.php');

$config = require project_path('config', 'defaults.php');
$database = (array)($config['database'] ?? []);

foreach (['name', 'user', 'password', 'charset'] as $key) {
    if (!array_key_exists($key, $database)) {
        fwrite(STDERR, "Konfigurasi database '{$key}' belum tersedia.\n");
        exit(1);
    }
}

$name = trim((string)$database['name']);
$socket = trim((string)($database['socket'] ?? ''));
$host = trim((string)($database['host'] ?? ''));
$port = (int)($database['port'] ?? 3306);
if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
    fwrite(STDERR, "Nama database tidak valid.\n");
    exit(1);
}

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    if ($socket === '' && $host === '') {
        throw new RuntimeException('DB socket atau host deployment belum dikonfigurasi.');
    }
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('DB port deployment tidak valid.');
    }
    $charset = trim((string)$database['charset']);
    $serverDsn = $socket !== ''
        ? 'mysql:unix_socket=' . $socket . ';charset=' . $charset
        : 'mysql:host=' . $host . ';port=' . $port . ';charset=' . $charset;

    $server = new PDO(
        $serverDsn,
        trim((string)$database['user']),
        (string)$database['password'],
        $options
    );
    $server->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $repository = new LinnmsRepository($database);
    $pdo = $repository->pdo();
    if ((int)$pdo->query("SELECT GET_LOCK('linnms_database_migration',30)")->fetchColumn() !== 1) {
        throw new RuntimeException('Migrasi lain masih berjalan. Coba lagi setelah proses tersebut selesai.');
    }

    try {
        $repository->ensureSchema((array)($config['default_admin'] ?? []));
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('linnms_database_migration')");
    }

    fwrite(STDOUT, "Berhasil goodjob\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Migrasi database LINNMS gagal: ' . $error->getMessage() . "\n");
    exit(1);
}
