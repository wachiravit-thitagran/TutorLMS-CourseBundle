<?php
/**
 * Unit tests for Tutor LMS adapter cleanup.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Unit;

use Brain\Monkey\Functions;
use RuntimeException;
use SpaceWork\TutorCourseBundles\Tutor\TutorAdapter;

/**
 * @covers \SpaceWork\TutorCourseBundles\Tutor\TutorAdapter
 */
final class TutorAdapterTest extends UnitTestCase {

	public function test_completion_policy_preserves_a_prerequisite_hold(): void {
		\Brain\Monkey\Filters\expectApplied( 'tcb/enrollment/can_complete' )->with( true, 10, 20 )->andReturn( false );
		$data = array( 'post_parent' => 10, 'post_author' => 20, 'post_status' => 'pending' );
		$this->assertSame( $data, ( new TutorAdapter() )->force_completed_enrollment( $data ) );
	}

	public function test_an_empty_denied_payload_is_never_resurrected(): void {
		$this->assertSame( array(), ( new TutorAdapter() )->force_completed_enrollment( array() ) );
	}

	/**
	 * Temporary Tutor filters must be removed even when Tutor throws.
	 */
	public function test_enroll_removes_filters_when_tutor_throws(): void {
		$utils = new class() {
			public function do_enroll(): bool {
				throw new RuntimeException( 'Tutor failed.' );
			}
		};

		Functions\when( 'get_post_type' )->justReturn( 'courses' );
		Functions\expect( 'add_filter' )->twice();
		Functions\expect( 'remove_filter' )->twice();

		$GLOBALS['tcb_test_tutor_utils'] = $utils;
		$GLOBALS['wpdb']                 = new class() {
			public string $posts = 'wp_posts';

			public function prepare( string $query, ...$args ): string {
				return $query;
			}

			public function get_var(): ?int {
				return null;
			}
		};

		$this->expectException( RuntimeException::class );

		( new TutorAdapter() )->enroll( 10, 20, 30 );
	}

	/**
	 * Tutor LMS 4 moved enrollment creation to EnrollmentModel.
	 */
	public function test_enroll_uses_the_tutor_four_model_api(): void {
		Functions\when( 'get_post_type' )->justReturn( 'courses' );
		Functions\expect( 'add_filter' )->twice();
		Functions\expect( 'remove_filter' )->twice();

		$GLOBALS['tcb_test_tutor_utils'] = new \stdClass();
		unset( $GLOBALS['tcb_test_model_created'] );
		$GLOBALS['tcb_test_enrollment_model'] = static function (): int {
			$GLOBALS['tcb_test_model_created'] = 321;

			return 321;
		};
		$GLOBALS['wpdb']                      = new class() {
			public string $posts = 'wp_posts';

			public function prepare( string $query, ...$args ): string {
				return $query;
			}

			public function get_var(): ?int {
				return $GLOBALS['tcb_test_model_created'] ?? null;
			}
		};

		$result = ( new TutorAdapter() )->enroll( 10, 20, 30 );

		$this->assertSame( 321, $result );
	}
}
