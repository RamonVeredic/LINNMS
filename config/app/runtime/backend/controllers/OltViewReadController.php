<?php

declare(strict_types=1);

final class OltViewReadController
{
    public function __construct(private OltViewReadService $service)
    {
    }

    public function read(int $userId, array $params): array
    {
        $rowId = filter_var($params['row_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($userId < 1 || $rowId === false || $rowId === null) {
            throw new InvalidArgumentException('Identitas OLT tidak valid.');
        }
        return $this->service->read($userId, $rowId);
    }
}
