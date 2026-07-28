<?php
/**
 * Student dashboard: a single bundle.
 *
 * Override by copying to yourtheme/tutor-course-bundles/dashboard/bundle-details.php
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Frontend\AccessController;
use SpaceWork\TutorCourseBundles\Frontend\ProgressController;
use SpaceWork\TutorCourseBundles\Frontend\TemplateLoader;
use SpaceWork\TutorCourseBundles\Infrastructure\BundleRepository;
use SpaceWork\TutorCourseBundles\Tutor\DashboardIntegration;

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$tcb_bundle_id = isset( $_GET['bundle_id'] ) ? absint( wp_unslash( $_GET['bundle_id'] ) ) : 0;
$tcb_user_id   = get_current_user_id();
$tcb_dashboard = tcb()->get( DashboardIntegration::class );

if ( $tcb_user_id <= 0 || $tcb_bundle_id <= 0 ) {
	echo '<p class="tcb-notice">' . esc_html__( 'Bundle not found.', 'tutor-course-bundles' ) . '</p>';
	return;
}

$tcb_bundle = Bundle::from( $tcb_bundle_id );

if ( ! $tcb_bundle instanceof Bundle ) {
	echo '<p class="tcb-notice">' . esc_html__( 'Bundle not found.', 'tutor-course-bundles' ) . '</p>';
	return;
}

$tcb_access_controller = tcb()->get( AccessController::class );

if ( ! $tcb_access_controller->user_has_access( $tcb_bundle_id, $tcb_user_id ) ) {
	echo '<p class="tcb-notice">' . esc_html__( 'You do not have access to this bundle.', 'tutor-course-bundles' ) . '</p>';
	return;
}

$tcb_repo      = tcb()->get( BundleRepository::class );
$tcb_templates = tcb()->get( TemplateLoader::class );
$tcb_progress  = tcb()->get( ProgressController::class )->get_progress( $tcb_bundle_id, $tcb_user_id );
$tcb_entitle   = $tcb_access_controller->get_active_access( $tcb_bundle_id, $tcb_user_id );
?>
<div class="tcb-dashboard tcb-dashboard--bundle-details">
	<p class="tcb-breadcrumb">
		<a href="<?php echo esc_url( $tcb_dashboard->get_dashboard_url() ); ?>">&larr; <?php esc_html_e( 'All bundles', 'tutor-course-bundles' ); ?></a>
	</p>

	<h2 class="tcb-dashboard__title"><?php echo esc_html( $tcb_bundle->get_title() ); ?></h2>

	<?php
	$tcb_templates->render(
		'bundle-progress',
		array(
			'bundle'   => $tcb_bundle,
			'progress' => $tcb_progress,
		)
	);
	?>

	<?php if ( null !== $tcb_entitle && null !== $tcb_entitle->expires_at ) : ?>
		<p class="tcb-expiry">
			<?php
			printf(
				/* translators: %s: expiry date */
				esc_html__( 'Your access expires on %s.', 'tutor-course-bundles' ),
				esc_html( date_i18n( get_option( 'date_format' ), strtotime( $tcb_entitle->expires_at . ' UTC' ) ) )
			);
			?>
		</p>
	<?php endif; ?>

	<?php
	$tcb_templates->render(
		'bundle-courses',
		array(
			'bundle'     => $tcb_bundle,
			'courses'    => $tcb_repo->get_courses( $tcb_bundle_id, true ),
			'progress'   => $tcb_progress,
			'has_access' => true,
			'show_lock'  => false,
		)
	);
	?>

	<?php if ( null !== $tcb_progress->next_course_id ) : ?>
		<p class="tcb-dashboard__cta">
			<a class="tcb-button tcb-button--primary" href="<?php echo esc_url( (string) get_permalink( $tcb_progress->next_course_id ) ); ?>">
				<?php esc_html_e( 'Continue Learning', 'tutor-course-bundles' ); ?>
			</a>
		</p>
	<?php endif; ?>
</div>
