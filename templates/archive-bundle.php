<?php
/**
 * Bundle archive.
 *
 * Override by copying to yourtheme/tutor-course-bundles/archive-bundle.php
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Frontend\AccessController;
use SpaceWork\TutorCourseBundles\Frontend\TemplateLoader;
use SpaceWork\TutorCourseBundles\Infrastructure\BundleRepository;

defined( 'ABSPATH' ) || exit;

get_header();

$tcb_repo      = tcb()->get( BundleRepository::class );
$tcb_access    = tcb()->get( AccessController::class );
$tcb_templates = tcb()->get( TemplateLoader::class );
?>
<div class="tcb-bundle-archive-wrap">
	<header class="tcb-archive-header">
		<h1 class="tcb-archive-title">
			<?php
			if ( is_tax() ) {
				single_term_title();
			} else {
				post_type_archive_title();
			}
			?>
		</h1>

		<?php if ( is_tax() && '' !== term_description() ) : ?>
			<div class="tcb-archive-description"><?php echo wp_kses_post( term_description() ); ?></div>
		<?php endif; ?>
	</header>

	<?php if ( have_posts() ) : ?>
		<div class="tcb-bundle-grid tcb-columns-3">
			<?php
			while ( have_posts() ) :
				the_post();

				$tcb_bundle = Bundle::from( get_the_ID() );

				if ( ! $tcb_bundle instanceof Bundle ) {
					continue;
				}

				$tcb_templates->render(
					'bundle-card',
					array(
						'bundle'   => $tcb_bundle,
						'stats'    => $tcb_repo->get_stats( $tcb_bundle->get_id() ),
						'state'    => $tcb_access->get_purchase_state( $tcb_bundle->get_id() ),
						'style'    => 'grid',
						'show_cta' => true,
					)
				);
			endwhile;
			?>
		</div>

		<nav class="tcb-pagination">
			<?php
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'prev_text' => __( '&larr; Previous', 'tutor-course-bundles' ),
						'next_text' => __( 'Next &rarr;', 'tutor-course-bundles' ),
					)
				)
			);
			?>
		</nav>
	<?php else : ?>
		<p class="tcb-notice"><?php esc_html_e( 'No bundles found.', 'tutor-course-bundles' ); ?></p>
	<?php endif; ?>
</div>
<?php
get_footer();
