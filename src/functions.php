<?php
/**
 * Public template helper functions.
 *
 * These are intentionally global: theme authors work in templates, not in
 * namespaced service classes.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Domain\BundleProgress;
use SpaceWork\TutorCourseBundles\Frontend\AccessController;
use SpaceWork\TutorCourseBundles\Frontend\ProgressController;
use SpaceWork\TutorCourseBundles\Infrastructure\BundleRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\PostTypes;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tcb_format_price' ) ) {
	/**
	 * Format an amount using WooCommerce when available, WordPress otherwise.
	 *
	 * @param float $amount Amount.
	 */
	function tcb_format_price( float $amount ): string {
		if ( function_exists( 'wc_price' ) ) {
			return wc_price( $amount );
		}

		$symbol = (string) apply_filters( 'tcb/price/currency_symbol', get_option( 'tcb_currency_symbol', '$' ) );

		return esc_html( $symbol . number_format_i18n( $amount, 2 ) );
	}
}

if ( ! function_exists( 'tcb_get_bundle' ) ) {
	/**
	 * Fetch a bundle aggregate.
	 *
	 * @param int|WP_Post|null $bundle Bundle ID or post; defaults to the current post.
	 */
	function tcb_get_bundle( $bundle = null ): ?Bundle {
		$bundle = $bundle ?? get_the_ID();

		return Bundle::from( $bundle );
	}
}

if ( ! function_exists( 'tcb_is_bundle' ) ) {
	/**
	 * Whether a post is a bundle.
	 *
	 * @param int|WP_Post|null $post Post.
	 */
	function tcb_is_bundle( $post = null ): bool {
		return PostTypes::POST_TYPE === get_post_type( $post ?? get_the_ID() );
	}
}

if ( ! function_exists( 'tcb_get_bundle_courses' ) ) {
	/**
	 * Course membership rows for a bundle.
	 *
	 * @param int  $bundle_id      Bundle post ID.
	 * @param bool $only_available Drop unpublished or deleted courses.
	 * @return \SpaceWork\TutorCourseBundles\Domain\BundleCourse[]
	 */
	function tcb_get_bundle_courses( int $bundle_id, bool $only_available = true ): array {
		return tcb()->get( BundleRepository::class )->get_courses( $bundle_id, $only_available );
	}
}

if ( ! function_exists( 'tcb_user_has_bundle' ) ) {
	/**
	 * Whether a learner currently holds a bundle.
	 *
	 * @param int      $bundle_id Bundle post ID.
	 * @param int|null $user_id   User ID, defaults to current user.
	 */
	function tcb_user_has_bundle( int $bundle_id, ?int $user_id = null ): bool {
		return tcb()->get( AccessController::class )->user_has_access( $bundle_id, $user_id );
	}
}

if ( ! function_exists( 'tcb_get_bundle_progress' ) ) {
	/**
	 * Aggregated progress for a learner.
	 *
	 * @param int      $bundle_id Bundle post ID.
	 * @param int|null $user_id   User ID, defaults to current user.
	 */
	function tcb_get_bundle_progress( int $bundle_id, ?int $user_id = null ): BundleProgress {
		$user_id = $user_id ?? get_current_user_id();

		return tcb()->get( ProgressController::class )->get_progress( $bundle_id, $user_id );
	}
}

if ( ! function_exists( 'tcb_get_bundle_stats' ) ) {
	/**
	 * Course, lesson and instructor totals for a bundle.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @return array<string, mixed>
	 */
	function tcb_get_bundle_stats( int $bundle_id ): array {
		return tcb()->get( BundleRepository::class )->get_stats( $bundle_id );
	}
}

if ( ! function_exists( 'tcb_get_template' ) ) {
	/**
	 * Render a plugin template, honouring theme overrides.
	 *
	 * @param string               $template Template name without extension.
	 * @param array<string, mixed> $args     Variables.
	 */
	function tcb_get_template( string $template, array $args = array() ): void {
		tcb()->get( \SpaceWork\TutorCourseBundles\Frontend\TemplateLoader::class )->render( $template, $args );
	}
}
