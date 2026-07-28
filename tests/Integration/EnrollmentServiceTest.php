<?php
/**
 * Granting, batching, expiry and repair.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Integration;

use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Infrastructure\AccessRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\EnrollmentRepository;
use SpaceWork\TutorCourseBundles\Tests\Support\TestCase;
use SpaceWork\TutorCourseBundles\Tutor\EnrollmentService;

/**
 * @covers \SpaceWork\TutorCourseBundles\Tutor\EnrollmentService
 */
final class EnrollmentServiceTest extends TestCase {

	private EnrollmentService $enrollments;
	private AccessRepository $access;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->require_tutor();

		$this->enrollments = tcb()->get( EnrollmentService::class );
		$this->access      = tcb()->get( AccessRepository::class );
	}

	/**
	 * Acceptance criterion 4: paying for a bundle enrolls the learner in
	 * everything inside it.
	 */
	public function test_granting_enrolls_the_learner_in_every_course(): void {
		$user  = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses( 4 );

		$access = $this->enrollments->grant_access( $built['bundle'], $user, BundleAccess::SOURCE_MANUAL, 0 );

		$this->assertNotWPError( $access );
		$this->assertSame( BundleAccess::STATUS_ACTIVE, $access->status );

		foreach ( $built['courses'] as $course_id ) {
			$this->assertEnrolled( $course_id, $user );
		}

		$this->assertSame( 4, $this->countRows( 'enrollments', array( 'bundle_access_id' => $access->id ) ) );
	}

	/**
	 * Acceptance criterion 5: the same grant arriving twice — a retried webhook,
	 * a double-clicked button — must not produce a second entitlement or a
	 * second enrollment.
	 */
	public function test_granting_twice_from_the_same_source_is_idempotent(): void {
		$user  = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses( 3 );

		$first  = $this->enrollments->grant_access( $built['bundle'], $user, BundleAccess::SOURCE_WOOCOMMERCE, 4242 );
		$second = $this->enrollments->grant_access( $built['bundle'], $user, BundleAccess::SOURCE_WOOCOMMERCE, 4242 );

		$this->assertNotWPError( $first );
		$this->assertNotWPError( $second );
		$this->assertSame( $first->id, $second->id, 'The same order must resolve to the same entitlement.' );

		$this->assertSame( 1, $this->countRows( 'access', array( 'bundle_id' => $built['bundle'], 'user_id' => $user ) ) );
		$this->assertSame( 3, $this->countRows( 'enrollments', array( 'bundle_access_id' => $first->id ) ) );
	}

	/**
	 * Two different orders are two different entitlements, even for the same
	 * learner and bundle — a renewal must not overwrite the original record.
	 */
	public function test_different_sources_create_separate_entitlements(): void {
		$user  = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses( 1 );

		$first  = $this->enrollments->grant_access( $built['bundle'], $user, BundleAccess::SOURCE_WOOCOMMERCE, 1 );
		$second = $this->enrollments->grant_access( $built['bundle'], $user, BundleAccess::SOURCE_WOOCOMMERCE, 2 );

		$this->assertNotSame( $first->id, $second->id );
		$this->assertSame( 2, $this->countRows( 'access', array( 'bundle_id' => $built['bundle'], 'user_id' => $user ) ) );
	}

	/**
	 * Re-granting after a refund reactivates the same ledger row rather than
	 * piling up dead history for one order.
	 */
	public function test_regranting_reactivates_a_terminated_entitlement(): void {
		$user  = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses( 2 );

		$access = $this->enrollments->grant_access( $built['bundle'], $user, BundleAccess::SOURCE_WOOCOMMERCE, 77 );
		$this->enrollments->revoke_access( $access->id, BundleAccess::STATUS_REFUNDED, 'test' );

		$regranted = $this->enrollments->grant_access( $built['bundle'], $user, BundleAccess::SOURCE_WOOCOMMERCE, 77 );

		$this->assertSame( $access->id, $regranted->id );
		$this->assertSame( BundleAccess::STATUS_ACTIVE, $regranted->status );
		$this->assertNull( $regranted->revoked_at );

		foreach ( $built['courses'] as $course_id ) {
			$this->assertEnrolled( $course_id, $user );
		}
	}

	/**
	 * A bundle bigger than the batch threshold must not be enrolled inline —
	 * that is how a checkout request times out. Rows are pre-recorded as
	 * pending so the work is visible and repairable.
	 */
	public function test_large_bundles_are_queued_rather_than_enrolled_inline(): void {
		add_filter( 'tcb/enrollment/batch_threshold', static fn(): int => 5 );

		$world = $this->scenarios->large_bundle( 12 );

		$access = $this->enrollments->grant_access( $world['bundle'], $world['user'], BundleAccess::SOURCE_MANUAL, 0 );

		$this->assertNotWPError( $access );

		$pending = tcb()->get( EnrollmentRepository::class )->get_pending_course_ids( $access->id );

		$this->assertNotEmpty( $pending, 'Queued courses must be recorded as pending.' );
		$this->assertSame( 12, $this->countRows( 'enrollments', array( 'bundle_access_id' => $access->id ) ) );

		remove_all_filters( 'tcb/enrollment/batch_threshold' );
	}

	/**
	 * Running the queued job finishes the work the request deferred.
	 */
	public function test_the_background_job_completes_a_queued_batch(): void {
		add_filter( 'tcb/enrollment/batch_threshold', static fn(): int => 2 );

		$world  = $this->scenarios->large_bundle( 6 );
		$access = $this->enrollments->grant_access( $world['bundle'], $world['user'], BundleAccess::SOURCE_MANUAL, 0 );

		remove_all_filters( 'tcb/enrollment/batch_threshold' );

		$this->enrollments->process_batch_job( $access->id, $world['courses'] );

		foreach ( $world['courses'] as $course_id ) {
			$this->assertEnrolled( $course_id, $world['user'] );
		}

		$this->assertSame(
			array(),
			tcb()->get( EnrollmentRepository::class )->get_pending_course_ids( $access->id )
		);
	}

	/**
	 * A queued batch that wakes after a refund must remain revoked.
	 */
	public function test_a_refunded_queued_batch_cannot_enroll_later(): void {
		add_filter( 'tcb/enrollment/batch_threshold', static fn(): int => 2 );

		$world  = $this->scenarios->large_bundle( 6 );
		$access = $this->enrollments->grant_access( $world['bundle'], $world['user'], BundleAccess::SOURCE_MANUAL, 0 );

		remove_all_filters( 'tcb/enrollment/batch_threshold' );

		$this->enrollments->revoke_access( $access->id, BundleAccess::STATUS_REFUNDED, 'test_refund' );
		$this->enrollments->process_batch_job( $access->id, $world['courses'] );

		foreach ( $world['courses'] as $course_id ) {
			$this->assertNotEnrolled( $course_id, $world['user'] );
			$this->assertSame( EnrollmentRepository::STATUS_REVOKED, $this->enrollmentRow( $access->id, $course_id )['status'] );
		}
	}

	/**
	 * Unavailable courses are skipped and recorded with a reason rather than
	 * failing the whole grant. A learner buying a bundle where one course is
	 * mid-edit should still get everything else.
	 */
	public function test_broken_courses_are_skipped_with_a_reason(): void {
		$world = $this->scenarios->bundle_with_broken_courses();
		$user  = $this->seeder->student();

		$access = $this->enrollments->grant_access( $world['bundle'], $user, BundleAccess::SOURCE_MANUAL, 0 );

		$this->assertNotWPError( $access );
		$this->assertEnrolled( $world['ok'], $user );
		$this->assertNotEnrolled( $world['draft'], $user );

		// Only available courses are attempted, so the draft is never queued.
		$this->assertNull( $this->enrollmentRow( $access->id, $world['draft'] ) );
	}

	/**
	 * Acceptance criterion 10 in ledger form: expired entitlements are retired
	 * by the hourly job, and the courses they alone granted go with them.
	 */
	public function test_the_expiry_job_retires_overdue_entitlements(): void {
		$user   = $this->seeder->student();
		$course = $this->seeder->course();
		$bundle = $this->seeder->bundle( array( 'courses' => array( $course ) ) );

		$access = $this->enrollments->grant_access( $bundle, $user, BundleAccess::SOURCE_MANUAL, 0 );
		$this->assertEnrolled( $course, $user );

		$this->access->update(
			$access->id,
			array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) )
		);

		$this->enrollments->expire_due_access();

		$refreshed = $this->access->find( $access->id );

		$this->assertSame( BundleAccess::STATUS_EXPIRED, $refreshed->status );
		$this->assertNotEnrolled( $course, $user );
	}

	/**
	 * A bundle with a fixed access window stamps an expiry on the entitlement
	 * at grant time.
	 */
	public function test_access_duration_produces_an_expiry_date(): void {
		$user  = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses( 1, array( 'access_duration' => 30 ) );

		$access = $this->enrollments->grant_access( $built['bundle'], $user, BundleAccess::SOURCE_MANUAL, 0 );

		$this->assertNotNull( $access->expires_at );
		$this->assertTrue( $access->is_usable() );

		$expected = time() + ( 30 * DAY_IN_SECONDS );
		$actual   = strtotime( $access->expires_at . ' UTC' );

		$this->assertEqualsWithDelta( $expected, $actual, 120, 'Expiry should land 30 days out.' );
	}

	/**
	 * Adding a course later gives it to learners who already own the bundle,
	 * because they bought the bundle rather than a fixed list.
	 */
	public function test_courses_added_later_reach_existing_learners(): void {
		$user  = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses( 2 );

		$this->enrollments->grant_access( $built['bundle'], $user, BundleAccess::SOURCE_MANUAL, 0 );

		$new_course = $this->seeder->course( array( 'title' => 'Bonus module' ) );
		$this->seeder->repository()->add_course( $built['bundle'], $new_course );

		// Run the job the hook would have queued.
		$this->enrollments->sync_new_course_job( $built['bundle'], $new_course );

		$this->assertEnrolled( $new_course, $user, 'Existing learners should receive courses added later.' );
	}

	/**
	 * Adding a course schedules the sync job — the transport differs (Action
	 * Scheduler or WP-Cron) but something must be queued.
	 */
	public function test_adding_a_course_queues_the_sync_job(): void {
		$user  = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses( 1 );

		$this->enrollments->grant_access( $built['bundle'], $user, BundleAccess::SOURCE_MANUAL, 0 );

		$new_course = $this->seeder->course();
		$this->seeder->repository()->add_course( $built['bundle'], $new_course );

		$this->assertTrue(
			$this->syncJobQueued( $built['bundle'], $new_course ),
			'Adding a course to a bundle should queue the learner sync job.'
		);
	}

	/**
	 * With the setting turned off, nothing is queued at all, so only future
	 * buyers get the new course.
	 */
	public function test_new_courses_can_be_withheld_from_existing_learners(): void {
		$user  = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses( 2, array( 'grant_new_courses' => 'no' ) );

		$this->enrollments->grant_access( $built['bundle'], $user, BundleAccess::SOURCE_MANUAL, 0 );

		$new_course = $this->seeder->course();
		$this->seeder->repository()->add_course( $built['bundle'], $new_course );

		$this->assertFalse(
			$this->syncJobQueued( $built['bundle'], $new_course ),
			'No sync job should be queued when the bundle withholds new courses.'
		);

		$this->assertNotEnrolled( $new_course, $user );
	}

	/**
	 * Whether a sync job is queued, via either transport.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @param int $course_id Course post ID.
	 */
	private function syncJobQueued( int $bundle_id, int $course_id ): bool {
		$args = array( $bundle_id, $course_id );

		if ( false !== wp_next_scheduled( EnrollmentService::HOOK_SYNC_NEW_COURSE, $args ) ) {
			return true;
		}

		if ( function_exists( 'as_has_scheduled_action' ) ) {
			return (bool) as_has_scheduled_action( EnrollmentService::HOOK_SYNC_NEW_COURSE, $args, 'tutor-course-bundles' );
		}

		return false;
	}

	/**
	 * Removing a course leaves existing learners alone by default. Taking back
	 * something already paid for needs an explicit opt-in.
	 */
	public function test_removing_a_course_keeps_existing_learners_by_default(): void {
		$user  = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses( 2 );

		$this->enrollments->grant_access( $built['bundle'], $user, BundleAccess::SOURCE_MANUAL, 0 );
		$this->seeder->repository()->remove_course( $built['bundle'], $built['courses'][0] );

		$this->assertEnrolled( $built['courses'][0], $user );
	}

	/**
	 * With the opt-in enabled, removal does revoke — but still only when no
	 * other source covers the course.
	 */
	public function test_removing_a_course_can_revoke_when_opted_in(): void {
		update_option( 'tcb_revoke_on_course_removal', 'yes' );

		$user  = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses( 2 );

		$this->enrollments->grant_access( $built['bundle'], $user, BundleAccess::SOURCE_MANUAL, 0 );
		$this->seeder->repository()->remove_course( $built['bundle'], $built['courses'][0] );

		update_option( 'tcb_revoke_on_course_removal', 'no' );

		$this->assertNotEnrolled( $built['courses'][0], $user );
		$this->assertEnrolled( $built['courses'][1], $user );
	}

	/**
	 * Acceptance criterion 11: an admin can find and fix enrollments that
	 * failed in the background.
	 */
	public function test_failed_enrollments_can_be_repaired(): void {
		$user  = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses( 3 );

		$access = $this->enrollments->grant_access( $built['bundle'], $user, BundleAccess::SOURCE_MANUAL, 0 );

		// Simulate a batch that never ran.
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			\SpaceWork\TutorCourseBundles\Support\Migration::table_bundle_enrollments(),
			array( 'status' => EnrollmentRepository::STATUS_FAILED ),
			array( 'bundle_access_id' => $access->id ),
			array( '%s' ),
			array( '%d' )
		);

		$result = $this->enrollments->repair_failed_enrollments( 50 );

		$this->assertGreaterThan( 0, $result['repaired'] );
		$this->assertSame(
			array(),
			tcb()->get( EnrollmentRepository::class )->get_pending_course_ids( $access->id )
		);
	}

	/**
	 * Granting to a user or bundle that does not exist fails loudly instead of
	 * writing a dangling ledger row.
	 */
	public function test_invalid_grants_are_rejected(): void {
		$user = $this->seeder->student();

		$this->assertWPError( $this->enrollments->grant_access( 999999, $user, BundleAccess::SOURCE_MANUAL, 0 ) );

		$bundle = $this->seeder->bundle();

		$this->assertWPError( $this->enrollments->grant_access( $bundle, 999999, BundleAccess::SOURCE_MANUAL, 0 ) );
		$this->assertSame( 0, $this->countRows( 'access', array( 'bundle_id' => $bundle ) ) );
	}
}
