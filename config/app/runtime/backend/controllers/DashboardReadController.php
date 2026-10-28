<?php

declare(strict_types=1);

final class DashboardReadController
{
    public function __construct(private DashboardReadService $service)
    {
    }

    public function read(int $userId, array $params): array
    {
        if ($userId < 1) throw new InvalidArgumentException('User API internal tidak valid.');
        return $this->service->read($userId, $params);
    }
}
