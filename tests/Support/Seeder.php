<?php
/**
 * Fluent test data builder.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Support;

use SpaceWork\TutorCourseBundles\Commerce\WooCommerceGateway;
use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Infrastructure\AccessRepository;
use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Infrastructure\BundleRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\PostTypes;
use SpaceWork\TutorCourseBundles\Support\Migration;
use SpaceWork\TutorCourseBundles\Tutor\CourseAccessResolver;
use SpaceWork\TutorCourseBundles\Tutor\TutorAdapter;

/**
 * Creates realistic worlds for integration tests.
 *
 * Everything here goes through real WordPress and real Tutor LMS APIs. Nothing
 * writes to the plugin's own tables directly except `access()`, which needs to
 * be able to fabricate states (expired, refunded) that the normal flow would
 * take weeks to reach.
 */
final class Seeder {

	/**
	 * Auto-incrementing suffix so seeded titles stay unique within a run.
	 *
	 * @var int
	 */
	private static int $sequence = 0;

	/**
	 * Every post ID this seeder created, for teardown.
	 *
	 * @var int[]
	 */
	private array $created_posts = array();

	/**
	 * Every user ID this seeder created.
	 *
	 * @var int[]
	 */
	private array $created_users = array();

	/**
	 * Next unique suffix.
	 */
	private function next(): int {
		return ++self::$sequence;
	}

	/* ---------------------------------------------------------------------
	 * Users
	 * ------------------------------------------------------------------ */

	/**
	 * Create a student.
	 *
	 * @param array<string, mixed> $args User args.
	 */
	public function student( array $args = array() ): int {
		return $this->user( array_merge( array( 'role' => 'subscriber' ), $args ) );
	}

	/**
	 * Create an instructor.
	 *
	 * @param array<string, mixed> $args User args.
	 */
	public function instructor( array $args = array() ): int {
		$role    = get_role( 'tutor_instructor' ) ? 'tutor_instructor' : 'editor';
		$user_id = $this->user( array_merge( array( 'role' => $role ), $args ) );

		update_user_meta( $user_id, '_is_tutor_instructor', time() );
		update_user_meta( $user_id, '_tutor_instructor_status', 'approved' );

		return $user_id;
	}

	/**
	 * Create an administrator.
	 *
	 * @param array<string, mixed> $args User args.
	 */
	public function admin( array $args = array() ): int {
		return $this->user( array_merge( array( 'role' => 'administrator' ), $args ) );
	}

	/**
	 * Create a user of any role.
	 *
	 * @param array<string, mixed> $args User args.
	 */
	public function user( array $args = array() ): int {
		$n = $this->next();

		$user_id = wp_insert_user(
			wp_parse_args(
				$args,
				array(
					'user_login' => 'tcb_user_' . $n,
					'user_email' => 'tcb_user_' . $n . '@example.test',
					'user_pass'  => wp_generate_password( 12 ),
					'first_name' => 'Test',
					'last_name'  => 'User ' . $n,
					'role'       => 'subscriber',
				)
			)
		);

		if ( is_wp_error( $user_id ) ) {
			throw new \RuntimeException( 'Seeder could not create a user: ' . $user_id->get_error_message() );
		}

		$this->created_users[] = (int) $user_id;

		return (int) $user_id;
	}

	/* ---------------------------------------------------------------------
	 * Courses
	 * ------------------------------------------------------------------ */

	/**
	 * Create a Tutor LMS course, optionally with lessons.
	 *
	 * @param array<string, mixed> $args title, status, author, lessons.
	 */
	public function course( array $args = array() ): int {
		$n = $this->next();

		$args = wp_parse_args(
			$args,
			array(
				'title'   => 'Course ' . $n,
				'status'  => 'publish',
				'author'  => 1,
				'lessons' => 0,
			)
		);

		$course_id = wp_insert_post(
			array(
				'post_type'    => \SpaceWork\TutorCourseBundles\Compatibility::course_post_type(),
				'post_title'   => (string) $args['title'],
				'post_content' => 'Seeded course content.',
				'post_status'  => (string) $args['status'],
				'post_author'  => (int) $args['author'],
			),
			true
		);

		if ( is_wp_error( $course_id ) ) {
			throw new \RuntimeException( 'Seeder could not create a course: ' . $course_id->get_error_message() );
		}

		$course_id             = (int) $course_id;
		$this->created_posts[] = $course_id;

		update_post_meta( $course_id, '_tutor_course_price_type', 'free' );

		if ( (int) $args['lessons'] > 0 ) {
			$this->lessons( $course_id, (int) $args['lessons'] );
		}

		return $course_id;
	}

