<?php
/**
 * REST API surface and permissions.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Integration;

use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Rest\BundleController;
use SpaceWork\TutorCourseBundles\Tests\Support\TestCase;
use WP_REST_Request;

/**
 * Reads are open for published bundles; every write is checked against the
 * specific bundle. The permission tests here are the ones that matter — a
 * missing capability check is how a subscriber edits someone else's product.
 *
 * @covers \SpaceWork\TutorCourseBundles\Rest\BundleController
 * @covers \SpaceWork\TutorCourseBundles\Rest\EnrollmentController
 */
final class RestApiTest extends TestCase {

	private \WP_REST_Server $server;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;

		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;

		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Dispatch a request.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route below the namespace.
	 * @param array<string, mixed> $params Body/query params.
	 */
	private function request( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, '/' . BundleController::NAMESPACE . $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $this->server->dispatch( $request );
	}

	/**
	 * Every documented route is actually registered. A typo in a route string
	 * is invisible until an integration calls it.
	 */
	public function test_routes_are_registered(): void {
		$routes = $this->server->get_routes();

		foreach (
			array(
				'/tcb/v1/bundles',
				'/tcb/v1/bundles/(?P<id>\d+)',
				'/tcb/v1/bundles/(?P<id>\d+)/courses',
				'/tcb/v1/bundles/(?P<id>\d+)/courses/order',
				'/tcb/v1/bundles/(?P<id>\d+)/progress',
				'/tcb/v1/bundles/(?P<id>\d+)/enroll',
				'/tcb/v1/bundles/(?P<id>\d+)/access',
				'/tcb/v1/users/(?P<user_id>\d+)/bundles',
			) as $route
		) {
			$this->assertArrayHasKey( $route, $routes, "Route {$route} is not registered." );
		}
	}

	/**
	 * Anyone can browse published bundles.
	 */
	public function test_published_bundles_are_publicly_readable(): void {
		$built = $this->seeder->bundle_with_courses( 2, array( 'title' => 'Public bundle' ) );

		$response = $this->request( 'GET', '/bundles/' . $built['bundle'] );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame( 'Public bundle', $data['title'] );
		$this->assertCount( 2, $data['courses'] );
		$this->assertArrayHasKey( 'stats', $data );
	}

