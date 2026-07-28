<?php
/**
 * Custom post type and taxonomy registration.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Infrastructure;

use SpaceWork\TutorCourseBundles\Support\Cache;
use SpaceWork\TutorCourseBundles\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `tcb_bundle` plus its category and tag taxonomies.
 */
final class PostTypes {

	public const POST_TYPE    = 'tcb_bundle';
	public const TAXONOMY_CAT = 'tcb_bundle_category';
	public const TAXONOMY_TAG = 'tcb_bundle_tag';

	/**
	 * Wire up WordPress hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_taxonomies' ) );
		add_action( 'init', array( $this, 'register_meta' ) );

		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'on_bundle_saved' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'on_bundle_deleted' ) );
		add_action( 'trashed_post', array( $this, 'on_bundle_trashed' ) );
	}

	/**
	 * Register the bundle post type.
	 */
	public function register_post_type(): void {
		$slug = (string) get_option( 'tcb_bundle_slug', 'course-bundle' );

		$labels = array(
			'name'                  => _x( 'Course Bundles', 'post type general name', 'tutor-course-bundles' ),
			'singular_name'         => _x( 'Course Bundle', 'post type singular name', 'tutor-course-bundles' ),
			'menu_name'             => _x( 'Course Bundles', 'admin menu', 'tutor-course-bundles' ),
			'add_new'               => __( 'Add New', 'tutor-course-bundles' ),
			'add_new_item'          => __( 'Add New Bundle', 'tutor-course-bundles' ),
			'edit_item'             => __( 'Edit Bundle', 'tutor-course-bundles' ),
			'new_item'              => __( 'New Bundle', 'tutor-course-bundles' ),
			'view_item'             => __( 'View Bundle', 'tutor-course-bundles' ),
			'view_items'            => __( 'View Bundles', 'tutor-course-bundles' ),
			'search_items'          => __( 'Search Bundles', 'tutor-course-bundles' ),
			'not_found'             => __( 'No bundles found.', 'tutor-course-bundles' ),
			'not_found_in_trash'    => __( 'No bundles found in Trash.', 'tutor-course-bundles' ),
			'all_items'             => __( 'All Bundles', 'tutor-course-bundles' ),
			'archives'              => __( 'Bundle Archives', 'tutor-course-bundles' ),
			'featured_image'        => __( 'Bundle Cover Image', 'tutor-course-bundles' ),
			'set_featured_image'    => __( 'Set bundle cover image', 'tutor-course-bundles' ),
			'remove_featured_image' => __( 'Remove bundle cover image', 'tutor-course-bundles' ),
			'items_list'            => __( 'Bundles list', 'tutor-course-bundles' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			// The bundle UI lives under the Tutor LMS menu; Menu.php adds the entry.
			'show_in_menu'       => false,
			'show_in_nav_menus'  => true,
			'show_in_admin_bar'  => true,
			'show_in_rest'       => true,
			'rest_base'          => 'tcb-bundles',
			'query_var'          => true,
			'rewrite'            => array(
				'slug'       => $slug,
				'with_front' => false,
			),
			'capability_type'    => array( 'tcb_bundle', 'tcb_bundles' ),
			'capabilities'       => Capabilities::post_type_caps(),
			'map_meta_cap'       => true,
			'has_archive'        => (string) get_option( 'tcb_bundle_archive_slug', 'course-bundles' ),
			'hierarchical'       => false,
			'menu_position'      => 26,
			'menu_icon'          => 'dashicons-portfolio',
			'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt', 'author', 'revisions', 'custom-fields' ),
			'taxonomies'         => array( self::TAXONOMY_CAT, self::TAXONOMY_TAG ),
		);

		/**
		 * Filter the bundle post type registration arguments.
		 *
		 * @param array<string, mixed> $args Registration arguments.
		 */
		register_post_type( self::POST_TYPE, apply_filters( 'tcb/post_type/args', $args ) );
	}

