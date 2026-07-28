<?php
/**
 * Bundle reporting and CSV export.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Admin;

use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Frontend\ProgressController;
use SpaceWork\TutorCourseBundles\Infrastructure\AccessRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\BundleRepository;
use SpaceWork\TutorCourseBundles\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Answers the three questions a course seller actually asks: how many people
 * bought it, how many finished it, and where do they get stuck.
 */
final class Reports {

	private const NONCE_ACTION = 'tcb_reports';

	/**
	 * Constructor.
	 *
	 * @param BundleRepository $bundles Bundle repository.
	 * @param AccessRepository $access  Entitlement repository.
	 */
	public function __construct(
		private readonly BundleRepository $bundles,
		private readonly AccessRepository $access
	) {}

	/**
	 * Register admin hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_tcb_export_report', array( $this, 'handle_export' ) );
	}

	/**
	 * Aggregate figures for one bundle.
	 *
	 * @param int $bundle_id Bundle post ID.
	 * @return array<string, mixed>
	 */
	public function get_bundle_report( int $bundle_id ): array {
		$counts        = $this->access->count_by_status( $bundle_id );
		$learners      = $this->access->count_active_learners( $bundle_id );
		$progress      = tcb()->get( ProgressController::class );
		$completed     = 0;
		$started       = 0;
		$percent_total = 0.0;
		$stuck         = array();
		$seen_users    = array();
		$sample        = 0;

		foreach ( $this->access->iterate(
			array(
				'bundle_id' => $bundle_id,
				'status'    => BundleAccess::STATUS_ACTIVE,
			)
		) as $access ) {
			if ( ! $access->is_usable() || isset( $seen_users[ $access->user_id ] ) ) {
				continue;
			}

			$seen_users[ $access->user_id ] = true;
			++$sample;
			$user_progress  = $progress->get_progress( $bundle_id, $access->user_id );
			$percent_total += $user_progress->percent;

			if ( $user_progress->is_complete() ) {
				++$completed;
			}

			if ( $user_progress->percent > 0 ) {
				++$started;
			}

			if ( null !== $user_progress->next_course_id && ! $user_progress->is_complete() ) {
				$key           = (int) $user_progress->next_course_id;
				$stuck[ $key ] = ( $stuck[ $key ] ?? 0 ) + 1;
			}
		}

		arsort( $stuck );

		$revenue = $this->get_revenue( $bundle_id );

		return array(
			'bundle_id'        => $bundle_id,
			'title'            => get_the_title( $bundle_id ),
			'active_learners'  => $learners,
			'total_grants'     => array_sum( $counts ),
			'status_counts'    => $counts,
			'started'          => $started,
			'completed'        => $completed,
			'completion_rate'  => $sample > 0 ? round( ( $completed / $sample ) * 100, 1 ) : 0.0,
			'average_progress' => $sample > 0 ? round( $percent_total / $sample, 1 ) : 0.0,
			'revenue'          => $revenue['net'],
			'gross_revenue'    => $revenue['gross'],
			'refunded_revenue' => $revenue['refunded'],
			'net_revenue'      => $revenue['net'],
			'drop_off_courses' => array_slice( $stuck, 0, 5, true ),
			'sample_size'      => $sample,
		);
	}

	/**
	 * Gross revenue for a bundle, when WooCommerce is available.
	 *
	 * @param int $bundle_id Bundle post ID.
	 */
	private function get_revenue( int $bundle_id ): array {
		$gateway = tcb()->get( \SpaceWork\TutorCourseBundles\Commerce\WooCommerceGateway::class );

		return $gateway->get_revenue_summary( $bundle_id );
	}

