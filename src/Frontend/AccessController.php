<?php
/**
 * Frontend access checks and the free-enrollment endpoint.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Frontend;

use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Infrastructure\AccessRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\BundleRepository;
use SpaceWork\TutorCourseBundles\Tutor\EnrollmentService;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the `?tcb_action=enroll_free` round trip and exposes the access
 * helpers templates need.
 */
final class AccessController {

	private const RATE_LIMIT_WINDOW = 60;
	private const RATE_LIMIT_MAX    = 5;

	/**
	 * Constructor.
	 *
	 * @param BundleRepository  $bundles     Bundle repository.
	 * @param AccessRepository  $access      Entitlement repository.
	 * @param EnrollmentService $enrollments Enrollment engine.
	 */
	public function __construct(
		private readonly BundleRepository $bundles,
		private readonly AccessRepository $access,
		private readonly EnrollmentService $enrollments
	) {}

	/**
	 * Register frontend hooks.
	 */
	public function register_hooks(): void {
		add_action( 'template_redirect', array( $this, 'maybe_handle_action' ) );
		add_action( 'wp_ajax_tcb_enroll_free', array( $this, 'ajax_enroll_free' ) );
	}

	/**
	 * Intercept the free-enrollment link.
	 */
	public function maybe_handle_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['tcb_action'] ) ? sanitize_key( wp_unslash( $_GET['tcb_action'] ) ) : '';

		if ( 'enroll_free' !== $action ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bundle_id = isset( $_GET['bundle_id'] ) ? absint( wp_unslash( $_GET['bundle_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce = isset( $_GET['tcb_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['tcb_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'tcb_enroll_free_' . $bundle_id ) ) {
			$this->redirect_with_message( $bundle_id, 'invalid_nonce' );
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( (string) get_permalink( $bundle_id ) ) );
			exit;
		}

		$user_id = get_current_user_id();

		if ( ! $this->check_rate_limit( $user_id ) ) {
			$this->redirect_with_message( $bundle_id, 'rate_limited' );
		}

		$result = tcb()->get( \SpaceWork\TutorCourseBundles\Commerce\CommerceManager::class )
			->enroll_free( $bundle_id, $user_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_message( $bundle_id, $result->get_error_code() );
		}

		$this->redirect_with_message( $bundle_id, 'enrolled' );
	}

	/**
	 * AJAX variant used by the frontend script.
	 */
	public function ajax_enroll_free(): void {
		check_ajax_referer( 'wp_rest', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Please log in first.', 'tutor-course-bundles' ) ), 401 );
		}

		$bundle_id = isset( $_POST['bundle_id'] ) ? absint( wp_unslash( $_POST['bundle_id'] ) ) : 0;
		$user_id   = get_current_user_id();

		if ( ! $this->check_rate_limit( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many attempts. Please wait a moment.', 'tutor-course-bundles' ) ), 429 );
		}

		$result = tcb()->get( \SpaceWork\TutorCourseBundles\Commerce\CommerceManager::class )
			->enroll_free( $bundle_id, $user_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message'  => __( 'You are enrolled.', 'tutor-course-bundles' ),
				'redirect' => (string) get_permalink( $bundle_id ),
			)
		);
	}

	/**
	 * Whether a learner currently holds a bundle.
	 *
	 * @param int      $bundle_id Bundle post ID.
	 * @param int|null $user_id   User ID, defaults to current user.
	 */
	public function user_has_access( int $bundle_id, ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();

		return $this->access->user_has_access( $bundle_id, $user_id );
	}

	/**
	 * The entitlement backing a learner's access, if any.
	 *
	 * @param int      $bundle_id Bundle post ID.
	 * @param int|null $user_id   User ID.
	 */
	public function get_active_access( int $bundle_id, ?int $user_id = null ): ?BundleAccess {
		$user_id = $user_id ?? get_current_user_id();

		if ( $user_id <= 0 ) {
			return null;
		}

		return $this->access->find_active( $bundle_id, $user_id );
	}

	/**
	 * Everything a template needs to render the purchase area.
	 *
	 * @param int      $bundle_id Bundle post ID.
	 * @param int|null $user_id   User ID.
	 * @return array<string, mixed>
	 */
	public function get_purchase_state( int $bundle_id, ?int $user_id = null ): array {
		$user_id = $user_id ?? get_current_user_id();
		$bundle  = $this->bundles->find( $bundle_id );

		if ( ! $bundle instanceof Bundle ) {
			return array(
				'available'  => false,
				'has_access' => false,
			);
		}

		$manager = tcb()->get( \SpaceWork\TutorCourseBundles\Commerce\CommerceManager::class );
		$gateway = $manager->get_gateway( $bundle_id );
		$access  = $this->get_active_access( $bundle_id, $user_id );
		$url     = $gateway ? $gateway->get_purchase_url( $bundle_id ) : '';
		$label   = $gateway ? $gateway->get_button_label( $bundle_id ) : __( 'Unavailable', 'tutor-course-bundles' );

		if ( $user_id <= 0 && ! $bundle->is_free() && '' !== $url ) {
			$url   = wp_login_url( $bundle->get_permalink() );
			$label = __( 'Log in to buy', 'tutor-course-bundles' );
		}

		return array(
			'available'    => $bundle->is_purchasable() && array() !== $this->bundles->get_course_ids( $bundle_id, true ),
			'has_access'   => null !== $access,
			'access'       => $access,
			'expires_at'   => $access?->expires_at,
			'is_free'      => $bundle->is_free(),
			'price'        => $bundle->get_price(),
			'sale_price'   => $bundle->get_sale_price(),
			'gateway'      => $gateway,
			'purchase_url' => $url,
			'button_label' => $label,
			'logged_in'    => $user_id > 0,
		);
	}

	/**
	 * Simple per-user rate limit on enrollment attempts.
	 *
	 * @param int $user_id User ID.
	 */
	private function check_rate_limit( int $user_id ): bool {
		$key   = 'tcb_rate_enroll_' . $user_id;
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_MAX ) {
			return false;
		}

		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );

		return true;
	}

	/**
	 * Redirect back to the bundle with a status code in the query string.
	 *
	 * @param int    $bundle_id Bundle post ID.
	 * @param string $message   Message key.
	 */
	private function redirect_with_message( int $bundle_id, string $message ): void {
		$url = add_query_arg( 'tcb_message', rawurlencode( $message ), (string) get_permalink( $bundle_id ) );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Human-readable text for a message key.
	 *
	 * @param string $key Message key.
	 */
	public static function get_message_text( string $key ): string {
		$messages = array(
			'enrolled'               => __( 'You are now enrolled in this bundle.', 'tutor-course-bundles' ),
			'already_enrolled'       => __( 'You are already enrolled in this bundle.', 'tutor-course-bundles' ),
			'tcb_already_enrolled'   => __( 'You are already enrolled in this bundle.', 'tutor-course-bundles' ),
			'invalid_nonce'          => __( 'That link has expired. Please try again.', 'tutor-course-bundles' ),
			'rate_limited'           => __( 'Too many attempts. Please wait a moment and try again.', 'tutor-course-bundles' ),
			'tcb_bundle_not_free'    => __( 'This bundle requires payment.', 'tutor-course-bundles' ),
			'tcb_bundle_unavailable' => __( 'This bundle is not available right now.', 'tutor-course-bundles' ),
			'tcb_enroll_blocked'     => __( 'Enrollment is not available for you right now.', 'tutor-course-bundles' ),
		);

		return $messages[ $key ] ?? '';
	}
}
