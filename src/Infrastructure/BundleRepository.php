<?php
/**
 * Persistence for bundles and their course membership.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Infrastructure;

use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Domain\BundleCourse;
use SpaceWork\TutorCourseBundles\Support\Cache;
use SpaceWork\TutorCourseBundles\Support\Migration;
use SpaceWork\TutorCourseBundles\Tutor\TutorAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * All reads and writes against wp_tcb_bundle_courses, plus bundle queries.
 */
final class BundleRepository {

	/**
	 * Fetch a bundle aggregate.
	 *
	 * @param int $bundle_id Bundle post ID.
	 */
	public function find( int $bundle_id ): ?Bundle {
		return Bundle::from( $bundle_id );
	}

	/**
	 * Course membership rows for a bundle, ordered by position.
	 *
	 * @param int  $bundle_id       Bundle post ID.
	 * @param bool $only_available  Drop courses that are deleted or unpublished.
	 * @return BundleCourse[]
	 */
	public function get_courses( int $bundle_id, bool $only_available = false ): array {
		$cached = Cache::get( 'courses_' . $bundle_id, Cache::GROUP_BUNDLE );

		if ( null === $cached ) {
			global $wpdb;

			$table = Migration::table_bundle_courses();

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE bundle_id = %d ORDER BY position ASC, id ASC",
					$bundle_id
				),
				ARRAY_A
			);

			$cached = is_array( $rows ) ? $rows : array();

