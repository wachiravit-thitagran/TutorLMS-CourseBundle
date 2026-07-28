<?php
/**
 * Unit tests for plugin lifecycle cleanup.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Unit;

use Brain\Monkey\Functions;
use SpaceWork\TutorCourseBundles\Activation;
use SpaceWork\TutorCourseBundles\Tutor\EnrollmentService;

/**
 * @covers \SpaceWork\TutorCourseBundles\Activation
 */
final class ActivationTest extends UnitTestCase {

	/**
	 * Deactivation clears every hook the enrollment engine registers.
	 */
	public function test_deactivate_clears_real_enrollment_hooks(): void {
		Functions\expect( 'flush_rewrite_rules' )->once();
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( EnrollmentService::HOOK_EXPIRE_ACCESS );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( EnrollmentService::HOOK_PROCESS_BATCH );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( EnrollmentService::HOOK_SYNC_NEW_COURSE );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( EnrollmentService::HOOK_REMOVE_COURSE );
		Functions\expect( 'do_action' )->once()->with( 'tcb/deactivated' );

		Activation::deactivate();

		$this->assertTrue( true );
	}
}
