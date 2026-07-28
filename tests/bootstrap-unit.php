<?php
/**
 * Bootstrap for the isolated unit suite.
 *
 * No WordPress, no database. Brain Monkey stands in for the WordPress function
 * layer so the domain objects can be exercised in microseconds.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

$tcb_root = dirname( __DIR__ );

if ( ! is_readable( $tcb_root . '/vendor/autoload.php' ) ) {
	fwrite( STDERR, "Run `composer install` before the unit suite.\n" );
	exit( 1 );
}

require_once $tcb_root . '/vendor/autoload.php';

// Constants the plugin normally defines in its main file.
define( 'ABSPATH', $tcb_root . '/tests/stubs/wordpress/' );
define( 'TCB_VERSION', '1.0.0' );
define( 'TCB_DB_VERSION', '1' );
define( 'TCB_FILE', $tcb_root . '/tutor-course-bundles.php' );
define( 'TCB_PATH', $tcb_root . '/' );
define( 'TCB_URL', 'https://example.test/wp-content/plugins/tutor-course-bundles/' );
define( 'TCB_BASENAME', 'tutor-course-bundles/tutor-course-bundles.php' );

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

// The plugin's own PSR-4 loader, so src/ is exercised exactly as it is shipped
// rather than through Composer's optimised map.
spl_autoload_register(
	static function ( string $class ) use ( $tcb_root ): void {
		$prefix = 'SpaceWork\\TutorCourseBundles\\';

		if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );

		if ( str_starts_with( $relative, 'Tests\\' ) ) {
			return; // Handled by composer autoload-dev.
		}

		$path = $tcb_root . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

require_once __DIR__ . '/stubs/wp-classes.php';
require_once __DIR__ . '/stubs/tutor-classes.php';
