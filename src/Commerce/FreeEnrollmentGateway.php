<?php
/**
 * Gateway for bundles that cost nothing.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Commerce;

use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Infrastructure\BundleRepository;

defined( 'ABSPATH' ) || exit;

/**
 * No payment, no product — just a nonce-protected POST that grants access.
 */
final class FreeEnrollmentGateway implements CommerceGateway {

	/**
	 * Constructor.
	 *
	 * @param BundleRepository $bundles Bundle repository.
	 */
	public function __construct( private readonly BundleRepository $bundles ) {}

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return BundleAccess::SOURCE_FREE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $bundle_id Bundle post ID.
	 */
	public function supports( int $bundle_id ): bool {
		$bundle = $this->bundles->find( $bundle_id );

		return $bundle instanceof Bundle && $bundle->is_free();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $bundle_id Bundle post ID.
	 */
	public function get_purchase_url( int $bundle_id ): string {
		$bundle = $this->bundles->find( $bundle_id );

		if ( ! $bundle instanceof Bundle ) {
			return '';
		}

		if ( ! is_user_logged_in() ) {
			return wp_login_url( $bundle->get_permalink() );
		}

		return wp_nonce_url(
			add_query_arg(
				array(
					'tcb_action' => 'enroll_free',
					'bundle_id'  => $bundle_id,
				),
				$bundle->get_permalink()
			),
			'tcb_enroll_free_' . $bundle_id,
			'tcb_nonce'
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $bundle_id Bundle post ID.
	 */
	public function get_button_label( int $bundle_id ): string {
		return __( 'Enroll Now', 'tutor-course-bundles' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $user_id   User ID.
	 * @param int $bundle_id Bundle post ID.
	 */
	public function user_has_purchased( int $user_id, int $bundle_id ): bool {
		return false; // Free bundles are never "purchased"; access is checked in the ledger.
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $user_id   User ID.
	 * @param int    $bundle_id Bundle post ID.
	 * @param string $reason    Audit reason.
	 */
	public function revoke_access( int $user_id, int $bundle_id, string $reason ): void {
		// Handled centrally by EnrollmentService::revoke_access().
	}
}
