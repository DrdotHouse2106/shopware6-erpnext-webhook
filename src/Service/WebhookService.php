<?php

declare(strict_types=1);

namespace ShopwareWebhookConnector\Service;

use Psr\Log\LoggerInterface;
use ShopwareWebhookConnector\Preset\PresetInterface;
use ShopwareWebhookConnector\Preset\ERPNextPreset;
use ShopwareWebhookConnector\Preset\N8nPreset;
use ShopwareWebhookConnector\Preset\CustomPreset;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WebhookService
{
    private const CONFIG_PREFIX = 'ShopwareWebhookConnector.config.';

    /** @var array<string, PresetInterface> */
    private array $presets;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SystemConfigService $systemConfigService,
        private readonly LoggerInterface $logger
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
     * @param string $eventType The event type (e.g., 'order.placed')
     * @param array<string, mixed> $data The event data
     * @param string|null $salesChannelId Optional sales channel ID for config lookup
     */
    public function sendWebhook(string $eventType, array $data, ?string $salesChannelId = null): void
    {
        // Check if this event is enabled
        if (!$this->isEventEnabled($eventType, $salesChannelId)) {
            return;
        }

        $webhookUrl = $this->getConfig('webhookUrl', $salesChannelId);
        $webhookSecret = $this->getConfig('webhookSecret', $salesChannelId);
        $presetName = $this->getConfig('preset', $salesChannelId) ?? 'erpnext';
        $retryCount = (int) ($this->getConfig('retryCount', $salesChannelId) ?? 3);
        $retryDelay = (int) ($this->getConfig('retryDelay', $salesChannelId) ?? 1000);

        if (empty($webhookUrl)) {
            $this->logger->warning('Webhook URL is not configured');
            return;
        }

        $preset = $this->getPreset($presetName);
        $payload = json_encode($preset->buildPayload($eventType, $data));

        if ($payload === false) {
            $this->logger->error('Failed to encode webhook payload', ['eventType' => $eventType]);
            return;
        }

        $headers = array_merge(
            ['Content-Type' => 'application/json'],
            $preset->getHeaders($payload, $webhookSecret ?? '')
        );

        $this->sendWithRetry($webhookUrl, $payload, $headers, $eventType, $retryCount, $retryDelay);
    }

    /**
     * Send the webhook request with retry logic.
     */
    private function sendWithRetry(
        string $url,
        string $payload,
        array $headers,
        string $eventType,
        int $retryCount,
        int $retryDelayMs
    ): void {
        $attempt = 0;
        $lastException = null;

        while ($attempt <= $retryCount) {
            try {
                $response = $this->httpClient->request('POST', $url, [
                    'headers' => $headers,
                    'body' => $payload,
                    'timeout' => 30,
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
                    'response' => $response->getContent(false),
                    'attempt' => $attempt + 1,
                ]);

                // Don't retry on client errors (4xx)
                if ($statusCode >= 400 && $statusCode < 500) {
                    return;
                }
            } catch (\Exception $e) {
                $lastException = $e;
                $this->logger->warning('Webhook request failed', [
                    'event' => $eventType,
                    'error' => $e->getMessage(),
                    'attempt' => $attempt + 1,
                ]);
            }

            $attempt++;

            if ($attempt <= $retryCount) {
                // Exponential backoff
                $delay = $retryDelayMs * (2 ** ($attempt - 1));
                usleep($delay * 1000);
            }
        }

        $this->logger->error('Webhook failed after all retries', [
            'event' => $eventType,
            'attempts' => $retryCount + 1,
            'lastError' => $lastException?->getMessage(),
        ]);
    }

    /**
     * Check if a specific event type is enabled.
     */
    private function isEventEnabled(string $eventType, ?string $salesChannelId): bool
    {
        $enabledEvents = $this->getConfig('enabledEvents', $salesChannelId);

        if (empty($enabledEvents)) {
            // Default: all events enabled
            return true;
        }

        if (is_string($enabledEvents)) {
            $enabledEvents = json_decode($enabledEvents, true) ?? [];
        }

        return in_array($eventType, $enabledEvents, true);
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
