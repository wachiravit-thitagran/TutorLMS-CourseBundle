<?php
/**
 * Contract every payment integration implements.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Commerce;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the enrollment engine free of any payment-provider knowledge.
 *
 * WooCommerce is the only paid implementation in 1.0.0, but subscriptions,
 * Tutor's native monetisation, or a marketplace can be dropped in later without
 * the access ledger noticing.
 */
interface CommerceGateway {

	/**
	 * Gateway identifier, e.g. "woocommerce".
	 */
	public function get_id(): string;

	/**
	 * Whether this gateway handles a given bundle.
	 *
	 * @param int $bundle_id Bundle post ID.
	 */
	public function supports( int $bundle_id ): bool;

	/**
	 * URL that starts the purchase or enrollment flow.
	 *
	 * @param int $bundle_id Bundle post ID.
	 */
	public function get_purchase_url( int $bundle_id ): string;

	/**
	 * Label for the call-to-action button.
	 *
	 * @param int $bundle_id Bundle post ID.
	 */
	public function get_button_label( int $bundle_id ): string;

	/**
	 * Whether the user already paid for this bundle through this gateway.
	 *
	 * @param int $user_id   User ID.
	 * @param int $bundle_id Bundle post ID.
	 */
	public function user_has_purchased( int $user_id, int $bundle_id ): bool;

	/**
	 * Wind down whatever this gateway granted.
	 *
	 * @param int    $user_id   User ID.
	 * @param int    $bundle_id Bundle post ID.
	 * @param string $reason    Audit reason.
	 */
	public function revoke_access( int $user_id, int $bundle_id, string $reason ): void;
}
