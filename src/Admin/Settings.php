<?php
/**
 * Plugin settings screen.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Admin;

use SpaceWork\TutorCourseBundles\Compatibility;
use SpaceWork\TutorCourseBundles\Frontend\ProgressController;
use SpaceWork\TutorCourseBundles\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * A deliberately short settings screen.
 *
 * Every option here changes behaviour that a site owner can reasonably be
 * expected to have an opinion about; anything else is a filter, not a checkbox.
 */
final class Settings {

	public const OPTION_GROUP = 'tcb_settings';

	/**
	 * Register admin hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Every option with its sanitiser and default.
	 *
	 * @return array<string, array{default: string, sanitize: callable}>
	 */
	private function get_fields(): array {
		return array(
			'tcb_bundle_slug'              => array(
				'default'  => 'course-bundle',
				'sanitize' => 'sanitize_title',
			),
			'tcb_bundle_archive_slug'      => array(
				'default'  => 'course-bundles',
				'sanitize' => 'sanitize_title',
			),
			'tcb_progress_mode'            => array(
				'default'  => ProgressController::MODE_AVERAGE,
				'sanitize' => 'sanitize_key',
			),
			'tcb_wc_auto_product'          => array(
				'default'  => 'yes',
				'sanitize' => array( $this, 'sanitize_yes_no' ),
			),
			'tcb_wc_hide_from_catalog'     => array(
				'default'  => 'no',
				'sanitize' => array( $this, 'sanitize_yes_no' ),
			),
			'tcb_wc_direct_checkout'       => array(
				'default'  => 'no',
				'sanitize' => array( $this, 'sanitize_yes_no' ),
			),
			'tcb_prevent_repurchase'       => array(
				'default'  => 'yes',
				'sanitize' => array( $this, 'sanitize_yes_no' ),
			),
			'tcb_revoke_on_course_removal' => array(
				'default'  => 'no',
				'sanitize' => array( $this, 'sanitize_yes_no' ),
			),
			'tcb_instructor_any_course'    => array(
				'default'  => 'no',
				'sanitize' => array( $this, 'sanitize_yes_no' ),
			),
			'tcb_currency_symbol'          => array(
				'default'  => '$',
				'sanitize' => 'sanitize_text_field',
			),
			'tcb_delete_data_on_uninstall' => array(
				'default'  => 'no',
				'sanitize' => array( $this, 'sanitize_yes_no' ),
			),
		);
	}

	/**
	 * Register every setting with the Settings API.
	 */
	public function register_settings(): void {
		foreach ( $this->get_fields() as $name => $field ) {
			register_setting(
				self::OPTION_GROUP,
				$name,
				array(
					'type'              => 'string',
					'sanitize_callback' => $field['sanitize'],
					'default'           => $field['default'],
					'show_in_rest'      => false,
				)
			);
		}
	}

	/**
	 * Normalise a checkbox into yes/no.
	 *
	 * @param mixed $value Raw value.
	 */
	public function sanitize_yes_no( $value ): string {
		return in_array( $value, array( 'yes', '1', 1, true, 'on' ), true ) ? 'yes' : 'no';
	}