	/**
	 * Create several courses at once.
	 *
	 * @param int                  $count Number of courses.
	 * @param array<string, mixed> $args  Shared args.
	 * @return int[]
	 */
	public function courses( int $count, array $args = array() ): array {
		$ids = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$ids[] = $this->course( $args );
		}

		return $ids;
	}

	/**
	 * Create a topic inside a course.
	 *
	 * @param int    $course_id Course post ID.
	 * @param string $title     Topic title.
	 */
	public function topic( int $course_id, string $title = '' ): int {
		$topic_id = wp_insert_post(
			array(
				'post_type'   => 'topics',
				'post_title'  => '' !== $title ? $title : 'Topic ' . $this->next(),
				'post_status' => 'publish',
				'post_parent' => $course_id,
			),
			true
		);

		if ( is_wp_error( $topic_id ) ) {
			throw new \RuntimeException( 'Seeder could not create a topic: ' . $topic_id->get_error_message() );
		}

		$this->created_posts[] = (int) $topic_id;

		return (int) $topic_id;
	}

	/**
	 * Add lessons to a course, creating a topic to hold them.
	 *
	 * Tutor's content tree is course → topic → lesson, and its lesson counter
	 * walks exactly that shape, so the seeder builds the real thing rather than
	 * faking the count with meta.
	 *
	 * @param int $course_id Course post ID.
	 * @param int $count     How many lessons.
	 * @return int[] Lesson post IDs.
	 */
	public function lessons( int $course_id, int $count ): array {
		$topic_id = $this->topic( $course_id );
		$lessons  = array();

		for ( $i = 1; $i <= $count; $i++ ) {
			$lesson_id = wp_insert_post(
				array(
					'post_type'    => 'lesson',
					'post_title'   => sprintf( 'Lesson %d', $i ),
					'post_content' => 'Seeded lesson content.',
					'post_status'  => 'publish',
					'post_parent'  => $topic_id,
					'menu_order'   => $i,
				),
				true
			);

			if ( is_wp_error( $lesson_id ) ) {
				continue;
			}

			$lesson_id = (int) $lesson_id;

			update_post_meta( $lesson_id, '_tutor_course_id_for_lesson', $course_id );

			$this->created_posts[] = $lesson_id;
			$lessons[]             = $lesson_id;
		}

		return $lessons;
	}

	/**
	 * Lesson IDs belonging to a course, in menu order.
	 *
	 * @param int $course_id Course post ID.
	 * @return int[]
	 */
	public function get_lessons( int $course_id ): array {
		$query = new \WP_Query(
			array(
				'post_type'      => 'lesson',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_tutor_course_id_for_lesson',
						'value' => $course_id,
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/* ---------------------------------------------------------------------
	 * Bundles
	 * ------------------------------------------------------------------ */

	/**
	 * Create a bundle, optionally attaching courses.
	 *
	 * @param array<string, mixed> $args title, status, price, sale_price,
	 *                                   access_type, courses, optional_courses,
	 *                                   access_duration, completion_mode,
	 *                                   grant_new_courses, author.
	 */
	public function bundle( array $args = array() ): int {
		$n = $this->next();

		$args = wp_parse_args(
			$args,
			array(
				'title'             => 'Bundle ' . $n,
				'status'            => 'publish',
				'author'            => 1,
				'price'             => 0.0,
				'sale_price'        => null,
				'access_type'       => Bundle::ACCESS_TYPE_FREE,
				'courses'           => array(),
				'optional_courses'  => array(),
				'access_duration'   => 0,
				'completion_mode'   => Bundle::COMPLETION_ALL_REQUIRED,
				'grant_new_courses' => 'yes',
				'short_description' => '',
			)
		);

		$bundle_id = wp_insert_post(
			array(
				'post_type'    => PostTypes::POST_TYPE,
				'post_title'   => (string) $args['title'],
				'post_content' => 'Seeded bundle description.',
				'post_status'  => (string) $args['status'],
				'post_author'  => (int) $args['author'],
			),
			true
		);

		if ( is_wp_error( $bundle_id ) ) {
			throw new \RuntimeException( 'Seeder could not create a bundle: ' . $bundle_id->get_error_message() );
		}

		$bundle_id             = (int) $bundle_id;
		$this->created_posts[] = $bundle_id;

		update_post_meta( $bundle_id, Bundle::META_ACCESS_TYPE, (string) $args['access_type'] );
		update_post_meta( $bundle_id, Bundle::META_PRICE, (float) $args['price'] );
		update_post_meta( $bundle_id, Bundle::META_ACCESS_DURATION, (int) $args['access_duration'] );
		update_post_meta( $bundle_id, Bundle::META_COMPLETION_MODE, (string) $args['completion_mode'] );
		update_post_meta( $bundle_id, Bundle::META_GRANT_NEW_COURSES, (string) $args['grant_new_courses'] );
		update_post_meta( $bundle_id, Bundle::META_SHORT_DESCRIPTION, (string) $args['short_description'] );

		if ( null !== $args['sale_price'] ) {
			update_post_meta( $bundle_id, Bundle::META_SALE_PRICE, (float) $args['sale_price'] );
		}

		$items = array();

		foreach ( (array) $args['courses'] as $course_id ) {
			$items[] = array(
				'course_id'   => (int) $course_id,
				'is_required' => 1,
			);
		}

		foreach ( (array) $args['optional_courses'] as $course_id ) {
			$items[] = array(
				'course_id'   => (int) $course_id,
				'is_required' => 0,
			);
		}

		if ( array() !== $items ) {
			$this->repository()->set_courses( $bundle_id, $items );
		}

		return $bundle_id;
	}

	/**
	 * Create a bundle together with brand new courses.
	 *
	 * @param int                  $course_count   How many courses to create.
	 * @param array<string, mixed> $bundle_args    Bundle args.
	 * @param array<string, mixed> $course_args    Course args (e.g. lessons).
	 * @return array{bundle: int, courses: int[]}
	 */
	public function bundle_with_courses( int $course_count, array $bundle_args = array(), array $course_args = array() ): array {
		$courses = $this->courses( $course_count, $course_args );

		$bundle_args['courses'] = $courses;

		return array(
			'bundle'  => $this->bundle( $bundle_args ),
			'courses' => $courses,
		);
	}

	/* ---------------------------------------------------------------------
	 * Entitlements
	 * ------------------------------------------------------------------ */

	/**
	 * Insert an entitlement row directly.
	 *
	 * Used to fabricate states the happy path cannot reach quickly: an access
	 * that expired yesterday, one that was refunded, one that starts next week.
	 * For normal grants, call the real EnrollmentService instead.
	 *
	 * @param int                  $bundle_id Bundle post ID.
	 * @param int                  $user_id   User ID.
	 * @param array<string, mixed> $args      Row overrides.
	 */
	public function access( int $bundle_id, int $user_id, array $args = array() ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$row = wp_parse_args(
			$args,
			array(
				'bundle_id'   => $bundle_id,
				'user_id'     => $user_id,
				'source_type' => BundleAccess::SOURCE_MANUAL,
				'source_id'   => 0,
				'status'      => BundleAccess::STATUS_ACTIVE,
				'granted_at'  => $now,
				'starts_at'   => null,
				'expires_at'  => null,
				'revoked_at'  => null,
				'note'        => 'seeded',
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);

		$wpdb->insert( Migration::table_bundle_access(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return (int) $wpdb->insert_id;
	}

	/**
	 * An entitlement that expired a given number of days ago.
	 *
	 * @param int $bundle_id   Bundle post ID.
	 * @param int $user_id     User ID.
	 * @param int $days_ago    How long ago it expired.
	 */
	public function expired_access( int $bundle_id, int $user_id, int $days_ago = 1 ): int {
		return $this->access(
			$bundle_id,
			$user_id,
			array(
				'status'     => BundleAccess::STATUS_ACTIVE,
				'granted_at' => gmdate( 'Y-m-d H:i:s', time() - ( ( $days_ago + 30 ) * DAY_IN_SECONDS ) ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() - ( $days_ago * DAY_IN_SECONDS ) ),
			)
		);
	}

	/**
	 * An entitlement that has not started yet.
	 *
	 * @param int $bundle_id  Bundle post ID.
	 * @param int $user_id    User ID.
	 * @param int $days_ahead Days until it opens.
	 */
	public function future_access( int $bundle_id, int $user_id, int $days_ahead = 7 ): int {
		return $this->access(
			$bundle_id,
			$user_id,
			array(
				'status'    => BundleAccess::STATUS_ACTIVE,
				'starts_at' => gmdate( 'Y-m-d H:i:s', time() + ( $days_ahead * DAY_IN_SECONDS ) ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Tutor enrollment
	 * ------------------------------------------------------------------ */

	/**
	 * Enroll a learner in a course *outside* the plugin.
	 *
	 * This is how the suite represents a direct course purchase or an admin
	 * grant: a real Tutor enrollment with no `_tcb_origin_access_id` meta. The
	 * access resolver must never revoke one of these.
	 *
	 * @param int $course_id Course post ID.
	 * @param int $user_id   User ID.
	 */
	public function direct_enrollment( int $course_id, int $user_id ): int {
		$adapter       = new TutorAdapter();
		$enrollment_id = $adapter->enroll( $course_id, $user_id );

		if ( is_wp_error( $enrollment_id ) ) {
			throw new \RuntimeException( 'Seeder could not enroll directly: ' . $enrollment_id->get_error_message() );
		}

		// Strip any provenance so the enrollment looks like it came from elsewhere.
		delete_post_meta( (int) $enrollment_id, CourseAccessResolver::ORIGIN_META );
		delete_post_meta( (int) $enrollment_id, '_tcb_origin_bundle_id' );

		return (int) $enrollment_id;
	}

	/**
	 * Mark a number of a course's lessons complete for a learner.
	 *
	 * @param int $course_id Course post ID.
	 * @param int $user_id   User ID.
	 * @param int $count     How many lessons, -1 for all.
	 * @return int Lessons actually completed.
	 */
	public function complete_lessons( int $course_id, int $user_id, int $count = -1 ): int {
		if ( ! function_exists( 'tutor_utils' ) ) {
			return 0;
		}

		$lessons = $this->get_lessons( $course_id );

		if ( $count >= 0 ) {
			$lessons = array_slice( $lessons, 0, $count );
		}

		$done = 0;

		foreach ( $lessons as $lesson_id ) {
			if ( method_exists( tutor_utils(), 'mark_lesson_complete' ) ) {
				tutor_utils()->mark_lesson_complete( $lesson_id, $user_id );
				++$done;
			} elseif ( class_exists( '\Tutor\Models\LessonModel' ) && method_exists( '\Tutor\Models\LessonModel', 'mark_lesson_complete' ) ) {
				\Tutor\Models\LessonModel::mark_lesson_complete( $lesson_id, $user_id );
				++$done;
			}
		}

		$this->reset_tutor_runtime_cache();
		\SpaceWork\TutorCourseBundles\Support\Cache::flush_user_progress( $user_id );

		return $done;
	}

	/**
	 * Complete every lesson in a course and mark the course itself complete.
	 *
	 * @param int $course_id Course post ID.
	 * @param int $user_id   User ID.
	 */
	public function complete_course( int $course_id, int $user_id ): void {
		$this->complete_lessons( $course_id, $user_id, -1 );

		if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'mark_course_complete' ) ) {
			tutor_utils()->mark_course_complete( $course_id, $user_id );
		} elseif ( class_exists( '\Tutor\Models\CourseModel' ) && method_exists( '\Tutor\Models\CourseModel', 'mark_course_as_completed' ) ) {
			\Tutor\Models\CourseModel::mark_course_as_completed( $course_id, $user_id );
		}

		$this->reset_tutor_runtime_cache();
		\SpaceWork\TutorCourseBundles\Support\Cache::flush_user_progress( $user_id );
	}

	/**
	 * Tutor's request-local cache has no public invalidation API. Integration
	 * scenarios perform several browser requests in one PHP process, so reset
	 * it after simulated completion events to match real request boundaries.
	 */
	private function reset_tutor_runtime_cache(): void {
		if ( ! class_exists( '\Tutor\Cache\TutorCache' ) ) {
			return;
		}

		$reflection = new \ReflectionClass( '\Tutor\Cache\TutorCache' );
		$instance   = $reflection->getProperty( 'instance' );
		$instance->setValue( null, null );
	}

	/* ---------------------------------------------------------------------
	 * WooCommerce
	 * ------------------------------------------------------------------ */

	/**
	 * Create the WooCommerce product for a bundle.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @return int Product ID, 0 when WooCommerce is unavailable.
	 */
	public function wc_product( int $bundle_id ): int {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return 0;
		}

		$gateway = new WooCommerceGateway( $this->repository(), new AccessRepository() );
		$result  = $gateway->sync_product( $bundle_id );

		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( 'Seeder could not create a product: ' . $result->get_error_message() );
		}

		$this->created_posts[] = (int) $result;

		return (int) $result;
	}

	/**
	 * Create a WooCommerce order containing a bundle.
	 *
	 * @param int    $bundle_id  Bundle post ID.
	 * @param int    $user_id    Customer user ID.
	 * @param string $status     Initial order status, e.g. "pending".
	 * @param bool   $stamp_meta Whether to write the immutable bundle item meta
	 *                           that a real checkout would add. Pass false to
	 *                           simulate a legacy order.
	 * @return int Order ID, 0 when WooCommerce is unavailable.
	 */
	public function wc_order( int $bundle_id, int $user_id, string $status = 'pending', bool $stamp_meta = true ): int {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return 0;
		}

		$product_id = $this->wc_product( $bundle_id );

		if ( $product_id <= 0 ) {
			return 0;
		}

		$order = wc_create_order( array( 'customer_id' => $user_id ) );

		if ( is_wp_error( $order ) ) {
			throw new \RuntimeException( 'Seeder could not create an order: ' . $order->get_error_message() );
		}

		$product = wc_get_product( $product_id );
		$item_id = $order->add_product( $product, 1 );

		if ( $stamp_meta && $item_id ) {
			// `add_product()` bypasses the checkout hook that normally stamps
			// this, so mirror what a real purchase would record.
			wc_add_order_item_meta( $item_id, WooCommerceGateway::ORDER_ITEM_META, $bundle_id, true );
		}

		$order->calculate_totals();
		$order->set_status( $status );
		$order->save();

		$order_id              = (int) $order->get_id();
		$this->created_posts[] = $order_id;

		return $order_id;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Bundle repository instance.
	 */
	public function repository(): BundleRepository {
		return tcb()->get( BundleRepository::class );
	}

	/**
	 * Delete everything this seeder created.
	 */
	public function cleanup(): void {
		global $wpdb;

		$previous_suppress = $wpdb->suppress_errors( true );

		foreach ( array_reverse( $this->created_posts ) as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		foreach ( $this->created_users as $user_id ) {
			if ( function_exists( 'wp_delete_user' ) ) {
				wp_delete_user( $user_id );
			}
		}

		$this->created_posts = array();
		$this->created_users = array();

		$wpdb->suppress_errors( $previous_suppress );
	}
}
