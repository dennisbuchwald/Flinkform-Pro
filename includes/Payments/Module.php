<?php
/**
 * Stripe Payments module wiring (Flinkform Pro).
 *
 * Registers the payment field block, the REST endpoint for creating
 * PaymentIntents, the server-side payment verification during form
 * submission, and the admin settings page.
 *
 * @package FlinkformPro
 * @since 1.1.0
 */

declare( strict_types = 1 );

namespace FlinkformPro\Payments;

use FlinkformPro\Settings\Secret;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the Stripe payment field into the free core.
 */
final class Module {

	/**
	 * Register the WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Block registration.
		add_filter(
			'flinkform_block_dirs',
			static function ( array $dirs ): array {
				$dirs['field-payment'] = FLINKFORM_PRO_DIR . 'blocks/build/field-payment';
				return $dirs;
			}
		);

		// Field-type registration.
		add_filter(
			'flinkform_field_blocks',
			static function ( array $map ): array {
				$map['flinkform/field-payment'] = 'payment';
				return $map;
			}
		);

		// Carry block attributes into the field definition.
		add_filter(
			'flinkform_field_extras',
			static function ( array $extras, string $type, string $block_name, array $attrs ): array {
				if ( 'payment' !== $type ) {
					return $extras;
				}
				return [
					'amount'    => isset( $attrs['amount'] ) ? (int) $attrs['amount'] : 0,
					'currency'  => isset( $attrs['currency'] ) ? (string) $attrs['currency'] : '',
					'products'  => isset( $attrs['products'] ) && is_array( $attrs['products'] ) ? $attrs['products'] : [],
					'priceMode' => isset( $attrs['priceMode'] ) ? (string) $attrs['priceMode'] : 'fixed',
				];
			},
			10,
			4
		);

		// The payment field uses a hidden input to carry the PaymentIntent ID.
		// Sanitise it and use it as the field value.
		add_filter(
			'flinkform_sanitise_field',
			static function ( $sanitised, string $type, $raw ) {
				if ( 'payment' !== $type ) {
					return $sanitised;
				}
				return is_string( $raw ) && str_starts_with( $raw, 'pi_' ) ? sanitize_text_field( $raw ) : '';
			},
			10,
			3
		);

		// Verify the Stripe payment after all fields are validated.
		add_filter( 'flinkform_process_submission', [ $this, 'verify_payment' ], 20, 3 );

		// REST endpoint for creating PaymentIntents from the frontend.
		add_action( 'rest_api_init', [ new RestController(), 'register_routes' ] );

		// Admin settings page.
		( new SettingsPage() )->register();

		// Enqueue Stripe.js on pages with a payment form.
		add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_stripe_js' ] );
	}

	/**
	 * Verify the Stripe PaymentIntent after form validation.
	 *
	 * Runs on `flinkform_process_submission` at priority 20 (after the
	 * default sanitisation at 10). If the form has a payment field and
	 * the PaymentIntent is not confirmed as paid, an error is injected.
	 *
	 * @param array<string, mixed> $result     { clean, errors }.
	 * @param array<string, mixed> $definition Form definition.
	 * @param string               $form_id    Form ID.
	 * @return array<string, mixed>
	 */
	public function verify_payment( array $result, array $definition, string $form_id ): array {
		$fields = $definition['fields'] ?? [];
		$payment_field = null;

		foreach ( $fields as $field ) {
			if ( ( $field['type'] ?? '' ) === 'payment' ) {
				$payment_field = $field;
				break;
			}
		}

		// No payment field in this form.
		if ( null === $payment_field ) {
			return $result;
		}

		// Already has errors — don't attempt payment verification.
		if ( ! empty( $result['errors'] ) ) {
			return $result;
		}

		$field_name = (string) ( $payment_field['name'] ?? '' );
		$intent_id  = (string) ( $result['clean'][ $field_name ] ?? '' );

		if ( '' === $intent_id || ! str_starts_with( $intent_id, 'pi_' ) ) {
			$result['errors'][ $field_name ] = __( 'Payment was not completed. Please try again.', 'flinkform-pro' );
			return $result;
		}

		// Verify with Stripe.
		$settings   = get_option( 'flinkform_stripe_settings', [] );
		$secret_key = Secret::decrypt( (string) ( $settings['secret_key'] ?? '' ) );

		if ( '' === $secret_key ) {
			$result['errors']['_form'] = __( 'Payment processing is not configured.', 'flinkform-pro' );
			return $result;
		}

		$api    = new StripeApi( $secret_key );
		$verify = $api->retrieve_payment_intent( $intent_id );

		if ( ! $verify['success'] ) {
			$result['errors'][ $field_name ] = __( 'Payment verification failed. Please try again.', 'flinkform-pro' );
			return $result;
		}

		if ( 'succeeded' !== ( $verify['status'] ?? '' ) ) {
			$result['errors'][ $field_name ] = __( 'Payment was not completed. Please try again.', 'flinkform-pro' );
			return $result;
		}

		// Verify the paid amount matches the configured price (anti-tampering).
		$expected = $this->resolve_expected_amount( $payment_field, $result['clean'] );
		if ( $expected > 0 && (int) ( $verify['amount'] ?? 0 ) !== $expected ) {
			$result['errors'][ $field_name ] = __( 'Payment amount mismatch. Please try again.', 'flinkform-pro' );
			return $result;
		}

		return $result;
	}

	/**
	 * Enqueue Stripe.js on pages that contain a Flinkform with a payment field.
	 *
	 * Uses wp_enqueue_script with the Stripe CDN URL. The actual mounting
	 * happens in the block's view.js.
	 *
	 * @return void
	 */
	public function maybe_enqueue_stripe_js(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post || ! has_block( 'flinkform/field-payment', $post ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External Stripe.js, version is managed by Stripe.
		wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', [], null, false );
	}

	/**
	 * Resolve the expected payment amount from the field definition.
	 *
	 * For fixed-price fields, returns the configured amount. For product-
	 * choice fields, looks up which product the visitor selected and
	 * returns that amount. Returns 0 if the amount cannot be determined
	 * (caller should skip the amount check in that case).
	 *
	 * @param array<string, mixed> $field Payment field definition.
	 * @param array<string, mixed> $clean Sanitised submission values.
	 * @return int Amount in smallest currency unit (cents).
	 */
	private function resolve_expected_amount( array $field, array $clean ): int {
		$mode = (string) ( $field['priceMode'] ?? 'fixed' );

		if ( 'fixed' === $mode ) {
			return (int) ( $field['amount'] ?? 0 );
		}

		// Product mode: the selected amount is posted as a separate field.
		$field_name     = (string) ( $field['name'] ?? '' );
		$selected_raw   = '';

		// The product radio posts into flinkform_payment_product[fieldName].
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified in the core handler.
		if ( isset( $_POST['flinkform_payment_product'][ $field_name ] ) ) {
			$selected_raw = sanitize_text_field( wp_unslash( (string) $_POST['flinkform_payment_product'][ $field_name ] ) );
		}

		$selected_amount = (int) $selected_raw;
		$products        = isset( $field['products'] ) && is_array( $field['products'] ) ? $field['products'] : [];

		// Validate the selected amount is actually one of the configured products.
		foreach ( $products as $product ) {
			if ( (int) ( $product['amount'] ?? 0 ) === $selected_amount ) {
				return $selected_amount;
			}
		}

		return 0; // Unknown product, caller skips amount check.
	}
}
