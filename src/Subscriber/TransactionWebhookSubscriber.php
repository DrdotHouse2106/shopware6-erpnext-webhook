<?php

declare(strict_types=1);

namespace ShopwareWebhookConnector\Subscriber;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use ShopwareWebhookConnector\Service\WebhookService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Psr\Log\LoggerInterface;

/**
 * Handle order transaction state changes (payment status updates).
 *
 * This is triggered when payment status changes (e.g., PayPal marks payment as paid).
 */
class TransactionWebhookSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly WebhookService $webhookService,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'state_machine.order_transaction.state_changed' => 'onOrderTransactionStateChanged',
        ];
    }

    /**
     * Handle order transaction state changes.
     *
     * Note: The event 'state_machine.order_transaction.state_changed' dispatches
     * StateMachineStateChangeEvent, not StateMachineTransitionEvent.
     */
    public function onOrderTransactionStateChanged(StateMachineStateChangeEvent $event): void
    {
        $transition = $event->getTransition();
        $transactionId = $transition->getEntityId();
        $newState = $event->getNextState()->getTechnicalName();
        $previousState = $event->getPreviousState()?->getTechnicalName() ?? 'unknown';

        try {
            // Load the transaction to get the order
            $criteria = new Criteria([$transactionId]);
            $criteria->addAssociation('order');
            $transaction = $this->orderTransactionRepository->search(
                $criteria,
                Context::createDefaultContext()
            )->first();

            if (!$transaction) {
                $this->logger->warning('Transaction not found', ['transactionId' => $transactionId]);
                return;
            }

            $order = $transaction->getOrder();
            if (!$order) {
                $this->logger->warning('Order not found for transaction', ['transactionId' => $transactionId]);
                return;
            }

            $paymentMethodId = $transaction->getPaymentMethodId();

            $this->webhookService->sendWebhook('order_transaction.state.changed', [
                'orderId' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'transactionId' => $transactionId,
                'paymentMethodId' => $paymentMethodId,
                'state' => $newState,
                'previousState' => $previousState,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error handling transaction state change', [
                'transactionId' => $transactionId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
