<?php
/**
 * Unit tests for the progress value object.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Unit;

use SpaceWork\TutorCourseBundles\Domain\BundleProgress;

/**
 * @covers \SpaceWork\TutorCourseBundles\Domain\BundleProgress
 */
final class BundleProgressTest extends UnitTestCase {

	/**
	 * Learners see a whole number; a bundle that is 99.6% done must not read
	 * as "100%" while a course is still open, and 0.4% must not read as "0%"
	 * either — rounding is the compromise, so pin it down.
	 *
	 * @dataProvider providePercentages
	 *
	 * @param float $percent  Raw percentage.
	 * @param int   $expected Displayed percentage.
	 */
	public function test_display_percent_rounds_to_the_nearest_whole( float $percent, int $expected ): void {
		$progress = new BundleProgress( 1, 2, $percent );

		$this->assertSame( $expected, $progress->get_display_percent() );
	}

	/**
	 * Rounding cases.
	 *
	 * @return array<string, array{0: float, 1: int}>
	 */
	public static function providePercentages(): array {
		return array(
			'zero'          => array( 0.0, 0 ),
			'just above 0'  => array( 0.4, 0 ),
			'a third'       => array( 33.3333, 33 ),
			'half'          => array( 50.0, 50 ),
			'two thirds'    => array( 66.6667, 67 ),
			'almost done'   => array( 99.6, 100 ),
			'complete'      => array( 100.0, 100 ),
		);
	}

	/**
	 * Status drives the badge learners see, and `is_complete()` gates the
	 * completion hook, so only the completed status may return true.
	 *
	 * @dataProvider provideStatuses
	 *
	 * @param string $status   Status constant.
	 * @param bool   $complete Whether it counts as complete.
	 */
	public function test_only_completed_status_reports_completion( string $status, bool $complete ): void {
		$progress = new BundleProgress( 1, 2, 100.0, 3, 3, $status );

		$this->assertSame( $complete, $progress->is_complete() );
		$this->assertNotSame( '', $progress->get_status_label() );
	}

	/**
	 * Status cases.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function provideStatuses(): array {
		return array(
			'not started' => array( BundleProgress::STATUS_NOT_STARTED, false ),
			'in progress' => array( BundleProgress::STATUS_IN_PROGRESS, false ),
			'completed'   => array( BundleProgress::STATUS_COMPLETED, true ),
			'expired'     => array( BundleProgress::STATUS_EXPIRED, false ),
			'revoked'     => array( BundleProgress::STATUS_REVOKED, false ),
		);
	}

	/**
	 * The array form is cached and re-hydrated by ProgressController, so every
	 * field it needs has to survive the round trip.
	 */
	public function test_to_array_carries_everything_needed_to_rehydrate(): void {
		$courses = array(
			array( 'course_id' => 11, 'percent' => 100.0, 'completed' => true ),
			array( 'course_id' => 12, 'percent' => 25.0, 'completed' => false ),
		);

		$progress = new BundleProgress( 5, 9, 62.5, 1, 2, BundleProgress::STATUS_IN_PROGRESS, $courses, 12 );
		$data     = $progress->to_array();

		$this->assertSame( 5, $data['bundle_id'] );
		$this->assertSame( 9, $data['user_id'] );
		$this->assertSame( 62.5, $data['percent'] );
		$this->assertSame( 63, $data['display_percent'] );
		$this->assertSame( 1, $data['completed_count'] );
		$this->assertSame( 2, $data['total_count'] );
		$this->assertSame( BundleProgress::STATUS_IN_PROGRESS, $data['status'] );
		$this->assertSame( 12, $data['next_course_id'] );
		$this->assertCount( 2, $data['courses'] );
	}

	/**
	 * An empty bundle should not blow up or claim completion.
	 */
	public function test_empty_progress_is_safe(): void {
		$progress = new BundleProgress( 1, 2 );

		$this->assertSame( 0, $progress->get_display_percent() );
		$this->assertSame( 0, $progress->total_count );
		$this->assertFalse( $progress->is_complete() );
		$this->assertNull( $progress->next_course_id );
		$this->assertSame( array(), $progress->courses );
	}

	/**
	 * Percentages are rounded to two decimals in the array form so cached JSON
	 * does not drift with floating point noise.
	 */
	public function test_percent_is_rounded_in_array_form(): void {
		$progress = new BundleProgress( 1, 2, 33.333333333 );

		$this->assertSame( 33.33, $progress->to_array()['percent'] );
	}
}