	/**
	 * Render the settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'tutor-course-bundles' ) );
		}

		$option = static fn( string $name, string $fallback ): string => (string) get_option( $name, $fallback );
		?>
		<div class="wrap tcb-admin-page">
			<h1><?php esc_html_e( 'Bundle Settings', 'tutor-course-bundles' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<h2 class="title"><?php esc_html_e( 'Permalinks', 'tutor-course-bundles' ); ?></h2>
				<table class="form-table tcb-form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tcb_bundle_slug"><?php esc_html_e( 'Single bundle slug', 'tutor-course-bundles' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="tcb_bundle_slug" name="tcb_bundle_slug" value="<?php echo esc_attr( $option( 'tcb_bundle_slug', 'course-bundle' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Visit Settings → Permalinks after changing this.', 'tutor-course-bundles' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tcb_bundle_archive_slug"><?php esc_html_e( 'Archive slug', 'tutor-course-bundles' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="tcb_bundle_archive_slug" name="tcb_bundle_archive_slug" value="<?php echo esc_attr( $option( 'tcb_bundle_archive_slug', 'course-bundles' ) ); ?>" />
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Progress', 'tutor-course-bundles' ); ?></h2>
				<table class="form-table tcb-form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tcb_progress_mode"><?php esc_html_e( 'Progress calculation', 'tutor-course-bundles' ); ?></label></th>
						<td>
							<select id="tcb_progress_mode" name="tcb_progress_mode">
								<option value="<?php echo esc_attr( ProgressController::MODE_AVERAGE ); ?>" <?php selected( $option( 'tcb_progress_mode', ProgressController::MODE_AVERAGE ), ProgressController::MODE_AVERAGE ); ?>>
									<?php esc_html_e( 'Average across courses', 'tutor-course-bundles' ); ?>
								</option>
								<option value="<?php echo esc_attr( ProgressController::MODE_WEIGHTED ); ?>" <?php selected( $option( 'tcb_progress_mode', ProgressController::MODE_AVERAGE ), ProgressController::MODE_WEIGHTED ); ?>>
									<?php esc_html_e( 'Weighted by amount of content', 'tutor-course-bundles' ); ?>
								</option>
							</select>
							<p class="description"><?php esc_html_e( 'Weighted is fairer when courses differ a lot in length.', 'tutor-course-bundles' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Selling', 'tutor-course-bundles' ); ?></h2>
				<table class="form-table tcb-form-table" role="presentation">
					<?php if ( ! Compatibility::woocommerce_active() ) : ?>
						<tr>
							<td colspan="2">
								<p class="tcb-warning"><?php esc_html_e( 'WooCommerce is not active. Paid bundles will not be purchasable until it is.', 'tutor-course-bundles' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>

					<tr>
						<th scope="row"><?php esc_html_e( 'Product sync', 'tutor-course-bundles' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="tcb_wc_auto_product" value="yes" <?php checked( $option( 'tcb_wc_auto_product', 'yes' ), 'yes' ); ?> />
								<?php esc_html_e( 'Create and update a WooCommerce product automatically for paid bundles', 'tutor-course-bundles' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Catalog visibility', 'tutor-course-bundles' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="tcb_wc_hide_from_catalog" value="yes" <?php checked( $option( 'tcb_wc_hide_from_catalog', 'no' ), 'yes' ); ?> />
								<?php esc_html_e( 'Hide bundle products from the shop and search', 'tutor-course-bundles' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Learners then buy only from the bundle page itself.', 'tutor-course-bundles' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Checkout', 'tutor-course-bundles' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="tcb_wc_direct_checkout" value="yes" <?php checked( $option( 'tcb_wc_direct_checkout', 'no' ), 'yes' ); ?> />
								<?php esc_html_e( 'Send buyers straight to checkout instead of the cart', 'tutor-course-bundles' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Repeat purchases', 'tutor-course-bundles' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="tcb_prevent_repurchase" value="yes" <?php checked( $option( 'tcb_prevent_repurchase', 'yes' ), 'yes' ); ?> />
								<?php esc_html_e( 'Stop learners buying a bundle they already own', 'tutor-course-bundles' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="tcb_currency_symbol"><?php esc_html_e( 'Fallback currency symbol', 'tutor-course-bundles' ); ?></label></th>
						<td>
							<input type="text" class="small-text" id="tcb_currency_symbol" name="tcb_currency_symbol" value="<?php echo esc_attr( $option( 'tcb_currency_symbol', '$' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Only used when WooCommerce is not active.', 'tutor-course-bundles' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Access rules', 'tutor-course-bundles' ); ?></h2>
				<table class="form-table tcb-form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Removing a course', 'tutor-course-bundles' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="tcb_revoke_on_course_removal" value="yes" <?php checked( $option( 'tcb_revoke_on_course_removal', 'no' ), 'yes' ); ?> />
								<?php esc_html_e( 'Also remove existing learners from a course when it leaves a bundle', 'tutor-course-bundles' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Off by default. Learners keep what they already paid for, and courses they hold through another source are never touched.', 'tutor-course-bundles' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Instructors', 'tutor-course-bundles' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="tcb_instructor_any_course" value="yes" <?php checked( $option( 'tcb_instructor_any_course', 'no' ), 'yes' ); ?> />
								<?php esc_html_e( 'Let instructors add courses they do not own to their bundles', 'tutor-course-bundles' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Uninstall', 'tutor-course-bundles' ); ?></h2>
				<table class="form-table tcb-form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Data removal', 'tutor-course-bundles' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="tcb_delete_data_on_uninstall" value="yes" <?php checked( $option( 'tcb_delete_data_on_uninstall', 'no' ), 'yes' ); ?> />
								<?php esc_html_e( 'Delete all bundle data when this plugin is uninstalled', 'tutor-course-bundles' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Off by default. Courses, Tutor enrollments and WooCommerce orders are never deleted either way.', 'tutor-course-bundles' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
