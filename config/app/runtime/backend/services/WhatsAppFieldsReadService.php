<?php

declare(strict_types=1);

final class WhatsAppFieldsReadService
{
    public function __construct(private LinnmsRepository $db)
    {
    }

    public function read(int $userId, string $provider): array
    {
        $definitions = WhatsAppGateway::providers();
        if ($provider === '' || !array_key_exists($provider, $definitions)) {
            return ['ok' => false, 'code' => 'validation', 'data' => []];
        }
        $definition = (array)$definitions[$provider];
        $settings = $this->db->getUserWhatsAppSettings($userId);
        $savedFields = [];
        foreach (['base_url', 'device', 'token'] as $field) {
            $savedFields[$field] = trim((string)($settings[$field] ?? '')) !== '' ? 'saved' : '';
        }
        $targetTypes = (array)($definition['target']['types'] ?? ['phone', 'group']);
        return ['ok' => true, 'code' => 'ok', 'data' => [
            'provider' => $provider,
            'definition' => $definition,
            'saved_fields' => $savedFields,
            'info_url' => (new WhatsAppGateway(['provider' => $provider]))->providerInfoUrl(),
            'supports_groups' => in_array('group', $targetTypes, true),
            'supports_group_listing' => trim((string)(((array)($definition['groups'] ?? []))['url'] ?? '')) !== '',
        ]];
    }
}
