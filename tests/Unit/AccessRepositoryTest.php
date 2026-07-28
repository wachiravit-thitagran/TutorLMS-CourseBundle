<?php
/**
 * Unit tests for entitlement read-model selection.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Unit;

use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Infrastructure\AccessRepository;

/**
 * @covers \SpaceWork\TutorCourseBundles\Infrastructure\AccessRepository
 */
final class AccessRepositoryTest extends UnitTestCase {

	/**
	 * A newer refund must not hide an older entitlement that still grants access.
	 */
	public function test_preferred_access_uses_a_usable_grant_before_newer_history(): void {
		$active   = new BundleAccess( 10, 100, 200, BundleAccess::SOURCE_MANUAL, 1, BundleAccess::STATUS_ACTIVE );
		$refunded = new BundleAccess( 11, 100, 200, BundleAccess::SOURCE_WOOCOMMERCE, 2, BundleAccess::STATUS_REFUNDED );

		$selected = AccessRepository::select_preferred_by_bundle( array( $refunded, $active ) );

		$this->assertSame( $active, $selected[100] );
	}

	/**
	 * With no usable grant, the newest historical row remains visible.
	 */
	public function test_preferred_access_falls_back_to_newest_history(): void {
		$older = new BundleAccess( 10, 100, 200, BundleAccess::SOURCE_MANUAL, 1, BundleAccess::STATUS_REVOKED );
		$newer = new BundleAccess( 11, 100, 200, BundleAccess::SOURCE_WOOCOMMERCE, 2, BundleAccess::STATUS_REFUNDED );

		$selected = AccessRepository::select_preferred_by_bundle( array( $newer, $older ) );

		$this->assertSame( $newer, $selected[100] );
	}
}
