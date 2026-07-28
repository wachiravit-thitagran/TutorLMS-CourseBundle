<?php
/**
 * Aggregated progress and completion.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Integration;

use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Domain\BundleProgress;
use SpaceWork\TutorCourseBundles\Frontend\ProgressController;
use SpaceWork\TutorCourseBundles\Support\Cache;
use SpaceWork\TutorCourseBundles\Tests\Support\TestCase;
use SpaceWork\TutorCourseBundles\Tutor\CompletionService;
use SpaceWork\TutorCourseBundles\Tutor\EnrollmentService;

/**
 * Progress is computed from real Tutor lesson completions, not stubs, so these
 * tests also act as a canary for upstream API changes.
 *
 * @covers \SpaceWork\TutorCourseBundles\Frontend\ProgressController
 * @covers \SpaceWork\TutorCourseBundles\Tutor\CompletionService
 */
final class ProgressTest extends TestCase {

	private ProgressController $progress;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->require_tutor();

		$this->progress = tcb()->get( ProgressController::class );
	}

	/**
	 * Acceptance criterion 7, with the arithmetic pinned: one course finished,
	 * one half done, one untouched averages to 50%.
	 */
	public function test_average_progress_across_courses(): void {
		$world = $this->scenarios->partial_progress();

		Cache::flush_user_progress( $world['user'] );

		$progress = $this->progress->get_progress( $world['bundle'], $world['user'] );

		$this->assertSame( 3, $progress->total_count );
		$this->assertSame( 1, $progress->completed_count );
		$this->assertEqualsWithDelta( 50.0, $progress->percent, 1.0, 'Expected roughly (100 + 50 + 0) / 3.' );
		$this->assertSame( BundleProgress::STATUS_IN_PROGRESS, $progress->status );
	}

	/**
	 * A learner who has done nothing sits at zero and "not started", not at
	 * "in progress" — the badge is the first thing they see.
	 */
	public function test_untouched_bundle_reports_not_started(): void {
		$user  = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses( 3, array(), array( 'lessons' => 2 ) );

		tcb()->get( EnrollmentService::class )
			->grant_access( $built['bundle'], $user, BundleAccess::SOURCE_MANUAL, 0 );

		$progress = $this->progress->get_progress( $built['bundle'], $user );

		$this->assertSame( 0.0, $progress->percent );
		$this->assertSame( BundleProgress::STATUS_NOT_STARTED, $progress->status );
		$this->assertSame( $built['courses'][0], $progress->next_course_id, 'The first course is the obvious next step.' );
	}

	/**
	 * Finishing everything required flips the bundle to completed and fires the
	 * public hook exactly once.
	 */
	public function test_completing_every_required_course_completes_the_bundle(): void {
		$world = $this->scenarios->required_and_optional();

		$fired = array();
		add_action(
			'tcb/bundle/completed',
			static function ( $user_id, $bundle_id ) use ( &$fired ): void {
				$fired[] = array( $user_id, $bundle_id );
			},
			10,
			2
		);

		$completion = tcb()->get( CompletionService::class );

		$this->assertTrue(
			$completion->evaluate_bundle( $world['bundle'], $world['user'] ),
			'All required courses are done, so the bundle is complete.'
		);

		// A second evaluation must not fire the hook again.
		$completion->evaluate_bundle( $world['bundle'], $world['user'] );

		$this->assertCount( 1, $fired, 'The completion hook must fire once per learner per bundle.' );
		$this->assertSame( array( $world['user'], $world['bundle'] ), $fired[0] );
	}

	/**
	 * An unfinished optional course must not hold completion back — that is the
	 * whole point of marking it optional.
	 */
	public function test_optional_courses_do_not_block_completion(): void {
		$world = $this->scenarios->required_and_optional();

		$adapter = new \SpaceWork\TutorCourseBundles\Tutor\TutorAdapter();

		$this->assertFalse(
			$adapter->is_course_completed( $world['optional'], $world['user'] ),
			'The optional course is deliberately left unfinished.'
		);

		$this->assertTrue(
			tcb()->get( CompletionService::class )->evaluate_bundle( $world['bundle'], $world['user'] )
		);
	}

	/**
	 * Under the stricter completion mode the same optional course does block.
	 */
	public function test_all_courses_mode_requires_the_optional_one_too(): void {
		$world = $this->scenarios->required_and_optional();

		update_post_meta( $world['bundle'], Bundle::META_COMPLETION_MODE, Bundle::COMPLETION_ALL_COURSES );

		$completion = tcb()->get( CompletionService::class );
		$completion->reset_completion( $world['bundle'], $world['user'] );

		$this->assertFalse(
			$completion->evaluate_bundle( $world['bundle'], $world['user'] ),
			'With "every course" selected, the optional course counts.'
		);

		$this->seeder->complete_course( $world['optional'], $world['user'] );

		$this->assertTrue( $completion->evaluate_bundle( $world['bundle'], $world['user'] ) );
	}

	/**
	 * A learner without access has no progress to report, however much of the
	 * content they may have seen elsewhere.
	 */
	public function test_completion_requires_access(): void {
		$user  = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses( 1, array(), array( 'lessons' => 2 ) );

		$this->seeder->complete_course( $built['courses'][0], $user );

		$this->assertFalse(
			tcb()->get( CompletionService::class )->evaluate_bundle( $built['bundle'], $user ),
			'Completion should not be recorded for someone who does not hold the bundle.'
		);
	}

	/**
	 * The weighted mode gives longer courses more say. A 2-lesson course fully
	 * done next to an untouched 8-lesson course is 20% weighted, but 50% on a
	 * plain average — the two modes must actually differ.
	 */
	public function test_weighted_mode_favours_larger_courses(): void {
		$user  = $this->seeder->student();
		$small = $this->seeder->course( array( 'title' => 'Short course', 'lessons' => 2 ) );
		$large = $this->seeder->course( array( 'title' => 'Long course', 'lessons' => 8 ) );

		$bundle = $this->seeder->bundle( array( 'courses' => array( $small, $large ) ) );

		tcb()->get( EnrollmentService::class )
			->grant_access( $bundle, $user, BundleAccess::SOURCE_MANUAL, 0 );

		$this->seeder->complete_course( $small, $user );

		update_option( 'tcb_progress_mode', ProgressController::MODE_AVERAGE );
		Cache::flush_user_progress( $user );
		$average = $this->progress->get_progress( $bundle, $user )->percent;

		update_option( 'tcb_progress_mode', ProgressController::MODE_WEIGHTED );
		Cache::flush_user_progress( $user );
		$weighted = $this->progress->get_progress( $bundle, $user )->percent;

		update_option( 'tcb_progress_mode', ProgressController::MODE_AVERAGE );

		$this->assertEqualsWithDelta( 50.0, $average, 1.0 );
		$this->assertLessThan(
			$average,
			$weighted,
			'Weighted progress should be lower when the finished course is the small one.'
		);
	}

	/**
	 * "Continue learning" should point at the first unfinished course a learner
	 * is actually enrolled in.
	 */
	public function test_next_course_skips_finished_work(): void {
		$world = $this->scenarios->partial_progress();

		Cache::flush_user_progress( $world['user'] );

		$progress = $this->progress->get_progress( $world['bundle'], $world['user'] );

		$this->assertSame(
			$world['half'],
			$progress->next_course_id,
			'The half-finished course is where the learner left off.'
		);
	}

	/**
	 * Cached progress must not outlive the lesson completion that invalidates
	 * it, or learners see a stale bar after finishing something.
	 */
	public function test_progress_cache_is_invalidated_by_new_completions(): void {
		$user  = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses( 2, array(), array( 'lessons' => 4 ) );

		tcb()->get( EnrollmentService::class )
			->grant_access( $built['bundle'], $user, BundleAccess::SOURCE_MANUAL, 0 );

		$before = $this->progress->get_progress( $built['bundle'], $user )->percent;

		$this->seeder->complete_course( $built['courses'][0], $user );

		$after = $this->progress->get_progress( $built['bundle'], $user )->percent;

		$this->assertSame( 0.0, $before );
		$this->assertGreaterThan( $before, $after, 'Finishing a course must move the bar.' );
	}

	/**
	 * The per-course breakdown is what the template renders, so it has to carry
	 * every field the list needs.
	 */
	public function test_breakdown_describes_each_course(): void {
		$world = $this->scenarios->partial_progress();

		Cache::flush_user_progress( $world['user'] );

		$progress = $this->progress->get_progress( $world['bundle'], $world['user'] );

		$this->assertCount( 3, $progress->courses );

		foreach ( $progress->courses as $entry ) {
			foreach ( array( 'course_id', 'title', 'permalink', 'continue_url', 'percent', 'completed', 'enrolled', 'required', 'position' ) as $key ) {
				$this->assertArrayHasKey( $key, $entry, "Breakdown entry is missing '{$key}'." );
			}
		}

		$by_id = array_column( $progress->courses, null, 'course_id' );

		$this->assertTrue( $by_id[ $world['done'] ]['completed'] );
		$this->assertFalse( $by_id[ $world['untouched'] ]['completed'] );
	}

	/**
	 * An empty bundle reports zero rather than dividing by nothing.
	 */
	public function test_empty_bundle_progress_is_zero(): void {
		$user   = $this->seeder->student();
		$bundle = $this->seeder->bundle();

		$progress = $this->progress->get_progress( $bundle, $user );

		$this->assertSame( 0.0, $progress->percent );
		$this->assertSame( 0, $progress->total_count );
		$this->assertFalse( $progress->is_complete() );
	}

	/**
	 * Logged-out visitors get an empty progress object, not a fatal error.
	 */
	public function test_anonymous_visitors_have_no_progress(): void {
		$built = $this->seeder->bundle_with_courses( 2 );

		$progress = $this->progress->get_progress( $built['bundle'], 0 );

		$this->assertSame( 0.0, $progress->percent );
		$this->assertSame( 0, $progress->user_id );
	}
}
