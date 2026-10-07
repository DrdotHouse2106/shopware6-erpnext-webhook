# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-10-07

### Security
- Event toggles in the plugin configuration are now respected. Previously a non-existent config key was read, so every event (including `customer.written` with e-mail addresses) was always sent.
- Webhook URL must use `https://` (plain `http://` only for localhost). Other URLs are rejected and logged.
- ERPNext preset no longer sends a signature header when no secret is configured (an HMAC with an empty key only pretended to be a signature).
- Response bodies and error messages are truncated to 500 characters before logging.
- GitHub Actions in the release workflow are pinned to commit SHAs.

### Changed
- Webhooks are delivered asynchronously via the Shopware message queue by default (new option "Send asynchronously"). Checkout, login and admin requests no longer wait for the target system. When disabled, exactly one synchronous attempt is made without retries.
- The configured request timeout is now actually used (1–120 s, previously hard-coded 30 s). Retry count is capped at 10.
- README: signature verification examples now reject unsigned requests and include replay protection via the payload timestamp.

## [1.0.0] - 2025-01-01

### Added
- Initial release
- Preset system for ERPNext, n8n, and custom webhooks
- Order events: placed, updated, state changed
- Payment transaction state change events
- Customer written events
- HMAC-SHA256 signature verification
- Retry logic with exponential backoff
- Checkout custom fields (PO number, Tel. Avis, Forklift, Invoice Email)
- Admin configuration panel
- German and English translations
- GitHub Actions release workflow