	/**
	 * Register bundle taxonomies.
	 */
	public function register_taxonomies(): void {
		register_taxonomy(
			self::TAXONOMY_CAT,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Bundle Categories', 'tutor-course-bundles' ),
					'singular_name' => __( 'Bundle Category', 'tutor-course-bundles' ),
					'search_items'  => __( 'Search Bundle Categories', 'tutor-course-bundles' ),
					'all_items'     => __( 'All Bundle Categories', 'tutor-course-bundles' ),
					'edit_item'     => __( 'Edit Bundle Category', 'tutor-course-bundles' ),
					'add_new_item'  => __( 'Add New Bundle Category', 'tutor-course-bundles' ),
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'bundle-category' ),
			)
		);

		register_taxonomy(
			self::TAXONOMY_TAG,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Bundle Tags', 'tutor-course-bundles' ),
					'singular_name' => __( 'Bundle Tag', 'tutor-course-bundles' ),
					'search_items'  => __( 'Search Bundle Tags', 'tutor-course-bundles' ),
					'all_items'     => __( 'All Bundle Tags', 'tutor-course-bundles' ),
				),
				'hierarchical'      => false,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'bundle-tag' ),
			)
		);
	}

	/**
	 * Register meta so it is readable/writable through the REST API with
	 * proper sanitisation and auth callbacks.
	 */
	public function register_meta(): void {
		$numeric = array(
			\SpaceWork\TutorCourseBundles\Domain\Bundle::META_PRICE,
			\SpaceWork\TutorCourseBundles\Domain\Bundle::META_SALE_PRICE,
		);

		foreach ( $numeric as $key ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'              => 'number',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => static fn( $value ) => (float) $value,
					'auth_callback'     => static fn(): bool => current_user_can( Capabilities::EDIT_BUNDLES ),
				)
			);
		}

		$strings = array(
			\SpaceWork\TutorCourseBundles\Domain\Bundle::META_ACCESS_TYPE,
			\SpaceWork\TutorCourseBundles\Domain\Bundle::META_ENROLLMENT_MODE,
			\SpaceWork\TutorCourseBundles\Domain\Bundle::META_COMPLETION_MODE,
			\SpaceWork\TutorCourseBundles\Domain\Bundle::META_ESTIMATED_DURATION,
			\SpaceWork\TutorCourseBundles\Domain\Bundle::META_DIFFICULTY,
			\SpaceWork\TutorCourseBundles\Domain\Bundle::META_GRANT_NEW_COURSES,
		);

		foreach ( $strings as $key ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => static fn(): bool => current_user_can( Capabilities::EDIT_BUNDLES ),
				)
			);
		}

		$integers = array(
			\SpaceWork\TutorCourseBundles\Domain\Bundle::META_COURSE_COUNT,
			\SpaceWork\TutorCourseBundles\Domain\Bundle::META_WC_PRODUCT_ID,
			\SpaceWork\TutorCourseBundles\Domain\Bundle::META_ACCESS_DURATION,
		);

		foreach ( $integers as $key ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'              => 'integer',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'absint',
					'auth_callback'     => static fn(): bool => current_user_can( Capabilities::EDIT_BUNDLES ),
				)
			);
		}
	}

	/**
	 * Invalidate caches when a bundle is saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update.
	 */
	public function on_bundle_saved( int $post_id, \WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		Cache::flush_bundle( $post_id );

		if ( $update ) {
			do_action( 'tcb/bundle/updated', $post_id );
		} else {
			do_action( 'tcb/bundle/created', $post_id );
		}
	}

	/**
	 * Clean up membership rows when a bundle is permanently deleted.
	 *
	 * Entitlement rows are intentionally left alone: they are the historical
	 * record of what a learner paid for and must survive the bundle itself.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_bundle_deleted( int $post_id ): void {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		global $wpdb;

		$table = \SpaceWork\TutorCourseBundles\Support\Migration::table_bundle_courses();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'bundle_id' => $post_id ), array( '%d' ) );

		Cache::flush_bundle( $post_id );

		do_action( 'tcb/bundle/deleted', $post_id );
	}

	/**
	 * Flush caches when a bundle is trashed.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_bundle_trashed( int $post_id ): void {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		Cache::flush_bundle( $post_id );

		do_action( 'tcb/bundle/trashed', $post_id );
	}
}
