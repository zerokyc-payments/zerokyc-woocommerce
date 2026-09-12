# zerokyc-woocommerce

Official WooCommerce payment gateway for the [ZeroKYC Pay](https://zerokyc-payments.com) crypto payment gateway.
Direct integration, zero external runtime Composer dependencies, HPOS compatible, PHP 8.1+, WordPress 6.5+, WooCommerce 8.0+.

The plugin is powered by the official [zkp-sdk-php](https://github.com/zerokyc-payments/zkp-sdk-php) core with a native WordPress HTTP API transport (`wp_remote_*`). Full server-side verification, replay-protected webhooks, and automatic order settlement.

The same public contract and security guarantees as the official SDK family:
[zkp-sdk-php](https://github.com/zerokyc-payments/zkp-sdk-php),
[zkp-sdk-python](https://github.com/zerokyc-payments/zkp-sdk-python), and
[zkp-sdk-node](https://github.com/zerokyc-payments/zkp-sdk-node).

## Features

- **Hosted checkout:** redirect-based payment flow — no wallet keys, no seed phrases, and no customer card data on your server.
- **Zero KYC for buyers:** seamless checkout across multiple crypto assets.
- **HMAC-SHA256 verified webhooks:** constant-time signature verification, replay protection backed by an atomic `{prefix}zerokyc_events` store.
- **HPOS compatible:** portable invoice-to-order mapping (`{prefix}zerokyc_invoices`) supporting both High-Performance Order Storage and classic custom post types.
- **Double-check verification:** option to query the ZeroKYC API server-side on `payment.confirmed` before transitioning the order to `processing`.
- **Background reconciliation:** WP-Cron poller every 15 minutes acts as a safety net for lost or delayed webhooks.
- **Idempotent processing:** stable idempotency keys prevent duplicate invoices on checkout retries.
- **Automated stock & expiry:** automatically cancels expired unpaid orders and releases inventory if enabled.
- **Admin connection probe:** one-click "Test connection" AJAX button validates API key and displays environment/ping status.
- **Sandbox ready:** automatically switches between Sandbox and Production based on API key prefix (`pk_test_...` vs `pk_live_...`).

## Supported assets

USDT (TRC-20), USDC/USDT (Polygon, Arbitrum), BTC, XMR, TON, USDT-TON.
You can pin a specific asset (e.g. `USDT_TRON`) in settings or leave it as `any` (default) to let the customer select at checkout.

## Requirements

- WordPress 6.5+
- WooCommerce 8.0+ (compatible with 9.x+ and HPOS)
- PHP 8.1+
- Store currency set to USD, EUR, or RUB
- A [ZeroKYC Pay](https://console.zerokyc-payments.com) account

## Installation

### From release zip

1. Download `zerokyc-pay.zip` from [Releases](https://github.com/zerokyc-payments/zerokyc-woocommerce/releases).
2. Go to **WordPress Admin → Plugins → Add New → Upload Plugin**.
3. Choose the zip file and click **Install Now**, then **Activate Plugin**.

### From WordPress.org directory

Search for `ZeroKYC` under **Plugins → Add New** and click **Install**.

## Configuration

In **WooCommerce → Settings → Payments → ZeroKYC Pay (crypto)**:

| Setting | Required | Description |
|---|---|---|
| **Enable** | yes | Enable/disable the payment gateway at checkout |
| **Title** | yes | Method title displayed to the customer (default: *ZeroKYC Pay (crypto)*) |
| **Description** | yes | Method description displayed to the customer |
| **API key** | yes | `pk_test_...` (sandbox) or `pk_live_...` (production) from console |
| **Webhook secret** | yes | `whsec_...` from console → Webhooks |
| **Double check** | no | Re-queries the API on payment confirmation before completing order (recommended: yes) |
| **Payment currency** | no | Pin a specific asset or `any` (default) |
| **Invoice TTL** | no | Invoice lifetime in minutes (default: 360) |
| **Cancel on expiry** | no | Automatically cancel order and restore stock when invoice expires (default: yes) |

The environment (Sandbox or Production) is detected automatically from the API key prefix.

## Webhook setup

1. In your ZeroKYC console, navigate to **Webhooks → Add endpoint**.
2. Set the endpoint URL to:
   ```
   https://your-domain.com/wp-json/zkp/v1/webhook
   ```
3. Subscribe to all payment events (`payment.confirmed`, `payment.underpaid`, `invoice.expired`).
4. Copy the webhook secret (`whsec_...`) and paste it into the plugin settings in WooCommerce.

## How it works

```
[Customer Checkout] ──> [process_payment()] ──> [ZeroKYC API (createInvoice)]
                                                        │
                                                        ▼
[Complete Order] <── [Verify Webhook / Ping] <── [Hosted Checkout URL]
```

1. **Checkout:** The customer selects ZeroKYC Pay and clicks Place Order.
2. **Invoice creation:** The gateway issues an idempotent request to ZeroKYC API and redirects the user to the secure hosted checkout.
3. **Payment:** Customer pays with their chosen cryptocurrency.
4. **Webhook:** ZeroKYC delivers a signed webhook to `/wp-json/zkp/v1/webhook`.
5. **Verification:** The plugin validates the HMAC-SHA256 signature, ensures replay safety via `{prefix}zerokyc_events`, optionally performs a server-side double check, and marks the WooCommerce order as `processing`.

## Development & testing

Docker Compose provides an isolated environment for testing with MariaDB, WordPress, and WP-CLI:

```bash
# Run unit & integration tests (PHPUnit 9.6)
docker compose run --rm tooling phpunit

# Run WordPress Coding Standards check (PHPCS)
docker compose run --rm tooling phpcs

# Build submission zip
./bin/build-zip.sh
```

### End-to-end sandbox verification

Run the automated E2E sandbox script against a local WordPress instance:

```bash
ZKP_E2E_API_KEY=pk_test_... ZKP_E2E_WEBHOOK_SECRET=whsec_... ./bin/e2e-sandbox.sh
```

## Security

- **Strict HMAC-SHA256:** Webhook signatures are checked in constant time over the raw request payload.
- **At-least-once safety:** The atomic `{prefix}zerokyc_events` table ensures that repeated webhook deliveries never trigger duplicate processing.
- **WP HTTP API:** All remote calls strictly use `wp_remote_post()` / `wp_remote_get()` — cURL is never called directly in the plugin layer.
- **Data privacy:** Customer personal data (name, email, shipping address) is never sent to ZeroKYC Pay.

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
The vendored SDK core ([zkp-sdk-php](https://github.com/zerokyc-payments/zkp-sdk-php)) is licensed under MIT.
