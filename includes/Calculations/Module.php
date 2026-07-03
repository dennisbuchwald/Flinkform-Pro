<?php
/**
 * Calculations module wiring (Flinkform Pro).
 *
 * Registers the Calculation field block and docks it onto the free core's
 * field-type seams. The field displays a live-computed number on the
 * frontend (view.js) and is ALWAYS recomputed server-side on submit —
 * the client's displayed value is never trusted or persisted.
 *
 * Runs at priority 5 on `flinkform_process_submission`, i.e. before the
 * upload processing (10) and the payment verification (20), so downstream
 * consumers see the final computed value in $clean.
 *
 * @package FlinkformPro
 * @since 1.2.0
 */

declare( strict_types = 1 );

namespace FlinkformPro\Calculations;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the calculation field into the free core.
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
				$dirs['field-calculation'] = FLINKFORM_PRO_DIR . 'blocks/build/field-calculation';
				return $dirs;
			}
		);

		// Field-type registration.
		add_filter(
			'flinkform_field_blocks',
			static function ( array $map ): array {
				$map['flinkform/field-calculation'] = 'calculation';
				return $map;
			}
		);

		// Carry the formula + formatting attributes into the field definition.
		add_filter(
			'flinkform_field_extras',
			static function ( array $extras, string $type, string $block_name, array $attrs ): array {
				if ( 'calculation' !== $type ) {
					return $extras;
				}
				return [
					'formula'  => isset( $attrs['formula'] ) && is_string( $attrs['formula'] ) ? $attrs['formula'] : '',
					'decimals' => isset( $attrs['decimals'] ) && is_numeric( $attrs['decimals'] ) ? max( 0, min( 4, (int) $attrs['decimals'] ) ) : 2,
				];
			},
			10,
			4
		);

		// The client's posted value is irrelevant — blank it here, the
		// authoritative recompute below fills it in.
		add_filter(
			'flinkform_sanitise_field',
			static function ( $sanitised, string $type ) {
				return 'calculation' === $type ? '' : $sanitised;
			},
			10,
			2
		);

		// Authoritative server-side recompute of every calculation field.
		add_filter( 'flinkform_process_submission', [ $this, 'recompute' ], 5, 2 );
	}

	/**
	 * Recompute all calculation fields from the sanitised values.
	 *
	 * @param array{clean: array<string, mixed>, errors: array<string, string>} $result
	 * @param array<string, mixed> $definition Located form definition.
	 * @return array{clean: array<string, mixed>, errors: array<string, string>}
	 */
	public function recompute( array $result, array $definition ): array {
		$fields = isset( $definition['fields'] ) && is_array( $definition['fields'] ) ? $definition['fields'] : [];

		foreach ( $fields as $field ) {
			if ( ( $field['type'] ?? '' ) !== 'calculation' ) {
				continue;
			}
			$name = (string) ( $field['name'] ?? '' );
			if ( '' === $name || ! array_key_exists( $name, $result['clean'] ) ) {
				continue; // Stripped by conditional logic — leave it out.
			}

			$formula  = (string) ( $field['formula'] ?? '' );
			$decimals = isset( $field['decimals'] ) && is_numeric( $field['decimals'] ) ? (int) $field['decimals'] : 2;

			$value = '' !== $formula ? Evaluator::evaluate( $formula, $result['clean'] ) : null;

			// Malformed formula or division by zero: store '' rather than a
			// wrong number. Never an error — the visitor can't fix the
			// author's formula.
			$result['clean'][ $name ] = null === $value
				? ''
				: number_format( $value, $decimals, '.', '' );
		}

		return $result;
	}
}
