<?php
/**
 * Capability definitions and role mapping.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Custom capabilities for bundles.
 *
 * The CPT uses `map_meta_cap` with a custom capability type, so WordPress
 * derives edit_post/delete_post/read_post checks from the plural caps below.
 */
final class Capabilities {

	public const MANAGE           = 'manage_tcb_bundles';
	public const EDIT_BUNDLE      = 'edit_tcb_bundle';
	public const EDIT_BUNDLES     = 'edit_tcb_bundles';
	public const EDIT_OTHERS      = 'edit_others_tcb_bundles';
	public const PUBLISH_BUNDLES  = 'publish_tcb_bundles';
	public const READ_PRIVATE     = 'read_private_tcb_bundles';
	public const DELETE_BUNDLE    = 'delete_tcb_bundle';
	public const DELETE_BUNDLES   = 'delete_tcb_bundles';
	public const DELETE_OTHERS    = 'delete_others_tcb_bundles';
	public const DELETE_PRIVATE   = 'delete_private_tcb_bundles';
	public const DELETE_PUBLISHED = 'delete_published_tcb_bundles';
	public const EDIT_PRIVATE     = 'edit_private_tcb_bundles';
	public const EDIT_PUBLISHED   = 'edit_published_tcb_bundles';
	public const ENROLL_STUDENTS  = 'enroll_tcb_bundle_students';
	public const VIEW_REPORTS     = 'view_tcb_bundle_reports';
	public const MANAGE_SETTINGS  = 'manage_tcb_bundle_settings';

	/**
	 * Capability map handed to register_post_type().
	 *
	 * @return array<string, string>
	 */
	public static function post_type_caps(): array {
		return array(
			'edit_post'              => self::EDIT_BUNDLE,
			'read_post'              => 'read_tcb_bundle',
			'delete_post'            => self::DELETE_BUNDLE,
			'edit_posts'             => self::EDIT_BUNDLES,
			'edit_others_posts'      => self::EDIT_OTHERS,
			'publish_posts'          => self::PUBLISH_BUNDLES,
			'read_private_posts'     => self::READ_PRIVATE,
			'delete_posts'           => self::DELETE_BUNDLES,
			'delete_private_posts'   => self::DELETE_PRIVATE,
			'delete_published_posts' => self::DELETE_PUBLISHED,
			'delete_others_posts'    => self::DELETE_OTHERS,
			'edit_private_posts'     => self::EDIT_PRIVATE,
			'edit_published_posts'   => self::EDIT_PUBLISHED,
			'create_posts'           => self::EDIT_BUNDLES,
		);
	}

	/**
	 * Everything an administrator gets.
	 *
	 * @return string[]
	 */
	public static function administrator_caps(): array {
		return array(
			self::MANAGE,
			self::EDIT_BUNDLES,
			self::EDIT_OTHERS,
			self::PUBLISH_BUNDLES,
			self::READ_PRIVATE,
			self::DELETE_BUNDLES,
			self::DELETE_PRIVATE,
			self::DELETE_PUBLISHED,
			self::DELETE_OTHERS,
			self::EDIT_PRIVATE,
			self::EDIT_PUBLISHED,
			self::ENROLL_STUDENTS,
			self::VIEW_REPORTS,
			self::MANAGE_SETTINGS,
		);
	}

	/**
	 * Instructors may author their own bundles but never touch someone else's.
	 *
	 * @return string[]
	 */
	public static function instructor_caps(): array {
		return array(
			self::EDIT_BUNDLES,
			self::PUBLISH_BUNDLES,
			self::DELETE_BUNDLES,
			self::DELETE_PUBLISHED,
			self::EDIT_PUBLISHED,
			self::VIEW_REPORTS,
		);
	}

	/**
	 * Grant capabilities to the relevant roles.
	 */
	public static function add_roles_capabilities(): void {
		// These singular capabilities are meta capabilities. WordPress maps them
		// to the primitive role capabilities below for a specific post, so they
		// must never be assigned directly to a role.
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( array( self::EDIT_BUNDLE, 'read_tcb_bundle', self::DELETE_BUNDLE ) as $meta_cap ) {
				$role->remove_cap( $meta_cap );
			}
		}

		$admin = get_role( 'administrator' );

		if ( $admin ) {
			foreach ( self::administrator_caps() as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		foreach ( array( 'tutor_instructor', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );

			if ( ! $role ) {
				continue;
			}

			foreach ( self::instructor_caps() as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Remove every custom capability (uninstall path).
	 */
	public static function remove_roles_capabilities(): void {
		$all = array_unique(
			array_merge(
				self::administrator_caps(),
				self::instructor_caps(),
				array( self::EDIT_BUNDLE, 'read_tcb_bundle', self::DELETE_BUNDLE )
			)
		);

		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( $all as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Whether the current user may administer bundles globally.
	 */
	public static function current_user_can_manage(): bool {
		return current_user_can( self::MANAGE ) || current_user_can( 'manage_options' );
	}

	/**
	 * Whether a user may edit a specific bundle.
	 *
	 * @param int      $bundle_id Bundle post ID.
	 * @param int|null $user_id   User ID, defaults to current user.
	 */
	public static function can_edit_bundle( int $bundle_id, ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();

		if ( $user_id <= 0 ) {
			return false;
		}

		return user_can( $user_id, self::EDIT_BUNDLE, $bundle_id );
	}
}
