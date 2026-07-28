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
 * Implementation uses `wp_options` with an `autoload = no` row and the atomic
 * `INSERT ... ON DUPLICATE KEY UPDATE` semantics that `add_option()` gives us:
 * add_option() returns false when the row already exists.
 */
final class LockManager {

	private const PREFIX      = 'tcb_lock_';
	private const DEFAULT_TTL = 120;

	/**
	 * Attempt to acquire a lock.
	 *
	 * @param string $key Lock key, e.g. "order_processing_1234".
	 * @param int    $ttl Seconds before the lock is considered stale.
	 * @return bool True when the lock was acquired by this process.
	 */
	public function acquire( string $key, int $ttl = self::DEFAULT_TTL ): bool {
		$option  = $this->option_name( $key );
		$expires = time() + $ttl;

		// Fast path: fresh insert wins the lock.
		if ( add_option( $option, $expires, '', false ) ) {
			return true;
		}

		$existing = (int) get_option( $option, 0 );

		// Stale lock left behind by a crashed request — take it over.
		if ( $existing > 0 && $existing < time() ) {
			delete_option( $option );

			return (bool) add_option( $option, $expires, '', false );
		}

		return false;
	}

	/**
	 * Release a lock.
	 *
	 * @param string $key Lock key.
	 */
	public function release( string $key ): void {
		delete_option( $this->option_name( $key ) );
	}

	/**
	 * Run a callback while holding a lock; skip entirely if it cannot be acquired.
	 *
	 * @template T
	 * @param string   $key      Lock key.
	 * @param callable $callback Work to perform.
	 * @param int      $ttl      Lock lifetime.
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
		return self::PREFIX . substr( preg_replace( '/[^a-z0-9_]/i', '_', $key ) ?? '', 0, 100 );
	}
}
