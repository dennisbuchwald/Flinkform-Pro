<?php
/**
 * Server-side render for the Calculation Field block (Pro).
 *
 * Renders a read-only computed value: an <output> element (implicitly
 * aria-live) for the visible number and a hidden input that travels with
 * the POST. The hidden value is decorative — the server recomputes the
 * formula authoritatively on submit (Calculations\Module::recompute()).
 *
 * @var array<string, mixed> $attributes
 * @var WP_Block             $block
 *
 * @package FlinkformPro
 * @since 1.2.0
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$form_id    = isset( $block->context['flinkform/formId'] ) ? (string) $block->context['flinkform/formId'] : '';
$label      = isset( $attributes['label'] ) && is_string( $attributes['label'] ) ? $attributes['label'] : '';
$field_name = isset( $attributes['fieldName'] ) && is_string( $attributes['fieldName'] ) ? $attributes['fieldName'] : '';
$formula    = isset( $attributes['formula'] ) && is_string( $attributes['formula'] ) ? $attributes['formula'] : '';
$decimals   = isset( $attributes['decimals'] ) && is_numeric( $attributes['decimals'] ) ? max( 0, min( 4, (int) $attributes['decimals'] ) ) : 2;
$prefix     = isset( $attributes['prefix'] ) && is_string( $attributes['prefix'] ) ? $attributes['prefix'] : '';
$suffix     = isset( $attributes['suffix'] ) && is_string( $attributes['suffix'] ) ? $attributes['suffix'] : '';
$help_text  = isset( $attributes['helpText'] ) && is_string( $attributes['helpText'] ) ? $attributes['helpText'] : '';

if ( '' === $field_name || '' === $form_id ) {
	return;
}

$field_uid = 'flinkform-field-' . md5( $form_id . '-' . $field_name );
$help_id   = $help_text ? $field_uid . '-help' : '';
?>
<div
	class="flinkform-field flinkform-field--calculation<?php echo ! empty( $attributes['fullWidth'] ) ? ' flinkform-field--full-width' : ''; ?>"
	<?php $flinkform_condition = \Flinkform\Conditions\Wrapper::condition_value( $attributes['conditionalLogic'] ?? [] ); echo $flinkform_condition ? ' data-flinkform-condition="' . esc_attr( $flinkform_condition ) . '"' : ''; ?>
	data-flinkform-field-name="<?php echo esc_attr( $field_name ); ?>"
	data-flinkform-calculation
	data-formula="<?php echo esc_attr( $formula ); ?>"
	data-decimals="<?php echo esc_attr( (string) $decimals ); ?>"
>
	<span class="flinkform-field__label" id="<?php echo esc_attr( $field_uid . '-label' ); ?>">
		<?php echo esc_html( $label ); ?>
	</span>
	<div class="flinkform-field__calculation-value">
		<?php if ( '' !== $prefix ) : ?>
			<span class="flinkform-field__calculation-prefix"><?php echo esc_html( $prefix ); ?></span>
		<?php endif; ?>
		<output
			id="<?php echo esc_attr( $field_uid ); ?>"
			class="flinkform-field__calculation-output"
			aria-labelledby="<?php echo esc_attr( $field_uid . '-label' ); ?>"
			data-flinkform-calculation-output
		>&mdash;</output>
		<?php if ( '' !== $suffix ) : ?>
			<span class="flinkform-field__calculation-suffix"><?php echo esc_html( $suffix ); ?></span>
		<?php endif; ?>
	</div>
	<input
		type="hidden"
		name="flinkform_field[<?php echo esc_attr( $field_name ); ?>]"
		value=""
		data-flinkform-calculation-input
	/>
	<?php if ( $help_text ) : ?>
		<p class="flinkform-field__help" id="<?php echo esc_attr( $help_id ); ?>">
			<?php echo esc_html( $help_text ); ?>
		</p>
	<?php endif; ?>
</div>
