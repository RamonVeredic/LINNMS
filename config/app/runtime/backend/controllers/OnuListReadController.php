<?php

declare(strict_types=1);

final class OnuListReadController
{
    public function __construct(private OnuListReadService $service)
    {
    }

    public function read(int $userId, array $params): array
    {
        $input = is_array($params['filters'] ?? null) ? $params['filters'] : [];
        $status = strtolower(trim((string)($input['status'] ?? '')));
        if (!in_array($status, ['online', 'down', 'powerdown', 'loss', 'problem'], true)) $status = '';
        $olt = filter_var($input['olt'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $filters = [
            'olt' => $olt === false ? null : $olt,
            'source' => valid_olt_source($input['source'] ?? null),
            'pon' => mb_substr(trim((string)($input['pon'] ?? '')), 0, 120),
            'status' => $status,
            'q' => mb_substr(trim((string)($input['q'] ?? '')), 0, 100),
        ];
        return $this->service->read(
            $userId,
            $filters,
            max(1, (int)($params['page'] ?? 1)),
            !empty($params['include_lookups'])
        );
    }
}
