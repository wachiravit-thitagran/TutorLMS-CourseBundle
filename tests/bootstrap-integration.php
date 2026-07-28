<?php
/**
 * Bootstrap for the WordPress integration suite.
 *
 * Loads the real WordPress test library, then Tutor LMS and WooCommerce (when
 * present), then this plugin — in that order, so our `plugins_loaded` hook sees
 * a fully formed environment exactly as it would on a live site.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

$tcb_root = dirname( __DIR__ );

/*
 * Locate the WordPress test library.
 */
$tcb_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $tcb_tests_dir ) {
	$tcb_tmp       = rtrim( sys_get_temp_dir(), '/\\' );
	$tcb_tests_dir = $tcb_tmp . '/wordpress-tests-lib';
}

$tcb_tests_dir = rtrim( $tcb_tests_dir, '/\\' );

if ( ! is_readable( $tcb_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"Could not find the WordPress test library at {$tcb_tests_dir}.\n" .
		"Run: bash bin/install-wp-tests.sh wordpress_test root '' localhost latest\n"
	);
	exit( 1 );
}

if ( is_readable( $tcb_root . '/vendor/autoload.php' ) ) {
	require_once $tcb_root . '/vendor/autoload.php';
}

require_once $tcb_tests_dir . '/includes/functions.php';

/**
 * Absolute path to a plugin's main file inside the shared plugins directory.
 *
 * @param string $relative Relative path, e.g. "tutor/tutor.php".
 */
function tcb_test_plugin_path( string $relative ): string {
	$dir = getenv( 'WP_PLUGIN_DIR' );

	if ( ! $dir ) {
		$dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress/wp-content/plugins';
	}

	return rtrim( $dir, '/\\' ) . '/' . ltrim( $relative, '/' );
}

$tcb_tutor_path = tcb_test_plugin_path( 'tutor/tutor.php' );
$tcb_wc_path    = tcb_test_plugin_path( 'woocommerce/woocommerce.php' );

define( 'TCB_TESTS_HAS_TUTOR', is_readable( $tcb_tutor_path ) );
define( 'TCB_TESTS_HAS_WOOCOMMERCE', is_readable( $tcb_wc_path ) );

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $tcb_root, $tcb_tutor_path, $tcb_wc_path ): void {
		// WooCommerce first: Tutor checks for it during its own boot.
		if ( TCB_TESTS_HAS_WOOCOMMERCE ) {
			require_once $tcb_wc_path;
		}

		if ( TCB_TESTS_HAS_TUTOR ) {
			require_once $tcb_tutor_path;
		}

		require_once $tcb_root . '/tutor-course-bundles.php';
	}
);

/*
 * WooCommerce needs its schema installed before any test touches an order.
 * `WC_Install::install()` is idempotent, so running it here is safe.
 */
tests_add_filter(
	'setup_theme',
	static function (): void {
		if ( TCB_TESTS_HAS_TUTOR && class_exists( '\TUTOR\Tutor' ) ) {
			global $wpdb;
			$previous_suppress_errors = $wpdb->suppress_errors( true );
			\TUTOR\Tutor::tutor_activate();
			$wpdb->suppress_errors( $previous_suppress_errors );
		}

		if ( ! TCB_TESTS_HAS_WOOCOMMERCE || ! class_exists( 'WC_Install' ) ) {
			return;
		}

		// Silence WooCommerce's onboarding redirects and notices during install.
		update_option( 'woocommerce_db_version', WC()->version ?? '9.0.0' );
		remove_all_filters( 'woocommerce_enable_setup_wizard' );

		WC_Install::install();

		$roles = new WP_Roles();
		$roles->for_site();
	}
);

require $tcb_tests_dir . '/includes/bootstrap.php';

/*
 * Install our schema and capabilities once, outside the per-test transaction,
 * so every test starts from a fully migrated database.
 */
\SpaceWork\TutorCourseBundles\Support\Migration::install();
\SpaceWork\TutorCourseBundles\Support\Capabilities::add_roles_capabilities();

require_once __DIR__ . '/Support/TestCase.php';
require_once __DIR__ . '/Support/Seeder.php';
require_once __DIR__ . '/Support/Scenarios.php';

printf(
	"\nEnvironment: PHP %s | WP %s | Tutor LMS %s | WooCommerce %s\n\n",
	PHP_VERSION,
	get_bloginfo( 'version' ),
	TCB_TESTS_HAS_TUTOR ? ( defined( 'TUTOR_VERSION' ) ? TUTOR_VERSION : 'loaded' ) : 'absent',
	TCB_TESTS_HAS_WOOCOMMERCE ? ( defined( 'WC_VERSION' ) ? WC_VERSION : 'loaded' ) : 'absent'
);
