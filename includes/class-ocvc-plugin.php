<?php
/**
 * Main plugin loader (singleton).
 *
 * Wires the moving parts together. Each concern lives in its own class so the
 * plugin stays easy to maintain and extend.
 *
 * @package OC_ValueCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCVC_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var OCVC_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return OCVC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor: load translations and boot components.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Admin settings (always available).
		OCVC_Settings::init();

		// Front-end / order components are loaded on the next build slice:
		//   - OCVC_Member   (identity + pull points on login/checkout)
		//   - OCVC_Checkout (GetBenefitsQuery + apply discount)
		//   - OCVC_Order    (commit on configured status + void on cancel/refund + enrol)
		$this->load_components();
	}

	/**
	 * Load optional components if their files are present.
	 *
	 * @return void
	 */
	private function load_components() {
		$components = array(
			'includes/class-ocvc-member.php'   => 'OCVC_Member',
			'includes/class-ocvc-checkout.php' => 'OCVC_Checkout',
			'includes/class-ocvc-order.php'    => 'OCVC_Order',
		);

		foreach ( $components as $file => $class ) {
			$path = OCVC_PLUGIN_DIR . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
				if ( class_exists( $class ) && method_exists( $class, 'init' ) ) {
					$class::init();
				}
			}
		}
	}

	/**
	 * Load the plugin text domain.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'oc-valuecard', false, dirname( OCVC_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Convenience accessor for an API client bound to saved credentials.
	 *
	 * @return OCVC_API
	 */
	public static function api() {
		return new OCVC_API();
	}
}
