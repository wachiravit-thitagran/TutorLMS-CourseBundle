<?php
/**
 * Bundle card, used by the grid, archive and [tcb_bundle] shortcode.
 *
 * Override by copying to yourtheme/tutor-course-bundles/bundle-card.php
 *
 * @package SpaceWork\TutorCourseBundles
 *
 * @var \SpaceWork\TutorCourseBundles\Domain\Bundle $bundle   Bundle.
 * @var array<string, mixed>                        $stats    Aggregate stats.
 * @var array<string, mixed>                        $state    Purchase state.
 * @var string                                      $style    Style modifier.
 * @var bool                                        $show_cta Whether to render the button.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( ! isset( $bundle ) ) {
	return;
}

$style    = isset( $style ) ? (string) $style : 'card';
$show_cta = isset( $show_cta ) ? (bool) $show_cta : true;
$stats    = isset( $stats ) && is_array( $stats ) ? $stats : array(
	'course_count' => 0,
	'lesson_count' => 0,
);
$state    = isset( $state ) && is_array( $state ) ? $state : array();
?>
<article class="tcb-bundle-card tcb-bundle-card--<?php echo esc_attr( $style ); ?>">
	<a class="tcb-bundle-card__media" href="<?php echo esc_url( $bundle->get_permalink() ); ?>">
		<?php if ( '' !== $bundle->get_thumbnail_url( 'medium_large' ) ) : ?>
			<img
				src="<?php echo esc_url( $bundle->get_thumbnail_url( 'medium_large' ) ); ?>"
				alt="<?php echo esc_attr( $bundle->get_title() ); ?>"
				loading="lazy"
			/>
		<?php else : ?>
			<span class="tcb-bundle-card__placeholder" aria-hidden="true"></span>
		<?php endif; ?>

		<span class="tcb-bundle-card__badge">
			<?php
			printf(
				/* translators: %d: number of courses */
				esc_html( _n( '%d course', '%d courses', (int) $stats['course_count'], 'tutor-course-bundles' ) ),
				(int) $stats['course_count']
			);
			?>
		</span>
	</a>

	<div class="tcb-bundle-card__body">
		<h3 class="tcb-bundle-card__title">
			<a href="<?php echo esc_url( $bundle->get_permalink() ); ?>"><?php echo esc_html( $bundle->get_title() ); ?></a>
		</h3>

		<p class="tcb-bundle-card__excerpt"><?php echo esc_html( $bundle->get_short_description() ); ?></p>

		<div class="tcb-bundle-card__meta">
			<span><?php echo esc_html( sprintf( /* translators: %d: number of lessons */ _n( '%d lesson', '%d lessons', (int) ( $stats['lesson_count'] ?? 0 ), 'tutor-course-bundles' ), (int) ( $stats['lesson_count'] ?? 0 ) ) ); ?></span>
			<?php if ( '' !== $bundle->get_estimated_duration() ) : ?>
				<span><?php echo esc_html( $bundle->get_estimated_duration() ); ?></span>
			<?php endif; ?>
		</div>
	</div>

	<footer class="tcb-bundle-card__footer">
		<div class="tcb-bundle-card__price">
			<?php if ( $bundle->is_free() ) : ?>
				<span class="tcb-price tcb-price--free"><?php esc_html_e( 'Free', 'tutor-course-bundles' ); ?></span>
			<?php elseif ( null !== $bundle->get_sale_price() ) : ?>
				<del><?php echo wp_kses_post( tcb_format_price( $bundle->get_price() ) ); ?></del>
				<ins><?php echo wp_kses_post( tcb_format_price( (float) $bundle->get_sale_price() ) ); ?></ins>
			<?php else : ?>
				<span><?php echo wp_kses_post( tcb_format_price( $bundle->get_price() ) ); ?></span>
			<?php endif; ?>
		</div>

		<?php if ( $show_cta ) : ?>
			<?php if ( ! empty( $state['has_access'] ) ) : ?>
				<a class="tcb-button tcb-button--ghost" href="<?php echo esc_url( $bundle->get_permalink() ); ?>">
					<?php esc_html_e( 'Continue', 'tutor-course-bundles' ); ?>
				</a>
			<?php elseif ( ! empty( $state['purchase_url'] ) ) : ?>
				<a class="tcb-button tcb-button--primary" href="<?php echo esc_url( (string) $state['purchase_url'] ); ?>">
					<?php echo esc_html( (string) ( $state['button_label'] ?? __( 'View Bundle', 'tutor-course-bundles' ) ) ); ?>
				</a>
			<?php else : ?>
				<a class="tcb-button tcb-button--ghost" href="<?php echo esc_url( $bundle->get_permalink() ); ?>">
					<?php esc_html_e( 'View Bundle', 'tutor-course-bundles' ); ?>
				</a>
			<?php endif; ?>
		<?php endif; ?>
	</footer>
</article>
