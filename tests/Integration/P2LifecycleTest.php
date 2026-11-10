<?php
declare(strict_types=1);
namespace SpaceWork\TutorCourseBundles\Tests\Integration;

use SpaceWork\TutorCourseBundles\Tests\Support\TestCase;
use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Infrastructure\AccessRepository;
use SpaceWork\TutorCourseBundles\Tutor\EnrollmentService;
use SpaceWork\TutorCourseBundles\Tutor\TutorAdapter;
final class P2LifecycleTest extends TestCase {
	private function service(): EnrollmentService {
		return tcb()->get( EnrollmentService::class ); }
	public function test_repair_reconciles_active_mapping_with_canceled_tutor_enrollment(): void {
		$this->require_tutor();
		$u = $this->seeder->student();
		$b = $this->seeder->bundle_with_courses( 1 );
		$a = $this->service()->grant_access( $b['bundle'], $u, BundleAccess::SOURCE_MANUAL );
		tcb()->get( TutorAdapter::class )->cancel_enrollment( $b['courses'][0], $u );
		$result = $this->service()->repair_failed_enrollments();
		$this->assertSame( 1, $result['repaired'] );
		$this->assertEnrolled( $b['courses'][0], $u );
	}
	public function test_queued_addition_cannot_grant_removed_membership(): void {
		$this->require_tutor();
		$u = $this->seeder->student();
		$b = $this->seeder->bundle_with_courses( 1 );
		$a = $this->service()->grant_access( $b['bundle'], $u, BundleAccess::SOURCE_MANUAL );
		$c = $this->seeder->course();
		$this->seeder->repository()->add_course( $b['bundle'], $c );
		$this->seeder->repository()->remove_course( $b['bundle'], $c );
		$this->service()->sync_new_course_job( $b['bundle'], $c );
		$this->service()->process_batch_job( $a->id, array( $c ) );
		$this->assertNotEnrolled( $c, $u );
	}
	public function test_expiry_backlog_queues_continuation(): void {
		$u    = $this->seeder->student();
		$b    = $this->seeder->bundle();
		$repo = tcb()->get( AccessRepository::class );
		$ids  = array(); for ( $i = 1;$i <= 51;$i++ ) {
			$ids[] = $repo->create(
				array(
					'bundle_id'   => $b,
					'user_id'     => $u,
					'source_type' => 'manual',
					'status'      => 'active',
					'source_id'   => $i,
					'expires_at'  => gmdate( 'Y-m-d H:i:s', time() - 100 ),
				)
			)->id;}
		$this->service()->expire_due_access();
		$this->assertNotFalse( wp_next_scheduled( 'tcb/job/expire_access_batch', array( $ids[1] ) ) );
		do_action( 'tcb/job/expire_access_batch', $ids[1] );
		$this->assertSame( array(), $repo->find_expired_active() );
	}
	public function test_future_grant_records_pending_work_and_schedules_start(): void {
		$this->require_tutor();
		$u = $this->seeder->student();
		$b = $this->seeder->bundle_with_courses( 1 );
		$a = $this->service()->grant_access( $b['bundle'], $u, BundleAccess::SOURCE_MANUAL, 0, array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 120 ) ) );
		$this->assertNotEnrolled( $b['courses'][0], $u );
		$this->assertNotNull( $this->enrollmentRow( $a->id, $b['courses'][0] ) );
		$this->assertNotFalse( wp_next_scheduled( 'tcb/job/activate_access', array( $a->id ) ) );
		tcb()->get( AccessRepository::class )->update( $a->id, array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
		do_action( 'tcb/job/activate_access', $a->id );
		$this->assertEnrolled( $b['courses'][0], $u );
	}
	public function test_expired_owned_enrollment_is_denied_before_cron(): void {
		$this->require_tutor();
		$u = $this->seeder->student();
		$b = $this->seeder->bundle_with_courses( 1 );
		$a = $this->service()->grant_access( $b['bundle'], $u, BundleAccess::SOURCE_MANUAL );
		tcb()->get( AccessRepository::class )->update( $a->id, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
		wp_set_current_user( $u );
		$this->assertFalse( apply_filters( 'tutor_course_content_access', true, array( 'course_id' => $b['courses'][0] ) ) );
	}
	public function test_mapping_failure_does_not_report_successful_revoke(): void {
		$this->require_tutor();
		$u     = $this->seeder->student();
		$b     = $this->seeder->bundle_with_courses( 1 );
		$a     = $this->service()->grant_access( $b['bundle'], $u, BundleAccess::SOURCE_MANUAL );
		$fault = static function ( $sql ) {
			return str_starts_with( $sql, 'UPDATE `' ) && str_contains( $sql, 'tcb_bundle_enrollments' ) ? 'INVALID P2 FAILURE' : $sql;
		};
		global $wpdb;
		$old = $wpdb->suppress_errors( true );
		add_filter( 'query', $fault );
		try {
			$result = $this->service()->revoke_access( $a->id );
		} finally {
			remove_filter( 'query', $fault );
			$wpdb->suppress_errors( $old ); }
		$this->assertFalse( $result );
		$this->assertSame( BundleAccess::STATUS_ACTIVE, tcb()->get( AccessRepository::class )->find( $a->id )->status );
	}
	public function test_expired_canceled_owned_enrollment_cannot_reuse_tutor_cache(): void {
		$this->require_tutor();
		$u = $this->seeder->student();
		$b = $this->seeder->bundle_with_courses( 1 );
		$a = $this->service()->grant_access( $b['bundle'], $u, BundleAccess::SOURCE_MANUAL );
		tcb()->get( AccessRepository::class )->update( $a->id, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
		$this->assertTrue( $this->service()->revoke_access( $a->id, BundleAccess::STATUS_EXPIRED ) );
		wp_set_current_user( $u );
		$this->assertFalse( apply_filters( 'tutor_course_content_access', true, array( 'course_id' => $b['courses'][0] ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_autoload_next_course_content', array( $this->service(), 'guard_content_ajax' ) ) );
	}
	public function test_tutor_status_write_failure_keeps_revoke_retryable(): void {
		$this->require_tutor();
		$u     = $this->seeder->student();
		$b     = $this->seeder->bundle_with_courses( 1 );
		$a     = $this->service()->grant_access( $b['bundle'], $u, BundleAccess::SOURCE_MANUAL );
		$fault = static function ( $sql ) {
			return str_starts_with( $sql, 'UPDATE `' ) && str_contains( $sql, 'wptests_posts' ) ? 'INVALID P2 FAILURE' : $sql;
		};
		global $wpdb;
		$old = $wpdb->suppress_errors( true );
		add_filter( 'query', $fault );
		try {
			$result = $this->service()->revoke_access( $a->id );
		} finally {
			remove_filter( 'query', $fault );
			$wpdb->suppress_errors( $old );}
		$this->assertFalse( $result );
		$this->assertEnrolled( $b['courses'][0], $u );
		$this->assertSame( 'active', $this->enrollmentRow( $a->id, $b['courses'][0] )['status'] );
		$this->assertNotFalse( wp_next_scheduled( EnrollmentService::HOOK_REVOKE_ACCESS, array( $a->id, 'revoked', '' ) ) );
		$this->assertTrue( $this->service()->revoke_access( $a->id ) );
		$this->assertNotEnrolled( $b['courses'][0], $u );
	}
	public function test_terminal_ledger_write_failure_is_retryable(): void {
		$this->require_tutor();
		$u     = $this->seeder->student();
		$b     = $this->seeder->bundle_with_courses( 1 );
		$a     = $this->service()->grant_access( $b['bundle'], $u, BundleAccess::SOURCE_MANUAL );
		$fault = static function ( $sql ) {
			return str_starts_with( $sql, 'UPDATE `' ) && str_contains( $sql, 'tcb_bundle_access' ) ? 'INVALID P2 FAILURE' : $sql;
		};
		global $wpdb;
		$old = $wpdb->suppress_errors( true );
		add_filter( 'query', $fault );
		try {
			$result = $this->service()->revoke_access( $a->id );
		} finally {
			remove_filter( 'query', $fault );
			$wpdb->suppress_errors( $old );}
		$this->assertFalse( $result );
		$this->assertSame( 'active', tcb()->get( AccessRepository::class )->find( $a->id )->status );
		$this->assertTrue( $this->service()->revoke_access( $a->id ) );
	}
	public function test_expiry_preserves_direct_and_other_valid_bundle_access(): void {
		$this->require_tutor();
		$u      = $this->seeder->student();
		$b      = $this->seeder->bundle_with_courses( 1 );
		$course = $b['courses'][0];
		tcb()->get( TutorAdapter::class )->enroll( $course, $u );
		$a = $this->service()->grant_access( $b['bundle'], $u, BundleAccess::SOURCE_MANUAL );
		tcb()->get( AccessRepository::class )->update( $a->id, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
		wp_set_current_user( $u );
		$this->assertTrue( apply_filters( 'tutor_course_content_access', true, array( 'course_id' => $course ) ) );
		$u2 = $this->seeder->student();
		$a2 = $this->service()->grant_access( $b['bundle'], $u2, BundleAccess::SOURCE_MANUAL );
		$a3 = $this->service()->grant_access( $b['bundle'], $u2, BundleAccess::SOURCE_WOOCOMMERCE, 776 );
		tcb()->get( AccessRepository::class )->update( $a2->id, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
		wp_set_current_user( $u2 );
		$this->assertTrue( apply_filters( 'tutor_course_content_access', true, array( 'course_id' => $course ) ) );
	}
	public function test_delayed_removal_does_not_revoke_a_course_restored_to_bundle(): void {
		$this->require_tutor();
		$u      = $this->seeder->student();
		$b      = $this->seeder->bundle_with_courses( 1 );
		$course = $b['courses'][0];
		$a      = $this->service()->grant_access( $b['bundle'], $u, BundleAccess::SOURCE_MANUAL );
		$this->service()->remove_course_job( $b['bundle'], $course );
		$this->assertEnrolled( $course, $u );
	}
	public function test_future_activation_survives_busy_learner_lock(): void {
		$this->require_tutor();
		$u = $this->seeder->student();
		$b = $this->seeder->bundle_with_courses( 1 );
		$a = $this->service()->grant_access( $b['bundle'], $u, BundleAccess::SOURCE_MANUAL, 0, array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 120 ) ) );
		wp_clear_scheduled_hook( 'tcb/job/activate_access', array( $a->id ) );
		$lock = tcb()->get( \SpaceWork\TutorCourseBundles\Infrastructure\LockManager::class );
		$this->assertTrue( $lock->acquire( 'learner_' . $u ) );
		try {
			$this->service()->enroll_all_courses( $a );
		} finally {
			$lock->release( 'learner_' . $u );
		}
		$this->assertNotFalse( wp_next_scheduled( 'tcb/job/activate_access', array( $a->id ) ) );
	}
	public function test_real_ajax_action_chain_returns_no_lesson_html_after_expiry(): void {
		$this->require_tutor();
		$u      = $this->seeder->student();
		$b      = $this->seeder->bundle_with_courses( 1 );
		$course = $b['courses'][0];
		$a      = $this->service()->grant_access( $b['bundle'], $u, BundleAccess::SOURCE_MANUAL );
		$topic  = self::factory()->post->create(
			array(
				'post_type'   => 'topics',
				'post_parent' => $course,
			)
		);
		$lesson = self::factory()->post->create(
			array(
				'post_type'    => 'lesson',
				'post_parent'  => $topic,
				'post_content' => 'Secret expired content',
			)
		);
		tcb()->get( AccessRepository::class )->update( $a->id, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
		wp_set_current_user( $u );
		$_POST['lesson_id'] = $lesson;
		$handler            = static function () {
			return static function () {
				throw new \RuntimeException( 'JSON request stopped' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $handler );
		ob_start();
		try {
			do_action( 'wp_ajax_tutor_render_lesson_content' );
			$this->fail( 'Expired content was delivered.' );
		} catch ( \RuntimeException $error ) {
					$this->assertSame( 'JSON request stopped', $error->getMessage() );
		} finally {
			$json = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $handler );
			remove_filter( 'wp_doing_ajax', '__return_true' );
			unset( $_POST['lesson_id'] );
		}
			$data = json_decode( $json, true );
			$this->assertFalse( $data['success'] );
			$this->assertSame( 'tcb_access_expired', $data['data']['code'] );
			$this->assertStringNotContainsString( 'Secret expired content', $json );
	}
}
