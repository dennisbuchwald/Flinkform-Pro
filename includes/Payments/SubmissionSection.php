<?php
/**
 * Payment details on the submission detail screen (Flinkform Pro).
 *
 * Hooks the free core's `flinkform_submission_detail_after` seam (same
 * pattern as the webhook deliveries section) and renders amount, currency,
 * method and live status for the submission's payment — replacing the bare
 * pi_... string as the only visible trace of a completed payment.
 *
 * @package FlinkformPro
 * @since 1.2.0
 */

declare( strict_types = 1 );

namespace FlinkformPro\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the payment section on the submission detail page.
 */
final class SubmissionSection {

	/**
	 * Register the WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'flinkform_submission_detail_after', [ $this, 'render_section' ], 5 );
	}

	/**
	 * Render the payment details for a submission, when one exists.
	 *
	 * @param int $submission_id Submission id passed by the core seam.
	 * @return void
	 */
	public function render_section( $submission_id ): void {
		$payment = ( new PaymentRepository() )->find_for_submission( (int) $submission_id );
		if ( null === $payment ) {
			return;
		}

		$status   = (string) ( $payment['status'] ?? '' );
		$amount   = (int) ( $payment['amount'] ?? 0 );
		$currency = strtoupper( (string) ( $payment['currency'] ?? '' ) );
		$method   = (string) ( $payment['method'] ?? '' );
		$intent   = (string) ( $payment['intent_id'] ?? '' );

		$status_labels = [
			'succeeded'  => __( 'Paid', 'flinkform-pro' ),
			'processing' => __( 'Processing (e.g. SEPA — settles in a few days)', 'flinkform-pro' ),
			'failed'     => __( 'Failed', 'flinkform-pro' ),
			'canceled'   => __( 'Canceled', 'flinkform-pro' ),
			'refunded'   => __( 'Refunded', 'flinkform-pro' ),
		];
		$status_colors = [
			'succeeded'  => '#00a32a',
			'processing' => '#dba617',
			'failed'     => '#d63638',
			'canceled'   => '#646970',
			'refunded'   => '#72aee6',
		];

		$method_labels = [
			'card'       => __( 'Card', 'flinkform-pro' ),
			'sepa_debit' => __( 'SEPA Direct Debit', 'flinkform-pro' ),
			'link'       => 'Link',
		];

		$ts = strtotime( ( $payment['updated_at'] ?? '' ) . ' UTC' );
		?>
		<h2 style="margin-top:32px;"><?php esc_html_e( 'Payment', 'flinkform-pro' ); ?></h2>
		<table class="widefat striped" style="max-width:720px;">
			<tbody>
				<tr>
					<th style="width:180px;"><?php esc_html_e( 'Status', 'flinkform-pro' ); ?></th>
					<td>
						<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:<?php echo esc_attr( $status_colors[ $status ] ?? '#646970' ); ?>;color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:0.04em;">
							<?php echo esc_html( $status ); ?>
						</span>
						<?php if ( isset( $status_labels[ $status ] ) ) : ?>
							<span style="margin-left:8px;opacity:0.75;"><?php echo esc_html( $status_labels[ $status ] ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Amount', 'flinkform-pro' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $amount / 100, 2 ) . ' ' . $currency ); ?></td>
				</tr>
				<?php if ( '' !== $method ) : ?>
					<tr>
						<th><?php esc_html_e( 'Method', 'flinkform-pro' ); ?></th>
						<td><?php echo esc_html( $method_labels[ $method ] ?? $method ); ?></td>
					</tr>
				<?php endif; ?>
				<tr>
					<th><?php esc_html_e( 'Stripe PaymentIntent', 'flinkform-pro' ); ?></th>
					<td><code><?php echo esc_html( $intent ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Last update', 'flinkform-pro' ); ?></th>
					<td><?php echo $ts ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) ) : '—'; ?></td>
				</tr>
			</tbody>
		</table>
		<?php
	}
}
