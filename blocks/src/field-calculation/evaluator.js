/**
 * Safe arithmetic formula evaluator — JS mirror of the authoritative PHP
 * implementation in includes/Calculations/Evaluator.php. Keep both in sync.
 *
 * Grammar: numbers (dot or comma decimals), {field:name} references,
 * + - * /, parentheses, unary minus. No eval(), no functions.
 *
 * evaluate() returns a number, or null when the formula is malformed
 * (unbalanced parens, unknown tokens, division by zero).
 */

const PRECEDENCE = { '+': 1, '-': 1, '*': 2, '/': 2, 'u-': 3 };

export function toNumber( value ) {
	if ( Array.isArray( value ) ) {
		return value.reduce( ( sum, item ) => sum + toNumber( item ), 0 );
	}
	const str = String( value == null ? '' : value ).trim().replace( ',', '.' );
	const match = str.match( /^-?\d+(\.\d+)?/ );
	return match ? parseFloat( match[ 0 ] ) : 0;
}

function tokenise( formula ) {
	const tokens = [];
	let i = 0;

	while ( i < formula.length ) {
		const char = formula[ i ];

		if ( ' ' === char || '\t' === char || '\n' === char || '\r' === char ) {
			i++;
			continue;
		}

		const num = /^\d+([.,]\d+)?/.exec( formula.slice( i ) );
		if ( num ) {
			tokens.push( [ 'num', parseFloat( num[ 0 ].replace( ',', '.' ) ) ] );
			i += num[ 0 ].length;
			continue;
		}

		const ref = /^\{field:([a-zA-Z0-9_-]+)\}/.exec( formula.slice( i ) );
		if ( ref ) {
			tokens.push( [ 'ref', ref[ 1 ] ] );
			i += ref[ 0 ].length;
			continue;
		}

		if ( '+' === char || '-' === char || '*' === char || '/' === char ) {
			tokens.push( [ 'op', char ] );
			i++;
			continue;
		}

		if ( '(' === char || ')' === char ) {
			tokens.push( [ char, null ] );
			i++;
			continue;
		}

		return null; // Outside the grammar.
	}

	return tokens;
}

function toRpn( tokens ) {
	const output = [];
	const stack = [];
	let prev = null;

	for ( const token of tokens ) {
		const [ kind, value ] = token;

		if ( 'num' === kind || 'ref' === kind ) {
			output.push( token );
			prev = 'operand';
			continue;
		}

		if ( 'op' === kind ) {
			let op = value;

			if ( '-' === op && 'operand' !== prev ) {
				op = 'u-';
			} else if ( '+' === op && 'operand' !== prev ) {
				prev = null; // Unary plus is a no-op.
				continue;
			}

			while (
				stack.length &&
				'op' === stack[ stack.length - 1 ][ 0 ] &&
				( PRECEDENCE[ stack[ stack.length - 1 ][ 1 ] ] > PRECEDENCE[ op ] ||
					( PRECEDENCE[ stack[ stack.length - 1 ][ 1 ] ] === PRECEDENCE[ op ] && 'u-' !== op ) )
			) {
				output.push( stack.pop() );
			}

			stack.push( [ 'op', op ] );
			prev = 'op';
			continue;
		}

		if ( '(' === kind ) {
			stack.push( token );
			prev = null;
			continue;
		}

		// ')'
		let found = false;
		while ( stack.length ) {
			const top = stack.pop();
			if ( '(' === top[ 0 ] ) {
				found = true;
				break;
			}
			output.push( top );
		}
		if ( ! found ) {
			return null;
		}
		prev = 'operand';
	}

	while ( stack.length ) {
		const top = stack.pop();
		if ( '(' === top[ 0 ] ) {
			return null;
		}
		output.push( top );
	}

	return output;
}

function evalRpn( rpn, resolve ) {
	const stack = [];

	for ( const [ kind, value ] of rpn ) {
		if ( 'num' === kind ) {
			stack.push( value );
			continue;
		}
		if ( 'ref' === kind ) {
			stack.push( resolve( value ) );
			continue;
		}

		if ( 'u-' === value ) {
			if ( ! stack.length ) {
				return null;
			}
			stack.push( -stack.pop() );
			continue;
		}

		if ( stack.length < 2 ) {
			return null;
		}
		const b = stack.pop();
		const a = stack.pop();

		if ( '+' === value ) {
			stack.push( a + b );
		} else if ( '-' === value ) {
			stack.push( a - b );
		} else if ( '*' === value ) {
			stack.push( a * b );
		} else if ( '/' === value ) {
			if ( 0 === b ) {
				return null;
			}
			stack.push( a / b );
		}
	}

	return 1 === stack.length ? stack[ 0 ] : null;
}

/**
 * Evaluate a formula.
 *
 * @param {string}   formula Author formula.
 * @param {Function} resolve Callback: field name → number.
 * @return {?number} Result, or null when malformed.
 */
export function evaluate( formula, resolve ) {
	const tokens = tokenise( formula );
	if ( ! tokens || ! tokens.length ) {
		return null;
	}
	const rpn = toRpn( tokens );
	if ( ! rpn ) {
		return null;
	}
	return evalRpn( rpn, resolve );
}
