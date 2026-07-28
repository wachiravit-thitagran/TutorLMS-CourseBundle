<?php
/**
 * Bundle list table columns.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Admin;

use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Infrastructure\AccessRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\BundleRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the columns an admin actually needs at a glance: how many courses are in
 * the bundle, what it costs, and how many learners hold it.
 */
final class BundleColumns {

	/**
	 * Constructor.
	 *
	 * @param BundleRepository $bundles Bundle repository.
	 */
	public function __construct( private readonly BundleRepository $bundles ) {}

	/**
	 * Register admin hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'manage_' . PostTypes::POST_TYPE . '_posts_columns', array( $this, 'add_columns' ) );
		add_action( 'manage_' . PostTypes::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-' . PostTypes::POST_TYPE . '_sortable_columns', array( $this, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( $this, 'handle_sorting' ) );
	}

	/**
	 * Insert custom columns before the date column.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function add_columns( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$new['tcb_courses']  = __( 'Courses', 'tutor-course-bundles' );
				$new['tcb_price']    = __( 'Price', 'tutor-course-bundles' );
				$new['tcb_learners'] = __( 'Learners', 'tutor-course-bundles' );
			}

			$new[ $key ] = $label;
		}

		if ( ! isset( $new['tcb_courses'] ) ) {
			$new['tcb_courses']  = __( 'Courses', 'tutor-course-bundles' );
			$new['tcb_price']    = __( 'Price', 'tutor-course-bundles' );
			$new['tcb_learners'] = __( 'Learners', 'tutor-course-bundles' );
		}

		return $new;
	}

	/**
	 * Render a custom column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Bundle post ID.
	 */
	public function render_column( $column, $post_id ): void {
		$post_id = (int) $post_id;
		$bundle  = $this->bundles->find( $post_id );

		if ( ! $bundle instanceof Bundle ) {
			return;
		}

		switch ( $column ) {
			case 'tcb_courses':
				$all       = $this->bundles->get_courses( $post_id );
				$available = $this->bundles->get_courses( $post_id, true );
				$missing   = count( $all ) - count( $available );

				echo esc_html( (string) count( $all ) );

				if ( $missing > 0 ) {
					echo ' <span class="tcb-status tcb-status--pending">';
					printf(
						/* translators: %d: number of unavailable courses */
						esc_html__( '%d unavailable', 'tutor-course-bundles' ),
						(int) $missing
					);
					echo '</span>';
				}
				break;

			case 'tcb_price':
				if ( $bundle->is_free() ) {
					esc_html_e( 'Free', 'tutor-course-bundles' );
					break;
				}

				$sale = $bundle->get_sale_price();

				if ( null !== $sale ) {
					echo '<del>' . wp_kses_post( tcb_format_price( $bundle->get_price() ) ) . '</del> ';
					echo wp_kses_post( tcb_format_price( $sale ) );
				} else {
					echo wp_kses_post( tcb_format_price( $bundle->get_price() ) );
				}
				break;

			case 'tcb_learners':
				$count = tcb()->get( AccessRepository::class )->count_active_learners( $post_id );

				printf(
					'<a href="%s">%d</a>',
					esc_url(
						add_query_arg(
							array(
								'page'      => Menu::PAGE_ENROLL,
								'bundle_id' => $post_id,
							),
							admin_url( 'admin.php' )
						)
					),
					(int) $count
				);
				break;
		}
	}

	/**
	 * Mark the course-count column sortable.
	 *
	 * @param array<string, string> $columns Sortable columns.
	 * @return array<string, string>
	 */
	public function sortable_columns( $columns ) {
		$columns['tcb_courses'] = 'tcb_courses';
		$columns['tcb_price']   = 'tcb_price';

		return $columns;
	}

	/**
	 * Translate the sortable columns into meta queries.
	 *
	 * @param \WP_Query $query Current query.
	 */
	public function handle_sorting( $query ): void {
		if ( ! is_admin() || ! $query instanceof \WP_Query || ! $query->is_main_query() ) {
			return;
		}

		if ( PostTypes::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		$orderby = $query->get( 'orderby' );

		if ( 'tcb_courses' === $orderby ) {
			$query->set( 'meta_key', Bundle::META_COURSE_COUNT ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$query->set( 'orderby', 'meta_value_num' );
		}

		if ( 'tcb_price' === $orderby ) {
			$query->set( 'meta_key', Bundle::META_PRICE ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$query->set( 'orderby', 'meta_value_num' );
		}
	}
}
