<?php
/**
 * Base class for integration tests.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Support;

use SpaceWork\TutorCourseBundles\Support\Cache;
use SpaceWork\TutorCourseBundles\Support\Capabilities;
use SpaceWork\TutorCourseBundles\Support\Migration;
use WP_UnitTestCase;

/**
 * Gives every integration test a seeder, a scenario builder, and a clean slate.
 *
 * WordPress rolls each test back in a transaction, so the plugin's own tables
 * unwind with it. What does *not* unwind is in-process state — the service
 * container and the object cache — so those are reset explicitly.
 */
abstract class TestCase extends WP_UnitTestCase {

	protected Seeder $seeder;
	protected Scenarios $scenarios;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->seeder    = new Seeder();
		$this->scenarios = new Scenarios( $this->seeder );

		Capabilities::add_roles_capabilities();
		$this->reset_plugin_state();
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		$this->seeder->cleanup();
		$this->reset_plugin_state();

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Drop cached data and rate limits that survive a database rollback.
	 */
	protected function reset_plugin_state(): void {
		wp_cache_flush();

		Cache::flush_group( Cache::GROUP_BUNDLE );
		Cache::flush_group( Cache::GROUP_PROGRESS );
		Cache::flush_group( Cache::GROUP_ACCESS );

		global $wpdb;

		// Advisory locks and enrollment rate limits are plain options/transients.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( 'tcb_lock_' ) . '%',
				$wpdb->esc_like( '_transient_tcb_rate_' ) . '%'
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Guards
	 * ------------------------------------------------------------------ */

	/**
	 * Skip the test when Tutor LMS is not installed.
	 */
	protected function require_tutor(): void {
		if ( ! defined( 'TCB_TESTS_HAS_TUTOR' ) || ! TCB_TESTS_HAS_TUTOR ) {
			$this->markTestSkipped( 'Tutor LMS is not installed in this environment.' );
		}
	}

	/**
	 * Skip the test when WooCommerce is not installed.
	 */
	protected function require_woocommerce(): void {
		if ( ! defined( 'TCB_TESTS_HAS_WOOCOMMERCE' ) || ! TCB_TESTS_HAS_WOOCOMMERCE ) {
			$this->markTestSkipped( 'WooCommerce is not installed in this environment.' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Assertions
	 * ------------------------------------------------------------------ */

	/**
	 * Assert that a learner is enrolled in a course according to Tutor LMS.
	 *
	 * @param int    $course_id Course post ID.
	 * @param int    $user_id   User ID.
	 * @param string $message   Failure message.
	 */
	protected function assertEnrolled( int $course_id, int $user_id, string $message = '' ): void {
		$adapter = new \SpaceWork\TutorCourseBundles\Tutor\TutorAdapter();

		$this->assertTrue(
			$adapter->is_enrolled( $course_id, $user_id ),
			'' !== $message ? $message : sprintf( 'Expected user %d to be enrolled in course %d.', $user_id, $course_id )
		);
	}

	/**
	 * Assert that a learner is not enrolled in a course.
	 *
	 * @param int    $course_id Course post ID.
	 * @param int    $user_id   User ID.
	 * @param string $message   Failure message.
	 */
	protected function assertNotEnrolled( int $course_id, int $user_id, string $message = '' ): void {
		$adapter = new \SpaceWork\TutorCourseBundles\Tutor\TutorAdapter();

		$this->assertFalse(
			$adapter->is_enrolled( $course_id, $user_id ),
			'' !== $message ? $message : sprintf( 'Expected user %d NOT to be enrolled in course %d.', $user_id, $course_id )
		);
	}

	/**
	 * Count rows in one of the plugin's tables.
	 *
	 * @param string               $table Logical table name: access, courses, enrollments, audit.
	 * @param array<string, mixed> $where Simple equality conditions.
	 */
	protected function countRows( string $table, array $where = array() ): int {
		global $wpdb;

		$map = array(
			'access'      => Migration::table_bundle_access(),
			'courses'     => Migration::table_bundle_courses(),
			'enrollments' => Migration::table_bundle_enrollments(),
			'audit'       => Migration::table_audit_log(),
		);

		if ( ! isset( $map[ $table ] ) ) {
			throw new \InvalidArgumentException( "Unknown table: {$table}" );
		}

		$sql    = 'SELECT COUNT(*) FROM ' . $map[ $table ];
		$params = array();

		if ( array() !== $where ) {
			$clauses = array();

			foreach ( $where as $column => $value ) {
				$clauses[] = sprintf( '%s = %s', $column, is_int( $value ) ? '%d' : '%s' );
				$params[]  = $value;
			}

			$sql .= ' WHERE ' . implode( ' AND ', $clauses );
		}

		if ( array() === $params ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $sql );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Fetch the raw enrollment mapping row for an entitlement/course pair.
	 *
	 * @param int $access_id Entitlement row ID.
	 * @param int $course_id Course post ID.
	 * @return array<string, mixed>|null
	 */
	protected function enrollmentRow( int $access_id, int $course_id ): ?array {
		return tcb()->get( \SpaceWork\TutorCourseBundles\Infrastructure\EnrollmentRepository::class )
			->find( $access_id, $course_id );
	}
}
