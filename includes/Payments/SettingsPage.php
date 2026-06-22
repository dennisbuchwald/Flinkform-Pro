<?php
/**
 * Stripe settings page in the WordPress admin.
 *
 * Docks under the Flinkform admin menu. Stores the publishable key in
 * plain text (it's public) and the secret key AES-256 encrypted.
 *
 * @package FlinkformPro
 * @since 1.1.0
 */

declare( strict_types = 1 );

namespace FlinkformPro\Payments;

use FlinkformPro\Settings\Secret;

defined( 'ABSPATH' ) || exit;

/**
 * Stripe settings admin page.
 */
final class SettingsPage {

	private const OPTION_KEY = 'flinkform_stripe_settings';

	/**
	 * Register the WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_submenu_page' ], 30 );
		add_action( 'admin_init', [ $this, 'save_settings' ] );
	}

	/**
	 * Add the submenu page under the Flinkform top-level menu.
	 *
	 * @return void
	 */
	public function add_submenu_page(): void {
		if ( ! class_exists( '\\Flinkform\\Admin\\Menu' ) ) {
			return;
		}

		add_submenu_page(
			'flinkform',
			__( 'Stripe Payments', 'flinkform-pro' ),
			__( 'Stripe Payments', 'flinkform-pro' ),
			'manage_options',
			'flinkform-stripe',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Save the settings on POST.
	 *
	 * @return void
	 */
	public function save_settings(): void {
		if (
			! isset( $_POST['flinkform_stripe_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['flinkform_stripe_nonce'] ) ), 'flinkform_stripe_save' )
			|| ! current_user_can( 'manage_options' )
		) {
			return;
		}

		$current  = get_option( self::OPTION_KEY, [] );
		$settings = is_array( $current ) ? $current : [];

		$settings['mode'] = ( $_POST['flinkform_stripe_mode'] ?? 'test' ) === 'live' ? 'live' : 'test';

		// Publishable key — stored in plain text (it's public).
		$pub_key = sanitize_text_field( wp_unslash( (string) ( $_POST['flinkform_stripe_publishable_key'] ?? '' ) ) );
		if ( '' !== $pub_key ) {
			$settings['publishable_key'] = $pub_key;
		}

		// Secret key — stored encrypted. Empty means "keep current".
		$secret_raw = sanitize_text_field( wp_unslash( (string) ( $_POST['flinkform_stripe_secret_key'] ?? '' ) ) );
		if ( '' !== $secret_raw ) {
			$settings['secret_key'] = Secret::encrypt( $secret_raw );
		}

		// Currency default.
		$currency = strtolower( sanitize_text_field( wp_unslash( (string) ( $_POST['flinkform_stripe_currency'] ?? 'eur' ) ) ) );
		$settings['currency'] = preg_match( '/^[a-z]{3}$/', $currency ) ? $currency : 'eur';

		update_option( self::OPTION_KEY, $settings, false );

		add_settings_error( 'flinkform_stripe', 'saved', __( 'Stripe settings saved.', 'flinkform-pro' ), 'success' );
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		$mode           = (string) ( $settings['mode'] ?? 'test' );
		$publishable    = (string) ( $settings['publishable_key'] ?? '' );
		$has_secret_key = isset( $settings['secret_key'] ) && '' !== $settings['secret_key'];
		$currency       = (string) ( $settings['currency'] ?? 'eur' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Stripe Payments', 'flinkform-pro' ); ?></h1>
			<?php settings_errors( 'flinkform_stripe' ); ?>
			<form method="post" action="">
				<?php wp_nonce_field( 'flinkform_stripe_save', 'flinkform_stripe_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Mode', 'flinkform-pro' ); ?></th>
						<td>
							<select name="flinkform_stripe_mode">
								<option value="test" <?php selected( $mode, 'test' ); ?>><?php esc_html_e( 'Test', 'flinkform-pro' ); ?></option>
								<option value="live" <?php selected( $mode, 'live' ); ?>><?php esc_html_e( 'Live', 'flinkform-pro' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Test mode uses Stripe test keys. No real payments are processed.', 'flinkform-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="flinkform_stripe_publishable_key"><?php esc_html_e( 'Publishable Key', 'flinkform-pro' ); ?></label></th>
						<td>
							<input type="text" name="flinkform_stripe_publishable_key" id="flinkform_stripe_publishable_key" class="regular-text" value="<?php echo esc_attr( $publishable ); ?>" placeholder="pk_test_..." autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Starts with pk_test_ or pk_live_. Found in the Stripe Dashboard under Developers > API keys.', 'flinkform-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="flinkform_stripe_secret_key"><?php esc_html_e( 'Secret Key', 'flinkform-pro' ); ?></label></th>
						<td>
							<input type="password" name="flinkform_stripe_secret_key" id="flinkform_stripe_secret_key" class="regular-text" value="" placeholder="<?php echo $has_secret_key ? esc_attr__( 'Saved (leave empty to keep)', 'flinkform-pro' ) : 'sk_test_...'; ?>" autocomplete="new-password" />
							<p class="description"><?php esc_html_e( 'Starts with sk_test_ or sk_live_. Stored AES-256 encrypted. Never committed to version control.', 'flinkform-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="flinkform_stripe_currency"><?php esc_html_e( 'Default Currency', 'flinkform-pro' ); ?></label></th>
						<td>
							<select name="flinkform_stripe_currency" id="flinkform_stripe_currency">
								<option value="eur" <?php selected( $currency, 'eur' ); ?>>EUR</option>
								<option value="usd" <?php selected( $currency, 'usd' ); ?>>USD</option>
								<option value="gbp" <?php selected( $currency, 'gbp' ); ?>>GBP</option>
								<option value="chf" <?php selected( $currency, 'chf' ); ?>>CHF</option>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Stripe Settings', 'flinkform-pro' ) ); ?>
			</form>
		</div>
		<?php
	}
}
