<?php

declare(strict_types=1);

namespace ShopwareWebhookConnector\Preset;

/**
 * n8n webhook preset.
 *
 * Payload format:
 * {
 *   "event": "order.placed",
 *   "payload": { ... },
 *   "meta": {
 *     "timestamp": "2025-01-01T12:00:00Z",
 *     "source": "shopware6",
 *     "version": "1.0.0"
 *   }
 * }
 *
 * Signature: HMAC-SHA256 in X-N8N-Signature header
 */
class N8nPreset implements PresetInterface
{
    public function getName(): string
    {
        return 'n8n';
    }

    public function getLabel(): string
    {
        return 'n8n';
    }

    public function buildPayload(string $eventType, array $data): array
    {
        return [
            'event' => $eventType,
            'payload' => $data,
            'meta' => [
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'source' => 'shopware6',
                'version' => '1.0.0',
            ],
        ];
    }

    public function getHeaders(string $payload, string $secret): array
    {
        if (empty($secret)) {
            return [];
        }

        $signature = hash_hmac('sha256', $payload, $secret);

        return [
            $this->getSignatureHeaderName() => $signature,
        ];
    }

    public function getSignatureHeaderName(): string
    {
        return 'X-N8N-Signature';
    }
}
