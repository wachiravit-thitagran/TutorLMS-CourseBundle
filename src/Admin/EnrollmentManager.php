<?php
/**
 * Manual enrollment and entitlement management.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Admin;

use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Infrastructure\AccessRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\BundleRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\PostTypes;
use SpaceWork\TutorCourseBundles\Support\Capabilities;
use SpaceWork\TutorCourseBundles\Tutor\EnrollmentService;

defined( 'ABSPATH' ) || exit;

/**
 * The screen an admin lands on when a learner says "I paid but I can't get in".
 *
 * Lists every entitlement with its source, lets staff grant access by hand, and
 * exposes the repair tool that retries enrollments that failed in the
 * background.
 */
final class EnrollmentManager {

	private const NONCE_ACTION = 'tcb_manage_enrollments';
	private const PER_PAGE     = 25;

	/**
	 * Constructor.
	 *
	 * @param BundleRepository  $bundles     Bundle repository.
	 * @param AccessRepository  $access      Entitlement repository.
	 * @param EnrollmentService $enrollments Enrollment engine.
	 */
	public function __construct(
		private readonly BundleRepository $bundles,
		private readonly AccessRepository $access,
		private readonly EnrollmentService $enrollments
	) {}

	/**
	 * Register admin hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_tcb_grant_access', array( $this, 'handle_grant' ) );
		add_action( 'admin_post_tcb_revoke_access', array( $this, 'handle_revoke' ) );
		add_action( 'admin_post_tcb_repair_enrollments', array( $this, 'handle_repair' ) );
	}

	/**
	 * Guard used by every write action on this screen.
	 */
	private function assert_permission(): void {
		if ( ! current_user_can( Capabilities::ENROLL_STUDENTS ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage bundle enrollments.', 'tutor-course-bundles' ) );
		}

		check_admin_referer( self::NONCE_ACTION );
	}

	/**
	 * Grant a bundle to one or more learners.
	 */
	public function handle_grant(): void {
		$this->assert_permission();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$bundle_id = isset( $_POST['bundle_id'] ) ? absint( wp_unslash( $_POST['bundle_id'] ) ) : 0;
		$raw_users = isset( $_POST['user_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['user_ids'] ) ) : '';
		$expires   = isset( $_POST['expires_at'] ) ? sanitize_text_field( wp_unslash( $_POST['expires_at'] ) ) : '';
		$note      = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$user_ids = $this->parse_users( $raw_users );
		$granted  = 0;
		$failed   = 0;

		foreach ( $user_ids as $user_id ) {
			$result = $this->enrollments->grant_access(
				$bundle_id,
				$user_id,
				BundleAccess::SOURCE_MANUAL,
				0,
				array(
					'expires_at' => '' === $expires ? null : gmdate( 'Y-m-d H:i:s', strtotime( $expires ) ? strtotime( $expires ) : time() ),
					'note'       => $note,
				)
			);

			if ( is_wp_error( $result ) ) {
				++$failed;
			} else {
				++$granted;
			}
		}

		$this->redirect_back(
			array(
				'granted' => $granted,
				'failed'  => $failed,
			)
		);
	}

	/**
	 * Revoke a single entitlement.
	 */
	public function handle_revoke(): void {
		$this->assert_permission();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$access_id = isset( $_POST['access_id'] ) ? absint( wp_unslash( $_POST['access_id'] ) ) : 0;

		if ( $access_id > 0 ) {
			$this->enrollments->revoke_access( $access_id, BundleAccess::STATUS_REVOKED, 'admin_revoked' );
		}

		$this->redirect_back( array( 'revoked' => 1 ) );
	}

	/**
	 * Retry failed background enrollments.
	 */
	public function handle_repair(): void {
		$this->assert_permission();

		$result = $this->enrollments->repair_failed_enrollments( 100 );

		$this->redirect_back(
			array(
				'repaired' => $result['repaired'],
				'failed'   => $result['failed'],
			)
		);
	}

	/**
	 * Turn a comma-separated list of IDs, logins or emails into user IDs.
	 *
	 * @param string $raw Raw input.
	 * @return int[]
	 */
	private function parse_users( string $raw ): array {
		$parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		$ids   = array();

		foreach ( $parts as $part ) {
			if ( ctype_digit( $part ) ) {
				$user = get_userdata( (int) $part );
			} elseif ( is_email( $part ) ) {
				$user = get_user_by( 'email', $part );
			} else {
				$user = get_user_by( 'login', $part );
			}

			if ( $user instanceof \WP_User ) {
				$ids[ $user->ID ] = (int) $user->ID;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Redirect back to the screen with a result summary.
	 *
	 * @param array<string, int> $args Query args.
	 */
	private function redirect_back( array $args ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- redirect only.
		$bundle_id = isset( $_POST['bundle_id'] ) ? absint( wp_unslash( $_POST['bundle_id'] ) ) : 0;

		$url = add_query_arg(
			array_merge(
				array(
					'page'      => Menu::PAGE_ENROLL,
					'bundle_id' => $bundle_id,
				),
				$args
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render the enrollments screen.
	 */
	public function render_page(): void {
		if ( ! current_user_can( Capabilities::ENROLL_STUDENTS ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'tutor-course-bundles' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$bundle_id = isset( $_GET['bundle_id'] ) ? absint( wp_unslash( $_GET['bundle_id'] ) ) : 0;
		$status    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$paged     = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$granted   = isset( $_GET['granted'] ) ? absint( wp_unslash( $_GET['granted'] ) ) : 0;
		$repaired  = isset( $_GET['repaired'] ) ? absint( wp_unslash( $_GET['repaired'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = $this->access->query(
			array(
				'bundle_id' => $bundle_id,
				'status'    => $status,
				'limit'     => self::PER_PAGE,
				'offset'    => ( $paged - 1 ) * self::PER_PAGE,
			)
		);

		$all_bundles = $this->bundles->query(
			array(
				'posts_per_page' => 200,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<div class="wrap tcb-admin-page">
			<h1><?php esc_html_e( 'Bundle Enrollments', 'tutor-course-bundles' ); ?></h1>

			<?php if ( $granted > 0 ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %d: number of learners */
							esc_html( _n( 'Granted access to %d learner.', 'Granted access to %d learners.', $granted, 'tutor-course-bundles' ) ),
							(int) $granted
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $repaired > 0 ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %d: number of enrollments */
							esc_html( _n( 'Repaired %d enrollment.', 'Repaired %d enrollments.', $repaired, 'tutor-course-bundles' ) ),
							(int) $repaired
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="tcb-inline-form">
				<h2><?php esc_html_e( 'Grant access manually', 'tutor-course-bundles' ); ?></h2>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action" value="tcb_grant_access" />

					<p>
						<label for="tcb-grant-bundle"><strong><?php esc_html_e( 'Bundle', 'tutor-course-bundles' ); ?></strong></label><br />
						<select name="bundle_id" id="tcb-grant-bundle" required>
							<option value=""><?php esc_html_e( '— Select a bundle —', 'tutor-course-bundles' ); ?></option>
							<?php foreach ( $all_bundles as $bundle ) : ?>
								<option value="<?php echo esc_attr( (string) $bundle->get_id() ); ?>" <?php selected( $bundle_id, $bundle->get_id() ); ?>>
									<?php echo esc_html( $bundle->get_title() ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</p>

					<p>
						<label for="tcb-grant-users"><strong><?php esc_html_e( 'Learners', 'tutor-course-bundles' ); ?></strong></label><br />
						<input type="text" class="large-text" id="tcb-grant-users" name="user_ids" placeholder="<?php esc_attr_e( 'user IDs, logins or emails, comma separated', 'tutor-course-bundles' ); ?>" required />
					</p>

					<p>
						<label for="tcb-grant-expires"><strong><?php esc_html_e( 'Expires on', 'tutor-course-bundles' ); ?></strong></label><br />
						<input type="date" id="tcb-grant-expires" name="expires_at" />
						<span class="description"><?php esc_html_e( 'Leave empty for lifetime access.', 'tutor-course-bundles' ); ?></span>
					</p>

					<p>
						<label for="tcb-grant-note"><strong><?php esc_html_e( 'Note', 'tutor-course-bundles' ); ?></strong></label><br />
						<textarea id="tcb-grant-note" name="note" rows="2" class="large-text"></textarea>
					</p>

					<?php submit_button( __( 'Grant access', 'tutor-course-bundles' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<form method="get" class="tcb-filter-bar">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_ENROLL ); ?>" />

				<label>
					<?php esc_html_e( 'Bundle', 'tutor-course-bundles' ); ?><br />
					<select name="bundle_id">
						<option value="0"><?php esc_html_e( 'All bundles', 'tutor-course-bundles' ); ?></option>
						<?php foreach ( $all_bundles as $bundle ) : ?>
							<option value="<?php echo esc_attr( (string) $bundle->get_id() ); ?>" <?php selected( $bundle_id, $bundle->get_id() ); ?>>
								<?php echo esc_html( $bundle->get_title() ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<?php esc_html_e( 'Status', 'tutor-course-bundles' ); ?><br />
					<select name="status">
						<option value=""><?php esc_html_e( 'Any', 'tutor-course-bundles' ); ?></option>
						<?php
						$statuses = array(
							BundleAccess::STATUS_ACTIVE,
							BundleAccess::STATUS_PENDING,
							BundleAccess::STATUS_EXPIRED,
							BundleAccess::STATUS_REVOKED,
							BundleAccess::STATUS_REFUNDED,
							BundleAccess::STATUS_CANCELLED,
						);
						foreach ( $statuses as $value ) :
							?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( ucfirst( $value ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<?php submit_button( __( 'Filter', 'tutor-course-bundles' ), 'secondary', '', false ); ?>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Learner', 'tutor-course-bundles' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Bundle', 'tutor-course-bundles' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Source', 'tutor-course-bundles' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'tutor-course-bundles' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Granted', 'tutor-course-bundles' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Expires', 'tutor-course-bundles' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'tutor-course-bundles' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $result['items'] ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No entitlements found.', 'tutor-course-bundles' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $result['items'] as $access ) : ?>
							<?php $user = get_userdata( $access->user_id ); ?>
							<tr>
								<td>
									<?php if ( $user instanceof \WP_User ) : ?>
										<a href="<?php echo esc_url( (string) get_edit_user_link( $access->user_id ) ); ?>">
											<?php echo esc_html( $user->display_name ); ?>
										</a>
										<br /><span class="description"><?php echo esc_html( $user->user_email ); ?></span>
									<?php else : ?>
										<?php echo esc_html( sprintf( /* translators: %d: user ID */ __( 'Deleted user #%d', 'tutor-course-bundles' ), $access->user_id ) ); ?>
									<?php endif; ?>
								</td>
								<td>
									<a href="<?php echo esc_url( (string) get_edit_post_link( $access->bundle_id ) ); ?>">
										<?php echo esc_html( get_the_title( $access->bundle_id ) ); ?>
									</a>
								</td>
								<td>
									<?php echo esc_html( $access->source_type ); ?>
									<?php if ( $access->source_id > 0 ) : ?>
										<br /><span class="description">#<?php echo esc_html( (string) $access->source_id ); ?></span>
									<?php endif; ?>
								</td>
								<td><span class="tcb-status tcb-status--<?php echo esc_attr( $access->status ); ?>"><?php echo esc_html( $access->get_status_label() ); ?></span></td>
								<td><?php echo esc_html( $access->granted_at ? date_i18n( get_option( 'date_format' ), strtotime( $access->granted_at . ' UTC' ) ) : '—' ); ?></td>
								<td><?php echo esc_html( $access->expires_at ? date_i18n( get_option( 'date_format' ), strtotime( $access->expires_at . ' UTC' ) ) : __( 'Never', 'tutor-course-bundles' ) ); ?></td>
								<td>
									<?php if ( ! $access->is_terminated() ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Revoke this bundle access?', 'tutor-course-bundles' ) ); ?>');">
											<?php wp_nonce_field( self::NONCE_ACTION ); ?>
											<input type="hidden" name="action" value="tcb_revoke_access" />
											<input type="hidden" name="access_id" value="<?php echo esc_attr( (string) $access->id ); ?>" />
											<input type="hidden" name="bundle_id" value="<?php echo esc_attr( (string) $bundle_id ); ?>" />
											<button type="submit" class="button-link delete"><?php esc_html_e( 'Revoke', 'tutor-course-bundles' ); ?></button>
										</form>
									<?php else : ?>
										<span class="description">—</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php
			$total_pages = (int) ceil( $result['total'] / self::PER_PAGE );

			if ( $total_pages > 1 ) :
				?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						(string) paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $total_pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Repair tools', 'tutor-course-bundles' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Retries course enrollments that failed or are still queued. Safe to run more than once.', 'tutor-course-bundles' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="tcb_repair_enrollments" />
				<input type="hidden" name="bundle_id" value="<?php echo esc_attr( (string) $bundle_id ); ?>" />
				<?php submit_button( __( 'Repair failed enrollments', 'tutor-course-bundles' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}
