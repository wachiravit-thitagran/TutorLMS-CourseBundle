<?php
/**
 * Uninstall routine.
 *
 * Data is only destroyed when the site owner explicitly opts in via the
 * `tcb_delete_data_on_uninstall` option. Courses, Tutor enrollments and
 * WooCommerce orders are never touched here — removing this plugin must not
 * damage the LMS underneath it.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( 'yes' !== get_option( 'tcb_delete_data_on_uninstall', 'no' ) ) {
	return;
}

require_once __DIR__ . '/src/Support/Migration.php';
require_once __DIR__ . '/src/Support/Capabilities.php';

global $wpdb;

// Uninstall must remove uncached persistent data directly.
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery

// 1. Remove bundle posts and their meta.
$bundle_ids = $wpdb->get_col(
	$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'tcb_bundle' )
);

foreach ( (array) $bundle_ids as $bundle_id ) {
	wp_delete_post( (int) $bundle_id, true );
}

// 2. Remove the provenance meta written on Tutor enrollment records, so the
// enrollments themselves survive but no longer point at deleted rows.
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_tcb_origin_access_id' ), array( '%s' ) );
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_tcb_origin_bundle_id' ), array( '%s' ) );

// 3. Remove completion markers.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( '_tcb_bundle_completed_' ) . '%'
	)
);

// 4. Drop the custom tables.
\SpaceWork\TutorCourseBundles\Support\Migration::drop_tables();

// 5. Strip capabilities.
\SpaceWork\TutorCourseBundles\Support\Capabilities::remove_roles_capabilities();

// 6. Delete options.
$options = array(
	'tcb_db_version',
	'tcb_activated_at',
	'tcb_bundle_slug',
	'tcb_bundle_archive_slug',
	'tcb_progress_mode',
	'tcb_wc_auto_product',
	'tcb_wc_hide_from_catalog',
	'tcb_wc_direct_checkout',
	'tcb_prevent_repurchase',
	'tcb_revoke_on_course_removal',
	'tcb_instructor_any_course',
	'tcb_currency_symbol',
	'tcb_delete_data_on_uninstall',
	'tcb_cache_gen_tcb_bundle',
	'tcb_cache_gen_tcb_progress',
	'tcb_cache_gen_tcb_access',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// 7. Clear any leftover advisory locks.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'tcb_lock_' ) . '%'
	)
);

wp_clear_scheduled_hook( 'tcb/cron/expire_access' );
wp_clear_scheduled_hook( 'tcb/job/process_enrollment_batch' );
wp_clear_scheduled_hook( 'tcb/job/sync_new_course' );
wp_clear_scheduled_hook( 'tcb/job/remove_course' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( 'tcb/job/process_enrollment_batch', 'tcb/job/sync_new_course', 'tcb/job/remove_course' ) as $hook ) {
		as_unschedule_all_actions( $hook );
	}
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
