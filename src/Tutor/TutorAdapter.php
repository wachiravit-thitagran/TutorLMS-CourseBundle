<?php
/**
 * Isolation layer over Tutor LMS Free.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tutor;

use SpaceWork\TutorCourseBundles\Compatibility;
use SpaceWork\TutorCourseBundles\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Every call into Tutor LMS goes through here.
 *
 * Two rules this class exists to enforce:
 *
 * 1. Nothing outside this file references a Tutor class, function or table
 *    directly, so a breaking change upstream is a one-file fix.
 * 2. Nothing here touches Tutor LMS Pro. We use `tutor_utils()` and the public
 *    `tutor_enrolled` post type only — both ship in the free plugin.
 */
final class TutorAdapter {

	public const ENROLLMENT_POST_TYPE = 'tutor_enrolled';

	/**
	 * Whether Tutor LMS is available at all.
	 */
	public function is_available(): bool {
		return Compatibility::tutor_active();
	}

	/**
	 * Tutor's course post type slug.
	 */
	public function course_post_type(): string {
		return Compatibility::course_post_type();
	}

	/**
	 * Whether a user is enrolled in a course.
	 *
	 * @param int $course_id Course post ID.
	 * @param int $user_id   User ID.
	 */
	public function is_enrolled( int $course_id, int $user_id ): bool {
		if ( $course_id <= 0 || $user_id <= 0 ) {
			return false;
		}

		// Query the completed enrollment record directly. Tutor caches
		// is_enrolled() results but does not invalidate that cache when
		// cancel_course_enrol() changes the post status, which can leave revoked
		// learners looking enrolled for the rest of the request.
		return $this->get_enrollment_id( $course_id, $user_id ) > 0;
	}

	/**
	 * The `tutor_enrolled` post ID for a completed enrollment, or 0.
	 *
	 * @param int $course_id Course post ID.
	 * @param int $user_id   User ID.
	 */
	public function get_enrollment_id( int $course_id, int $user_id ): int {
		global $wpdb;

		if ( $course_id <= 0 || $user_id <= 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = %s AND post_parent = %d AND post_author = %d AND post_status = %s
				 ORDER BY ID DESC LIMIT 1",
				self::ENROLLMENT_POST_TYPE,
				$course_id,
				$user_id,
				'completed'
			)
		);

