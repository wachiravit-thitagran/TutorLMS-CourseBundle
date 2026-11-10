<?php
/**
 * Grants and revokes bundle entitlements, and keeps Tutor enrollments in sync.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tutor;

use SpaceWork\TutorCourseBundles\Compatibility;
use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Infrastructure\AccessRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\BundleRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\EnrollmentRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\LockManager;
use SpaceWork\TutorCourseBundles\Support\Cache;
use SpaceWork\TutorCourseBundles\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The engine.
 *
 * Everything here is written to be safe under repetition: WooCommerce fires
 * order hooks more than once, webhooks arrive concurrently, and admins click
 * buttons twice. Granting the same entitlement or enrolling the same course
 * twice must be a no-op, not a duplicate.
 */
final class EnrollmentService {

	public const HOOK_PROCESS_BATCH   = 'tcb/job/process_enrollment_batch';
	public const HOOK_EXPIRE_BATCH    = 'tcb/job/expire_access_batch';
	public const HOOK_ACTIVATE_ACCESS = 'tcb/job/activate_access';
	public const HOOK_EXPIRE_ACCESS   = 'tcb/cron/expire_access';
	public const HOOK_SYNC_NEW_COURSE = 'tcb/job/sync_new_course';
	public const HOOK_REMOVE_COURSE   = 'tcb/job/remove_course';
	public const HOOK_REVOKE_ACCESS   = 'tcb/job/revoke_access';

	private const DEFAULT_BATCH_SIZE = 20;
	private const ACCESS_BATCH_SIZE  = 200;

	/**
	 * Constructor.
	 *
	 * @param BundleRepository     $bundles     Bundle repository.
	 * @param AccessRepository     $access      Entitlement repository.
	 * @param EnrollmentRepository $enrollments Mapping repository.
	 * @param TutorAdapter         $tutor       Tutor bridge.
	 * @param CourseAccessResolver $resolver    Revocation guard.
	 * @param LockManager          $locks       Advisory locks.
	 */
	public function __construct(
		private readonly BundleRepository $bundles,
		private readonly AccessRepository $access,
		private readonly EnrollmentRepository $enrollments,
		private readonly TutorAdapter $tutor,
		private readonly CourseAccessResolver $resolver,
		private readonly LockManager $locks
	) {}

	/**
	 * Wire up background jobs and cross-cutting hooks.
	 */
	public function register_hooks(): void {
		add_action( self::HOOK_PROCESS_BATCH, array( $this, 'process_batch_job' ), 10, 2 );
		add_action( self::HOOK_SYNC_NEW_COURSE, array( $this, 'sync_new_course_job' ), 10, 3 );
		add_action( self::HOOK_REMOVE_COURSE, array( $this, 'remove_course_job' ), 10, 3 );
		add_action( self::HOOK_REVOKE_ACCESS, array( $this, 'revoke_access' ), 10, 3 );
		add_action( self::HOOK_EXPIRE_ACCESS, array( $this, 'expire_due_access' ) );
		add_action( self::HOOK_EXPIRE_BATCH, array( $this, 'expire_due_access' ), 10, 1 );
		add_action( self::HOOK_ACTIVATE_ACCESS, array( $this, 'activate_access_job' ), 10, 1 );
		add_filter( 'tutor_course_content_access', array( $this, 'filter_content_access' ), 100, 2 );
		add_action( 'template_redirect', array( $this, 'guard_content_request' ), 0 );
		foreach ( array( 'tutor_render_lesson_content', 'autoload_next_course_content' ) as $action ) {
			add_action( 'wp_ajax_' . $action, array( $this, 'guard_content_ajax' ), -1 );
		}

		add_action( 'tcb/bundle/course_added', array( $this, 'on_course_added_to_bundle' ), 10, 2 );
		add_action( 'tcb/bundle/course_removed', array( $this, 'on_course_removed_from_bundle' ), 10, 2 );

		if ( ! wp_next_scheduled( self::HOOK_EXPIRE_ACCESS ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK_EXPIRE_ACCESS );
		}
	}