	/**
	 * A draft bundle is invisible to the public but readable by its author.
	 */
	public function test_draft_bundles_are_hidden_from_the_public(): void {
		$author = $this->seeder->instructor();
		$bundle = $this->seeder->bundle(
			array(
				'status' => 'draft',
				'author' => $author,
			)
		);

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'GET', '/bundles/' . $bundle )->get_status() );

		wp_set_current_user( $author );
		$this->assertSame( 200, $this->request( 'GET', '/bundles/' . $bundle )->get_status() );
	}

	/**
	 * Creating a bundle needs the capability; a subscriber must be turned away.
	 */
	public function test_creating_a_bundle_requires_capability(): void {
		wp_set_current_user( $this->seeder->student() );

		$response = $this->request( 'POST', '/bundles', array( 'title' => 'Sneaky bundle' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * An administrator can create one, and the meta comes back as written.
	 */
	public function test_an_admin_can_create_a_bundle(): void {
		wp_set_current_user( $this->seeder->admin() );

		$response = $this->request(
			'POST',
			'/bundles',
			array(
				'title'       => 'API bundle',
				'status'      => 'publish',
				'access_type' => Bundle::ACCESS_TYPE_PAID,
				'price'       => 75.5,
			)
		);

		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame( 'API bundle', $data['title'] );
		$this->assertSame( 75.5, $data['price'] );
		$this->assertFalse( $data['is_free'] );
	}

	/**
	 * Editing one field must not apply create-only defaults to other fields.
	 */
	public function test_a_partial_update_does_not_unpublish_the_bundle(): void {
		$admin  = $this->seeder->admin();
		$bundle = $this->seeder->bundle( array( 'status' => 'publish' ) );

		wp_set_current_user( $admin );

		$response = $this->request( 'PUT', '/bundles/' . $bundle, array( 'title' => 'Only the title changed' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'publish', get_post_status( $bundle ) );
	}

	/**
	 * An instructor may not edit a bundle belonging to someone else.
	 */
	public function test_instructors_cannot_edit_other_peoples_bundles(): void {
		$owner    = $this->seeder->instructor();
		$intruder = $this->seeder->instructor();

		$bundle = $this->seeder->bundle( array( 'author' => $owner ) );

		wp_set_current_user( $intruder );

		$response = $this->request( 'PUT', '/bundles/' . $bundle, array( 'title' => 'Hijacked' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertNotSame( 'Hijacked', get_the_title( $bundle ), 'The title must be untouched.' );

		wp_set_current_user( $owner );

		$this->assertSame(
			200,
			$this->request( 'PUT', '/bundles/' . $bundle, array( 'title' => 'Renamed by owner' ) )->get_status()
		);
	}

	/**
	 * Course management is a write, so it is capability-checked too.
	 */
	public function test_adding_a_course_requires_edit_rights(): void {
		$bundle = $this->seeder->bundle();
		$course = $this->seeder->course();

		wp_set_current_user( $this->seeder->student() );

		$this->assertSame(
			403,
			$this->request( 'POST', '/bundles/' . $bundle . '/courses', array( 'course_id' => $course ) )->get_status()
		);

		wp_set_current_user( $this->seeder->admin() );

		$this->assertSame(
			201,
			$this->request( 'POST', '/bundles/' . $bundle . '/courses', array( 'course_id' => $course ) )->get_status()
		);
	}

	/**
	 * The duplicate guard applies to the API as much as the admin screen, and
	 * reports a 400 rather than silently succeeding.
	 */
	public function test_the_api_rejects_duplicate_courses(): void {
		$bundle = $this->seeder->bundle();
		$course = $this->seeder->course();

		wp_set_current_user( $this->seeder->admin() );

		$this->request( 'POST', '/bundles/' . $bundle . '/courses', array( 'course_id' => $course ) );
		$second = $this->request( 'POST', '/bundles/' . $bundle . '/courses', array( 'course_id' => $course ) );

		$this->assertSame( 400, $second->get_status() );
		$this->assertSame( 1, $this->countRows( 'courses', array( 'bundle_id' => $bundle ) ) );
	}

	/**
	 * Reordering through the API persists.
	 */
	public function test_reordering_via_the_api(): void {
		$courses = $this->seeder->courses( 3 );
		$bundle  = $this->seeder->bundle( array( 'courses' => $courses ) );

		wp_set_current_user( $this->seeder->admin() );

		$reversed = array_reverse( $courses );

		$response = $this->request(
			'PUT',
			'/bundles/' . $bundle . '/courses/order',
			array( 'course_ids' => $reversed )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $reversed, $this->seeder->repository()->get_course_ids( $bundle ) );
	}

	/**
	 * A learner may read their own progress and nobody else's.
	 */
	public function test_progress_is_private_to_the_learner(): void {
		$learner = $this->seeder->student();
		$nosy    = $this->seeder->student();
		$built   = $this->seeder->bundle_with_courses( 2 );

		wp_set_current_user( $learner );
		$this->assertSame( 200, $this->request( 'GET', '/bundles/' . $built['bundle'] . '/progress' )->get_status() );

		wp_set_current_user( $nosy );
		$this->assertSame(
			403,
			$this->request( 'GET', '/bundles/' . $built['bundle'] . '/progress', array( 'user_id' => $learner ) )->get_status()
		);

		wp_set_current_user( $this->seeder->admin() );
		$this->assertSame(
			200,
			$this->request( 'GET', '/bundles/' . $built['bundle'] . '/progress', array( 'user_id' => $learner ) )->get_status()
		);
	}

	/**
	 * The free-enrollment endpoint is the only public write, so it needs a
	 * logged-in user.
	 */
	public function test_free_enrollment_requires_login(): void {
		$built = $this->seeder->bundle_with_courses( 1 );

		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->request( 'POST', '/bundles/' . $built['bundle'] . '/enroll' )->get_status() );
	}

	/**
	 * A logged-in learner can enroll in a free bundle, once.
	 */
	public function test_free_enrollment_works_and_is_not_repeatable(): void {
		$this->require_tutor();

		$user  = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses( 2 );

		wp_set_current_user( $user );

		$first = $this->request( 'POST', '/bundles/' . $built['bundle'] . '/enroll' );

		$this->assertSame( 201, $first->get_status() );
		$this->assertTrue( $first->get_data()['enrolled'] );

		foreach ( $built['courses'] as $course_id ) {
			$this->assertEnrolled( $course_id, $user );
		}

		$second = $this->request( 'POST', '/bundles/' . $built['bundle'] . '/enroll' );

		$this->assertSame( 400, $second->get_status() );
		$this->assertSame( 1, $this->countRows( 'access', array( 'bundle_id' => $built['bundle'] ) ) );
	}

	/**
	 * A paid bundle cannot be claimed through the free endpoint. This is the
	 * obvious attack on the only public write in the plugin.
	 */
	public function test_a_paid_bundle_cannot_be_claimed_for_free(): void {
		$user  = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses(
			1,
			array(
				'access_type' => Bundle::ACCESS_TYPE_PAID,
				'price'       => 199.0,
			)
		);

		wp_set_current_user( $user );

		$response = $this->request( 'POST', '/bundles/' . $built['bundle'] . '/enroll' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 0, $this->countRows( 'access', array( 'bundle_id' => $built['bundle'] ) ) );
	}

	/**
	 * Repeated enrollment attempts are rate limited, so the endpoint cannot be
	 * hammered.
	 */
	public function test_free_enrollment_is_rate_limited(): void {
		$user   = $this->seeder->student();
		$bundle = $this->seeder->bundle();

		wp_set_current_user( $user );

		$statuses = array();

		for ( $i = 0; $i < 7; $i++ ) {
			$statuses[] = $this->request( 'POST', '/bundles/' . $bundle . '/enroll' )->get_status();
		}

		$this->assertContains( 429, $statuses, 'The endpoint should start refusing after a burst.' );
	}

	/**
	 * Managing entitlements directly is staff-only.
	 */
	public function test_entitlement_management_is_staff_only(): void {
		$learner = $this->seeder->student();
		$built   = $this->seeder->bundle_with_courses( 1 );

		wp_set_current_user( $learner );

		$this->assertSame(
			403,
			$this->request( 'POST', '/bundles/' . $built['bundle'] . '/access', array( 'user_id' => $learner ) )->get_status()
		);

		wp_set_current_user( $this->seeder->admin() );

		$response = $this->request(
			'POST',
			'/bundles/' . $built['bundle'] . '/access',
			array(
				'user_id' => $learner,
				'note'    => 'granted by support',
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( BundleAccess::SOURCE_MANUAL, $response->get_data()['source_type'] );
	}

	/**
	 * A learner can list their own bundles.
	 */
	public function test_a_learner_can_list_their_own_bundles(): void {
		$this->require_tutor();

		$user  = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses( 2 );

		tcb()->get( \SpaceWork\TutorCourseBundles\Tutor\EnrollmentService::class )
			->grant_access( $built['bundle'], $user, BundleAccess::SOURCE_MANUAL, 0 );

		wp_set_current_user( $user );

		$response = $this->request( 'GET', '/users/' . $user . '/bundles' );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( $built['bundle'], $data[0]['bundle']['id'] );
		$this->assertArrayHasKey( 'progress', $data[0] );
		$this->assertArrayHasKey( 'access', $data[0] );
	}

	/**
	 * One learner may not read another's bundle list.
	 */
	public function test_bundle_lists_are_private(): void {
		$owner = $this->seeder->student();
		$nosy  = $this->seeder->student();

		wp_set_current_user( $nosy );

		$this->assertSame( 403, $this->request( 'GET', '/users/' . $owner . '/bundles' )->get_status() );
	}

	/**
	 * A missing bundle is a 404, not a 500.
	 */
	public function test_unknown_bundles_return_404(): void {
		wp_set_current_user( $this->seeder->admin() );

		$this->assertSame( 404, $this->request( 'GET', '/bundles/999999' )->get_status() );
	}
}
