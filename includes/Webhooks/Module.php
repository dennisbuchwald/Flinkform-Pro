<?php
/**
 * Webhooks module wiring (Flinkform Pro).
 *
 * Boots the whole webhook subsystem now that it lives in Pro (slice M-c-d-2):
 * the REST CRUD controller, the cron dispatcher, the submission listener that
 * enqueues deliveries, the Webhook Log admin page, and the deliveries section
 * on the submission detail screen. Also runs the Pro schema auto-migrate and
 * self-heals the dispatcher cron on the FTP-update path.
 *
 * Mirrors the wiring the free core's Plugin::init() used to do before webhooks
 * became a Pro feature.
 *
 * @package FlinkformPro
 * @since 0.2.5
 */

declare( strict_types = 1 );

namespace FlinkformPro\Webhooks;

use Flinkform\Admin\Menu;
use Flinkform\Submissions\Repository as SubmissionsRepository;
use FlinkformPro\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the Pro webhook subsystem.
 */
final class Module {

	/**
	 * Cron hook that prunes the delivery log daily.
	 */
	public const PRUNE_CRON_HOOK = 'flinkform_prune_webhook_deliveries';

	/**
	 * Default retention window for the delivery log, in days.
	 */
	private const DEFAULT_RETENTION_DAYS = 90;

	/**
	 * Register everything.
	 *
	 * @return void
	 */
	public function register(): void {
		// Create/upgrade the webhook tables when behind — covers the
		// file-only update path where the activation hook never fires.
		Schema::maybe_upgrade();

		$webhooks   = new Repository();
		$deliveries = new DeliveryRepository();
		$deliverer  = new Deliverer( new SubmissionsRepository() );

		// Runtime: REST CRUD, the cron dispatcher, and the listener that
		// bridges flinkform_after_submission into the delivery queue.
		( new RestController( $webhooks, $deliverer ) )->register();
		( new Dispatcher( $webhooks, $deliveries, $deliverer ) )->register();
		( new SubmissionListener( $webhooks, $deliveries ) )->register();

		// Self-heal the every-minute schedule when Pro was updated by file
		// upload (activation hook never ran). Idempotent — one option read.
		if ( ! wp_next_scheduled( Dispatcher::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, Dispatcher::CRON_SCHEDULE, Dispatcher::CRON_HOOK );
		}

		// Daily delivery-log pruning. Self-heals on the file-only update path
		// the same way the dispatcher schedule does.
		add_action( self::PRUNE_CRON_HOOK, [ $this, 'prune_delivery_log' ] );
		if ( ! wp_next_scheduled( self::PRUNE_CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_CRON_HOOK );
		}

		// Admin: Webhook Log submenu (read-only) + the deliveries section on
		// the submission detail screen + the Resend handler.
		add_action( 'admin_menu', [ $this, 'register_log_page' ], 20 );
		( new SubmissionDetail() )->register();
	}

	/**
	 * Daily cron callback: prune delivery-log rows past the retention window.
	 *
	 * @return void
	 */
	public function prune_delivery_log(): void {
		/**
		 * Filter the webhook delivery-log retention window (in days).
		 *
		 * @param int $days Retention window. Default 90.
		 */
		$days = (int) apply_filters( 'flinkform_webhook_log_retention_days', self::DEFAULT_RETENTION_DAYS );

		( new DeliveryRepository() )->prune_expired( $days );
	}

	/**
	 * Add the Webhook Log submenu under the Flinkform menu.
	 *
	 * Priority 20: after the free core's Menu::register_pages() (priority 10)
	 * so the parent Flinkform menu exists.
	 *
	 * @return void
	 */
	public function register_log_page(): void {
		add_submenu_page(
			Menu::PARENT_SLUG,
			__( 'Webhook Log', 'flinkform-pro' ),
			__( 'Webhook Log', 'flinkform-pro' ),
			Menu::CAPABILITY,
			WebhookLogPage::SLUG,
			[ $this, 'render_log_page' ]
		);
	}

	/**
	 * Render the Webhook Log page.
	 *
	 * @return void
	 */
	public function render_log_page(): void {
		( new WebhookLogPage() )->render();
	}
}
