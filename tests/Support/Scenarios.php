<?php
/**
 * Named seeded worlds shared across integration tests.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Support;

use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Tutor\CompletionService;
use SpaceWork\TutorCourseBundles\Tutor\EnrollmentService;

/**
 * Each method here builds one recognisable situation from the real world and
 * hands back the IDs a test needs to assert against.
 *
 * Keeping them in one place means a scenario used by six tests is defined once,
 * and when the plugin's behaviour changes there is exactly one seed to update.
 */
final class Scenarios {

	/**
	 * Constructor.
	 *
	 * @param Seeder $seeder Seeder instance.
	 */
	public function __construct( private readonly Seeder $seeder ) {}

	/**
	 * Enrollment engine.
	 */
	private function enrollments(): EnrollmentService {
		return tcb()->get( EnrollmentService::class );
	}

	/**
	 * Two bundles that share a course, both owned by the same learner.
	 *
	 * The classic double-grant: refunding one must not cost the learner the
	 * shared course, because the other bundle still covers it.
	 *
	 * @return array{user:int, shared_course:int, only_a:int, only_b:int, bundle_a:int, bundle_b:int, access_a:int, access_b:int}
	 */
	public function overlapping_bundles(): array {
		$user   = $this->seeder->student();
		$shared = $this->seeder->course( array( 'title' => 'Shared: JavaScript', 'lessons' => 2 ) );
		$only_a = $this->seeder->course( array( 'title' => 'Only A: HTML', 'lessons' => 2 ) );
		$only_b = $this->seeder->course( array( 'title' => 'Only B: React', 'lessons' => 2 ) );

		$bundle_a = $this->seeder->bundle(
			array(
				'title'   => 'Frontend Basics',
				'courses' => array( $only_a, $shared ),
			)
		);

		$bundle_b = $this->seeder->bundle(
			array(
				'title'   => 'Frontend Advanced',
				'courses' => array( $shared, $only_b ),
			)
		);

		$access_a = $this->enrollments()->grant_access( $bundle_a, $user, BundleAccess::SOURCE_MANUAL, 0 );
		$access_b = $this->enrollments()->grant_access( $bundle_b, $user, BundleAccess::SOURCE_MANUAL, 0 );

		return array(
			'user'          => $user,
			'shared_course' => $shared,
			'only_a'        => $only_a,
			'only_b'        => $only_b,
			'bundle_a'      => $bundle_a,
			'bundle_b'      => $bundle_b,
			'access_a'      => is_wp_error( $access_a ) ? 0 : $access_a->id,
			'access_b'      => is_wp_error( $access_b ) ? 0 : $access_b->id,
		);
	}

	/**
	 * A learner who bought one course directly, then bought a bundle that
	 * happens to contain it.
	 *
	 * Refunding the bundle must leave the directly purchased course alone.
	 *
	 * @return array{user:int, direct_course:int, bundle_course:int, bundle:int, access_id:int}
	 */
	public function direct_purchase_plus_bundle(): array {
		$user   = $this->seeder->student();
		$direct = $this->seeder->course( array( 'title' => 'Bought directly', 'lessons' => 2 ) );
		$extra  = $this->seeder->course( array( 'title' => 'Bundle only', 'lessons' => 2 ) );

		// The learner already owns the course before the bundle exists.
		$this->seeder->direct_enrollment( $direct, $user );

		$bundle = $this->seeder->bundle(
			array(
				'title'   => 'Overlapping bundle',
				'courses' => array( $direct, $extra ),
			)
		);

		$access = $this->enrollments()->grant_access( $bundle, $user, BundleAccess::SOURCE_MANUAL, 0 );

		return array(
			'user'          => $user,
			'direct_course' => $direct,
			'bundle_course' => $extra,
			'bundle'        => $bundle,
			'access_id'     => is_wp_error( $access ) ? 0 : $access->id,
		);
	}

	/**
	 * A paid bundle with a WooCommerce order sitting at "pending".
	 *
	 * @param string $status Initial order status.
	 * @return array{user:int, bundle:int, courses:int[], product:int, order:int}
	 */
	public function woocommerce_purchase( string $status = 'pending' ): array {
		$user  = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses(
			3,
			array(
				'title'       => 'Paid bundle',
				'access_type' => Bundle::ACCESS_TYPE_PAID,
				'price'       => 149.00,
			),
			array( 'lessons' => 2 )
		);

		$product = $this->seeder->wc_product( $built['bundle'] );
		$order   = $this->seeder->wc_order( $built['bundle'], $user, $status );

		return array(
			'user'    => $user,
			'bundle'  => $built['bundle'],
			'courses' => $built['courses'],
			'product' => $product,
			'order'   => $order,
		);
	}

	/**
	 * A bundle big enough to trip the background-processing threshold.
	 *
	 * @param int $course_count How many courses.
	 * @return array{user:int, bundle:int, courses:int[]}
	 */
	public function large_bundle( int $course_count = 25 ): array {
		$user  = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses(
			$course_count,
			array( 'title' => 'Large bundle' )
		);

		return array(
			'user'    => $user,
			'bundle'  => $built['bundle'],
			'courses' => $built['courses'],
		);
	}

	/**
	 * A bundle whose contents have rotted: one course deleted, one unpublished.
	 *
	 * @return array{bundle:int, ok:int, draft:int, deleted:int}
	 */
	public function bundle_with_broken_courses(): array {
		$ok      = $this->seeder->course( array( 'title' => 'Healthy course' ) );
		$draft   = $this->seeder->course( array( 'title' => 'Draft course', 'status' => 'draft' ) );
		$deleted = $this->seeder->course( array( 'title' => 'Doomed course' ) );

		$bundle = $this->seeder->bundle(
			array(
				'title'   => 'Rotting bundle',
				'courses' => array( $ok, $draft, $deleted ),
			)
		);

		wp_delete_post( $deleted, true );

		return array(
			'bundle'  => $bundle,
			'ok'      => $ok,
			'draft'   => $draft,
			'deleted' => $deleted,
		);
	}

