<?php
/**
 * The WooCommerce payment gateway.
 *
 * @package zerokyc-pay
 */

defined( 'ABSPATH' ) || exit;

use ZeroKYC\Config;
use ZeroKYC\Exception\ApiException;
use ZeroKYC\Exception\AuthenticationException;
use ZeroKYC\Exception\NetworkException;
use ZeroKYC\Exception\ValidationException;
use ZeroKYC\Idempotency\IdempotencyKey;
use ZeroKYC\Invoice\CreateInvoiceRequest;
use ZeroKYC\Invoice\InvoiceStatus;
use ZeroKYC\ZeroKYC;

final class ZKP_Gateway extends WC_Payment_Gateway {

	public const ID = 'zerokyc_pay';

	/**
	 * Currencies the ZeroKYC API accepts invoice amounts in.
	 */
	public const SUPPORTED_CURRENCIES = array( 'USD', 'EUR', 'RUB' );

	/**
	 * Payment assets supported by the hosted checkout; `any` lets the buyer
	 * choose at checkout.
	 */
	public const PAYMENT_CURRENCIES = array(
		'any'        => 'Let the buyer choose (USDT, USDC, BTC, XMR, TON)',
		'USDT_TRON'  => 'USDT (TRC-20)',
		'USDC_POLYGON' => 'USDC (Polygon)',
		'USDC_ARBITRUM' => 'USDC (Arbitrum)',
		'USDT_POLYGON' => 'USDT (Polygon)',
		'USDT_ARBITRUM' => 'USDT (Arbitrum)',
		'TON'        => 'TON',
		'USDT_TON'   => 'USDT (TON)',
	);

	public function __construct() {
		$this->id                 = self::ID;
		$this->has_fields         = false;
		$this->method_title       = __( 'ZeroKYC Pay (crypto)', 'zerokyc-pay' );
		$this->method_description = __( 'Accept crypto payments through the ZeroKYC Pay hosted checkout. Orders are completed by HMAC-verified webhooks.', 'zerokyc-pay' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Settings schema.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'          => array(
				'title'   => __( 'Enable/disable', 'zerokyc-pay' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable ZeroKYC Pay', 'zerokyc-pay' ),
				'default' => 'no',
			),
			'title'            => array(
				'title'       => __( 'Title', 'zerokyc-pay' ),
				'type'        => 'text',
				'description' => __( 'Payment method title the buyer sees at checkout.', 'zerokyc-pay' ),
				'default'     => __( 'Cryptocurrency (USDT, BTC, XMR, TON…)', 'zerokyc-pay' ),
				'desc_tip'    => true,
			),
			'description'      => array(
				'title'       => __( 'Description', 'zerokyc-pay' ),
				'type'        => 'text',
				'description' => __( 'Payment method description the buyer sees at checkout.', 'zerokyc-pay' ),
				'default'     => __( 'Pay with crypto — no account, no KYC. You will be redirected to a secure checkout.', 'zerokyc-pay' ),
				'desc_tip'    => true,
			),
			'api_key'          => array(
				'title'       => __( 'API key', 'zerokyc-pay' ),
				'type'        => 'password',
				'description' => __( 'Publishable key from console.zerokyc-payments.com → API keys. pk_test_… = sandbox, pk_live_… = production; the environment is derived from the key.', 'zerokyc-pay' ),
				'default'     => '',
			),
			'webhook_secret'   => array(
				'title'       => __( 'Webhook secret', 'zerokyc-pay' ),
				'type'        => 'password',
				'description' => __( 'Endpoint secret (whsec_…) from console.zerokyc-payments.com → Webhooks. Required for order completion.', 'zerokyc-pay' ),
				'default'     => '',
			),
			'payment_currency' => array(
				'title'       => __( 'Payment asset', 'zerokyc-pay' ),
				'type'        => 'select',
				'options'     => self::PAYMENT_CURRENCIES,
				'default'     => 'any',
				'description' => __( 'Pin one asset or let the buyer choose at checkout.', 'zerokyc-pay' ),
				'desc_tip'    => true,
			),
			'ttl_minutes'      => array(
				'title'             => __( 'Invoice lifetime (minutes)', 'zerokyc-pay' ),
				'type'              => 'number',
				'default'           => 360,
				'custom_attributes' => array( 'min' => 10, 'max' => 4320 ),
				'description'       => __( 'How long the invoice stays payable (10–4320).', 'zerokyc-pay' ),
				'desc_tip'          => true,
			),
			'auto_cancel'      => array(
				'title'   => __( 'Expired invoices', 'zerokyc-pay' ),
				'type'    => 'checkbox',
				'label'   => __( 'Automatically cancel the order when the invoice expires unpaid', 'zerokyc-pay' ),
				'default' => 'yes',
			),
			'double_check'     => array(
				'title'   => __( 'Server-side confirmation', 'zerokyc-pay' ),
				'type'    => 'checkbox',
				'label'   => __( 'Re-fetch the invoice from the API before completing an order (recommended)', 'zerokyc-pay' ),
				'default' => 'yes',
			),
			'debug'            => array(
				'title'   => __( 'Debug log', 'zerokyc-pay' ),
				'type'    => 'checkbox',
				'label'   => __( 'Log detailed events to WooCommerce → Status → Logs', 'zerokyc-pay' ),
				'default' => 'no',
			),
		);
	}

