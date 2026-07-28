<?php
/**
 * The access ledger: who has which course, and why.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Integration;

use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Infrastructure\AccessRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\EnrollmentRepository;
use SpaceWork\TutorCourseBundles\Tests\Support\TestCase;
use SpaceWork\TutorCourseBundles\Tutor\CourseAccessResolver;
use SpaceWork\TutorCourseBundles\Tutor\EnrollmentService;

/**
 * These are the tests that matter most.
 *
 * A learner can reach the same course through several doors: two bundles, a
 * direct purchase, an admin grant. When one door closes the others must keep
 * working. Every failure in this file is a learner losing something they paid
 * for, so the assertions are deliberately blunt.
 *
 * @covers \SpaceWork\TutorCourseBundles\Tutor\CourseAccessResolver
 * @covers \SpaceWork\TutorCourseBundles\Tutor\EnrollmentService
 */
final class AccessLedgerTest extends TestCase {

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
	 * Acceptance criteria 8 and 9, in one shape: refunding bundle A must not
	 * cost the learner a course that bundle B also grants.
	 */
	public function test_refunding_one_bundle_keeps_courses_covered_by_another(): void {
		$world = $this->scenarios->overlapping_bundles();

		$this->assertEnrolled( $world['shared_course'], $world['user'] );
		$this->assertEnrolled( $world['only_a'], $world['user'] );
		$this->assertEnrolled( $world['only_b'], $world['user'] );

		$this->enrollments->revoke_access( $world['access_a'], BundleAccess::STATUS_REFUNDED, 'test_refund' );

		$this->assertNotEnrolled(
			$world['only_a'],
			$world['user'],
			'A course only bundle A granted should be gone.'
		);

		$this->assertEnrolled(
			$world['shared_course'],
			$world['user'],
			'The shared course is still covered by bundle B and must survive.'
		);

		$this->assertEnrolled( $world['only_b'], $world['user'] );
	}

	/**
	 * Acceptance criterion 9: a course the learner bought directly is never
	 * touched, even when a bundle containing it is refunded.
	 */
	public function test_a_directly_purchased_course_survives_a_bundle_refund(): void {
		$world = $this->scenarios->direct_purchase_plus_bundle();

		$this->enrollments->revoke_access( $world['access_id'], BundleAccess::STATUS_REFUNDED, 'test_refund' );

		$this->assertEnrolled(
			$world['direct_course'],
			$world['user'],
			'A directly purchased course must never be revoked by a bundle refund.'
		);

		$this->assertNotEnrolled( $world['bundle_course'], $world['user'] );
	}

	/**
	 * Provenance is the mechanism behind the rule above: the plugin only ever
	 * cancels enrollments it created itself.
	 */
	public function test_only_plugin_created_enrollments_are_tagged(): void {
		$resolver = tcb()->get( CourseAccessResolver::class );
		$user     = $this->seeder->student();
		$direct   = $this->seeder->course();
		$granted  = $this->seeder->course();

		$direct_enrollment = $this->seeder->direct_enrollment( $direct, $user );

		$bundle = $this->seeder->bundle( array( 'courses' => array( $granted ) ) );
		$this->enrollments->grant_access( $bundle, $user, BundleAccess::SOURCE_MANUAL, 0 );

		$adapter          = new \SpaceWork\TutorCourseBundles\Tutor\TutorAdapter();
		$bundle_enrolment = $adapter->get_enrollment_id( $granted, $user );

		$this->assertFalse(
			$resolver->is_plugin_owned( $direct_enrollment ),
			'A direct enrollment must carry no provenance meta.'
		);

		$this->assertTrue(
			$resolver->is_plugin_owned( $bundle_enrolment ),
			'A bundle-created enrollment must be tagged so it can be reclaimed later.'
		);
	}

	/**
	 * When a learner already owned a course, the bundle records the grant but
	 * does not claim ownership of the existing enrollment.
	 */
	public function test_a_bundle_does_not_adopt_a_pre_existing_enrollment(): void {
		$resolver = tcb()->get( CourseAccessResolver::class );
		$user     = $this->seeder->student();
		$course   = $this->seeder->course();

		$existing = $this->seeder->direct_enrollment( $course, $user );

		$bundle = $this->seeder->bundle( array( 'courses' => array( $course ) ) );
		$access = $this->enrollments->grant_access( $bundle, $user, BundleAccess::SOURCE_MANUAL, 0 );

		$this->assertNotWPError( $access );

		$row = $this->enrollmentRow( $access->id, $course );

		$this->assertNotNull( $row );
		$this->assertSame( EnrollmentRepository::STATUS_ACTIVE, $row['status'], 'The bundle still records the grant.' );
		$this->assertFalse(
			$resolver->is_plugin_owned( $existing ),
			'The original enrollment must stay untagged so it survives a refund.'
		);
	}

	/**
	 * Revoking marks our own mapping row inactive first, so the
	 * alternative-access query cannot mistake the grant being withdrawn for a
	 * reason to keep the course.
	 */
	public function test_revoking_the_only_source_removes_the_course(): void {
		$user   = $this->seeder->student();
		$course = $this->seeder->course();
		$bundle = $this->seeder->bundle( array( 'courses' => array( $course ) ) );

		$access = $this->enrollments->grant_access( $bundle, $user, BundleAccess::SOURCE_MANUAL, 0 );
		$this->assertNotWPError( $access );
		$this->assertEnrolled( $course, $user );

		$this->enrollments->revoke_access( $access->id, BundleAccess::STATUS_REVOKED, 'test' );

		$this->assertNotEnrolled( $course, $user );

		$row = $this->enrollmentRow( $access->id, $course );
		$this->assertSame( EnrollmentRepository::STATUS_REVOKED, $row['status'] );
		$this->assertNotNull( $row['revoked_at'] );
	}

