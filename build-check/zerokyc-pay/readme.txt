=== ZeroKYC Pay ===
Contributors: zerokypayments
Tags: payment-gateway, cryptocurrency, usdt, bitcoin, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Accept crypto payments (USDT, USDC, BTC, XMR, TON) in WooCommerce through the ZeroKYC Pay hosted checkout. No KYC for your buyers.

== Description ==

ZeroKYC Pay is a crypto payment gateway for digital services. This plugin connects your WooCommerce store to the ZeroKYC Pay hosted checkout: the buyer pays in crypto, you receive payouts to your own wallets, and orders are completed automatically.

**How it works**

1. At checkout the buyer picks ZeroKYC Pay and is redirected to a secure hosted checkout page.
2. The buyer pays in USDT (TRC-20), USDC or USDT (Polygon, Arbitrum), BTC, XMR, TON or USDT-TON — or one asset of your choice.
3. The plugin verifies the signed webhook (HMAC-SHA256, constant-time comparison, replay protection) and re-checks the invoice server-side before completing the order. A browser success URL is never treated as proof of payment.
4. A WP-Cron job polls open invoices every 15 minutes as a safety net for lost webhooks.

**Features**

* Redirect-based hosted checkout — no card data, no wallets on your server
* HMAC-verified webhooks with duplicate/replay protection
* Idempotent invoice creation (retries never create duplicate invoices)
* Server-side confirmation of every payment before an order is completed
* Amount and asset matching against the invoice for pinned assets
* Automatic order cancellation when an invoice expires unpaid (optional)
* Sandbox mode out of the box — test the full flow with a pk_test_... key
* WooCommerce high-performance order storage (HPOS) compatible
* No tracking, no external assets, no cookies

**Requirements**

* A ZeroKYC Pay account — [create one at console.zerokyc-payments.com](https://console.zerokyc-payments.com)
* Shop currency USD, EUR or RUB (invoice amounts)
* Classic WooCommerce checkout (block-based checkout support is on the roadmap)

**Pricing and legal**

ZeroKYC Pay is a paid service (1% transit fee or subscription plans). Fees are charged by the service, not by this plugin. See the [Terms of Service](https://zerokyc-payments.com/terms) and [Privacy Policy](https://zerokyc-payments.com/privacy). Cryptocurrency payments are final; refunds are processed manually by the merchant.

== Privacy ==

To process a payment, the plugin sends to ZeroKYC Pay: the order amount and currency, an order reference, an order description, and your site's URLs (checkout success and webhook). No customer personal data (name, address, email) is transmitted by the plugin. Webhook payloads and the plugin's own records (invoice ids, payment amounts) are stored in your database. See the [ZeroKYC Pay privacy policy](https://zerokyc-payments.com/privacy).

== Installation ==

1. Install and activate the plugin (WooCommerce must be active).
2. In WooCommerce → Settings → Payments enable **ZeroKYC Pay (crypto)**.
3. Copy your API key from [console.zerokyc-payments.com → API keys](https://console.zerokyc-payments.com) into the API key field. A pk_test_... key switches the gateway to sandbox automatically; pk_live_... runs in production.
4. Copy your webhook endpoint secret (whsec_...) from the console → Webhooks into the Webhook secret field. The endpoint URL to register is shown in the gateway settings.
5. Click **Test connection** to verify the key.
6. Optionally pin one payment asset, set the invoice lifetime, or disable automatic cancellation of expired orders.

== Frequently Asked Questions ==

= Does the buyer need an account or pass KYC? =

No. The buyer just pays to the invoice address on the hosted checkout page.

= Which cryptocurrencies are supported? =

USDT (TRC-20), USDC/USDT on Polygon and Arbitrum, Bitcoin, Monero, TON and USDT-TON. You can pin one asset in the settings or let the buyer choose at checkout.

= Which shop currencies are supported? =

USD, EUR and RUB. If your shop uses another currency the gateway hides itself at checkout.

= How do I test the plugin? =

Use a sandbox key (pk_test_...) — the full flow works with simulated invoices, no real crypto moves.

= Are refunds supported? =

Not automatically: crypto payments are one-way. Refund manually from your wallet and set the order status accordingly.

= An order is stuck on-hold, what now? =

The built-in cron poller reconciles orders with the invoice state every 15 minutes. You can also check the invoice id in the order notes against the console.

== Changelog ==

= 1.0.0 =
* Initial release: redirect checkout, HMAC-verified webhooks with replay protection, invoice polling safety net, sandbox/production modes, HPOS compatibility.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
