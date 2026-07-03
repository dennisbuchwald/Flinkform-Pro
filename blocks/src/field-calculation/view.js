/**
 * Calculation field — frontend live computation (Pro).
 *
 * Listens to input/change events on the surrounding form and recomputes
 * every calculation field from the current field values. Purely cosmetic:
 * the server recomputes the formula authoritatively on submit and never
 * trusts what this script displays or posts.
 *
 * Field values resolve the same way the PHP side does: radio → checked
 * value, checkbox group → sum of checked numeric values, multi-select →
 * sum of selected, toggle → 1/0, everything else → leading float or 0.
 */

import { evaluate, toNumber } from './evaluator';

( function () {
	'use strict';

	function resolveField( form, name ) {
		const selector = ( suffix ) => `[name="flinkform_field[${ name }]${ suffix }"]`;

		// Checkbox group / multi-select post as name[].
		const multi = form.querySelectorAll( selector( '[]' ) );
		if ( multi.length ) {
			let sum = 0;
			multi.forEach( ( el ) => {
				if ( 'checkbox' === el.type ) {
					if ( el.checked ) {
						sum += toNumber( el.value );
					}
				} else if ( el.multiple && el.selectedOptions ) {
					Array.from( el.selectedOptions ).forEach( ( opt ) => {
						sum += toNumber( opt.value );
					} );
				}
			} );
			return sum;
		}

		const els = form.querySelectorAll( selector( '' ) );
		if ( ! els.length ) {
			return 0;
		}

		// Radio group: the checked one counts.
		if ( els.length > 1 || 'radio' === els[ 0 ].type ) {
			let value = 0;
			els.forEach( ( el ) => {
				if ( 'radio' !== el.type || el.checked ) {
					value = toNumber( el.value );
				}
			} );
			return value;
		}

		const el = els[ 0 ];
		if ( 'checkbox' === el.type ) {
			return el.checked ? toNumber( el.value ) || 1 : 0;
		}
		return toNumber( el.value );
	}

	function setup( field ) {
		const form = field.closest( 'form' );
		if ( ! form ) {
			return;
		}

		const output  = field.querySelector( '[data-flinkform-calculation-output]' );
		const input   = field.querySelector( '[data-flinkform-calculation-input]' );
		const formula = field.dataset.formula || '';
		const decimals = Math.max( 0, Math.min( 4, parseInt( field.dataset.decimals, 10 ) || 0 ) );

		const recompute = () => {
			const result = '' !== formula
				? evaluate( formula, ( name ) => resolveField( form, name ) )
				: null;

			if ( null === result || ! isFinite( result ) ) {
				if ( output ) {
					output.textContent = '—';
				}
				if ( input ) {
					input.value = '';
				}
				return;
			}

			const fixed = result.toFixed( decimals );
			if ( output ) {
				// Display with the visitor's locale decimal separator.
				output.textContent = fixed.replace( '.', ( 0.5 ).toLocaleString().substring( 1, 2 ) );
			}
			if ( input ) {
				input.value = fixed;
			}
		};

		form.addEventListener( 'input', recompute );
		form.addEventListener( 'change', recompute );
		recompute();
	}

	function init() {
		document.querySelectorAll( '[data-flinkform-calculation]' ).forEach( setup );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
