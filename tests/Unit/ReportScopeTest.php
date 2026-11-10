<?php
declare( strict_types = 1 );
namespace SpaceWork\TutorCourseBundles\Tests\Unit;
use Brain\Monkey\Functions;
use SpaceWork\TutorCourseBundles\Support\Capabilities;
final class ReportScopeTest extends UnitTestCase {
	public function test_report_capability_does_not_allow_foreign_bundle_or_all_bundle_export(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => Capabilities::VIEW_REPORTS === $cap );
		Functions\when( 'user_can' )->justReturn( false );
		$this->assertFalse( Capabilities::can_view_bundle_reports( 42 ) );
		$this->assertFalse( Capabilities::can_view_bundle_reports( 0 ) );
	}
	public function test_instructor_can_read_own_bundle_reports(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => Capabilities::VIEW_REPORTS === $cap );
		Functions\when( 'user_can' )->justReturn( true );
		$this->assertTrue( Capabilities::can_view_bundle_reports( 42 ) );
	}
}
