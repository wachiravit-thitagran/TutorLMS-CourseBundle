<?php
/**
 * Coordinates gateways and order lifecycle events.
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
use SpaceWork\TutorCourseBundles\Infrastructure\LockManager;
use SpaceWork\TutorCourseBundles\Infrastructure\PostTypes;
use SpaceWork\TutorCourseBundles\Support\Logger;
use SpaceWork\TutorCourseBundles\Tutor\EnrollmentService;

defined( 'ABSPATH' ) || exit;

/**
 * The commerce front door.
 *
 * Order status hooks are the noisiest part of the system: WooCommerce fires
 * `processing` and `completed` for the same order, webhooks retry, and admins
 * flip statuses by hand. Every path through here is wrapped in a per-order lock
 * and resolves to the same entitlement row.
 */
final class CommerceManager {

	/**
	 * Constructor.
	 *
	 * @param BundleRepository      $bundles     Bundle repository.
	 * @param AccessRepository      $access      Entitlement repository.
	 * @param EnrollmentService     $enrollments Enrollment engine.
	 * @param WooCommerceGateway    $woo         WooCommerce gateway.
	 * @param FreeEnrollmentGateway $free        Free gateway.
	 * @param RefundHandler         $refunds     Refund handler.
	 * @param LockManager           $locks       Advisory locks.
	 */
	public function __construct(
		private readonly BundleRepository $bundles,
		private readonly AccessRepository $access,
		private readonly EnrollmentService $enrollments,
		private readonly WooCommerceGateway $woo,
		private readonly FreeEnrollmentGateway $free,
		private readonly RefundHandler $refunds,
		private readonly LockManager $locks
	) {}

