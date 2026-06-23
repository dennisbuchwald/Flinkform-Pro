<?php
/**
 * Server-side render for the Payment Field block (Pro).
 *
 * Renders either a fixed amount display or product radio buttons, plus
 * the Stripe Card Element mount point and hidden inputs for the
 * PaymentIntent ID. The actual Stripe.js interaction is handled by
 * view.js on the frontend.
 *
 * @var array<string, mixed> $attributes
 * @var WP_Block             $block
 *
 * @package FlinkformPro
 * @since 1.1.0
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$form_id    = isset( $block->context['flinkform/formId'] ) ? (string) $block->context['flinkform/formId'] : '';
$label      = isset( $attributes['label'] ) && is_string( $attributes['label'] ) ? $attributes['label'] : '';
$field_name = isset( $attributes['fieldName'] ) && is_string( $attributes['fieldName'] ) ? $attributes['fieldName'] : '';
$price_mode = isset( $attributes['priceMode'] ) && is_string( $attributes['priceMode'] ) ? $attributes['priceMode'] : 'fixed';
$amount     = isset( $attributes['amount'] ) ? (int) $attributes['amount'] : 0;
$currency   = isset( $attributes['currency'] ) && is_string( $attributes['currency'] ) && '' !== $attributes['currency']
	? $attributes['currency']
	: '';
$products   = isset( $attributes['products'] ) && is_array( $attributes['products'] ) ? $attributes['products'] : [];

if ( '' === $field_name || '' === $form_id ) {
	return;
}

// Resolve currency: block attribute > global setting > EUR fallback.
if ( '' === $currency ) {
	$stripe_settings = get_option( 'flinkform_stripe_settings', [] );
	$currency = is_array( $stripe_settings ) && isset( $stripe_settings['currency'] )
		? (string) $stripe_settings['currency']
		: 'eur';
}

// Resolve publishable key for the frontend JS.
$stripe_settings = get_option( 'flinkform_stripe_settings', [] );
$publishable_key = is_array( $stripe_settings ) ? (string) ( $stripe_settings['publishable_key'] ?? '' ) : '';

$error     = \Flinkform\Submissions\Handler::flash_error( $field_name );
$field_uid = 'flinkform-field-' . md5( $form_id . '-' . $field_name );
$error_id  = $error ? $field_uid . '-error' : '';

$currency_symbols = [
	'eur' => "\u{20AC}",
	'usd' => '$',
	'gbp' => "\u{00A3}",
	'chf' => 'CHF',
];
$symbol = $currency_symbols[ strtolower( $currency ) ] ?? strtoupper( $currency );

/**
 * Format cents to display string.
 */
$format_amount = static function ( int $cents ) use ( $symbol ): string {
	return number_format( $cents / 100, 2, ',', '.' ) . ' ' . $symbol;
};
?>
<div
	class="flinkform-field flinkform-field--payment<?php echo $error ? ' flinkform-field--has-error' : ''; ?><?php echo ! empty( $attributes['fullWidth'] ) ? ' flinkform-field--full-width' : ''; ?>"
	<?php $flinkform_condition = \Flinkform\Conditions\Wrapper::condition_value( $attributes['conditionalLogic'] ?? [] ); echo $flinkform_condition ? ' data-flinkform-condition="' . esc_attr( $flinkform_condition ) . '"' : ''; ?>
	data-flinkform-field-name="<?php echo esc_attr( $field_name ); ?>"
	data-flinkform-payment
	data-stripe-key="<?php echo esc_attr( $publishable_key ); ?>"
	data-currency="<?php echo esc_attr( $currency ); ?>"
	data-form-id="<?php echo esc_attr( $form_id ); ?>"
	data-rest-url="<?php echo esc_url( rest_url( 'flinkform-pro/v1/create-intent' ) ); ?>"
	data-nonce="<?php echo esc_attr( wp_create_nonce( 'flinkform_stripe_intent' ) ); ?>"
>
	<label class="flinkform-field__label" for="<?php echo esc_attr( $field_uid ); ?>">
		<?php echo esc_html( $label ); ?>
		<span class="flinkform-field__required" aria-hidden="true"> *</span>
	</label>

	<?php if ( 'products' === $price_mode && ! empty( $products ) ) : ?>
		<div class="flinkform-payment__products" role="radiogroup" aria-label="<?php echo esc_attr( $label ); ?>">
			<?php foreach ( $products as $i => $product ) :
				$p_label  = isset( $product['label'] ) ? (string) $product['label'] : '';
				$p_amount = isset( $product['amount'] ) ? (int) $product['amount'] : 0;
				$p_id     = $field_uid . '-product-' . $i;
			?>
				<label class="flinkform-payment__product-option" for="<?php echo esc_attr( $p_id ); ?>">
					<input
						type="radio"
						id="<?php echo esc_attr( $p_id ); ?>"
						name="flinkform_payment_product[<?php echo esc_attr( $field_name ); ?>]"
						value="<?php echo esc_attr( (string) $p_amount ); ?>"
						class="flinkform-payment__product-radio"
						data-amount="<?php echo esc_attr( (string) $p_amount ); ?>"
						<?php echo 0 === $i ? 'checked' : ''; ?>
						required
					/>
					<span class="flinkform-payment__product-label"><?php echo esc_html( $p_label ); ?></span>
					<span class="flinkform-payment__product-price"><?php echo esc_html( $format_amount( $p_amount ) ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<div class="flinkform-payment__amount" data-amount="<?php echo esc_attr( (string) $amount ); ?>">
			<?php echo esc_html( $format_amount( $amount ) ); ?>
		</div>
	<?php endif; ?>

	<div class="flinkform-payment__card-wrapper">
		<div id="<?php echo esc_attr( $field_uid . '-card' ); ?>" class="flinkform-payment__card-element" data-flinkform-card-element></div>
		<div class="flinkform-payment__card-errors" data-flinkform-card-errors role="alert"></div>
	</div>

	<input
		type="hidden"
		id="<?php echo esc_attr( $field_uid ); ?>"
		name="flinkform_field[<?php echo esc_attr( $field_name ); ?>]"
		value=""
		data-flinkform-payment-intent
	/>

	<?php if ( $error ) : ?>
		<p class="flinkform-field__error" id="<?php echo esc_attr( $error_id ); ?>" role="alert">
			<?php echo esc_html( $error ); ?>
		</p>
	<?php endif; ?>
</div>
