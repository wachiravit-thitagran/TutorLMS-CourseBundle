<?php
/**
 * WooCommerce order lifecycle.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Integration;

use SpaceWork\TutorCourseBundles\Commerce\CommerceManager;
use SpaceWork\TutorCourseBundles\Commerce\WooCommerceGateway;
use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Infrastructure\AccessRepository;
use SpaceWork\TutorCourseBundles\Tests\Support\TestCase;

/**
 * Buying, refunding and cancelling.
 *
 * WooCommerce fires order-status hooks more than once for the same order, and
 * webhooks retry, so most of this file is about proving the same event twice
 * produces one outcome.
 *
 * @covers \SpaceWork\TutorCourseBundles\Commerce\CommerceManager
 * @covers \SpaceWork\TutorCourseBundles\Commerce\WooCommerceGateway
 * @covers \SpaceWork\TutorCourseBundles\Commerce\RefundHandler
 */
final class WooCommerceOrderTest extends TestCase {

	private CommerceManager $commerce;
	private AccessRepository $access;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->require_tutor();
		$this->require_woocommerce();

		$this->commerce = tcb()->get( CommerceManager::class );
		$this->access   = tcb()->get( AccessRepository::class );
	}

	/**
	 * Acceptance criteria 3 and 4: a completed order grants the bundle and
	 * enrolls the buyer in every course.
	 */
	public function test_a_completed_order_grants_the_bundle(): void {
		$world = $this->scenarios->woocommerce_purchase();

		$this->commerce->handle_order_paid( $world['order'] );

		$this->assertTrue(
			$this->access->user_has_access( $world['bundle'], $world['user'] ),
			'A paid order must grant the bundle.'
		);

		foreach ( $world['courses'] as $course_id ) {
			$this->assertEnrolled( $course_id, $world['user'] );
		}
	}

	/**
	 * Acceptance criterion 5: WooCommerce firing `processing` and then
	 * `completed` for the same order must not double-enroll anyone.
	 */
	public function test_repeated_order_hooks_are_idempotent(): void {
		$world = $this->scenarios->woocommerce_purchase();

		$this->commerce->handle_order_paid( $world['order'] );
		$this->commerce->handle_order_paid( $world['order'] );
		$this->commerce->handle_order_paid( $world['order'] );

		$this->assertSame(
			1,
			$this->countRows( 'access', array( 'bundle_id' => $world['bundle'], 'user_id' => $world['user'] ) ),
			'Three hook fires must still produce one entitlement.'
		);

		$access = $this->access->find_active( $world['bundle'], $world['user'] );

		$this->assertNotNull( $access );
		$this->assertSame(
			count( $world['courses'] ),
			$this->countRows( 'enrollments', array( 'bundle_access_id' => $access->id ) ),
			'Each course must be enrolled exactly once.'
		);
	}

	/**
	 * The entitlement records which order paid for it, which is what the refund
	 * path looks up.
	 */
	public function test_the_entitlement_records_its_order(): void {
		$world = $this->scenarios->woocommerce_purchase();

		$this->commerce->handle_order_paid( $world['order'] );

		$access = $this->access->find_active( $world['bundle'], $world['user'] );

		$this->assertSame( BundleAccess::SOURCE_WOOCOMMERCE, $access->source_type );
		$this->assertSame( $world['order'], $access->source_id );
	}

	/**
	 * The order line carries the bundle identity, so a later product edit — or
	 * a product deletion — cannot orphan the purchase.
	 */
	public function test_the_order_line_records_the_bundle_directly(): void {
		$world = $this->scenarios->woocommerce_purchase();

		$order = wc_get_order( $world['order'] );
		$items = $order->get_items();
		$item  = reset( $items );

		$this->assertSame(
			$world['bundle'],
			(int) $item->get_meta( WooCommerceGateway::ORDER_ITEM_META, true )
		);

		// Break the product link entirely; the order must still resolve.
		tcb()->get( WooCommerceGateway::class )->unlink_product( $world['bundle'] );

		$this->commerce->handle_order_paid( $world['order'] );

		$this->assertTrue(
			$this->access->user_has_access( $world['bundle'], $world['user'] ),
			'The order item meta should survive the product link being broken.'
		);
	}

	/**
	 * Orders placed before item metadata existed still resolve through the
	 * product, so an upgrade does not strand in-flight purchases.
	 */
	public function test_legacy_orders_resolve_through_the_product(): void {
		$user  = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses(
			2,
			array( 'access_type' => Bundle::ACCESS_TYPE_PAID, 'price' => 30.0 )
		);

		$order_id = $this->seeder->wc_order( $built['bundle'], $user, 'pending', false );

		$this->commerce->handle_order_paid( $order_id );

		$this->assertTrue( $this->access->user_has_access( $built['bundle'], $user ) );
	}

	/**
	 * Acceptance criterion 8: refunding revokes what that order granted.
	 */
	public function test_a_refund_revokes_the_bundle(): void {
		$world = $this->scenarios->woocommerce_purchase();

		$this->commerce->handle_order_paid( $world['order'] );
		$this->commerce->handle_order_refunded( $world['order'] );

		$this->assertFalse( $this->access->user_has_access( $world['bundle'], $world['user'] ) );

		foreach ( $world['courses'] as $course_id ) {
			$this->assertNotEnrolled( $course_id, $world['user'] );
		}

		$accesses = $this->access->find_for_user_bundle( $world['bundle'], $world['user'] );

		$this->assertSame( BundleAccess::STATUS_REFUNDED, $accesses[0]->status );
	}

	/**
	 * A cancelled order is recorded as cancelled rather than refunded, so
	 * reports can tell an abandoned checkout from a real refund.
	 */
	public function test_a_cancelled_order_revokes_with_its_own_status(): void {
		$world = $this->scenarios->woocommerce_purchase();

		$this->commerce->handle_order_paid( $world['order'] );
		$this->commerce->handle_order_cancelled( $world['order'] );

		$accesses = $this->access->find_for_user_bundle( $world['bundle'], $world['user'] );

		$this->assertSame( BundleAccess::STATUS_CANCELLED, $accesses[0]->status );
	}

	/**
	 * Refunding an order the plugin never granted must be harmless.
	 */
	public function test_refunding_an_unknown_order_is_a_no_op(): void {
		$this->commerce->handle_order_refunded( 999999 );

		$this->assertSame( 0, $this->countRows( 'access' ) );
	}

	/**
	 * A guest checkout has no user to enroll. It must be skipped and recorded,
	 * not fatal.
	 */
	public function test_a_guest_order_is_skipped_and_logged(): void {
		$built = $this->seeder->bundle_with_courses(
			1,
			array( 'access_type' => Bundle::ACCESS_TYPE_PAID, 'price' => 25.0 )
		);

		$order_id = $this->seeder->wc_order( $built['bundle'], 0, 'pending' );

		$this->commerce->handle_order_paid( $order_id );

		$this->assertSame( 0, $this->countRows( 'access', array( 'bundle_id' => $built['bundle'] ) ) );

		$actions = array_column(
			\SpaceWork\TutorCourseBundles\Support\Logger::get_entries( array( 'bundle_id' => $built['bundle'] ) ),
			'action'
		);

		$this->assertContains( 'access.guest_order_skipped', $actions );
	}

	/**
	 * Paid bundles cannot enter a guest cart because an entitlement needs a
	 * stable WordPress user ID.
	 */
	public function test_a_guest_cannot_add_a_bundle_to_the_cart(): void {
		$built = $this->seeder->bundle_with_courses(
			1,
			array( 'access_type' => Bundle::ACCESS_TYPE_PAID, 'price' => 25.0 )
		);

		$product_id = $this->seeder->wc_product( $built['bundle'] );

		wp_set_current_user( 0 );

		$this->assertFalse( $this->commerce->validate_add_to_cart( true, $product_id, 1 ) );
	}

	/**
	 * Saving a paid bundle creates and links a product automatically, and
	 * saving it again updates rather than duplicating.
	 */
	public function test_product_sync_creates_then_updates(): void {
		$gateway = tcb()->get( WooCommerceGateway::class );

		$built = $this->seeder->bundle_with_courses(
			1,
			array(
				'title'       => 'Syncable bundle',
				'access_type' => Bundle::ACCESS_TYPE_PAID,
				'price'       => 120.0,
			)
		);

		$product_id = $gateway->sync_product( $built['bundle'] );

		$this->assertIsInt( $product_id );
		$this->assertSame( $product_id, (int) get_post_meta( $built['bundle'], Bundle::META_WC_PRODUCT_ID, true ) );
		$this->assertSame( $built['bundle'], (int) get_post_meta( $product_id, WooCommerceGateway::PRODUCT_META_BUNDLE, true ) );

		$product = wc_get_product( $product_id );

		$this->assertTrue( $product->is_virtual() );
		$this->assertTrue( $product->is_sold_individually() );
		$this->assertSame( '120', (string) $product->get_regular_price() );

		update_post_meta( $built['bundle'], Bundle::META_PRICE, 90.0 );

		$again = $gateway->sync_product( $built['bundle'] );

		$this->assertSame( $product_id, $again, 'Re-syncing must reuse the same product.' );
		$this->assertSame( '90', (string) wc_get_product( $product_id )->get_regular_price() );
	}

	/**
	 * Free bundles have nothing to sell, so no product is created.
	 */
	public function test_free_bundles_get_no_product(): void {
		$gateway = tcb()->get( WooCommerceGateway::class );
		$built   = $this->seeder->bundle_with_courses( 1 );

		$result = $gateway->sync_product( $built['bundle'] );

		$this->assertWPError( $result );
		$this->assertSame( 'tcb_bundle_is_free', $result->get_error_code() );
	}

	/**
	 * The product resolves back to its bundle, which is how order lines are
	 * matched during checkout.
	 */
	public function test_a_product_resolves_back_to_its_bundle(): void {
		$built = $this->seeder->bundle_with_courses(
			1,
			array( 'access_type' => Bundle::ACCESS_TYPE_PAID, 'price' => 10.0 )
		);

		$product_id = $this->seeder->wc_product( $built['bundle'] );
		$resolved   = $this->seeder->repository()->find_by_wc_product( $product_id );

		$this->assertInstanceOf( Bundle::class, $resolved );
		$this->assertSame( $built['bundle'], $resolved->get_id() );
	}

	/**
	 * Acceptance criterion 10: an unpublished bundle cannot be bought, even if
	 * its product is still sitting in the catalogue.
	 */
	public function test_an_unpublished_bundle_is_not_purchasable(): void {
		$built = $this->seeder->bundle_with_courses(
			1,
			array( 'access_type' => Bundle::ACCESS_TYPE_PAID, 'price' => 10.0 )
		);

		$product_id = $this->seeder->wc_product( $built['bundle'] );

		wp_update_post(
			array(
				'ID'          => $built['bundle'],
				'post_status' => 'draft',
			)
		);

		$this->assertFalse(
			$this->commerce->filter_is_purchasable( true, wc_get_product( $product_id ) )
		);
	}

	/**
	 * A bundle whose courses have all gone must not stay on sale.
	 */
	public function test_a_bundle_with_no_usable_courses_is_not_purchasable(): void {
		$built = $this->seeder->bundle_with_courses(
			1,
			array( 'access_type' => Bundle::ACCESS_TYPE_PAID, 'price' => 10.0 )
		);

		$product_id = $this->seeder->wc_product( $built['bundle'] );

		wp_delete_post( $built['courses'][0], true );

		$this->assertFalse(
			$this->commerce->filter_is_purchasable( true, wc_get_product( $product_id ) )
		);
	}

	/**
	 * A learner who already owns the bundle is stopped at the cart.
	 */
	public function test_repeat_purchase_is_blocked(): void {
		$world = $this->scenarios->woocommerce_purchase();

		$this->commerce->handle_order_paid( $world['order'] );

		wp_set_current_user( $world['user'] );

		$this->assertFalse(
			$this->commerce->validate_add_to_cart( true, $world['product'], 1 ),
			'Owning the bundle must block adding it to the cart again.'
		);
	}

	/**
	 * Sites that sell renewals can turn the guard off.
	 */
	public function test_repeat_purchase_can_be_allowed(): void {
		$world = $this->scenarios->woocommerce_purchase();

		$this->commerce->handle_order_paid( $world['order'] );

		update_option( 'tcb_prevent_repurchase', 'no' );
		wp_set_current_user( $world['user'] );

		$allowed = $this->commerce->validate_add_to_cart( true, $world['product'], 1 );

		update_option( 'tcb_prevent_repurchase', 'yes' );

		$this->assertTrue( $allowed );
	}

	/**
	 * A partial refund is ambiguous, so the plugin flags it for review instead
	 * of quietly taking a course away.
	 */
	public function test_a_partial_refund_is_flagged_rather_than_enforced(): void {
		$world = $this->scenarios->woocommerce_purchase();

		$this->commerce->handle_order_paid( $world['order'] );

		tcb()->get( \SpaceWork\TutorCourseBundles\Commerce\RefundHandler::class )
			->handle_partial_refund( $world['order'], 10.0, 149.0 );

		$this->assertTrue(
			$this->access->user_has_access( $world['bundle'], $world['user'] ),
			'A partial refund must not revoke access on its own.'
		);

		$actions = array_column(
			\SpaceWork\TutorCourseBundles\Support\Logger::get_entries( array( 'bundle_id' => $world['bundle'] ) ),
			'action'
		);

		$this->assertContains( 'access.partial_refund_review', $actions );
	}

	/**
	 * A refund that covers the whole order is treated as a full refund.
	 */
	public function test_a_full_value_partial_refund_revokes(): void {
		$world = $this->scenarios->woocommerce_purchase();

		$this->commerce->handle_order_paid( $world['order'] );

		tcb()->get( \SpaceWork\TutorCourseBundles\Commerce\RefundHandler::class )
			->handle_partial_refund( $world['order'], 149.0, 149.0 );

		$this->assertFalse( $this->access->user_has_access( $world['bundle'], $world['user'] ) );
	}
}
