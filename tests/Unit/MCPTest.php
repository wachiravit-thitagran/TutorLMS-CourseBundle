<?php
/**
 * MCP abilities tests.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Unit;

use Brain\Monkey\Functions;
use SpaceWork\TutorCourseBundles\MCP;

final class MCPTest extends UnitTestCase {

	public function test_registers_bundle_category_and_abilities(): void {
		$categories = array();
		$abilities  = array();

		Functions\when( 'wp_register_ability_category' )->alias(
			static function ( $name, $args ) use ( &$categories ): void {
				$categories[ $name ] = $args;
			}
		);
		Functions\when( 'wp_register_ability' )->alias(
			static function ( $name, $args ) use ( &$abilities ): void {
				$abilities[ $name ] = $args;
			}
		);

		MCP::register_category();
		MCP::register_abilities();

		$this->assertArrayHasKey( 'tutorlms-bundle', $categories );
		$this->assertArrayHasKey( 'tutorlms-bundle/list-bundles', $abilities );
		$this->assertArrayHasKey( 'tutorlms-bundle/get-bundle', $abilities );
		$this->assertArrayHasKey( 'tutorlms-bundle/create-bundle', $abilities );
		$this->assertArrayHasKey( 'tutorlms-bundle/update-bundle', $abilities );
		$this->assertArrayHasKey( 'tutorlms-bundle/delete-bundle', $abilities );
		$this->assertArrayHasKey( 'tutorlms-bundle/get-bundle-progress', $abilities );
		$this->assertTrue( $abilities['tutorlms-bundle/delete-bundle']['meta']['annotations']['destructive'] );
	}
}
