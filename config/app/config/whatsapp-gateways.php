<?php

declare(strict_types=1);
return [
    'fonnte' => [
        'label' => 'Fonnte',
        'verified' => true,
        'info' => 'Token perangkat dari dashboard Fonnte (menu Device)',
        'info_url' => 'https://md.fonnte.com/',
        'fields' => [
            'token' => ['label' => 'Device Token', 'required' => true, 'hint' => ''],
        ],
        'send' => [
            'url' => 'https://api.fonnte.com/send',
            'method' => 'POST',
            'body' => 'form',
            'headers' => ['Authorization: {token}'],

            'payload' => ['target' => '{to}', 'message' => '{message}', 'countryCode' => '0'],
        ],
        'device' => [
            'url' => 'https://api.fonnte.com/device',
            'method' => 'POST',
            'body' => 'form',
            'headers' => ['Authorization: {token}'],
            'payload' => [],
        ],
        'target' => [
            'types' => ['phone', 'group'],
            'group_hint' => 'Gunakan fitur "Muat grup" di bawah untuk menampilkan daftar grup yang tersedia di gateway',
        ],
        'groups' => [
            'url' => 'https://api.fonnte.com/get-whatsapp-group',
            'method' => 'POST',
            'body' => 'form',
            'headers' => ['Authorization: {token}'],
            'payload' => [],
            'items_path' => 'data',
            'item_id_path' => 'id',
            'item_name_path' => 'name',
        ],
        'result' => ['ok_path' => 'status', 'ok_equals' => true, 'message_path' => 'detail', 'http_ok_only' => false],
    ],

    'starsender' => [
        'label' => 'Starsender',
        'verified' => true,
        'info' => 'API key dari dashboard Starsender; tujuan nomor pribadi atau Group ID WhatsApp.',
        'info_url' => 'https://app.starsender.online/',
        'fields' => [
            'token' => ['label' => 'API Key', 'required' => true, 'hint' => ''],
        ],
        'send' => [
            'url' => 'https://api.starsender.online/api/send',
            'method' => 'POST',
            'body' => 'json',
            'headers' => ['Authorization: {token}', 'Content-Type: application/json'],
            'payload' => ['messageType' => 'text', 'to' => '{to}', 'body' => '{message}'],
        ],
        'target' => [
            'types' => ['phone', 'group'],
            'group_hint' => '',
        ],
        'groups' => [
            'url' => 'https://api.starsender.online/api/whatsapp/groups',
            'method' => 'GET',
            'body' => 'json',
            'headers' => ['Authorization: {token}'],
            'payload' => [],
            'items_path' => 'data',
            'item_id_path' => 'id',
            'item_name_path' => 'name',
            'items_path_alternatives' => ['data.groups', 'groups', 'data.data'],
            'item_id_path_alternatives' => ['chatId', 'jid', 'group_id', 'groupId', 'group'],
            'item_name_path_alternatives' => ['subject', 'group_name', 'groupName', 'title'],
        ],
        'result' => ['ok_path' => 'success', 'ok_equals' => true, 'message_path' => 'message', 'http_ok_only' => false],
    ],

    'wagate' => [
        'label' => 'Wagate',
        'verified' => true,
        'info' => 'API key Wagate dengan format wgw_ dari dashboard Wagate.',
        'info_url' => 'https://www.wagate.id/',
        'fields' => [
            'token' => ['label' => 'API Key', 'required' => true, 'hint' => ''],
        ],
        'send' => [
            'url' => 'https://www.wagate.id/api/send',
            'method' => 'POST',
            'body' => 'json',
            'headers' => ['Authorization: Bearer {token}', 'Accept: application/json', 'Content-Type: application/json'],
            'payload' => ['to' => '{to}', 'message' => '{message}'],
        ],
        'target' => [
            'types' => ['phone', 'group'],
            'group_hint' => 'Masukkan Group ID WhatsApp yang didukung Wagate.',
        ],
        'result' => ['ok_path' => 'success', 'ok_equals' => true, 'message_path' => 'message', 'http_ok_only' => true],
    ],

    'wppconnect' => [
        'label' => 'WPPConnect',
        'verified' => true,
        'info' => '',
        'info_url' => 'https://netnms.planetsrv.net/dokumentasi#waha-installation',
        'fields' => [
            'base_url' => ['label' => 'URL Server', 'required' => true, 'hint' => ''],
            'token' => ['label' => 'Token', 'required' => true, 'hint' => ''],
        ],
        'token_generator' => ['enabled' => true],
        'token_contains_session' => true,
        'send' => [
            'url' => '{base_url}/api/{session}/send-message',
            'method' => 'POST',
            'body' => 'json',
            'headers' => ['Authorization: Bearer {auth_token}', 'Accept: application/json', 'Content-Type: application/json'],
            'payload' => ['phone' => '{to}', 'message' => '{message}'],
        ],
        'target' => [
            'types' => ['phone', 'group'],
            'group_hint' => 'Pilih grup dari daftar WPPConnect atau gunakan Group ID yang diberikan server.',
        ],
        'group_params' => ['isGroup' => true],
        'groups' => [
            'url' => '{base_url}/api/{session}/all-groups',
            'method' => 'GET',
            'body' => 'json',
            'headers' => ['Authorization: Bearer {auth_token}', 'Accept: application/json'],
            'payload' => [],
            'items_path' => 'response',
            'items_path_alternatives' => ['$', 'data', 'groups', 'data.groups'],
            'item_id_path' => 'id._serialized',
            'item_id_path_alternatives' => ['id.user', 'chatId', '_serialized'],
            'item_name_path' => 'name',
            'item_name_path_alternatives' => ['subject', 'title', 'formattedTitle'],
        ],
        'result' => ['ok_path' => 'status', 'ok_equals' => true, 'message_path' => 'message', 'http_ok_only' => false],
    ],

];