	/**
	 * Register WooCommerce and bundle-save hooks.
	 */
	public function register_hooks(): void {
		add_action( 'save_post_' . PostTypes::POST_TYPE, array( $this, 'maybe_sync_product' ), 30, 3 );

		if ( ! Compatibility::woocommerce_active() ) {
			return;
		}

		foreach ( array( 'processing', 'completed' ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( $this, 'handle_order_paid' ), 10, 2 );
		}

		foreach ( array( 'cancelled', 'failed' ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( $this, 'handle_order_cancelled' ), 10, 2 );
		}

		add_action( 'woocommerce_order_status_refunded', array( $this, 'handle_order_refunded' ), 10, 2 );
		add_action( 'woocommerce_order_refunded', array( $this, 'handle_partial_refund' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this->woo, 'add_bundle_order_item_meta' ), 10, 4 );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_checkout_account' ) );

		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 3 );
		add_filter( 'woocommerce_is_purchasable', array( $this, 'filter_is_purchasable' ), 10, 2 );
		add_filter( 'woocommerce_order_item_permalink', array( $this, 'filter_order_item_permalink' ), 10, 3 );
		add_filter( 'woocommerce_checkout_registration_required', array( $this, 'require_registration_for_bundle' ) );
	}

	/**
	 * Gateway responsible for a bundle.
	 *
	 * @param int $bundle_id Bundle post ID.
	 */
	public function get_gateway( int $bundle_id ): ?CommerceGateway {
		$gateways = array( $this->free, $this->woo );

		/**
		 * Filter the registered commerce gateways.
		 *
		 * @param CommerceGateway[] $gateways Gateways in priority order.
		 * @param int               $bundle_id Bundle post ID.
		 */
		$gateways = apply_filters( 'tcb/commerce/gateways', $gateways, $bundle_id );

		foreach ( $gateways as $gateway ) {
			if ( $gateway instanceof CommerceGateway && $gateway->supports( $bundle_id ) ) {
				return $gateway;
			}
		}

		return null;
	}

	/**
	 * The WooCommerce gateway, for callers that need product-specific methods.
	 */
	public function woocommerce(): WooCommerceGateway {
		return $this->woo;
	}

	/**
	 * Keep the linked product in step with the bundle.
	 *
	 * @param int      $post_id Bundle post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update.
	 */
	public function maybe_sync_product( int $post_id, \WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( 'yes' !== get_option( 'tcb_wc_auto_product', 'yes' ) ) {
			return;
		}

		$bundle = $this->bundles->find( $post_id );

		if ( ! $bundle instanceof Bundle || $bundle->is_free() ) {
			return;
		}

		$result = $this->woo->sync_product( $post_id );

		if ( is_wp_error( $result ) ) {
			Logger::debug(
				'Product sync failed.',
				array(
					'bundle_id' => $post_id,
					'error'     => $result->get_error_message(),
				)
			);
		}
	}

	/**
	 * An order was paid — grant every bundle it contains.
	 *
	 * @param int            $order_id Order ID.
	 * @param \WC_Order|null $order    Order object.
	 */
	public function handle_order_paid( $order_id, $order = null ): void {
		$order_id = (int) $order_id;
		$order    = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->locks->with_lock(
			'order_processing_' . $order_id,
			function () use ( $order, $order_id ): void {
				foreach ( $this->woo->extract_bundle_orders( $order ) as $bundle_order ) {
					$this->grant_from_order( $bundle_order );
				}

				Logger::debug( 'Order processed.', array( 'order_id' => $order_id ) );
			}
		);
	}

	/**
	 * Grant one bundle line from an order.
	 *
	 * @param BundleOrder $bundle_order Normalised order line.
	 */
	private function grant_from_order( BundleOrder $bundle_order ): void {
		if ( $bundle_order->user_id <= 0 ) {
			Logger::audit(
				'access.guest_order_skipped',
				array( 'order_id' => $bundle_order->order_id ),
				$bundle_order->bundle_id
			);

			return;
		}

		$existing = $this->access->find_by_source(
			$bundle_order->bundle_id,
			$bundle_order->user_id,
			BundleAccess::SOURCE_WOOCOMMERCE,
			$bundle_order->order_id
		);

		// Already granted by an earlier fire of the same hook — nothing to do.
		if ( $existing instanceof BundleAccess && BundleAccess::STATUS_ACTIVE === $existing->status ) {
			return;
		}

		$result = $this->enrollments->grant_access(
			$bundle_order->bundle_id,
			$bundle_order->user_id,
			BundleAccess::SOURCE_WOOCOMMERCE,
			$bundle_order->order_id,
			array(
				'note' => sprintf(
					/* translators: %d: WooCommerce order ID */
					__( 'WooCommerce order #%d', 'tutor-course-bundles' ),
					$bundle_order->order_id
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			Logger::audit(
				'access.grant_failed',
				array(
					'order_id' => $bundle_order->order_id,
					'error'    => $result->get_error_message(),
				),
				$bundle_order->bundle_id,
				$bundle_order->user_id
			);

			return;
		}

		/**
		 * Fires after a paid order grants a bundle.
		 *
		 * @param BundleOrder  $bundle_order Order line.
		 * @param BundleAccess $access       Entitlement.
		 */
		do_action( 'tcb/commerce/order_granted', $bundle_order, $result );
	}

	/**
	 * Order cancelled or failed.
	 *
	 * @param int            $order_id Order ID.
	 * @param \WC_Order|null $order    Order object.
	 */
	public function handle_order_cancelled( $order_id, $order = null ): void {
		$this->refunds->revoke_order( (int) $order_id, BundleAccess::STATUS_CANCELLED, 'order_cancelled' );
	}

	/**
	 * Order fully refunded.
	 *
	 * @param int            $order_id Order ID.
	 * @param \WC_Order|null $order    Order object.
	 */
	public function handle_order_refunded( $order_id, $order = null ): void {
		$this->refunds->revoke_order( (int) $order_id, BundleAccess::STATUS_REFUNDED, 'order_refunded' );
	}

	/**
	 * A refund was recorded against an order — may be partial.
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 */
	public function handle_partial_refund( $order_id, $refund_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->refunds->handle_partial_refund(
			(int) $order_id,
			(float) $order->get_total_refunded(),
			(float) $order->get_total()
		);
	}

	/**
	 * Block a second purchase of a bundle the learner already holds.
	 *
	 * @param bool $passed     Current validation result.
	 * @param int  $product_id Product being added.
	 * @param int  $quantity   Quantity.
	 */
	public function validate_add_to_cart( $passed, $product_id, $quantity ) {
		if ( ! $passed ) {
			return $passed;
		}

		if ( 'yes' !== get_option( 'tcb_prevent_repurchase', 'yes' ) ) {
			return $passed;
		}

		$bundle = $this->bundles->find_by_wc_product( (int) $product_id );

		if ( ! $bundle instanceof Bundle ) {
			return $passed;
		}

		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( __( 'Please log in or create an account before purchasing a course bundle.', 'tutor-course-bundles' ), 'error' );
			}

			return false;
		}

		if ( $user_id > 0 && $this->access->user_has_access( $bundle->get_id(), $user_id ) ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice(
					sprintf(
						/* translators: %s: bundle title */
						__( 'You are already enrolled in %s.', 'tutor-course-bundles' ),
						$bundle->get_title()
					),
					'error'
				);
			}

			return false;
		}

		return $passed;
	}

	/**
	 * Force account creation whenever the cart contains a bundle.
	 *
	 * @param bool $required Existing WooCommerce requirement.
	 */
	public function require_registration_for_bundle( $required ): bool {
		return (bool) $required || $this->cart_contains_bundle();
	}

	/**
	 * Final checkout guard in case a bundle entered the cart before login was
	 * enforced (for example through a restored session).
	 */
	public function validate_checkout_account(): void {
		if ( get_current_user_id() > 0 || ! $this->cart_contains_bundle() ) {
			return;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'An account is required to purchase a course bundle.', 'tutor-course-bundles' ), 'error' );
		}
	}

	/**
	 * Whether the current WooCommerce cart includes a linked bundle product.
	 */
	private function cart_contains_bundle(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product_id = (int) ( $cart_item['product_id'] ?? 0 );

			if ( $product_id > 0 && $this->woo->is_bundle_product( $product_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Stop bundle products being bought when the bundle is not published.
	 *
	 * @param bool        $purchasable Current value.
	 * @param \WC_Product $product     Product object.
	 */
	public function filter_is_purchasable( $purchasable, $product ) {
		if ( ! $purchasable || ! $product instanceof \WC_Product ) {
			return $purchasable;
		}

		$bundle = $this->bundles->find_by_wc_product( (int) $product->get_id() );

		if ( ! $bundle instanceof Bundle ) {
			return $purchasable;
		}

		if ( ! $bundle->is_purchasable() ) {
			return false;
		}

		// A bundle with no usable courses must not be sold.
		return array() !== $this->bundles->get_course_ids( $bundle->get_id(), true );
	}

	/**
	 * Point order-item links at the bundle page rather than the shell product.
	 *
	 * @param string    $permalink Current permalink.
	 * @param object    $item      Order item.
	 * @param \WC_Order $order    Order object.
	 */
	public function filter_order_item_permalink( $permalink, $item, $order ) {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return $permalink;
		}

		$bundle_id = (int) $item->get_meta( WooCommerceGateway::ORDER_ITEM_META, true );
		$bundle    = $bundle_id > 0 ? $this->bundles->find( $bundle_id ) : null;

		if ( ! $bundle instanceof Bundle ) {
			$bundle = $this->bundles->find_by_wc_product( (int) $item->get_product_id() );
		}

		return $bundle instanceof Bundle ? $bundle->get_permalink() : $permalink;
	}

	/**
	 * Grant a free bundle to a learner.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @param int $user_id   User ID.
	 * @return BundleAccess|\WP_Error
	 */
	public function enroll_free( int $bundle_id, int $user_id ) {
		$bundle = $this->bundles->find( $bundle_id );

		if ( ! $bundle instanceof Bundle ) {
			return new \WP_Error( 'tcb_bundle_not_found', __( 'Bundle not found.', 'tutor-course-bundles' ) );
		}

		if ( ! $bundle->is_purchasable() ) {
			return new \WP_Error( 'tcb_bundle_unavailable', __( 'This bundle is not available.', 'tutor-course-bundles' ) );
		}

		if ( ! $bundle->is_free() ) {
			return new \WP_Error( 'tcb_bundle_not_free', __( 'This bundle is not free.', 'tutor-course-bundles' ) );
		}

		if ( $this->access->user_has_access( $bundle_id, $user_id ) ) {
			return new \WP_Error( 'tcb_already_enrolled', __( 'You are already enrolled in this bundle.', 'tutor-course-bundles' ) );
		}

		/**
		 * Filter whether a learner may enroll in a bundle.
		 *
		 * @param bool $allowed   Whether enrollment is allowed.
		 * @param int  $bundle_id Bundle post ID.
		 * @param int  $user_id   User ID.
		 */
		$allowed = (bool) apply_filters( 'tcb/bundle/can_enroll', true, $bundle_id, $user_id );

		if ( ! $allowed ) {
			return new \WP_Error( 'tcb_enroll_blocked', __( 'Enrollment is not available for you right now.', 'tutor-course-bundles' ) );
		}

		return $this->enrollments->grant_access( $bundle_id, $user_id, BundleAccess::SOURCE_FREE, 0 );
	}
}
