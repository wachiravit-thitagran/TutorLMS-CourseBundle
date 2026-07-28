<?php
/**
 * Namespaced object cache helper with group invalidation.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over the WP object cache.
 *
 * Because `wp_cache_delete_group()` is not universally supported, invalidation
 * is done with an incrementing generation number stored per logical group.
 */
final class Cache {

	public const GROUP_BUNDLE   = 'tcb_bundle';
	public const GROUP_PROGRESS = 'tcb_progress';
	public const GROUP_ACCESS   = 'tcb_access';

	private const TTL = 300;

	/**
	 * Read a cached value.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Logical group.
	 * @return mixed|null Null when absent.
	 */
	public static function get( string $key, string $group = self::GROUP_BUNDLE ) {
		$cached = wp_cache_get( self::build_key( $key, $group ), $group );

		if ( ! is_array( $cached ) || ! array_key_exists( 'tcb_value', $cached ) ) {
			return null;
		}

		return $cached['tcb_value'];
	}

	/**
	 * Write a cached value.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value.
	 * @param string $group Logical group.
	 * @param int    $ttl   Lifetime in seconds.
	 */
	public static function set( string $key, $value, string $group = self::GROUP_BUNDLE, int $ttl = self::TTL ): void {
		wp_cache_set(
			self::build_key( $key, $group ),
			array( 'tcb_value' => $value ),
			$group,
			$ttl
		);
	}

	/**
	 * Delete a single entry.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Logical group.
	 */
	public static function delete( string $key, string $group = self::GROUP_BUNDLE ): void {
		wp_cache_delete( self::build_key( $key, $group ), $group );
	}

	/**
	 * Invalidate an entire group by bumping its generation.
	 *
	 * @param string $group Logical group.
	 */
	public static function flush_group( string $group ): void {
		$generation = (int) get_option( self::generation_option( $group ), 1 );
		update_option( self::generation_option( $group ), $generation + 1, false );
	}

	/**
	 * Invalidate everything derived from a bundle.
	 *
	 * @param int $bundle_id Bundle post ID.
	 */
	public static function flush_bundle( int $bundle_id ): void {
		self::delete( 'courses_' . $bundle_id, self::GROUP_BUNDLE );
		self::delete( 'stats_' . $bundle_id, self::GROUP_BUNDLE );
		self::delete( 'instructors_' . $bundle_id, self::GROUP_BUNDLE );
		self::flush_group( self::GROUP_PROGRESS );
	}

	/**
	 * Invalidate a single learner's progress data.
	 *
	 * @param int $user_id   User ID.
	 * @param int $bundle_id Bundle post ID, 0 for all bundles.
	 */
	public static function flush_user_progress( int $user_id, int $bundle_id = 0 ): void {
		if ( $bundle_id > 0 ) {
			self::delete( "progress_{$bundle_id}_{$user_id}", self::GROUP_PROGRESS );
			return;
		}

		self::flush_group( self::GROUP_PROGRESS );
	}

	/**
	 * Build a generation-aware cache key.
	 *
	 * @param string $key   Raw key.
	 * @param string $group Logical group.
	 */
	private static function build_key( string $key, string $group ): string {
		$generation = (int) get_option( self::generation_option( $group ), 1 );

		return $group . ':' . $generation . ':' . $key;
	}

	/**
	 * Option name holding a group's generation counter.
	 *
	 * @param string $group Logical group.
	 */
	private static function generation_option( string $group ): string {
		return 'tcb_cache_gen_' . $group;
	}
}
