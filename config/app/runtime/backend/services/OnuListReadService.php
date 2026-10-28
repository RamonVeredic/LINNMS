<?php

declare(strict_types=1);

final class OnuListReadService
{
    public function __construct(private LinnmsRepository $db)
    {
    }

    public function read(int $userId, array $filters, int $page, bool $includeLookups = false, int $perPage = 50): array
    {
        if ($userId < 1) throw new InvalidArgumentException('User API internal tidak valid.');
        $perPage = max(1, min(200, $perPage));
        $page = max(1, $page);
        $problemMap = [];
        foreach ($this->db->getProblemOnus($userId, 1000) as $problem) {
            if (!is_array($problem)) continue;
            $identity = strtolower(trim((string)($problem['source'] ?? '')))
                . '|' . (int)($problem['olt_id'] ?? 0)
                . '|' . trim((string)($problem['onu_index'] ?? ''));
            $problemMap[$identity] = $problem;
        }
        $problemIds = [];
        foreach ($problemMap as $problem) {
            $id = (int)($problem['id'] ?? 0);
            if ($id > 0) $problemIds[] = $id;
        }

        $baseFilters = $filters;
        unset($baseFilters['status'], $baseFilters['ids']);
        $queryFilters = $baseFilters;
        if (($filters['status'] ?? '') === 'problem') {
            $queryFilters['ids'] = $problemIds;
        } elseif (($filters['status'] ?? '') !== '') {
            $queryFilters['status'] = (string)$filters['status'];
        }

        $stats = [
            'total' => $this->db->countOnusList($userId, $baseFilters),
            'online' => $this->db->countOnusList($userId, $baseFilters + ['status' => 'online']),
            'down' => $this->db->countOnusList($userId, $baseFilters + ['status' => 'down']),
            'power_down' => $this->db->countOnusList($userId, $baseFilters + ['status' => 'powerdown']),
            'loss' => $this->db->countOnusList($userId, $baseFilters + ['status' => 'loss']),
            'problem' => $this->db->countOnusList($userId, $baseFilters + ['ids' => $problemIds]),
        ];
        $total = ($filters['status'] ?? '') !== ''
            ? $this->db->countOnusList($userId, $queryFilters) : $stats['total'];
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $rows = $this->db->getOnusListPage($userId, $queryFilters, $perPage, ($page - 1) * $perPage);

        foreach ($rows as &$onu) {
            $identity = strtolower(trim((string)($onu['source'] ?? '')))
                . '|' . (int)($onu['olt_id'] ?? 0)
                . '|' . trim((string)($onu['onu_index'] ?? ''));
            $problem = $problemMap[$identity] ?? null;
            $onu['is_problem'] = is_array($problem) ? 1 : 0;
            $onu['problem_since'] = is_array($problem) ? ($problem['problem_since'] ?? null) : null;
            $onu['problem_reason'] = is_array($problem) ? ($problem['problem_reason'] ?? null) : null;
            $onu['problem_description'] = is_array($problem) ? ($problem['problem_description'] ?? null) : null;
            $detailFields = json_decode((string)($onu['detail_json'] ?? ''), true);
            $detailFields = is_array($detailFields) ? $detailFields : [];
            $definition = monitoring_driver_definition(device_driver_type($onu)) ?? [];
            $onu['rx_power_supported'] = array_key_exists('rx_power_raw', $detailFields)
                || array_key_exists('rx_power', (array)($detailFields['device_response'] ?? []))
                || !empty(DataOnuView::fieldSupport($definition)['rx_power']);
        }
        unset($onu);

        $result = [
            'rows' => array_values($rows),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per' => $perPage,
            'filters' => $queryFilters,
            'stats' => $stats,
        ];
        if ($includeLookups) {
            $olts = $this->db->getOlts($userId);
            $result['summary'] = [
                'total' => $this->db->countOnusList($userId, []),
                'online' => $this->db->countOnusList($userId, ['status' => 'online']),
                'down' => $this->db->countOnusList($userId, ['status' => 'down']),
                'power_down' => $this->db->countOnusList($userId, ['status' => 'powerdown']),
                'loss' => $this->db->countOnusList($userId, ['status' => 'loss']),
                'problem' => $this->db->countOnusList($userId, ['ids' => array_map('intval', array_values($problemIds))]),
            ];
            $result['has_zte'] = !empty(array_filter($olts, static fn(array $olt): bool => monitoring_device_is_zte($olt)));
            $result['olts'] = array_map(static fn(array $olt): array => [
                'id' => (int)($olt['id'] ?? 0),
                'name' => (string)($olt['name'] ?? ''),
                'ip_address' => (string)($olt['ip_address'] ?? ''),
            ], $olts);
            $result['pon_options'] = $this->db->getOltPonFilterOptions($userId);
        }
        return $result;
    }
}
