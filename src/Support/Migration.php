<?php
/**
 * Database schema installation and upgrades.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the three custom tables that make up the access ledger.
 *
 * Bundle *content* lives in a CPT; bundle *membership* and *entitlements* live
 * here because they need real indexes, uniqueness constraints and concurrent
 * writes — none of which post meta gives us.
 */
final class Migration {

	public const OPTION_DB_VERSION = 'tcb_db_version';

	/**
	 * Table storing which courses belong to which bundle.
	 */
	public static function table_bundle_courses(): string {
		global $wpdb;
		return $wpdb->prefix . 'tcb_bundle_courses';
	}

	/**
	 * Table storing entitlements (a user's right to a bundle, and where it came from).
	 */
	public static function table_bundle_access(): string {
		global $wpdb;
		return $wpdb->prefix . 'tcb_bundle_access';
	}

	/**
	 * Table mapping an entitlement to the individual Tutor course enrollments it created.
	 */
	public static function table_bundle_enrollments(): string {
		global $wpdb;
		return $wpdb->prefix . 'tcb_bundle_enrollments';
	}

	/**
	 * Audit log table.
	 */
	public static function table_audit_log(): string {
		global $wpdb;
		return $wpdb->prefix . 'tcb_audit_log';
	}

	/**
	 * Create or update all tables.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$bundle_courses = self::table_bundle_courses();
		$bundle_access  = self::table_bundle_access();
		$enrollments    = self::table_bundle_enrollments();
		$audit_log      = self::table_audit_log();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Schema DDL, table names are internal.
		$sql = array();

		$sql[] = "CREATE TABLE {$bundle_courses} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			bundle_id BIGINT(20) UNSIGNED NOT NULL,
			course_id BIGINT(20) UNSIGNED NOT NULL,
			position INT(11) NOT NULL DEFAULT 0,
			is_required TINYINT(1) NOT NULL DEFAULT 1,
			unlock_mode VARCHAR(32) NOT NULL DEFAULT 'immediate',
			unlock_reference_course_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			unlock_delay_days INT(11) NOT NULL DEFAULT 0,
			created_at DATETIME NULL DEFAULT NULL,
			updated_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY bundle_course (bundle_id, course_id),
			KEY bundle_position (bundle_id, position),
			KEY course_id (course_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$bundle_access} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			bundle_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			source_type VARCHAR(40) NOT NULL DEFAULT 'manual',
			source_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			granted_at DATETIME NULL DEFAULT NULL,
			starts_at DATETIME NULL DEFAULT NULL,
			expires_at DATETIME NULL DEFAULT NULL,
			revoked_at DATETIME NULL DEFAULT NULL,
			note TEXT NULL,
			created_at DATETIME NULL DEFAULT NULL,
			updated_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY access_source (bundle_id, user_id, source_type, source_id),
			KEY user_status (user_id, status),
			KEY bundle_status (bundle_id, status),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$enrollments} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			bundle_access_id BIGINT(20) UNSIGNED NOT NULL,
			bundle_id BIGINT(20) UNSIGNED NOT NULL,
			course_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			tutor_enrollment_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			enrolled_at DATETIME NULL DEFAULT NULL,
			revoked_at DATETIME NULL DEFAULT NULL,
			error_code VARCHAR(64) NULL DEFAULT NULL,
			created_at DATETIME NULL DEFAULT NULL,
			updated_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY access_course (bundle_access_id, course_id),
			KEY user_course (user_id, course_id),
			KEY user_course_status (user_id, course_id, status),
			KEY bundle_id (bundle_id),
			KEY status (status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$audit_log} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			bundle_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			actor_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(64) NOT NULL,
			context LONGTEXT NULL,
			created_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY bundle_id (bundle_id),
			KEY user_id (user_id),
			KEY action (action),
			KEY created_at (created_at)
		) {$charset_collate};";
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::OPTION_DB_VERSION, TCB_DB_VERSION, false );
	}

	/**
	 * Run pending upgrades when the stored schema version is behind.
	 */
	public static function maybe_upgrade(): void {
		$installed = (string) get_option( self::OPTION_DB_VERSION, '0' );

		if ( version_compare( $installed, (string) TCB_DB_VERSION, '>=' ) ) {
			return;
		}

		self::install();

		/**
		 * Fires after the schema is brought up to date.
		 *
		 * @param string $installed Previously installed schema version.
		 */
		do_action( 'tcb/migrated', $installed );
	}

	/**
	 * Drop every custom table. Only called from uninstall.php.
	 */
	public static function drop_tables(): void {
		global $wpdb;

		$tables = array(
			self::table_bundle_enrollments(),
			self::table_bundle_access(),
			self::table_bundle_courses(),
			self::table_audit_log(),
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		delete_option( self::OPTION_DB_VERSION );
	}
}
