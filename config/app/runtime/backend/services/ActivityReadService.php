<?php

declare(strict_types=1);

final class ActivityReadService
{
    public function __construct(private LinnmsRepository $db)
    {
    }

    public function read(int $userId, array $params): array
    {
        $pageSize = LinnmsRepository::ACTIVITY_FEED_PAGE_SIZE;
        $search = mb_substr(trim((string)($params['activity_q'] ?? '')), 0, 100);
        $device = mb_substr(trim((string)($params['activity_device'] ?? '')), 0, 100);
        $total = $this->db->countRecentOnuActivityEvents($userId, 24, $search, $device);
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = max(1, min($pages, (int)($params['activity_page'] ?? 1)));
        $events = $this->db->getRecentOnuActivityEvents(
            $userId,
            $pageSize,
            24,
            ($page - 1) * $pageSize,
            $search,
            $device
        );

        return [
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'page_size' => $pageSize,
            'search' => $search,
            'device' => $device,
            'events' => array_map(fn(array $event): array => $this->row($event), $events),
        ];
    }

    private function row(array $event): array
    {
        $parts = activity_event_parts($event);
        $ponEvent = null;
        if (isset($parts['pon_event_name'], $parts['pon_event_ref'], $parts['pon_event_note'])) {
            $note = (string)$parts['pon_event_note'];
            $ponEvent = [
                'name' => (string)$parts['pon_event_name'],
                'ref' => (string)$parts['pon_event_ref'],
                'status' => (string)$parts['pon_event_status'],
                'old_power' => (string)$parts['pon_event_old_power'],
                'new_power' => (string)$parts['pon_event_new_power'],
                'note' => $note,
                'note_class' => strcasecmp($note, 'error') === 0 ? 'text-danger' : '',
            ];
        }

        $hasOnuIdentity = activity_event_has_onu_identity($event);
        $isOnu = strtoupper(trim((string)($event['entity_type'] ?? ''))) === 'ONU';

        return [
            'created_at' => (string)($event['created_at'] ?? ''),
            'subject' => trim((string)($parts['subject'] ?? '')),
            'detail' => activity_event_detail_text($parts['detail'] ?? ''),
            'status_key' => (string)($parts['status_key'] ?? 'neutral'),
            'show_identity' => $hasOnuIdentity,
            'name' => $hasOnuIdentity
                ? display_onu_name($event['onu_name_display'] ?? $event['current_onu_name'] ?? $event['event_onu_name'] ?? null)
                : '',
            'serial' => $isOnu
                ? display_mac_or_serial($event['onu_serial_display'] ?? $event['current_onu_serial'] ?? null, '—')
                : '—',
            'old_power_display' => isset($parts['old_power_display']) ? (string)$parts['old_power_display'] : '',
            'new_power_display' => isset($parts['new_power_display']) ? (string)$parts['new_power_display'] : '',
            'new_power_trend' => isset($parts['new_power_trend']) ? (string)$parts['new_power_trend'] : '',
            'pon_event' => $ponEvent,
        ];
    }
}
