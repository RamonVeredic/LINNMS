<?php

declare(strict_types=1);

final class ConnectionTestController
{
    public function __construct(
        private LinnmsRepository $db,
        private array $config,
        private RuntimeBridge $bridge
    )
    {
    }

    public function test(int $userId, string $type, array $params): array
    {
        if ($userId < 1) throw new InvalidArgumentException('User API internal tidak valid.');
        return match ($type) {
            'device' => (new DeviceConnectionTestService(
                $this->db,
                $this->config,
                new ApiClientFactory($this->config, new ApiRequestLogger($this->db)),
                new CredentialCipher((string)($this->config['credential_key'] ?? ''))
            ))->test($userId, $params),
            'telegram' => (new TelegramActionService($this->db, new TelegramNotifier(), $this->bridge))
                ->connectionTest($userId, $params),
            'whatsapp' => (new WhatsAppActionService($this->db, $this->bridge))
                ->connectionTest($userId, $params),
            default => ['ok' => false, 'code' => 'unsupported', 'data' => []],
        };
    }
}
