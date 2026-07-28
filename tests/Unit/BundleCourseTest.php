<?php
/**
 * Unit tests for the bundle → course membership row.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Unit;

use Brain\Monkey\Functions;
use SpaceWork\TutorCourseBundles\Domain\BundleCourse;

/**
 * @covers \SpaceWork\TutorCourseBundles\Domain\BundleCourse
 */
final class BundleCourseTest extends UnitTestCase {

	/**
	 * Make get_post() return a course in a given state, or nothing at all.
	 *
	 * @param string|null $status Post status, or null for a deleted course.
	 */
	private function givenCourse( ?string $status ): void {
		if ( null === $status ) {
			Functions\when( 'get_post' )->justReturn( null );
			Functions\when( 'get_the_title' )->justReturn( '' );
			Functions\when( 'get_permalink' )->justReturn( '' );
			Functions\when( 'get_the_post_thumbnail_url' )->justReturn( '' );

			return;
		}

		$post = new \WP_Post(
			array(
				'ID'          => 101,
				'post_type'   => 'courses',
				'post_status' => $status,
				'post_title'  => 'JavaScript Basics',
			)
		);

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'get_the_title' )->justReturn( 'JavaScript Basics' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/courses/js/' );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( 'https://example.test/thumb.jpg' );
	}

	/**
	 * A course only counts as available when it exists *and* is published.
	 * Draft courses stay in the bundle — an admin may be mid-edit — but they
	 * must not be shown to learners as if they were ready.
	 *
	 * @dataProvider provideCourseStates
	 *
	 * @param string|null $status    Post status, null for deleted.
	 * @param bool        $exists    Expected exists().
	 * @param bool        $available Expected is_available().
	 */
	public function test_availability( ?string $status, bool $exists, bool $available ): void {
		$this->givenCourse( $status );

		$course = new BundleCourse( 1, 10, 101 );

		$this->assertSame( $exists, $course->exists() );
		$this->assertSame( $available, $course->is_available() );
	}

	/**
	 * Course state cases.
	 *
	 * @return array<string, array{0: string|null, 1: bool, 2: bool}>
	 */
	public static function provideCourseStates(): array {
		return array(
			'published' => array( 'publish', true, true ),
			'draft'     => array( 'draft', true, false ),
			'pending'   => array( 'pending', true, false ),
			'private'   => array( 'private', true, false ),
			'trashed'   => array( 'trash', true, false ),
			'deleted'   => array( null, false, false ),
		);
	}

	/**
	 * A deleted course still has a row, so the admin screen needs something
	 * readable rather than a blank line.
	 */
	public function test_missing_course_gets_a_placeholder_title(): void {
		$this->givenCourse( null );

		$course = new BundleCourse( 1, 10, 404 );

		$this->assertStringContainsString( '404', $course->get_title() );
		$this->assertSame( 'missing', $course->to_array()['status'] );
		$this->assertFalse( $course->to_array()['exists'] );
	}

	/**
	 * Rows arrive from the database as strings; the object must hand back real
	 * types so `===` comparisons downstream behave.
	 */
	public function test_from_row_casts_types(): void {
		$this->givenCourse( 'publish' );

		$course = BundleCourse::from_row(
			array(
				'id'                         => '7',
				'bundle_id'                  => '10',
				'course_id'                  => '101',
				'position'                   => '3',
				'is_required'                => '0',
				'unlock_mode'                => BundleCourse::UNLOCK_AFTER_DAYS,
				'unlock_reference_course_id' => '0',
				'unlock_delay_days'          => '14',
			)
		);

		$this->assertSame( 7, $course->id );
		$this->assertSame( 10, $course->bundle_id );
		$this->assertSame( 101, $course->course_id );
		$this->assertSame( 3, $course->position );
		$this->assertFalse( $course->is_required );
		$this->assertSame( 14, $course->unlock_delay_days );
		$this->assertNull( $course->unlock_reference_course_id, 'A zero reference course must normalise to null.' );
	}

	/**
	 * Defaults keep a bare row usable: required, unlocked immediately.
	 */
	public function test_defaults_are_immediate_and_required(): void {
		$this->givenCourse( 'publish' );

		$course = BundleCourse::from_row(
			array(
				'id'        => 1,
				'bundle_id' => 10,
				'course_id' => 101,
			)
		);

		$this->assertTrue( $course->is_required );
		$this->assertSame( BundleCourse::UNLOCK_IMMEDIATE, $course->unlock_mode );
		$this->assertSame( 0, $course->position );
		$this->assertSame( 0, $course->unlock_delay_days );
	}

	/**
	 * The array form feeds both the REST API and the admin JS, so its keys are
	 * a contract.
	 */
	public function test_to_array_shape(): void {
		$this->givenCourse( 'publish' );

		$data = ( new BundleCourse( 1, 10, 101, 2 ) )->to_array();

		foreach ( array( 'id', 'bundle_id', 'course_id', 'position', 'is_required', 'unlock_mode', 'title', 'permalink', 'thumbnail', 'status', 'exists', 'available' ) as $key ) {
			$this->assertArrayHasKey( $key, $data, "to_array() is missing '{$key}'." );
		}

		$this->assertTrue( $data['available'] );
		$this->assertSame( 'publish', $data['status'] );
	}
}
