<?php
/**
 * Unit tests for REST write contracts.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Unit;

use ReflectionClass;
use SpaceWork\TutorCourseBundles\Rest\BundleController;

/**
 * @covers \SpaceWork\TutorCourseBundles\Rest\BundleController
 */
final class BundleControllerTest extends UnitTestCase {

	/**
	 * Update args must not inject create-only defaults.
	 */
	public function test_update_status_has_no_default(): void {
		$controller = ( new ReflectionClass( BundleController::class ) )->newInstanceWithoutConstructor();
		$method     = new \ReflectionMethod( BundleController::class, 'get_update_args' );
		$args = $method->invoke( $controller );

		$this->assertArrayHasKey( 'status', $args );
		$this->assertArrayNotHasKey( 'default', $args['status'] );
	}

	/**
	 * New bundles still default to draft.
	 */
	public function test_create_status_defaults_to_draft(): void {
		$controller = ( new ReflectionClass( BundleController::class ) )->newInstanceWithoutConstructor();
		$method     = new \ReflectionMethod( BundleController::class, 'get_create_args' );
		$args = $method->invoke( $controller );

		$this->assertSame( 'draft', $args['status']['default'] );
	}
}
