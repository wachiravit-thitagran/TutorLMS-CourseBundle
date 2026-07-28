<?php
/**
 * Activation / deactivation routines.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles;

use SpaceWork\TutorCourseBundles\Infrastructure\PostTypes;
use SpaceWork\TutorCourseBundles\Support\Capabilities;
use SpaceWork\TutorCourseBundles\Support\Migration;
use SpaceWork\TutorCourseBundles\Tutor\EnrollmentService;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on activation and deactivation only. Deliberately does not delete data —
 * see uninstall.php for the destructive path.
 */
final class Activation {

	/**
	 * Activation handler.
	 */
	public static function activate(): void {
		if ( version_compare( PHP_VERSION, Compatibility::MIN_PHP, '<' ) ) {
			deactivate_plugins( TCB_BASENAME );
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: required PHP version */
						__( 'Tutor Course Bundles requires PHP %s or newer.', 'tutor-course-bundles' ),
						Compatibility::MIN_PHP
					)
				)
			);
		}

		Migration::install();
		Capabilities::add_roles_capabilities();

		// Register the post type so rewrite rules can be generated immediately.
		( new PostTypes() )->register_post_type();
		( new PostTypes() )->register_taxonomies();
		flush_rewrite_rules();

		add_option( 'tcb_activated_at', time(), '', false );

		do_action( 'tcb/activated' );
	}

	/**
	 * Deactivation handler.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();

		wp_clear_scheduled_hook( EnrollmentService::HOOK_EXPIRE_ACCESS );
		wp_clear_scheduled_hook( EnrollmentService::HOOK_PROCESS_BATCH );
		wp_clear_scheduled_hook( EnrollmentService::HOOK_SYNC_NEW_COURSE );
		wp_clear_scheduled_hook( EnrollmentService::HOOK_REMOVE_COURSE );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( EnrollmentService::HOOK_PROCESS_BATCH, EnrollmentService::HOOK_SYNC_NEW_COURSE, EnrollmentService::HOOK_REMOVE_COURSE ) as $hook ) {
				as_unschedule_all_actions( $hook );
			}
		}

		do_action( 'tcb/deactivated' );
	}
}
