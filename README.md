# Shopware 6 Webhook Connector

A generic webhook connector plugin for Shopware 6 with presets for ERPNext, n8n, and custom endpoints.

## Features

- **Preset System**: Choose from ERPNext, n8n, or custom webhook formats
- **Multiple Events**: Order placed, order updated, order state changed, payment state changed, customer written
- **HMAC-SHA256 Signing**: Secure webhook delivery with signature verification
- **Retry Logic**: Automatic retries with exponential backoff on failure
- **Checkout Custom Fields**: Optional fields for PO number, telephone notification, forklift requirement, and alternative invoice email
- **Sales Channel Support**: Configure different webhooks per sales channel

## Requirements

- Shopware 6.5.x, 6.6.x, or 6.7.x
- PHP 8.1 or higher

## Installation

### Via Composer (recommended)

```bash
composer require tubaapollo/shopware-webhook-connector
bin/console plugin:refresh
bin/console plugin:install --activate ShopwareWebhookConnector
bin/console cache:clear
```

### Manual Installation

1. Download the latest release from [GitHub Releases](https://github.com/TubaApollo/shopware6-erpnext-webhook/releases)
2. Extract to `custom/plugins/ShopwareWebhookConnector`
3. Run:
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate ShopwareWebhookConnector
   bin/console cache:clear
   ```

## Configuration

Navigate to **Settings > System > Plugins > Webhook Connector** in the Shopware Admin.

### Preset Selection

| Preset | Payload Format | Signature Header |
|--------|---------------|------------------|
| ERPNext | `{event, data, timestamp, source}` | `X-Shopware-Signature` |
| n8n | `{event, payload, meta}` | `X-N8N-Signature` |
| Custom | `{event, data, timestamp}` | `X-Webhook-Signature` |

### Webhook URL Examples

**ERPNext:**
```
https://your-erpnext.com/api/method/ecommerce_integrations.shopware6.connection.webhook_handler
```

**n8n:**
```
https://your-n8n.com/webhook/your-webhook-id
```

### Events

| Event | Description |
|-------|-------------|
| `order.placed` | New order created |
| `order.updated` | Order custom fields updated |
| `order.state.changed` | Order state machine transition |
| `order_transaction.state.changed` | Payment status changed |
| `customer.written` | Customer created or updated |

## Payload Examples

### ERPNext Preset

```json
{
  "event": "order.placed",
  "data": {
    "orderId": "0190a1b2-c3d4-e5f6-g7h8-i9j0k1l2m3n4",
    "orderNumber": "10001",
    "isUpdate": false,
    "customFields": {
      "custom_po_number": "PO-2025-001",
      "custom_tel_avis": true,
      "custom_forklift_required": false,
      "invoice_email": "accounting@company.com"
    }
  },
  "timestamp": 1704067200,
  "source": "shopware6"
}
```

### n8n Preset

```json
{
  "event": "order.placed",
  "payload": {
    "orderId": "0190a1b2-c3d4-e5f6-g7h8-i9j0k1l2m3n4",
    "orderNumber": "10001"
  },
  "meta": {
    "timestamp": "2025-01-01T12:00:00Z",
    "source": "shopware6",
    "version": "1.0.0"
  }
}
```

## Security

- **HTTPS only**: the webhook URL must use `https://`. Plain `http://` is accepted only for `localhost` / `127.0.0.1` during development. Other URLs are rejected and logged.
- **Always configure a secret**: without a secret *no* signature header is sent (for all presets). Your receiver should reject unsigned requests.
- **Asynchronous delivery** (default): webhooks are queued via the Shopware message queue and sent by the worker, so checkout, login and admin requests never wait for your ERP. Make sure a worker is running (admin worker or `bin/console messenger:consume async`). If you disable it, exactly one synchronous attempt is made without retries.
- **Replay protection**: the signed payload contains a `timestamp`. Reject requests whose timestamp is older than a few minutes (see below) and, if needed, de-duplicate on `orderId` + `event`.
- Response bodies from your endpoint are logged truncated to 500 characters.

## Signature Verification

All webhooks are signed using HMAC-SHA256 over the raw request body when a secret is configured. Verify the signature **and** the timestamp:

```php
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_SHOPWARE_SIGNATURE'] ?? '';
$secret = 'your-webhook-secret';

if ($signature === '') {
    http_response_code(401); // unsigned request – reject
    exit;
}

$expectedSignature = hash_hmac('sha256', $payload, $secret);

if (!hash_equals($expectedSignature, $signature)) {
    http_response_code(401);
    exit;
}

// Replay protection: payload timestamp must be recent (ERPNext/custom: unix time, n8n: meta.timestamp ISO-8601)
$data = json_decode($payload, true);
$timestamp = $data['timestamp'] ?? strtotime($data['meta']['timestamp'] ?? '') ?: 0;

if (abs(time() - (int) $timestamp) > 300) {
    http_response_code(401); // older than 5 minutes – possible replay
    exit;
}

// Valid request
```

### Python Example

```python
import hmac
import hashlib
import json
import time

def verify_webhook(payload: bytes, signature: str, secret: str, max_age: int = 300) -> bool:
    if not signature:
        return False  # unsigned request
    expected = hmac.new(secret.encode(), payload, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, signature):
        return False
    data = json.loads(payload)
    timestamp = int(data.get("timestamp", 0))
    return abs(time.time() - timestamp) <= max_age  # replay protection
```

## Checkout Custom Fields

When enabled, the following custom fields are added to orders:

| Field | Type | Description |
|-------|------|-------------|
| `custom_po_number` | Text | Customer's internal order reference |
| `custom_tel_avis` | Boolean | Telephone notification requested |
| `custom_forklift_required` | Boolean | Forklift/dock equipment needed |
| `invoice_email` | Text | Alternative invoice email address |

## Troubleshooting

### Webhooks not being sent

1. Check that the webhook URL is configured and uses `https://`
2. Verify the events you want are enabled
3. Make sure the message queue worker is running (`bin/console messenger:consume async` or the admin worker), or disable "Send asynchronously"
4. Check the Shopware logs at `var/log/`

### Signature verification fails

1. Ensure the secret matches on both sides
2. Check for any whitespace in the secret
3. Verify you're comparing the raw payload, not a parsed version

### Retries not working

Retries only apply to asynchronous delivery. The plugin uses exponential backoff:
- 1st retry: `retryDelay` ms
- 2nd retry: `retryDelay * 2` ms
- 3rd retry: `retryDelay * 4` ms

4xx errors are not retried (client errors).

## Development

```bash
# Clone the repository
git clone https://github.com/TubaApollo/shopware6-erpnext-webhook.git

# Install in Shopware
ln -s /path/to/shopware6-erpnext-webhook /path/to/shopware/custom/plugins/ShopwareWebhookConnector

# Activate
bin/console plugin:refresh
bin/console plugin:install --activate ShopwareWebhookConnector
```

## License

MIT License - see [LICENSE](LICENSE) for details.

## Contributing

Pull requests are welcome! Please ensure your code follows PSR-12 coding standards.

## Support

- [GitHub Issues](https://github.com/TubaApollo/shopware6-erpnext-webhook/issues)
- [Shopware Community](https://community.shopware.com/)
