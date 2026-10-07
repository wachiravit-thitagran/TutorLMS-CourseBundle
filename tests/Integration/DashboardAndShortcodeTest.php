<?php
/**
 * Learner-facing surfaces: dashboard, shortcodes, templates.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Integration;

use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Frontend\AccessController;
use SpaceWork\TutorCourseBundles\Frontend\TemplateLoader;
use SpaceWork\TutorCourseBundles\Tests\Support\TestCase;
use SpaceWork\TutorCourseBundles\Tutor\DashboardIntegration;
use SpaceWork\TutorCourseBundles\Tutor\EnrollmentService;

/**
 * @covers \SpaceWork\TutorCourseBundles\Tutor\DashboardIntegration
 * @covers \SpaceWork\TutorCourseBundles\Frontend\Shortcodes
 * @covers \SpaceWork\TutorCourseBundles\Frontend\TemplateLoader
 * @covers \SpaceWork\TutorCourseBundles\Frontend\AccessController
 */
final class DashboardAndShortcodeTest extends TestCase {

	/**
	 * Acceptance criterion 6: a learner's bundles show up in the dashboard.
	 */
	public function test_dashboard_lists_the_learners_bundles(): void {
		$this->require_tutor();

		$user   = $this->seeder->student();
		$first  = $this->seeder->bundle_with_courses( 2, array( 'title' => 'First bundle' ) );
		$second = $this->seeder->bundle_with_courses( 1, array( 'title' => 'Second bundle' ) );
		$this->seeder->bundle_with_courses( 1, array( 'title' => 'Never bought' ) );

		$enrollments = tcb()->get( EnrollmentService::class );
		$enrollments->grant_access( $first['bundle'], $user, BundleAccess::SOURCE_MANUAL, 0 );
		$enrollments->grant_access( $second['bundle'], $user, BundleAccess::SOURCE_MANUAL, 0 );

		$items = tcb()->get( DashboardIntegration::class )->get_dashboard_items( $user );

		$this->assertCount( 2, $items, 'Only bundles the learner holds should be listed.' );

		$titles = array_map(
			static fn( array $item ): string => $item['bundle']->get_title(),
			$items
		);

		$this->assertContains( 'First bundle', $titles );
		$this->assertContains( 'Second bundle', $titles );
		$this->assertNotContains( 'Never bought', $titles );

		foreach ( $items as $item ) {
			$this->assertInstanceOf( Bundle::class, $item['bundle'] );
			$this->assertInstanceOf( BundleAccess::class, $item['access'] );
			$this->assertInstanceOf( \SpaceWork\TutorCourseBundles\Domain\BundleProgress::class, $item['progress'] );
		}
	}

	/**
	 * A learner who bought the same bundle twice sees it once, and the row
	 * shown is the live one rather than an old refunded record.
	 */
	public function test_a_repurchased_bundle_appears_once_with_the_live_entitlement(): void {
		$this->require_tutor();

		$user  = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses( 1 );

		$enrollments = tcb()->get( EnrollmentService::class );

		$old = $enrollments->grant_access( $built['bundle'], $user, BundleAccess::SOURCE_WOOCOMMERCE, 1 );
		$enrollments->revoke_access( $old->id, BundleAccess::STATUS_REFUNDED, 'test' );

		$new = $enrollments->grant_access( $built['bundle'], $user, BundleAccess::SOURCE_WOOCOMMERCE, 2 );

		$items = tcb()->get( DashboardIntegration::class )->get_dashboard_items( $user );

		$this->assertCount( 1, $items, 'The bundle should be listed once, not once per purchase.' );
		$this->assertSame( $new->id, $items[0]['access']->id, 'The live entitlement should win over the refunded one.' );
	}

	/**
	 * The nav item lands in the Tutor dashboard menu.
	 */
	public function test_the_dashboard_nav_item_is_added(): void {
		$items = tcb()->get( DashboardIntegration::class )->add_nav_item(
			array(
				'dashboard'         => 'Dashboard',
				'enrolled-courses'  => 'Enrolled Courses',
				'wishlist'          => 'Wishlist',
			)
		);

		$this->assertArrayHasKey( DashboardIntegration::ENDPOINT, $items );

		$keys = array_keys( $items );

		$this->assertSame(
			array_search( 'enrolled-courses', $keys, true ) + 1,
			array_search( DashboardIntegration::ENDPOINT, $keys, true ),
			'My Bundles should sit directly after Enrolled Courses.'
		);
	}

	/**
	 * With no anchor to attach to, the item is appended rather than dropped.
	 */
	public function test_the_nav_item_survives_an_unfamiliar_menu(): void {
		$items = tcb()->get( DashboardIntegration::class )->add_nav_item( array( 'something-else' => 'Other' ) );

		$this->assertArrayHasKey( DashboardIntegration::ENDPOINT, $items );
	}

	/**
	 * Every shipped template resolves to a readable file. A typo in a template
	 * name renders a blank section rather than an error, so check them all.
	 *
	 * @dataProvider provideTemplates
	 *
	 * @param string $template Template name.
	 */
	public function test_templates_resolve( string $template ): void {
		$path = tcb()->get( TemplateLoader::class )->locate( $template );

		$this->assertIsReadable( $path, "Template {$template} did not resolve to a readable file." );
	}