	/**
	 * A learner partway through a three-course bundle: one finished, one half
	 * done, one untouched. Expected average progress is 50%.
	 *
	 * @return array{user:int, bundle:int, done:int, half:int, untouched:int, access_id:int}
	 */
	public function partial_progress(): array {
		$user      = $this->seeder->student();
		$done      = $this->seeder->course( array( 'title' => 'Finished', 'lessons' => 4 ) );
		$half      = $this->seeder->course( array( 'title' => 'Half done', 'lessons' => 4 ) );
		$untouched = $this->seeder->course( array( 'title' => 'Not started', 'lessons' => 4 ) );

		$bundle = $this->seeder->bundle(
			array(
				'title'   => 'Progress bundle',
				'courses' => array( $done, $half, $untouched ),
			)
		);

		$access = $this->enrollments()->grant_access( $bundle, $user, BundleAccess::SOURCE_MANUAL, 0 );

		$this->seeder->complete_course( $done, $user );
		$this->seeder->complete_lessons( $half, $user, 2 );

		return array(
			'user'      => $user,
			'bundle'    => $bundle,
			'done'      => $done,
			'half'      => $half,
			'untouched' => $untouched,
			'access_id' => is_wp_error( $access ) ? 0 : $access->id,
		);
	}

	/**
	 * A bundle mixing required and optional courses, with the optional one
	 * deliberately left unfinished.
	 *
	 * @return array{user:int, bundle:int, required:int[], optional:int, access_id:int}
	 */
	public function required_and_optional(): array {
		$user      = $this->seeder->student();
		$required1 = $this->seeder->course( array( 'title' => 'Required 1', 'lessons' => 2 ) );
		$required2 = $this->seeder->course( array( 'title' => 'Required 2', 'lessons' => 2 ) );
		$optional  = $this->seeder->course( array( 'title' => 'Optional extra', 'lessons' => 2 ) );

		$bundle = $this->seeder->bundle(
			array(
				'title'            => 'Mixed bundle',
				'courses'          => array( $required1, $required2 ),
				'optional_courses' => array( $optional ),
			)
		);

		$access = $this->enrollments()->grant_access( $bundle, $user, BundleAccess::SOURCE_MANUAL, 0 );

		$this->seeder->complete_course( $required1, $user );
		$this->seeder->complete_course( $required2, $user );
		tcb()->get( CompletionService::class )->reset_completion( $bundle, $user );

		return array(
			'user'      => $user,
			'bundle'    => $bundle,
			'required'  => array( $required1, $required2 ),
			'optional'  => $optional,
			'access_id' => is_wp_error( $access ) ? 0 : $access->id,
		);
	}

	/**
	 * A catalogue of bundles built from a JSON fixture file.
	 *
	 * Fixtures let the same assertions run across many bundle shapes without a
	 * new PHP method per shape.
	 *
	 * @param string $fixture Fixture file name without extension.
	 * @return array<string, array{bundle:int, courses:int[], spec:array<string, mixed>}>
	 */
	public function from_fixture( string $fixture ): array {
		$specs = self::load_fixture( $fixture );
		$built = array();

		foreach ( $specs as $key => $spec ) {
			$courses = array();

			foreach ( (array) ( $spec['courses'] ?? array() ) as $course_spec ) {
				$courses[] = $this->seeder->course(
					array(
						'title'   => (string) ( $course_spec['title'] ?? 'Course' ),
						'status'  => (string) ( $course_spec['status'] ?? 'publish' ),
						'lessons' => (int) ( $course_spec['lessons'] ?? 0 ),
					)
				);
			}

			$required = array();
			$optional = array();

			foreach ( (array) ( $spec['courses'] ?? array() ) as $index => $course_spec ) {
				if ( false === ( $course_spec['required'] ?? true ) ) {
					$optional[] = $courses[ $index ];
				} else {
					$required[] = $courses[ $index ];
				}
			}

			$bundle_id = $this->seeder->bundle(
				array(
					'title'            => (string) ( $spec['title'] ?? $key ),
					'status'           => (string) ( $spec['status'] ?? 'publish' ),
					'access_type'      => (string) ( $spec['access_type'] ?? Bundle::ACCESS_TYPE_FREE ),
					'price'            => (float) ( $spec['price'] ?? 0 ),
					'sale_price'       => isset( $spec['sale_price'] ) ? (float) $spec['sale_price'] : null,
					'access_duration'  => (int) ( $spec['access_duration'] ?? 0 ),
					'completion_mode'  => (string) ( $spec['completion_mode'] ?? Bundle::COMPLETION_ALL_REQUIRED ),
					'courses'          => $required,
					'optional_courses' => $optional,
				)
			);

			$built[ $key ] = array(
				'bundle'  => $bundle_id,
				'courses' => $courses,
				'spec'    => $spec,
			);
		}

		return $built;
	}

	/**
	 * Read a fixture file.
	 *
	 * @param string $fixture Fixture file name without extension.
	 * @return array<string, array<string, mixed>>
	 */
	public static function load_fixture( string $fixture ): array {
		$path = dirname( __DIR__ ) . '/fixtures/' . $fixture . '.json';

		if ( ! is_readable( $path ) ) {
			throw new \RuntimeException( "Fixture not found: {$path}" );
		}

		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( "Fixture is not valid JSON: {$path}" );
		}

		return $data;
	}
}
