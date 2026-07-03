<?php
/**
 * Safe arithmetic formula evaluator (Flinkform Pro).
 *
 * Evaluates author-written formulas like
 *
 *     ({field:qty} * 49.90) + {field:setup} - 10
 *
 * against the submitted field values. Implemented as a classic
 * tokenise → shunting-yard → RPN evaluation pipeline over an allow-list
 * grammar (numbers, field references, + - * /, parentheses, unary minus).
 * There is by design NO eval(), no function calls, no variables beyond
 * `{field:...}` references — a formula can compute numbers and nothing else.
 *
 * The exact same algorithm ships in blocks/src/field-calculation/evaluator.js
 * for the live frontend preview; THIS implementation is the authoritative
 * one — the server recomputes every calculation field on submit and never
 * trusts the client's displayed value. Keep both in sync.
 *
 * @package FlinkformPro
 * @since 1.2.0
 */

declare( strict_types = 1 );

namespace FlinkformPro\Calculations;

defined( 'ABSPATH' ) || exit;

/**
 * Tokenises and evaluates calculation formulas.
 */
final class Evaluator {

	/**
	 * Operator precedence. 'u-' is unary minus.
	 *
	 * @var array<string, int>
	 */
	private const PRECEDENCE = [
		'+'  => 1,
		'-'  => 1,
		'*'  => 2,
		'/'  => 2,
		'u-' => 3,
	];

	/**
	 * Evaluate a formula against resolved field values.
	 *
	 * @param string                $formula  Author formula.
	 * @param array<string, mixed>  $values   Submitted values keyed by field name.
	 * @return float|null Result, or null when the formula is malformed
	 *                    (unbalanced parens, unknown tokens, division by zero).
	 */
	public static function evaluate( string $formula, array $values ): ?float {
		$tokens = self::tokenise( $formula );
		if ( null === $tokens || empty( $tokens ) ) {
			return null;
		}

		$rpn = self::to_rpn( $tokens );
		if ( null === $rpn ) {
			return null;
		}

		return self::eval_rpn( $rpn, $values );
	}

	/**
	 * Resolve one field value to a number.
	 *
	 * Arrays (checkbox groups, multi-selects, multi-file) sum their numeric
	 * entries; scalars parse a leading float (comma accepted as decimal
	 * separator); anything non-numeric counts as 0 — a calculation must
	 * never fail because a referenced text field holds prose.
	 *
	 * @param mixed $value Submitted value.
	 * @return float
	 */
	public static function to_number( $value ): float {
		if ( is_array( $value ) ) {
			$sum = 0.0;
			foreach ( $value as $item ) {
				$sum += self::to_number( $item );
			}
			return $sum;
		}

		$str = str_replace( ',', '.', trim( (string) $value ) );
		if ( '' === $str || ! preg_match( '/^-?\d+(\.\d+)?/', $str, $m ) ) {
			return 0.0;
		}

		return (float) $m[0];
	}

	/**
	 * Split a formula into tokens.
	 *
	 * Token shapes: ['num', float] | ['ref', string] | ['op', string] |
	 * ['(', null] | [')', null]. Returns null on any character outside
	 * the grammar.
	 *
	 * @param string $formula
	 * @return array<int, array{0: string, 1: mixed}>|null
	 */
	private static function tokenise( string $formula ): ?array {
		$tokens = [];
		$len    = strlen( $formula );
		$i      = 0;

		while ( $i < $len ) {
			$char = $formula[ $i ];

			if ( ' ' === $char || "\t" === $char || "\n" === $char || "\r" === $char ) {
				++$i;
				continue;
			}

			// Number (dot or comma as decimal separator).
			if ( preg_match( '/\G\d+([.,]\d+)?/', $formula, $m, 0, $i ) ) {
				$tokens[] = [ 'num', (float) str_replace( ',', '.', $m[0] ) ];
				$i       += strlen( $m[0] );
				continue;
			}

			// Field reference: {field:name}.
			if ( preg_match( '/\G\{field:([a-zA-Z0-9_\-]+)\}/', $formula, $m, 0, $i ) ) {
				$tokens[] = [ 'ref', $m[1] ];
				$i       += strlen( $m[0] );
				continue;
			}

			if ( '+' === $char || '-' === $char || '*' === $char || '/' === $char ) {
				$tokens[] = [ 'op', $char ];
				++$i;
				continue;
			}

			if ( '(' === $char || ')' === $char ) {
				$tokens[] = [ $char, null ];
				++$i;
				continue;
			}

			return null; // Outside the grammar.
		}

		return $tokens;
	}

