<?php
/**
 * Unit tests for the gateway-agnostic order descriptor.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Unit;

use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Domain\BundleOrder;

/**
 * @covers \SpaceWork\TutorCourseBundles\Domain\BundleOrder
 */
final class BundleOrderTest extends UnitTestCase {

	/**
	 * WooCommerce orders must land in the ledger under the canonical source
	 * type, because the refund path looks entitlements up by exactly that
	 * string. A mismatch here would silently orphan every refund.
	 */
	public function test_woocommerce_maps_to_the_canonical_source_type(): void {
		$order = new BundleOrder( 500, 10, 20, 'woocommerce', 'completed', 149.0, 'USD', 77 );

		$this->assertSame( BundleAccess::SOURCE_WOOCOMMERCE, $order->get_source_type() );
	}

	/**
	 * A future gateway keeps its own identifier rather than being forced into
	 * the WooCommerce bucket.
	 */
	public function test_other_gateways_keep_their_own_identifier(): void {
		$order = new BundleOrder( 500, 10, 20, 'subscription' );

		$this->assertSame( 'subscription', $order->get_source_type() );
	}

	/**
	 * The array form is what audit entries record, so every field survives.
	 */
	public function test_to_array_round_trip(): void {
		$order = new BundleOrder( 500, 10, 20, 'woocommerce', 'processing', 99.5, 'THB', 77 );
		$data  = $order->to_array();

		$this->assertSame( 500, $data['order_id'] );
		$this->assertSame( 10, $data['bundle_id'] );
		$this->assertSame( 20, $data['user_id'] );
		$this->assertSame( 'woocommerce', $data['gateway'] );
		$this->assertSame( 'processing', $data['status'] );
		$this->assertSame( 99.5, $data['total'] );
		$this->assertSame( 'THB', $data['currency'] );
		$this->assertSame( 77, $data['product_id'] );
	}

	/**
	 * A guest checkout has no customer ID; the object must carry the zero
	 * through rather than guessing, so the commerce layer can skip it loudly.
	 */
	public function test_guest_orders_carry_a_zero_user(): void {
		$order = new BundleOrder( 500, 10, 0 );

		$this->assertSame( 0, $order->user_id );
	}
}
