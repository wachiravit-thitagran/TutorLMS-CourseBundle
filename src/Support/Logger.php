<?php
/**
 * Audit log and debug logging.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Two distinct concerns behind one facade.
 *
 * `audit()` writes durable rows describing entitlement changes — this is what
 * you read when a learner says "I lost access". `debug()` is throwaway output
 * that only appears when WP_DEBUG_LOG is on.
 */
final class Logger {

	public const ACTION_ACCESS_GRANTED   = 'access.granted';
	public const ACTION_ACCESS_REVOKED   = 'access.revoked';
	public const ACTION_ACCESS_EXPIRED   = 'access.expired';
	public const ACTION_ACCESS_REFUNDED  = 'access.refunded';
	public const ACTION_COURSE_ENROLLED  = 'course.enrolled';
	public const ACTION_COURSE_REVOKED   = 'course.revoked';
	public const ACTION_COURSE_SKIPPED   = 'course.skipped';
	public const ACTION_ENROLL_FAILED    = 'course.enroll_failed';
	public const ACTION_BUNDLE_COMPLETED = 'bundle.completed';

	/**
	 * Write an audit row.
	 *
	 * @param string               $action    One of the ACTION_* constants (or a custom string).
	 * @param array<string, mixed> $context   Arbitrary structured detail.
	 * @param int                  $bundle_id Bundle post ID.
	 * @param int                  $user_id   Subject user ID.
	 */
	public static function audit( string $action, array $context = array(), int $bundle_id = 0, int $user_id = 0 ): void {
		global $wpdb;

		$table = Migration::table_audit_log();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'bundle_id'  => $bundle_id,
				'user_id'    => $user_id,
				'actor_id'   => get_current_user_id(),
				'action'     => $action,
				'context'    => wp_json_encode( $context ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		/**
		 * Fires whenever an audit entry is recorded.
		 *
		 * @param string               $action    Action key.
		 * @param array<string, mixed> $context   Structured context.
		 * @param int                  $bundle_id Bundle post ID.
		 * @param int                  $user_id   Subject user ID.
		 */
		do_action( 'tcb/audit/logged', $action, $context, $bundle_id, $user_id );
	}

	/**
	 * Read recent audit entries.
	 *
	 * @param array<string, mixed> $args Query args: bundle_id, user_id, action, limit, offset.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_entries( array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'bundle_id' => 0,
			'user_id'   => 0,
			'action'    => '',
			'limit'     => 50,
			'offset'    => 0,
		);

		$args  = wp_parse_args( $args, $defaults );
		$table = Migration::table_audit_log();

		$where  = array( '1=1' );
		$params = array();

		if ( $args['bundle_id'] > 0 ) {
			$where[]  = 'bundle_id = %d';
			$params[] = (int) $args['bundle_id'];
		}

		if ( $args['user_id'] > 0 ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $args['user_id'];
		}

		if ( '' !== $args['action'] ) {
			$where[]  = 'action = %s';
			$params[] = (string) $args['action'];
		}

		$params[] = max( 1, min( 500, (int) $args['limit'] ) );
		$params[] = max( 0, (int) $args['offset'] );

		$sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Debug logging, gated behind WP_DEBUG_LOG.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context payload.
	 */
	public static function debug( string $message, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}

		$suffix = array() === $context ? '' : ' ' . wp_json_encode( $context );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[tutor-course-bundles] ' . $message . $suffix );
	}
}
