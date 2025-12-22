<?php

declare(strict_types=1);

namespace ShopwareWebhookConnector\Subscriber;

use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use ShopwareWebhookConnector\Service\WebhookService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Psr\Log\LoggerInterface;

class OrderWebhookSubscriber implements EventSubscriberInterface
{
    private const CONFIG_PREFIX = 'ShopwareWebhookConnector.config.';

    /**
     * Track orders we've already processed to avoid duplicates in same request.
     *
     * @var array<string, bool>
     */
    private static array $processedOrders = [];

    public function __construct(
        private readonly WebhookService $webhookService,
        private readonly EntityRepository $orderRepository,
        private readonly SystemConfigService $systemConfigService,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OrderEvents::ORDER_WRITTEN_EVENT => 'onOrderWritten',
            'state_machine.order.state_changed' => 'onOrderStateChanged',
        ];
    }

    /**
     * Handle order written events (new orders and updates).
     *
     * TIMING NOTE: Custom fields are often saved AFTER order creation via a separate API call.
     * The ORDER_WRITTEN_EVENT fires twice:
     * 1. On order creation (has orderNumber, customFields empty)
     * 2. On custom fields update (no orderNumber, customFields filled)
     */
    public function onOrderWritten(EntityWrittenEvent $event): void
    {
        foreach ($event->getWriteResults() as $result) {
            $payload = $result->getPayload();

            if (!isset($payload['id'])) {
                continue;
            }

            $orderId = $payload['id'];
            $isNewOrder = isset($payload['orderNumber']);
            $hasCustomFieldsInPayload = isset($payload['customFields']) && !empty($payload['customFields']);

            // Skip if we've already processed this order in this request cycle
            $processKey = $orderId . '_' . ($isNewOrder ? 'new' : 'update');
            if (isset(self::$processedOrders[$processKey])) {
                continue;
            }
            self::$processedOrders[$processKey] = true;

            // Load order from database to get current state including custom fields
            $order = $this->loadOrder($orderId, $event->getContext());

            if (!$order) {
                continue;
            }

            $customFields = $order->getCustomFields() ?? [];
            $orderNumber = $order->getOrderNumber();

            // Check for custom fields that we care about
            $enableCustomFields = (bool) $this->systemConfigService->get(
                self::CONFIG_PREFIX . 'enableCheckoutCustomFields'
            );

            $hasRelevantCustomFields = $enableCustomFields && (
                !empty($customFields['custom_po_number'])
                || !empty($customFields['custom_tel_avis'])
                || !empty($customFields['custom_forklift_required'])
                || !empty($customFields['invoice_email'])
            );

            // Determine event type
            $eventType = 'order.placed';

            if (!$isNewOrder && $hasRelevantCustomFields) {
                // Custom fields update
                $eventType = 'order.updated';
            } elseif (!$isNewOrder && !$hasRelevantCustomFields) {
                // Update without relevant custom fields - skip
                continue;
            }

            $webhookData = [
                'orderId' => $orderId,
                'orderNumber' => $orderNumber,
                'isUpdate' => !$isNewOrder,
            ];

            // Include custom fields if enabled
            if ($enableCustomFields) {
                $webhookData['customFields'] = [
                    'custom_po_number' => $customFields['custom_po_number'] ?? null,
                    'custom_tel_avis' => $customFields['custom_tel_avis'] ?? false,
                    'custom_forklift_required' => $customFields['custom_forklift_required'] ?? false,
                    'invoice_email' => $customFields['invoice_email'] ?? null,
                ];
            }

            $this->webhookService->sendWebhook($eventType, $webhookData);
        }
    }

    /**
     * Handle order state machine state changes.
     */
    public function onOrderStateChanged(OrderStateMachineStateChangeEvent $event): void
    {
        $order = $event->getOrder();

        $this->webhookService->sendWebhook('order.state.changed', [
            'orderId' => $order->getId(),
            'orderNumber' => $order->getOrderNumber(),
            'state' => $event->getToPlace()->getTechnicalName(),
            'previousState' => $event->getFromPlace()->getTechnicalName(),
        ]);
    }

    /**
     * Load order from database with all fields.
     */
    private function loadOrder(string $orderId, Context $context): ?\Shopware\Core\Checkout\Order\OrderEntity
    {
        try {
            $criteria = new Criteria([$orderId]);
            return $this->orderRepository->search($criteria, $context)->first();
        } catch (\Exception $e) {
            $this->logger->error('Error loading order', [
                'orderId' => $orderId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