			Cache::set( 'courses_' . $bundle_id, $cached, Cache::GROUP_BUNDLE );
		}

		$courses = array_map( array( BundleCourse::class, 'from_row' ), $cached );

		if ( $only_available ) {
			$courses = array_values(
				array_filter(
					$courses,
					static fn( BundleCourse $course ): bool => $course->is_available()
				)
			);
		}

		/**
		 * Filter the course list for a bundle.
		 *
		 * @param BundleCourse[] $courses   Membership rows.
		 * @param int            $bundle_id Bundle post ID.
		 */
		return apply_filters( 'tcb/bundle/courses', $courses, $bundle_id );
	}

	/**
	 * Course IDs in a bundle.
	 *
	 * @param int  $bundle_id      Bundle post ID.
	 * @param bool $only_available Drop unavailable courses.
	 * @return int[]
	 */
	public function get_course_ids( int $bundle_id, bool $only_available = false ): array {
		return array_map(
			static fn( BundleCourse $course ): int => $course->course_id,
			$this->get_courses( $bundle_id, $only_available )
		);
	}

	/**
	 * Membership rows that count toward completion.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @return BundleCourse[]
	 */
	public function get_required_courses( int $bundle_id ): array {
		return array_values(
			array_filter(
				$this->get_courses( $bundle_id, true ),
				static fn( BundleCourse $course ): bool => $course->is_required
			)
		);
	}

	/**
	 * Whether a course already belongs to a bundle.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @param int $course_id Course post ID.
	 */
	public function has_course( int $bundle_id, int $course_id ): bool {
		foreach ( $this->get_courses( $bundle_id ) as $course ) {
			if ( $course->course_id === $course_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Add a course to a bundle.
	 *
	 * Uses INSERT IGNORE semantics via the unique (bundle_id, course_id) key so
	 * a double submit cannot create duplicate rows.
	 *
	 * @param int                  $bundle_id Bundle post ID.
	 * @param int                  $course_id Course post ID.
	 * @param array<string, mixed> $args      position, is_required, unlock_mode, ...
	 * @return int|\WP_Error Inserted row ID.
	 */
	public function add_course( int $bundle_id, int $course_id, array $args = array() ) {
		global $wpdb;

		if ( $bundle_id <= 0 || $course_id <= 0 ) {
			return new \WP_Error( 'tcb_invalid_ids', __( 'Invalid bundle or course ID.', 'tutor-course-bundles' ) );
		}

		if ( get_post_type( $course_id ) !== \SpaceWork\TutorCourseBundles\Compatibility::course_post_type() ) {
			return new \WP_Error( 'tcb_not_a_course', __( 'That post is not a Tutor LMS course.', 'tutor-course-bundles' ) );
		}

		if ( $this->has_course( $bundle_id, $course_id ) ) {
			return new \WP_Error( 'tcb_duplicate_course', __( 'That course is already in this bundle.', 'tutor-course-bundles' ) );
		}

		$defaults = array(
			'position'                   => $this->get_next_position( $bundle_id ),
			'is_required'                => 1,
			'unlock_mode'                => BundleCourse::UNLOCK_IMMEDIATE,
			'unlock_reference_course_id' => null,
			'unlock_delay_days'          => 0,
		);

		$args = wp_parse_args( $args, $defaults );
		$now  = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			Migration::table_bundle_courses(),
			array(
				'bundle_id'                  => $bundle_id,
				'course_id'                  => $course_id,
				'position'                   => (int) $args['position'],
				'is_required'                => (int) (bool) $args['is_required'],
				'unlock_mode'                => (string) $args['unlock_mode'],
				'unlock_reference_course_id' => $args['unlock_reference_course_id'] ? (int) $args['unlock_reference_course_id'] : null,
				'unlock_delay_days'          => (int) $args['unlock_delay_days'],
				'created_at'                 => $now,
				'updated_at'                 => $now,
			),
			array( '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new \WP_Error( 'tcb_insert_failed', __( 'Could not add the course to the bundle.', 'tutor-course-bundles' ) );
		}

		$row_id = (int) $wpdb->insert_id;

		Cache::flush_bundle( $bundle_id );
		$this->sync_course_count( $bundle_id );

		/**
		 * Fires after a course is attached to a bundle.
		 *
		 * @param int $bundle_id Bundle post ID.
		 * @param int $course_id Course post ID.
		 */
		do_action( 'tcb/bundle/course_added', $bundle_id, $course_id );

		return $row_id;
	}

	/**
	 * Detach a course from a bundle.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @param int $course_id Course post ID.
	 */
	public function remove_course( int $bundle_id, int $course_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->delete(
			Migration::table_bundle_courses(),
			array(
				'bundle_id' => $bundle_id,
				'course_id' => $course_id,
			),
			array( '%d', '%d' )
		);

		if ( ! $deleted ) {
			return false;
		}

		Cache::flush_bundle( $bundle_id );
		$this->sync_course_count( $bundle_id );

		/**
		 * Fires after a course is detached from a bundle.
		 *
		 * Note: existing learners keep their course enrollment by default —
		 * see the `tcb_revoke_on_course_removal` setting.
		 *
		 * @param int $bundle_id Bundle post ID.
		 * @param int $course_id Course post ID.
		 */
		do_action( 'tcb/bundle/course_removed', $bundle_id, $course_id );

		return true;
	}

	/**
	 * Replace a bundle's entire course list in one transactional-ish pass.
	 *
	 * @param int                              $bundle_id Bundle post ID.
	 * @param array<int, array<string, mixed>> $items     Each with course_id, is_required, unlock_* keys.
	 * @return array{added: int[], removed: int[], kept: int[]}|\WP_Error
	 */
	public function set_courses( int $bundle_id, array $items ): array|\WP_Error {
		global $wpdb;

		$existing = $this->get_course_ids( $bundle_id );
		$incoming = array();
		$position = 0;

		foreach ( $items as $item ) {
			$course_id = (int) ( $item['course_id'] ?? 0 );

			if ( $course_id <= 0 || isset( $incoming[ $course_id ] ) ) {
				continue;
			}

			if ( get_post_type( $course_id ) !== \SpaceWork\TutorCourseBundles\Compatibility::course_post_type() ) {
				return new \WP_Error( 'tcb_not_a_course', __( 'One of the selected items is not a Tutor LMS course.', 'tutor-course-bundles' ) );
			}

			$incoming[ $course_id ] = array(
				'position'                   => $position++,
				'is_required'                => (int) (bool) ( $item['is_required'] ?? 1 ),
				'unlock_mode'                => (string) ( $item['unlock_mode'] ?? BundleCourse::UNLOCK_IMMEDIATE ),
				'unlock_reference_course_id' => isset( $item['unlock_reference_course_id'] ) && $item['unlock_reference_course_id']
					? (int) $item['unlock_reference_course_id']
					: null,
				'unlock_delay_days'          => (int) ( $item['unlock_delay_days'] ?? 0 ),
			);
		}

		$incoming_ids = array_keys( $incoming );
		$to_remove    = array_diff( $existing, $incoming_ids );
		$to_add       = array_diff( $incoming_ids, $existing );
		$kept         = array_intersect( $existing, $incoming_ids );

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new \WP_Error( 'tcb_transaction_failed', __( 'Could not start the bundle update transaction.', 'tutor-course-bundles' ) );
		}

		foreach ( $to_remove as $course_id ) {
			if ( ! $this->remove_course( $bundle_id, (int) $course_id ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				Cache::flush_bundle( $bundle_id );
				return new \WP_Error( 'tcb_remove_course_failed', __( 'Could not update the bundle course list.', 'tutor-course-bundles' ) );
			}
		}

		foreach ( $incoming as $course_id => $data ) {
			if ( in_array( $course_id, $to_add, true ) ) {
				$result = $this->add_course( $bundle_id, (int) $course_id, $data );
				if ( is_wp_error( $result ) ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					Cache::flush_bundle( $bundle_id );
					return $result;
				}
			} elseif ( ! $this->update_course( $bundle_id, (int) $course_id, $data ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				Cache::flush_bundle( $bundle_id );
				return new \WP_Error( 'tcb_update_course_failed', __( 'Could not update the bundle course list.', 'tutor-course-bundles' ) );
			}
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Cache::flush_bundle( $bundle_id );
			return new \WP_Error( 'tcb_commit_failed', __( 'Could not commit the bundle course update.', 'tutor-course-bundles' ) );
		}

		Cache::flush_bundle( $bundle_id );
		$this->sync_course_count( $bundle_id );

		return array(
			'added'   => array_values( array_map( 'intval', $to_add ) ),
			'removed' => array_values( array_map( 'intval', $to_remove ) ),
			'kept'    => array_values( array_map( 'intval', $kept ) ),
		);
	}

	/**
	 * Update an existing membership row.
	 *
	 * @param int                  $bundle_id Bundle post ID.
	 * @param int                  $course_id Course post ID.
	 * @param array<string, mixed> $data      Columns to update.
	 */
	public function update_course( int $bundle_id, int $course_id, array $data ): bool {
		global $wpdb;

		$allowed = array( 'position', 'is_required', 'unlock_mode', 'unlock_reference_course_id', 'unlock_delay_days' );
		$update  = array();
		$formats = array();

		foreach ( $allowed as $column ) {
			if ( ! array_key_exists( $column, $data ) ) {
				continue;
			}

			switch ( $column ) {
				case 'unlock_mode':
					$update[ $column ] = (string) $data[ $column ];
					$formats[]         = '%s';
					break;
				case 'unlock_reference_course_id':
					$update[ $column ] = $data[ $column ] ? (int) $data[ $column ] : null;
					$formats[]         = '%d';
					break;
				default:
					$update[ $column ] = (int) $data[ $column ];
					$formats[]         = '%d';
			}
		}

		if ( array() === $update ) {
			return false;
		}

		$update['updated_at'] = current_time( 'mysql', true );
		$formats[]            = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update(
			Migration::table_bundle_courses(),
			$update,
			array(
				'bundle_id' => $bundle_id,
				'course_id' => $course_id,
			),
			$formats,
			array( '%d', '%d' )
		);

		Cache::flush_bundle( $bundle_id );

		return false !== $result;
	}

	/**
	 * Persist a new ordering.
	 *
	 * @param int   $bundle_id  Bundle post ID.
	 * @param int[] $course_ids Course IDs in the desired order.
	 */
	public function reorder( int $bundle_id, array $course_ids ): bool {
		global $wpdb;

		$course_ids = array_values( array_filter( array_map( 'absint', $course_ids ) ) );
		if ( count( $course_ids ) !== count( array_unique( $course_ids ) ) ) {
			return false;
		}

		$current  = $this->get_course_ids( $bundle_id );
		$expected = $current;
		$received = $course_ids;
		sort( $expected );
		sort( $received );

		if ( $expected !== $received ) {
			return false;
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return false;
		}

		$position = 0;
		foreach ( $course_ids as $course_id ) {
			if ( ! $this->update_course( $bundle_id, (int) $course_id, array( 'position' => $position++ ) ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				Cache::flush_bundle( $bundle_id );
				return false;
			}
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Cache::flush_bundle( $bundle_id );
			return false;
		}

		Cache::flush_bundle( $bundle_id );
		do_action( 'tcb/bundle/courses_reordered', $bundle_id, $course_ids );

		return true;
	}

	/**
	 * Next free sort position in a bundle.
	 *
	 * @param int $bundle_id Bundle post ID.
	 */
	public function get_next_position( int $bundle_id ): int {
		global $wpdb;

		$table = Migration::table_bundle_courses();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$max = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT MAX(position) FROM {$table} WHERE bundle_id = %d",
				$bundle_id
			)
		);

		return null === $max ? 0 : ( (int) $max ) + 1;
	}

	/**
	 * Every bundle that contains a given course.
	 *
	 * @param int $course_id Course post ID.
	 * @return int[] Bundle post IDs.
	 */
	public function get_bundles_containing_course( int $course_id ): array {
		global $wpdb;

		$table = Migration::table_bundle_courses();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT DISTINCT bundle_id FROM {$table} WHERE course_id = %d",
				$course_id
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Recalculate and store the cached course count.
	 *
	 * @param int $bundle_id Bundle post ID.
	 */
	public function sync_course_count( int $bundle_id ): int {
		global $wpdb;

		$table = Migration::table_bundle_courses();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE bundle_id = %d",
				$bundle_id
			)
		);

		update_post_meta( $bundle_id, Bundle::META_COURSE_COUNT, $count );

		return $count;
	}

	/**
	 * Aggregate stats shown on the bundle page: lesson totals, duration, instructors.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @return array{course_count:int, lesson_count:int, quiz_count:int, assignment_count:int, instructors:array<int, array<string,mixed>>}
	 */
	public function get_stats( int $bundle_id ): array {
		$cached = Cache::get( 'stats_' . $bundle_id, Cache::GROUP_BUNDLE );

		if ( null !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$adapter     = new TutorAdapter();
		$courses     = $this->get_courses( $bundle_id, true );
		$lessons     = 0;
		$quizzes     = 0;
		$assignments = 0;
		$instructors = array();

		foreach ( $courses as $course ) {
			$counts       = $adapter->get_course_content_counts( $course->course_id );
			$lessons     += $counts['lessons'];
			$quizzes     += $counts['quizzes'];
			$assignments += $counts['assignments'];

			foreach ( $adapter->get_course_instructors( $course->course_id ) as $instructor ) {
				$instructors[ (int) $instructor['id'] ] = $instructor;
			}
		}

		$stats = array(
			'course_count'     => count( $courses ),
			'lesson_count'     => $lessons,
			'quiz_count'       => $quizzes,
			'assignment_count' => $assignments,
			'instructors'      => array_values( $instructors ),
		);

		Cache::set( 'stats_' . $bundle_id, $stats, Cache::GROUP_BUNDLE );

		return $stats;
	}

	/**
	 * Query bundles.
	 *
	 * @param array<string, mixed> $args WP_Query-ish arguments.
	 * @return Bundle[]
	 */
	public function query( array $args = array() ): array {
		$defaults = array(
			'post_type'      => PostTypes::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 12,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);

		$query = new \WP_Query( wp_parse_args( $args, $defaults ) );

		return array_values(
			array_filter(
				array_map( array( Bundle::class, 'from' ), $query->posts )
			)
		);
	}

	/**
	 * Find the bundle linked to a WooCommerce product.
	 *
	 * @param int $product_id Product ID.
	 */
	public function find_by_wc_product( int $product_id ): ?Bundle {
		if ( $product_id <= 0 ) {
			return null;
		}

		$linked = (int) get_post_meta( $product_id, '_tcb_linked_bundle_id', true );

		if ( $linked > 0 ) {
			return $this->find( $linked );
		}

		$query = new \WP_Query(
			array(
				'post_type'      => PostTypes::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => Bundle::META_WC_PRODUCT_ID,
						'value' => $product_id,
					),
				),
			)
		);

		if ( empty( $query->posts ) ) {
			return null;
		}

		return $this->find( (int) $query->posts[0] );
	}
}
