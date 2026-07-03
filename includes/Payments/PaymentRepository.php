<?php
/**
 * Payment tracking repository (Flinkform Pro).
 *
 * Owns `{prefix}flinkform_payments` — one row per PaymentIntent that reached
 * server-side verification. Serves three jobs at once:
 *
 *   1. Replay protection. The UNIQUE key on intent_id makes "claim this
 *      intent for this submission" atomic: a paid intent can only ever be
 *      consumed by ONE submission. Posting the same pi_... a second time is
 *      rejected at verify time.
 *   2. Status model. `status` mirrors Stripe's intent status. Synchronous
 *      methods (card, wallets) arrive as `succeeded`; asynchronous methods
 *      (SEPA Direct Debit) arrive as `processing` and are settled later by
 *      the Stripe webhook (or stay `processing` if no webhook is configured).
 *   3. Admin visibility. Amount, currency, method and status render on the
 *      submission detail screen instead of a bare intent ID.
 *
 * @package FlinkformPro
 * @since 1.2.0
 */

declare( strict_types = 1 );

namespace FlinkformPro\Payments;

use FlinkformPro\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Payments table persistence.
 */
final class PaymentRepository {

	/**
	 * Claim a PaymentIntent for the submission currently being processed.
	 *
	 * Inserts the row. When the intent is already recorded (duplicate key)
	 * the claim FAILS — the intent was consumed by an earlier submission.
	 * The only exception is a row that never got a submission attached
	 * (a previous attempt died between verify and persist, e.g. a DB save
	 * failure): that orphan may be re-claimed so the visitor's honest retry
	 * still goes through.
	 *
	 * @param string $intent_id Stripe PaymentIntent ID (pi_...).
	 * @param string $form_id   Form UUID.
	 * @param string $status    Stripe intent status at verify time.
	 * @param int    $amount    Amount in smallest currency unit.
	 * @param string $currency  Three-letter ISO code, lower-case.
	 * @param string $method    Payment method type (card, sepa_debit, …).
	 * @return bool True when the intent is claimed for this submission.
	 */
	public function claim_intent( string $intent_id, string $form_id, string $status, int $amount, string $currency, string $method ): bool {
		global $wpdb;

		$table = Schema::payments_table_name();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- dedicated custom table.
		$inserted = $wpdb->insert(
			$table,
			[
				'submission_id' => null,
				'form_id'       => $form_id,
				'intent_id'     => $intent_id,
				'status'        => $status,
				'amount'        => $amount,
				'currency'      => $currency,
				'method'        => $method,
				'created_at'    => $now,
				'updated_at'    => $now,
			]
		);

		if ( false !== $inserted ) {
			return true;
		}

		// Insert failed — almost certainly the UNIQUE intent_id key. Allow
		// re-claiming ONLY when the existing row is an unconsumed orphan.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, submission_id FROM {$table} WHERE intent_id = %s", $intent_id ),
			ARRAY_A
		);

		if ( ! is_array( $existing ) ) {
			return false; // Insert failed for another reason — fail closed.
		}

		return null === $existing['submission_id'];
	}

	/**
	 * Attach the persisted submission to its claimed payment row.
	 *
	 * The guarded UPDATE (submission_id IS NULL) keeps the one-intent-one-
	 * submission invariant even if two requests raced past claim_intent().
	 *
	 * @param string $intent_id     Stripe PaymentIntent ID.
	 * @param int    $submission_id Newly persisted submission row id.
	 * @return void
	 */
	public function attach_submission( string $intent_id, int $submission_id ): void {
		global $wpdb;

		$table = Schema::payments_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET submission_id = %d, updated_at = %s
				 WHERE intent_id = %s AND submission_id IS NULL",
				$submission_id,
				current_time( 'mysql', true ),
				$intent_id
			)
		);
	}

	/**
	 * Update the payment status for an intent (Stripe webhook path).
	 *
	 * @param string $intent_id Stripe PaymentIntent ID.
	 * @param string $status    New status (succeeded, processing, failed, refunded…).
	 * @return bool Whether a row was updated.
	 */
	public function update_status( string $intent_id, string $status ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$updated = $wpdb->update(
			Schema::payments_table_name(),
			[
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'intent_id' => $intent_id ]
		);

		return is_int( $updated ) && $updated > 0;
	}

	/**
	 * Fetch the payment row for a submission, if any.
	 *
	 * @param int $submission_id Submission id.
	 * @return array<string, mixed>|null
	 */
	public function find_for_submission( int $submission_id ): ?array {
		global $wpdb;

		$table = Schema::payments_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE submission_id = %d ORDER BY id DESC LIMIT 1", $submission_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Delete payment rows tied to the given submissions (GDPR cascade).
	 *
	 * @param array<int, int> $submission_ids Submission ids.
	 * @return int Rows deleted.
	 */
	public function delete_for_submissions( array $submission_ids ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $submission_ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$table        = Schema::payments_table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name controlled; placeholders prepared.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE submission_id IN ({$placeholders})", $ids ) );

		return false === $deleted ? 0 : (int) $deleted;
	}
}
