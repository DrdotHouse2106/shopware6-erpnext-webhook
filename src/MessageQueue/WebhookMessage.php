<?php

declare(strict_types=1);

namespace ShopwareWebhookConnector\MessageQueue;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * Nachricht für den asynchronen Webhook-Versand über die Shopware Message Queue.
 *
 * Enthält nur serialisierbare Skalare/Arrays. Die eigentliche Zustellung
 * (Payload, Signatur, Wiederholungen) übernimmt der WebhookMessageHandler.
 */
final class WebhookMessage implements AsyncMessageInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly string $eventType,
        private readonly array $data,
        private readonly ?string $salesChannelId = null
    ) {
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getSalesChannelId(): ?string
    {
        return $this->salesChannelId;
    }
}