	/**
	 * The resolver reports every live source for a course, which is what the
	 * admin needs when a learner asks why they still have access.
	 */
	public function test_sources_are_explainable(): void {
		$world    = $this->scenarios->overlapping_bundles();
		$resolver = tcb()->get( CourseAccessResolver::class );

		$sources = $resolver->describe_sources( $world['user'], $world['shared_course'] );

		$this->assertCount( 2, $sources, 'The shared course is granted by both bundles.' );

		$bundle_ids = array_column( $sources, 'bundle_id' );
		sort( $bundle_ids );

		$expected = array( $world['bundle_a'], $world['bundle_b'] );
		sort( $expected );

		$this->assertSame( $expected, $bundle_ids );
	}

	/**
	 * An expired entitlement is not an alternative source. If it were, a
	 * refund on a live bundle would leave the learner with nothing but a dead
	 * grant propping the course up.
	 */
	public function test_an_expired_entitlement_does_not_count_as_an_alternative(): void {
		$user   = $this->seeder->student();
		$course = $this->seeder->course();

		$old_bundle = $this->seeder->bundle( array( 'courses' => array( $course ) ) );
		$new_bundle = $this->seeder->bundle( array( 'courses' => array( $course ) ) );

		$old_access = $this->enrollments->grant_access( $old_bundle, $user, BundleAccess::SOURCE_MANUAL, 0 );
		$new_access = $this->enrollments->grant_access( $new_bundle, $user, BundleAccess::SOURCE_MANUAL, 0 );

		$this->assertNotWPError( $old_access );
		$this->assertNotWPError( $new_access );

		// Age the first entitlement out.
		$this->access->update(
			$old_access->id,
			array(
				'status'     => BundleAccess::STATUS_EXPIRED,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			)
		);

		$resolver = tcb()->get( CourseAccessResolver::class );

		$this->assertFalse(
			$resolver->user_has_alternative_access( $user, $course, $new_access->id ),
			'An expired entitlement must not keep a course alive.'
		);
	}

	/**
	 * Third-party membership plugins hook `tcb/access/has_alternative` to
	 * protect their own enrollments. That veto has to be honoured.
	 */
	public function test_third_parties_can_veto_a_revocation(): void {
		$user   = $this->seeder->student();
		$course = $this->seeder->course();
		$bundle = $this->seeder->bundle( array( 'courses' => array( $course ) ) );

		$access = $this->enrollments->grant_access( $bundle, $user, BundleAccess::SOURCE_MANUAL, 0 );
		$this->assertNotWPError( $access );

		add_filter( 'tcb/access/has_alternative', '__return_true' );

		$this->enrollments->revoke_access( $access->id, BundleAccess::STATUS_REVOKED, 'test' );

		remove_filter( 'tcb/access/has_alternative', '__return_true' );

		$this->assertEnrolled(
			$course,
			$user,
			'A membership plugin claiming the course must block the revocation.'
		);
	}

	/**
	 * Revocation announces what it did and what it deliberately left alone, so
	 * integrations and the audit trail can follow along.
	 */
	public function test_revocation_reports_kept_and_revoked_courses(): void {
		$world = $this->scenarios->overlapping_bundles();

		$captured = array();

		add_action(
			'tcb/access/revoked',
			static function ( $access, $reason, $revoked, $kept ) use ( &$captured ): void {
				$captured = compact( 'reason', 'revoked', 'kept' );
			},
			10,
			4
		);

		$this->enrollments->revoke_access( $world['access_a'], BundleAccess::STATUS_REFUNDED, 'test_reason' );

		$this->assertSame( 'test_reason', $captured['reason'] );
		$this->assertContains( $world['only_a'], $captured['revoked'] );
		$this->assertContains( $world['shared_course'], $captured['kept'] );
	}

	/**
	 * Every entitlement change leaves an audit row. This is what an admin reads
	 * when a learner says "I used to have this".
	 */
	public function test_grants_and_revocations_are_audited(): void {
		$user   = $this->seeder->student();
		$course = $this->seeder->course();
		$bundle = $this->seeder->bundle( array( 'courses' => array( $course ) ) );

		$access = $this->enrollments->grant_access( $bundle, $user, BundleAccess::SOURCE_MANUAL, 0 );
		$this->assertNotWPError( $access );

		$this->enrollments->revoke_access( $access->id, BundleAccess::STATUS_REFUNDED, 'test' );

		$actions = array_column(
			\SpaceWork\TutorCourseBundles\Support\Logger::get_entries( array( 'bundle_id' => $bundle ) ),
			'action'
		);

		$this->assertContains( 'access.granted', $actions );
		$this->assertContains( 'course.enrolled', $actions );
		$this->assertContains( 'access.refunded', $actions );
	}

	/**
	 * Cursor iteration must not truncate a bundle at the repository page size.
	 */
	public function test_iteration_returns_more_than_two_hundred_entitlements(): void {
		$user   = $this->seeder->student();
		$bundle = $this->seeder->bundle();

		for ( $source_id = 1; $source_id <= 201; $source_id++ ) {
			$this->seeder->access(
				$bundle,
				$user,
				array(
					'source_type' => BundleAccess::SOURCE_IMPORT,
					'source_id'   => $source_id,
				)
			);
		}

		$items = iterator_to_array( $this->access->iterate( array( 'bundle_id' => $bundle ) ), false );

		$this->assertCount( 201, $items );
		$this->assertCount( 201, array_unique( array_map( static fn( BundleAccess $access ): int => $access->id, $items ) ) );
	}
}
