<?php
/**
 * Unit tests for the bundle aggregate's pricing and access rules.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Unit;

use Brain\Monkey\Filters;
use SpaceWork\TutorCourseBundles\Domain\Bundle;

/**
 * @covers \SpaceWork\TutorCourseBundles\Domain\Bundle
 */
final class BundleTest extends UnitTestCase {

	/**
	 * Build a bundle backed by the in-memory meta store.
	 *
	 * @param array<string, mixed> $meta Meta values.
	 * @param array<string, mixed> $post Post property overrides.
	 */
	private function makeBundle( array $meta = array(), array $post = array() ): Bundle {
		$wp_post = $this->givenPost( array_merge( array( 'ID' => 42, 'post_title' => 'Test bundle' ), $post ) );

		$this->givenMeta( (int) $wp_post->ID, $meta );

		$bundle = Bundle::from( $wp_post );

		$this->assertInstanceOf( Bundle::class, $bundle );

		return $bundle;
	}

	/**
	 * A post of the wrong type is not a bundle, and `from()` must say so rather
	 * than hand back a half-working object.
	 */
	public function test_from_rejects_other_post_types(): void {
		$post = $this->givenPost( array( 'ID' => 42, 'post_type' => 'courses' ) );

		$this->assertNull( Bundle::from( $post ) );
	}

	/**
	 * Sale price only counts when it is genuinely lower than the regular price.
	 * A "sale" of 200 on a 100 product is a data entry mistake, not a discount.
	 *
	 * @dataProvider providePricing
	 *
	 * @param float      $price          Regular price.
	 * @param mixed      $sale           Sale price meta value.
	 * @param float|null $expected_sale  Expected sale price.
	 * @param float      $expected_price Expected effective price.
	 */
	public function test_effective_price( float $price, $sale, ?float $expected_sale, float $expected_price ): void {
		$bundle = $this->makeBundle(
			array(
				Bundle::META_ACCESS_TYPE => Bundle::ACCESS_TYPE_PAID,
				Bundle::META_PRICE       => $price,
				Bundle::META_SALE_PRICE  => $sale,
			)
		);

		$this->assertSame( $expected_sale, $bundle->get_sale_price() );
		$this->assertSame( $expected_price, $bundle->get_effective_price() );
	}

	/**
	 * Pricing cases.
	 *
	 * @return array<string, array{0: float, 1: mixed, 2: float|null, 3: float}>
	 */
	public static function providePricing(): array {
		return array(
			'no sale'                 => array( 100.0, '', null, 100.0 ),
			'genuine discount'        => array( 100.0, 60.0, 60.0, 60.0 ),
			'sale higher than price'  => array( 100.0, 200.0, null, 100.0 ),
			'sale equal to price'     => array( 100.0, 100.0, null, 100.0 ),
			'zero sale is ignored'    => array( 100.0, 0.0, null, 100.0 ),
		);
	}

	/**
	 * "Free" has two independent triggers: the access type, and a price that
	 * works out to nothing. Both must be honoured, or a paid bundle priced at 0
	 * would sit behind a checkout that charges nothing.
	 *
	 * @dataProvider provideFreeCases
	 *
	 * @param string $access_type Access type meta.
	 * @param float  $price       Price meta.
	 * @param bool   $expected    Whether the bundle is free.
	 */
	public function test_is_free( string $access_type, float $price, bool $expected ): void {
		$bundle = $this->makeBundle(
			array(
				Bundle::META_ACCESS_TYPE => $access_type,
				Bundle::META_PRICE       => $price,
			)
		);

		$this->assertSame( $expected, $bundle->is_free() );
	}

	/**
	 * Free/paid cases.
	 *
	 * @return array<string, array{0: string, 1: float, 2: bool}>
	 */
	public static function provideFreeCases(): array {
		return array(
			'explicitly free'    => array( Bundle::ACCESS_TYPE_FREE, 0.0, true ),
			'free with a price'  => array( Bundle::ACCESS_TYPE_FREE, 99.0, true ),
			'paid with a price'  => array( Bundle::ACCESS_TYPE_PAID, 99.0, false ),
			'paid priced at nil' => array( Bundle::ACCESS_TYPE_PAID, 0.0, true ),
		);
	}

