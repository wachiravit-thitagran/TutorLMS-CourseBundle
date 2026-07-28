<?php
/**
 * Roles, capabilities and post type registration.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Integration;

use SpaceWork\TutorCourseBundles\Infrastructure\PostTypes;
use SpaceWork\TutorCourseBundles\Support\Capabilities;
use SpaceWork\TutorCourseBundles\Tests\Support\TestCase;

/**
 * The bundle post type uses a custom capability type with `map_meta_cap`, which
 * is powerful but easy to get subtly wrong — a missing mapping silently gives
 * every subscriber edit rights.
 *
 * @covers \SpaceWork\TutorCourseBundles\Support\Capabilities
 * @covers \SpaceWork\TutorCourseBundles\Infrastructure\PostTypes
 */
final class CapabilitiesTest extends TestCase {

	/**
	 * The post type and its taxonomies exist.
	 */
	public function test_post_type_and_taxonomies_are_registered(): void {
		$this->assertTrue( post_type_exists( PostTypes::POST_TYPE ) );
		$this->assertTrue( taxonomy_exists( PostTypes::TAXONOMY_CAT ) );
		$this->assertTrue( taxonomy_exists( PostTypes::TAXONOMY_TAG ) );

		$object = get_post_type_object( PostTypes::POST_TYPE );

		$this->assertTrue( $object->public );
		$this->assertTrue( $object->show_in_rest );
		$this->assertTrue( $object->map_meta_cap );
	}

	/**
	 * Administrators hold every bundle capability.
	 *
	 * @dataProvider provideAdminCaps
	 *
	 * @param string $cap Capability.
	 */
	public function test_administrators_hold_every_capability( string $cap ): void {
		$admin = $this->seeder->admin();

		$this->assertTrue( user_can( $admin, $cap ), "Administrators should hold {$cap}." );
	}

	/**
	 * Administrator capabilities.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provideAdminCaps(): array {
		$cases = array();

		foreach ( Capabilities::administrator_caps() as $cap ) {
			$cases[ $cap ] = array( $cap );
		}

		return $cases;
	}

	/**
	 * A subscriber holds none of them. This is the check that stops a customer
	 * account from touching the catalogue.
	 *
	 * @dataProvider provideAdminCaps
	 *
	 * @param string $cap Capability.
	 */
	public function test_subscribers_hold_no_bundle_capabilities( string $cap ): void {
		$student = $this->seeder->student();

		$this->assertFalse( user_can( $student, $cap ), "Subscribers must not hold {$cap}." );
	}

	/**
	 * An instructor can author bundles but must not be able to administer the
	 * plugin or enroll students at will.
	 */
	public function test_instructors_can_author_but_not_administer(): void {
		$instructor = $this->seeder->instructor();

		$this->assertTrue( user_can( $instructor, Capabilities::EDIT_BUNDLES ) );
		$this->assertTrue( user_can( $instructor, Capabilities::PUBLISH_BUNDLES ) );
		$this->assertTrue( user_can( $instructor, Capabilities::VIEW_REPORTS ) );

		$this->assertFalse( user_can( $instructor, Capabilities::MANAGE ) );
		$this->assertFalse( user_can( $instructor, Capabilities::MANAGE_SETTINGS ) );
		$this->assertFalse( user_can( $instructor, Capabilities::ENROLL_STUDENTS ) );
		$this->assertFalse( user_can( $instructor, Capabilities::EDIT_OTHERS ) );
	}

	/**
	 * Meta capability mapping: an author may edit their own bundle, and nobody
	 * else's.
	 */
	public function test_instructors_can_only_edit_their_own_bundles(): void {
		$owner    = $this->seeder->instructor();
		$intruder = $this->seeder->instructor();

		$bundle = $this->seeder->bundle( array( 'author' => $owner ) );

		$this->assertTrue( user_can( $owner, Capabilities::EDIT_BUNDLE, $bundle ) );
		$this->assertFalse( user_can( $intruder, Capabilities::EDIT_BUNDLE, $bundle ) );
		$this->assertTrue( user_can( $this->seeder->admin(), Capabilities::EDIT_BUNDLE, $bundle ) );
	}

	/**
	 * Deleting follows the same rule as editing.
	 */
	public function test_delete_capability_is_scoped_to_the_author(): void {
		$owner    = $this->seeder->instructor();
		$intruder = $this->seeder->instructor();

		$bundle = $this->seeder->bundle( array( 'author' => $owner ) );

		$this->assertTrue( user_can( $owner, Capabilities::DELETE_BUNDLE, $bundle ) );
		$this->assertFalse( user_can( $intruder, Capabilities::DELETE_BUNDLE, $bundle ) );
	}

	/**
	 * The helper used throughout the admin agrees with the raw capability.
	 */
	public function test_can_edit_bundle_helper_matches_wordpress(): void {
		$owner  = $this->seeder->instructor();
		$bundle = $this->seeder->bundle( array( 'author' => $owner ) );

		wp_set_current_user( $owner );
		$this->assertTrue( Capabilities::can_edit_bundle( $bundle ) );

		wp_set_current_user( $this->seeder->student() );
		$this->assertFalse( Capabilities::can_edit_bundle( $bundle ) );

		wp_set_current_user( 0 );
		$this->assertFalse( Capabilities::can_edit_bundle( $bundle ), 'Logged-out visitors edit nothing.' );
	}

	/**
	 * Removing capabilities has to be complete, or an uninstall leaves orphan
	 * permissions behind on every role.
	 */
	public function test_capabilities_can_be_fully_removed_and_restored(): void {
		Capabilities::remove_roles_capabilities();

		$admin_role = get_role( 'administrator' );

		foreach ( Capabilities::administrator_caps() as $cap ) {
			$this->assertFalse( $admin_role->has_cap( $cap ), "{$cap} should have been removed." );
		}

		Capabilities::add_roles_capabilities();

		$admin_role = get_role( 'administrator' );

		foreach ( Capabilities::administrator_caps() as $cap ) {
			$this->assertTrue( $admin_role->has_cap( $cap ), "{$cap} should have been restored." );
		}
	}

	/**
	 * Acceptance criterion 12: the plugin must not damage Tutor LMS. Its course
	 * post type and the courses themselves survive our registration.
	 */
	public function test_tutor_course_post_type_is_untouched(): void {
		$this->require_tutor();

		$course_type = \SpaceWork\TutorCourseBundles\Compatibility::course_post_type();

		$this->assertTrue( post_type_exists( $course_type ) );
		$this->assertNotSame( PostTypes::POST_TYPE, $course_type );

		$course = $this->seeder->course();

		$this->assertSame( $course_type, get_post_type( $course ) );
	}
}
