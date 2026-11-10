<?php
declare( strict_types = 1 );
namespace SpaceWork\TutorCourseBundles\Tests\Integration;
use SpaceWork\TutorCourseBundles\Admin\Reports;
use SpaceWork\TutorCourseBundles\Tests\Support\TestCase;
final class ReportPrivacyTest extends TestCase {
	/** @dataProvider export_targets */
	public function test_instructor_export_denies_global_or_foreign_bundle( bool $global ): void {
		$bundle = $this->seeder->bundle( array( 'author' => $this->seeder->instructor() ) );
		wp_set_current_user( $this->seeder->instructor() );
		$_POST['bundle_id'] = $global ? 0 : $bundle;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'tcb_reports' );
		$handler = static fn() => static function ( $message ) { throw new \RuntimeException( (string) $message ); };
		add_filter( 'wp_die_handler', $handler );
		try {
			tcb()->get( Reports::class )->handle_export();
			$this->fail( 'Unauthorized CSV export did not stop.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'permission to export this bundle', $error->getMessage() );
		} finally {
			remove_filter( 'wp_die_handler', $handler );
			unset( $_POST['bundle_id'], $_REQUEST['_wpnonce'] );
		}
		$this->assertSame( array(), tcb()->get( Reports::class )->get_bundle_report( $global ? 0 : $bundle ) );
	}
	public static function export_targets(): array {
		return array( 'all bundles' => array( true ), 'foreign bundle' => array( false ) );
	}
}
