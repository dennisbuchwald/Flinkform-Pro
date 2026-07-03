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

		// Attach the persisted submission to its claimed payment row.
		add_action( 'flinkform_after_submission', [ $this, 'attach_payment_to_submission' ], 10, 3 );

		// REST endpoints: PaymentIntent creation + the Stripe webhook receiver.
		add_action( 'rest_api_init', [ new RestController(), 'register_routes' ] );
		add_action( 'rest_api_init', [ new WebhookController(), 'register_routes' ] );

		// Admin: settings page + payment details on the submission detail view.
		( new SettingsPage() )->register();
		( new SubmissionSection() )->register();

		// GDPR cascade: deleting submissions removes their payment rows.
		add_action(
			'flinkform_submissions_deleted',
			static function ( $submission_ids ): void {
				( new PaymentRepository() )->delete_for_submissions( is_array( $submission_ids ) ? $submission_ids : [] );
			}
		);

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

		// Synchronous methods (card, wallets) confirm as `succeeded`.
		// Asynchronous methods (SEPA Direct Debit) confirm as `processing`
		// and settle days later — the Stripe webhook updates the payment row
		// then. Both count as "payment made" for accepting the submission.
		$intent_status = (string) ( $verify['status'] ?? '' );
		if ( ! in_array( $intent_status, [ 'succeeded', 'processing' ], true ) ) {
			$result['errors'][ $field_name ] = __( 'Payment was not completed. Please try again.', 'flinkform-pro' );
			return $result;
		}

		// The intent must have been minted for THIS form (create-intent
		// stamps the form_id into the metadata) — otherwise an intent
		// created against a cheap form could be replayed on an expensive one.
		$intent_form = (string) ( $verify['form_id'] ?? '' );
		if ( '' !== $intent_form && $intent_form !== $form_id ) {
			$result['errors'][ $field_name ] = __( 'Payment was not completed. Please try again.', 'flinkform-pro' );
			return $result;
		}

		// Anti-tampering: the amount that was actually charged must equal the
		// price the visitor was shown for their selection. resolve_expected_amount
		// returns null when a product-mode submission carries a selection that
		// matches no configured product — that is a manipulated request and we
		// fail closed (reject) rather than skipping the check.
		$expected = $this->resolve_expected_amount( $payment_field, $result['clean'] );
		if ( null === $expected || $expected <= 0 || (int) ( $verify['amount'] ?? 0 ) !== $expected ) {
			$result['errors'][ $field_name ] = __( 'Payment amount mismatch. Please try again.', 'flinkform-pro' );
			return $result;
		}

		// Currency must match too — otherwise a client that forced a weaker
		// currency (e.g. paying 500 JPY against a 500-cent EUR price) would
		// pass the integer amount check above.
		$expected_currency = self::resolve_currency( $payment_field );
		if ( '' !== $expected_currency && strtolower( (string) ( $verify['currency'] ?? '' ) ) !== $expected_currency ) {
			$result['errors'][ $field_name ] = __( 'Payment amount mismatch. Please try again.', 'flinkform-pro' );
			return $result;
		}

		// Replay protection: claim the intent for this submission. A paid
		// intent can only be consumed ONCE — posting the same pi_... again
		// (to mint a second accepted submission from one payment) fails here.
		$claimed = ( new PaymentRepository() )->claim_intent(
			$intent_id,
			$form_id,
			$intent_status,
			(int) ( $verify['amount'] ?? 0 ),
			strtolower( (string) ( $verify['currency'] ?? '' ) ),
			(string) ( $verify['method'] ?? '' )
		);
		if ( ! $claimed ) {
			$result['errors'][ $field_name ] = __( 'This payment was already used for another submission.', 'flinkform-pro' );
			return $result;
		}

		// Remember which intent belongs to the submission being persisted so
		// attach_payment_to_submission() can link the row after the insert.
		$this->pending_intent = [
			'intent_id' => $intent_id,
			'form_id'   => $form_id,
		];

		return $result;
	}

	/**
	 * Intent claimed during verify_payment(), waiting for the submission
	 * insert of the SAME request. Request-scoped by nature (one submission
	 * per request through admin-post.php).
	 *
	 * @var array{intent_id: string, form_id: string}|null
	 */
	private ?array $pending_intent = null;

	/**
	 * Link the persisted submission to the payment row claimed earlier in
	 * this request. Runs on `flinkform_after_submission`.
	 *
	 * @param int    $submission_id Newly inserted submission row id.
	 * @param string $form_id       Form UUID.
	 * @param mixed  $clean         Sanitised values (unused).
	 * @return void
	 */
	public function attach_payment_to_submission( $submission_id, $form_id, $clean ): void {
		unset( $clean );

		if ( null === $this->pending_intent || $this->pending_intent['form_id'] !== (string) $form_id ) {
			return;
		}

		( new PaymentRepository() )->attach_submission( $this->pending_intent['intent_id'], (int) $submission_id );
		$this->pending_intent = null;
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
	 * choice fields, looks up which product the visitor selected and returns
	 * that amount. Returns null when the amount cannot be legitimately
	 * determined (product mode with a selection matching no configured
	 * product) — callers MUST treat null as "reject", never as "skip".
	 *
	 * @param array<string, mixed> $field Payment field definition.
	 * @param array<string, mixed> $clean Sanitised submission values.
	 * @return int|null Amount in smallest currency unit (cents), or null when invalid.
	 */
	private function resolve_expected_amount( array $field, array $clean ): ?int {
		$mode = (string) ( $field['priceMode'] ?? 'fixed' );

		if ( 'fixed' === $mode ) {
			return (int) ( $field['amount'] ?? 0 );
		}

		// Product mode: the selected amount is posted as a separate field.
		$field_name   = (string) ( $field['name'] ?? '' );
		$selected_raw = '';

		// The product radio posts into flinkform_payment_product[fieldName].
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified in the core handler.
		if ( isset( $_POST['flinkform_payment_product'][ $field_name ] ) ) {
			$selected_raw = sanitize_text_field( wp_unslash( (string) $_POST['flinkform_payment_product'][ $field_name ] ) );
		}

		$selected_amount = (int) $selected_raw;

		// The selected amount must be one of the configured products.
		if ( in_array( $selected_amount, self::allowed_amounts( $field ), true ) ) {
			return $selected_amount;
		}

		return null; // Manipulated / unknown selection — caller rejects.
	}

	/**
	 * The set of amounts a payment field legitimately accepts.
	 *
	 * Fixed mode → the single configured amount. Product mode → every
	 * configured product's amount. Used both to validate the paid amount
	 * server-side (verify_payment) and to bind the PaymentIntent amount to
	 * the form definition instead of trusting the client (create-intent).
	 *
	 * @param array<string, mixed> $field Payment field definition.
	 * @return array<int, int> Allowed amounts in cents (positive values only).
	 */
	public static function allowed_amounts( array $field ): array {
		$mode = (string) ( $field['priceMode'] ?? 'fixed' );

		if ( 'fixed' === $mode ) {
			$amount = (int) ( $field['amount'] ?? 0 );
			return $amount > 0 ? [ $amount ] : [];
		}

		$products = isset( $field['products'] ) && is_array( $field['products'] ) ? $field['products'] : [];
		$amounts  = [];
		foreach ( $products as $product ) {
			$amount = (int) ( $product['amount'] ?? 0 );
			if ( $amount > 0 ) {
				$amounts[] = $amount;
			}
		}
		return array_values( array_unique( $amounts ) );
	}

	/**
	 * Resolve the expected currency for a payment field, lower-cased.
	 *
	 * Field-level currency wins; otherwise the global Stripe setting;
	 * otherwise EUR. Returns '' only when nothing is configured at all.
	 *
	 * @param array<string, mixed> $field Payment field definition.
	 * @return string Three-letter ISO code, lower-case (e.g. 'eur').
	 */
	public static function resolve_currency( array $field ): string {
		$currency = isset( $field['currency'] ) ? strtolower( trim( (string) $field['currency'] ) ) : '';
		if ( '' !== $currency ) {
			return $currency;
		}

		$settings = get_option( 'flinkform_stripe_settings', [] );
		$currency = is_array( $settings ) && isset( $settings['currency'] ) ? strtolower( trim( (string) $settings['currency'] ) ) : '';

		return '' !== $currency ? $currency : 'eur';
	}
}
