<?php
/**
 * WooCommerce integration.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Commerce;

use SpaceWork\TutorCourseBundles\Compatibility;
use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Domain\BundleOrder;
use SpaceWork\TutorCourseBundles\Infrastructure\AccessRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\BundleRepository;
use SpaceWork\TutorCourseBundles\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a bundle onto a simple, virtual WooCommerce product and reads orders
 * back out again.
 *
 * The product is a thin shell: pricing lives on the bundle, and the product is
 * kept in sync whenever the bundle is saved. Everything about entitlements
 * stays in this plugin's own tables.
 */
final class WooCommerceGateway implements CommerceGateway {

	public const PRODUCT_META_BUNDLE = '_tcb_linked_bundle_id';
	public const ORDER_ITEM_META     = '_tcb_bundle_id';

	/**
	 * Constructor.
	 *
	 * @param BundleRepository $bundles Bundle repository.
	 * @param AccessRepository $access  Entitlement repository.
	 */
	public function __construct(
		private readonly BundleRepository $bundles,
		private readonly AccessRepository $access
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'woocommerce';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $bundle_id Bundle post ID.
	 */
	public function supports( int $bundle_id ): bool {
		if ( ! Compatibility::woocommerce_active() ) {
			return false;
		}

		$bundle = $this->bundles->find( $bundle_id );

		return $bundle instanceof Bundle && ! $bundle->is_free();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $bundle_id Bundle post ID.
	 */
	public function get_purchase_url( int $bundle_id ): string {
		$product_id = $this->get_product_id( $bundle_id );

		if ( $product_id <= 0 ) {
			return '';
		}

		$checkout_direct = 'yes' === get_option( 'tcb_wc_direct_checkout', 'no' );
		$base            = $checkout_direct && function_exists( 'wc_get_checkout_url' )
			? wc_get_checkout_url()
			: home_url( '/' );

		return add_query_arg(
			array(
				'add-to-cart' => $product_id,
				'quantity'    => 1,
			),
			$base
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $bundle_id Bundle post ID.
	 */
	public function get_button_label( int $bundle_id ): string {
		return __( 'Buy Bundle', 'tutor-course-bundles' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $user_id   User ID.
	 * @param int $bundle_id Bundle post ID.
	 */
	public function user_has_purchased( int $user_id, int $bundle_id ): bool {
		if ( $user_id <= 0 || ! Compatibility::woocommerce_active() ) {
			return false;
		}

		foreach ( $this->access->find_for_user_bundle( $bundle_id, $user_id ) as $access ) {
			if ( BundleAccess::SOURCE_WOOCOMMERCE === $access->source_type && ! $access->is_terminated() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $user_id   User ID.
	 * @param int    $bundle_id Bundle post ID.
	 * @param string $reason    Audit reason.
	 */
	public function revoke_access( int $user_id, int $bundle_id, string $reason ): void {
		// Revocation is driven from order status changes in CommerceManager.
	}

	/**
	 * Linked product ID for a bundle, 0 when none.
	 *
	 * @param int $bundle_id Bundle post ID.
	 */
	public function get_product_id( int $bundle_id ): int {
		$product_id = (int) get_post_meta( $bundle_id, Bundle::META_WC_PRODUCT_ID, true );

		if ( $product_id <= 0 ) {
			return 0;
		}

		if ( 'product' !== get_post_type( $product_id ) ) {
			return 0;
		}

		return $product_id;
	}

	/**
	 * Create or update the WooCommerce product that represents a bundle.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @return int|\WP_Error Product ID.
	 */
	public function sync_product( int $bundle_id ) {
		if ( ! Compatibility::woocommerce_active() ) {
			return new \WP_Error( 'tcb_wc_inactive', __( 'WooCommerce is not active.', 'tutor-course-bundles' ) );
		}

		$bundle = $this->bundles->find( $bundle_id );

		if ( ! $bundle instanceof Bundle ) {
			return new \WP_Error( 'tcb_bundle_not_found', __( 'Bundle not found.', 'tutor-course-bundles' ) );
		}

		if ( $bundle->is_free() ) {
			return new \WP_Error( 'tcb_bundle_is_free', __( 'Free bundles do not need a product.', 'tutor-course-bundles' ) );
		}

		$product_id = $this->get_product_id( $bundle_id );
		$product    = $product_id > 0 ? wc_get_product( $product_id ) : null;

		if ( ! $product instanceof \WC_Product ) {
			$product = new \WC_Product_Simple();
		}

		$product->set_name( $bundle->get_title() );
		$product->set_status( 'publish' === $bundle->post->post_status ? 'publish' : 'draft' );
		$product->set_catalog_visibility( 'yes' === get_option( 'tcb_wc_hide_from_catalog', 'no' ) ? 'hidden' : 'visible' );
		$product->set_virtual( true );
		$product->set_downloadable( false );
		$product->set_sold_individually( true );
		$product->set_short_description( $bundle->get_short_description() );
		$product->set_regular_price( (string) $bundle->get_price() );

		$sale = $bundle->get_sale_price();
		$product->set_sale_price( null === $sale ? '' : (string) $sale );

		$thumbnail_id = (int) get_post_thumbnail_id( $bundle_id );

		if ( $thumbnail_id > 0 ) {
			$product->set_image_id( $thumbnail_id );
		}

		$new_product_id = $product->save();

		if ( ! $new_product_id ) {
			return new \WP_Error( 'tcb_product_save_failed', __( 'Could not save the WooCommerce product.', 'tutor-course-bundles' ) );
		}

		update_post_meta( $new_product_id, self::PRODUCT_META_BUNDLE, $bundle_id );
		update_post_meta( $bundle_id, Bundle::META_WC_PRODUCT_ID, $new_product_id );

		/**
		 * Fires after a bundle's WooCommerce product is created or updated.
		 *
		 * @param int $product_id Product ID.
		 * @param int $bundle_id  Bundle post ID.
		 */
		do_action( 'tcb/woocommerce/product_synced', (int) $new_product_id, $bundle_id );

		return (int) $new_product_id;
	}

	/**
	 * Detach the product from a bundle without deleting it.
	 *
	 * @param int $bundle_id Bundle post ID.
	 */
	public function unlink_product( int $bundle_id ): void {
		$product_id = $this->get_product_id( $bundle_id );

		if ( $product_id > 0 ) {
			delete_post_meta( $product_id, self::PRODUCT_META_BUNDLE );
		}

		delete_post_meta( $bundle_id, Bundle::META_WC_PRODUCT_ID );
	}

	/**
	 * Persist the bundle identity on a WooCommerce order item.
	 *
	 * Product metadata is mutable; the order item is the immutable record later
	 * payment and refund handlers must be able to trust.
	 *
	 * @param \WC_Order_Item_Product $item          Order item being created.
	 * @param string                 $cart_item_key Cart item key.
	 * @param array<string, mixed>   $values        Cart item values.
	 * @param \WC_Order              $order         Parent order.
	 */
	public function add_bundle_order_item_meta( $item, $cart_item_key, $values, $order ): void {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		$product_id = (int) ( $values['product_id'] ?? $item->get_product_id() );
		$bundle     = $this->bundles->find_by_wc_product( $product_id );

		if ( $bundle instanceof Bundle ) {
			$item->add_meta_data( self::ORDER_ITEM_META, $bundle->get_id(), true );
		}
	}

	/**
	 * Extract the bundle lines from a WooCommerce order.
	 *
	 * @param \WC_Order $order Order object.
	 * @return BundleOrder[]
	 */
	public function extract_bundle_orders( \WC_Order $order ): array {
		$orders  = array();
		$user_id = (int) $order->get_customer_id();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product_id = (int) $item->get_product_id();
			$bundle_id  = (int) $item->get_meta( self::ORDER_ITEM_META, true );
			$bundle     = $bundle_id > 0 ? $this->bundles->find( $bundle_id ) : null;

			// Legacy orders created before immutable item metadata was introduced.
			if ( ! $bundle instanceof Bundle ) {
				$bundle = $this->bundles->find_by_wc_product( $product_id );
			}

			if ( ! $bundle instanceof Bundle ) {
				continue;
			}

			$orders[] = new BundleOrder(
				(int) $order->get_id(),
				$bundle->get_id(),
				$user_id,
				'woocommerce',
				(string) $order->get_status(),
				(float) $item->get_total(),
				(string) $order->get_currency(),
				$product_id
			);
		}

		return $orders;
	}

	/**
	 * Whether a WooCommerce order line refers to a bundle.
	 *
	 * @param int $product_id Product ID.
	 */
	public function is_bundle_product( int $product_id ): bool {
		return $this->bundles->find_by_wc_product( $product_id ) instanceof Bundle;
	}

	/**
	 * Gross revenue recorded against a bundle's product.
	 *
	 * @param int $bundle_id Bundle post ID.
	 */
	public function get_gross_revenue( int $bundle_id ): float {
		return $this->get_revenue_summary( $bundle_id )['gross'];
	}

	/**
	 * Gross, refunded, and net revenue for a bundle.
	 *
	 * Orders are discovered from the immutable access ledger rather than the
	 * bundle's current product mapping, so historical revenue survives remaps.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @return array{gross: float, refunded: float, net: float}
	 */
	public function get_revenue_summary( int $bundle_id ): array {
		$gross     = 0.0;
		$refunded  = 0.0;
		$order_ids = array();

		foreach ( $this->access->iterate(
			array(
				'bundle_id' => $bundle_id,
				'source'    => BundleAccess::SOURCE_WOOCOMMERCE,
			)
		) as $access ) {
			if ( $access->source_id > 0 ) {
				$order_ids[ $access->source_id ] = true;
			}
		}

		foreach ( array_keys( $order_ids ) as $order_id ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			foreach ( $order->get_items() as $item_id => $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}

				$item_bundle_id = (int) $item->get_meta( self::ORDER_ITEM_META, true );

				if ( $item_bundle_id <= 0 ) {
					$legacy         = $this->bundles->find_by_wc_product( (int) $item->get_product_id() );
					$item_bundle_id = $legacy instanceof Bundle ? $legacy->get_id() : 0;
				}

				if ( $item_bundle_id !== $bundle_id ) {
					continue;
				}

				$gross += (float) $item->get_total();

				if ( method_exists( $order, 'get_total_refunded_for_item' ) ) {
					$refunded += abs( (float) $order->get_total_refunded_for_item( (int) $item_id ) );
				}
			}
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

		return array(
			'gross'    => round( $gross, $decimals ),
			'refunded' => round( $refunded, $decimals ),
			'net'      => round( max( 0.0, $gross - $refunded ), $decimals ),
		);
	}
}
