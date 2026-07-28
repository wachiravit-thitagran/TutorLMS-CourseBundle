<?php
/**
 * Student dashboard: My Bundles.
 *
 * Override by copying to yourtheme/tutor-course-bundles/dashboard/bundles.php
 *
 * @package SpaceWork\TutorCourseBundles
 *
 * @var array<int, array<string, mixed>>|null $items   Pre-built items (shortcode path).
 * @var int|null                              $user_id User ID.
 */

declare( strict_types = 1 );

use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Tutor\DashboardIntegration;

defined( 'ABSPATH' ) || exit;

$tcb_dashboard = tcb()->get( DashboardIntegration::class );
$tcb_user_id   = isset( $user_id ) ? (int) $user_id : get_current_user_id();

if ( $tcb_user_id <= 0 ) {
	echo '<p class="tcb-notice">' . esc_html__( 'Please log in to see your bundles.', 'tutor-course-bundles' ) . '</p>';
	return;
}

$tcb_items = isset( $items ) && is_array( $items ) ? $items : $tcb_dashboard->get_dashboard_items( $tcb_user_id );
?>
<div class="tcb-dashboard tcb-dashboard--bundles">
	<h2 class="tcb-dashboard__title"><?php esc_html_e( 'My Bundles', 'tutor-course-bundles' ); ?></h2>

	<?php if ( array() === $tcb_items ) : ?>
		<p class="tcb-notice"><?php esc_html_e( 'You have not enrolled in any bundles yet.', 'tutor-course-bundles' ); ?></p>
	<?php else : ?>
		<div class="tcb-dashboard__list">
			<?php foreach ( $tcb_items as $tcb_item ) : ?>
				<?php
				$tcb_bundle   = $tcb_item['bundle'];
				$tcb_access   = $tcb_item['access'];
				$tcb_progress = $tcb_item['progress'];
				$tcb_percent  = $tcb_progress->get_display_percent();
				?>
				<article class="tcb-dashboard-item tcb-dashboard-item--<?php echo esc_attr( $tcb_progress->status ); ?>">
					<div class="tcb-dashboard-item__media">
						<?php if ( '' !== $tcb_bundle->get_thumbnail_url( 'medium' ) ) : ?>
							<img src="<?php echo esc_url( $tcb_bundle->get_thumbnail_url( 'medium' ) ); ?>" alt="" loading="lazy" />
						<?php endif; ?>
					</div>

					<div class="tcb-dashboard-item__body">
						<h3 class="tcb-dashboard-item__title">
							<a href="<?php echo esc_url( $tcb_dashboard->get_dashboard_url( $tcb_bundle->get_id() ) ); ?>">
								<?php echo esc_html( $tcb_bundle->get_title() ); ?>
							</a>
						</h3>

						<p class="tcb-dashboard-item__meta">
							<span class="tcb-badge tcb-badge--<?php echo esc_attr( $tcb_access->status ); ?>">
								<?php echo esc_html( $tcb_access->get_status_label() ); ?>
							</span>

							<span>
								<?php
								printf(
									/* translators: 1: completed courses, 2: total courses */
									esc_html__( '%1$d / %2$d courses', 'tutor-course-bundles' ),
									(int) $tcb_progress->completed_count,
									(int) $tcb_progress->total_count
								);
								?>
							</span>

							<?php if ( null !== $tcb_access->expires_at ) : ?>
								<span>
									<?php
									printf(
										/* translators: %s: expiry date */
										esc_html__( 'Expires %s', 'tutor-course-bundles' ),
										esc_html( date_i18n( get_option( 'date_format' ), strtotime( $tcb_access->expires_at . ' UTC' ) ) )
									);
									?>
								</span>
							<?php endif; ?>
						</p>

						<div class="tcb-progress-bar" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) $tcb_percent ); ?>" aria-valuemin="0" aria-valuemax="100">
							<span class="tcb-progress-bar__fill" style="width: <?php echo esc_attr( (string) $tcb_percent ); ?>%;"></span>
						</div>
					</div>

					<div class="tcb-dashboard-item__actions">
						<?php if ( $tcb_progress->is_complete() ) : ?>
							<span class="tcb-badge tcb-badge--completed"><?php esc_html_e( 'Completed', 'tutor-course-bundles' ); ?></span>
						<?php elseif ( BundleAccess::STATUS_ACTIVE === $tcb_access->status && null !== $tcb_progress->next_course_id ) : ?>
							<a class="tcb-button tcb-button--primary" href="<?php echo esc_url( (string) get_permalink( $tcb_progress->next_course_id ) ); ?>">
								<?php esc_html_e( 'Continue Learning', 'tutor-course-bundles' ); ?>
							</a>
						<?php endif; ?>

						<a class="tcb-button tcb-button--ghost" href="<?php echo esc_url( $tcb_dashboard->get_dashboard_url( $tcb_bundle->get_id() ) ); ?>">
							<?php esc_html_e( 'Details', 'tutor-course-bundles' ); ?>
						</a>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
