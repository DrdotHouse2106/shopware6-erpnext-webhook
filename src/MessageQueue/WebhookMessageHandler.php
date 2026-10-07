<?php

declare(strict_types=1);

namespace ShopwareWebhookConnector\MessageQueue;

use ShopwareWebhookConnector\Service\WebhookService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Verarbeitet WebhookMessage im Queue-Worker (bin/console messenger:consume
 * bzw. Admin-Worker). Dadurch blockiert der Versand nicht mehr den
 * Kundenrequest (Checkout, Login, Admin-API).
 */
#[AsMessageHandler(handles: WebhookMessage::class)]
final class WebhookMessageHandler
{
    public function __construct(private readonly WebhookService $webhookService)
    {
    }

    public function __invoke(WebhookMessage $message): void
    {
        $this->webhookService->deliver(
            $message->getEventType(),
            $message->getData(),
            $message->getSalesChannelId()
        );
    }
}
