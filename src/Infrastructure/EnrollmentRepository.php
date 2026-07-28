<?php
/**
 * Persistence for the entitlement → course-enrollment mapping.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Infrastructure;

use SpaceWork\TutorCourseBundles\Support\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes wp_tcb_bundle_enrollments.
 *
 * This table is the reason a refund on Bundle A cannot silently remove a course
 * the learner also bought directly or received through Bundle B: every course
 * enrollment we create is attributed to exactly one entitlement.
 */
final class EnrollmentRepository {

	public const STATUS_PENDING = 'pending';
	public const STATUS_ACTIVE  = 'active';
	public const STATUS_REVOKED = 'revoked';
	public const STATUS_FAILED  = 'failed';
	public const STATUS_SKIPPED = 'skipped';

	/**
	 * Fetch a row for an entitlement/course pair.
	 *
	 * @param int $access_id Entitlement row ID.
	 * @param int $course_id Course post ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $access_id, int $course_id ): ?array {
		global $wpdb;

		$table = Migration::table_bundle_enrollments();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE bundle_access_id = %d AND course_id = %d",
				$access_id,
				$course_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * All rows created by one entitlement.
	 *
	 * @param int $access_id Entitlement row ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_by_access( int $access_id ): array {
		global $wpdb;

		$table = Migration::table_bundle_enrollments();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE bundle_access_id = %d ORDER BY id ASC", $access_id ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Record a course enrollment created on behalf of an entitlement.
	 *
	 * @param array<string, mixed> $data Row data.
	 * @return int Row ID (existing row ID when it already exists).
	 */
	public function record( array $data ): int {
		global $wpdb;

		$access_id = (int) ( $data['bundle_access_id'] ?? 0 );
		$course_id = (int) ( $data['course_id'] ?? 0 );

		$existing = $this->find( $access_id, $course_id );

		if ( null !== $existing ) {
			$this->update(
				(int) $existing['id'],
				array(
					'tutor_enrollment_id' => (int) ( $data['tutor_enrollment_id'] ?? $existing['tutor_enrollment_id'] ),
					'status'              => (string) ( $data['status'] ?? $existing['status'] ),
					'enrolled_at'         => $data['enrolled_at'] ?? $existing['enrolled_at'],
					'error_code'          => $data['error_code'] ?? null,
				)
			);

			return (int) $existing['id'];
		}

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			Migration::table_bundle_enrollments(),
			array(
				'bundle_access_id'    => $access_id,
				'bundle_id'           => (int) ( $data['bundle_id'] ?? 0 ),
				'course_id'           => $course_id,
				'user_id'             => (int) ( $data['user_id'] ?? 0 ),
				'tutor_enrollment_id' => (int) ( $data['tutor_enrollment_id'] ?? 0 ),
				'status'              => (string) ( $data['status'] ?? self::STATUS_PENDING ),
				'enrolled_at'         => $data['enrolled_at'] ?? $now,
				'revoked_at'          => null,
				'error_code'          => isset( $data['error_code'] ) ? (string) $data['error_code'] : null,
				'created_at'          => $now,
				'updated_at'          => $now,
			),
			array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			$existing = $this->find( $access_id, $course_id );

			return $existing ? (int) $existing['id'] : 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a mapping row.
	 *
	 * @param int                  $id   Row ID.
	 * @param array<string, mixed> $data Columns to change.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$allowed = array( 'tutor_enrollment_id', 'status', 'enrolled_at', 'revoked_at', 'error_code' );
		$update  = array();
		$formats = array();

		foreach ( $allowed as $column ) {
			if ( ! array_key_exists( $column, $data ) ) {
				continue;
			}

			if ( 'tutor_enrollment_id' === $column ) {
				$update[ $column ] = (int) $data[ $column ];
				$formats[]         = '%d';
				continue;
			}

			$update[ $column ] = null === $data[ $column ] ? null : (string) $data[ $column ];
			$formats[]         = '%s';
		}

		if ( array() === $update ) {
			return false;
		}

		$update['updated_at'] = current_time( 'mysql', true );
		$formats[]            = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update(
			Migration::table_bundle_enrollments(),
			$update,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * Other *active* entitlements that also grant this course to this user.
	 *
	 * This is the query that protects a learner from losing a course when one
	 * of several sources goes away.
	 *
	 * @param int $user_id            User ID.
	 * @param int $course_id          Course post ID.
	 * @param int $excluding_access_id Entitlement to ignore.
	 * @return int Count of other live grants.
	 */
	public function count_other_active_grants( int $user_id, int $course_id, int $excluding_access_id ): int {
		global $wpdb;

		$enrollments = Migration::table_bundle_enrollments();
		$access      = Migration::table_bundle_access();
		$now         = current_time( 'mysql', true );

		// Table names are fixed by the plugin migration layer.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$enrollments} AS e
				 INNER JOIN {$access} AS a ON a.id = e.bundle_access_id
				 WHERE e.user_id = %d
				   AND e.course_id = %d
				   AND e.bundle_access_id != %d
				   AND e.status = %s
				   AND a.status = %s
				   AND (a.expires_at IS NULL OR a.expires_at > %s)",
				$user_id,
				$course_id,
				$excluding_access_id,
				self::STATUS_ACTIVE,
				\SpaceWork\TutorCourseBundles\Domain\BundleAccess::STATUS_ACTIVE,
				$now
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Rows still waiting to be processed for an entitlement.
	 *
	 * @param int $access_id Entitlement row ID.
	 * @return int[] Course IDs.
	 */
	public function get_pending_course_ids( int $access_id ): array {
		global $wpdb;

		$table = Migration::table_bundle_enrollments();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT course_id FROM {$table} WHERE bundle_access_id = %d AND status IN (%s, %s)",
				$access_id,
				self::STATUS_PENDING,
				self::STATUS_FAILED
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Failed rows across the whole site, for the admin repair tool.
	 *
	 * @param int $limit Batch size.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_failed( int $limit = 100 ): array {
		global $wpdb;

		$table = Migration::table_bundle_enrollments();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE status IN (%s, %s) ORDER BY id DESC LIMIT %d",
				self::STATUS_FAILED,
				self::STATUS_PENDING,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete all rows for an entitlement.
	 *
	 * @param int $access_id Entitlement row ID.
	 */
	public function delete_by_access( int $access_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Migration::table_bundle_enrollments(), array( 'bundle_access_id' => $access_id ), array( '%d' ) );
	}
}