	/**
	 * Gateway availability: parent rules + shop currency support.
	 */
	public function is_available(): bool {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( '' === trim( (string) $this->get_option( 'api_key' ) ) ) {
			return false;
		}
		if ( ! in_array( get_woocommerce_currency(), self::SUPPORTED_CURRENCIES, true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * The site's webhook endpoint URL (also sent with every invoice). rest_url()
	 * falls back to ?rest_route= automatically when pretty permalinks are off.
	 */
	public static function webhook_url(): string {
		return rest_url( 'zkp/v1/webhook' );
	}

	/**
	 * Raw gateway settings array.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		return (array) get_option( 'woocommerce_' . self::ID . '_settings', array() );
	}

	/**
	 * Builds the SDK facade wired to the WP HTTP API (never raw cURL).
	 *
	 * @throws RuntimeException When no API key is configured.
	 */
	public static function sdk(): ZeroKYC {
		$settings = self::settings();
		$api_key  = trim( (string) ( $settings['api_key'] ?? '' ) );
		if ( '' === $api_key ) {
			throw new RuntimeException( 'ZeroKYC Pay: API key is not configured.' );
		}

		return new ZeroKYC(
			Config::fromArray(
				array(
					'api_key'        => $api_key,
					'webhook_secret' => trim( (string) ( $settings['webhook_secret'] ?? '' ) ),
					'max_retries'    => 2,
				)
			),
			new ZKP_Http_Client(),
		);
	}

	/**
	 * Creates (or reuses) an invoice and redirects to the hosted checkout.
	 *
	 * @param int $order_id Order ID.
	 * @return array{result:string, redirect?:string}
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! ( $order instanceof WC_Order ) ) {
			wc_add_notice( __( 'Invalid order.', 'zerokyc-pay' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$currency = get_woocommerce_currency();
		if ( ! in_array( $currency, self::SUPPORTED_CURRENCIES, true ) ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: comma-separated currency codes */
					__( 'ZeroKYC Pay supports %s as shop currency. Please contact the store.', 'zerokyc-pay' ),
					implode( ', ', self::SUPPORTED_CURRENCIES )
				),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		try {
			$checkout_url = $this->ensure_invoice( $order, $currency );
		} catch ( AuthenticationException | ValidationException $e ) {
			ZKP_Logger::alert( sprintf( 'order %d rejected by API: %s', $order->get_id(), $e->getMessage() ) );
			wc_add_notice( __( 'Payment could not be started. The store administrator has been notified.', 'zerokyc-pay' ), 'error' );
			return array( 'result' => 'failure' );
		} catch ( NetworkException | ApiException | RuntimeException $e ) {
			ZKP_Logger::warning( sprintf( 'order %d invoice creation failed: %s', $order->get_id(), $e->getMessage() ) );
			wc_add_notice( __( 'The payment service is temporarily unavailable. Please try again in a moment.', 'zerokyc-pay' ), 'error' );
			return array( 'result' => 'failure' );
		}

		return array(
			'result'   => 'success',
			'redirect' => $checkout_url,
		);
	}

	/**
	 * Returns a payable checkout URL for the order, reusing a live invoice
	 * when one exists and creating a fresh one otherwise.
	 *
	 * @throws ZeroKYC\Exception\ZeroKYCException
	 * @throws RuntimeException
	 */
	private function ensure_invoice( WC_Order $order, string $currency ): string {
		$settings = self::settings();
		$zkp      = self::sdk();

		if ( ! $order->is_paid() ) {
			$existing_id = (string) $order->get_meta( '_zkp_invoice_id' );
			if ( '' !== $existing_id ) {
				try {
					$invoice = $zkp->getInvoice( $existing_id );
					if ( ! $invoice->isTerminal() && strtotime( (string) $invoice->expiresAt ) > ( time() + 60 ) ) {
						ZKP_Order_Service::apply_invoice( $order, $invoice );
						return $invoice->checkoutUrl;
					}
				} catch ( NetworkException $e ) {
					// Fall through: create a new invoice; the idempotency key
					// keeps it safe even if the old one is still alive.
				}
			}
		}

		$seq = (int) $order->get_meta( '_zkp_seq' ) + 1;
		$order->update_meta_data( '_zkp_seq', $seq );

		$payment_currency = (string) ( $settings['payment_currency'] ?? 'any' );
		$request          = CreateInvoiceRequest::make( self::format_amount( $order->get_total() ), $currency )
			->withOrderId( self::remote_order_id( $order ) )
			->withDescription( self::invoice_description( $order ) )
			->withPaymentCurrency( $payment_currency )
			->withTtlMinutes( max( 10, min( 4320, (int) ( $settings['ttl_minutes'] ?? 360 ) ) ) )
			->withWebhookUrl( self::webhook_url() )
			->withSuccessUrl( $order->get_checkout_order_received_url() )
			->withMetadata(
				array(
					'plugin'   => 'woocommerce',
					'order_id' => $order->get_id(),
					'site'     => home_url(),
				)
			);

		$response = $zkp->createInvoice(
			$request,
			IdempotencyKey::make( 'woocommerce', 'order', $order->get_id() . ':' . $seq )
		);
		$invoice  = $response->invoice;

		$order->update_meta_data( '_zkp_invoice_id', $invoice->id );
		$order->update_meta_data( '_zkp_checkout_url', $invoice->checkoutUrl );
		$order->update_meta_data( '_zkp_created', time() );
		ZKP_Invoice_Map::remember( $invoice->id, $order->get_id() );

		if ( 'any' !== $payment_currency ) {
			$option = $invoice->option( $payment_currency );
			if ( null !== $option && isset( $option['amount'] ) ) {
				$order->update_meta_data( '_zkp_asset', $payment_currency );
				$order->update_meta_data( '_zkp_amount_crypto', (string) $option['amount'] );
			}
		}

		$order->update_status(
			'on-hold',
			sprintf(
				/* translators: %s: invoice id */
				__( 'ZeroKYC invoice %s created, awaiting crypto payment.', 'zerokyc-pay' ),
				$invoice->id
			)
		);
		$order->save();

		ZKP_Logger::debug( sprintf( 'order %d: invoice %s created (seq %d)', $order->get_id(), $invoice->id, $seq ) );

		return $invoice->checkoutUrl;
	}

	/**
	 * Sanitized, host-prefixed order reference for the API side.
	 */
	private static function remote_order_id( WC_Order $order ): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? $host : 'site';
		return $host . '-' . $order->get_id();
	}

