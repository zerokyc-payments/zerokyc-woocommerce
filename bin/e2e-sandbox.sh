#!/usr/bin/env bash
# End-to-end check on the docker WordPress site (localhost:8080):
#   1. install WP + WooCommerce + zerokyc-pay
#   2. enable the gateway with sandbox credentials (env, never committed)
#   3. run process_payment -> REAL sandbox invoice -> hosted checkout URL
#   4. self-signed payment.confirmed webhook:
#        a. with double-check on  -> order must STAY on-hold (server-side
#           invoice is not paid - spoofing is rejected)
#        b. with double-check off -> order completes
#
# Usage:
#   ZKP_E2E_API_KEY=pk_test_... ZKP_E2E_WEBHOOK_SECRET=whsec_... bin/e2e-sandbox.sh
set -euo pipefail

cd "$(dirname "$0")/.."

API_KEY="${ZKP_E2E_API_KEY:?set ZKP_E2E_API_KEY=pk_test_...}"
WEBHOOK_SECRET="${ZKP_E2E_WEBHOOK_SECRET:?set ZKP_E2E_WEBHOOK_SECRET=whsec_...}"
URL="${ZKP_E2E_URL:-http://localhost:8080}"

wp() { docker compose run --rm -e ZKP_E2E_API_KEY -e ZKP_E2E_WEBHOOK_SECRET -e ZKP_INV -e ZKP_EVENT_ID wpcli wp "$@"; }

echo "==> starting site"
docker compose up -d db wp
sleep 3

echo "==> install WordPress"
wp core install --url="$URL" --title="ZeroKYC Pay E2E" --admin_user=admin \
    --admin_password=admin --admin_email=admin@example.test --skip-email || true

echo "==> plugins"
wp plugin install woocommerce --activate >/dev/null
wp plugin activate zerokyc-pay
wp rewrite structure '/%postname%/' --hard >/dev/null
wp option update woocommerce_currency USD >/dev/null

echo "==> gateway settings (sandbox)"
wp eval "
update_option( 'woocommerce_zerokyc_pay_settings', array(
    'enabled' => 'yes',
    'api_key' => getenv('ZKP_E2E_API_KEY'),
    'webhook_secret' => getenv('ZKP_E2E_WEBHOOK_SECRET'),
    'double_check' => 'yes',
    'payment_currency' => 'any',
    'ttl_minutes' => 360,
) );
delete_option( '_zkp_e2e_state' );
" >/dev/null

echo "==> test connection (ping)"
wp eval '
$ping = ZKP_Gateway::sdk()->ping();
printf("ping: %s / chain_mode=%s\n", $ping["status"] ?? "?", $ping["chain_mode"] ?? "?");
'

echo "==> create product + order"
ORDER_ID=$(wp eval '
$product = new WC_Product_Simple();
$product->set_name( "E2E test product" );
$product->set_regular_price( "19.90" );
$product->save();
$order = wc_create_order();
$order->add_product( $product, 1 );
$order->set_currency( "USD" );
$order->set_address( array( "email" => "buyer@example.test" ), "billing" );
$order->calculate_totals();
$order->save();
echo $order->get_id();
' | tr -d '\r\n')
echo "order id: $ORDER_ID"

echo "==> process_payment (real sandbox invoice)"
CHECKOUT=$(wp eval "
\$r = null;
foreach ( WC()->payment_gateways()->payment_gateways as \$gw ) {
    if ( \$gw instanceof ZKP_Gateway ) { \$r = \$gw; break; }
}
\$res = \$r->process_payment( $ORDER_ID );
echo \$res['redirect'] ?? 'FAILED';
" | tr -d '\r\n')
echo "checkout url: $CHECKOUT"
[ "$CHECKOUT" != "FAILED" ] || { echo "process_payment failed"; exit 1; }

HTTP=$(curl -s -o /dev/null -w '%{http_code}' "$CHECKOUT")
echo "hosted checkout HTTP: $HTTP"

INVOICE_ID=$(wp eval "echo (string) wc_get_order( $ORDER_ID )->get_meta( '_zkp_invoice_id' );" | tr -d '\r\n')
echo "invoice: $INVOICE_ID"

export ZKP_INV="$INVOICE_ID"
sign_and_post() {
    export ZKP_EVENT_ID="evt_e2e_${1:-a}"
    wp eval '
$body = json_encode(array(
    "id" => getenv("ZKP_EVENT_ID"),
    "type" => "payment.confirmed",
    "invoice_id" => getenv("ZKP_INV"),
    "data" => array( "invoice_id" => getenv("ZKP_INV"), "amount" => "19.9", "asset" => "USDT_TRON" ),
));
echo (new ZeroKYC\Webhook\WebhookVerifier( getenv("ZKP_E2E_WEBHOOK_SECRET") ))->sign( $body );
' | tr -d '\r\n' > /tmp/zkp-e2e-sig.txt
    local SIG; SIG=$(cat /tmp/zkp-e2e-sig.txt)

    BODY='{"id":"'"$ZKP_EVENT_ID"'","type":"payment.confirmed","invoice_id":"'"$INVOICE_ID"'","data":{"invoice_id":"'"$INVOICE_ID"'","amount":"19.9","asset":"USDT_TRON"}}'

    curl -s -o /dev/null -w 'webhook HTTP %{http_code}\n' \
        -X POST "$URL/wp-json/zkp/v1/webhook" \
        -H "Content-Type: application/json" \
        -H "X-Zkp-Signature: $SIG" \
        --data-binary "$BODY"
}

echo "==> webhook A: double-check ON -> order must stay on-hold (spoof rejected)"
sign_and_post a
wp eval "echo 'status after A: ' . wc_get_order( $ORDER_ID )->get_status() . PHP_EOL;"

echo "==> webhook B: double-check OFF -> order completes"
wp eval "update_option( 'woocommerce_zerokyc_pay_settings', array_merge( (array) get_option('woocommerce_zerokyc_pay_settings'), array( 'double_check' => 'no' ) ) );" >/dev/null

sign_and_post b
wp eval "echo 'status after B: ' . wc_get_order( $ORDER_ID )->get_status() . PHP_EOL;"
wp eval "
\$o = wc_get_order( $ORDER_ID );
echo 'paid_asset: ' . \$o->get_meta('_zkp_paid_asset') . PHP_EOL;
echo 'notes:' . PHP_EOL;
foreach ( wc_get_order_notes( array( 'order_id' => $ORDER_ID ) ) as \$n ) { echo '  - ' . \$n->content . PHP_EOL; }
"

echo "==> done"
