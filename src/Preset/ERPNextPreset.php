<?php

declare(strict_types=1);

namespace ShopwareWebhookConnector\Preset;

/**
 * ERPNext webhook preset.
 *
 * Payload format:
 * {
 *   "event": "order.placed",
 *   "data": { ... },
 *   "timestamp": 1234567890,
 *   "source": "shopware6"
 * }
 *
 * Signature: HMAC-SHA256 in X-Shopware-Signature header.
 * Ohne konfiguriertes Secret wird KEIN Signatur-Header gesendet – eine Signatur
 * mit leerem Schlüssel wäre wertlos und würde dem Empfänger Sicherheit vortäuschen.
 */
class ERPNextPreset implements PresetInterface
{
    public function getName(): string
    {
        return 'erpnext';
    }

    public function getLabel(): string
    {
        return 'ERPNext';
    }

    public function buildPayload(string $eventType, array $data): array
    {
        return [
            'event' => $eventType,
            'data' => $data,
            'timestamp' => time(),
            'source' => 'shopware6',
        ];
    }

    public function getHeaders(string $payload, string $secret): array
    {
        if ($secret === '') {
            return [];
        }

        $signature = hash_hmac('sha256', $payload, $secret);

        return [
            $this->getSignatureHeaderName() => $signature,
        ];
    }

    public function getSignatureHeaderName(): string
    {
        return 'X-Shopware-Signature';
    }
}
