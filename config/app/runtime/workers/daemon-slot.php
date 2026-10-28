<?php

declare(strict_types=1);


function linnms_acquire_daemon_slot(string $role, string $environmentName): bool
{
    $slot = trim((string)(getenv($environmentName) ?: ''));
    if ($slot === '') return true;
    if (preg_match('/^[A-Za-z0-9_.-]+$/', $slot) !== 1) {
        throw new RuntimeException('Identitas slot daemon tidak valid.');
    }

    $lockDirectory = function_exists('lock_path') && function_exists('ensure_data_directory')
        ? ensure_data_directory(lock_path())
        : rtrim(sys_get_temp_dir(), '/\\');
    $prefix = $lockDirectory . DIRECTORY_SEPARATOR . 'daemon-' . $role . '-' . $slot;
    $lock = @fopen($prefix . '.lock', 'c+');
    if (!is_resource($lock)) {
        throw new RuntimeException('Lock slot daemon tidak dapat dibuka: ' . $prefix . '.lock');
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        fwrite(STDERR, 'Slot daemon ' . $role . '/' . $slot . " masih aktif.\n");
        return false;
    }

    $pidPath = $prefix . '.pid';
    if (@file_put_contents($pidPath, (string)getmypid() . PHP_EOL, LOCK_EX) === false) {
        flock($lock, LOCK_UN);
        fclose($lock);
        throw new RuntimeException('PID slot daemon tidak dapat ditulis: ' . $pidPath);
    }
    @chmod($pidPath, 0644);

    register_shutdown_function(static function () use ($lock, $pidPath): void {
        @unlink($pidPath);
        flock($lock, LOCK_UN);
        fclose($lock);
    });
    return true;
}