	/**
	 * Stream a CSV of entitlements.
	 */
	public function handle_export(): void {
		if ( ! current_user_can( Capabilities::VIEW_REPORTS ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export reports.', 'tutor-course-bundles' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$bundle_id = isset( $_POST['bundle_id'] ) ? absint( wp_unslash( $_POST['bundle_id'] ) ) : 0;

		$filename = sprintf( 'tcb-enrollments-%s.csv', gmdate( 'Y-m-d' ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$output = fopen( 'php://output', 'w' );

		if ( false === $output ) {
			exit;
		}

		fputcsv(
			$output,
			array(
				__( 'Access ID', 'tutor-course-bundles' ),
				__( 'Bundle', 'tutor-course-bundles' ),
				__( 'Learner', 'tutor-course-bundles' ),
				__( 'Email', 'tutor-course-bundles' ),
				__( 'Source', 'tutor-course-bundles' ),
				__( 'Source ID', 'tutor-course-bundles' ),
				__( 'Status', 'tutor-course-bundles' ),
				__( 'Granted at', 'tutor-course-bundles' ),
				__( 'Expires at', 'tutor-course-bundles' ),
				__( 'Progress %', 'tutor-course-bundles' ),
			)
		);

		$progress = tcb()->get( ProgressController::class );

		foreach ( $this->access->iterate( array( 'bundle_id' => $bundle_id ) ) as $access ) {
			$user = get_userdata( $access->user_id );

			fputcsv(
				$output,
				array(
					$access->id,
					get_the_title( $access->bundle_id ),
					$user instanceof \WP_User ? $user->display_name : '',
					$user instanceof \WP_User ? $user->user_email : '',
					$access->source_type,
					$access->source_id,
					$access->status,
					(string) $access->granted_at,
					(string) $access->expires_at,
					$progress->get_progress( $access->bundle_id, $access->user_id )->get_display_percent(),
				)
			);
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing php://output.
		exit;
	}

	/**
	 * Render the reports screen.
	 */
	public function render_page(): void {
		if ( ! current_user_can( Capabilities::VIEW_REPORTS ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'tutor-course-bundles' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bundle_id = isset( $_GET['bundle_id'] ) ? absint( wp_unslash( $_GET['bundle_id'] ) ) : 0;

		$all_bundles = $this->bundles->query(
			array(
				'posts_per_page' => 200,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( 0 === $bundle_id && array() !== $all_bundles ) {
			$bundle_id = $all_bundles[0]->get_id();
		}

		$report = $bundle_id > 0 ? $this->get_bundle_report( $bundle_id ) : null;
		?>
		<div class="wrap tcb-admin-page">
			<h1><?php esc_html_e( 'Bundle Reports', 'tutor-course-bundles' ); ?></h1>

			<form method="get" class="tcb-filter-bar">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_REPORTS ); ?>" />
				<label>
					<?php esc_html_e( 'Bundle', 'tutor-course-bundles' ); ?><br />
					<select name="bundle_id" onchange="this.form.submit()">
						<?php foreach ( $all_bundles as $bundle ) : ?>
							<option value="<?php echo esc_attr( (string) $bundle->get_id() ); ?>" <?php selected( $bundle_id, $bundle->get_id() ); ?>>
								<?php echo esc_html( $bundle->get_title() ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
			</form>

			<?php if ( null === $report ) : ?>
				<p><?php esc_html_e( 'Create a bundle to see reports here.', 'tutor-course-bundles' ); ?></p>
			<?php else : ?>
				<div class="tcb-stat-grid">
					<div class="tcb-stat-card">
						<span class="tcb-stat-card__value"><?php echo esc_html( (string) $report['active_learners'] ); ?></span>
						<span class="tcb-stat-card__label"><?php esc_html_e( 'Active learners', 'tutor-course-bundles' ); ?></span>
					</div>
					<div class="tcb-stat-card">
						<span class="tcb-stat-card__value"><?php echo esc_html( (string) $report['total_grants'] ); ?></span>
						<span class="tcb-stat-card__label"><?php esc_html_e( 'Total grants', 'tutor-course-bundles' ); ?></span>
					</div>
					<div class="tcb-stat-card">
						<span class="tcb-stat-card__value"><?php echo esc_html( (string) $report['completed'] ); ?></span>
						<span class="tcb-stat-card__label"><?php esc_html_e( 'Completed', 'tutor-course-bundles' ); ?></span>
					</div>
					<div class="tcb-stat-card">
						<span class="tcb-stat-card__value"><?php echo esc_html( (string) $report['completion_rate'] ); ?>%</span>
						<span class="tcb-stat-card__label"><?php esc_html_e( 'Completion rate', 'tutor-course-bundles' ); ?></span>
					</div>
					<div class="tcb-stat-card">
						<span class="tcb-stat-card__value"><?php echo esc_html( (string) $report['average_progress'] ); ?>%</span>
						<span class="tcb-stat-card__label"><?php esc_html_e( 'Average progress', 'tutor-course-bundles' ); ?></span>
					</div>
					<div class="tcb-stat-card">
						<span class="tcb-stat-card__value"><?php echo wp_kses_post( tcb_format_price( (float) $report['gross_revenue'] ) ); ?></span>
						<span class="tcb-stat-card__label"><?php esc_html_e( 'Gross revenue', 'tutor-course-bundles' ); ?></span>
					</div>
					<div class="tcb-stat-card">
						<span class="tcb-stat-card__value"><?php echo wp_kses_post( tcb_format_price( (float) $report['refunded_revenue'] ) ); ?></span>
						<span class="tcb-stat-card__label"><?php esc_html_e( 'Refunded', 'tutor-course-bundles' ); ?></span>
					</div>
					<div class="tcb-stat-card">
						<span class="tcb-stat-card__value"><?php echo wp_kses_post( tcb_format_price( (float) $report['net_revenue'] ) ); ?></span>
						<span class="tcb-stat-card__label"><?php esc_html_e( 'Net revenue', 'tutor-course-bundles' ); ?></span>
					</div>
				</div>

				<h2><?php esc_html_e( 'Where learners stop', 'tutor-course-bundles' ); ?></h2>

				<?php if ( array() === $report['drop_off_courses'] ) : ?>
					<p><?php esc_html_e( 'No drop-off data yet.', 'tutor-course-bundles' ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Course', 'tutor-course-bundles' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Learners currently here', 'tutor-course-bundles' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $report['drop_off_courses'] as $course_id => $count ) : ?>
								<tr>
									<td><?php echo esc_html( get_the_title( (int) $course_id ) ); ?></td>
									<td><?php echo esc_html( (string) $count ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Export', 'tutor-course-bundles' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action" value="tcb_export_report" />
					<input type="hidden" name="bundle_id" value="<?php echo esc_attr( (string) $bundle_id ); ?>" />
					<?php submit_button( __( 'Download CSV', 'tutor-course-bundles' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