		return null === $id ? 0 : (int) $id;
	}

	/**
	 * Enroll a user in a course.
	 *
	 * Idempotent by design: an already-enrolled user short-circuits to the
	 * existing enrollment ID rather than creating a second record.
	 *
	 * @param int $course_id Course post ID.
	 * @param int $user_id   User ID.
	 * @param int $order_id  Optional gateway order ID for Tutor's own records.
	 * @return int|\WP_Error Enrollment post ID.
	 */
	public function enroll( int $course_id, int $user_id, int $order_id = 0 ) {
		if ( ! $this->is_available() ) {
			return new \WP_Error( 'tcb_tutor_missing', __( 'Tutor LMS is not available.', 'tutor-course-bundles' ) );
		}

		if ( $course_id <= 0 || $user_id <= 0 ) {
			return new \WP_Error( 'tcb_invalid_enrollment', __( 'Invalid course or user.', 'tutor-course-bundles' ) );
		}

		if ( get_post_type( $course_id ) !== $this->course_post_type() ) {
			return new \WP_Error( 'tcb_not_a_course', __( 'That post is not a Tutor LMS course.', 'tutor-course-bundles' ) );
		}

		$existing = $this->get_enrollment_id( $course_id, $user_id );

		if ( $existing > 0 ) {
			return $existing;
		}

		// Tutor gates do_enroll() on its own purchase checks in some flows;
		// these filters tell it the enrollment is already paid for and should
		// be written as completed rather than pending.
		add_filter( 'tutor_enroll_data', array( $this, 'force_completed_enrollment' ), 99 );
		add_filter( 'tutor_is_enrolled', '__return_false', 99 );

		$enrolled = false;

		try {
			if ( method_exists( tutor_utils(), 'do_enroll' ) ) {
				$enrolled = (bool) tutor_utils()->do_enroll( $course_id, $order_id, $user_id );
			} elseif ( class_exists( '\Tutor\Models\EnrollmentModel' ) && method_exists( '\Tutor\Models\EnrollmentModel', 'do_enroll' ) ) {
				$enrolled = (bool) \Tutor\Models\EnrollmentModel::do_enroll( $course_id, $order_id, $user_id );
			}
		} finally {
			remove_filter( 'tutor_enroll_data', array( $this, 'force_completed_enrollment' ), 99 );
			remove_filter( 'tutor_is_enrolled', '__return_false', 99 );
		}

		$enrollment_id = $this->get_enrollment_id( $course_id, $user_id );

		if ( $enrollment_id > 0 ) {
			return $enrollment_id;
		}

		if ( ! $enrolled ) {
			Logger::debug(
				'Tutor do_enroll() did not create an enrollment.',
				array(
					'course_id' => $course_id,
					'user_id'   => $user_id,
				)
			);
		}

		// Fall back to a pending enrollment left behind by Tutor and promote it.
		$pending = $this->find_enrollment_any_status( $course_id, $user_id );

		if ( $pending > 0 ) {
			$this->set_enrollment_status( $pending, 'completed' );

			return $pending;
		}

		return new \WP_Error(
			'tcb_enroll_failed',
			__( 'Tutor LMS did not create the enrollment record.', 'tutor-course-bundles' )
		);
	}

	/**
	 * Force Tutor's enrollment payload to a completed state.
	 *
	 * @param array<string, mixed> $data Enrollment post data.
	 * @return array<string, mixed>
	 */
	public function force_completed_enrollment( $data ) {
		if ( is_array( $data ) ) {
			$data['post_status'] = 'completed';
		}

		return $data;
	}

	/**
	 * Any enrollment record regardless of status.
	 *
	 * @param int $course_id Course post ID.
	 * @param int $user_id   User ID.
	 */
	private function find_enrollment_any_status( int $course_id, int $user_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = %s AND post_parent = %d AND post_author = %d
				 ORDER BY ID DESC LIMIT 1",
				self::ENROLLMENT_POST_TYPE,
				$course_id,
				$user_id
			)
		);

		return null === $id ? 0 : (int) $id;
	}

	/**
	 * Change an enrollment record's status.
	 *
	 * @param int    $enrollment_id Enrollment post ID.
	 * @param string $status        New status.
	 */
	public function set_enrollment_status( int $enrollment_id, string $status ): bool {
		if ( $enrollment_id <= 0 ) {
			return false;
		}

		if ( $this->is_available() && method_exists( tutor_utils(), 'course_enrol_status_change' ) ) {
			tutor_utils()->course_enrol_status_change( $enrollment_id, $status );
			clean_post_cache( $enrollment_id );

			return true;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->posts,
			array( 'post_status' => $status ),
			array( 'ID' => $enrollment_id ),
			array( '%s' ),
			array( '%d' )
		);

		clean_post_cache( $enrollment_id );

		return true;
	}

	/**
	 * Cancel a course enrollment.
	 *
	 * @param int    $course_id Course post ID.
	 * @param int    $user_id   User ID.
	 * @param string $status    Target status, Tutor uses "canceled".
	 */
	public function cancel_enrollment( int $course_id, int $user_id, string $status = 'canceled' ): bool {
		$enrollment_id = $this->get_enrollment_id( $course_id, $user_id );

		if ( $enrollment_id <= 0 ) {
			return false;
		}

		if ( $this->is_available() && method_exists( tutor_utils(), 'cancel_course_enrol' ) ) {
			tutor_utils()->cancel_course_enrol( $course_id, $user_id, $status );
		}

		// Tutor's own is_enrolled() lookup is request-cached. If that cache
		// contains a stale false value, cancel_course_enrol() silently skips
		// its update, so enforce the status on the enrollment ID we resolved.
		return $this->set_enrollment_status( $enrollment_id, $status );
	}

	/**
	 * Completion percentage for a course, 0–100.
	 *
	 * @param int $course_id Course post ID.
	 * @param int $user_id   User ID.
	 */
	public function get_course_progress( int $course_id, int $user_id ): float {
		if ( ! $this->is_available() || $course_id <= 0 || $user_id <= 0 ) {
			return 0.0;
		}

		if ( ! method_exists( tutor_utils(), 'get_course_completed_percent' ) ) {
			return 0.0;
		}

		$percent = tutor_utils()->get_course_completed_percent( $course_id, $user_id );

		if ( is_array( $percent ) ) {
			$percent = $percent['completed_percent'] ?? 0;
		}

		return max( 0.0, min( 100.0, (float) $percent ) );
	}

	/**
	 * Whether a course is finished.
	 *
	 * @param int $course_id Course post ID.
	 * @param int $user_id   User ID.
	 */
	public function is_course_completed( int $course_id, int $user_id ): bool {
		if ( ! $this->is_available() ) {
			return false;
		}

		if ( method_exists( tutor_utils(), 'is_completed_course' ) ) {
			$completed = tutor_utils()->is_completed_course( $course_id, $user_id );

			if ( false !== $completed && null !== $completed ) {
				return true;
			}
		}

		return $this->get_course_progress( $course_id, $user_id ) >= 100.0;
	}

	/**
	 * Content counts used for bundle stats.
	 *
	 * @param int $course_id Course post ID.
	 * @return array{lessons:int, quizzes:int, assignments:int, topics:int}
	 */
	public function get_course_content_counts( int $course_id ): array {
		$counts = array(
			'lessons'     => 0,
			'quizzes'     => 0,
			'assignments' => 0,
			'topics'      => 0,
		);

		if ( ! $this->is_available() || $course_id <= 0 ) {
			return $counts;
		}

		$utils = tutor_utils();

		if ( method_exists( $utils, 'get_lesson_count_by_course' ) ) {
			$counts['lessons'] = (int) $utils->get_lesson_count_by_course( $course_id );
		} elseif ( class_exists( '\Tutor\Models\LessonModel' ) ) {
			$lesson_model = new \Tutor\Models\LessonModel();

			if ( method_exists( $lesson_model, 'get_lesson_count_by_course' ) ) {
				$counts['lessons'] = (int) $lesson_model->get_lesson_count_by_course( $course_id );
			}
		}

		if ( method_exists( $utils, 'get_course_content_ids_by' ) ) {
			$quiz_type       = defined( 'TUTOR_QUIZ_POST_TYPE' ) ? TUTOR_QUIZ_POST_TYPE : 'tutor_quiz';
			$assignment_type = 'tutor_assignments';
			$course_type     = $this->course_post_type();

			$quizzes     = $utils->get_course_content_ids_by( $quiz_type, $course_type, $course_id );
			$assignments = $utils->get_course_content_ids_by( $assignment_type, $course_type, $course_id );

			$counts['quizzes']     = is_array( $quizzes ) ? count( $quizzes ) : 0;
			$counts['assignments'] = is_array( $assignments ) ? count( $assignments ) : 0;
		}

		if ( method_exists( $utils, 'get_topics' ) ) {
			$topics           = $utils->get_topics( $course_id );
			$counts['topics'] = ( is_object( $topics ) && isset( $topics->post_count ) ) ? (int) $topics->post_count : 0;
		}

		return $counts;
	}

	/**
	 * Instructors attached to a course.
	 *
	 * @param int $course_id Course post ID.
	 * @return array<int, array{id:int, name:string, avatar:string, url:string}>
	 */
	public function get_course_instructors( int $course_id ): array {
		$instructors = array();

		if ( $course_id <= 0 ) {
			return $instructors;
		}

		$raw = array();

		if ( $this->is_available() && method_exists( tutor_utils(), 'get_instructors_by_course' ) ) {
			$result = tutor_utils()->get_instructors_by_course( $course_id );
			$raw    = is_array( $result ) ? $result : array();
		}

		if ( array() === $raw ) {
			$author = (int) get_post_field( 'post_author', $course_id );

			if ( $author > 0 ) {
				$user = get_userdata( $author );

				if ( $user ) {
					$raw = array( $user );
				}
			}
		}

		foreach ( $raw as $user ) {
			$user_id = (int) ( $user->ID ?? 0 );

			if ( $user_id <= 0 ) {
				continue;
			}

			$instructors[] = array(
				'id'     => $user_id,
				'name'   => (string) ( $user->display_name ?? '' ),
				'avatar' => get_avatar_url( $user_id, array( 'size' => 96 ) ),
				'url'    => (string) get_author_posts_url( $user_id ),
			);
		}

		return $instructors;
	}

	/**
	 * Whether a user authored or co-teaches a course.
	 *
	 * @param int $course_id Course post ID.
	 * @param int $user_id   User ID.
	 */
	public function user_owns_course( int $course_id, int $user_id ): bool {
		if ( (int) get_post_field( 'post_author', $course_id ) === $user_id ) {
			return true;
		}

		foreach ( $this->get_course_instructors( $course_id ) as $instructor ) {
			if ( (int) $instructor['id'] === $user_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * URL of a Tutor dashboard sub-page.
	 *
	 * @param string $sub_url Dashboard endpoint, e.g. "my-bundles".
	 */
	public function dashboard_url( string $sub_url = '' ): string {
		if ( $this->is_available() && method_exists( tutor_utils(), 'tutor_dashboard_url' ) ) {
			return (string) tutor_utils()->tutor_dashboard_url( $sub_url );
		}

		return home_url( '/dashboard/' . ltrim( $sub_url, '/' ) );
	}

	/**
	 * URL a learner should land on to continue a course.
	 *
	 * @param int $course_id Course post ID.
	 * @param int $user_id   User ID.
	 */
	public function get_continue_url( int $course_id, int $user_id ): string {
		$permalink = (string) get_permalink( $course_id );

		if ( ! $this->is_available() || $user_id <= 0 ) {
			return $permalink;
		}

		if ( method_exists( tutor_utils(), 'get_course_first_lesson' ) ) {
			$lesson = tutor_utils()->get_course_first_lesson( $course_id );

			if ( is_object( $lesson ) && isset( $lesson->ID ) ) {
				return (string) get_permalink( (int) $lesson->ID );
			}
		}

		return $permalink;
	}
}
