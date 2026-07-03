<?php
/**
 * Lightweight Stripe API client using wp_remote_*().
 *
 * No Stripe PHP SDK dependency — keeps the plugin lean. Only the
 * endpoints needed for PaymentIntents are implemented.
 *
 * @package FlinkformPro
 * @since 1.1.0
 */

declare( strict_types = 1 );

namespace FlinkformPro\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Stripe API wrapper.
 */
final class StripeApi {

	private string $secret_key;

	public function __construct( string $secret_key ) {
		$this->secret_key = $secret_key;
	}

	/**
	 * Create a PaymentIntent.
	 *
	 * @param int    $amount_cents Amount in smallest currency unit (e.g. cents).
	 * @param string $currency     Three-letter ISO currency code.
	 * @param string $description  Shown on the Stripe receipt.
	 * @param array<string, string> $metadata Optional metadata.
	 * @param array{
	 *     receipt_email?: string,
	 *     automatic_payment_methods?: bool,
	 *     allow_redirects?: bool
	 * } $options Extra PaymentIntent options. `automatic_payment_methods`
	 *           enables the Payment Element method set; `allow_redirects`
	 *           (default false) keeps it to non-redirect methods only —
	 *           cards, Apple Pay, Google Pay, Link — for the synchronous
	 *           confirm-then-submit flow (Stufe A). Redirect/async methods
	 *           (SEPA, giropay) are unlocked in Stufe B by allowing redirects
	 *           and handling the return URL.
	 * @return array{success: bool, client_secret?: string, intent_id?: string, error?: string}
	 */
	public function create_payment_intent( int $amount_cents, string $currency, string $description = '', array $metadata = [], array $options = [] ): array {
		$body = [
			'amount'   => $amount_cents,
			'currency' => strtolower( $currency ),
		];

		if ( '' !== $description ) {
			$body['description'] = $description;
		}

		if ( ! empty( $options['automatic_payment_methods'] ) ) {
			$body['automatic_payment_methods[enabled]'] = 'true';
			// Restrict to methods that confirm in-page (no browser redirect)
			// unless the caller explicitly opts into redirect flows.
			$body['automatic_payment_methods[allow_redirects]'] = empty( $options['allow_redirects'] ) ? 'never' : 'always';
		}

		if ( ! empty( $options['receipt_email'] ) && is_email( (string) $options['receipt_email'] ) ) {
			$body['receipt_email'] = (string) $options['receipt_email'];
		}

		foreach ( $metadata as $k => $v ) {
			$body[ 'metadata[' . $k . ']' ] = $v;
		}

		$response = $this->post( 'payment_intents', $body );

		if ( isset( $response['error'] ) ) {
			return [
				'success' => false,
				'error'   => (string) ( $response['error']['message'] ?? 'Unknown Stripe error' ),
			];
		}

		return [
			'success'       => true,
			'client_secret' => (string) ( $response['client_secret'] ?? '' ),
			'intent_id'     => (string) ( $response['id'] ?? '' ),
		];
	}

	/**
	 * Retrieve a PaymentIntent by ID.
	 *
	 * @param string $intent_id Stripe PaymentIntent ID (pi_...).
	 * @return array{success: bool, status?: string, amount?: int, currency?: string, method?: string, form_id?: string, error?: string}
	 */
	public function retrieve_payment_intent( string $intent_id ): array {
		$response = $this->get( 'payment_intents/' . $intent_id );

		if ( isset( $response['error'] ) ) {
			return [
				'success' => false,
				'error'   => (string) ( $response['error']['message'] ?? 'Unknown Stripe error' ),
			];
		}

		// The payment method actually used, when available (e.g. 'card',
		// 'sepa_debit'). payment_method_types lists what the intent ACCEPTS,
		// so it's only a fallback hint before confirmation.
		$method = '';
		if ( isset( $response['payment_method_types'] ) && is_array( $response['payment_method_types'] ) && ! empty( $response['payment_method_types'][0] ) ) {
			$method = (string) $response['payment_method_types'][0];
		}

		return [
			'success'  => true,
			'status'   => (string) ( $response['status'] ?? '' ),
			'amount'   => (int) ( $response['amount'] ?? 0 ),
			'currency' => (string) ( $response['currency'] ?? '' ),
			'method'   => $method,
			'form_id'  => isset( $response['metadata']['form_id'] ) ? (string) $response['metadata']['form_id'] : '',
		];
	}

	/**
	 * POST request to the Stripe API.
	 *
	 * @param string               $endpoint Relative to /v1/.
	 * @param array<string, mixed> $body     Form-encoded body params.
	 * @return array<string, mixed>
	 */
	private function post( string $endpoint, array $body ): array {
		$response = wp_remote_post(
			'https://api.stripe.com/v1/' . $endpoint,
			[
				'headers' => $this->headers(),
				'body'    => $body,
				'timeout' => 30,
			]
		);

		return $this->parse( $response );
	}

	/**
	 * GET request to the Stripe API.
	 *
	 * @param string $endpoint Relative to /v1/.
	 * @return array<string, mixed>
	 */
	private function get( string $endpoint ): array {
		$response = wp_remote_get(
			'https://api.stripe.com/v1/' . $endpoint,
			[
				'headers' => $this->headers(),
				'timeout' => 30,
			]
		);

		return $this->parse( $response );
	}

	/**
	 * @return array<string, string>
	 */
	private function headers(): array {
		return [
			'Authorization' => 'Bearer ' . $this->secret_key,
			'Stripe-Version' => '2024-06-20',
		];
	}

	/**
	 * @param array|\WP_Error $response
	 * @return array<string, mixed>
	 */
	private function parse( $response ): array {
		if ( is_wp_error( $response ) ) {
			return [ 'error' => [ 'message' => $response->get_error_message() ] ];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) ? $body : [ 'error' => [ 'message' => 'Invalid Stripe response' ] ];
	}
}
