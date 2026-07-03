<?php
/**
 * REST endpoints for Stripe payment processing.
 *
 * Exposes a single endpoint for creating PaymentIntents from the frontend.
 * The endpoint is public (no authentication) because the visitor filling
 * the form is not a logged-in user — security is ensured by nonce
 * verification and the Stripe publishable/secret key separation.
 *
 * @package FlinkformPro
 * @since 1.1.0
 */

declare( strict_types = 1 );

namespace FlinkformPro\Payments;

defined( 'ABSPATH' ) || exit;

use FlinkformPro\Settings\Secret;

/**
 * Registers the REST routes for payment processing.
 */
final class RestController {

	/**
	 * Register the REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'flinkform-pro/v1',
			'/create-intent',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_intent' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Create a Stripe PaymentIntent.
	 *
	 * Expects JSON body: { form_id, post_id, amount, nonce, email? }.
	 *
	 * The charged amount and currency are NOT taken from the request — they
	 * are resolved from the authoritative form definition in the source post
	 * (via the free core's Locator). The client-sent `amount` is only accepted
	 * when it matches one of the field's configured prices; anything else is
	 * rejected. This stops both amount tampering and abuse of the endpoint as
	 * a free intent generator on the operator's Stripe account.
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @return \WP_REST_Response
	 */
	public function create_intent( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = [];
		}

		$nonce = (string) ( $params['nonce'] ?? '' );
		if ( ! wp_verify_nonce( $nonce, 'flinkform_stripe_intent' ) ) {
			return new \WP_REST_Response( [ 'error' => __( 'Security check failed. Please reload the page.', 'flinkform-pro' ) ], 403 );
		}

		$form_id          = sanitize_text_field( (string) ( $params['form_id'] ?? '' ) );
		$post_id          = absint( $params['post_id'] ?? 0 );
		$requested_amount = (int) ( $params['amount'] ?? 0 );

		if ( '' === $form_id || 0 === $post_id ) {
			return new \WP_REST_Response( [ 'error' => __( 'Invalid payment request.', 'flinkform-pro' ) ], 400 );
		}

		// Resolve the payment field from the authoritative form definition.
		$payment_field = $this->locate_payment_field( $post_id, $form_id );
		if ( null === $payment_field ) {
			return new \WP_REST_Response( [ 'error' => __( 'Invalid payment request.', 'flinkform-pro' ) ], 400 );
		}

		// Bind amount + currency to the form definition — never the client.
		$allowed = Module::allowed_amounts( $payment_field );
		if ( ! in_array( $requested_amount, $allowed, true ) ) {
			return new \WP_REST_Response( [ 'error' => __( 'Invalid payment amount.', 'flinkform-pro' ) ], 400 );
		}
		if ( $requested_amount < 50 ) { // Stripe minimum is typically 50 cents.
			return new \WP_REST_Response( [ 'error' => __( 'Amount too low.', 'flinkform-pro' ) ], 400 );
		}
		$currency = Module::resolve_currency( $payment_field );

		$settings   = get_option( 'flinkform_stripe_settings', [] );
		$secret_key = Secret::decrypt( (string) ( $settings['secret_key'] ?? '' ) );

		if ( '' === $secret_key ) {
			return new \WP_REST_Response( [ 'error' => __( 'Payment processing is not configured.', 'flinkform-pro' ) ], 500 );
		}

		// Optional receipt address — best effort only, never a security input.
		$receipt_email = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';

		$api    = new StripeApi( $secret_key );
		$result = $api->create_payment_intent(
			$requested_amount,
			$currency,
			sprintf( 'Flinkform submission (form %s)', $form_id ),
			[ 'form_id' => $form_id ],
			[
				'automatic_payment_methods' => true,
				'allow_redirects'           => false, // Stufe A: in-page methods only.
				'receipt_email'             => $receipt_email,
			]
		);

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( [ 'error' => $result['error'] ?? __( 'Payment failed.', 'flinkform-pro' ) ], 400 );
		}

		return new \WP_REST_Response( [
			'client_secret' => $result['client_secret'],
			'intent_id'     => $result['intent_id'],
		] );
	}

	/**
	 * Locate the payment field of a form inside its source post.
	 *
	 * Leans on the free core's Locator so the amount/currency the endpoint
	 * charges come from the same authoritative definition the submission
	 * handler validates against.
	 *
	 * @param int    $post_id Source post embedding the form.
	 * @param string $form_id Form UUID.
	 * @return array<string, mixed>|null The payment field record, or null.
	 */
	private function locate_payment_field( int $post_id, string $form_id ): ?array {
		if ( ! class_exists( '\Flinkform\Forms\Locator' ) ) {
			return null;
		}

		$definition = ( new \Flinkform\Forms\Locator() )->locate( $post_id, $form_id );
		if ( null === $definition || empty( $definition['fields'] ) || ! is_array( $definition['fields'] ) ) {
			return null;
		}

		foreach ( $definition['fields'] as $field ) {
			if ( is_array( $field ) && ( $field['type'] ?? '' ) === 'payment' ) {
				return $field;
			}
		}

		return null;
	}
}
