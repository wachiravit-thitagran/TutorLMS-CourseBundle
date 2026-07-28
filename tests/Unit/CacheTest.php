<?php
/**
 * Unit tests for the generation-based cache helper.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Unit;

use SpaceWork\TutorCourseBundles\Support\Cache;

/**
 * `wp_cache_delete_group()` is not available on every object cache backend, so
 * the plugin invalidates by bumping a generation counter baked into the key.
 * These tests pin that behaviour down — a silent regression here means learners
 * see stale progress after finishing a lesson.
 *
 * @covers \SpaceWork\TutorCourseBundles\Support\Cache
 */
final class CacheTest extends UnitTestCase {

	/**
	 * Round trip.
	 */
	public function test_set_and_get(): void {
		Cache::set( 'courses_10', array( 1, 2, 3 ), Cache::GROUP_BUNDLE );

		$this->assertSame( array( 1, 2, 3 ), Cache::get( 'courses_10', Cache::GROUP_BUNDLE ) );
	}

	/**
	 * A miss is null, not false — false is a legitimate cached value.
	 */
	public function test_missing_key_returns_null(): void {
		$this->assertNull( Cache::get( 'nothing_here', Cache::GROUP_BUNDLE ) );
	}

	/**
	 * A cached `false` must survive the round trip and not read as a miss.
	 */
	public function test_false_is_a_valid_cached_value(): void {
		Cache::set( 'flag', false, Cache::GROUP_ACCESS );

		$this->assertFalse( Cache::get( 'flag', Cache::GROUP_ACCESS ) );
		$this->assertNotNull( Cache::get( 'flag', Cache::GROUP_ACCESS ) );
	}

	/**
	 * Deleting one key leaves its neighbours alone.
	 */
	public function test_delete_is_surgical(): void {
		Cache::set( 'a', 'first', Cache::GROUP_BUNDLE );
		Cache::set( 'b', 'second', Cache::GROUP_BUNDLE );

		Cache::delete( 'a', Cache::GROUP_BUNDLE );

		$this->assertNull( Cache::get( 'a', Cache::GROUP_BUNDLE ) );
		$this->assertSame( 'second', Cache::get( 'b', Cache::GROUP_BUNDLE ) );
	}

	/**
	 * Bumping the generation orphans every key in that group at once.
	 */
	public function test_flush_group_invalidates_everything_in_the_group(): void {
		Cache::set( 'a', 'first', Cache::GROUP_PROGRESS );
		Cache::set( 'b', 'second', Cache::GROUP_PROGRESS );

		Cache::flush_group( Cache::GROUP_PROGRESS );

		$this->assertNull( Cache::get( 'a', Cache::GROUP_PROGRESS ) );
		$this->assertNull( Cache::get( 'b', Cache::GROUP_PROGRESS ) );
	}

	/**
	 * Groups are independent; flushing progress must not drop bundle data.
	 */
	public function test_groups_are_isolated(): void {
		Cache::set( 'shared_key', 'bundle value', Cache::GROUP_BUNDLE );
		Cache::set( 'shared_key', 'progress value', Cache::GROUP_PROGRESS );

		Cache::flush_group( Cache::GROUP_PROGRESS );

		$this->assertSame( 'bundle value', Cache::get( 'shared_key', Cache::GROUP_BUNDLE ) );
		$this->assertNull( Cache::get( 'shared_key', Cache::GROUP_PROGRESS ) );
	}

	/**
	 * Editing a bundle drops its derived entries and every learner's progress,
	 * because adding a course changes what "50% done" means.
	 */
	public function test_flush_bundle_clears_derived_and_progress_data(): void {
		Cache::set( 'courses_10', array( 1 ), Cache::GROUP_BUNDLE );
		Cache::set( 'stats_10', array( 'course_count' => 1 ), Cache::GROUP_BUNDLE );
		Cache::set( 'courses_11', array( 2 ), Cache::GROUP_BUNDLE );
		Cache::set( 'progress_10_5', array( 'percent' => 50 ), Cache::GROUP_PROGRESS );

		Cache::flush_bundle( 10 );

		$this->assertNull( Cache::get( 'courses_10', Cache::GROUP_BUNDLE ) );
		$this->assertNull( Cache::get( 'stats_10', Cache::GROUP_BUNDLE ) );
		$this->assertNull( Cache::get( 'progress_10_5', Cache::GROUP_PROGRESS ) );

		$this->assertSame(
			array( 2 ),
			Cache::get( 'courses_11', Cache::GROUP_BUNDLE ),
			'Flushing bundle 10 must not touch bundle 11.'
		);
	}

	/**
	 * Targeting one learner leaves everyone else's cached progress in place.
	 */
	public function test_flush_user_progress_can_target_a_single_bundle(): void {
		Cache::set( 'progress_10_5', 'user 5', Cache::GROUP_PROGRESS );
		Cache::set( 'progress_10_6', 'user 6', Cache::GROUP_PROGRESS );

		Cache::flush_user_progress( 5, 10 );

		$this->assertNull( Cache::get( 'progress_10_5', Cache::GROUP_PROGRESS ) );
		$this->assertSame( 'user 6', Cache::get( 'progress_10_6', Cache::GROUP_PROGRESS ) );
	}

	/**
	 * Without a bundle ID it is a blunt instrument, and that is intentional:
	 * a lesson completion can affect several bundles at once.
	 */
	public function test_flush_user_progress_without_bundle_clears_the_group(): void {
		Cache::set( 'progress_10_5', 'user 5', Cache::GROUP_PROGRESS );
		Cache::set( 'progress_10_6', 'user 6', Cache::GROUP_PROGRESS );

		Cache::flush_user_progress( 5 );

		$this->assertNull( Cache::get( 'progress_10_5', Cache::GROUP_PROGRESS ) );
		$this->assertNull( Cache::get( 'progress_10_6', Cache::GROUP_PROGRESS ) );
	}
}
