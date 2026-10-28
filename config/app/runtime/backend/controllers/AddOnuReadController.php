<?php

declare(strict_types=1);

final class AddOnuReadController
{
    public function __construct(private AddOnuReadService $service)
    {
    }

    public function read(int $userId): array
    {
        return $this->service->read($userId);
    }
}
