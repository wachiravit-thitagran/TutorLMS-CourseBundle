<?php
declare( strict_types = 1 );
namespace SpaceWork\TutorCourseBundles\Tests\Integration;

use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Infrastructure\EnrollmentRepository;
use SpaceWork\TutorCourseBundles\Tests\Support\TestCase;
use SpaceWork\TutorCourseBundles\Tutor\EnrollmentService;
use SpaceWork\TutorLearningPaths\Domain\Access\AccessContext;
use SpaceWork\TutorLearningPaths\Infrastructure\Cache\AccessCache;
use SpaceWork\TutorLearningPaths\Plugin;

final class PrerequisiteCompositionTest extends TestCase {
	public function test_bundle_hold_preserves_pending_ownership_and_unlocks_after_explicit_repair(): void {
		if ( ! class_exists( Plugin::class ) ) {
			$this->markTestSkipped( 'Set TCB_TEST_PREREQUISITES_FILE to test the real prerequisite plugin together with bundles.' );
		}
		require_once dirname( (string) getenv( 'TCB_TEST_PREREQUISITES_FILE' ) ) . '/tests/Fixtures/Seeder.php';
		$rules = new \SpaceWork\TutorLearningPaths\Tests\Fixtures\Seeder();
		$student = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses( 1 );
		$course = $built['courses'][0];
		$source = $this->seeder->course();
		update_post_meta( $course, '_tutor_course_price_type', 'paid' );
		$this->assertTrue( $rules->require_courses( $course, array( $source ), array( 'visibility' => 'purchasable' ) ) );
		$pending = wp_insert_post( array( 'post_type' => 'tutor_enrolled', 'post_status' => 'pending', 'post_parent' => $course, 'post_author' => $student ) );
		$service = tcb()->get( EnrollmentService::class );
		$access = $service->grant_access( $built['bundle'], $student, BundleAccess::SOURCE_MANUAL );
		$this->assertNotWPError( $access );
		$this->assertSame( 'pending', get_post_status( $pending ) );
		$this->assertNotEnrolled( $course, $student );
		$row = tcb()->get( EnrollmentRepository::class )->find( $access->id, $course );
		$this->assertSame( 'tcb_enrollment_held', $row['error_code'] );
		AccessCache::flush_all(); // A subsequent request cannot grandfather the held ownership.
		$this->assertFalse( Plugin::instance()->gate()->allows( $student, $course, AccessContext::Content ) );
		$rules->complete( $student, $source );
		Plugin::instance()->tutor()->clear_runtime_cache();
		$repaired = $service->repair_failed_enrollments();
		$this->assertSame( 1, $repaired['repaired'] );
		$this->assertEnrolled( $course, $student );
		AccessCache::flush_all();
		$this->assertTrue( Plugin::instance()->gate()->allows( $student, $course, AccessContext::Content ) );
	}
}
