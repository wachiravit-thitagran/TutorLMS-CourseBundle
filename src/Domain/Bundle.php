<?php
/**
 * Bundle aggregate: the CPT post plus its meta.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Domain;

use SpaceWork\TutorCourseBundles\Infrastructure\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Read model wrapping a `tcb_bundle` post.
 *
 * Content (title, description, image, taxonomy) is WordPress's job. Everything
 * commercial or structural is meta, and the course list lives in a custom table
 * behind BundleRepository.
 */
final class Bundle {

	public const META_PRICE              = '_tcb_bundle_price';
	public const META_SALE_PRICE         = '_tcb_bundle_sale_price';
	public const META_ACCESS_TYPE        = '_tcb_bundle_access_type';
	public const META_ENROLLMENT_MODE    = '_tcb_bundle_enrollment_mode';
	public const META_COMPLETION_MODE    = '_tcb_bundle_completion_mode';
	public const META_COURSE_COUNT       = '_tcb_bundle_course_count';
	public const META_ESTIMATED_DURATION = '_tcb_bundle_estimated_duration';
	public const META_WC_PRODUCT_ID      = '_tcb_bundle_wc_product_id';
	public const META_VERSION            = '_tcb_bundle_version';
	public const META_DIFFICULTY         = '_tcb_bundle_difficulty';
	public const META_SHORT_DESCRIPTION  = '_tcb_bundle_short_description';
	public const META_ACCESS_DURATION    = '_tcb_bundle_access_duration_days';
	public const META_GRANT_NEW_COURSES  = '_tcb_bundle_grant_new_courses';

	public const ACCESS_TYPE_FREE = 'free';
	public const ACCESS_TYPE_PAID = 'paid';

	public const ENROLLMENT_IMMEDIATE  = 'immediate';
	public const ENROLLMENT_SEQUENTIAL = 'sequential';

	public const COMPLETION_ALL_REQUIRED = 'all_required';
	public const COMPLETION_ALL_COURSES  = 'all_courses';

	/**
	 * Constructor.
	 *
	 * @param \WP_Post $post Underlying post object.
	 */
	private function __construct( public readonly \WP_Post $post ) {}