	/**
	 * Shunting-yard: infix tokens → RPN. Detects unary minus by context
	 * (expression start, after an operator, after an opening paren).
	 *
	 * @param array<int, array{0: string, 1: mixed}> $tokens
	 * @return array<int, array{0: string, 1: mixed}>|null Null on unbalanced parens.
	 */
	private static function to_rpn( array $tokens ): ?array {
		$output   = [];
		$stack    = [];
		$prev     = null; // Previous significant token kind for unary detection.

		foreach ( $tokens as $token ) {
			[ $kind, $value ] = $token;

			if ( 'num' === $kind || 'ref' === $kind ) {
				$output[] = $token;
				$prev     = 'operand';
				continue;
			}

			if ( 'op' === $kind ) {
				$op = (string) $value;

				// Unary minus: no operand before it.
				if ( '-' === $op && 'operand' !== $prev ) {
					$op = 'u-';
				} elseif ( '+' === $op && 'operand' !== $prev ) {
					$prev = null; // Unary plus is a no-op — skip it.
					continue;
				}

				while (
					! empty( $stack )
					&& 'op' === end( $stack )[0]
					&& (
						self::PRECEDENCE[ end( $stack )[1] ] > self::PRECEDENCE[ $op ]
						// Left-assoc binary ops pop equal precedence; the
						// right-assoc unary minus does not.
						|| ( self::PRECEDENCE[ end( $stack )[1] ] === self::PRECEDENCE[ $op ] && 'u-' !== $op )
					)
				) {
					$output[] = array_pop( $stack );
				}

				$stack[] = [ 'op', $op ];
				$prev    = 'op';
				continue;
			}

			if ( '(' === $kind ) {
				$stack[] = $token;
				$prev    = null;
				continue;
			}

			// ')' — pop until the matching '('.
			$found = false;
			while ( ! empty( $stack ) ) {
				$top = array_pop( $stack );
				if ( '(' === $top[0] ) {
					$found = true;
					break;
				}
				$output[] = $top;
			}
			if ( ! $found ) {
				return null; // Unbalanced.
			}
			$prev = 'operand';
		}

		while ( ! empty( $stack ) ) {
			$top = array_pop( $stack );
			if ( '(' === $top[0] ) {
				return null; // Unbalanced.
			}
			$output[] = $top;
		}

		return $output;
	}

	/**
	 * Evaluate an RPN token list.
	 *
	 * @param array<int, array{0: string, 1: mixed}> $rpn
	 * @param array<string, mixed>                   $values Submitted values keyed by field name.
	 * @return float|null Null on stack underflow or division by zero.
	 */
	private static function eval_rpn( array $rpn, array $values ): ?float {
		$stack = [];

		foreach ( $rpn as [ $kind, $value ] ) {
			if ( 'num' === $kind ) {
				$stack[] = (float) $value;
				continue;
			}

			if ( 'ref' === $kind ) {
				$stack[] = self::to_number( $values[ (string) $value ] ?? 0 );
				continue;
			}

			// Operator.
			if ( 'u-' === $value ) {
				if ( empty( $stack ) ) {
					return null;
				}
				$stack[] = -array_pop( $stack );
				continue;
			}

			if ( count( $stack ) < 2 ) {
				return null;
			}
			$b = array_pop( $stack );
			$a = array_pop( $stack );

			switch ( $value ) {
				case '+':
					$stack[] = $a + $b;
					break;
				case '-':
					$stack[] = $a - $b;
					break;
				case '*':
					$stack[] = $a * $b;
					break;
				case '/':
					if ( 0.0 === $b ) {
						return null;
					}
					$stack[] = $a / $b;
					break;
			}
		}

		return 1 === count( $stack ) ? (float) $stack[0] : null;
	}
}
