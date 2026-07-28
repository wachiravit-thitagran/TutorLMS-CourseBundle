<?php
/**
 * Shortcode API.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Frontend;

use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Infrastructure\AccessRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\BundleRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Five shortcodes so bundles can be dropped into any page builder without
 * needing blocks or widgets.
 */
final class Shortcodes {

	/**
	 * Constructor.
	 *
	 * @param BundleRepository $bundles   Bundle repository.
	 * @param TemplateLoader   $templates Template loader.
	 */
	public function __construct(
		private readonly BundleRepository $bundles,
		private readonly TemplateLoader $templates
	) {}

	/**
	 * Register shortcodes.
	 */
	public function register_hooks(): void {
		add_shortcode( 'tcb_bundle', array( $this, 'render_bundle' ) );
		add_shortcode( 'tcb_bundle_courses', array( $this, 'render_bundle_courses' ) );
		add_shortcode( 'tcb_bundle_progress', array( $this, 'render_bundle_progress' ) );
		add_shortcode( 'tcb_my_bundles', array( $this, 'render_my_bundles' ) );
		add_shortcode( 'tcb_bundle_grid', array( $this, 'render_bundle_grid' ) );
	}

	/**
	 * Ensure styles load when a shortcode is used outside bundle pages.
	 */
	private function force_assets(): void {
		add_filter( 'tcb/frontend/enqueue_assets', '__return_true' );

		if ( ! wp_style_is( 'tcb-frontend', 'enqueued' ) ) {
			wp_enqueue_style( 'tcb-frontend', TCB_URL . 'assets/css/frontend.css', array(), TCB_VERSION );
		}
	}

	/**
	 * Resolve the bundle a shortcode refers to.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 */
	private function resolve_bundle( array $atts ): ?Bundle {
		$id = (int) ( $atts['id'] ?? 0 );

		if ( $id <= 0 && is_singular( PostTypes::POST_TYPE ) ) {
			$id = (int) get_the_ID();
		}

		return $id > 0 ? $this->bundles->find( $id ) : null;
	}

