<?php
/**
 * Stripe webhook receiver (Flinkform Pro).
 *
 * Handles asynchronous payment outcomes — most importantly SEPA Direct
 * Debit, which confirms as `processing` and only settles (or fails) days
 * later. Stripe calls this endpoint; the signature check below is the
 * native equivalent of the SDK's Webhook::constructEvent():
 *
 *   Stripe-Signature: t=<unix>,v1=<hmac>[,v1=<hmac>…]
 *   expected v1 = HMAC-SHA256( "<t>.<raw payload>", signing secret )
 *
 * No Stripe SDK, no external service — wp REST + hash_hmac only.
 *
 * Events handled:
 *   payment_intent.succeeded       → status 'succeeded'
 *   payment_intent.payment_failed  → status 'failed'
 *   payment_intent.canceled        → status 'canceled'
 *   charge.refunded                → status 'refunded'
 *
 * Everything else is acknowledged with 200 and ignored, so operators can
 * point a broad webhook configuration at this endpoint without noise.
 *
 * @package FlinkformPro
 * @since 1.2.0
 */

declare( strict_types = 1 );

namespace FlinkformPro\Payments;

use FlinkformPro\Settings\Secret;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoint for Stripe webhook events.
 */
final class WebhookController {

	/**
	 * Signature timestamp tolerance (seconds) against replayed payloads.
	 */
	private const TOLERANCE_SECONDS = 300;

	/**
	 * Register the REST route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'flinkform-pro/v1',
			'/stripe-webhook',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				// Public by design — authentication is the Stripe signature.
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Handle an incoming Stripe event.
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$settings       = get_option( 'flinkform_stripe_settings', [] );
		$signing_secret = Secret::decrypt( (string) ( $settings['webhook_secret'] ?? '' ) );

		if ( '' === $signing_secret ) {
			// Not configured — refuse loudly so the operator notices in the
			// Stripe dashboard's webhook attempt log.
			return new \WP_REST_Response( [ 'error' => 'Webhook signing secret is not configured.' ], 500 );
		}

		$payload   = $request->get_body();
		$signature = (string) $request->get_header( 'stripe-signature' );

		if ( ! $this->is_signature_valid( $payload, $signature, $signing_secret ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid signature.' ], 400 );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'Malformed event.' ], 400 );
		}

		$this->apply_event( (string) $event['type'], $event );

		return new \WP_REST_Response( [ 'received' => true ] );
	}

	/**
	 * Verify the Stripe-Signature header against the raw payload.
	 *
	 * @param string $payload   Raw request body (exactly as received).
	 * @param string $signature Stripe-Signature header value.
	 * @param string $secret    Webhook signing secret (whsec_...).
	 * @return bool
	 */
	private function is_signature_valid( string $payload, string $signature, string $secret ): bool {
		if ( '' === $signature ) {
			return false;
		}

		$timestamp  = 0;
		$candidates = [];

		foreach ( explode( ',', $signature ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$candidates[] = $pair[1];
			}
		}

		if ( $timestamp <= 0 || empty( $candidates ) ) {
			return false;
		}

		// Replay window: reject payloads signed too far in the past (or the
		// future, which indicates clock trouble rather than an attack).
		if ( abs( time() - $timestamp ) > self::TOLERANCE_SECONDS ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

		foreach ( $candidates as $candidate ) {
			if ( hash_equals( $expected, $candidate ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Map a verified Stripe event onto the payments table.
	 *
	 * @param string               $type  Event type.
	 * @param array<string, mixed> $event Decoded event payload.
	 * @return void
	 */
	private function apply_event( string $type, array $event ): void {
		$object = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : [];

		$status_map = [
			'payment_intent.succeeded'      => 'succeeded',
			'payment_intent.payment_failed' => 'failed',
			'payment_intent.canceled'       => 'canceled',
			'charge.refunded'               => 'refunded',
		];

		if ( ! isset( $status_map[ $type ] ) ) {
			return; // Unhandled event type — acknowledged upstream, ignored here.
		}

		// payment_intent.* events carry the intent id in `id`; charge.*
		// events reference it in `payment_intent`.
		$intent_id = '';
		if ( str_starts_with( $type, 'payment_intent.' ) ) {
			$intent_id = (string) ( $object['id'] ?? '' );
		} elseif ( isset( $object['payment_intent'] ) ) {
			$intent_id = (string) $object['payment_intent'];
		}

		if ( '' === $intent_id || ! str_starts_with( $intent_id, 'pi_' ) ) {
			return;
		}

		$repository = new PaymentRepository();
		$updated    = $repository->update_status( $intent_id, $status_map[ $type ] );

		if ( $updated ) {
			/**
			 * Fires after a Stripe webhook settled a payment's status.
			 *
			 * Lets site owners react to asynchronous outcomes — e.g. send a
			 * "payment failed" notice when a SEPA debit bounces days after
			 * the submission was accepted.
			 *
			 * @param string               $intent_id Stripe PaymentIntent ID.
			 * @param string               $status    New status (succeeded|failed|canceled|refunded).
			 * @param array<string, mixed> $event     Full decoded Stripe event.
			 */
			do_action( 'flinkform_payment_status_updated', $intent_id, $status_map[ $type ], $event );
		}
	}
}