	/**
	 * Build from a post ID or object; returns null when it is not a bundle.
	 *
	 * @param int|\WP_Post $post Post ID or object.
	 */
	public static function from( $post ): ?self {
		$post = get_post( $post );

		if ( ! $post instanceof \WP_Post || PostTypes::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return new self( $post );
	}

	/**
	 * Bundle post ID.
	 */
	public function get_id(): int {
		return (int) $this->post->ID;
	}

	/**
	 * Bundle title.
	 */
	public function get_title(): string {
		return get_the_title( $this->post );
	}

	/**
	 * Permalink.
	 */
	public function get_permalink(): string {
		return (string) get_permalink( $this->post );
	}

	/**
	 * Full description (post content, filtered).
	 */
	public function get_description(): string {
		return apply_filters( 'the_content', $this->post->post_content );
	}

	/**
	 * Short description used in cards and archives.
	 */
	public function get_short_description(): string {
		$short = (string) get_post_meta( $this->get_id(), self::META_SHORT_DESCRIPTION, true );

		if ( '' !== $short ) {
			return $short;
		}

		return wp_trim_words( wp_strip_all_tags( $this->post->post_content ), 30 );
	}

	/**
	 * Featured image URL.
	 *
	 * @param string $size Image size.
	 */
	public function get_thumbnail_url( string $size = 'large' ): string {
		return (string) get_the_post_thumbnail_url( $this->post, $size );
	}

	/**
	 * Raw meta accessor with a default.
	 *
	 * @param string $key     Meta key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public function meta( string $key, $fallback = '' ) {
		$value = get_post_meta( $this->get_id(), $key, true );

		return ( '' === $value || null === $value ) ? $fallback : $value;
	}

	/**
	 * Regular price.
	 */
	public function get_price(): float {
		return (float) $this->meta( self::META_PRICE, 0 );
	}

	/**
	 * Sale price, or null when not on sale.
	 */
	public function get_sale_price(): ?float {
		$sale = $this->meta( self::META_SALE_PRICE, '' );

		if ( '' === $sale ) {
			return null;
		}

		$sale = (float) $sale;

		return $sale > 0 && $sale < $this->get_price() ? $sale : null;
	}

	/**
	 * The price a learner would actually pay.
	 */
	public function get_effective_price(): float {
		$price = $this->get_sale_price() ?? $this->get_price();

		/**
		 * Filter the effective bundle price.
		 *
		 * @param float $price     Effective price.
		 * @param int   $bundle_id Bundle post ID.
		 * @param int   $user_id   Current user ID.
		 */
		return (float) apply_filters( 'tcb/bundle/price', $price, $this->get_id(), get_current_user_id() );
	}

	/**
	 * Whether the bundle is free.
	 */
	public function is_free(): bool {
		if ( self::ACCESS_TYPE_FREE === $this->meta( self::META_ACCESS_TYPE, self::ACCESS_TYPE_FREE ) ) {
			return true;
		}

		return $this->get_effective_price() <= 0;
	}

	/**
	 * Access type: free or paid.
	 */
	public function get_access_type(): string {
		return (string) $this->meta( self::META_ACCESS_TYPE, self::ACCESS_TYPE_FREE );
	}

	/**
	 * Enrollment mode.
	 */
	public function get_enrollment_mode(): string {
		return (string) $this->meta( self::META_ENROLLMENT_MODE, self::ENROLLMENT_IMMEDIATE );
	}

	/**
	 * Completion mode.
	 */
	public function get_completion_mode(): string {
		return (string) $this->meta( self::META_COMPLETION_MODE, self::COMPLETION_ALL_REQUIRED );
	}

	/**
	 * Cached course count.
	 */
	public function get_course_count(): int {
		return (int) $this->meta( self::META_COURSE_COUNT, 0 );
	}

	/**
	 * Estimated duration, free-form text such as "12 hours".
	 */
	public function get_estimated_duration(): string {
		return (string) $this->meta( self::META_ESTIMATED_DURATION, '' );
	}

	/**
	 * Difficulty level slug.
	 */
	public function get_difficulty(): string {
		return (string) $this->meta( self::META_DIFFICULTY, 'all_levels' );
	}

	/**
	 * Linked WooCommerce product ID, 0 when unlinked.
	 */
	public function get_wc_product_id(): int {
		return (int) $this->meta( self::META_WC_PRODUCT_ID, 0 );
	}

	/**
	 * Access duration in days after purchase; 0 means lifetime.
	 */
	public function get_access_duration_days(): int {
		return (int) $this->meta( self::META_ACCESS_DURATION, 0 );
	}

	/**
	 * Whether courses added later are granted to existing learners.
	 */
	public function grants_new_courses(): bool {
		$value = $this->meta( self::META_GRANT_NEW_COURSES, 'yes' );

		return 'yes' === $value;
	}

	/**
	 * Whether the bundle is published and therefore purchasable.
	 */
	public function is_purchasable(): bool {
		return 'publish' === $this->post->post_status;
	}

	/**
	 * Author (bundle owner) ID.
	 */
	public function get_author_id(): int {
		return (int) $this->post->post_author;
	}

	/**
	 * Compute an expiry timestamp for a new entitlement, or null for lifetime.
	 *
	 * @param int|null $from Base timestamp, defaults to now.
	 */
	public function calculate_expiry( ?int $from = null ): ?string {
		$days = $this->get_access_duration_days();

		if ( $days <= 0 ) {
			return null;
		}

		$from = $from ?? time();

		return gmdate( 'Y-m-d H:i:s', $from + ( $days * DAY_IN_SECONDS ) );
	}

	/**
	 * Array form for REST responses.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                 => $this->get_id(),
			'title'              => $this->get_title(),
			'slug'               => $this->post->post_name,
			'status'             => $this->post->post_status,
			'permalink'          => $this->get_permalink(),
			'short_description'  => $this->get_short_description(),
			'thumbnail'          => $this->get_thumbnail_url(),
			'price'              => $this->get_price(),
			'sale_price'         => $this->get_sale_price(),
			'effective_price'    => $this->get_effective_price(),
			'is_free'            => $this->is_free(),
			'access_type'        => $this->get_access_type(),
			'enrollment_mode'    => $this->get_enrollment_mode(),
			'completion_mode'    => $this->get_completion_mode(),
			'course_count'       => $this->get_course_count(),
			'estimated_duration' => $this->get_estimated_duration(),
			'difficulty'         => $this->get_difficulty(),
			'wc_product_id'      => $this->get_wc_product_id(),
			'author_id'          => $this->get_author_id(),
			'date_created'       => $this->post->post_date_gmt,
		);
	}
}
