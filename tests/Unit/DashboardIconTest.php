<?php
/** Tutor 4 uses SVG names instead of the legacy icon-font class. */
namespace TUTOR {
	if ( ! class_exists( Icon::class ) ) {
		class Icon { const BUNDLE = 'bundle'; }
	}
}
namespace SpaceWork\TutorCourseBundles\Tests\Unit {
	use SpaceWork\TutorCourseBundles\Tutor\DashboardIntegration;
	final class DashboardIconTest extends UnitTestCase {
		public function test_dashboard_icon_uses_tutor_svg_name(): void {
			$dashboard = ( new \ReflectionClass( DashboardIntegration::class ) )->newInstanceWithoutConstructor();
			$items = $dashboard->add_nav_item( array() );
			$this->assertSame( 'bundle', $items['my-bundles']['icon'] );
		}
	}
}
