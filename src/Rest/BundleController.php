<?php
/**
 * REST controller for bundles and their courses.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Rest;

use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Domain\BundleCourse;
use SpaceWork\TutorCourseBundles\Frontend\ProgressController;
use SpaceWork\TutorCourseBundles\Infrastructure\BundleRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\PostTypes;
use SpaceWork\TutorCourseBundles\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Namespace `tcb/v1`.
 *
 * Reads are public for published bundles; every write is capability-checked
 * against the specific bundle, not just "can this user edit posts".
 */
final class BundleController {

	public const NAMESPACE = 'tcb/v1';

	/**
	 * Constructor.
	 *
	 * @param BundleRepository   $bundles  Bundle repository.
	 * @param ProgressController $progress Progress calculator.
	 */
	public function __construct(
		private readonly BundleRepository $bundles,
		private readonly ProgressController $progress
	) {}

	/**
	 * Register REST hooks.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register every route in this controller.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/bundles',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_bundles' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'page'     => array(
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'type'              => 'integer',
							'default'           => 12,
							'sanitize_callback' => 'absint',
						),
						'search'   => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'category' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_title',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_bundle' ),
					'permission_callback' => array( $this, 'can_create' ),
					'args'                => $this->get_create_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bundles/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_bundle' ),
					'permission_callback' => array( $this, 'can_read_bundle' ),
					'args'                => $this->get_id_arg(),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_bundle' ),
					'permission_callback' => array( $this, 'can_edit_bundle' ),
					'args'                => array_merge( $this->get_id_arg(), $this->get_update_args() ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_bundle' ),
					'permission_callback' => array( $this, 'can_delete_bundle' ),
					'args'                => $this->get_id_arg(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bundles/(?P<id>\d+)/courses',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_courses' ),
					'permission_callback' => array( $this, 'can_read_bundle' ),
					'args'                => $this->get_id_arg(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_course' ),
					'permission_callback' => array( $this, 'can_edit_bundle' ),
					'args'                => array_merge(
						$this->get_id_arg(),
						array(
							'course_id'   => array(
								'type'              => 'integer',
								'required'          => true,
								'sanitize_callback' => 'absint',
							),
							'is_required' => array(
								'type'    => 'boolean',
								'default' => true,
							),
						)
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bundles/(?P<id>\d+)/courses/order',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'reorder_courses' ),
				'permission_callback' => array( $this, 'can_edit_bundle' ),
				'args'                => array_merge(
					$this->get_id_arg(),
					array(
						'course_ids' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array( 'type' => 'integer' ),
						),
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bundles/(?P<id>\d+)/courses/(?P<course_id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'remove_course' ),
				'permission_callback' => array( $this, 'can_edit_bundle' ),
				'args'                => array_merge(
					$this->get_id_arg(),
					array(
						'course_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bundles/(?P<id>\d+)/progress',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_progress' ),
				'permission_callback' => array( $this, 'can_read_own_progress' ),
				'args'                => array_merge(
					$this->get_id_arg(),
					array(
						'user_id' => array(
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					)
				),
			)
		);
	}

	/**
	 * Shared `id` route argument.
	 *
	 * @return array<string, mixed>
	 */
	private function get_id_arg(): array {
		return array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => static fn( $value ): bool => absint( $value ) > 0,
			),
		);
	}

	/**
	 * Arguments accepted when creating or updating a bundle.
	 *
	 * @return array<string, mixed>
	 */
	private function get_update_args(): array {
		return array(
			'title'       => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'content'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_post',
			),
			'status'      => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => static fn( $value ): bool => in_array( $value, array( 'draft', 'publish', 'private', 'pending' ), true ),
			),
			'price'       => array(
				'type'              => 'number',
				'sanitize_callback' => static fn( $value ): float => max( 0.0, (float) $value ),
			),
			'sale_price'  => array(
				'type'              => 'number',
				'sanitize_callback' => static fn( $value ): float => max( 0.0, (float) $value ),
			),
			'access_type' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => static fn( $value ): bool => in_array( $value, array( Bundle::ACCESS_TYPE_FREE, Bundle::ACCESS_TYPE_PAID ), true ),
			),
		);
	}

	/**
	 * Arguments accepted when creating a bundle.
	 *
	 * @return array<string, mixed>
	 */
	private function get_create_args(): array {
		$args = $this->get_update_args();

		$args['status']['default'] = 'draft';

		return $args;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Permission callbacks
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Whether the caller may create bundles.
	 */
	public function can_create(): bool {
		return current_user_can( Capabilities::EDIT_BUNDLES );
	}

	/**
	 * Whether the caller may read a bundle.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function can_read_bundle( \WP_REST_Request $request ): bool {
		$bundle = $this->bundles->find( (int) $request['id'] );

		if ( ! $bundle instanceof Bundle ) {
			// Let the endpoint return its canonical 404 response. Returning
			// false here would turn a missing public resource into a 403.
			return true;
		}

		if ( 'publish' === $bundle->post->post_status ) {
			return true;
		}

		return Capabilities::can_edit_bundle( $bundle->get_id() );
	}

	/**
	 * Whether the caller may edit a bundle.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function can_edit_bundle( \WP_REST_Request $request ): bool {
		return Capabilities::can_edit_bundle( (int) $request['id'] );
	}

	/**
	 * Whether the caller may delete a bundle.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function can_delete_bundle( \WP_REST_Request $request ): bool {
		return current_user_can( Capabilities::DELETE_BUNDLE, (int) $request['id'] );
	}

	/**
	 * Learners may read their own progress; staff may read anyone's.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function can_read_own_progress( \WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$requested = (int) $request['user_id'];

		if ( 0 === $requested || get_current_user_id() === $requested ) {
			return true;
		}

		return Capabilities::current_user_can_manage() || current_user_can( Capabilities::VIEW_REPORTS );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Handlers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * List bundles.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_bundles( \WP_REST_Request $request ): \WP_REST_Response {
		$args = array(
			'posts_per_page' => min( 50, max( 1, (int) $request['per_page'] ) ),
			'paged'          => max( 1, (int) $request['page'] ),
			's'              => (string) $request['search'],
			'no_found_rows'  => false,
		);

		if ( '' !== (string) $request['category'] ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => PostTypes::TAXONOMY_CAT,
					'field'    => 'slug',
					'terms'    => (string) $request['category'],
				),
			);
		}

		$bundles = $this->bundles->query( $args );

		$data = array_map(
			fn( Bundle $bundle ): array => $this->prepare_bundle( $bundle ),
			$bundles
		);

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Fetch one bundle.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_bundle( \WP_REST_Request $request ) {
		$bundle = $this->bundles->find( (int) $request['id'] );

		if ( ! $bundle instanceof Bundle ) {
			return new \WP_Error( 'tcb_not_found', __( 'Bundle not found.', 'tutor-course-bundles' ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response( $this->prepare_bundle( $bundle, true ), 200 );
	}

	/**
	 * Create a bundle.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_bundle( \WP_REST_Request $request ) {
		$title = trim( (string) $request['title'] );

		if ( '' === $title ) {
			return new \WP_Error( 'tcb_missing_title', __( 'A title is required.', 'tutor-course-bundles' ), array( 'status' => 400 ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => PostTypes::POST_TYPE,
				'post_title'   => $title,
				'post_content' => (string) $request['content'],
				'post_status'  => (string) $request['status'],
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->apply_meta( (int) $post_id, $request );

		$bundle = $this->bundles->find( (int) $post_id );

		return new \WP_REST_Response( $bundle ? $this->prepare_bundle( $bundle, true ) : array(), 201 );
	}

	/**
	 * Update a bundle.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_bundle( \WP_REST_Request $request ) {
		$bundle_id = (int) $request['id'];
		$bundle    = $this->bundles->find( $bundle_id );

		if ( ! $bundle instanceof Bundle ) {
			return new \WP_Error( 'tcb_not_found', __( 'Bundle not found.', 'tutor-course-bundles' ), array( 'status' => 404 ) );
		}

		$update = array( 'ID' => $bundle_id );

		if ( $request->has_param( 'title' ) ) {
			$update['post_title'] = (string) $request['title'];
		}

		if ( $request->has_param( 'content' ) ) {
			$update['post_content'] = (string) $request['content'];
		}

		if ( $request->has_param( 'status' ) ) {
			$update['post_status'] = (string) $request['status'];
		}

		if ( count( $update ) > 1 ) {
			$result = wp_update_post( $update, true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$this->apply_meta( $bundle_id, $request );

		$bundle = $this->bundles->find( $bundle_id );

		return new \WP_REST_Response( $bundle ? $this->prepare_bundle( $bundle, true ) : array(), 200 );
	}

	/**
	 * Write meta fields from a request.
	 *
	 * @param int              $bundle_id Bundle post ID.
	 * @param \WP_REST_Request $request   Request.
	 */
	private function apply_meta( int $bundle_id, \WP_REST_Request $request ): void {
		if ( $request->has_param( 'price' ) ) {
			update_post_meta( $bundle_id, Bundle::META_PRICE, (float) $request['price'] );
		}

		if ( $request->has_param( 'sale_price' ) ) {
			update_post_meta( $bundle_id, Bundle::META_SALE_PRICE, (float) $request['sale_price'] );
		}

		if ( $request->has_param( 'access_type' ) ) {
			update_post_meta( $bundle_id, Bundle::META_ACCESS_TYPE, (string) $request['access_type'] );
		}
	}

	/**
	 * Move a bundle to the trash.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_bundle( \WP_REST_Request $request ) {
		$bundle_id = (int) $request['id'];

		if ( ! $this->bundles->find( $bundle_id ) instanceof Bundle ) {
			return new \WP_Error( 'tcb_not_found', __( 'Bundle not found.', 'tutor-course-bundles' ), array( 'status' => 404 ) );
		}

		$result = wp_trash_post( $bundle_id );

		if ( ! $result ) {
			return new \WP_Error( 'tcb_delete_failed', __( 'Could not delete the bundle.', 'tutor-course-bundles' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * List a bundle's courses.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_courses( \WP_REST_Request $request ): \WP_REST_Response {
		$bundle_id           = (int) $request['id'];
		$include_unpublished = Capabilities::can_edit_bundle( $bundle_id );
		$courses             = $this->bundles->get_courses( $bundle_id, ! $include_unpublished );

		return new \WP_REST_Response(
			array_map( static fn( BundleCourse $course ): array => $course->to_array(), $courses ),
			200
		);
	}

	/**
	 * Attach a course.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function add_course( \WP_REST_Request $request ) {
		$result = $this->bundles->add_course(
			(int) $request['id'],
			(int) $request['course_id'],
			array( 'is_required' => (bool) $request['is_required'] )
		);

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );

			return $result;
		}

		return new \WP_REST_Response( array( 'id' => $result ), 201 );
	}

	/**
	 * Detach a course.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function remove_course( \WP_REST_Request $request ) {
		$removed = $this->bundles->remove_course( (int) $request['id'], (int) $request['course_id'] );

		if ( ! $removed ) {
			return new \WP_Error( 'tcb_not_in_bundle', __( 'That course is not in this bundle.', 'tutor-course-bundles' ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response( array( 'removed' => true ), 200 );
	}

	/**
	 * Persist a new course order.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function reorder_courses( \WP_REST_Request $request ) {
		$ids = array_map( 'absint', (array) $request['course_ids'] );

		if ( ! $this->bundles->reorder( (int) $request['id'], $ids ) ) {
			return new \WP_Error( 'tcb_invalid_course_order', __( 'Course order must contain every bundle course exactly once.', 'tutor-course-bundles' ), array( 'status' => 400 ) );
		}

		return new \WP_REST_Response( array( 'reordered' => true ), 200 );
	}

	/**
	 * Progress for a learner.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_progress( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = (int) $request['user_id'];
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();

		return new \WP_REST_Response(
			$this->progress->get_progress( (int) $request['id'], $user_id )->to_array(),
			200
		);
	}

	/**
	 * Shape a bundle for the API.
	 *
	 * @param Bundle $bundle  Bundle.
	 * @param bool   $full    Whether to include the course list.
	 * @return array<string, mixed>
	 */
	private function prepare_bundle( Bundle $bundle, bool $full = false ): array {
		$data          = $bundle->to_array();
		$data['stats'] = $this->bundles->get_stats( $bundle->get_id() );

		if ( $full ) {
			$include_unpublished = Capabilities::can_edit_bundle( $bundle->get_id() );
			$data['courses']      = array_map(
				static fn( BundleCourse $course ): array => $course->to_array(),
				$this->bundles->get_courses( $bundle->get_id(), ! $include_unpublished )
			);
		}

		/**
		 * Filter a bundle REST payload.
		 *
		 * @param array<string, mixed> $data   Response data.
		 * @param Bundle               $bundle Bundle.
		 */
		return apply_filters( 'tcb/rest/bundle', $data, $bundle );
	}
}