	/**
	 * @param float|string $total Order total.
	 */
	private static function format_amount( $total ): string {
		return number_format( (float) $total, 2, '.', '' );
	}

	private static function invoice_description( WC_Order $order ): string {
		$name = get_bloginfo( 'name' );
		if ( ! is_string( $name ) || '' === $name ) {
			$name = 'Order';
		}
		return sprintf( '%s order %s', $name, $order->get_order_number() );
	}

	/**
	 * "Test connection" button output after the settings form.
	 */
	public function admin_options(): void {
		parent::admin_options();
		$nonce = wp_create_nonce( 'zkp_ping' );
		?>
		<div style="margin-top:1em">
			<button type="button" class="button" id="zkp-ping"
				data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Test connection', 'zerokyc-pay' ); ?>
			</button>
			<span id="zkp-ping-result" style="margin-left:.5em"></span>
		</div>
		<script>
		( function () {
			var button = document.getElementById( 'zkp-ping' );
			if ( ! button ) { return; }
			button.addEventListener( 'click', function () {
				var result = document.getElementById( 'zkp-ping-result' );
				result.textContent = '…';
				var body = new window.FormData();
				body.append( 'action', 'zkp_ping' );
				body.append( 'nonce', button.getAttribute( 'data-nonce' ) );
				window.fetch( window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( response ) { return response.json(); } )
					.then( function ( json ) {
						var data = json && json.data ? json.data : {};
						if ( json && json.success ) {
							result.textContent = data.environment + ': OK' + ( data.chain_mode ? ' (' + data.chain_mode + ')' : '' );
						} else {
							result.textContent = 'Failed: ' + ( data.message || 'unknown error' );
						}
					} )
					.catch( function () { result.textContent = 'Request failed'; } );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * ajax action=zkp_ping: verifies the configured API key against /v1/ping.
	 */
	public static function ajax_ping(): void {
		check_ajax_referer( 'zkp_ping', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		try {
			$zkp  = self::sdk();
			$body = $zkp->ping();
		} catch ( RuntimeException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		} catch ( AuthenticationException $e ) {
			wp_send_json_error( array( 'message' => 'authentication failed: ' . $e->getMessage() ) );
		} catch ( ZeroKYC\Exception\ZeroKYCException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

		wp_send_json_success(
			array(
				'environment' => $zkp->config->isSandbox() ? 'sandbox' : 'production',
				'chain_mode'  => is_string( $body['chain_mode'] ?? null ) ? $body['chain_mode'] : null,
			)
		);
	}
}
