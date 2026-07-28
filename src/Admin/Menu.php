<?php
/**
 * Admin menu registration.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Admin;

use SpaceWork\TutorCourseBundles\Infrastructure\PostTypes;
use SpaceWork\TutorCourseBundles\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Hangs the bundle screens off Tutor LMS's own menu when it exists, and falls
 * back to a top-level menu otherwise.
 */
final class Menu {

	public const PARENT_SLUG   = 'tutor';
	public const PAGE_SETTINGS = 'tcb-settings';
	public const PAGE_ENROLL   = 'tcb-enrollments';
	public const PAGE_REPORTS  = 'tcb-reports';

	/**
	 * Register admin hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_filter( 'parent_file', array( $this, 'highlight_parent_menu' ) );
		add_filter( 'submenu_file', array( $this, 'highlight_submenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
	}

	/**
	 * Whether Tutor's admin menu is present.
	 */
	private function tutor_menu_exists(): bool {
		global $menu;

		if ( ! is_array( $menu ) ) {
			return false;
		}

		foreach ( $menu as $item ) {
			if ( isset( $item[2] ) && self::PARENT_SLUG === $item[2] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Add all bundle admin pages.
	 */
	public function register_menu(): void {
		$parent = $this->tutor_menu_exists() ? self::PARENT_SLUG : null;

		if ( null === $parent ) {
			add_menu_page(
				__( 'Course Bundles', 'tutor-course-bundles' ),
				__( 'Course Bundles', 'tutor-course-bundles' ),
				Capabilities::EDIT_BUNDLES,
				'edit.php?post_type=' . PostTypes::POST_TYPE,
				'',
				'dashicons-portfolio',
				26
			);

			$parent = 'edit.php?post_type=' . PostTypes::POST_TYPE;
		} else {
			add_submenu_page(
				$parent,
				__( 'Course Bundles', 'tutor-course-bundles' ),
				__( 'Course Bundles', 'tutor-course-bundles' ),
				Capabilities::EDIT_BUNDLES,
				'edit.php?post_type=' . PostTypes::POST_TYPE
			);

			add_submenu_page(
				$parent,
				__( 'Add New Bundle', 'tutor-course-bundles' ),
				__( 'Add New Bundle', 'tutor-course-bundles' ),
				Capabilities::EDIT_BUNDLES,
				'post-new.php?post_type=' . PostTypes::POST_TYPE
			);
		}

		add_submenu_page(
			$parent,
			__( 'Bundle Enrollments', 'tutor-course-bundles' ),
			__( 'Bundle Enrollments', 'tutor-course-bundles' ),
			Capabilities::ENROLL_STUDENTS,
			self::PAGE_ENROLL,
			array( tcb()->get( EnrollmentManager::class ), 'render_page' )
		);

		add_submenu_page(
			$parent,
			__( 'Bundle Reports', 'tutor-course-bundles' ),
			__( 'Bundle Reports', 'tutor-course-bundles' ),
			Capabilities::VIEW_REPORTS,
			self::PAGE_REPORTS,
			array( tcb()->get( Reports::class ), 'render_page' )
		);

		add_submenu_page(
			$parent,
			__( 'Bundle Settings', 'tutor-course-bundles' ),
			__( 'Bundle Settings', 'tutor-course-bundles' ),
			Capabilities::MANAGE_SETTINGS,
			self::PAGE_SETTINGS,
			array( tcb()->get( Settings::class ), 'render_page' )
		);
	}

	/**
	 * Keep the Tutor menu open while editing a bundle.
	 *
	 * @param string $parent_file Current parent file.
	 */
	public function highlight_parent_menu( $parent_file ) {
		$screen = get_current_screen();

		if ( $screen instanceof \WP_Screen && PostTypes::POST_TYPE === $screen->post_type && $this->tutor_menu_exists() ) {
			return self::PARENT_SLUG;
		}

		return $parent_file;
	}

	/**
	 * Highlight the right submenu entry on bundle screens.
	 *
	 * @param string|null $submenu_file Current submenu file.
	 */
	public function highlight_submenu( $submenu_file ) {
		$screen = get_current_screen();

		if ( $screen instanceof \WP_Screen && PostTypes::POST_TYPE === $screen->post_type ) {
			return 'edit.php?post_type=' . PostTypes::POST_TYPE;
		}

		return $submenu_file;
	}

	/**
	 * Load admin CSS on bundle screens only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_admin_styles( $hook_suffix ): void {
		$screen    = get_current_screen();
		$is_bundle = $screen instanceof \WP_Screen && PostTypes::POST_TYPE === $screen->post_type;
		$is_page   = in_array(
			$hook_suffix,
			array(
				'tutor_page_' . self::PAGE_ENROLL,
				'tutor_page_' . self::PAGE_REPORTS,
				'tutor_page_' . self::PAGE_SETTINGS,
			),
			true
		) || false !== strpos( (string) $hook_suffix, 'tcb-' );

		if ( ! $is_bundle && ! $is_page ) {
			return;
		}

		wp_enqueue_style( 'tcb-admin', TCB_URL . 'assets/css/admin.css', array(), TCB_VERSION );
	}
}
