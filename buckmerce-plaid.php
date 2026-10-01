<?php
/**
 * Plugin Name: Buckmerce – Bank Payments via Plaid for WooCommerce
 * Description: Secure Pay by Bank payments for WooCommerce using Plaid.
 * Version: 1.0.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Author: al5dy
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: buckmerce-plaid
 * Domain Path: /languages
 * WC requires at least: 8.7
 * WC tested up to: 11.1
 *
 * @package Buckmerce\Plaid
 */

defined( 'ABSPATH' ) || exit;

define( 'BUCKMERCE_PLAID_VERSION', '1.0.0' );
define( 'BUCKMERCE_PLAID_FILE', __FILE__ );
define( 'BUCKMERCE_PLAID_DIR', plugin_dir_path( __FILE__ ) );
define( 'BUCKMERCE_PLAID_URL', plugin_dir_url( __FILE__ ) );

// Runtime dependencies are namespace-prefixed (Buckmerce\Plaid\Vendor\...) so
// they cannot collide with other plugins that bundle the same libraries.
if ( is_readable( BUCKMERCE_PLAID_DIR . 'vendor-prefixed/autoload.php' ) ) {
	require_once BUCKMERCE_PLAID_DIR . 'vendor-prefixed/autoload.php';
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Buckmerce\\Plaid\\';
		if ( 0 !== strpos( $class, $prefix ) || 0 === strpos( $class, $prefix . 'Vendor\\' ) ) {
			return;
		}
		$file = BUCKMERCE_PLAID_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BUCKMERCE_PLAID_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BUCKMERCE_PLAID_FILE, true );
		}
	}
);

register_activation_hook( __FILE__, array( '\\Buckmerce\\Plaid\\Persistence\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\Buckmerce\\Plaid\\Background\\Scheduler', 'unschedule_all' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		\Buckmerce\Plaid\Bootstrap::boot();
	},
	20
);
