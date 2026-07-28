<?php
/**
 * Normalised view of a commerce order line that grants a bundle.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Gateway-agnostic order descriptor.
 *
 * WooCommerce is the only implementation shipped in 1.0.0, but the commerce
 * layer talks in terms of this object so a future gateway (Tutor's native
 * monetisation, subscriptions, a marketplace) can be added without touching
 * the enrollment engine.
 */
final class BundleOrder {

	/**
	 * Constructor.
	 *
	 * @param int    $order_id    Gateway order ID.
	 * @param int    $bundle_id   Bundle post ID.
	 * @param int    $user_id     Purchasing user ID.
	 * @param string $gateway     Gateway identifier, e.g. "woocommerce".
	 * @param string $status      Gateway order status.
	 * @param float  $total       Amount paid for this line.
	 * @param string $currency    Currency code.
	 * @param int    $product_id  Gateway product ID.
	 */
	public function __construct(
		public readonly int $order_id,
		public readonly int $bundle_id,
		public readonly int $user_id,
		public readonly string $gateway = 'woocommerce',
		public readonly string $status = '',
		public readonly float $total = 0.0,
		public readonly string $currency = '',
		public readonly int $product_id = 0
	) {}

	/**
	 * Source type string used in the access ledger.
	 */
	public function get_source_type(): string {
		return 'woocommerce' === $this->gateway
			? BundleAccess::SOURCE_WOOCOMMERCE
			: $this->gateway;
	}

	/**
	 * Array form.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'order_id'   => $this->order_id,
			'bundle_id'  => $this->bundle_id,
			'user_id'    => $this->user_id,
			'gateway'    => $this->gateway,
			'status'     => $this->status,
			'total'      => $this->total,
			'currency'   => $this->currency,
			'product_id' => $this->product_id,
		);
	}
}
