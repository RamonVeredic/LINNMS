<?php

declare(strict_types=1);

final class TelegramNotifier
{
    public function __construct(private readonly int $timeout = 15)
    {
    }

    
    public function send(string $botToken, string $chatId, string $message, string $parseMode = ''): array
    {
        $botToken = trim($botToken);
        $chatId = trim($chatId);
        $message = $this->withoutTerminalPeriods(trim($message));
        if ($botToken === '' || $chatId === '') {
            return $this->sendFailure('Bot Token / Chat ID belum diatur.');
        }
        if ($message === '') {
            return $this->sendFailure('Pesan Telegram tidak boleh kosong.');
        }

        $params = [
            'chat_id' => $chatId,
            'text' => $message,
            'link_preview_options' => json_encode(['is_disabled' => true], JSON_UNESCAPED_SLASHES),
        ];
        if (in_array($parseMode, ['HTML', 'MarkdownV2'], true)) $params['parse_mode'] = $parseMode;
        $response = $this->request($botToken, 'sendMessage', $params, true);

        if (!$response['ok']) {
            return $this->sendFailure($response['error'], $response['status'], !empty($response['transport']));
        }

        $result = is_array($response['result']) ? $response['result'] : [];
        $chat = is_array($result['chat'] ?? null) ? $result['chat'] : [];
        $title = trim((string)($chat['title'] ?? $chat['username'] ?? $chat['first_name'] ?? ''));

        return [
            'ok' => true,
            'error' => '',
            'status' => $response['status'],
            'transport' => false,
            'message_id' => (int)($result['message_id'] ?? 0),
            'chat_id' => trim((string)($chat['id'] ?? $chatId)),
            'chat_type' => trim((string)($chat['type'] ?? '')),
            'chat_title' => $title,
        ];
    }

    
    public function getMe(string $botToken): array
    {
        $botToken = trim($botToken);
        if ($botToken === '') {
            return ['ok'=>false,'id'=>'','username'=>'','name'=>'','error'=>'Bot Token belum diatur.','status'=>0];
        }

        $response = $this->request($botToken, 'getMe');
        if (!$response['ok']) {
            return ['ok'=>false,'id'=>'','username'=>'','name'=>'','error'=>$response['error'],'status'=>$response['status']];
        }

        $result = is_array($response['result']) ? $response['result'] : [];
        return [
            'ok' => true,
            'id' => trim((string)($result['id'] ?? '')),
            'username' => ltrim(trim((string)($result['username'] ?? '')), '@'),
            'name' => trim((string)($result['first_name'] ?? '')),
            'error' => '',
            'status' => $response['status'],
        ];
    }

    
    public function getWebhookInfo(string $botToken): array
    {
        $botToken = trim($botToken);
        if ($botToken === '') {
            return ['ok'=>false,'url'=>'','pending'=>0,'error'=>'Bot Token belum diatur.','status'=>0];
        }

        $response = $this->request($botToken, 'getWebhookInfo');
        if (!$response['ok']) {
            return ['ok'=>false,'url'=>'','pending'=>0,'error'=>$response['error'],'status'=>$response['status']];
        }

        $result = is_array($response['result']) ? $response['result'] : [];
        return [
            'ok' => true,
            'url' => trim((string)($result['url'] ?? '')),
            'pending' => max(0, (int)($result['pending_update_count'] ?? 0)),
            'error' => trim((string)($result['last_error_message'] ?? '')),
            'status' => $response['status'],
        ];
    }

    
    public function ensurePollingMode(string $botToken): array
    {
        $botToken = trim($botToken);
        if ($botToken === '') {
            return ['ok'=>false,'changed'=>false,'error'=>'Bot Token belum diatur.','status'=>0];
        }

        $webhook = $this->getWebhookInfo($botToken);
        if (!$webhook['ok']) {
            return [
                'ok'=>false,
                'changed'=>false,
                'error'=>$webhook['error'] !== '' ? $webhook['error'] : 'Status webhook Telegram tidak dapat diperiksa.',
                'status'=>$webhook['status'],
            ];
        }
        if ($webhook['url'] === '') {
            return ['ok'=>true,'changed'=>false,'error'=>'','status'=>$webhook['status']];
        }

        $response = $this->request($botToken, 'deleteWebhook', [
            'drop_pending_updates' => 'false',
        ], true);
        if (!$response['ok']) {
            return [
                'ok'=>false,
                'changed'=>false,
                'error'=>$response['error'] !== '' ? $response['error'] : 'Webhook Telegram tidak dapat dinonaktifkan.',
                'status'=>$response['status'],
            ];
        }

        return ['ok'=>true,'changed'=>true,'error'=>'','status'=>$response['status']];
    }

    
    public function resetPollingState(string $botToken, bool $dropPendingUpdates = true): array
    {
        $botToken = trim($botToken);
        if ($botToken === '') {
            return ['ok'=>false,'dropped'=>false,'error'=>'Bot Token belum diatur.','status'=>0];
        }

        $response = $this->request($botToken, 'deleteWebhook', [
            'drop_pending_updates' => $dropPendingUpdates ? 'true' : 'false',
        ], true);
        if (!$response['ok']) {
            return [
                'ok'=>false,
                'dropped'=>false,
                'error'=>$response['error'] !== '' ? $response['error'] : 'State polling Telegram tidak dapat direset.',
                'status'=>$response['status'],
            ];
        }

        $probe = $this->getUpdates($botToken, 0, 1, 0);
        if (!$probe['ok']) {
            return [
                'ok'=>false,
                'dropped'=>$dropPendingUpdates,
                'error'=>$probe['error'],
                'status'=>$probe['status'],
            ];
        }

        return ['ok'=>true,'dropped'=>$dropPendingUpdates,'error'=>'','status'=>$probe['status']];
    }

    
    public function primePollingCursor(string $botToken): array
    {
        $reset = $this->resetPollingState($botToken, true);
        return [
            'ok'=>$reset['ok'],
            'next_offset'=>0,
            'dropped'=>$reset['ok'] ? 1 : 0,
            'error'=>$reset['error'],
            'status'=>$reset['status'],
        ];
    }

    
    public function getUpdates(string $botToken, int $offset = 0, int $limit = 100, int $timeoutSeconds = 2): array
    {
        $botToken = trim($botToken);
        if ($botToken === '') {
            return ['ok'=>false,'updates'=>[],'error'=>'Bot Token belum diatur.','status'=>0];
        }

        $params = [
            'limit' => (string)max(1, min(100, $limit)),

            'timeout' => (string)max(0, min(5, $timeoutSeconds)),
            'allowed_updates' => json_encode(['message','edited_message'], JSON_UNESCAPED_SLASHES),
        ];
        if ($offset !== 0) $params['offset'] = (string)$offset;

        $response = $this->request($botToken, 'getUpdates', $params);
        if (!$response['ok'] || !is_array($response['result'])) {
            $error = $response['error'] !== '' ? $response['error'] : 'Update Telegram tidak dapat dibaca.';
            if ((int)$response['status'] === 409) {
                $error .= ' Pastikan Bot Token ini tidak sedang dipakai aplikasi/worker Telegram lain. getUpdates hanya boleh memiliki satu listener aktif dan tidak dapat berjalan bersama webhook.';
            }
            return [
                'ok'=>false,
                'updates'=>[],
                'error'=>$error,
                'status'=>$response['status'],
            ];
        }

        return [
            'ok'=>true,
            'updates'=>array_values($response['result']),
            'error'=>'',
            'status'=>$response['status'],
        ];
    }

    
    public function parseConnectCommand(string $text, string $botUsername = ''): array
    {
        $text = trim($text);
        $botUsername = ltrim(trim($botUsername), '@');
        if (preg_match('/^\/connect(?:@([A-Za-z0-9_]+))?\s+(LINNMS-[A-F0-9]{8,32})$/i', $text, $m) !== 1) {
            return ['ok'=>false,'code'=>'','mode'=>'','command_username'=>''];
        }

        $commandUsername = ltrim(trim((string)($m[1] ?? '')), '@');
        if ($commandUsername !== '' && $botUsername !== '' && strcasecmp($commandUsername, $botUsername) !== 0) {

            return ['ok'=>false,'code'=>'','mode'=>'','command_username'=>$commandUsername];
        }

        return [
            'ok'=>true,
            'code'=>strtoupper(trim((string)$m[2])),

            'mode'=>$commandUsername !== '' ? 'group' : 'private',
            'command_username'=>$commandUsername,
        ];
    }

    
    public function findChatByPairCode(string $botToken, string $pairCode): array
    {
        $botToken = trim($botToken);
        $pairCode = trim($pairCode);
        if ($botToken === '' || $pairCode === '') {
            return ['ok'=>false,'chat_id'=>'','chat_type'=>'','title'=>'','error'=>'Kode penghubung belum tersedia.','status'=>0];
        }

        $webhook = $this->getWebhookInfo($botToken);
        if ($webhook['ok'] && $webhook['url'] !== '') {
            return [
                'ok'=>false,
                'chat_id'=>'',
                'chat_type'=>'',
                'title'=>'',
                'error'=>'Bot sedang menggunakan webhook. Pairing berbasis long polling getUpdates tidak dapat digunakan selama webhook aktif.',
                'status'=>$webhook['status'],
            ];
        }

        $response = $this->request($botToken, 'getUpdates', [
            'limit' => '100',
            'timeout' => '0',
            'allowed_updates' => json_encode(['message','edited_message','channel_post','edited_channel_post'], JSON_UNESCAPED_SLASHES),
        ]);
        if (!$response['ok'] || !is_array($response['result'])) {
            return [
                'ok'=>false,
                'chat_id'=>'',
                'chat_type'=>'',
                'title'=>'',
                'error'=>$response['error'] !== '' ? $response['error'] : 'Pesan kode tidak dapat diperiksa.',
                'status'=>$response['status'],
            ];
        }

        $updates = array_reverse($response['result']);
        foreach ($updates as $update) {
            if (!is_array($update)) continue;
            $message = null;
            foreach (['message','edited_message','channel_post','edited_channel_post'] as $key) {
                if (is_array($update[$key] ?? null)) {
                    $message = $update[$key];
                    break;
                }
            }
            if (!is_array($message)) continue;

            $text = trim((string)($message['text'] ?? ''));
            $matchesPair = hash_equals($pairCode, $text) ||
                preg_match('/^\/connect(?:@[A-Za-z0-9_]+)?\s+' . preg_quote($pairCode, '/') . '$/i', $text) === 1;
            if (!$matchesPair) continue;

            $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
            $chatId = trim((string)($chat['id'] ?? ''));
            if ($chatId === '') continue;

            $title = trim((string)($chat['title'] ?? $chat['username'] ?? $chat['first_name'] ?? ''));
            return [
                'ok'=>true,
                'chat_id'=>$chatId,
                'chat_type'=>trim((string)($chat['type'] ?? '')),
                'title'=>$title,
                'error'=>'',
                'status'=>$response['status'],
            ];
        }

        return ['ok'=>false,'chat_id'=>'','chat_type'=>'','title'=>'','error'=>'Kode belum ditemukan. Kirim perintah /connect beserta kode persis seperti yang tampil, lalu coba lagi.','status'=>$response['status']];
    }

    
    public function sendChunks(string $botToken, string $chatId, string $title, array $lines, int $limit = 3000, string $parseMode = '', int $maxLines = 120): array
    {
        $limit = max(500, min(4000, $limit));
        $maxLines = max(1, $maxLines);
        $chunks = [];
        $current = $title;
        $currentLines = 0;
        foreach ($lines as $line) {
            $piece = "\n" . $line;
            $wouldOverflow = $current !== $title && (
                $currentLines >= $maxLines
                || strlen($current . $piece) > $limit
            );
            if ($wouldOverflow) {
                $chunks[] = $current;
                $current = $title . $piece;
                $currentLines = 1;
            } else {
                $current .= $piece;
                $currentLines++;
            }
        }
        if ($current !== $title || $lines === []) $chunks[] = $current;

        $last = [
            'ok' => true,
            'error' => '',
            'status' => 200,
            'transport' => false,
            'message_id' => 0,
            'chat_id' => trim($chatId),
            'chat_type' => '',
            'chat_title' => '',
            'failed_message' => '',
        ];
        $sentMessages = [];
        foreach ($chunks as $chunk) {
            $last = $this->send($botToken, $chatId, $chunk, $parseMode);
            if (!$last['ok']) {
                $last['sent_messages'] = $sentMessages;
                $last['failed_message'] = $chunk;
                return $last;
            }
            $sentMessages[] = $this->withoutTerminalPeriods(trim($chunk));
        }
        $last['sent_messages'] = $sentMessages;
        return $last;
    }

