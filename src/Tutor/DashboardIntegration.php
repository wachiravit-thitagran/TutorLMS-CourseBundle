<?php
/**
 * "My Bundles" inside the Tutor LMS student dashboard.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tutor;

use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Frontend\ProgressController;
use SpaceWork\TutorCourseBundles\Frontend\TemplateLoader;
use SpaceWork\TutorCourseBundles\Infrastructure\AccessRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\BundleRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a dashboard tab without touching any Pro code.
 *
 * Tutor exposes `tutor_dashboard/nav_items` for the menu and
 * `tutor_get_template_path` for template resolution — both are public filters
 * in the free plugin, so the tab renders from this plugin's own template file.
 */
final class DashboardIntegration {

	public const ENDPOINT = 'my-bundles';

	/**
	 * Constructor.
	 *
	 * @param BundleRepository $bundles   Bundle repository.
	 * @param AccessRepository $access    Entitlement repository.
	 * @param TemplateLoader   $templates Template loader.
	 */
	public function __construct(
		private readonly BundleRepository $bundles,
		private readonly AccessRepository $access,
		private readonly TemplateLoader $templates
	) {}

	/**
	 * Register dashboard hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'tutor_dashboard/nav_items', array( $this, 'add_nav_item' ) );
		add_filter( 'tutor_get_template_path', array( $this, 'filter_template_path' ), 10, 2 );
		add_filter( 'load_dashboard_template_part_from_other_location', array( $this, 'filter_dashboard_template' ) );
		add_action( 'tutor_dashboard/before_content', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Insert the nav item after "Enrolled Courses" when that entry exists.
	 *
	 * @param array<string, mixed> $items Existing nav items.
	 * @return array<string, mixed>
	 */
	public function add_nav_item( $items ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}

		$entry = array(
			self::ENDPOINT => array(
				'title'    => __( 'My Bundles', 'tutor-course-bundles' ),
				'auth_cap' => 'read',
				'icon'     => 'tutor-icon-bundle',
			),
		);

		$anchor = 'enrolled-courses';

		if ( ! array_key_exists( $anchor, $items ) ) {
			return array_merge( $items, $entry );
		}

		$result = array();

		foreach ( $items as $key => $value ) {
			$result[ $key ] = $value;

			if ( $key === $anchor ) {
				$result = array_merge( $result, $entry );
			}
		}

		return $result;
	}

	/**
	 * Point Tutor at this plugin's dashboard templates.
	 *
	 * @param string $location Resolved template path.
	 * @param string $template Template name, e.g. "dashboard.my-bundles".
	 */
	public function filter_template_path( $location, $template = '' ) {
		$template = (string) $template;

		if ( 'dashboard.' . self::ENDPOINT !== $template && 'dashboard/' . self::ENDPOINT !== $template ) {
			return $location;
		}

		return $this->resolve_dashboard_template();
	}

	/**
	 * Fallback filter used by some Tutor versions.
	 *
	 * @param string $location Resolved template path.
	 */
	public function filter_dashboard_template( $location ) {
		if ( self::ENDPOINT !== $this->get_current_endpoint() ) {
			return $location;
		}

		return $this->resolve_dashboard_template();
	}

	/**
	 * Choose between the list and detail dashboard templates.
	 */
	private function resolve_dashboard_template(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bundle_id = isset( $_GET['bundle_id'] ) ? absint( wp_unslash( $_GET['bundle_id'] ) ) : 0;

		return $bundle_id > 0
			? $this->templates->locate( 'dashboard/bundle-details' )
			: $this->templates->locate( 'dashboard/bundles' );
	}

	/**
	 * Current Tutor dashboard endpoint slug.
	 */
	private function get_current_endpoint(): string {
		$page = get_query_var( 'tutor_dashboard_page' );

		return is_string( $page ) ? $page : '';
	}

	/**
	 * Load frontend styles inside the dashboard.
	 */
	public function maybe_enqueue_assets(): void {
		if ( self::ENDPOINT !== $this->get_current_endpoint() ) {
			return;
		}

		wp_enqueue_style( 'tcb-frontend', TCB_URL . 'assets/css/frontend.css', array(), TCB_VERSION );
	}

	/**
	 * Data for the dashboard list template.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_dashboard_items( int $user_id ): array {
		$progress = tcb()->get( ProgressController::class );
		$items    = array();

		foreach ( $this->access->find_preferred_for_user( $user_id ) as $access ) {
			$bundle = $this->bundles->find( $access->bundle_id );

			if ( ! $bundle instanceof Bundle ) {
				continue;
			}

			$items[] = array(
				'bundle'   => $bundle,
				'access'   => $access,
				'progress' => $progress->get_progress( $access->bundle_id, $user_id ),
			);
		}

		return $items;
	}

	/**
	 * URL of the dashboard bundle list, optionally for one bundle.
	 *
	 * @param int $bundle_id Bundle post ID, 0 for the list.
	 */
	public function get_dashboard_url( int $bundle_id = 0 ): string {
		$adapter = new TutorAdapter();
		$url     = $adapter->dashboard_url( self::ENDPOINT );

		return $bundle_id > 0 ? add_query_arg( 'bundle_id', $bundle_id, $url ) : $url;
	}
}
