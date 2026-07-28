<?php
/**
 * Bundle progress bar.
 *
 * Override by copying to yourtheme/tutor-course-bundles/bundle-progress.php
 *
 * @package SpaceWork\TutorCourseBundles
 *
 * @var \SpaceWork\TutorCourseBundles\Domain\Bundle         $bundle   Bundle.
 * @var \SpaceWork\TutorCourseBundles\Domain\BundleProgress $progress Learner progress.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( ! isset( $progress ) ) {
	return;
}

$percent = $progress->get_display_percent();
?>
<div class="tcb-bundle-progress tcb-bundle-progress--<?php echo esc_attr( $progress->status ); ?>">
	<div class="tcb-bundle-progress__header">
		<span class="tcb-bundle-progress__label"><?php echo esc_html( $progress->get_status_label() ); ?></span>
		<span class="tcb-bundle-progress__value"><?php echo esc_html( (string) $percent ); ?>%</span>
	</div>

	<div
		class="tcb-progress-bar"
		role="progressbar"
		aria-valuenow="<?php echo esc_attr( (string) $percent ); ?>"
		aria-valuemin="0"
		aria-valuemax="100"
		aria-label="<?php esc_attr_e( 'Bundle completion', 'tutor-course-bundles' ); ?>"
	>
		<span class="tcb-progress-bar__fill" style="width: <?php echo esc_attr( (string) $percent ); ?>%;"></span>
	</div>

	<p class="tcb-bundle-progress__summary">
		<?php
		printf(
			/* translators: 1: completed course count, 2: total course count */
			esc_html__( '%1$d of %2$d courses completed', 'tutor-course-bundles' ),
			(int) $progress->completed_count,
			(int) $progress->total_count
		);
		?>
	</p>
</div>
