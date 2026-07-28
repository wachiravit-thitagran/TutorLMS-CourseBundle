<?php
/**
 * Persistence for bundle entitlements.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Infrastructure;

use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Support\Cache;
use SpaceWork\TutorCourseBundles\Support\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes wp_tcb_bundle_access.
 *
 * The unique key (bundle_id, user_id, source_type, source_id) is what makes
 * granting idempotent: a repeated webhook resolves to the same row instead of
 * creating a second entitlement.
 */
final class AccessRepository {

	/**
	 * Fetch by primary key.
	 *
	 * @param int $access_id Row ID.
	 */
	public function find( int $access_id ): ?BundleAccess {
		global $wpdb;

		$table = Migration::table_bundle_access();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $access_id ),
			ARRAY_A
		);

		return is_array( $row ) ? BundleAccess::from_row( $row ) : null;
	}

	/**
	 * Find the entitlement created by a specific source.
	 *
	 * @param int    $bundle_id   Bundle post ID.
	 * @param int    $user_id     User ID.
	 * @param string $source_type Source type.
	 * @param int    $source_id   Source identifier.
	 */
	public function find_by_source( int $bundle_id, int $user_id, string $source_type, int $source_id ): ?BundleAccess {
		global $wpdb;

		$table = Migration::table_bundle_access();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE bundle_id = %d AND user_id = %d AND source_type = %s AND source_id = %d",
				$bundle_id,
				$user_id,
				$source_type,
				$source_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? BundleAccess::from_row( $row ) : null;
	}

	/**
	 * Every entitlement a user holds for a bundle, newest first.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @param int $user_id   User ID.
	 * @return BundleAccess[]
	 */
	public function find_for_user_bundle( int $bundle_id, int $user_id ): array {
		global $wpdb;

		$table = Migration::table_bundle_access();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE bundle_id = %d AND user_id = %d ORDER BY id DESC",
				$bundle_id,
				$user_id
			),
			ARRAY_A
		);

		return array_map( array( BundleAccess::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The entitlement that currently grants access, if any.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @param int $user_id   User ID.
	 */
	public function find_active( int $bundle_id, int $user_id ): ?BundleAccess {
		foreach ( $this->find_for_user_bundle( $bundle_id, $user_id ) as $access ) {
			if ( $access->is_usable() ) {
				return $access;
			}
		}

		return null;
	}

	/**
	 * Whether a user can currently use a bundle.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @param int $user_id   User ID.
	 */
	public function user_has_access( int $bundle_id, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		return null !== $this->find_active( $bundle_id, $user_id );
	}

	/**
	 * Every entitlement created by one source record, e.g. a single order.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Source identifier.
	 * @return BundleAccess[]
	 */
	public function find_by_source_id( string $source_type, int $source_id ): array {
		global $wpdb;

		$table = Migration::table_bundle_access();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE source_type = %s AND source_id = %d ORDER BY id ASC",
				$source_type,
				$source_id
			),
			ARRAY_A
		);

		return array_map( array( BundleAccess::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * All entitlements held by a user across bundles.
	 *
	 * @param int      $user_id User ID.
	 * @param string[] $statuses Optional status filter.
	 * @return BundleAccess[]
	 */
	public function find_for_user( int $user_id, array $statuses = array() ): array {
		global $wpdb;

		$table  = Migration::table_bundle_access();
		$sql    = "SELECT * FROM {$table} WHERE user_id = %d";
		$params = array( $user_id );

		if ( array() !== $statuses ) {
			$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
			$sql         .= " AND status IN ({$placeholders})";
			$params       = array_merge( $params, $statuses );
		}

		$sql .= ' ORDER BY id DESC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return array_map( array( BundleAccess::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * One display entitlement per bundle, preferring a usable grant over newer
	 * historical rows.
	 *
	 * Input is expected newest-first, as returned by find_for_user().
	 *
	 * @param BundleAccess[] $accesses Entitlements for one learner.
	 * @return array<int, BundleAccess> Entitlements keyed by bundle ID.
	 */
	public static function select_preferred_by_bundle( array $accesses ): array {
		$selected = array();

		foreach ( $accesses as $access ) {
			if ( ! $access instanceof BundleAccess ) {
				continue;
			}

			$bundle_id = $access->bundle_id;

			if ( ! isset( $selected[ $bundle_id ] ) || ( $access->is_usable() && ! $selected[ $bundle_id ]->is_usable() ) ) {
				$selected[ $bundle_id ] = $access;
			}
		}

		return $selected;
	}

	/**
	 * Preferred display entitlement for every bundle held by a learner.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, BundleAccess> Entitlements keyed by bundle ID.
	 */
	public function find_preferred_for_user( int $user_id ): array {
		return self::select_preferred_by_bundle( $this->find_for_user( $user_id ) );
	}

	/**
	 * Paginated entitlement list for admin screens.
	 *
	 * @param array<string, mixed> $args bundle_id, user_id, status, source, limit, offset, before_id.
	 * @return array{items: BundleAccess[], total: int}
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'bundle_id' => 0,
			'user_id'   => 0,
			'status'    => '',
			'source'    => '',
			'limit'     => 20,
			'offset'    => 0,
			'before_id' => 0,
		);

		$args   = wp_parse_args( $args, $defaults );
		$table  = Migration::table_bundle_access();
		$where  = array( '1=1' );
		$params = array();

		if ( (int) $args['bundle_id'] > 0 ) {
			$where[]  = 'bundle_id = %d';
			$params[] = (int) $args['bundle_id'];
		}

		if ( (int) $args['user_id'] > 0 ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $args['user_id'];
		}

		if ( '' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}

		if ( '' !== $args['source'] ) {
			$where[]  = 'source_type = %s';
			$params[] = (string) $args['source'];
		}

		if ( (int) $args['before_id'] > 0 ) {
			$where[]  = 'id < %d';
			$params[] = (int) $args['before_id'];
		}

		$where_sql = implode( ' AND ', $where );

		// Dynamic fragments contain only hard-coded clauses assembled above;
		// table names come from the plugin's migration layer.
		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery
		if ( array() === $params ) {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
		} else {
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) );
		}

		$list_params   = $params;
		$list_params[] = max( 1, min( 200, (int) $args['limit'] ) );
		$list_params[] = max( 0, (int) $args['offset'] );

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", $list_params ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery

		return array(
			'items' => array_map( array( BundleAccess::class, 'from_row' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Iterate every matching entitlement without loading the full result set.
	 *
	 * @param array<string, mixed> $args       Query filters.
	 * @param int                  $batch_size Rows per database page.
	 * @return \Generator<int, BundleAccess>
	 */
	public function iterate( array $args = array(), int $batch_size = 200 ): \Generator {
		$before_id  = 0;
		$batch_size = max( 1, min( 200, $batch_size ) );

		do {
			$page = $this->query(
				array_merge(
					$args,
					array(
						'limit'     => $batch_size,
						'offset'    => 0,
						'before_id' => $before_id,
					)
				)
			);

			foreach ( $page['items'] as $access ) {
				yield $access;
				$before_id = $access->id;
			}

			$page_count = count( $page['items'] );
		} while ( $page_count === $batch_size );
	}

	/**
	 * Create an entitlement, or return the existing one for the same source.
	 *
	 * @param array<string, mixed> $data Row data.
	 * @return BundleAccess|\WP_Error
	 */
	public function create( array $data ) {
		global $wpdb;

		$bundle_id   = (int) ( $data['bundle_id'] ?? 0 );
		$user_id     = (int) ( $data['user_id'] ?? 0 );
		$source_type = (string) ( $data['source_type'] ?? BundleAccess::SOURCE_MANUAL );
		$source_id   = (int) ( $data['source_id'] ?? 0 );

		if ( $bundle_id <= 0 || $user_id <= 0 ) {
			return new \WP_Error( 'tcb_invalid_access', __( 'A bundle and user are required to grant access.', 'tutor-course-bundles' ) );
		}

		$existing = $this->find_by_source( $bundle_id, $user_id, $source_type, $source_id );

		if ( $existing instanceof BundleAccess ) {
			return $existing;
		}

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			Migration::table_bundle_access(),
			array(
				'bundle_id'   => $bundle_id,
				'user_id'     => $user_id,
				'source_type' => $source_type,
				'source_id'   => $source_id,
				'status'      => (string) ( $data['status'] ?? BundleAccess::STATUS_PENDING ),
				'granted_at'  => $data['granted_at'] ?? $now,
				'starts_at'   => $data['starts_at'] ?? null,
				'expires_at'  => $data['expires_at'] ?? null,
				'revoked_at'  => null,
				'note'        => (string) ( $data['note'] ?? '' ),
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			// Lost a race against a concurrent request — re-read the winner's row.
			$existing = $this->find_by_source( $bundle_id, $user_id, $source_type, $source_id );

			if ( $existing instanceof BundleAccess ) {
				return $existing;
			}

			return new \WP_Error( 'tcb_access_insert_failed', __( 'Could not record bundle access.', 'tutor-course-bundles' ) );
		}

		Cache::flush_group( Cache::GROUP_ACCESS );

		$access = $this->find( (int) $wpdb->insert_id );

		return $access ?? new \WP_Error( 'tcb_access_read_failed', __( 'Access was created but could not be read back.', 'tutor-course-bundles' ) );
	}

	/**
	 * Update entitlement columns.
	 *
	 * @param int                  $access_id Row ID.
	 * @param array<string, mixed> $data      Columns to change.
	 */
	public function update( int $access_id, array $data ): bool {
		global $wpdb;

		$allowed = array( 'status', 'granted_at', 'starts_at', 'expires_at', 'revoked_at', 'note' );
		$update  = array();
		$formats = array();

		foreach ( $allowed as $column ) {
			if ( ! array_key_exists( $column, $data ) ) {
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
		$result = $wpdb->update(
			Migration::table_bundle_access(),
			$update,
			array( 'id' => $access_id ),
			$formats,
			array( '%d' )
		);

		Cache::flush_group( Cache::GROUP_ACCESS );

		return false !== $result;
	}

	/**
	 * Mark an entitlement as no longer valid.
	 *
	 * @param int    $access_id Row ID.
	 * @param string $status    Terminal status.
	 */
	public function terminate( int $access_id, string $status = BundleAccess::STATUS_REVOKED ): bool {
		return $this->update(
			$access_id,
			array(
				'status'     => $status,
				'revoked_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Entitlements that have passed their expiry but are still marked active.
	 *
	 * @param int $limit Batch size.
	 * @return BundleAccess[]
	 */
	public function find_expired_active( int $limit = 100 ): array {
		global $wpdb;

		$table = Migration::table_bundle_access();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE status = %s AND expires_at IS NOT NULL AND expires_at < %s ORDER BY expires_at ASC LIMIT %d",
				BundleAccess::STATUS_ACTIVE,
				$now,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( BundleAccess::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Count entitlements grouped by status for a bundle.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @return array<string, int>
	 */
	public function count_by_status( int $bundle_id ): array {
		global $wpdb;

		$table = Migration::table_bundle_access();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT status, COUNT(*) AS total FROM {$table} WHERE bundle_id = %d GROUP BY status",
				$bundle_id
			),
			ARRAY_A
		);

		$counts = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Number of users with a usable entitlement to a bundle.
	 *
	 * @param int $bundle_id Bundle post ID.
	 */
	public function count_active_learners( int $bundle_id ): int {
		global $wpdb;

		$table = Migration::table_bundle_access();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(DISTINCT user_id) FROM {$table}
				 WHERE bundle_id = %d AND status = %s
				 AND (starts_at IS NULL OR starts_at <= %s)
				 AND (expires_at IS NULL OR expires_at > %s)",
				$bundle_id,
				BundleAccess::STATUS_ACTIVE,
				$now,
				$now
			)
		);
	}

	/**
	 * Delete every entitlement row for a bundle. Used by uninstall only.
	 *
	 * @param int $bundle_id Bundle post ID.
	 */
	public function delete_for_bundle( int $bundle_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Migration::table_bundle_access(), array( 'bundle_id' => $bundle_id ), array( '%d' ) );

		Cache::flush_group( Cache::GROUP_ACCESS );
	}
}