	/**
	 * [tcb_bundle id="123"] — full bundle card with pricing and CTA.
	 *
	 * @param array<string, mixed>|string $atts Attributes.
	 */
	public function render_bundle( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'id'       => 0,
				'style'    => 'card',
				'show_cta' => 'yes',
			),
			(array) $atts,
			'tcb_bundle'
		);

		$bundle = $this->resolve_bundle( $atts );

		if ( ! $bundle instanceof Bundle || 'publish' !== $bundle->post->post_status ) {
			return '';
		}

		$this->force_assets();

		$access_controller = tcb()->get( AccessController::class );

		return $this->templates->get_html(
			'bundle-card',
			array(
				'bundle'   => $bundle,
				'stats'    => $this->bundles->get_stats( $bundle->get_id() ),
				'state'    => $access_controller->get_purchase_state( $bundle->get_id() ),
				'style'    => sanitize_html_class( (string) $atts['style'] ),
				'show_cta' => 'yes' === $atts['show_cta'],
			)
		);
	}

	/**
	 * [tcb_bundle_courses id="123"] — the course list.
	 *
	 * @param array<string, mixed>|string $atts Attributes.
	 */
	public function render_bundle_courses( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'id'        => 0,
				'show_lock' => 'yes',
			),
			(array) $atts,
			'tcb_bundle_courses'
		);

		$bundle = $this->resolve_bundle( $atts );

		if ( ! $bundle instanceof Bundle ) {
			return '';
		}

		$this->force_assets();

		$user_id  = get_current_user_id();
		$progress = tcb()->get( ProgressController::class )->get_progress( $bundle->get_id(), $user_id );

		return $this->templates->get_html(
			'bundle-courses',
			array(
				'bundle'     => $bundle,
				'courses'    => $this->bundles->get_courses( $bundle->get_id(), true ),
				'progress'   => $progress,
				'has_access' => tcb()->get( AccessController::class )->user_has_access( $bundle->get_id(), $user_id ),
				'show_lock'  => 'yes' === $atts['show_lock'],
			)
		);
	}

	/**
	 * [tcb_bundle_progress id="123"] — progress bar for the current learner.
	 *
	 * @param array<string, mixed>|string $atts Attributes.
	 */
	public function render_bundle_progress( $atts = array() ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), (array) $atts, 'tcb_bundle_progress' );

		$bundle = $this->resolve_bundle( $atts );

		if ( ! $bundle instanceof Bundle || ! is_user_logged_in() ) {
			return '';
		}

		$this->force_assets();

		return $this->templates->get_html(
			'bundle-progress',
			array(
				'bundle'   => $bundle,
				'progress' => tcb()->get( ProgressController::class )->get_progress( $bundle->get_id(), get_current_user_id() ),
			)
		);
	}

	/**
	 * [tcb_my_bundles] — the learner's own bundles.
	 *
	 * @param array<string, mixed>|string $atts Attributes.
	 */
	public function render_my_bundles( $atts = array() ): string {
		$atts = shortcode_atts(
			array( 'status' => 'all' ),
			(array) $atts,
			'tcb_my_bundles'
		);

		if ( ! is_user_logged_in() ) {
			return '<p class="tcb-notice">' . esc_html__( 'Please log in to see your bundles.', 'tutor-course-bundles' ) . '</p>';
		}

		$this->force_assets();

		$user_id  = get_current_user_id();
		$accesses = tcb()->get( AccessRepository::class )->find_preferred_for_user( $user_id );
		$progress = tcb()->get( ProgressController::class );
		$items    = array();

		foreach ( $accesses as $access ) {
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

		$items = $this->filter_by_status( $items, (string) $atts['status'] );

		return $this->templates->get_html(
			'dashboard/bundles',
			array(
				'items'   => $items,
				'user_id' => $user_id,
			)
		);
	}

	/**
	 * [tcb_bundle_grid] — a catalogue of published bundles.
	 *
	 * @param array<string, mixed>|string $atts Attributes.
	 */
	public function render_bundle_grid( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'count'    => 12,
				'category' => '',
				'columns'  => 3,
				'orderby'  => 'date',
				'order'    => 'DESC',
			),
			(array) $atts,
			'tcb_bundle_grid'
		);

		$this->force_assets();

		$args = array(
			'posts_per_page' => max( 1, min( 50, (int) $atts['count'] ) ),
			'orderby'        => sanitize_key( (string) $atts['orderby'] ),
			'order'          => 'ASC' === strtoupper( (string) $atts['order'] ) ? 'ASC' : 'DESC',
		);

		if ( '' !== $atts['category'] ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => PostTypes::TAXONOMY_CAT,
					'field'    => 'slug',
					'terms'    => array_map( 'sanitize_title', explode( ',', (string) $atts['category'] ) ),
				),
			);
		}

		$bundles           = $this->bundles->query( $args );
		$access_controller = tcb()->get( AccessController::class );

		ob_start();

		printf(
			'<div class="tcb-bundle-grid tcb-columns-%d">',
			(int) max( 1, min( 6, (int) $atts['columns'] ) )
		);

		foreach ( $bundles as $bundle ) {
			$this->templates->render(
				'bundle-card',
				array(
					'bundle'   => $bundle,
					'stats'    => $this->bundles->get_stats( $bundle->get_id() ),
					'state'    => $access_controller->get_purchase_state( $bundle->get_id() ),
					'style'    => 'grid',
					'show_cta' => true,
				)
			);
		}

		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * Filter dashboard items by learner-facing status.
	 *
	 * @param array<int, array<string, mixed>> $items  Items.
	 * @param string                           $status all|active|completed|expired.
	 * @return array<int, array<string, mixed>>
	 */
	private function filter_by_status( array $items, string $status ): array {
		if ( 'all' === $status ) {
			return $items;
		}

		return array_values(
			array_filter(
				$items,
				static function ( array $item ) use ( $status ): bool {
					/**
					 * Entitlement for this card.
					 *
					 * @var BundleAccess $access
					 */
					$access = $item['access'];

					return match ( $status ) {
						'active'    => $access->is_usable(),
						'completed' => $item['progress']->is_complete(),
						'expired'   => $access->is_expired() || BundleAccess::STATUS_EXPIRED === $access->status,
						default     => true,
					};
				}
			)
		);
	}
}
