<?php

declare(strict_types=1);

namespace ShopwareWebhookConnector\Preset;

interface PresetInterface
{
    /**
     * Get the unique identifier for this preset.
     */
    public function getName(): string;

    /**
     * Get the human-readable label for this preset.
     */
    public function getLabel(): string;

    /**
     * Build the webhook payload for the given event and data.
     *
     * @param string $eventType The event type (e.g., 'order.placed')
     * @param array<string, mixed> $data The event data
     * @return array<string, mixed> The formatted payload
     */
    public function buildPayload(string $eventType, array $data): array;

    /**
     * Get additional headers to send with the webhook request.
     *
     * @param string $payload The JSON-encoded payload
     * @param string $secret The webhook secret
     * @return array<string, string> Additional headers
     */
    public function getHeaders(string $payload, string $secret): array;

    /**
     * Get the signature header name for this preset.
     */
    public function getSignatureHeaderName(): string;
}
