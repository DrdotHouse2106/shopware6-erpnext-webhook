<?php

declare(strict_types=1);

namespace ShopwareWebhookConnector\Service;

use Psr\Log\LoggerInterface;
use ShopwareWebhookConnector\MessageQueue\WebhookMessage;
use ShopwareWebhookConnector\Preset\CustomPreset;
use ShopwareWebhookConnector\Preset\ERPNextPreset;
use ShopwareWebhookConnector\Preset\N8nPreset;
use ShopwareWebhookConnector\Preset\PresetInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WebhookService
{
    private const CONFIG_PREFIX = 'ShopwareWebhookConnector.config.';

    /** Antwort-Bodies des Zielsystems werden im Log auf diese Länge gekürzt. */
    private const MAX_LOGGED_RESPONSE_LENGTH = 500;

    private const DEFAULT_TIMEOUT_SECONDS = 30;
    private const MAX_TIMEOUT_SECONDS = 120;
    private const MAX_RETRY_COUNT = 10;

    /**
     * Zuordnung Event-Typ → Konfigurationsschlüssel (config.xml, Karte "Aktivierte Events").
     * Unbekannte Event-Typen werden nicht gesendet.
     */
    private const EVENT_CONFIG_KEYS = [
        'order.placed' => 'eventOrderPlaced',
        'order.updated' => 'eventOrderUpdated',
        'order.state.changed' => 'eventOrderStateChanged',
        'order_transaction.state.changed' => 'eventTransactionStateChanged',
        'customer.written' => 'eventCustomerWritten',
    ];

    /** Hosts, für die ausnahmsweise unverschlüsseltes http erlaubt ist (lokale Entwicklung). */
    private const PLAIN_HTTP_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    /** @var array<string, PresetInterface> */
    private array $presets;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SystemConfigService $systemConfigService,
        private readonly LoggerInterface $logger,
        private readonly ?MessageBusInterface $messageBus = null
    ) {
        $this->presets = [
            'erpnext' => new ERPNextPreset(),
            'n8n' => new N8nPreset(),
            'custom' => new CustomPreset(),
        ];
    }

    /**
     * Send a webhook for the given event.
     *
     * Standard: Die Zustellung wird in die Message Queue gestellt und vom Worker
     * erledigt, damit Checkout/Login nicht auf das Zielsystem warten müssen.
     * Ist "Asynchron senden" deaktiviert, erfolgt genau ein Zustellversuch
     * synchron im laufenden Request (ohne Wiederholungen).
     *
     * @param string $eventType The event type (e.g., 'order.placed')
     * @param array<string, mixed> $data The event data
     * @param string|null $salesChannelId Optional sales channel ID for config lookup
     */
    public function sendWebhook(string $eventType, array $data, ?string $salesChannelId = null): void
    {
        if (!$this->isEventEnabled($eventType, $salesChannelId)) {
            return;
        }

        $webhookUrl = $this->getConfig('webhookUrl', $salesChannelId);

        if (!\is_string($webhookUrl) || trim($webhookUrl) === '') {
            $this->logger->warning('Webhook URL is not configured');
            return;
        }

        if (!$this->isUrlAllowed($webhookUrl)) {
            $this->logger->error('Webhook URL rejected: only https:// is allowed (http only for localhost)', [
                'event' => $eventType,
            ]);
            return;
        }

        if ($this->isAsync($salesChannelId) && $this->messageBus !== null) {
            $this->messageBus->dispatch(new WebhookMessage($eventType, $data, $salesChannelId));
            return;
        }

        // Synchron: genau ein Versuch, damit der Kundenrequest nicht blockiert
        $this->deliver($eventType, $data, $salesChannelId, 0);
    }

    /**
     * Stellt den Webhook tatsächlich zu (wird vom Queue-Handler oder synchron aufgerufen).
     *
     * @param array<string, mixed> $data
     * @param int|null $retryCountOverride null = Wert aus der Konfiguration verwenden
     */
    public function deliver(string $eventType, array $data, ?string $salesChannelId = null, ?int $retryCountOverride = null): void
    {
        $webhookUrl = $this->getConfig('webhookUrl', $salesChannelId);

        if (!\is_string($webhookUrl) || trim($webhookUrl) === '') {
            $this->logger->warning('Webhook URL is not configured');
            return;
        }

        // Erneut prüfen: Die Konfiguration kann sich zwischen Dispatch und Verarbeitung geändert haben
        if (!$this->isUrlAllowed($webhookUrl)) {
            $this->logger->error('Webhook URL rejected: only https:// is allowed (http only for localhost)', [
                'event' => $eventType,
            ]);
            return;
        }

        $webhookSecret = $this->getConfig('webhookSecret', $salesChannelId);
        $presetName = $this->getConfig('preset', $salesChannelId) ?? 'erpnext';
        $retryCount = $retryCountOverride ?? $this->getRetryCount($salesChannelId);
        $retryDelay = max(0, (int) ($this->getConfig('retryDelay', $salesChannelId) ?? 1000));
        $timeout = $this->getTimeout($salesChannelId);

        $preset = $this->getPreset(\is_string($presetName) ? $presetName : 'erpnext');
        $payload = json_encode($preset->buildPayload($eventType, $data));

        if ($payload === false) {
            $this->logger->error('Failed to encode webhook payload', ['eventType' => $eventType]);
            return;
        }

        $headers = array_merge(
            ['Content-Type' => 'application/json'],
            $preset->getHeaders($payload, \is_string($webhookSecret) ? $webhookSecret : '')
        );

        $this->sendWithRetry($webhookUrl, $payload, $headers, $eventType, $retryCount, $retryDelay, $timeout);
    }

    /**
     * Send the webhook request with retry logic.
     *
     * @param array<string, string> $headers
     */
    private function sendWithRetry(
        string $url,
        string $payload,
        array $headers,
        string $eventType,
        int $retryCount,
        int $retryDelayMs,
        int $timeoutSeconds
    ): void {
        $attempt = 0;
        $lastException = null;

        while ($attempt <= $retryCount) {
            try {
                $response = $this->httpClient->request('POST', $url, [
                    'headers' => $headers,
                    'body' => $payload,
                    'timeout' => $timeoutSeconds,
                    'max_duration' => $timeoutSeconds * 2,
                ]);

                $statusCode = $response->getStatusCode();

                if ($statusCode >= 200 && $statusCode < 300) {
                    $this->logger->info('Webhook sent successfully', [
                        'event' => $eventType,
                        'status' => $statusCode,
                        'attempt' => $attempt + 1,
                    ]);
                    return;
                }

                $this->logger->warning('Webhook returned non-success status', [
                    'event' => $eventType,
                    'status' => $statusCode,
                    'response' => $this->truncate($response->getContent(false)),
                    'attempt' => $attempt + 1,
                ]);

                // Don't retry on client errors (4xx)
                if ($statusCode >= 400 && $statusCode < 500) {
                    return;
                }
            } catch (\Throwable $e) {
                $lastException = $e;
                $this->logger->warning('Webhook request failed', [
                    'event' => $eventType,
                    'error' => $this->truncate($e->getMessage()),
                    'attempt' => $attempt + 1,
                ]);
            }

            $attempt++;

            if ($attempt <= $retryCount && $retryDelayMs > 0) {
                // Exponential backoff
                $delay = $retryDelayMs * (2 ** ($attempt - 1));
                usleep($delay * 1000);
            }
        }

        $this->logger->error('Webhook failed after all retries', [
            'event' => $eventType,
            'attempts' => $retryCount + 1,
            'lastError' => $lastException !== null ? $this->truncate($lastException->getMessage()) : null,
        ]);
    }

    /**
     * Check if a specific event type is enabled (Karte "Aktivierte Events" in der Plugin-Konfiguration).
     * Nicht gesetzte Werte gelten als aktiviert (entspricht den defaultValues in config.xml).
     */
    private function isEventEnabled(string $eventType, ?string $salesChannelId): bool
    {
        $configKey = self::EVENT_CONFIG_KEYS[$eventType] ?? null;

        if ($configKey === null) {
            $this->logger->warning('Unknown webhook event type, not sending', ['event' => $eventType]);
            return false;
        }

        $value = $this->getConfig($configKey, $salesChannelId);

        return $value === null ? true : (bool) $value;
    }

    /**
     * Asynchron über die Message Queue senden? Standard: ja.
     */
    private function isAsync(?string $salesChannelId): bool
    {
        $value = $this->getConfig('asyncDelivery', $salesChannelId);

        return $value === null ? true : (bool) $value;
    }

    /**
     * Nur https ist erlaubt; http ausschließlich für lokale Entwicklungs-Hosts.
     */
    public function isUrlAllowed(string $url): bool
    {
        $parts = parse_url(trim($url));

        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);

        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http' && \in_array($host, self::PLAIN_HTTP_HOSTS, true);
    }

    private function getRetryCount(?string $salesChannelId): int
    {
        $value = (int) ($this->getConfig('retryCount', $salesChannelId) ?? 3);

        return max(0, min(self::MAX_RETRY_COUNT, $value));
    }

    private function getTimeout(?string $salesChannelId): int
    {
        $value = (int) ($this->getConfig('timeout', $salesChannelId) ?? self::DEFAULT_TIMEOUT_SECONDS);

        return max(1, min(self::MAX_TIMEOUT_SECONDS, $value));
    }

    private function truncate(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_LOGGED_RESPONSE_LENGTH) {
            return $text;
        }

        return mb_substr($text, 0, self::MAX_LOGGED_RESPONSE_LENGTH) . '…';
    }

    /**
     * Get a preset by name.
     */
    private function getPreset(string $name): PresetInterface
    {
        return $this->presets[$name] ?? $this->presets['custom'];
    }

    /**
     * Get a configuration value.
     */
    private function getConfig(string $key, ?string $salesChannelId): mixed
    {
        return $this->systemConfigService->get(self::CONFIG_PREFIX . $key, $salesChannelId);
    }

    /**
     * Get all available presets.
     *
     * @return array<string, PresetInterface>
     */
    public function getAvailablePresets(): array
    {
        return $this->presets;
    }
}
