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

- Shopware 6.5.x or 6.6.x
- PHP 8.1 or higher

## Installation

### Via Composer (recommended)

```bash
composer require kreckler/shopware-webhook-connector
bin/console plugin:refresh
bin/console plugin:install --activate ShopwareWebhookConnector
bin/console cache:clear
```

### Manual Installation

1. Download the latest release from [GitHub Releases](https://github.com/kreckler/shopware6-erpnext-webhook/releases)
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

## Signature Verification

All webhooks are signed using HMAC-SHA256. To verify:

```php
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_SHOPWARE_SIGNATURE'] ?? '';
$secret = 'your-webhook-secret';

$expectedSignature = hash_hmac('sha256', $payload, $secret);

if (hash_equals($expectedSignature, $signature)) {
    // Valid signature
}
```

### Python Example

```python
import hmac
import hashlib

def verify_signature(payload: bytes, signature: str, secret: str) -> bool:
    expected = hmac.new(
        secret.encode(),
        payload,
        hashlib.sha256
    ).hexdigest()
    return hmac.compare_digest(expected, signature)
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

1. Check that the webhook URL is configured
2. Verify the events you want are enabled
3. Check the Shopware logs at `var/log/`

### Signature verification fails

1. Ensure the secret matches on both sides
2. Check for any whitespace in the secret
3. Verify you're comparing the raw payload, not a parsed version

### Retries not working

The plugin uses exponential backoff:
- 1st retry: `retryDelay` ms
- 2nd retry: `retryDelay * 2` ms
- 3rd retry: `retryDelay * 4` ms

4xx errors are not retried (client errors).

## Development

```bash
# Clone the repository
git clone https://github.com/kreckler/shopware6-erpnext-webhook.git

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

- [GitHub Issues](https://github.com/kreckler/shopware6-erpnext-webhook/issues)
- [Shopware Community](https://community.shopware.com/)
