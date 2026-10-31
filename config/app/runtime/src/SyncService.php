<?php

declare(strict_types=1);

final class SyncService
{
    private ?OltSnapshotWriteService $snapshotWriter = null;

    public function __construct(
        private readonly array $config,
        private readonly LinnmsRepository $db,
        private readonly TelegramNotifier $telegram,
        private readonly ApiClientFactory $apiClients,
        private readonly ?RuntimeBridge $bridge = null,
    ) {
    }

    
    public function syncUser(int $userId, bool $recordChanges = true, ?callable $yieldAfterDevice = null): array
    {
        $settings = $this->db->getUserSettings($userId);
        
        $client = $this->apiClients->forUser($userId);
        if ($client === null) {
            $message = 'Layanan akun belum tersedia. Silakan hubungi pengelola.';
            $this->db->setLastSync($userId, 'error', $message);
            return ['ok'=>false,'message'=>$message,'olts'=>0,'onus'=>0,'odps'=>0,'changes'=>0,'failures'=>1,'telegram'=>''];
        }
        $adapter = $this->externalAdapter($client);

        $isBaseline = empty($settings['last_sync_at']);
        $recordChanges = $recordChanges && !$isBaseline;
        $signalThreshold = effective_signal_threshold_dbm($settings);

        $events = [];
        $oltCount = 0;
        $onuCount = 0;
        $failures = 0;
        $pendingOnuResults = 0;

        $oltResult = $adapter->fetchOlts();
        if (empty($oltResult['ok'])) {
            $failures++;
            $connectionMessage = (string)($oltResult['message'] ?? '');
            $message = 'Data jaringan tidak dapat diperbarui. ' . $connectionMessage;
            $this->db->setLastSync($userId,'error',$message);
            return ['ok'=>false,'message'=>$message,'olts'=>0,'onus'=>0,'odps'=>0,'changes'=>0,'failures'=>$failures,'telegram'=>''];
        }

        $ignoredOltIds = array_fill_keys($this->db->getIgnoredApiOltIds($userId), true);
        $oltItems = array_values(array_filter(
            (array)($oltResult['olts'] ?? []),
            static function (mixed $olt) use ($ignoredOltIds): bool {
                if (!is_array($olt)) return false;
                $oltId = valid_positive_id($olt['id'] ?? $olt['olt_id'] ?? null);
                $type = strtolower(trim((string)($olt['olt_type'] ?? $olt['type'] ?? '')));
                $isDirectlyManagedBrand = str_contains($type, 'hioso') || str_contains($type, 'hisfocus');
                return $oltId !== null && !isset($ignoredOltIds[$oltId]) && !$isDirectlyManagedBrand;
            },
        ));
        $oltCount = count($oltItems);
        $events = array_merge($events, $this->snapshotWriter()->writeDriverExternalOltInventory($userId, $oltItems, $recordChanges));
        $scanStateByOlt = [];
        foreach ($this->db->getOlts($userId, false) as $storedOlt) {
            if ((string)($storedOlt['source'] ?? '') !== 'api') continue;
            $scanStateByOlt[(int)$storedOlt['olt_id']] = $storedOlt;
        }

        foreach ($oltItems as $olt) {
            try {
                if (!is_array($olt)) continue;
                $oltId = valid_positive_id($olt['id'] ?? $olt['olt_id'] ?? null);
                if ($oltId === null) continue;

                $storedOlt = $scanStateByOlt[$oltId] ?? null;
                if (!is_array($storedOlt) || empty($storedOlt['first_scan_started_at'])) continue;

                $onuResult = $adapter->fetchOnus($oltId);
                if (($onuResult['state'] ?? '') === 'scanning') {
                    $pendingOnuResults++;
                    continue;
                }
                if (($onuResult['state'] ?? '') === 'error') {
                    $failures++;
                    continue;
                }

                $validOnuItems = (array)($onuResult['onus'] ?? []);
                if ($validOnuItems === []) {
                    $pendingOnuResults++;
                    continue;
                }

                $onuCount += count($validOnuItems);
                $events = array_merge(
                    $events,
                    $this->snapshotWriter()->writeDriverExternalOnuInventory(
                        $userId,
                        $oltId,
                        $validOnuItems,
                        $signalThreshold,
                        $recordChanges
                    )
                );
            } finally {
                if ($yieldAfterDevice !== null) $yieldAfterDevice();
            }
        }
         $telegramStatus = '';

        if ($failures > 0) {
            $status = 'partial';
            $message =
                'Sebagian Berhasil di perbaharui ' .
                'Beberapa perangkat tidak dapat diperiksa.';
        } elseif ($pendingOnuResults > 0) {
            $status = 'partial';
            $message =
                'Sebagian data ONU masih diproses. ' .
                'Silakan tunggu beberapa saat lalu segarkan kembali.';
        } else {
            $status = 'success';
            $message = 'Berhasil di perbaharui';
        }

        $this->db->setLastSync($userId, $status, $message);
        $this->snapshotWriter()->markMonitoringSync($userId, 'external_olt_api');

        return [
            'ok' => $failures === 0 && $pendingOnuResults === 0,
            'message' => $message,
            'olts' => $oltCount,
            'onus' => $onuCount,
            'changes' => count($events),
            'failures' => $failures,
            'telegram' => $telegramStatus,
        ];
    }

