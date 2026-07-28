<?php
/**
 * Refund and cancellation handling.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Commerce;

use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Infrastructure\AccessRepository;
use SpaceWork\TutorCourseBundles\Support\Logger;
use SpaceWork\TutorCourseBundles\Tutor\EnrollmentService;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a refunded or cancelled order into the right ledger changes.
 *
 * The important behaviour lives one layer down in EnrollmentService, which only
 * cancels a Tutor enrollment when no other source still grants that course.
 */
final class RefundHandler {

	/**
	 * Constructor.
	 *
	 * @param AccessRepository  $access      Entitlement repository.
	 * @param EnrollmentService $enrollments Enrollment engine.
	 */
	public function __construct(
		private readonly AccessRepository $access,
		private readonly EnrollmentService $enrollments
	) {}

	/**
	 * Revoke everything an order granted.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $status   Terminal status to apply.
	 * @param string $reason   Audit reason.
	 * @return int Number of entitlements terminated.
	 */
	public function revoke_order( int $order_id, string $status = BundleAccess::STATUS_REFUNDED, string $reason = 'order_refunded' ): int {
		$revoked = 0;

		foreach ( $this->find_order_access( $order_id ) as $access ) {
			if ( $access->is_terminated() ) {
				continue;
			}

			$this->enrollments->revoke_access( $access->id, $status, $reason );
			++$revoked;
		}

		Logger::debug(
			'Processed order revocation.',
			array(
				'order_id' => $order_id,
				'status'   => $status,
				'revoked'  => $revoked,
			)
		);

		return $revoked;
	}

	/**
	 * Handle a partial refund.
	 *
	 * A partial refund is ambiguous: it might be a goodwill gesture on a bundle
	 * the learner keeps, or it might zero out one line. We only revoke when the
	 * refunded amount covers the whole order, and otherwise leave a note for a
	 * human to act on.
	 *
	 * @param int   $order_id       Order ID.
	 * @param float $refunded_total Total refunded so far.
	 * @param float $order_total    Original order total.
	 */
	public function handle_partial_refund( int $order_id, float $refunded_total, float $order_total ): void {
		if ( $order_total > 0 && $refunded_total >= $order_total ) {
			$this->revoke_order( $order_id, BundleAccess::STATUS_REFUNDED, 'order_fully_refunded' );

			return;
		}

		foreach ( $this->find_order_access( $order_id ) as $access ) {
			Logger::audit(
				'access.partial_refund_review',
				array(
					'access_id'      => $access->id,
					'order_id'       => $order_id,
					'refunded_total' => $refunded_total,
					'order_total'    => $order_total,
				),
				$access->bundle_id,
				$access->user_id
			);
		}

		/**
		 * Fires when a partial refund needs a manual decision.
		 *
		 * @param int   $order_id       Order ID.
		 * @param float $refunded_total Amount refunded.
		 * @param float $order_total    Order total.
		 */
		do_action( 'tcb/commerce/partial_refund', $order_id, $refunded_total, $order_total );
	}

	/**
	 * All entitlements created by a given order.
	 *
	 * @param int $order_id Order ID.
	 * @return BundleAccess[]
	 */
	public function find_order_access( int $order_id ): array {
		return $this->access->find_by_source_id( BundleAccess::SOURCE_WOOCOMMERCE, $order_id );
	}
}
