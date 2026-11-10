<?php
/**
 * Cross-request advisory locking.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Infrastructure;

defined( 'ABSPATH' ) || exit;

/**
 * Prevents concurrent processing of the same order or enrollment batch.
 *
 * WooCommerce can fire `woocommerce_order_status_completed` from a webhook and
 * an admin request at nearly the same moment. Without a lock, both would walk
 * the bundle's course list and race on the enrollment insert.
 *
 * MySQL advisory locks are connection-owned and survive long operations.
 * A crashed connection releases its locks automatically; a live owner never
 * loses an expiring lease halfway through an enrollment write.
 */
final class LockManager {

	private const DEFAULT_TTL = 120;
	/**
	 * Locks owned by this PHP connection.
	 *
	 * @var array<string, self>
	 */
	private static array $owners = array();

	/**
	 * Attempt to acquire a lock.
	 *
	 * @param string $key Lock key, e.g. "order_processing_1234".
	 * @param int    $ttl Maximum wait in seconds (capped at ten); retained for API compatibility.
	 * @return bool True when the lock was acquired by this process.
	 */
	public function acquire( string $key, int $ttl = self::DEFAULT_TTL ): bool {
		global $wpdb;
		$name = $this->option_name( $key );
		if ( isset( self::$owners[ $name ] ) ) {
			return false; // MySQL named locks are reentrant; our critical sections are not.
		}
		// Advisory lock ownership is connection state and must never use an object cache.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$acquired = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, min( 10, max( 0, $ttl ) ) ) );
		if ( $acquired ) {
			self::$owners[ $name ] = $this;
		}
		return $acquired;
	}

	/**
	 * Release a lock.
	 *
	 * @param string $key Lock key.
	 */
	public function release( string $key ): void {
		global $wpdb;
		$name = $this->option_name( $key );
		if ( ( self::$owners[ $name ] ?? null ) !== $this ) {
			return;
		}
		// MySQL releases only locks belonging to this connection. Connection
		// loss releases automatically; no expiring lease can be stolen mid-write.
		// Releasing the connection-owned advisory lock requires an uncached SQL call.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		unset( self::$owners[ $name ] );
	}

	/**
	 * Run a callback while holding a lock; skip entirely if it cannot be acquired.
	 *
	 * @template T
	 * @param string   $key      Lock key.
	 * @param callable $callback Work to perform.
	 * @param int      $ttl      Maximum acquisition wait, capped at ten seconds.
	 * @return mixed|null Callback return value, or null when the lock was busy.
	 */
	public function with_lock( string $key, callable $callback, int $ttl = self::DEFAULT_TTL ) {
		if ( ! $this->acquire( $key, $ttl ) ) {
			return null;
		}

		try {
			return $callback();
		} finally {
			$this->release( $key );
		}
	}

	/**
	 * Build the option name for a lock key.
	 *
	 * @param string $key Lock key.
	 */
	private function option_name( string $key ): string {
		global $wpdb;
		return 'tcb_' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix . ':' . $key ), 0, 56 );
	}
}
