<?php
/**
 * Plugin Name:       Tutor Course Bundles
 * Plugin URI:        https://github.com/wachiravit/TutorLMS-CourseBundle
 * Description:       Group multiple Tutor LMS courses into a single purchasable bundle with a proper access ledger, WooCommerce support and aggregated progress.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            SpaceWork
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tutor-course-bundles
 * Domain Path:       /languages
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

define( 'TCB_VERSION', '1.0.0' );
define( 'TCB_DB_VERSION', '1' );
define( 'TCB_FILE', __FILE__ );
define( 'TCB_PATH', plugin_dir_path( __FILE__ ) );
define( 'TCB_URL', plugin_dir_url( __FILE__ ) );
define( 'TCB_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Minimal PSR-4 autoloader.
 *
 * Composer is optional; the plugin ships a hand-rolled loader so it can be
 * installed straight from a ZIP without a vendor directory.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'SpaceWork\\TutorCourseBundles\\';
		$length = strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class_name, $length ) ) {
			return;
		}

		$relative = substr( $class_name, $length );
		$path     = TCB_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

if ( is_readable( TCB_PATH . 'vendor/autoload.php' ) ) {
	require_once TCB_PATH . 'vendor/autoload.php';
}

require_once TCB_PATH . 'src/functions.php';

register_activation_hook( __FILE__, array( \SpaceWork\TutorCourseBundles\Activation::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \SpaceWork\TutorCourseBundles\Activation::class, 'deactivate' ) );

/**
 * Plugin container accessor.
 *
 * @return \SpaceWork\TutorCourseBundles\Plugin
 */
function tcb(): \SpaceWork\TutorCourseBundles\Plugin {
	return \SpaceWork\TutorCourseBundles\Plugin::instance();
}

add_action(
	'plugins_loaded',
	static function (): void {
		tcb()->boot();
	},
	20
);