	/**
	 * Grant a bundle to a learner and enroll them in its courses.
	 *
	 * @param int                  $bundle_id   Bundle post ID.
	 * @param int                  $user_id     User ID.
	 * @param string               $source_type One of BundleAccess::SOURCE_*.
	 * @param int                  $source_id   Source identifier (order ID, 0 for manual).
	 * @param array<string, mixed> $args        starts_at, expires_at, note, defer.
	 * @return BundleAccess|\WP_Error
	 */
	public function grant_access( int $bundle_id, int $user_id, string $source_type, int $source_id = 0, array $args = array() ) {
		$bundle = $this->bundles->find( $bundle_id );

		if ( ! $bundle instanceof Bundle ) {
			return new \WP_Error( 'tcb_bundle_not_found', __( 'Bundle not found.', 'tutor-course-bundles' ) );
		}

		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new \WP_Error( 'tcb_user_not_found', __( 'User not found.', 'tutor-course-bundles' ) );
		}

		$lock_key = 'learner_' . $user_id;

		$result = $this->locks->with_lock(
			$lock_key,
			function () use ( $bundle, $bundle_id, $user_id, $source_type, $source_id, $args ) {
				$expires_at = $args['expires_at'] ?? $bundle->calculate_expiry();

				$access = $this->access->create(
					array(
						'bundle_id'   => $bundle_id,
						'user_id'     => $user_id,
						'source_type' => $source_type,
						'source_id'   => $source_id,
						'status'      => BundleAccess::STATUS_ACTIVE,
						'granted_at'  => current_time( 'mysql', true ),
						'starts_at'   => $args['starts_at'] ?? null,
						'expires_at'  => $expires_at,
						'note'        => (string) ( $args['note'] ?? '' ),
					)
				);

				if ( is_wp_error( $access ) ) {
					return $access;
				}

				// Re-granting after a refund or expiry: reactivate the same row.
				if ( BundleAccess::STATUS_ACTIVE !== $access->status ) {
					if ( ! $this->access->update(
						$access->id,
						array(
							'status'     => BundleAccess::STATUS_ACTIVE,
							'granted_at' => current_time( 'mysql', true ),
							'expires_at' => $expires_at,
							'revoked_at' => null,
						)
					) ) {
						return new \WP_Error( 'tcb_access_write_failed', __( 'The entitlement could not be reactivated.', 'tutor-course-bundles' ) );
					}

					$access = $this->access->find( $access->id ) ?? $access;
				}

				Logger::audit(
					Logger::ACTION_ACCESS_GRANTED,
					array(
						'access_id'   => $access->id,
						'source_type' => $source_type,
						'source_id'   => $source_id,
						'expires_at'  => $expires_at,
					),
					$bundle_id,
					$user_id
				);

				/**
				 * Fires immediately after an entitlement becomes active.
				 *
				 * @param BundleAccess $access Entitlement.
				 */
				do_action( 'tcb/access/granted', $access );

				Cache::flush_user_progress( $user_id, $bundle_id );

				return $access;
			}
		);

		if ( null === $result ) {
			// Another request holds the lock and is doing exactly this work.
			$existing = $this->access->find_by_source( $bundle_id, $user_id, $source_type, $source_id );

			return $existing ?? new \WP_Error( 'tcb_grant_busy', __( 'This grant is already being processed.', 'tutor-course-bundles' ) );
		}

