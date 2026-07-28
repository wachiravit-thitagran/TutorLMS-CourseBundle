<?php
/**
 * Aggregated progress across every course in a bundle.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Value object produced by ProgressController.
 */
final class BundleProgress {

	public const STATUS_NOT_STARTED = 'not_started';
	public const STATUS_IN_PROGRESS = 'in_progress';
	public const STATUS_COMPLETED   = 'completed';
	public const STATUS_EXPIRED     = 'expired';
	public const STATUS_REVOKED     = 'revoked';

	/**
	 * Constructor.
	 *
	 * @param int                              $bundle_id        Bundle post ID.
	 * @param int                              $user_id          User ID.
	 * @param float                            $percent          0–100 overall completion.
	 * @param int                              $completed_count  Courses finished.
	 * @param int                              $total_count      Courses counted toward completion.
	 * @param string                           $status           One of the STATUS_* constants.
	 * @param array<int, array<string, mixed>> $courses          Per-course breakdown.
	 * @param int|null                         $next_course_id   Suggested next course.
	 */
	public function __construct(
		public readonly int $bundle_id,
		public readonly int $user_id,
		public readonly float $percent = 0.0,
		public readonly int $completed_count = 0,
		public readonly int $total_count = 0,
		public readonly string $status = self::STATUS_NOT_STARTED,
		public readonly array $courses = array(),
		public readonly ?int $next_course_id = null
	) {}

	/**
	 * Whether the bundle is finished.
	 */
	public function is_complete(): bool {
		return self::STATUS_COMPLETED === $this->status;
	}

	/**
	 * Percent rounded for display.
	 */
	public function get_display_percent(): int {
		return (int) round( $this->percent );
	}

	/**
	 * Human-readable status label.
	 */
	public function get_status_label(): string {
		$labels = array(
			self::STATUS_NOT_STARTED => __( 'Not started', 'tutor-course-bundles' ),
			self::STATUS_IN_PROGRESS => __( 'In progress', 'tutor-course-bundles' ),
			self::STATUS_COMPLETED   => __( 'Completed', 'tutor-course-bundles' ),
			self::STATUS_EXPIRED     => __( 'Expired', 'tutor-course-bundles' ),
			self::STATUS_REVOKED     => __( 'Revoked', 'tutor-course-bundles' ),
		);

		return $labels[ $this->status ] ?? $this->status;
	}

	/**
	 * Array form for REST and templates.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'bundle_id'       => $this->bundle_id,
			'user_id'         => $this->user_id,
			'percent'         => round( $this->percent, 2 ),
			'display_percent' => $this->get_display_percent(),
			'completed_count' => $this->completed_count,
			'total_count'     => $this->total_count,
			'status'          => $this->status,
			'status_label'    => $this->get_status_label(),
			'next_course_id'  => $this->next_course_id,
			'courses'         => $this->courses,
		);
	}
}