	/**
	 * The price filter is the documented extension point for coupons and
	 * member pricing, so it has to actually be applied.
	 */
	public function test_price_filter_is_applied(): void {
		$bundle = $this->makeBundle(
			array(
				Bundle::META_ACCESS_TYPE => Bundle::ACCESS_TYPE_PAID,
				Bundle::META_PRICE       => 100.0,
			)
		);

		Filters\expectApplied( 'tcb/bundle/price' )
			->once()
			->with( 100.0, 42, 0 )
			->andReturn( 42.0 );

		$this->assertSame( 42.0, $bundle->get_effective_price() );
	}

	/**
	 * Zero days means lifetime; anything else lands exactly that many days out.
	 *
	 * @dataProvider provideDurations
	 *
	 * @param int  $days     Configured duration.
	 * @param bool $lifetime Whether the result should be null.
	 */
	public function test_calculate_expiry( int $days, bool $lifetime ): void {
		$bundle = $this->makeBundle( array( Bundle::META_ACCESS_DURATION => $days ) );

		$from   = 1_800_000_000;
		$expiry = $bundle->calculate_expiry( $from );

		if ( $lifetime ) {
			$this->assertNull( $expiry );
			return;
		}

		$this->assertSame(
			gmdate( 'Y-m-d H:i:s', $from + ( $days * DAY_IN_SECONDS ) ),
			$expiry
		);
	}

	/**
	 * Duration cases.
	 *
	 * @return array<string, array{0: int, 1: bool}>
	 */
	public static function provideDurations(): array {
		return array(
			'lifetime'  => array( 0, true ),
			'one day'   => array( 1, false ),
			'a month'   => array( 30, false ),
			'a year'    => array( 365, false ),
		);
	}

	/**
	 * Only published bundles may be sold; a draft that is still purchasable is
	 * how sites accidentally sell unfinished products.
	 *
	 * @dataProvider providePostStatuses
	 *
	 * @param string $status      Post status.
	 * @param bool   $purchasable Expected result.
	 */
	public function test_only_published_bundles_are_purchasable( string $status, bool $purchasable ): void {
		$bundle = $this->makeBundle( array(), array( 'post_status' => $status ) );

		$this->assertSame( $purchasable, $bundle->is_purchasable() );
	}

	/**
	 * Post status cases.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function providePostStatuses(): array {
		return array(
			'published' => array( 'publish', true ),
			'draft'     => array( 'draft', false ),
			'pending'   => array( 'pending', false ),
			'private'   => array( 'private', false ),
			'trashed'   => array( 'trash', false ),
		);
	}

	/**
	 * New courses are granted to existing learners unless explicitly disabled;
	 * the default matters because most sites never touch the setting.
	 */
	public function test_new_courses_are_granted_by_default(): void {
		$this->assertTrue( $this->makeBundle()->grants_new_courses() );

		$this->assertFalse(
			$this->makeBundle( array( Bundle::META_GRANT_NEW_COURSES => 'no' ) )->grants_new_courses()
		);
	}

	/**
	 * Sensible defaults so a bundle saved with nothing but a title still works.
	 */
	public function test_defaults_are_safe(): void {
		$bundle = $this->makeBundle();

		$this->assertSame( Bundle::ACCESS_TYPE_FREE, $bundle->get_access_type() );
		$this->assertSame( Bundle::ENROLLMENT_IMMEDIATE, $bundle->get_enrollment_mode() );
		$this->assertSame( Bundle::COMPLETION_ALL_REQUIRED, $bundle->get_completion_mode() );
		$this->assertSame( 0, $bundle->get_course_count() );
		$this->assertSame( 0, $bundle->get_wc_product_id() );
		$this->assertSame( 0, $bundle->get_access_duration_days() );
		$this->assertSame( 'all_levels', $bundle->get_difficulty() );
	}

	/**
	 * The short description falls back to trimmed content so cards are never
	 * blank just because an editor skipped the field.
	 */
	public function test_short_description_falls_back_to_content(): void {
		$bundle = $this->makeBundle(
			array(),
			array( 'post_content' => 'Learn the whole stack from scratch with hands-on projects.' )
		);

		$this->assertStringContainsString( 'Learn the whole stack', $bundle->get_short_description() );

		$explicit = $this->makeBundle( array( Bundle::META_SHORT_DESCRIPTION => 'Custom blurb.' ) );

		$this->assertSame( 'Custom blurb.', $explicit->get_short_description() );
	}
}