	/**
	 * Shipped templates.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provideTemplates(): array {
		$templates = array(
			'single-bundle',
			'archive-bundle',
			'bundle-card',
			'bundle-courses',
			'bundle-progress',
			'dashboard/bundles',
			'dashboard/bundle-details',
		);

		$cases = array();

		foreach ( $templates as $template ) {
			$cases[ $template ] = array( $template );
		}

		return $cases;
	}

	/**
	 * The template path filter is the documented override point for themes.
	 */
	public function test_themes_can_override_a_template(): void {
		$custom = TCB_PATH . 'templates/bundle-card.php';

		add_filter(
			'tcb/bundle/template_path',
			static fn( $path, $template ) => 'bundle-courses' === $template ? $custom : $path,
			10,
			2
		);

		$resolved = tcb()->get( TemplateLoader::class )->locate( 'bundle-courses' );

		remove_all_filters( 'tcb/bundle/template_path' );

		$this->assertSame( $custom, $resolved );
	}

	/**
	 * The purchase state is what the single-bundle template renders. A visitor
	 * sees a price and a call to action; an owner sees neither.
	 */
	public function test_purchase_state_for_a_visitor_and_an_owner(): void {
		$this->require_tutor();

		$user  = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses( 2, array( 'title' => 'Purchasable' ) );

		$controller = tcb()->get( AccessController::class );

		$visitor = $controller->get_purchase_state( $built['bundle'], 0 );

		$this->assertTrue( $visitor['available'] );
		$this->assertFalse( $visitor['has_access'] );
		$this->assertTrue( $visitor['is_free'] );
		$this->assertNotSame( '', $visitor['purchase_url'], 'A free bundle needs an enrollment link.' );

		tcb()->get( EnrollmentService::class )
			->grant_access( $built['bundle'], $user, BundleAccess::SOURCE_MANUAL, 0 );

		$owner = $controller->get_purchase_state( $built['bundle'], $user );

		$this->assertTrue( $owner['has_access'] );
		$this->assertInstanceOf( BundleAccess::class, $owner['access'] );
	}

	/**
	 * Rendering the course list must not fatal for a logged-out visitor, and
	 * must not leak a direct course link they cannot use.
	 */
	public function test_course_list_renders_locked_for_visitors(): void {
		$built = $this->seeder->bundle_with_courses( 2, array( 'title' => 'Locked bundle' ) );

		$html = do_shortcode( '[tcb_bundle_courses id="' . $built['bundle'] . '"]' );

		$this->assertStringContainsString( 'tcb-course-list', $html );
		$this->assertStringContainsString( 'is-locked', $html, 'Visitors should see the courses as locked.' );
		$this->assertStringNotContainsString(
			'href="' . get_permalink( $built['courses'][0] ) . '"',
			$html,
			'A visitor should not get a direct link into a course.'
		);
	}

	/**
	 * An owner gets working links instead of padlocks.
	 */
	public function test_course_list_renders_links_for_owners(): void {
		$this->require_tutor();

		$user  = $this->seeder->student();
		$built = $this->seeder->bundle_with_courses( 2, array(), array( 'lessons' => 1 ) );

		tcb()->get( EnrollmentService::class )
			->grant_access( $built['bundle'], $user, BundleAccess::SOURCE_MANUAL, 0 );

		wp_set_current_user( $user );

		$html = do_shortcode( '[tcb_bundle_courses id="' . $built['bundle'] . '"]' );

		$this->assertStringNotContainsString( 'is-locked', $html );
		$this->assertStringContainsString( '<a href=', $html );
	}

	/**
	 * The catalogue shortcode renders one card per published bundle.
	 */
	public function test_the_grid_shortcode_lists_published_bundles(): void {
		$this->seeder->bundle_with_courses( 1, array( 'title' => 'Grid one' ) );
		$this->seeder->bundle_with_courses( 1, array( 'title' => 'Grid two' ) );
		$this->seeder->bundle( array( 'title' => 'Hidden draft', 'status' => 'draft' ) );

		$html = do_shortcode( '[tcb_bundle_grid count="10" columns="3"]' );

		$this->assertStringContainsString( 'Grid one', $html );
		$this->assertStringContainsString( 'Grid two', $html );
		$this->assertStringNotContainsString( 'Hidden draft', $html );
		$this->assertSame( 1, substr_count( $html, 'Grid one' ) );
		$this->assertSame( 1, substr_count( $html, 'Grid two' ) );
	}

	/**
	 * Logged-out visitors get a prompt rather than an empty page.
	 */
	public function test_my_bundles_prompts_anonymous_visitors(): void {
		wp_set_current_user( 0 );

		$html = do_shortcode( '[tcb_my_bundles]' );

		$this->assertStringContainsString( 'tcb-notice', $html );
	}

	/**
	 * A draft bundle renders nothing through the shortcode, so an unpublished
	 * product cannot be embedded on a public page.
	 */
	public function test_the_bundle_shortcode_ignores_drafts(): void {
		$bundle = $this->seeder->bundle( array( 'title' => 'Secret', 'status' => 'draft' ) );

		$this->assertSame( '', do_shortcode( '[tcb_bundle id="' . $bundle . '"]' ) );
	}

	/**
	 * An unknown ID is rendered as nothing rather than a warning.
	 */
	public function test_shortcodes_tolerate_unknown_ids(): void {
		$this->assertSame( '', do_shortcode( '[tcb_bundle id="999999"]' ) );
		$this->assertSame( '', do_shortcode( '[tcb_bundle_courses id="999999"]' ) );
		$this->assertSame( '', do_shortcode( '[tcb_bundle_progress id="999999"]' ) );
	}
}
