<?php
/**
 * REST controller for enrollment and entitlements.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Rest;

use SpaceWork\TutorCourseBundles\Commerce\CommerceManager;
use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Frontend\ProgressController;
use SpaceWork\TutorCourseBundles\Infrastructure\AccessRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\BundleRepository;
use SpaceWork\TutorCourseBundles\Support\Capabilities;
use SpaceWork\TutorCourseBundles\Tutor\EnrollmentService;

defined( 'ABSPATH' ) || exit;

/**
 * Enrollment endpoints.
 *
 * The free-enrollment route is the only public write in the plugin, so it gets
 * the same treatment as the form path: logged-in check, nonce (via the REST
 * cookie nonce), and a rate limit.
 */
final class EnrollmentController {

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
	 * Register REST hooks.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			BundleController::NAMESPACE,
			'/bundles/(?P<id>\d+)/enroll',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'enroll' ),
				'permission_callback' => array( $this, 'can_enroll' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			BundleController::NAMESPACE,
			'/bundles/(?P<id>\d+)/access',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_access' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'id'     => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'status' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'grant_access' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'id'         => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'user_id'    => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'expires_at' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'note'       => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'revoke_access' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'id'        => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'access_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'reason'    => array(
							'type'              => 'string',
							'default'           => 'api',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			BundleController::NAMESPACE,
			'/users/(?P<user_id>\d+)/bundles',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_user_bundles' ),
				'permission_callback' => array( $this, 'can_read_user' ),
				'args'                => array(
					'user_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Permission callbacks
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Any logged-in user may attempt a free enrollment.
	 */
	public function can_enroll(): bool {
		return is_user_logged_in();
	}

	/**
	 * Staff-only routes.
	 */
	public function can_manage(): bool {
		return current_user_can( Capabilities::ENROLL_STUDENTS ) || current_user_can( 'manage_options' );
	}

	/**
	 * Learners may read their own bundles; staff may read anyone's.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function can_read_user( \WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( get_current_user_id() === (int) $request['user_id'] ) {
			return true;
		}

		return $this->can_manage();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Handlers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Enroll the current user in a free bundle.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function enroll( \WP_REST_Request $request ) {
		$bundle_id = (int) $request['id'];
		$user_id   = get_current_user_id();

		$rate_key = 'tcb_rate_enroll_' . $user_id;
		$attempts = (int) get_transient( $rate_key );

		if ( $attempts >= 5 ) {
			return new \WP_Error(
				'tcb_rate_limited',
				__( 'Too many attempts. Please wait a moment.', 'tutor-course-bundles' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $rate_key, $attempts + 1, MINUTE_IN_SECONDS );

		$result = tcb()->get( CommerceManager::class )->enroll_free( $bundle_id, $user_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );

			return $result;
		}

		return new \WP_REST_Response(
			array(
				'enrolled' => true,
				'access'   => $result->to_array(),
			),
			201
		);
	}

	/**
	 * List entitlements for a bundle.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function list_access( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->access->query(
			array(
				'bundle_id' => (int) $request['id'],
				'status'    => (string) $request['status'],
				'limit'     => 100,
			)
		);

		return new \WP_REST_Response(
			array(
				'total' => $result['total'],
				'items' => array_map(
					static fn( BundleAccess $access ): array => $access->to_array(),
					$result['items']
				),
			),
			200
		);
	}

	/**
	 * Grant a bundle to a learner.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function grant_access( \WP_REST_Request $request ) {
		$expires = (string) $request['expires_at'];

		$result = $this->enrollments->grant_access(
			(int) $request['id'],
			(int) $request['user_id'],
			BundleAccess::SOURCE_MANUAL,
			0,
			array(
				'expires_at' => '' === $expires ? null : gmdate( 'Y-m-d H:i:s', strtotime( $expires ) ? strtotime( $expires ) : time() ),
				'note'       => (string) $request['note'],
			)
		);

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );

			return $result;
		}

		return new \WP_REST_Response( $result->to_array(), 201 );
	}

	/**
	 * Revoke an entitlement.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revoke_access( \WP_REST_Request $request ) {
		$access_id = (int) $request['access_id'];
		$access    = $this->access->find( $access_id );

		if ( ! $access instanceof BundleAccess || $access->bundle_id !== (int) $request['id'] ) {
			return new \WP_Error( 'tcb_access_not_found', __( 'Entitlement not found.', 'tutor-course-bundles' ), array( 'status' => 404 ) );
		}

		$this->enrollments->revoke_access( $access_id, BundleAccess::STATUS_REVOKED, (string) $request['reason'] );

		return new \WP_REST_Response( array( 'revoked' => true ), 200 );
	}

	/**
	 * Bundles a learner holds, with progress.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_user_bundles( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id  = (int) $request['user_id'];
		$progress = tcb()->get( ProgressController::class );
		$items    = array();

		foreach ( $this->access->find_preferred_for_user( $user_id ) as $access ) {
			$bundle = $this->bundles->find( $access->bundle_id );

			if ( ! $bundle instanceof Bundle ) {
				continue;
			}

			$items[] = array(
				'bundle'   => $bundle->to_array(),
				'access'   => $access->to_array(),
				'progress' => $progress->get_progress( $access->bundle_id, $user_id )->to_array(),
			);
		}

		return new \WP_REST_Response( $items, 200 );
	}
}
