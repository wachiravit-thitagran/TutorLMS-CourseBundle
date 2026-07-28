<?php
/**
 * Decides whether a course enrollment may safely be revoked.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tutor;

use SpaceWork\TutorCourseBundles\Infrastructure\AccessRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\EnrollmentRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The single most important safety rule in the plugin.
 *
 * A learner can arrive at the same course through several doors: two different
 * bundles, a direct course purchase, a manual grant from an admin. When one of
 * those doors closes, the others must keep working. Nothing revokes a Tutor
 * enrollment without asking this class first.
 */
final class CourseAccessResolver {

	/**
	 * Post meta written on the `tutor_enrolled` record when this plugin created it.
	 */
	public const ORIGIN_META = '_tcb_origin_access_id';

	/**
	 * Constructor.
	 *
	 * @param EnrollmentRepository $enrollments Mapping repository.
	 * @param AccessRepository     $access      Entitlement repository.
	 * @param TutorAdapter         $tutor       Tutor bridge.
	 */
	public function __construct(
		private readonly EnrollmentRepository $enrollments,
		private readonly AccessRepository $access,
		private readonly TutorAdapter $tutor
	) {}

	/**
	 * Whether the learner still holds this course through some other route.
	 *
	 * @param int $user_id             User ID.
	 * @param int $course_id           Course post ID.
	 * @param int $excluding_access_id Entitlement being wound down.
	 */
	public function user_has_alternative_access( int $user_id, int $course_id, int $excluding_access_id ): bool {
		// 1. Another live bundle entitlement covers the same course.
		if ( $this->enrollments->count_other_active_grants( $user_id, $course_id, $excluding_access_id ) > 0 ) {
			return true;
		}

		// 2. The Tutor enrollment was not created by this plugin — a direct
		// purchase, an admin grant, a membership, an import. Never touch it.
		$enrollment_id = $this->tutor->get_enrollment_id( $course_id, $user_id );

		if ( $enrollment_id > 0 ) {
			$origin = get_post_meta( $enrollment_id, self::ORIGIN_META, true );

			if ( '' === $origin || null === $origin ) {
				return true;
			}
		}

		/**
		 * Filter the alternative-access verdict.
		 *
		 * Membership and subscription add-ons should hook here to protect the
		 * enrollments they own.
		 *
		 * @param bool $has_access          Whether another source grants the course.
		 * @param int  $user_id             User ID.
		 * @param int  $course_id           Course post ID.
		 * @param int  $excluding_access_id Entitlement being wound down.
		 */
		return (bool) apply_filters( 'tcb/access/has_alternative', false, $user_id, $course_id, $excluding_access_id );
	}

	/**
	 * Whether the Tutor enrollment itself may be cancelled.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course post ID.
	 * @param int $access_id Entitlement being wound down.
	 */
	public function can_revoke_course( int $user_id, int $course_id, int $access_id ): bool {
		if ( ! $this->tutor->is_enrolled( $course_id, $user_id ) ) {
			return false;
		}

		return ! $this->user_has_alternative_access( $user_id, $course_id, $access_id );
	}

	/**
	 * Mark a Tutor enrollment as created by a given entitlement.
	 *
	 * @param int $enrollment_id Tutor enrollment post ID.
	 * @param int $access_id     Entitlement row ID.
	 * @param int $bundle_id     Bundle post ID.
	 */
	public function tag_origin( int $enrollment_id, int $access_id, int $bundle_id ): void {
		if ( $enrollment_id <= 0 ) {
			return;
		}

		update_post_meta( $enrollment_id, self::ORIGIN_META, $access_id );
		update_post_meta( $enrollment_id, '_tcb_origin_bundle_id', $bundle_id );
	}

	/**
	 * Whether an enrollment record was created by this plugin.
	 *
	 * @param int $enrollment_id Tutor enrollment post ID.
	 */
	public function is_plugin_owned( int $enrollment_id ): bool {
		if ( $enrollment_id <= 0 ) {
			return false;
		}

		$origin = get_post_meta( $enrollment_id, self::ORIGIN_META, true );

		return '' !== $origin && null !== $origin;
	}

	/**
	 * Every bundle entitlement currently granting a course to a user.
	 *
	 * Useful for the admin "why does this learner have this course?" view.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course post ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function describe_sources( int $user_id, int $course_id ): array {
		$sources = array();

		foreach ( $this->access->find_for_user( $user_id ) as $access ) {
			if ( ! $access->is_usable() ) {
				continue;
			}

			$row = $this->enrollments->find( $access->id, $course_id );

			if ( null === $row || EnrollmentRepository::STATUS_ACTIVE !== $row['status'] ) {
				continue;
			}

			$sources[] = array(
				'access_id'    => $access->id,
				'bundle_id'    => $access->bundle_id,
				'bundle_title' => get_the_title( $access->bundle_id ),
				'source_type'  => $access->source_type,
				'source_id'    => $access->source_id,
				'granted_at'   => $access->granted_at,
			);
		}

		$enrollment_id = $this->tutor->get_enrollment_id( $course_id, $user_id );

		if ( $enrollment_id > 0 && ! $this->is_plugin_owned( $enrollment_id ) ) {
			$sources[] = array(
				'access_id'    => 0,
				'bundle_id'    => 0,
				'bundle_title' => '',
				'source_type'  => 'direct',
				'source_id'    => $enrollment_id,
				'granted_at'   => get_post_field( 'post_date_gmt', $enrollment_id ),
			);
		}

		return $sources;
	}
}
