<?php
/**
 * Schema installation.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Integration;

use SpaceWork\TutorCourseBundles\Support\Migration;
use SpaceWork\TutorCourseBundles\Tests\Support\TestCase;

/**
 * The tables are the foundation everything else stands on. If dbDelta silently
 * fails — a zero-date default under MySQL strict mode is the classic cause —
 * every other test fails in a confusing way, so check the shape directly.
 *
 * @covers \SpaceWork\TutorCourseBundles\Support\Migration
 */
final class MigrationTest extends TestCase {

	/**
	 * Every table the plugin owns.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provideTables(): array {
		return array(
			'bundle courses'     => array( 'table_bundle_courses' ),
			'bundle access'      => array( 'table_bundle_access' ),
			'bundle enrollments' => array( 'table_bundle_enrollments' ),
			'audit log'          => array( 'table_audit_log' ),
		);
	}

	/**
	 * @dataProvider provideTables
	 *
	 * @param string $method Migration accessor.
	 */
	public function test_table_exists( string $method ): void {
		global $wpdb;

		$table = Migration::$method();

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB

		$this->assertSame( $table, $found, "Table {$table} was not created." );
	}

	/**
	 * The uniqueness constraints are what make granting and course membership
	 * idempotent. Without them the plugin relies on application-level checks
	 * that races can slip past.
	 *
	 * @dataProvider provideUniqueIndexes
	 *
	 * @param string $method     Migration accessor.
	 * @param string $index_name Expected index name.
	 */
	public function test_unique_index_exists( string $method, string $index_name ): void {
		global $wpdb;

		$table   = Migration::$method();
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB

		$names = array_column( (array) $indexes, 'Key_name' );

		$this->assertContains( $index_name, $names, "Missing index {$index_name} on {$table}." );

		foreach ( (array) $indexes as $index ) {
			if ( $index['Key_name'] === $index_name ) {
				$this->assertSame( '0', (string) $index['Non_unique'], "{$index_name} must be UNIQUE." );
				break;
			}
		}
	}

	/**
	 * Unique index expectations.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provideUniqueIndexes(): array {
		return array(
			'one row per bundle/course'  => array( 'table_bundle_courses', 'bundle_course' ),
			'one entitlement per source' => array( 'table_bundle_access', 'access_source' ),
			'one enrollment per grant'   => array( 'table_bundle_enrollments', 'access_course' ),
		);
	}

	/**
	 * Re-running install() on an existing database must be a no-op, because
	 * `maybe_upgrade()` calls it on every version bump.
	 */
	public function test_install_is_idempotent(): void {
		global $wpdb;

		$before = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Migration::table_bundle_access() ); // phpcs:ignore WordPress.DB

		Migration::install();
		Migration::install();

		$after = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Migration::table_bundle_access() ); // phpcs:ignore WordPress.DB

		$this->assertSame( $before, $after, 'Re-installing the schema must not touch data.' );
		$this->assertSame( TCB_DB_VERSION, get_option( Migration::OPTION_DB_VERSION ) );
	}

	/**
	 * Timestamps must accept a real datetime under MySQL strict mode. This is
	 * the exact failure that zero-date defaults cause on MySQL 8.
	 */
	public function test_timestamps_accept_values_under_strict_mode(): void {
		global $wpdb;

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB
			Migration::table_audit_log(),
			array(
				'bundle_id'  => 1,
				'user_id'    => 2,
				'actor_id'   => 3,
				'action'     => 'test.write',
				'context'    => '{}',
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		$this->assertSame( 1, $inserted, 'Insert failed: ' . $wpdb->last_error );
	}

	/**
	 * `maybe_upgrade()` should do nothing when the stored version is current.
	 */
	public function test_maybe_upgrade_is_a_no_op_when_current(): void {
		update_option( Migration::OPTION_DB_VERSION, TCB_DB_VERSION, false );

		$fired = 0;
		add_action( 'tcb/migrated', static function () use ( &$fired ): void {
			++$fired;
		} );

		Migration::maybe_upgrade();

		$this->assertSame( 0, $fired );
	}

	/**
	 * A stale version triggers the upgrade path and announces it.
	 */
	public function test_maybe_upgrade_runs_when_behind(): void {
		update_option( Migration::OPTION_DB_VERSION, '0', false );

		$fired = 0;
		add_action( 'tcb/migrated', static function () use ( &$fired ): void {
			++$fired;
		} );

		Migration::maybe_upgrade();

		$this->assertSame( 1, $fired );
		$this->assertSame( TCB_DB_VERSION, get_option( Migration::OPTION_DB_VERSION ) );
	}
}