    private function withoutTerminalPeriods(string $message): string
    {
        $lines = preg_split('/\R/u', $message);
        if (!is_array($lines)) return $message;

        foreach ($lines as &$line) {

            $line = preg_replace(
                '/\.+(?=(?:<\/[a-z][a-z0-9]*>\s*)*$)/iu',
                '',
                $line
            ) ?? $line;
        }
        unset($line);

        return implode("\n", $lines);
    }

    
    private function request(string $botToken, string $method, array $params = [], bool $post = false): array
    {
        $botToken = trim($botToken);
        $method = trim($method);
        if ($botToken === '' || $method === '') {
            return ['ok'=>false,'error'=>'Request Telegram tidak lengkap.','status'=>0,'result'=>null,'transport'=>false];
        }

        $url = 'https://api.telegram.org/bot' . $botToken . '/' . $method;
        $payload = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        if (!$post && $payload !== '') $url .= '?' . $payload;

        $raw = false;
        $status = 0;
        $transportError = '';
        $requestTimeout = $this->timeout;
        if ($method === 'getUpdates') {

            $telegramWait = max(0, (int)($params['timeout'] ?? 0));
            $requestTimeout = max($requestTimeout, $telegramWait + 8);
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => $requestTimeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ];
            if ($post) {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = $payload;
                $options[CURLOPT_HTTPHEADER] = [
                    'Accept: application/json',
                    'Content-Type: application/x-www-form-urlencoded',
                ];
            }
            curl_setopt_array($ch, $options);
            $raw = curl_exec($ch);
            $transportError = trim((string)curl_error($ch));
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $headers = "Accept: application/json\r\n";
            if ($post) $headers .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $context = stream_context_create(['http' => [
                'method' => $post ? 'POST' : 'GET',
                'header' => $headers,
                'content' => $post ? $payload : '',
                'timeout' => $requestTimeout,
                'ignore_errors' => true,
            ]]);
            $raw = @file_get_contents($url, false, $context);
            foreach ((array)($http_response_header ?? []) as $line) {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})/', (string)$line, $m) === 1) {
                    $status = (int)$m[1];
                    break;
                }
            }
        }

        if ($raw === false) {
            return [
                'ok'=>false,
                'error'=>$transportError !== '' ? $transportError : 'Tidak dapat terhubung ke Telegram Bot API.',
                'status'=>$status,
                'result'=>null,
                'transport'=>true,
            ];
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return ['ok'=>false,'error'=>'Respons Telegram bukan JSON yang valid.','status'=>$status,'result'=>null,'transport'=>false];
        }

        $ok = $status >= 200 && $status < 300 && ($decoded['ok'] ?? false) === true;
        if ($ok) {
            return ['ok'=>true,'error'=>'','status'=>$status,'result'=>$decoded['result'] ?? null,'transport'=>false];
        }

        $description = trim((string)($decoded['description'] ?? ''));
        $retryAfter = (int)($decoded['parameters']['retry_after'] ?? 0);
        if ($retryAfter > 0) {
            $description .= ($description !== '' ? ' ' : '') . 'Coba lagi dalam ' . $retryAfter . ' detik.';
        }
        if ($description === '') $description = 'Telegram menolak request.';

        return ['ok'=>false,'error'=>$description,'status'=>$status,'result'=>$decoded['result'] ?? null,'transport'=>false];
    }

    
    private function sendFailure(string $error, int $status = 0, bool $transport = false): array
    {
        return [
            'ok' => false,
            'error' => trim($error),
            'status' => $status,
            'transport' => $transport,
            'message_id' => 0,
            'chat_id' => '',
            'chat_type' => '',
            'chat_title' => '',
        ];
    }
}
