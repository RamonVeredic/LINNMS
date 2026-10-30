<?php

declare(strict_types=1);

require_once __DIR__ . '/internal-api-client.php';

function application_log_event(?int $userId, string $area, string $action, string $level, string $message, array $context = [], ?string $actor = null, ?string $ipAddress = null): void
{
    static $writing = false;
    if ($writing) return;
    $writing = true;
    try {
        internal_api_request(max(0, (int)$userId), 'app.log.write', [
            'area' => $area,
            'action' => $action,
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'actor' => $actor,
            'ip_address' => $ipAddress ?? (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        ], 3);
    } catch (Throwable $error) {
        error_log('[LINNMS] Log API: ' . $error->getMessage());
    } finally {
        $writing = false;
    }
}

function application_log_error(Throwable $error, string $action = 'application_error'): void
{
    static $fingerprints = [];
    $fingerprint = get_class($error) . '|' . $error->getMessage() . '|' . $error->getFile() . '|' . $error->getLine();
    if (isset($fingerprints[$fingerprint])) return;
    $fingerprints[$fingerprint] = true;
    application_log_event(current_user_id() ?: null, 'LINNMS APP', $action, 'error', (string)$error,
        [], current_username() ?: null);
    $GLOBALS['LINNMS_ORIGINAL_ERROR_LOGGED'] = true;
}

function register_application_error_logging(): void
{
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if ((error_reporting() & $severity) === 0) return false;
        application_log_error(new ErrorException($message, 0, $severity, $file, $line), 'php_error');
        return false;
    });
    register_shutdown_function(static function (): void {
        $fatal = error_get_last();
        if (!is_array($fatal) || !in_array((int)($fatal['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
        application_log_error(new ErrorException(
            (string)($fatal['message'] ?? 'Fatal error tanpa pesan.'), 0, (int)$fatal['type'],
            (string)($fatal['file'] ?? ''), (int)($fatal['line'] ?? 0)
        ), 'php_fatal_error');
    });
}
