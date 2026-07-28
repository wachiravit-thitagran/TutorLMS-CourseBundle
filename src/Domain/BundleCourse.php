<?php
/**
 * Bundle → course membership row.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable representation of one row in wp_tcb_bundle_courses.
 */
final class BundleCourse {

	public const UNLOCK_IMMEDIATE    = 'immediate';
	public const UNLOCK_SEQUENTIAL   = 'sequential';
	public const UNLOCK_AFTER_COURSE = 'after_course';
	public const UNLOCK_AFTER_DAYS   = 'after_days';

	/**
	 * Constructor.
	 *
	 * @param int      $id                         Row ID.
	 * @param int      $bundle_id                  Bundle post ID.
	 * @param int      $course_id                  Course post ID.
	 * @param int      $position                   Sort order, zero-based.
	 * @param bool     $is_required                Whether the course counts toward completion.
	 * @param string   $unlock_mode                One of the UNLOCK_* constants.
	 * @param int|null $unlock_reference_course_id Prerequisite course for after_course mode.
	 * @param int      $unlock_delay_days          Delay for after_days mode.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $bundle_id,
		public readonly int $course_id,
		public readonly int $position = 0,
		public readonly bool $is_required = true,
		public readonly string $unlock_mode = self::UNLOCK_IMMEDIATE,
		public readonly ?int $unlock_reference_course_id = null,
		public readonly int $unlock_delay_days = 0
	) {}

	/**
	 * Hydrate from a database row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['bundle_id'] ?? 0 ),
			(int) ( $row['course_id'] ?? 0 ),
			(int) ( $row['position'] ?? 0 ),
			(bool) ( $row['is_required'] ?? true ),
			(string) ( $row['unlock_mode'] ?? self::UNLOCK_IMMEDIATE ),
			isset( $row['unlock_reference_course_id'] ) && $row['unlock_reference_course_id'] ? (int) $row['unlock_reference_course_id'] : null,
			(int) ( $row['unlock_delay_days'] ?? 0 )
		);
	}

	/**
	 * The underlying course post, or null when it has been deleted.
	 */
	public function get_post(): ?\WP_Post {
		$post = get_post( $this->course_id );

		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * Whether the referenced course still exists.
	 */
	public function exists(): bool {
		return null !== $this->get_post();
	}

	/**
	 * Whether the course is published and therefore visible to learners.
	 */
	public function is_available(): bool {
		$post = $this->get_post();

		return $post instanceof \WP_Post && 'publish' === $post->post_status;
	}

	/**
	 * Course title, with a placeholder for missing courses.
	 */
	public function get_title(): string {
		$post = $this->get_post();

		if ( ! $post instanceof \WP_Post ) {
			return sprintf(
				/* translators: %d: course ID */
				__( '[Missing course #%d]', 'tutor-course-bundles' ),
				$this->course_id
			);
		}

		return get_the_title( $post );
	}

	/**
	 * Array form, suitable for REST responses and JS.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$post = $this->get_post();

		return array(
			'id'                         => $this->id,
			'bundle_id'                  => $this->bundle_id,
			'course_id'                  => $this->course_id,
			'position'                   => $this->position,
			'is_required'                => $this->is_required,
			'unlock_mode'                => $this->unlock_mode,
			'unlock_reference_course_id' => $this->unlock_reference_course_id,
			'unlock_delay_days'          => $this->unlock_delay_days,
			'title'                      => $this->get_title(),
			'permalink'                  => $post ? get_permalink( $post ) : '',
			'thumbnail'                  => $post ? get_the_post_thumbnail_url( $post, 'medium' ) : '',
			'status'                     => $post ? $post->post_status : 'missing',
			'exists'                     => null !== $post,
			'available'                  => $this->is_available(),
		);
	}
}