    public function sendPendingTelegramChanges(int $userId): array
    {
        if (!$this->db->acquireNotificationLock($userId, 5)) {
            return [
                'pending' => 0,
                'superseded' => 0,
                'selected' => 0,
                'skipped' => 0,
                'sent' => 0,
                'failed' => 0,
                'groups_sent' => 0,
                'groups_failed' => 0,
                'whatsapp_sent' => 0,
                'whatsapp_failed' => 0,
                'status' => 'locked',
                'message' => 'Notifikasi pengguna sedang diproses oleh worker lain.',
                'error' => null,
            ];
        }

        try {
            return $this->sendPendingTelegramChangesUnlocked($userId);
        } finally {
            $this->db->releaseNotificationLock($userId);
        }
    }

    private function sendPendingTelegramChangesUnlocked(int $userId): array
    {
        $settings = $this->db->getUserSettings($userId);
        $summary = [
            'pending' => 0,
            'superseded' => 0,
            'selected' => 0,
            'skipped' => 0,
            'sent' => 0,
            'failed' => 0,
            'groups_sent' => 0,
            'groups_failed' => 0,
            'whatsapp_sent' => 0,
            'whatsapp_failed' => 0,
            'status' => 'kosong',
            'message' => '',
            'error' => null,
        ];

        $telegramReady = $this->telegramFeatureAllowed()
            && (int)($this->db->getTelegramServiceSettings()['enabled'] ?? 0) === 1
            && (int)($settings['telegram_enabled'] ?? 0) === 1
            && trim((string)($settings['telegram_chat_id'] ?? '')) !== ''
            && trim((string)($settings['telegram_bot_token'] ?? '')) !== '';
        if (!$telegramReady && $this->userWhatsAppGateway($userId) === null) {
            $summary['status'] = 'nonaktif';
            $summary['message'] = 'Notifikasi Telegram dan WhatsApp akun belum diaktifkan/dikonfigurasi.';
            return $summary;
        }

        $pendingTelegramEvents = $this->db->getPendingChangeEvents($userId, 500, 5);
        if ($pendingTelegramEvents === []) return $summary;
        $summary['pending'] = count($pendingTelegramEvents);
        $pendingTelegramEvents = $this->latestStatusEvents($pendingTelegramEvents);
        $summary['superseded'] = $summary['pending'] - count($pendingTelegramEvents);

        if ($pendingTelegramEvents === []) {
            $summary['status'] = 'dilewati';
            $summary['message'] = 'Semua event status telah digantikan oleh status terbaru.';
            return $summary;
        }

        return $this->notifyUser($userId, $settings, $pendingTelegramEvents, $summary);
    }

