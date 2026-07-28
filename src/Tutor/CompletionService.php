<?php
/**
 * Detects bundle completion from Tutor course activity.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tutor;

use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Infrastructure\AccessRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\BundleRepository;
use SpaceWork\TutorCourseBundles\Support\Cache;
use SpaceWork\TutorCourseBundles\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to Tutor's progress hooks, invalidates cached progress, and fires
 * `tcb/bundle/completed` exactly once per learner per bundle.
 */
final class CompletionService {

	private const META_COMPLETED_PREFIX = '_tcb_bundle_completed_';

	/**
	 * Constructor.
	 *
	 * @param BundleRepository $bundles Bundle repository.
	 * @param AccessRepository $access  Entitlement repository.
	 * @param TutorAdapter     $tutor   Tutor bridge.
	 */
	public function __construct(
		private readonly BundleRepository $bundles,
		private readonly AccessRepository $access,
		private readonly TutorAdapter $tutor
	) {}

	/**
	 * Hook into Tutor's public progress actions.
	 */
	public function register_hooks(): void {
		add_action( 'tutor_course_complete_after', array( $this, 'on_course_completed' ), 20, 2 );
		add_action( 'tutor_lesson_completed_after', array( $this, 'on_progress_changed' ), 20, 2 );
		add_action( 'tutor_quiz/attempt_ended', array( $this, 'on_progress_changed' ), 20 );
		add_action( 'tutor_assignment/after/submitted', array( $this, 'on_progress_changed' ), 20 );
	}

	/**
	 * Cheap handler for any progress change: just drop cached percentages.
	 *
	 * @param mixed $context  Whatever the Tutor hook passes first.
	 * @param mixed $user_id  User ID when the hook provides one.
	 */
	public function on_progress_changed( $context = null, $user_id = null ): void {
		$user_id = (int) ( $user_id ? $user_id : get_current_user_id() );

		if ( $user_id > 0 ) {
			Cache::flush_user_progress( $user_id );
		}
	}

	/**
	 * A course finished — re-evaluate every bundle that contains it.
	 *
	 * @param int      $course_id Course post ID.
	 * @param int|null $user_id   User ID; falls back to the current user.
	 */
	public function on_course_completed( $course_id, $user_id = null ): void {
		$course_id = (int) $course_id;
		$user_id   = (int) ( $user_id ? $user_id : get_current_user_id() );

		if ( $course_id <= 0 || $user_id <= 0 ) {
			return;
		}

		Cache::flush_user_progress( $user_id );

		foreach ( $this->bundles->get_bundles_containing_course( $course_id ) as $bundle_id ) {
			$this->evaluate_bundle( $bundle_id, $user_id );
		}
	}

	/**
	 * Check whether a learner has now finished a bundle.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @param int $user_id   User ID.
	 */
	public function evaluate_bundle( int $bundle_id, int $user_id ): bool {
		if ( $this->is_marked_complete( $bundle_id, $user_id ) ) {
			return true;
		}

		if ( ! $this->access->user_has_access( $bundle_id, $user_id ) ) {
			return false;
		}

		$bundle = $this->bundles->find( $bundle_id );

		if ( ! $bundle instanceof Bundle ) {
			return false;
		}

		$courses = Bundle::COMPLETION_ALL_COURSES === $bundle->get_completion_mode()
			? $this->bundles->get_courses( $bundle_id, true )
			: $this->bundles->get_required_courses( $bundle_id );

		if ( array() === $courses ) {
			return false;
		}

		foreach ( $courses as $course ) {
			if ( ! $this->tutor->is_course_completed( $course->course_id, $user_id ) ) {
				return false;
			}
		}

		$this->mark_complete( $bundle_id, $user_id );

		return true;
	}

	/**
	 * Whether completion has already been recorded.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @param int $user_id   User ID.
	 */
	public function is_marked_complete( int $bundle_id, int $user_id ): bool {
		return '' !== (string) get_user_meta( $user_id, self::META_COMPLETED_PREFIX . $bundle_id, true );
	}

	/**
	 * Completion timestamp, or null.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @param int $user_id   User ID.
	 */
	public function get_completed_at( int $bundle_id, int $user_id ): ?string {
		$value = (string) get_user_meta( $user_id, self::META_COMPLETED_PREFIX . $bundle_id, true );

		return '' === $value ? null : $value;
	}

	/**
	 * Record completion and fire the public hook once.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @param int $user_id   User ID.
	 */
	public function mark_complete( int $bundle_id, int $user_id ): void {
		$now = current_time( 'mysql', true );

		update_user_meta( $user_id, self::META_COMPLETED_PREFIX . $bundle_id, $now );

		Logger::audit(
			Logger::ACTION_BUNDLE_COMPLETED,
			array( 'completed_at' => $now ),
			$bundle_id,
			$user_id
		);

		/**
		 * Fires the first time a learner completes a bundle.
		 *
		 * Certificate and automation add-ons should hook here.
		 *
		 * @param int $user_id   User ID.
		 * @param int $bundle_id Bundle post ID.
		 */
		do_action( 'tcb/bundle/completed', $user_id, $bundle_id );
	}

	/**
	 * Clear a recorded completion, e.g. after courses are added to the bundle.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @param int $user_id   User ID.
	 */
	public function reset_completion( int $bundle_id, int $user_id ): void {
		delete_user_meta( $user_id, self::META_COMPLETED_PREFIX . $bundle_id );
	}
}
