<?php
/**
 * Course membership persistence.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Integration;

use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Domain\BundleCourse;
use SpaceWork\TutorCourseBundles\Infrastructure\BundleRepository;
use SpaceWork\TutorCourseBundles\Tests\Support\Scenarios;
use SpaceWork\TutorCourseBundles\Tests\Support\TestCase;

/**
 * @covers \SpaceWork\TutorCourseBundles\Infrastructure\BundleRepository
 */
final class BundleRepositoryTest extends TestCase {

	private BundleRepository $repo;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->repo = tcb()->get( BundleRepository::class );
	}

	/**
	 * Acceptance criterion 2: a duplicate course ID is never stored.
	 */
	public function test_a_course_cannot_be_added_twice(): void {
		$course = $this->seeder->course();
		$bundle = $this->seeder->bundle();

		$first = $this->repo->add_course( $bundle, $course );
		$this->assertIsInt( $first );

		$second = $this->repo->add_course( $bundle, $course );

		$this->assertWPError( $second );
		$this->assertSame( 'tcb_duplicate_course', $second->get_error_code() );
		$this->assertSame( 1, $this->countRows( 'courses', array( 'bundle_id' => $bundle ) ) );
	}

	/**
	 * `set_courses()` receiving the same ID twice keeps one row rather than
	 * failing the whole save — the admin form can produce this after a
	 * double-click.
	 */
	public function test_set_courses_silently_deduplicates(): void {
		$course = $this->seeder->course();
		$bundle = $this->seeder->bundle();

		$this->repo->set_courses(
			$bundle,
			array(
				array( 'course_id' => $course ),
				array( 'course_id' => $course ),
				array( 'course_id' => $course ),
			)
		);

		$this->assertSame( 1, $this->countRows( 'courses', array( 'bundle_id' => $bundle ) ) );
	}

	/**
	 * Only Tutor courses may be bundled; a page or a post must be rejected.
	 */
	public function test_non_courses_are_rejected(): void {
		$bundle = $this->seeder->bundle();
		$page   = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$result = $this->repo->add_course( $bundle, (int) $page );

		$this->assertWPError( $result );
		$this->assertSame( 'tcb_not_a_course', $result->get_error_code() );
	}

	/**
	 * Ordering is what learners see as the path through the bundle, so it must
	 * round-trip exactly.
	 */
	public function test_courses_come_back_in_the_stored_order(): void {
		$courses = $this->seeder->courses( 4 );
		$bundle  = $this->seeder->bundle( array( 'courses' => $courses ) );

		$this->assertSame( $courses, $this->repo->get_course_ids( $bundle ) );

		$reversed = array_reverse( $courses );
		$this->repo->reorder( $bundle, $reversed );

		$this->assertSame( $reversed, $this->repo->get_course_ids( $bundle ) );
	}

	/**
	 * `set_courses()` reports exactly what changed, which is what drives the
	 * "grant new courses to existing learners" behaviour.
	 */
	public function test_set_courses_reports_the_diff(): void {
		$a      = $this->seeder->course();
		$b      = $this->seeder->course();
		$c      = $this->seeder->course();
		$bundle = $this->seeder->bundle( array( 'courses' => array( $a, $b ) ) );

		$diff = $this->repo->set_courses(
			$bundle,
			array(
				array( 'course_id' => $b ),
				array( 'course_id' => $c ),
			)
		);

		$this->assertSame( array( $c ), $diff['added'] );
		$this->assertSame( array( $a ), $diff['removed'] );
		$this->assertSame( array( $b ), $diff['kept'] );
		$this->assertSame( array( $b, $c ), $this->repo->get_course_ids( $bundle ) );
	}

	/**
	 * The cached course count on the bundle drives admin columns and the
	 * purchase box, so it must track the table.
	 */
	public function test_course_count_meta_stays_in_sync(): void {
		$courses = $this->seeder->courses( 3 );
		$bundle  = $this->seeder->bundle( array( 'courses' => $courses ) );

		$this->assertSame( 3, (int) get_post_meta( $bundle, Bundle::META_COURSE_COUNT, true ) );

		$this->repo->remove_course( $bundle, $courses[0] );

		$this->assertSame( 2, (int) get_post_meta( $bundle, Bundle::META_COURSE_COUNT, true ) );
	}

	/**
	 * Unpublished and deleted courses are filtered out of the learner-facing
	 * list but stay in the admin one.
	 */
	public function test_only_available_courses_are_shown_to_learners(): void {
		$world = $this->scenarios->bundle_with_broken_courses();

		$all       = $this->repo->get_courses( $world['bundle'] );
		$available = $this->repo->get_courses( $world['bundle'], true );

		$this->assertCount( 3, $all, 'The admin view keeps every row, including broken ones.' );
		$this->assertCount( 1, $available, 'Learners only see the published course.' );
		$this->assertSame( $world['ok'], $available[0]->course_id );
	}

	/**
	 * Deleting a bundle for good cleans up its membership rows.
	 */
	public function test_deleting_a_bundle_removes_its_membership_rows(): void {
		$courses = $this->seeder->courses( 2 );
		$bundle  = $this->seeder->bundle( array( 'courses' => $courses ) );

		$this->assertSame( 2, $this->countRows( 'courses', array( 'bundle_id' => $bundle ) ) );

		wp_delete_post( $bundle, true );

		$this->assertSame( 0, $this->countRows( 'courses', array( 'bundle_id' => $bundle ) ) );
	}

	/**
	 * A course in several bundles must be discoverable from either direction —
	 * this reverse lookup is what completion checks walk.
	 */
	public function test_reverse_lookup_finds_every_bundle_holding_a_course(): void {
		$shared  = $this->seeder->course();
		$bundle1 = $this->seeder->bundle( array( 'courses' => array( $shared ) ) );
		$bundle2 = $this->seeder->bundle( array( 'courses' => array( $shared ) ) );
		$this->seeder->bundle( array( 'courses' => array( $this->seeder->course() ) ) );

		$found = $this->repo->get_bundles_containing_course( $shared );

		sort( $found );
		$expected = array( $bundle1, $bundle2 );
		sort( $expected );

		$this->assertSame( $expected, $found );
	}

	/**
	 * Required and optional courses are stored distinctly, because only the
	 * required ones gate completion.
	 */
	public function test_optional_courses_are_excluded_from_the_required_set(): void {
		$required = $this->seeder->courses( 2 );
		$optional = $this->seeder->course();

		$bundle = $this->seeder->bundle(
			array(
				'courses'          => $required,
				'optional_courses' => array( $optional ),
			)
		);

		$this->assertCount( 3, $this->repo->get_courses( $bundle, true ) );

		$required_ids = array_map(
			static fn( BundleCourse $c ): int => $c->course_id,
			$this->repo->get_required_courses( $bundle )
		);

		$this->assertSame( $required, $required_ids );
		$this->assertNotContains( $optional, $required_ids );
	}

	/**
	 * Every bundle shape in the fixture file must persist and read back with
	 * the properties the fixture declares.
	 *
	 * @dataProvider provideBundleShapes
	 *
	 * @param string $key Fixture key.
	 */
	public function test_seeded_bundle_shapes_round_trip( string $key ): void {
		$built = $this->scenarios->from_fixture( 'bundle-shapes' );

		$this->assertArrayHasKey( $key, $built );

		$case     = $built[ $key ];
		$expected = $case['spec']['expected'];
		$bundle   = Bundle::from( $case['bundle'] );

		$this->assertInstanceOf( Bundle::class, $bundle );

		$this->assertSame( $expected['is_free'], $bundle->is_free(), "[{$key}] is_free()" );
		$this->assertSame( (float) $expected['effective_price'], $bundle->get_effective_price(), "[{$key}] effective price" );
		$this->assertSame(
			(int) $expected['course_count'],
			count( $this->repo->get_courses( $case['bundle'] ) ),
			"[{$key}] course count"
		);

		if ( isset( $expected['available_course_count'] ) ) {
			$this->assertSame(
				(int) $expected['available_course_count'],
				count( $this->repo->get_courses( $case['bundle'], true ) ),
				"[{$key}] available course count"
			);
		}

		if ( isset( $expected['required_count'] ) ) {
			$this->assertSame(
				(int) $expected['required_count'],
				count( $this->repo->get_required_courses( $case['bundle'] ) ),
				"[{$key}] required course count"
			);
		}

		if ( isset( $expected['access_duration'] ) ) {
			$this->assertSame( (int) $expected['access_duration'], $bundle->get_access_duration_days(), "[{$key}] access duration" );
		}

		$this->assertSame( $expected['purchasable'], $bundle->is_purchasable(), "[{$key}] purchasable" );
	}

	/**
	 * `is_purchasable()` only reports the post status. Whether a bundle can
	 * actually be sold also depends on it containing something — an empty
	 * bundle is published but must never reach checkout.
	 */
	public function test_an_empty_published_bundle_is_not_sellable(): void {
		$bundle = $this->seeder->bundle( array( 'title' => 'Nothing inside' ) );

		$state = tcb()->get( \SpaceWork\TutorCourseBundles\Frontend\AccessController::class )
			->get_purchase_state( $bundle, 0 );

		$this->assertTrue( Bundle::from( $bundle )->is_purchasable(), 'The post itself is published.' );
		$this->assertFalse( $state['available'], 'A bundle with no usable courses must not be sellable.' );
	}

	/**
	 * Fixture keys.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provideBundleShapes(): array {
		$cases = array();

		foreach ( array_keys( Scenarios::load_fixture( 'bundle-shapes' ) ) as $key ) {
			$cases[ $key ] = array( $key );
		}

		return $cases;
	}
}