    private function latestStatusEvents(array $events): array
    {
        $latest = [];
        $selected = [];
        foreach ($events as $event) {
            $type = strtoupper(trim((string)($event['entity_type'] ?? '')));
            $field = trim((string)($event['field_name'] ?? ''));
            $isStatus = ($type === 'ONU' && in_array($field, ['onu_state', 'is_present'], true))
                || ($type === 'DEVICE' && $field === 'driver_status')
                || ($type === 'INTERFACE' && $field === 'oper_status')
                || ($type === 'OLT' && in_array($field, ['driver_status', 'is_present', 'pon_link_status'], true));

            if (!$isStatus) {
                $selected[] = $event;
                continue;
            }
            $fieldGroup = match ($type) {
                'ONU' => 'onu_status',
                'OLT' => $field === 'pon_link_status' ? 'pon_status' : 'olt_status',
                'DEVICE' => 'device_status',
                'INTERFACE' => 'interface_status',
                default => $field,
            };
            $identity = $type . '|' . trim((string)($event['entity_key'] ?? '')) . '|' . $fieldGroup;
            if (!isset($latest[$identity])) {
                $latest[$identity] = count($selected);
                $event['superseded_notification_ids'] = [];
                $selected[] = $event;
                continue;
            }

            $eventId = (int)($event['id'] ?? 0);
            if ($eventId > 0) {
                $selected[$latest[$identity]]['superseded_notification_ids'][] = $eventId;
            }
        }

        return $selected;
    }
    private function notifyUser(int $userId, array $settings, array $events, array $summary): array
    {
        $service = $this->db->getTelegramServiceSettings();
        $whatsapp = $this->userWhatsAppGateway($userId);
        $telegramReady = $this->telegramFeatureAllowed()
            && (int)($service['enabled'] ?? 0) === 1
            && (int)($settings['telegram_enabled'] ?? 0) === 1
            && trim((string)($settings['telegram_bot_token'] ?? '')) !== ''
            && trim((string)($settings['telegram_chat_id'] ?? '')) !== '';
        if (!$telegramReady && $whatsapp === null) {
            $summary['skipped'] = count($events);
            $summary['status'] = 'nonaktif';
            $summary['message'] = 'Kanal notifikasi Telegram dan WhatsApp akun tidak siap.';
            return $summary;
        }
        $token = trim((string)($settings['telegram_bot_token'] ?? ''));
        $chatId = trim((string)($settings['telegram_chat_id'] ?? ''));

        $selected = [];
        $skippedIds = [];
        $selectedDeviceIds = $this->db->getNotificationSelectedDeviceIds($userId);
        $selectedDeviceMap = $selectedDeviceIds === null ? null : array_fill_keys($selectedDeviceIds, true);
        $deviceRowsById = [];
        $oltRowsByKey = [];
        $oltRowsByExternalId = [];
        if ($selectedDeviceMap !== null) {
            foreach ($this->db->getNotificationDeviceRows($userId) as $deviceRow) {
                $deviceId = (int)$deviceRow['id'];
                $deviceRowsById[$deviceId] = true;
                if ((string)$deviceRow['device_type'] !== 'olt') continue;
                $oltId = (int)($deviceRow['olt_id'] ?? 0);
                if ($oltId < 1) continue;
                $oltRowsByKey[strtolower((string)$deviceRow['source']) . '|' . $oltId] = $deviceId;
                $oltRowsByExternalId[$oltId][] = $deviceId;
            }
        }
        foreach ($events as $event) {
            $type = (string) ($event['entity_type'] ?? '');
            $field = (string) ($event['field_name'] ?? '');
            $allowed = true;
            if ($selectedDeviceMap !== null && in_array($type, ['ONU', 'OLT', 'DEVICE', 'INTERFACE'], true)) {
                $deviceId = $this->notificationEventDeviceId($event, $deviceRowsById, $oltRowsByKey, $oltRowsByExternalId);
                if ($deviceId === null || !isset($selectedDeviceMap[$deviceId])) $allowed = false;
            }
            if (
                $type === 'ONU'
                && in_array($field, ['onu_edit', 'onu_name', 'auth_mode', 'onu_registration', 'deleted'], true)
            ) {
                $allowed = false;
            }
            if (in_array($type, ['OLT', 'DEVICE'], true) && (int)($settings['notify_device_status'] ?? 1) !== 1) $allowed = false;
            if ($type === 'INTERFACE') {
                $preference = activity_event_is_pppoe($event)
                    ? 'notify_pppoe_status'
                    : 'notify_interface_changes';
                if ((int)($settings[$preference] ?? 1) !== 1) $allowed = false;
            }
            if ($type === 'ONU') {
                if (in_array($field, ['problem_offline','problem_rx_power','problem_rx_recovered'], true)) {
                    $allowed = (int)($settings['notify_onu_problem'] ?? 1) === 1;
                } else {
                if ($field === 'tx_power') $allowed = false;
                if ($field === 'rx_power' && (!is_numeric($event['old_value'] ?? null) || !is_numeric($event['new_value'] ?? null))) $allowed = false;
                if (in_array($field,['onu_state','is_present'],true) && (int)($settings['notify_onu_state'] ?? 1) !== 1) $allowed = false;
                if ($field === 'rx_power' && (int)($settings['notify_onu_signal'] ?? 1) !== 1) $allowed = false;
                if (!in_array($field,['onu_state','is_present','rx_power','tx_power'],true) && (int)($settings['notify_onu_details'] ?? 1) !== 1) $allowed = false;
                }
            }
            if (!$allowed) {
                $eventId = (int)($event['id'] ?? 0);
                if ($eventId > 0) $skippedIds[] = $eventId;
                foreach ((array)($event['superseded_notification_ids'] ?? []) as $supersededId) {
                    $supersededId = (int)$supersededId;
                    if ($supersededId > 0) $skippedIds[] = $supersededId;
                }
                continue;
            }
            $selected[] = $event;
        }
        if ($skippedIds !== []) $this->db->markEventsNotificationSkipped($skippedIds);
        $summary['skipped'] = count($skippedIds);
        $summary['selected'] = count($selected);

        if ($selected === []) {
            $summary['status'] = 'dilewati';
            $summary['message'] = 'Semua notifikasi dilewati sesuai pengaturan notifikasi.';
            return $summary;
        }

        $groups = activity_events_telegram_device_batches($selected);

        if (!$telegramReady) $groups = [];
        $sentEvents = 0;
        $failedEvents = 0;
        $lastError = null;
        foreach ($groups as $group) {
            $categoryEvents = (array)($group['events'] ?? []);
            $historyEventId = $this->notificationHistoryEventId($categoryEvents);
            if ($historyEventId === null) {
                foreach ((array)($group['ids'] ?? []) as $fallbackEventId) {
                    $fallbackEventId = (int)$fallbackEventId;
                    if ($fallbackEventId > 0) { $historyEventId = $fallbackEventId; break; }
                }
            }
            $historyAttempts = $this->notificationHistoryAttempts($categoryEvents);
            $result = $this->telegram->sendChunks(
                $token,
                $chatId,
                (string)($group['title'] ?? '<b>Perangkat</b>'),
                (array)($group['html_lines'] ?? []),
                (int)($this->config['sync']['telegram_chunk_size'] ?? 3000),
                'HTML'
            );
            $ids = (array)($group['ids'] ?? []);
            foreach ((array)($result['sent_messages'] ?? []) as $sentMessage) {
                $plainMessage = html_entity_decode(strip_tags((string)$sentMessage), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $this->db->recordNotificationHistory($userId, 'telegram', $plainMessage, '', (string)($group['name'] ?? ''), (string)($group['status_category'] ?? ''), 'sent', $historyEventId, $historyAttempts);
            }
            if (!empty($result['ok'])) {
                $this->db->markEventsNotified($ids);
                $summary['groups_sent']++;
                $sentEvents += count($categoryEvents);
                continue;
            }

            $summary['groups_failed']++;
            $failedEvents += count($categoryEvents);
            $error = (string)($result['error'] ?? '');
            if ($error === '') $error = 'TelegramNotifier::sendChunks() returned ok=false without an error message.';
            $lastError = $error;
            $failedMessage = trim((string)($result['failed_message'] ?? ''));
            if ($failedMessage === '') $failedMessage = trim((string)($group['plain'] ?? ''));
            if ($failedMessage !== '') {
                $failedMessage = html_entity_decode(strip_tags($failedMessage), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $failedStatus = !empty($result['transport']) ? 'pending' : 'failed';
                $this->db->recordNotificationHistory($userId, 'telegram', $failedMessage, '', (string)($group['name'] ?? ''), (string)($group['status_category'] ?? ''), $failedStatus, $historyEventId, $historyAttempts);
            }
            $this->db->markEventsNotificationError($ids, $error);
            try {
                $this->db->logApplication(null, 'System', 'send_notification', 'error', 'Pengguna #' . $userId . ': Telegram: ' . $error, [
                    'telegram_status' => (int)($result['status'] ?? 0),
                    'transport' => !empty($result['transport']),
                    'event_count' => count($categoryEvents),
                    'event_ids' => $ids,
                    'device' => (string)($group['name'] ?? ''),
                    'source' => 'scheduled_sync',
                ]);
            } catch (Throwable) {}
        }

        $whatsappSent = 0;
        $whatsappFailed = 0;
        if ($whatsapp !== null) {
            $whatsappGroups = $groups !== [] ? $groups : activity_events_telegram_device_batches($selected);
            $whatsappTarget = (string)($this->db->getUserWhatsAppSettings($userId)['target'] ?? '');
            foreach ($whatsappGroups as $whatsappGroup) {
                $whatsappMessage = trim((string)($whatsappGroup['plain'] ?? ''));
                $whatsappIds = (array)($whatsappGroup['ids'] ?? []);
                $historyEventId = $this->notificationHistoryEventId((array)($whatsappGroup['events'] ?? []));
                if ($historyEventId === null) {
                    foreach ($whatsappIds as $fallbackEventId) {
                        $fallbackEventId = (int)$fallbackEventId;
                        if ($fallbackEventId > 0) { $historyEventId = $fallbackEventId; break; }
                    }
                }
                $historyAttempts = $this->notificationHistoryAttempts((array)($whatsappGroup['events'] ?? []));
                if ($whatsappMessage === '') continue;
                
                $whatsappChunks = $this->chunkMessageByLines($whatsappMessage, 120, 3000);
                $whatsappResult = ['ok' => false, 'message' => '', 'raw' => [], 'transport' => false];
                $sentChunks = [];
                $failedChunk = null;
                try {
                    foreach ($whatsappChunks as $whatsappChunk) {
                        $failedChunk = $whatsappChunk;
                        $whatsappResult = $whatsapp->send($whatsappTarget, $whatsappChunk);
                        if (empty($whatsappResult['ok'])) break;
                        $sentChunks[] = $whatsappChunk;
                    }
                    if (!empty($whatsappResult['ok'])) $failedChunk = null;
                } catch (Throwable $e) {
                    $whatsappResult = ['ok' => false, 'message' => $e->getMessage(), 'raw' => [], 'transport' => true];
                }
                foreach ($sentChunks as $sentChunk) {
                    $this->db->recordNotificationHistory($userId, 'whatsapp', $sentChunk, $whatsapp->providerKey(), (string)($whatsappGroup['name'] ?? ''), (string)($whatsappGroup['status_category'] ?? ''), 'sent', $historyEventId, $historyAttempts);
                }
                $whatsappOk = !empty($whatsappResult['ok']);
                $whatsappDetail = trim((string)($whatsappResult['message'] ?? ''));
                $whatsappCount = count((array)($whatsappGroup['events'] ?? []));
                if ($whatsappOk) {
                    $this->db->markEventsNotified($whatsappIds);
                    $whatsappSent += $whatsappCount;
                } else {
                    $whatsappFailed += $whatsappCount;
                    $lastError = $whatsappDetail !== '' ? $whatsappDetail : 'Pengiriman WhatsApp gagal.';
                    $failedStatus = !empty($whatsappResult['transport']) ? 'pending' : 'failed';
                    $failedMessage = trim((string)($failedChunk ?? ''));
                    if ($failedMessage === '') $failedMessage = $whatsappMessage;
                    if ($failedMessage !== '') {
                        $this->db->recordNotificationHistory($userId, 'whatsapp', $failedMessage, $whatsapp->providerKey(), (string)($whatsappGroup['name'] ?? ''), (string)($whatsappGroup['status_category'] ?? ''), $failedStatus, $historyEventId, $historyAttempts);
                    }
                    if (!$telegramReady) $this->db->markEventsNotificationError($whatsappIds, $lastError);
                }
                $this->db->setUserWhatsAppResult(
                    $userId,
                    $whatsappOk,
                    $whatsappDetail !== '' ? $whatsappDetail : ($whatsappOk ? 'Notifikasi terkirim.' : 'Pengiriman gagal.')
                );
                $this->db->logApplication(
                    $whatsappOk ? $userId : null,
                    $whatsappOk ? 'WhatsApp' : 'System',
                    'send_notification',
                    $whatsappOk ? 'success' : 'error',
                    $whatsappOk ? $whatsappDetail : ('Pengguna #' . $userId . ': WhatsApp: ' . ($whatsappDetail !== '' ? $whatsappDetail : 'Pengiriman gagal.')),
                    [
                        'provider' => $whatsapp->providerKey(),
                        'http_status' => (int)($whatsappResult['http'] ?? 0),
                        'transport' => !empty($whatsappResult['transport']),
                        'event_count' => $whatsappCount,
                        'device' => (string)($whatsappGroup['name'] ?? ''),
                        'source' => 'scheduled_sync',
                    ]
                );
            }
        }
        $summary['whatsapp_sent'] = $whatsappSent;
        $summary['whatsapp_failed'] = $whatsappFailed;

        $summary['sent'] = $sentEvents;
        $summary['failed'] = $failedEvents;
        $summary['error'] = $lastError;

        if ($summary['groups_failed'] === 0 && $whatsappFailed === 0) {
            $summary['status'] = 'berhasil';
            $summary['message'] = 'Notifikasi perubahan berhasil dikirim.';
        } elseif ($summary['groups_sent'] > 0 || $whatsappSent > 0) {
            $summary['status'] = 'sebagian';
            $summary['message'] = 'Sebagian notifikasi perubahan tidak dapat dikirim.';
        } else {
            $summary['status'] = 'gagal';
            $summary['message'] = 'Notifikasi perubahan tidak dapat dikirim.';
        }

        return $summary;
    }

    private function notificationEventDeviceId(array $event, array $deviceRowsById, array $oltRowsByKey, array $oltRowsByExternalId): ?int
    {
        $type = strtoupper(trim((string)($event['entity_type'] ?? '')));
        $key = trim((string)($event['entity_key'] ?? ''));
        if (in_array($type, ['DEVICE', 'INTERFACE'], true)) {
            if (preg_match('~^(?:mikrotik|switch)\|(\d+)(?:\||$)~', $key, $match) !== 1) return null;
            $deviceId = (int)$match[1];
            return isset($deviceRowsById[$deviceId]) ? $deviceId : null;
        }
        if (!in_array($type, ['OLT', 'ONU'], true)) return null;

        $parts = explode('|', $key);
        $source = strtolower(trim((string)($event['event_source'] ?? '')));
        if ($source === '') $source = strtolower(trim((string)($parts[0] ?? '')));
        $oltId = (int)($event['event_olt_id'] ?? 0);
        if ($oltId < 1 && isset($parts[1]) && ctype_digit($parts[1])) $oltId = (int)$parts[1];
        if ($oltId < 1 && preg_match('~^(\d+)/~', $key, $match) === 1) $oltId = (int)$match[1];
        if ($oltId < 1) return null;
        if (in_array($source, ['cli', 'web', 'fast'], true)) $source = 'snmp';
        if ($source !== '') return $oltRowsByKey[$source . '|' . $oltId] ?? null;
        $matches = array_values(array_unique($oltRowsByExternalId[$oltId] ?? []));
        return count($matches) === 1 ? $matches[0] : null;
    }

    private function notificationHistoryEventId(array $events): ?int
    {
        foreach ($events as $event) {
            $id = (int)($event['id'] ?? 0);
            if ($id > 0) return $id;
        }
        return null;
    }

    private function notificationHistoryAttempts(array $events): int
    {
        $attempts = 0;
        foreach ($events as $event) {
            $attempts = max($attempts, (int)($event['notification_attempts'] ?? 0));
        }
        return max(1, $attempts + 1);
    }

    
    private function chunkMessageByLines(string $message, int $maxLines = 120, int $maxChars = 3000): array
    {
        $maxLines = max(1, $maxLines);
        $maxChars = max(200, $maxChars);
        $lines = preg_split('/\R/u', $message) ?: [$message];
        $chunks = [];
        $current = [];
        $currentChars = 0;
        foreach ($lines as $line) {
            $lineLen = mb_strlen($line);
            $wouldOverflow = $current !== [] && (
                count($current) >= $maxLines
                || ($currentChars + $lineLen + 1) > $maxChars
            );
            if ($wouldOverflow) {
                $chunks[] = implode("\n", $current);
                $current = [];
                $currentChars = 0;
            }
            $current[] = $line;
            $currentChars += $lineLen + 1;
        }
        if ($current !== []) $chunks[] = implode("\n", $current);
        return $chunks !== [] ? $chunks : [$message];
    }

    private function userWhatsAppGateway(int $userId): ?WhatsAppGateway
    {
        if (!class_exists('WhatsAppGateway')) return null;
        if (!$this->whatsAppFeatureAllowed()) return null;
        try {

            if ((int)($this->db->getWhatsAppNotificationServiceSettings()['enabled'] ?? 0) !== 1) return null;
            if (!$this->db->isUserWhatsAppNotificationEnabled($userId)) return null;
            $settings = $this->db->getUserWhatsAppSettings($userId);
        } catch (Throwable) {
            return null;
        }
        $target = trim((string)($settings['target'] ?? ''));
        if ((int)($settings['enabled'] ?? 0) !== 1 || $target === '') return null;
        $gateway = new WhatsAppGateway([
            'provider' => (string)($settings['provider'] ?? ''),
            'token' => (string)($settings['token'] ?? ''),
            'device' => (string)($settings['device'] ?? ''),
            'base_url' => (string)($settings['base_url'] ?? ''),
            'target_type' => (string)($settings['target_type'] ?? 'phone'),
            'sender' => $target,
            'enabled' => 1,
        ]);

        return $gateway->isConfigured() && $gateway->isValidTarget($target) ? $gateway : null;
    }

    private function telegramFeatureAllowed(): bool
    {
        if ($this->bridge === null) return true;
        try { return $this->bridge->allowsTelegram(); } catch (Throwable) { return false; }
    }

    private function whatsAppFeatureAllowed(): bool
    {
        if ($this->bridge === null) return true;
        try { return $this->bridge->allowsWhatsApp(); } catch (Throwable) { return false; }
    }

    private function snapshotWriter(): OltSnapshotWriteService
    {
        if ($this->snapshotWriter === null) {
            require_once project_path('backend', 'services', 'OltSnapshotWriteService.php');
            $this->snapshotWriter = new OltSnapshotWriteService($this->db);
        }
        return $this->snapshotWriter;
    }

    private function externalAdapter(ApiClient $client): DriverExternalAdapter
    {
        require_once project_path('vendor', 'driver', 'olt', 'zetset', 'Adapter.php');
        return new DriverExternalAdapter($client, $this->config);
    }
}
