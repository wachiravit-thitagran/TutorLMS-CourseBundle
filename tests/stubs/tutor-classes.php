<?php
/**
 * Tutor LMS class stubs used by the isolated unit suite.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace Tutor\Models;

/**
 * Tutor 4 enrollment API stub.
 */
final class EnrollmentModel {

	/**
	 * Delegate to the callback selected by the current test.
	 */
	public static function do_enroll( int $course_id, int $order_id, int $user_id ): int {
		$callback = $GLOBALS['tcb_test_enrollment_model'] ?? static fn(): int => 0;

		return (int) $callback( $course_id, $order_id, $user_id );
	}
}
