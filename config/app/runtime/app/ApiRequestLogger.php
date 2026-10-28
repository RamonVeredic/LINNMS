<?php

declare(strict_types=1);

final class ApiRequestLogger
{
    public function __construct(private readonly LinnmsRepository $repository)
    {
    }

    public function log(int $userId, array $result): void
    {
        if (!empty($result['ok'])) return;

        $error = trim((string)($result['error'] ?? ''));
        $status = (int)($result['status'] ?? 0);
        if ($error === '') {
            $error = $status > 0
                ? 'HTTP ' . $status
                : 'Request tidak menghasilkan respons.';
        }
        $body = $result['body'] ?? null;
        $response = $body !== null
            ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : (string)($result['raw'] ?? '');
        if (is_string($response) && $response !== '' && $response !== $error) {
            $error .= "\n" . $response;
        }

        $this->repository->logApplication(
            $userId,
            'API',
            'communication_error',
            'error',
            $error
        );
    }
}
