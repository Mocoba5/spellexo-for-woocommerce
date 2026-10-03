<?php
/**
 * Main plugin composition root.
 *
 * @package Spellexo_For_WooCommerce
 */

namespace Spellexo\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	/** @var Plugin|null */
	private static $instance = null;

	/** @var string */
	private $plugin_file;

	/** @var Compatibility */
	private $compatibility;

	/** @var Connection */
	private $connection;

	/** @var Sync */
	private $sync;

	/** @var Heartbeat */
	private $heartbeat;

	/** @var Commerce_Analytics */
	private $commerce_analytics;

	/** @var Mount_Renderer */
	private $mount_renderer;

	/**
	 * @param string $plugin_file Main plugin file.
	 */
	private function __construct( $plugin_file ) {
		$this->plugin_file    = $plugin_file;
		$this->compatibility  = new Compatibility();
		$this->connection     = new Connection();
		$this->sync           = new Sync( $this->connection );
		$this->heartbeat      = new Heartbeat( $this->connection, null, $this->sync );
		$this->commerce_analytics = new Commerce_Analytics( $this->connection );
		$this->mount_renderer = new Mount_Renderer( $this->connection );
	}

	/**
	 * @param string $plugin_file Main plugin file.
	 * @return Plugin
	 */
	public static function boot( $plugin_file ) {
		if ( null === self::$instance ) {
			self::$instance = new self( $plugin_file );
			self::$instance->register();
		}

		return self::$instance;
	}

	/**
	 * @return Plugin
	 */
	public static function instance() {
		return self::$instance;
	}

	/**
	 * @return Mount_Renderer
	 */
	public function mount_renderer() {
		return $this->mount_renderer;
	}

	/**
	 * @return void
	 */
	private function register() {
		add_action( 'plugins_loaded', array( $this, 'initialize' ), 20 );
	}

	/**
	 * Initializes native status/privacy functionality first, then Woo-dependent
	 * storefront and synchronization features only in a supported environment.
	 *
	 * @return void
	 */
	public function initialize() {
		$admin = new Admin( $this->connection, $this->sync, $this->compatibility );
		$admin->register();
		( new Privacy() )->register();
		( new Site_Health( $this->connection ) )->register();

		if ( ! $this->compatibility->is_supported() ) {
			add_action( 'admin_notices', array( $this->compatibility, 'render_admin_notice' ) );
			return;
		}

		$this->sync->register();
		$this->heartbeat->register();
		$this->commerce_analytics->register();
		$this->mount_renderer->register();
		( new Shortcode( $this->mount_renderer ) )->register();
		( new Blocks() )->register();

		$elementor_file = SPELLEXO_WC_PLUGIN_DIR . 'integrations/elementor/class-elementor-integration.php';
		if ( file_exists( $elementor_file ) ) {
			require_once $elementor_file;
			( new \Spellexo\WooCommerce\Elementor\Integration( $this->mount_renderer ) )->register();
		}
	}
}
