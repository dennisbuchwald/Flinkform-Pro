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
	 * Expects JSON body: { form_id, amount, currency, nonce }.
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
			return new \WP_REST_Response( [ 'error' => 'Invalid nonce.' ], 403 );
		}

		$amount   = (int) ( $params['amount'] ?? 0 );
		$currency = sanitize_text_field( (string) ( $params['currency'] ?? 'eur' ) );
		$form_id  = sanitize_text_field( (string) ( $params['form_id'] ?? '' ) );

		if ( $amount < 50 ) { // Stripe minimum is typically 50 cents.
			return new \WP_REST_Response( [ 'error' => 'Amount too low.' ], 400 );
		}

		$settings   = get_option( 'flinkform_stripe_settings', [] );
		$secret_key = Secret::decrypt( (string) ( $settings['secret_key'] ?? '' ) );

		if ( '' === $secret_key ) {
			return new \WP_REST_Response( [ 'error' => 'Stripe is not configured.' ], 500 );
		}

		$api    = new StripeApi( $secret_key );
		$result = $api->create_payment_intent(
			$amount,
			$currency,
			sprintf( 'Flinkform submission (form %s)', $form_id ),
			[ 'form_id' => $form_id ]
		);

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( [ 'error' => $result['error'] ?? 'Payment failed.' ], 400 );
		}

		return new \WP_REST_Response( [
			'client_secret' => $result['client_secret'],
			'intent_id'     => $result['intent_id'],
		] );
	}
}
