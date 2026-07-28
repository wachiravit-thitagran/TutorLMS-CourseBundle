<?php
/**
 * Aggregated bundle progress.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Frontend;

use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Domain\BundleCourse;
use SpaceWork\TutorCourseBundles\Domain\BundleProgress;
use SpaceWork\TutorCourseBundles\Infrastructure\BundleRepository;
use SpaceWork\TutorCourseBundles\Support\Cache;
use SpaceWork\TutorCourseBundles\Tutor\TutorAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Turns per-course Tutor progress into a single bundle-level number.
 *
 * Two strategies ship: a plain average across courses (default, matches what
 * learners expect from a checklist) and a content-weighted variant for bundles
 * where course sizes differ a lot.
 */
final class ProgressController {

	public const MODE_AVERAGE  = 'average';
	public const MODE_WEIGHTED = 'weighted';

	/**
	 * Constructor.
	 *
	 * @param BundleRepository $bundles Bundle repository.
	 * @param TutorAdapter     $tutor   Tutor bridge.
	 */
	public function __construct(
		private readonly BundleRepository $bundles,
		private readonly TutorAdapter $tutor
	) {}

	/**
	 * No frontend hooks of its own; kept for a consistent bootstrap surface.
	 */
	public function register_hooks(): void {
		add_action( 'tcb/course/enrolled', array( $this, 'flush_on_enrollment' ), 10, 3 );
	}

	/**
	 * Drop cached progress after an enrollment change.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course post ID.
	 * @param int $bundle_id Bundle post ID.
	 */
	public function flush_on_enrollment( $user_id, $course_id, $bundle_id ): void {
		Cache::flush_user_progress( (int) $user_id, (int) $bundle_id );
	}

	/**
	 * Compute a learner's progress through a bundle.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @param int $user_id   User ID.
	 */
	public function get_progress( int $bundle_id, int $user_id ): BundleProgress {
		if ( $user_id <= 0 ) {
			return new BundleProgress( $bundle_id, 0 );
		}

		$cache_key = "progress_{$bundle_id}_{$user_id}";
		$cached    = Cache::get( $cache_key, Cache::GROUP_PROGRESS );

		if ( is_array( $cached ) ) {
			return $this->hydrate( $cached );
		}

		$bundle = $this->bundles->find( $bundle_id );

		if ( ! $bundle instanceof Bundle ) {
			return new BundleProgress( $bundle_id, $user_id );
		}

		$all_courses = $this->bundles->get_courses( $bundle_id, true );

		if ( array() === $all_courses ) {
			return new BundleProgress( $bundle_id, $user_id );
		}

		$counted = Bundle::COMPLETION_ALL_COURSES === $bundle->get_completion_mode()
			? $all_courses
			: array_values( array_filter( $all_courses, static fn( BundleCourse $c ): bool => $c->is_required ) );

		if ( array() === $counted ) {
			$counted = $all_courses;
		}

		$mode = (string) apply_filters( 'tcb/progress/mode', get_option( 'tcb_progress_mode', self::MODE_AVERAGE ), $bundle_id );

		$breakdown       = array();
		$completed_count = 0;
		$next_course_id  = null;
		$sum_percent     = 0.0;
		$weighted_done   = 0.0;
		$weighted_total  = 0.0;

		foreach ( $all_courses as $course ) {
			$percent   = $this->tutor->get_course_progress( $course->course_id, $user_id );
			$completed = $this->tutor->is_course_completed( $course->course_id, $user_id );
			$enrolled  = $this->tutor->is_enrolled( $course->course_id, $user_id );
			$counts    = $this->tutor->get_course_content_counts( $course->course_id );
			$weight    = max( 1, $counts['lessons'] + $counts['quizzes'] + $counts['assignments'] );

			$is_counted = in_array( $course, $counted, true );

			if ( $is_counted ) {
				$sum_percent    += $percent;
				$weighted_done  += ( $percent / 100 ) * $weight;
				$weighted_total += $weight;

				if ( $completed ) {
					++$completed_count;
				}
			}

			if ( null === $next_course_id && ! $completed && $enrolled ) {
				$next_course_id = $course->course_id;
			}

			$breakdown[] = array(
				'course_id'    => $course->course_id,
				'title'        => $course->get_title(),
				'permalink'    => (string) get_permalink( $course->course_id ),
				'continue_url' => $this->tutor->get_continue_url( $course->course_id, $user_id ),
				'thumbnail'    => (string) get_the_post_thumbnail_url( $course->course_id, 'medium' ),
				'percent'      => round( $percent, 2 ),
				'completed'    => $completed,
				'enrolled'     => $enrolled,
				'required'     => $course->is_required,
				'position'     => $course->position,
			);
		}

		$total = count( $counted );

		if ( self::MODE_WEIGHTED === $mode && $weighted_total > 0 ) {
			$percent = ( $weighted_done / $weighted_total ) * 100;
		} else {
			$percent = $total > 0 ? $sum_percent / $total : 0.0;
		}

		$status = $this->resolve_status( $percent, $completed_count, $total );

		if ( null === $next_course_id && array() !== $all_courses && BundleProgress::STATUS_COMPLETED !== $status ) {
			$next_course_id = $all_courses[0]->course_id;
		}

		$progress = new BundleProgress(
			$bundle_id,
			$user_id,
			max( 0.0, min( 100.0, $percent ) ),
			$completed_count,
			$total,
			$status,
			$breakdown,
			$next_course_id
		);

		/**
		 * Filter a computed bundle progress object.
		 *
		 * @param BundleProgress $progress  Progress value object.
		 * @param int            $bundle_id Bundle post ID.
		 * @param int            $user_id   User ID.
		 */
		$progress = apply_filters( 'tcb/bundle/progress', $progress, $bundle_id, $user_id );

		Cache::set( $cache_key, $progress->to_array(), Cache::GROUP_PROGRESS, 120 );

		return $progress;
	}

	/**
	 * Decide the overall status from the numbers.
	 *
	 * @param float $percent         Overall percentage.
	 * @param int   $completed_count Courses finished.
	 * @param int   $total           Courses counted.
	 */
	private function resolve_status( float $percent, int $completed_count, int $total ): string {
		if ( $total > 0 && $completed_count >= $total ) {
			return BundleProgress::STATUS_COMPLETED;
		}

		if ( $percent > 0 ) {
			return BundleProgress::STATUS_IN_PROGRESS;
		}

		return BundleProgress::STATUS_NOT_STARTED;
	}

	/**
	 * Rebuild a progress object from its cached array form.
	 *
	 * @param array<string, mixed> $data Cached data.
	 */
	private function hydrate( array $data ): BundleProgress {
		return new BundleProgress(
			(int) ( $data['bundle_id'] ?? 0 ),
			(int) ( $data['user_id'] ?? 0 ),
			(float) ( $data['percent'] ?? 0 ),
			(int) ( $data['completed_count'] ?? 0 ),
			(int) ( $data['total_count'] ?? 0 ),
			(string) ( $data['status'] ?? BundleProgress::STATUS_NOT_STARTED ),
			(array) ( $data['courses'] ?? array() ),
			isset( $data['next_course_id'] ) && $data['next_course_id'] ? (int) $data['next_course_id'] : null
		);
	}
}