		if ( $result instanceof BundleAccess ) {
			$this->enroll_all_courses( $result, (bool) ( $args['defer'] ?? false ) );
		}
		return $result;
	}

	/**
	 * Enroll a learner in every course covered by an entitlement.
	 *
	 * Large bundles are handed to Action Scheduler (or WP-Cron) so a checkout
	 * request never has to write two hundred enrollment records inline.
	 *
	 * @param BundleAccess $access Entitlement.
	 * @param bool         $force_defer Always queue, even for small bundles.
	 */
	public function enroll_all_courses( BundleAccess $access, bool $force_defer = false ): void {
		if ( BundleAccess::STATUS_ACTIVE === $access->status && $access->starts_at && strtotime( $access->starts_at . ' UTC' ) > time() ) {
			$args = array( $access->id );
			if ( ! wp_next_scheduled( self::HOOK_ACTIVATE_ACCESS, $args ) ) {
				wp_schedule_single_event( (int) strtotime( $access->starts_at . ' UTC' ), self::HOOK_ACTIVATE_ACCESS, $args );
			}
		}
		$result = $this->locks->with_lock(
			'learner_' . $access->user_id,
			function () use ( $access, $force_defer ): bool {
				$fresh = $this->access->find( $access->id );
				if ( $fresh instanceof BundleAccess && ! $fresh->is_usable() && BundleAccess::STATUS_ACTIVE === $fresh->status && ! $fresh->is_expired() && $fresh->starts_at ) {
					foreach ( $this->bundles->get_course_ids( $fresh->bundle_id, true ) as $course_id ) {
						$this->enrollments->record(
							array(
								'bundle_access_id' => $fresh->id,
								'bundle_id'        => $fresh->bundle_id,
								'course_id'        => (int) $course_id,
								'user_id'          => $fresh->user_id,
								'status'           => EnrollmentRepository::STATUS_PENDING,
							)
						);
					}
				}
				if ( $fresh instanceof BundleAccess && $fresh->is_usable() ) {
					$this->enroll_all_courses_locked( $fresh, $force_defer );
				}
				return true;
			}
		);
		if ( null === $result ) {
			$this->schedule_batch( $access->id, $this->bundles->get_course_ids( $access->bundle_id, true ) );
		}
	}

	/**
	 * Enroll or queue bundle courses while holding the learner lock.
	 *
	 * @param BundleAccess $access      Entitlement to enroll.
	 * @param bool         $force_defer Whether to always queue enrollment.
	 */
	private function enroll_all_courses_locked( BundleAccess $access, bool $force_defer ): void {
		$course_ids = $this->bundles->get_course_ids( $access->bundle_id, true );

		if ( array() === $course_ids ) {
			return;
		}

		$threshold = (int) apply_filters( 'tcb/enrollment/batch_threshold', self::DEFAULT_BATCH_SIZE );

		if ( ! $force_defer && count( $course_ids ) <= $threshold ) {
			foreach ( $course_ids as $course_id ) {
				$this->ensure_enrolled_locked( $access, (int) $course_id );
			}

			return;
		}

		// Pre-record pending rows so the admin can see queued work and repair it.
		foreach ( $course_ids as $course_id ) {
			$this->enrollments->record(
				array(
					'bundle_access_id' => $access->id,
					'bundle_id'        => $access->bundle_id,
					'course_id'        => (int) $course_id,
					'user_id'          => $access->user_id,
					'status'           => EnrollmentRepository::STATUS_PENDING,
				)
			);
		}

		foreach ( array_chunk( $course_ids, $threshold ) as $index => $chunk ) {
			$this->schedule_batch( $access->id, array_map( 'intval', $chunk ), $index );
		}
	}

	/**
	 * Queue a batch of courses for background enrollment.
	 *
	 * @param int   $access_id  Entitlement row ID.
	 * @param int[] $course_ids Course IDs.
	 * @param int   $index      Batch index, used to stagger cron fallbacks.
	 */
	private function schedule_batch( int $access_id, array $course_ids, int $index = 0 ): void {
		if ( Compatibility::action_scheduler_active() ) {
			as_enqueue_async_action( self::HOOK_PROCESS_BATCH, array( $access_id, $course_ids ), 'tcb-access-' . $access_id );

			return;
		}

		wp_schedule_single_event( time() + ( $index * 10 ) + 5, self::HOOK_PROCESS_BATCH, array( $access_id, $course_ids ) );
	}

	/**
	 * Background handler for a queued batch.
	 *
	 * @param int   $access_id  Entitlement row ID.
	 * @param int[] $course_ids Course IDs.
	 */
	public function process_batch_job( $access_id, $course_ids ): void {
		$access = $this->access->find( (int) $access_id );

		if ( ! $access instanceof BundleAccess || ! $access->is_usable() ) {
			return;
		}

		foreach ( (array) $course_ids as $course_id ) {
			$this->ensure_enrolled( $access, (int) $course_id );
		}

		Cache::flush_user_progress( $access->user_id, $access->bundle_id );
	}

	/**
	 * Enroll one course under one entitlement, idempotently.
	 *
	 * @param BundleAccess $access    Entitlement.
	 * @param int          $course_id Course post ID.
	 */
	public function ensure_enrolled( BundleAccess $access, int $course_id ): bool {
		$result = $this->locks->with_lock(
			'learner_' . $access->user_id,
			fn(): bool => $this->ensure_enrolled_locked( $access, $course_id )
		);
		if ( null === $result ) {
			$this->schedule_batch( $access->id, array( $course_id ) );
		}
		return true === $result;
	}

	/**
	 * Recheck entitlement and shared Tutor enrollment under the revocation lock.
	 *
	 * @param BundleAccess $access    Entitlement to recheck.
	 * @param int          $course_id Course post ID.
	 */
	private function ensure_enrolled_locked( BundleAccess $access, int $course_id ): bool {
		$fresh_access = $this->access->find( $access->id );

		if ( ! $fresh_access instanceof BundleAccess || ! $fresh_access->is_usable() ) {
			return false;
		}

		if ( ! in_array( $course_id, $this->bundles->get_course_ids( $fresh_access->bundle_id, true ), true ) ) {
			return false;
		}

		$access       = $fresh_access;
		$existing_row = $this->enrollments->find( $access->id, $course_id );

		if ( null !== $existing_row && EnrollmentRepository::STATUS_ACTIVE === $existing_row['status'] && $this->tutor->is_enrolled( $course_id, $access->user_id ) ) {
			return true;
		}

		$post = get_post( $course_id );

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			$this->enrollments->record(
				array(
					'bundle_access_id' => $access->id,
					'bundle_id'        => $access->bundle_id,
					'course_id'        => $course_id,
					'user_id'          => $access->user_id,
					'status'           => EnrollmentRepository::STATUS_SKIPPED,
					'error_code'       => $post instanceof \WP_Post ? 'course_not_published' : 'course_missing',
				)
			);

			Logger::audit(
				Logger::ACTION_COURSE_SKIPPED,
				array(
					'course_id' => $course_id,
					'access_id' => $access->id,
					'reason'    => $post instanceof \WP_Post ? 'course_not_published' : 'course_missing',
				),
				$access->bundle_id,
				$access->user_id
			);

			return false;
		}

		$was_enrolled  = $this->tutor->is_enrolled( $course_id, $access->user_id );
		$enrollment_id = $this->tutor->enroll( $course_id, $access->user_id, $access->source_id );

		if ( is_wp_error( $enrollment_id ) ) {
			$this->enrollments->record(
				array(
					'bundle_access_id' => $access->id,
					'bundle_id'        => $access->bundle_id,
					'course_id'        => $course_id,
					'user_id'          => $access->user_id,
					'status'           => EnrollmentRepository::STATUS_FAILED,
					'error_code'       => $enrollment_id->get_error_code(),
				)
			);

			Logger::audit(
				Logger::ACTION_ENROLL_FAILED,
				array(
					'course_id' => $course_id,
					'access_id' => $access->id,
					'error'     => $enrollment_id->get_error_message(),
				),
				$access->bundle_id,
				$access->user_id
			);

			return false;
		}

		// Only claim ownership of enrollments we actually created. A learner who
		// already owned the course keeps their original, untagged record.
		if ( ! $was_enrolled ) {
			$this->resolver->tag_origin( (int) $enrollment_id, $access->id, $access->bundle_id );
		}

		$recorded = $this->enrollments->record(
			array(
				'bundle_access_id'    => $access->id,
				'bundle_id'           => $access->bundle_id,
				'course_id'           => $course_id,
				'user_id'             => $access->user_id,
				'tutor_enrollment_id' => (int) $enrollment_id,
				'status'              => EnrollmentRepository::STATUS_ACTIVE,
				'enrolled_at'         => current_time( 'mysql', true ),
				'error_code'          => null,
			)
		);

		if ( is_wp_error( $recorded ) || ! $recorded ) {
			$this->schedule_batch( $access->id, array( $course_id ) );
			return false;
		}

		Logger::audit(
			Logger::ACTION_COURSE_ENROLLED,
			array(
				'course_id'           => $course_id,
				'access_id'           => $access->id,
				'tutor_enrollment_id' => (int) $enrollment_id,
				'pre_existing'        => $was_enrolled,
			),
			$access->bundle_id,
			$access->user_id
		);

		/**
		 * Fires after a learner is enrolled in a course because of a bundle.
		 *
		 * @param int $user_id   User ID.
		 * @param int $course_id Course post ID.
		 * @param int $bundle_id Bundle post ID.
		 */
		do_action( 'tcb/course/enrolled', $access->user_id, $course_id, $access->bundle_id );

		Cache::flush_user_progress( $access->user_id, $access->bundle_id );

		return true;
	}

	/**
	 * Wind down an entitlement and, where safe, its course enrollments.
	 *
	 * @param int    $access_id Entitlement row ID.
	 * @param string $status    Terminal status.
	 * @param string $reason    Audit note.
	 */
	public function revoke_access( int $access_id, string $status = BundleAccess::STATUS_REVOKED, string $reason = '' ): bool {
		$access = $this->access->find( $access_id );
		if ( ! $access instanceof BundleAccess ) {
			return false;
		}
		$result = $this->locks->with_lock( 'learner_' . $access->user_id, fn(): bool => $this->revoke_access_locked( $access_id, $status, $reason ) );
		if ( true !== $result ) {
			$args = array( $access_id, $status, $reason );
			if ( ! wp_next_scheduled( self::HOOK_REVOKE_ACCESS, $args ) ) {
				wp_schedule_single_event( time() + 10, self::HOOK_REVOKE_ACCESS, $args );
			}
		}
		return true === $result;
	}

	/**
	 * Revoke entitlement and owned enrollments while holding the learner lock.
	 *
	 * @param int    $access_id Entitlement row ID.
	 * @param string $status    Terminal status.
	 * @param string $reason    Audit note.
	 */
	private function revoke_access_locked( int $access_id, string $status, string $reason ): bool {
		$access = $this->access->find( $access_id );

		if ( ! $access instanceof BundleAccess ) {
			return false;
		}

		$rows    = $this->enrollments->find_by_access( $access_id );
		$revoked = array();
		$kept    = array();

		foreach ( $rows as $row ) {
			$course_id = (int) $row['course_id'];
			if ( ! in_array( $row['status'], array( EnrollmentRepository::STATUS_ACTIVE, EnrollmentRepository::STATUS_PENDING, EnrollmentRepository::STATUS_FAILED ), true ) ) {
				continue;
			}
			if ( $this->resolver->can_revoke_course( $access->user_id, $course_id, $access_id ) ) {
				if ( ! $this->tutor->cancel_enrollment( $course_id, $access->user_id ) ) {
					return false;
				}
				$revoked[] = $course_id;
			} else {
				$kept[] = $course_id;
			}
			if ( ! $this->enrollments->update(
				(int) $row['id'],
				array(
					'status'     => EnrollmentRepository::STATUS_REVOKED,
					'revoked_at' => current_time( 'mysql', true ),
				)
			) ) {
				return false;
			}
		}

		if ( ! $this->access->terminate( $access_id, $status ) ) {
			return false;
		}
		$this->cancel_access_jobs( $access_id );

		Logger::audit(
			BundleAccess::STATUS_REFUNDED === $status ? Logger::ACTION_ACCESS_REFUNDED : Logger::ACTION_ACCESS_REVOKED,
			array(
				'access_id'       => $access_id,
				'status'          => $status,
				'reason'          => $reason,
				'courses_revoked' => $revoked,
				'courses_kept'    => $kept,
			),
			$access->bundle_id,
			$access->user_id
		);

		$updated = $this->access->find( $access_id ) ?? $access;

		/**
		 * Fires after an entitlement is terminated.
		 *
		 * @param BundleAccess $access  Entitlement in its new state.
		 * @param string       $reason  Free-form reason.
		 * @param int[]        $revoked Courses whose enrollment was cancelled.
		 * @param int[]        $kept    Courses kept because another source covers them.
		 */
		do_action( 'tcb/access/revoked', $updated, $reason, $revoked, $kept );

		Cache::flush_user_progress( $access->user_id, $access->bundle_id );

		return true;
	}

	/**
	 * Cancel pending enrollment batches for one entitlement.
	 *
	 * @param int $access_id Entitlement ID.
	 */
	private function cancel_access_jobs( int $access_id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'tcb-access-' . $access_id );
		}
	}

	/**
	 * Hourly cron: retire entitlements whose expiry has passed.
	 *
	 * @param int $before_id Continue below this entitlement ID.
	 */
	public function expire_due_access( int $before_id = 0 ): void {
		$items = $this->access->find_expired_active( 50, $before_id );
		foreach ( $items as $access ) {
			if ( $this->revoke_access( $access->id, BundleAccess::STATUS_EXPIRED, 'expiry_reached' ) ) {
				Logger::audit( Logger::ACTION_ACCESS_EXPIRED, array( 'access_id' => $access->id ), $access->bundle_id, $access->user_id );
			}
		}
		if ( count( $items ) === 50 ) {
			$last = end( $items );
			wp_schedule_single_event( time() + 10, self::HOOK_EXPIRE_BATCH, array( $last->id ) );
		}
	}

	/**
	 * Activate a future-dated grant using the fresh ledger and membership.
	 *
	 * @param int $access_id Entitlement row ID.
	 */
	public function activate_access_job( int $access_id ): void {
		$access = $this->access->find( $access_id );
		if ( $access instanceof BundleAccess ) {
			$this->enroll_all_courses( $access );
		}
	}

	/**
	 * Apply Tutor's final authorization filter so expiry takes effect before cron.
	 *
	 * @param bool                 $allowed Tutor authorization result.
	 * @param array<string, mixed> $args    Content access context.
	 */
	public function filter_content_access( $allowed, $args ): bool {
		if ( ! $allowed ) {
			return false;
		}
		$course_id     = (int) ( $args['course_id'] ?? 0 );
		$user_id       = get_current_user_id();
		$enrollment_id = $this->tutor->get_enrollment_id( $course_id, $user_id );
		if ( $enrollment_id <= 0 ) {
			$enrollment_id = $this->tutor->find_enrollment_any_status( $course_id, $user_id );
		}
		if ( ! $this->resolver->is_plugin_owned( $enrollment_id ) ) {
			return true;
		}
		return $this->resolver->user_has_alternative_access( $user_id, $course_id, 0 );
	}

	/**
	 * Resolve a content post to its containing Tutor course.
	 *
	 * @param int $post_id Content post ID.
	 */
	private function content_course_id( int $post_id ): int {
		for ( $depth = 0; $post_id > 0 && $depth < 5; ++$depth ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post ) {
				break;
			}
			if ( $post->post_type === $this->tutor->course_post_type() ) {
				return $post_id;
			}
			$post_id = (int) $post->post_parent;
		}
		return 0;
	}

	/**
	 * Deny expired bundle access before rendering course content.
	 */
	public function guard_content_request(): void {
		if ( is_admin() || ! is_singular( array( 'lesson', 'tutor_quiz', 'tutor_assignments' ) ) ) {
			return;
		}
		$course_id = $this->content_course_id( (int) get_queried_object_id() );
		if ( $course_id && ! $this->filter_content_access( true, array( 'course_id' => $course_id ) ) ) {
			wp_die( esc_html__( 'Your bundle access has expired.', 'tutor-course-bundles' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Deny expired bundle access before Tutor processes its content AJAX request.
	 */
	public function guard_content_ajax(): void {
		// Read-only denial guard; the Tutor handler verifies its nonce before processing.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$post_id   = absint( $_POST['lesson_id'] ?? $_POST['post_id'] ?? 0 );
		$course_id = $this->content_course_id( $post_id );
		if ( $course_id && ! $this->filter_content_access( true, array( 'course_id' => $course_id ) ) ) {
			wp_send_json_error( array( 'code' => 'tcb_access_expired' ), 403 );
		}
	}

	/**
	 * A course was added to a bundle — decide what existing learners get.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @param int $course_id Course post ID.
	 */
	public function on_course_added_to_bundle( int $bundle_id, int $course_id ): void {
		$bundle = $this->bundles->find( $bundle_id );

		if ( ! $bundle instanceof Bundle || ! $bundle->grants_new_courses() ) {
			return;
		}

		if ( Compatibility::action_scheduler_active() ) {
			as_enqueue_async_action( self::HOOK_SYNC_NEW_COURSE, array( $bundle_id, $course_id ), 'tutor-course-bundles' );

			return;
		}

		wp_schedule_single_event( time() + 10, self::HOOK_SYNC_NEW_COURSE, array( $bundle_id, $course_id ) );
	}

	/**
	 * Background handler: enroll every current learner into a newly added course.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @param int $course_id Course post ID.
	 * @param int $before_id Continue below this entitlement ID.
	 */
	public function sync_new_course_job( $bundle_id, $course_id, $before_id = 0 ): void {
		$bundle_id = (int) $bundle_id;
		$course_id = (int) $course_id;
		$before_id = (int) $before_id;
		$bundle    = $this->bundles->find( $bundle_id );
		if ( ! $bundle instanceof Bundle || ! $bundle->grants_new_courses() || ! in_array( $course_id, $this->bundles->get_course_ids( $bundle_id, true ), true ) ) {
			return;
		}

		$result = $this->access->query(
			array(
				'bundle_id' => $bundle_id,
				'status'    => BundleAccess::STATUS_ACTIVE,
				'limit'     => self::ACCESS_BATCH_SIZE,
				'before_id' => $before_id,
			)
		);

		foreach ( $result['items'] as $access ) {
			if ( ! $access->is_usable() ) {
				continue;
			}

			$this->ensure_enrolled( $access, $course_id );
		}

		if ( count( $result['items'] ) === self::ACCESS_BATCH_SIZE ) {
			$last = end( $result['items'] );

			if ( $last instanceof BundleAccess ) {
				$this->schedule_course_job( self::HOOK_SYNC_NEW_COURSE, $bundle_id, $course_id, $last->id );
			}
		}
	}

	/**
	 * A course was detached from a bundle.
	 *
	 * Default behaviour is deliberately conservative: existing learners keep
	 * what they already paid for. Site owners can opt into revocation.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @param int $course_id Course post ID.
	 */
	public function on_course_removed_from_bundle( int $bundle_id, int $course_id ): void {
		$revoke = 'yes' === get_option( 'tcb_revoke_on_course_removal', 'no' );

		/**
		 * Filter whether removing a course from a bundle revokes existing learners.
		 *
		 * @param bool $revoke    Whether to revoke.
		 * @param int  $bundle_id Bundle post ID.
		 * @param int  $course_id Course post ID.
		 */
		$revoke = (bool) apply_filters( 'tcb/bundle/revoke_on_course_removal', $revoke, $bundle_id, $course_id );

		if ( ! $revoke ) {
			return;
		}

		$this->remove_course_job( $bundle_id, $course_id );
	}

	/**
	 * Revoke one removed course for a cursor-bounded page of entitlements.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @param int $course_id Course post ID.
	 * @param int $before_id Continue below this entitlement ID.
	 */
	public function remove_course_job( $bundle_id, $course_id, $before_id = 0 ): void {
		$bundle_id = (int) $bundle_id;
		$course_id = (int) $course_id;
		$before_id = (int) $before_id;

		if ( in_array( $course_id, $this->bundles->get_course_ids( $bundle_id ), true ) ) {
			return;
		}

		$result = $this->access->query(
			array(
				'bundle_id' => $bundle_id,
				'status'    => BundleAccess::STATUS_ACTIVE,
				'limit'     => self::ACCESS_BATCH_SIZE,
				'before_id' => $before_id,
			)
		);

		foreach ( $result['items'] as $access ) {
			$removed = $this->locks->with_lock(
				'learner_' . $access->user_id,
				function () use ( $access, $course_id ): bool {
					$row = $this->enrollments->find( $access->id, $course_id );

					if ( null === $row || EnrollmentRepository::STATUS_ACTIVE !== $row['status'] ) {
						return true;
					}

					if ( $this->resolver->can_revoke_course( $access->user_id, $course_id, $access->id ) && ! $this->tutor->cancel_enrollment( $course_id, $access->user_id ) ) {
						return false;
					}
					if ( ! $this->enrollments->update(
						(int) $row['id'],
						array(
							'status'     => EnrollmentRepository::STATUS_REVOKED,
							'revoked_at' => current_time( 'mysql', true ),
						)
					) ) {
						return false;
					}
					return true;
				}
			);
			if ( true !== $removed ) {
				$this->schedule_course_job( self::HOOK_REMOVE_COURSE, $bundle_id, $course_id, $before_id );
				return;
			}
		}

		if ( count( $result['items'] ) === self::ACCESS_BATCH_SIZE ) {
			$last = end( $result['items'] );

			if ( $last instanceof BundleAccess ) {
				$this->schedule_course_job( self::HOOK_REMOVE_COURSE, $bundle_id, $course_id, $last->id );
			}
		}
	}

	/**
	 * Schedule a cursor-based course synchronization job.
	 *
	 * @param string $hook      Job hook.
	 * @param int    $bundle_id Bundle post ID.
	 * @param int    $course_id Course post ID.
	 * @param int    $before_id Cursor.
	 */
	private function schedule_course_job( string $hook, int $bundle_id, int $course_id, int $before_id ): void {
		$args = array( $bundle_id, $course_id, $before_id );

		if ( Compatibility::action_scheduler_active() ) {
			as_enqueue_async_action( $hook, $args, 'tutor-course-bundles' );

			return;
		}

		wp_schedule_single_event( time() + 10, $hook, $args );
	}

	/**
	 * Retry every failed or stuck enrollment row. Used by the admin repair tool.
	 *
	 * @param int $limit Batch size.
	 * @return array{repaired:int, failed:int}
	 */
	public function repair_failed_enrollments( int $limit = 50 ): array {
		$repaired = 0;
		$failed   = 0;

		foreach ( $this->enrollments->find_failed( $limit ) as $row ) {
			$access = $this->access->find( (int) $row['bundle_access_id'] );

			if ( ! $access instanceof BundleAccess || ! $access->is_usable() ) {
				++$failed;
				continue;
			}

			if ( $this->ensure_enrolled( $access, (int) $row['course_id'] ) ) {
				++$repaired;
			} else {
				++$failed;
			}
		}

		return array(
			'repaired' => $repaired,
			'failed'   => $failed,
		);
	}
}
