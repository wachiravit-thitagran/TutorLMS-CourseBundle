<?php
/**
 * Single bundle page.
 *
 * Override by copying to yourtheme/tutor-course-bundles/single-bundle.php
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

get_header();

$tcb_bundle = Bundle::from( get_the_ID() );

if ( ! $tcb_bundle instanceof Bundle ) {
	get_footer();
	return;
}

$tcb_repo      = tcb()->get( BundleRepository::class );
$tcb_access    = tcb()->get( AccessController::class );
$tcb_templates = tcb()->get( TemplateLoader::class );

$tcb_user_id  = get_current_user_id();
$tcb_stats    = $tcb_repo->get_stats( $tcb_bundle->get_id() );
$tcb_courses  = $tcb_repo->get_courses( $tcb_bundle->get_id(), true );
$tcb_state    = $tcb_access->get_purchase_state( $tcb_bundle->get_id(), $tcb_user_id );
$tcb_progress = tcb()->get( ProgressController::class )->get_progress( $tcb_bundle->get_id(), $tcb_user_id );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$tcb_message = isset( $_GET['tcb_message'] ) ? sanitize_key( wp_unslash( $_GET['tcb_message'] ) ) : '';
?>
<div class="tcb-single-bundle-wrap">
	<?php
	/**
	 * Fires at the very top of the single bundle template.
	 *
	 * @param Bundle $tcb_bundle Bundle.
	 */
	do_action( 'tcb/template/single_bundle_before', $tcb_bundle );
	?>

	<?php if ( '' !== $tcb_message ) : ?>
		<?php $tcb_message_text = AccessController::get_message_text( $tcb_message ); ?>
		<?php if ( '' !== $tcb_message_text ) : ?>
			<div class="tcb-notice tcb-notice-<?php echo 'enrolled' === $tcb_message ? 'success' : 'info'; ?>">
				<?php echo esc_html( $tcb_message_text ); ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<header class="tcb-bundle-header">
		<div class="tcb-bundle-header__media">
			<?php if ( '' !== $tcb_bundle->get_thumbnail_url() ) : ?>
				<img
					src="<?php echo esc_url( $tcb_bundle->get_thumbnail_url() ); ?>"
					alt="<?php echo esc_attr( $tcb_bundle->get_title() ); ?>"
					class="tcb-bundle-header__image"
					loading="lazy"
				/>
			<?php endif; ?>
		</div>

		<div class="tcb-bundle-header__body">
			<h1 class="tcb-bundle-title"><?php echo esc_html( $tcb_bundle->get_title() ); ?></h1>

			<p class="tcb-bundle-excerpt"><?php echo esc_html( $tcb_bundle->get_short_description() ); ?></p>

			<ul class="tcb-bundle-meta">
				<li>
					<span class="tcb-meta-value"><?php echo esc_html( (string) $tcb_stats['course_count'] ); ?></span>
					<span class="tcb-meta-label"><?php esc_html_e( 'Courses', 'tutor-course-bundles' ); ?></span>
				</li>
				<li>
					<span class="tcb-meta-value"><?php echo esc_html( (string) $tcb_stats['lesson_count'] ); ?></span>
					<span class="tcb-meta-label"><?php esc_html_e( 'Lessons', 'tutor-course-bundles' ); ?></span>
				</li>
				<?php if ( $tcb_stats['quiz_count'] > 0 ) : ?>
					<li>
						<span class="tcb-meta-value"><?php echo esc_html( (string) $tcb_stats['quiz_count'] ); ?></span>
						<span class="tcb-meta-label"><?php esc_html_e( 'Quizzes', 'tutor-course-bundles' ); ?></span>
					</li>
				<?php endif; ?>
				<?php if ( '' !== $tcb_bundle->get_estimated_duration() ) : ?>
					<li>
						<span class="tcb-meta-value"><?php echo esc_html( $tcb_bundle->get_estimated_duration() ); ?></span>
						<span class="tcb-meta-label"><?php esc_html_e( 'Duration', 'tutor-course-bundles' ); ?></span>
					</li>
				<?php endif; ?>
			</ul>

			<?php if ( array() !== $tcb_stats['instructors'] ) : ?>
				<div class="tcb-bundle-instructors">
					<span class="tcb-bundle-instructors__label"><?php esc_html_e( 'Taught by', 'tutor-course-bundles' ); ?></span>
					<?php foreach ( $tcb_stats['instructors'] as $tcb_instructor ) : ?>
						<span class="tcb-instructor">
							<img src="<?php echo esc_url( $tcb_instructor['avatar'] ); ?>" alt="" width="32" height="32" loading="lazy" />
							<?php echo esc_html( $tcb_instructor['name'] ); ?>
						</span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<aside class="tcb-bundle-purchase">
			<?php if ( $tcb_state['has_access'] ) : ?>
				<div class="tcb-purchase-enrolled">
					<p class="tcb-enrolled-badge"><?php esc_html_e( 'You own this bundle', 'tutor-course-bundles' ); ?></p>

					<?php
					$tcb_templates->render(
						'bundle-progress',
						array(
							'bundle'   => $tcb_bundle,
							'progress' => $tcb_progress,
						)
					);
					?>

					<?php if ( null !== $tcb_state['expires_at'] ) : ?>
						<p class="tcb-expiry">
							<?php
							printf(
								/* translators: %s: expiry date */
								esc_html__( 'Access until %s', 'tutor-course-bundles' ),
								esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) $tcb_state['expires_at'] . ' UTC' ) ) )
							);
							?>
						</p>
					<?php endif; ?>

					<?php if ( null !== $tcb_progress->next_course_id ) : ?>
						<a class="tcb-button tcb-button--primary" href="<?php echo esc_url( (string) get_permalink( $tcb_progress->next_course_id ) ); ?>">
							<?php esc_html_e( 'Continue Learning', 'tutor-course-bundles' ); ?>
						</a>
					<?php endif; ?>

					<a class="tcb-button tcb-button--ghost" href="<?php echo esc_url( tcb()->get( DashboardIntegration::class )->get_dashboard_url( $tcb_bundle->get_id() ) ); ?>">
						<?php esc_html_e( 'Open in dashboard', 'tutor-course-bundles' ); ?>
					</a>
				</div>
			<?php else : ?>
				<div class="tcb-purchase-box">
					<?php if ( $tcb_state['is_free'] ) : ?>
						<p class="tcb-price tcb-price--free"><?php esc_html_e( 'Free', 'tutor-course-bundles' ); ?></p>
					<?php else : ?>
						<p class="tcb-price">
							<?php if ( null !== $tcb_state['sale_price'] ) : ?>
								<del><?php echo wp_kses_post( tcb_format_price( (float) $tcb_state['price'] ) ); ?></del>
								<ins><?php echo wp_kses_post( tcb_format_price( (float) $tcb_state['sale_price'] ) ); ?></ins>
							<?php else : ?>
								<?php echo wp_kses_post( tcb_format_price( (float) $tcb_state['price'] ) ); ?>
							<?php endif; ?>
						</p>
					<?php endif; ?>

					<?php if ( ! $tcb_state['available'] ) : ?>
						<p class="tcb-notice tcb-notice-info"><?php esc_html_e( 'This bundle is not available right now.', 'tutor-course-bundles' ); ?></p>
					<?php elseif ( '' !== $tcb_state['purchase_url'] ) : ?>
						<a class="tcb-button tcb-button--primary tcb-button--block" href="<?php echo esc_url( (string) $tcb_state['purchase_url'] ); ?>">
							<?php echo esc_html( (string) $tcb_state['button_label'] ); ?>
						</a>
					<?php else : ?>
						<p class="tcb-notice tcb-notice-info"><?php esc_html_e( 'Checkout is unavailable. Please contact the site owner.', 'tutor-course-bundles' ); ?></p>
					<?php endif; ?>

					<ul class="tcb-purchase-facts">
						<li><?php echo esc_html( sprintf( /* translators: %d: number of courses */ _n( '%d course included', '%d courses included', (int) $tcb_stats['course_count'], 'tutor-course-bundles' ), (int) $tcb_stats['course_count'] ) ); ?></li>
						<?php if ( $tcb_bundle->get_access_duration_days() > 0 ) : ?>
							<li><?php echo esc_html( sprintf( /* translators: %d: number of days */ _n( '%d day of access', '%d days of access', $tcb_bundle->get_access_duration_days(), 'tutor-course-bundles' ), $tcb_bundle->get_access_duration_days() ) ); ?></li>
						<?php else : ?>
							<li><?php esc_html_e( 'Lifetime access', 'tutor-course-bundles' ); ?></li>
						<?php endif; ?>
					</ul>
				</div>
			<?php endif; ?>
		</aside>
	</header>

	<div class="tcb-bundle-body">
		<div class="tcb-bundle-description">
			<?php echo wp_kses_post( $tcb_bundle->get_description() ); ?>
		</div>

		<?php
		$tcb_templates->render(
			'bundle-courses',
			array(
				'bundle'     => $tcb_bundle,
				'courses'    => $tcb_courses,
				'progress'   => $tcb_progress,
				'has_access' => (bool) $tcb_state['has_access'],
				'show_lock'  => true,
			)
		);
		?>
	</div>

	<?php
	/**
	 * Fires at the bottom of the single bundle template.
	 *
	 * @param Bundle $tcb_bundle Bundle.
	 */
	do_action( 'tcb/template/single_bundle_after', $tcb_bundle );
	?>
</div>
<?php
get_footer();
