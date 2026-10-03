<?php
/**
 * Native wp-admin connection and status screen.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Admin {
	const NONCE_ACTION = 'spellexo_woocommerce_admin';

	/** @var Connection */
	private $connection;

	/** @var Sync */
	private $sync;

	/** @var Compatibility */
	private $compatibility;

	/** @var string */
	private $hook_suffix = '';

	/**
	 * @param Connection $connection Connection manager.
	 * @param Sync $sync Sync manager.
	 * @param Compatibility $compatibility Compatibility manager.
	 */
	public function __construct( $connection, $sync, $compatibility ) {
		$this->connection    = $connection;
		$this->sync          = $sync;
		$this->compatibility = $compatibility;
	}

	/**
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_spellexo_woocommerce_begin_pairing', array( $this, 'begin_pairing' ) );
		add_action( 'wp_ajax_spellexo_woocommerce_poll_pairing', array( $this, 'poll_pairing' ) );
		add_action( 'wp_ajax_spellexo_woocommerce_queue_sync', array( $this, 'queue_sync' ) );
		add_action( 'wp_ajax_spellexo_woocommerce_disconnect', array( $this, 'disconnect' ) );
		add_action( 'admin_notices', array( $this, 'activation_notice' ) );
	}

	/**
	 * @return void
	 */
	public function register_menu() {
		$parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'options-general.php';

		$this->hook_suffix = add_submenu_page(
			$parent,
			__( 'Spellexo', 'spellexo-for-woocommerce' ),
			__( 'Spellexo', 'spellexo-for-woocommerce' ),
			'manage_woocommerce',
			'spellexo-for-woocommerce',
			array( $this, 'render_page' )
		);
	}

	/**
	 * @param string $hook_suffix Current admin hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( $this->hook_suffix !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'spellexo-woocommerce-admin', SPELLEXO_WC_PLUGIN_URL . 'assets/css/admin.css', array(), SPELLEXO_WC_VERSION );
		wp_enqueue_script( 'spellexo-woocommerce-admin', SPELLEXO_WC_PLUGIN_URL . 'assets/js/admin-connection.js', array(), SPELLEXO_WC_VERSION, true );

		$connection = Options::connection();
		wp_localize_script(
			'spellexo-woocommerce-admin',
			'SpellexoWooCommerceAdmin',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( self::NONCE_ACTION ),
				'pairingActive' => ! empty( $connection['pairing_in_progress'] ) && (int) $connection['pairing_expires_at'] > time(),
				'labels'        => array(
					'working'           => __( 'Working…', 'spellexo-for-woocommerce' ),
					'error'             => __( 'The request could not be completed. Check the connection status and try again.', 'spellexo-for-woocommerce' ),
					'disconnectConfirm' => __( 'Disconnect this WooCommerce store from Spellexo? The hosted account, models, and billing will remain intact.', 'spellexo-for-woocommerce' ),
					'diagnosticsCopied' => __( 'Sanitized diagnostics copied.', 'spellexo-for-woocommerce' ),
					'diagnosticsCopyFallback' => __( 'Clipboard access is unavailable. Select and copy the sanitized diagnostics below.', 'spellexo-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Renders a local status and diagnostics surface. It contains no iframe and
	 * performs no remote network request while rendering.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this connection.', 'spellexo-for-woocommerce' ) );
		}

		$connection = Options::connection();
		$checks     = $this->compatibility->checks();
		$connected  = $this->connection->is_connected();
		$can_connect = $this->compatibility->can_connect();
		$diagnostics = $this->diagnostics( $connection, $checks );

		?>
		<div class="wrap spellexo-woocommerce-admin">
			<h1><?php echo esc_html__( 'Spellexo for WooCommerce', 'spellexo-for-woocommerce' ); ?></h1>
			<p><?php echo esc_html__( 'This page manages the local connection and diagnostics only. Models, activation, plans, billing, and analytics are managed in the hosted Spellexo dashboard.', 'spellexo-for-woocommerce' ); ?></p>

			<div class="spellexo-woocommerce-status-card">
				<h2><?php echo esc_html__( 'Connection status', 'spellexo-for-woocommerce' ); ?></h2>
				<p><strong><?php echo esc_html( $this->status_label( ! empty( $connection['pairing_in_progress'] ) ? 'pairing' : $connection['status'] ) ); ?></strong></p>
				<?php if ( '' !== $connection['last_error_code'] ) : ?>
					<?php /* translators: %s: non-sensitive connector diagnostic code. */ ?>
					<p class="description"><?php echo esc_html( sprintf( __( 'Latest safe diagnostic code: %s', 'spellexo-for-woocommerce' ), $connection['last_error_code'] ) ); ?></p>
				<?php endif; ?>

				<div class="spellexo-woocommerce-actions">
					<?php if ( ! $connected ) : ?>
						<button type="button" class="button button-primary" data-spellexo-connect <?php disabled( ! $can_connect ); ?>><?php echo esc_html__( 'Connect to Spellexo', 'spellexo-for-woocommerce' ); ?></button>
					<?php endif; ?>
					<?php if ( $connected && '' !== $connection['dashboard_url'] ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'store', $connection['store_id'], $connection['dashboard_url'] ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open Spellexo Dashboard', 'spellexo-for-woocommerce' ); ?></a>
					<?php endif; ?>
					<?php if ( $connected ) : ?>
						<button type="button" class="button" data-spellexo-connect><?php echo esc_html__( 'Reconnect', 'spellexo-for-woocommerce' ); ?></button>
						<button type="button" class="button" data-spellexo-sync><?php echo esc_html__( 'Run synchronization now', 'spellexo-for-woocommerce' ); ?></button>
						<button type="button" class="button-link-delete" data-spellexo-disconnect><?php echo esc_html__( 'Disconnect', 'spellexo-for-woocommerce' ); ?></button>
					<?php endif; ?>
				</div>
				<?php if ( ! $can_connect ) : ?>
					<p class="description"><?php echo esc_html__( 'Connection requires WooCommerce, the supported PHP and WordPress versions, a working encrypted-secret capability, and an HTTPS site URL.', 'spellexo-for-woocommerce' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="spellexo-woocommerce-status-card">
				<h2><?php echo esc_html__( 'Local integration health', 'spellexo-for-woocommerce' ); ?></h2>
				<table class="widefat striped">
					<tbody>
						<?php foreach ( $diagnostics as $label => $value ) : ?>
							<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $value ); ?></td></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<button type="button" class="button" data-spellexo-copy-diagnostics data-spellexo-diagnostics="<?php echo esc_attr( wp_json_encode( $diagnostics ) ); ?>"><?php echo esc_html__( 'Copy sanitized diagnostics', 'spellexo-for-woocommerce' ); ?></button>
				<p data-spellexo-diagnostics-feedback role="status" aria-live="polite"></p>
				<textarea data-spellexo-diagnostics-fallback class="large-text code" rows="12" readonly hidden aria-label="<?php echo esc_attr__( 'Sanitized diagnostics', 'spellexo-for-woocommerce' ); ?>"></textarea>
			</div>

			<div class="spellexo-woocommerce-status-card">
				<h2><?php echo esc_html__( 'Template placement', 'spellexo-for-woocommerce' ); ?></h2>
				<p><?php echo esc_html__( 'Spellexo never adds a button automatically. Place one block, Elementor widget, or shortcode in each applicable WooCommerce Single Product template.', 'spellexo-for-woocommerce' ); ?></p>
				<code>[spellexo_view_in_your_room]</code>
			</div>
		</div>
		<?php
	}

	/**
	 * @return void
	 */
	public function begin_pairing() {
		$this->authorize_ajax();

		try {
			$connection = $this->connection->start_pairing();
			Audit_Log::record( 'pairing_started' );
			wp_send_json_success(
				array(
					'status'       => $connection['status'],
					'dashboard_url'=> $connection['dashboard_url'],
				)
			);
		} catch ( Api_Exception $exception ) {
			$this->connection->record_error( $exception );
			$this->send_safe_error( $exception );
		}
	}

	/**
	 * @return void
	 */
	public function poll_pairing() {
		$this->authorize_ajax();

		try {
			$connection = $this->connection->poll_pairing();
			if ( 'connected' === $connection['status'] && empty( $connection['pairing_in_progress'] ) ) {
				Audit_Log::record( 'pairing_connected' );
				do_action( 'spellexo_woocommerce_connected' );
			}
			wp_send_json_success( array( 'status' => self::pairing_ui_status( $connection ) ) );
		} catch ( Api_Exception $exception ) {
			$this->connection->record_error( $exception );
			$this->send_safe_error( $exception );
		}
	}

	/**
	 * @return void
	 */
	public function queue_sync() {
		$this->authorize_ajax();

		$result = $this->sync->run_manual_catalog_step();
		$status = isset( $result['status'] ) ? (string) $result['status'] : 'failed';
		$code   = isset( $result['code'] ) ? sanitize_key( (string) $result['code'] ) : 'catalog_sync_failed';

		if ( 'failed' === $status ) {
			/* translators: %s: non-sensitive connector diagnostic code. */
			$message = sprintf( __( 'Catalog synchronization stopped. Safe diagnostic code: %s', 'spellexo-for-woocommerce' ), $code );
			wp_send_json_error(
				array(
					'message' => $message,
					'code'    => $code,
				),
				409
			);
		}

		if ( 'completed' === $status ) {
			Audit_Log::record( 'catalog_sync_completed' );
			$result['message'] = __( 'WooCommerce catalog synchronization completed.', 'spellexo-for-woocommerce' );
		} elseif ( 'running' === $status ) {
			if ( 1 === (int) $result['processed_page'] ) {
				Audit_Log::record( 'catalog_sync_started' );
			}
			$result['message'] = sprintf(
				/* translators: 1: accepted parent products, 2: total parent products. */
				__( 'Synchronizing WooCommerce catalog (%1$d of %2$d products)…', 'spellexo-for-woocommerce' ),
				(int) $result['processed_products'],
				(int) $result['total_products']
			);
		} else {
			$result['message'] = __( 'Another catalog page is finishing. Waiting to continue…', 'spellexo-for-woocommerce' );
		}

		wp_send_json_success( $result );
	}

	/**
	 * @return void
	 */
	public function disconnect() {
		$this->authorize_ajax();

		try {
			$this->connection->disconnect();
			Audit_Log::record( 'disconnected' );
			do_action( 'spellexo_woocommerce_disconnected' );
			wp_send_json_success( array( 'status' => 'revoked' ) );
		} catch ( Api_Exception $exception ) {
			$this->connection->record_error( $exception );
			$this->send_safe_error( $exception );
		}
	}

	/**
	 * @return void
	 */
	public function activation_notice() {
		if ( ! get_transient( 'spellexo_woocommerce_activation_notice' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		delete_transient( 'spellexo_woocommerce_activation_notice' );
		echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Spellexo was activated locally. Nothing has been sent to Spellexo; connect the store when you are ready.', 'spellexo-for-woocommerce' ) . '</p></div>';
	}

	/**
	 * @return void
	 */
	private function authorize_ajax() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage this connection.', 'spellexo-for-woocommerce' ) ), 403 );
		}
	}

	/**
	 * @param Api_Exception $exception Safe exception.
	 * @return void
	 */
	private function send_safe_error( $exception ) {
		wp_send_json_error(
			array(
				'message' => __( 'The Spellexo request could not be completed. Check the local status and try again.', 'spellexo-for-woocommerce' ),
				'code'    => $exception->error_code(),
			),
			400
		);
	}

	/**
	 * @param string $status Stored state.
	 * @return string
	 */
	private function status_label( $status ) {
		$labels = array(
			'not_connected' => __( 'Not connected', 'spellexo-for-woocommerce' ),
			'pairing'       => __( 'Waiting for pairing confirmation', 'spellexo-for-woocommerce' ),
			'connected'     => __( 'Connected', 'spellexo-for-woocommerce' ),
			'stale'         => __( 'Stale', 'spellexo-for-woocommerce' ),
			'revoked'       => __( 'Revoked', 'spellexo-for-woocommerce' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'Unknown', 'spellexo-for-woocommerce' );
	}

	/**
	 * Keeps a deliberately reconnecting connection in the polling UI even though
	 * its active final credential remains available for storefront service.
	 *
	 * @param mixed $connection Stored connection state.
	 * @return string
	 */
	public static function pairing_ui_status( $connection ) {
		if ( is_array( $connection ) && ! empty( $connection['pairing_in_progress'] ) ) {
			return 'pairing';
		}

		return is_array( $connection ) && isset( $connection['status'] ) ? sanitize_key( (string) $connection['status'] ) : 'not_connected';
	}

	/**
	 * @param array<string, mixed> $connection Stored connection state.
	 * @param array<string, bool|string> $checks Environment checks.
	 * @return array<string, string>
	 */
	private function diagnostics( $connection, $checks ) {
		$catalog = $this->sync->catalog_status();
		$progress = $catalog['active']
			? sprintf(
				/* translators: 1: accepted parent products, 2: total parent products. */
				__( 'In progress — %1$d of %2$d products accepted', 'spellexo-for-woocommerce' ),
				(int) $catalog['processed_products'],
				(int) $catalog['total_products']
			)
			: __( 'No active catalog run', 'spellexo-for-woocommerce' );
		$background = $catalog['wp_cron_disabled']
			? __( 'WP-Cron disabled; manual synchronization remains available', 'spellexo-for-woocommerce' )
			: ( $catalog['action_scheduler_available'] ? __( 'WooCommerce Action Scheduler available', 'spellexo-for-woocommerce' ) : __( 'Native WP-Cron fallback only', 'spellexo-for-woocommerce' ) );

		return array(
			__( 'Registered site URL', 'spellexo-for-woocommerce' ) => (string) $connection['canonical_site_url'],
			__( 'Last successful catalog sync', 'spellexo-for-woocommerce' ) => $this->formatted_time( (int) Options::get( Options::LAST_SYNC, 0 ) ),
			__( 'Catalog synchronization', 'spellexo-for-woocommerce' ) => $progress,
			__( 'Last catalog attempt', 'spellexo-for-woocommerce' ) => $this->formatted_time( (int) $catalog['last_attempt_at'] ),
			__( 'Background runner', 'spellexo-for-woocommerce' ) => $background,
			__( 'Catalog queue failures', 'spellexo-for-woocommerce' ) => (string) $catalog['queue_failures'],
			__( 'PHP version', 'spellexo-for-woocommerce' ) => (string) $checks['php_version'],
			__( 'WordPress version', 'spellexo-for-woocommerce' ) => (string) $checks['wordpress_version'],
			__( 'WooCommerce version', 'spellexo-for-woocommerce' ) => '' !== $checks['woocommerce_version'] ? (string) $checks['woocommerce_version'] : __( 'Not active', 'spellexo-for-woocommerce' ),
			__( 'HTTPS site URL', 'spellexo-for-woocommerce' ) => $checks['https'] ? __( 'Yes', 'spellexo-for-woocommerce' ) : __( 'No', 'spellexo-for-woocommerce' ),
			__( 'Storefront placement', 'spellexo-for-woocommerce' ) => __( 'Explicit block, Elementor widget, or shortcode only', 'spellexo-for-woocommerce' ),
		);
	}

	/**
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private function formatted_time( $timestamp ) {
		return $timestamp > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : __( 'Never', 'spellexo-for-woocommerce' );
	}
}
