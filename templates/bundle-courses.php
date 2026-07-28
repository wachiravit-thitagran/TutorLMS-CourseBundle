<?php
/**
 * Course list inside a bundle.
 *
 * Override by copying to yourtheme/tutor-course-bundles/bundle-courses.php
 *
 * @package SpaceWork\TutorCourseBundles
 *
 * @var \SpaceWork\TutorCourseBundles\Domain\Bundle         $bundle     Bundle.
 * @var \SpaceWork\TutorCourseBundles\Domain\BundleCourse[] $courses    Membership rows.
 * @var \SpaceWork\TutorCourseBundles\Domain\BundleProgress $progress   Learner progress.
 * @var bool                                                $has_access Whether the learner owns the bundle.
 * @var bool                                                $show_lock  Whether to show lock icons.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( ! isset( $bundle, $courses ) || array() === $courses ) {
	return;
}

$has_access = isset( $has_access ) ? (bool) $has_access : false;
$show_lock  = isset( $show_lock ) ? (bool) $show_lock : true;

$course_progress = array();

if ( isset( $progress ) ) {
	foreach ( $progress->courses as $entry ) {
		$course_progress[ (int) $entry['course_id'] ] = $entry;
	}
}
?>
<section class="tcb-bundle-courses">
	<h2 class="tcb-section-title">
		<?php esc_html_e( 'Courses in this bundle', 'tutor-course-bundles' ); ?>
		<span class="tcb-section-count"><?php echo esc_html( (string) count( $courses ) ); ?></span>
	</h2>

	<ol class="tcb-course-list">
		<?php foreach ( $courses as $index => $course ) : ?>
			<?php
			$entry     = $course_progress[ $course->course_id ] ?? array();
			$completed = ! empty( $entry['completed'] );
			$enrolled  = ! empty( $entry['enrolled'] );
			$percent   = (float) ( $entry['percent'] ?? 0 );
			$locked    = $show_lock && ! $has_access;

			$state_class = $completed ? 'is-completed' : ( $enrolled ? 'is-active' : ( $locked ? 'is-locked' : '' ) );
			?>
			<li class="tcb-course-item <?php echo esc_attr( $state_class ); ?>">
				<span class="tcb-course-item__index"><?php echo esc_html( (string) ( $index + 1 ) ); ?></span>

				<span class="tcb-course-item__status" aria-hidden="true">
					<?php if ( $completed ) : ?>
						&#10003;
					<?php elseif ( $enrolled ) : ?>
						&#9654;
					<?php elseif ( $locked ) : ?>
						&#128274;
					<?php else : ?>
						&#9675;
					<?php endif; ?>
				</span>

				<span class="tcb-course-item__body">
					<span class="tcb-course-item__title">
						<?php if ( $has_access ) : ?>
							<a href="<?php echo esc_url( (string) ( $entry['continue_url'] ?? get_permalink( $course->course_id ) ) ); ?>">
								<?php echo esc_html( $course->get_title() ); ?>
							</a>
						<?php else : ?>
							<?php echo esc_html( $course->get_title() ); ?>
						<?php endif; ?>
					</span>

					<?php if ( ! $course->is_required ) : ?>
						<span class="tcb-course-item__tag"><?php esc_html_e( 'Optional', 'tutor-course-bundles' ); ?></span>
					<?php endif; ?>

					<?php if ( $has_access && $enrolled ) : ?>
						<span class="tcb-course-item__progress">
							<span class="tcb-progress-bar tcb-progress-bar--slim">
								<span class="tcb-progress-bar__fill" style="width: <?php echo esc_attr( (string) min( 100, max( 0, $percent ) ) ); ?>%;"></span>
							</span>
							<span class="tcb-course-item__percent"><?php echo esc_html( (string) (int) round( $percent ) ); ?>%</span>
						</span>
					<?php endif; ?>
				</span>
			</li>
		<?php endforeach; ?>
	</ol>
</section>
