<?php
/**
 * Plugin Name:       OC ValueCard
 * Plugin URI:        https://originalconcepts.co.il/
 * Description:        Plug-and-play ValueCard loyalty integration for WooCommerce — pulls member points, redeems them at checkout, and reports usage back to ValueCard. Credentials and behaviour are fully configurable per site.
 * Version:           0.3.1
 * Author:            Original Concepts
 * Author URI:        https://originalconcepts.co.il/
 * Text Domain:       oc-valuecard
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 6.0
 * License:           GPL-2.0-or-later
 *
 * @package OC_ValueCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OCVC_VERSION', '0.3.1' );
define( 'OCVC_PLUGIN_FILE', __FILE__ );
define( 'OCVC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OCVC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OCVC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 * This is what the legacy plugin got wrong — it used get_post_meta() on orders,
 * which breaks once a store moves to custom order tables.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', OCVC_PLUGIN_FILE, true );
		}
	}
);

/**
 * Bootstrap the plugin once all plugins are loaded, so we can reliably
 * check whether WooCommerce is present.
 */
add_action( 'plugins_loaded', 'ocvc_bootstrap', 20 );

/**
 * Load the plugin, or show an admin notice if WooCommerce is missing.
 *
 * @return void
 */
function ocvc_bootstrap() {
	// Auto-updates run regardless of WooCommerce so the plugin can always self-update.
	require_once OCVC_PLUGIN_DIR . 'includes/class-ocvc-updater.php';
	OCVC_Updater::init();

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'OC ValueCard requires WooCommerce to be installed and active.', 'oc-valuecard' );
				echo '</p></div>';
			}
		);
		return;
	}

	require_once OCVC_PLUGIN_DIR . 'includes/class-ocvc-logger.php';
	require_once OCVC_PLUGIN_DIR . 'includes/class-ocvc-settings.php';
	require_once OCVC_PLUGIN_DIR . 'includes/class-ocvc-api.php';
	require_once OCVC_PLUGIN_DIR . 'includes/class-ocvc-plugin.php';

	OCVC_Plugin::instance();
}

/**
 * Activation: make sure the protected log directory exists.
 *
 * @return void
 */
register_activation_hook(
	__FILE__,
	function () {
		require_once OCVC_PLUGIN_DIR . 'includes/class-ocvc-logger.php';
		OCVC_Logger::install();
	}
);
