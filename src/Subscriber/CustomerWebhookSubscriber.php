<?php

declare(strict_types=1);

namespace ShopwareWebhookConnector\Subscriber;

use Shopware\Core\Checkout\Customer\CustomerEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use ShopwareWebhookConnector\Service\WebhookService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Handle customer written events.
 */
class CustomerWebhookSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly WebhookService $webhookService
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CustomerEvents::CUSTOMER_WRITTEN_EVENT => 'onCustomerWritten',
        ];
    }

    /**
     * Handle customer written events.
     */
    public function onCustomerWritten(EntityWrittenEvent $event): void
    {
        foreach ($event->getWriteResults() as $result) {
            $payload = $result->getPayload();

            if (!isset($payload['id'])) {
                continue;
            }

            $this->webhookService->sendWebhook('customer.written', [
                'customerId' => $payload['id'],
                'email' => $payload['email'] ?? null,
            ]);
        }
    }
}
