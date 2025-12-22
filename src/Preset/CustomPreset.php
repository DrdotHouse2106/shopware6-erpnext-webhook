<?php

declare(strict_types=1);

namespace ShopwareWebhookConnector\Preset;

/**
 * Custom webhook preset with configurable format.
 *
 * Default payload format:
 * {
 *   "event": "order.placed",
 *   "data": { ... },
 *   "timestamp": 1234567890
 * }
 *
 * Signature: HMAC-SHA256 in X-Webhook-Signature header
 */
class CustomPreset implements PresetInterface
{
    public function getName(): string
    {
        return 'custom';
    }

    public function getLabel(): string
    {
        return 'Custom';
    }

    public function buildPayload(string $eventType, array $data): array
    {
        return [
            'event' => $eventType,
            'data' => $data,
            'timestamp' => time(),
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
        return 'X-Webhook-Signature';
    }
}
