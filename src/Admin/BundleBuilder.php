<?php
/**
 * The bundle edit screen: course picker, pricing and access panels.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Admin;

use SpaceWork\TutorCourseBundles\Compatibility;
use SpaceWork\TutorCourseBundles\Domain\Bundle;
use SpaceWork\TutorCourseBundles\Domain\BundleCourse;
use SpaceWork\TutorCourseBundles\Infrastructure\BundleRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\PostTypes;
use SpaceWork\TutorCourseBundles\Support\Capabilities;
use SpaceWork\TutorCourseBundles\Tutor\TutorAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves every bundle-specific field.
 *
 * The course list is the only part that does not round-trip through post meta:
 * it is written straight to the membership table so ordering and uniqueness are
 * enforced by the database rather than by hope.
 */
final class BundleBuilder {

	private const NONCE_ACTION = 'tcb_save_bundle';
	private const NONCE_FIELD  = 'tcb_bundle_nonce';

	/**
	 * Constructor.
	 *
	 * @param BundleRepository $bundles Bundle repository.
	 */
	public function __construct( private readonly BundleRepository $bundles ) {}

	/**
	 * Register admin hooks.
	 */
	public function register_hooks(): void {
		add_action( 'add_meta_boxes_' . PostTypes::POST_TYPE, array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_' . PostTypes::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'wp_ajax_tcb_search_courses', array( $this, 'ajax_search_courses' ) );
		add_action( 'wp_ajax_tcb_repair_enrollments', array( $this, 'ajax_repair_enrollments' ) );

		add_action( 'admin_notices', array( $this, 'render_warnings' ) );
	}

	/**
	 * Add the bundle meta boxes.
	 */
	public function register_meta_boxes(): void {
		add_meta_box(
			'tcb-bundle-courses',
			__( 'Courses in this bundle', 'tutor-course-bundles' ),
			array( $this, 'render_courses_box' ),
			PostTypes::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'tcb-bundle-pricing',
			__( 'Pricing', 'tutor-course-bundles' ),
			array( $this, 'render_pricing_box' ),
			PostTypes::POST_TYPE,
			'side',
			'default'
		);

		add_meta_box(
			'tcb-bundle-access',
			__( 'Access & completion', 'tutor-course-bundles' ),
			array( $this, 'render_access_box' ),
			PostTypes::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'tcb-bundle-details',
			__( 'Bundle details', 'tutor-course-bundles' ),
			array( $this, 'render_details_box' ),
			PostTypes::POST_TYPE,
			'normal',
			'default'
		);
	}

	/**
	 * Course picker.
	 *
	 * @param \WP_Post $post Bundle post.
	 */
	public function render_courses_box( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$courses = $this->bundles->get_courses( (int) $post->ID );
		?>
		<div class="tcb-course-builder" data-bundle-id="<?php echo esc_attr( (string) $post->ID ); ?>">
			<div class="tcb-course-search">
				<label class="screen-reader-text" for="tcb-course-search-input">
					<?php esc_html_e( 'Search courses', 'tutor-course-bundles' ); ?>
				</label>
				<input
					type="search"
					id="tcb-course-search-input"
					class="tcb-course-search__input"
					placeholder="<?php esc_attr_e( 'Search courses by title…', 'tutor-course-bundles' ); ?>"
					autocomplete="off"
				/>
				<div class="tcb-course-search__results" role="listbox" aria-live="polite"></div>
			</div>

			<p class="description">
				<?php esc_html_e( 'Drag rows to change the order learners see. Optional courses do not block bundle completion.', 'tutor-course-bundles' ); ?>
			</p>

			<ul class="tcb-selected-courses" id="tcb-selected-courses">
				<?php foreach ( $courses as $index => $course ) : ?>
					<?php $this->render_course_row( $course, $index ); ?>
				<?php endforeach; ?>
			</ul>

			<p class="tcb-empty-state" <?php echo array() === $courses ? '' : 'hidden'; ?>>
				<?php esc_html_e( 'No courses selected yet. Search above to add some.', 'tutor-course-bundles' ); ?>
			</p>
		</div>

		<template id="tcb-course-row-template">
			<?php $this->render_course_row_template(); ?>
		</template>
		<?php
	}

	/**
	 * One selected-course row.
	 *
	 * @param BundleCourse $course Membership row.
	 * @param int          $index  Row index.
	 */
	private function render_course_row( BundleCourse $course, int $index ): void {
		$post      = $course->get_post();
		$available = $course->is_available();
		?>
		<li class="tcb-course-row<?php echo $available ? '' : ' tcb-course-row--warning'; ?>" data-course-id="<?php echo esc_attr( (string) $course->course_id ); ?>">
			<span class="tcb-course-row__handle" aria-hidden="true">⋮⋮</span>

			<span class="tcb-course-row__title">
				<?php echo esc_html( $course->get_title() ); ?>
				<?php if ( ! $available ) : ?>
					<span class="tcb-course-row__flag">
						<?php
						echo $post instanceof \WP_Post
							? esc_html__( 'Not published', 'tutor-course-bundles' )
							: esc_html__( 'Missing', 'tutor-course-bundles' );
						?>
					</span>
				<?php endif; ?>
			</span>

			<label class="tcb-course-row__required">
				<input
					type="checkbox"
					name="tcb_courses[<?php echo esc_attr( (string) $index ); ?>][is_required]"
					value="1"
					<?php checked( $course->is_required ); ?>
				/>
				<?php esc_html_e( 'Required', 'tutor-course-bundles' ); ?>
			</label>

			<input type="hidden" name="tcb_courses[<?php echo esc_attr( (string) $index ); ?>][course_id]" value="<?php echo esc_attr( (string) $course->course_id ); ?>" />

			<button type="button" class="button-link tcb-course-row__remove" aria-label="<?php esc_attr_e( 'Remove course', 'tutor-course-bundles' ); ?>">
				<?php esc_html_e( 'Remove', 'tutor-course-bundles' ); ?>
			</button>
		</li>
		<?php
	}

	/**
	 * Client-side row template; `__INDEX__` and `__ID__` are substituted in JS.
	 */
	private function render_course_row_template(): void {
		?>
		<li class="tcb-course-row" data-course-id="__ID__">
			<span class="tcb-course-row__handle" aria-hidden="true">⋮⋮</span>
			<span class="tcb-course-row__title">__TITLE__</span>
			<label class="tcb-course-row__required">
				<input type="checkbox" name="tcb_courses[__INDEX__][is_required]" value="1" checked />
				<?php esc_html_e( 'Required', 'tutor-course-bundles' ); ?>
			</label>
			<input type="hidden" name="tcb_courses[__INDEX__][course_id]" value="__ID__" />
			<button type="button" class="button-link tcb-course-row__remove">
				<?php esc_html_e( 'Remove', 'tutor-course-bundles' ); ?>
			</button>
		</li>
		<?php
	}

	/**
	 * Pricing panel.
	 *
	 * @param \WP_Post $post Bundle post.
	 */
	public function render_pricing_box( \WP_Post $post ): void {
		$bundle      = Bundle::from( $post );
		$access_type = $bundle?->get_access_type() ?? Bundle::ACCESS_TYPE_FREE;
		$price       = $bundle?->get_price() ?? 0.0;
		$sale        = $bundle?->get_sale_price();
		$product_id  = $bundle?->get_wc_product_id() ?? 0;
		?>
		<p>
			<label for="tcb_access_type"><strong><?php esc_html_e( 'Bundle type', 'tutor-course-bundles' ); ?></strong></label><br />
			<select name="tcb_access_type" id="tcb_access_type" class="widefat">
				<option value="<?php echo esc_attr( Bundle::ACCESS_TYPE_FREE ); ?>" <?php selected( $access_type, Bundle::ACCESS_TYPE_FREE ); ?>>
					<?php esc_html_e( 'Free', 'tutor-course-bundles' ); ?>
				</option>
				<option value="<?php echo esc_attr( Bundle::ACCESS_TYPE_PAID ); ?>" <?php selected( $access_type, Bundle::ACCESS_TYPE_PAID ); ?>>
					<?php esc_html_e( 'Paid', 'tutor-course-bundles' ); ?>
				</option>
			</select>
		</p>

		<div class="tcb-paid-fields" <?php echo Bundle::ACCESS_TYPE_PAID === $access_type ? '' : 'style="display:none"'; ?>>
			<p>
				<label for="tcb_price"><strong><?php esc_html_e( 'Regular price', 'tutor-course-bundles' ); ?></strong></label><br />
				<input type="number" step="0.01" min="0" class="widefat" name="tcb_price" id="tcb_price" value="<?php echo esc_attr( (string) $price ); ?>" />
			</p>

			<p>
				<label for="tcb_sale_price"><strong><?php esc_html_e( 'Sale price', 'tutor-course-bundles' ); ?></strong></label><br />
				<input type="number" step="0.01" min="0" class="widefat" name="tcb_sale_price" id="tcb_sale_price" value="<?php echo esc_attr( null === $sale ? '' : (string) $sale ); ?>" />
			</p>

			<?php if ( Compatibility::woocommerce_active() ) : ?>
				<p class="description">
					<?php if ( $product_id > 0 ) : ?>
						<?php
						printf(
							/* translators: %s: link to the WooCommerce product */
							esc_html__( 'Linked WooCommerce product: %s', 'tutor-course-bundles' ),
							'<a href="' . esc_url( (string) get_edit_post_link( $product_id ) ) . '">#' . esc_html( (string) $product_id ) . '</a>'
						);
						?>
					<?php else : ?>
						<?php esc_html_e( 'A WooCommerce product is created automatically when you save a paid bundle.', 'tutor-course-bundles' ); ?>
					<?php endif; ?>
				</p>
			<?php else : ?>
				<p class="description tcb-warning">
					<?php esc_html_e( 'WooCommerce is not active, so paid bundles cannot be sold yet.', 'tutor-course-bundles' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Access and completion panel.
	 *
	 * @param \WP_Post $post Bundle post.
	 */
	public function render_access_box( \WP_Post $post ): void {
		$bundle = Bundle::from( $post );
		?>
		<table class="form-table tcb-form-table" role="presentation">
			<tr>
				<th scope="row"><label for="tcb_access_duration"><?php esc_html_e( 'Access duration', 'tutor-course-bundles' ); ?></label></th>
				<td>
					<input type="number" min="0" step="1" id="tcb_access_duration" name="tcb_access_duration" value="<?php echo esc_attr( (string) ( $bundle?->get_access_duration_days() ?? 0 ) ); ?>" class="small-text" />
					<span class="description"><?php esc_html_e( 'Days after purchase. Use 0 for lifetime access.', 'tutor-course-bundles' ); ?></span>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="tcb_enrollment_mode"><?php esc_html_e( 'Course unlocking', 'tutor-course-bundles' ); ?></label></th>
				<td>
					<select name="tcb_enrollment_mode" id="tcb_enrollment_mode">
						<option value="<?php echo esc_attr( Bundle::ENROLLMENT_IMMEDIATE ); ?>" <?php selected( $bundle?->get_enrollment_mode(), Bundle::ENROLLMENT_IMMEDIATE ); ?>>
							<?php esc_html_e( 'Unlock all courses immediately', 'tutor-course-bundles' ); ?>
						</option>
						<option value="<?php echo esc_attr( Bundle::ENROLLMENT_SEQUENTIAL ); ?>" <?php selected( $bundle?->get_enrollment_mode(), Bundle::ENROLLMENT_SEQUENTIAL ); ?> disabled>
							<?php esc_html_e( 'Sequential (Learning Path add-on)', 'tutor-course-bundles' ); ?>
						</option>
					</select>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="tcb_completion_mode"><?php esc_html_e( 'Completion rule', 'tutor-course-bundles' ); ?></label></th>
				<td>
					<select name="tcb_completion_mode" id="tcb_completion_mode">
						<option value="<?php echo esc_attr( Bundle::COMPLETION_ALL_REQUIRED ); ?>" <?php selected( $bundle?->get_completion_mode(), Bundle::COMPLETION_ALL_REQUIRED ); ?>>
							<?php esc_html_e( 'All required courses completed', 'tutor-course-bundles' ); ?>
						</option>
						<option value="<?php echo esc_attr( Bundle::COMPLETION_ALL_COURSES ); ?>" <?php selected( $bundle?->get_completion_mode(), Bundle::COMPLETION_ALL_COURSES ); ?>>
							<?php esc_html_e( 'Every course completed', 'tutor-course-bundles' ); ?>
						</option>
					</select>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'New courses', 'tutor-course-bundles' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="tcb_grant_new_courses" value="yes" <?php checked( $bundle?->grants_new_courses() ?? true ); ?> />
						<?php esc_html_e( 'Give courses added later to learners who already own this bundle', 'tutor-course-bundles' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Recommended. Learners bought the bundle as a whole, not a fixed list.', 'tutor-course-bundles' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Descriptive details panel.
	 *
	 * @param \WP_Post $post Bundle post.
	 */
	public function render_details_box( \WP_Post $post ): void {
		$bundle     = Bundle::from( $post );
		$difficulty = $bundle?->get_difficulty() ?? 'all_levels';
		$levels     = array(
			'all_levels'   => __( 'All levels', 'tutor-course-bundles' ),
			'beginner'     => __( 'Beginner', 'tutor-course-bundles' ),
			'intermediate' => __( 'Intermediate', 'tutor-course-bundles' ),
			'expert'       => __( 'Expert', 'tutor-course-bundles' ),
		);
		?>
		<table class="form-table tcb-form-table" role="presentation">
			<tr>
				<th scope="row"><label for="tcb_short_description"><?php esc_html_e( 'Short description', 'tutor-course-bundles' ); ?></label></th>
				<td>
					<textarea id="tcb_short_description" name="tcb_short_description" rows="3" class="large-text"><?php echo esc_textarea( (string) ( $bundle?->meta( Bundle::META_SHORT_DESCRIPTION, '' ) ?? '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Shown on cards and archives. Falls back to the first lines of the description.', 'tutor-course-bundles' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="tcb_estimated_duration"><?php esc_html_e( 'Estimated duration', 'tutor-course-bundles' ); ?></label></th>
				<td>
					<input type="text" id="tcb_estimated_duration" name="tcb_estimated_duration" class="regular-text" value="<?php echo esc_attr( $bundle?->get_estimated_duration() ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. 20 hours', 'tutor-course-bundles' ); ?>" />
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="tcb_difficulty"><?php esc_html_e( 'Difficulty', 'tutor-course-bundles' ); ?></label></th>
				<td>
					<select name="tcb_difficulty" id="tcb_difficulty">
						<?php foreach ( $levels as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $difficulty, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Persist everything on the edit screen.
	 *
	 * @param int      $post_id Bundle post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function save( int $post_id, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( Capabilities::EDIT_BUNDLE, $post_id ) ) {
			return;
		}

		$this->save_courses( $post_id );
		$this->save_meta( $post_id );
	}

	/**
	 * Write the course list through the repository.
	 *
	 * @param int $post_id Bundle post ID.
	 */
	private function save_courses( int $post_id ): void {
		// Nonce verified in save(); each nested scalar is sanitized before use.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$raw = isset( $_POST['tcb_courses'] )
			? map_deep( wp_unslash( $_POST['tcb_courses'] ), 'sanitize_text_field' )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$items        = array();
		$restricted   = $this->instructor_is_restricted();
		$tutor        = new TutorAdapter();
		$current_user = get_current_user_id();

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$course_id = absint( $entry['course_id'] ?? 0 );

			if ( $course_id <= 0 ) {
				continue;
			}

			// An instructor without the global capability may only bundle their own courses.
			if ( $restricted && ! $tutor->user_owns_course( $course_id, $current_user ) ) {
				continue;
			}

			$items[] = array(
				'course_id'   => $course_id,
				'is_required' => ! empty( $entry['is_required'] ) ? 1 : 0,
			);
		}

		$this->bundles->set_courses( $post_id, $items );
	}

	/**
	 * Whether the current user may only add their own courses.
	 */
	private function instructor_is_restricted(): bool {
		if ( Capabilities::current_user_can_manage() ) {
			return false;
		}

		return 'yes' !== get_option( 'tcb_instructor_any_course', 'no' );
	}

	/**
	 * Save the bundle meta fields.
	 *
	 * @param int $post_id Bundle post ID.
	 */
	private function save_meta( int $post_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in save().
		$access_type = isset( $_POST['tcb_access_type'] ) ? sanitize_key( wp_unslash( $_POST['tcb_access_type'] ) ) : Bundle::ACCESS_TYPE_FREE;
		$access_type = in_array( $access_type, array( Bundle::ACCESS_TYPE_FREE, Bundle::ACCESS_TYPE_PAID ), true )
			? $access_type
			: Bundle::ACCESS_TYPE_FREE;

		update_post_meta( $post_id, Bundle::META_ACCESS_TYPE, $access_type );

		$price = isset( $_POST['tcb_price'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['tcb_price'] ) ) : 0.0;
		update_post_meta( $post_id, Bundle::META_PRICE, max( 0.0, $price ) );

		$sale_raw = isset( $_POST['tcb_sale_price'] ) ? sanitize_text_field( wp_unslash( $_POST['tcb_sale_price'] ) ) : '';

		if ( '' === $sale_raw ) {
			delete_post_meta( $post_id, Bundle::META_SALE_PRICE );
		} else {
			update_post_meta( $post_id, Bundle::META_SALE_PRICE, max( 0.0, (float) $sale_raw ) );
		}

		$duration = isset( $_POST['tcb_access_duration'] ) ? absint( wp_unslash( $_POST['tcb_access_duration'] ) ) : 0;
		update_post_meta( $post_id, Bundle::META_ACCESS_DURATION, $duration );

		$enrollment_mode = isset( $_POST['tcb_enrollment_mode'] ) ? sanitize_key( wp_unslash( $_POST['tcb_enrollment_mode'] ) ) : Bundle::ENROLLMENT_IMMEDIATE;
		update_post_meta(
			$post_id,
			Bundle::META_ENROLLMENT_MODE,
			in_array( $enrollment_mode, array( Bundle::ENROLLMENT_IMMEDIATE, Bundle::ENROLLMENT_SEQUENTIAL ), true )
				? $enrollment_mode
				: Bundle::ENROLLMENT_IMMEDIATE
		);

		$completion_mode = isset( $_POST['tcb_completion_mode'] ) ? sanitize_key( wp_unslash( $_POST['tcb_completion_mode'] ) ) : Bundle::COMPLETION_ALL_REQUIRED;
		update_post_meta(
			$post_id,
			Bundle::META_COMPLETION_MODE,
			in_array( $completion_mode, array( Bundle::COMPLETION_ALL_REQUIRED, Bundle::COMPLETION_ALL_COURSES ), true )
				? $completion_mode
				: Bundle::COMPLETION_ALL_REQUIRED
		);

		update_post_meta( $post_id, Bundle::META_GRANT_NEW_COURSES, isset( $_POST['tcb_grant_new_courses'] ) ? 'yes' : 'no' );

		$short = isset( $_POST['tcb_short_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['tcb_short_description'] ) ) : '';
		update_post_meta( $post_id, Bundle::META_SHORT_DESCRIPTION, $short );

		$estimated = isset( $_POST['tcb_estimated_duration'] ) ? sanitize_text_field( wp_unslash( $_POST['tcb_estimated_duration'] ) ) : '';
		update_post_meta( $post_id, Bundle::META_ESTIMATED_DURATION, $estimated );

		$difficulty = isset( $_POST['tcb_difficulty'] ) ? sanitize_key( wp_unslash( $_POST['tcb_difficulty'] ) ) : 'all_levels';
		update_post_meta( $post_id, Bundle::META_DIFFICULTY, $difficulty );

		update_post_meta( $post_id, Bundle::META_VERSION, TCB_VERSION );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * AJAX course search for the picker.
	 */
	public function ajax_search_courses(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( Capabilities::EDIT_BUNDLES ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'tutor-course-bundles' ) ), 403 );
		}

		$search     = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$exclude    = isset( $_POST['exclude'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['exclude'] ) ) : array();
		$restricted = $this->instructor_is_restricted();

		$args = array(
			'post_type'      => Compatibility::course_post_type(),
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => 20,
			's'              => $search,
			'post__not_in'   => $exclude,
			'no_found_rows'  => true,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( $restricted ) {
			$args['author'] = get_current_user_id();
		}

		$query   = new \WP_Query( $args );
		$results = array();

		foreach ( $query->posts as $course ) {
			$results[] = array(
				'id'     => (int) $course->ID,
				'title'  => get_the_title( $course ),
				'status' => $course->post_status,
				'author' => get_the_author_meta( 'display_name', (int) $course->post_author ),
			);
		}

		wp_send_json_success( array( 'courses' => $results ) );
	}

	/**
	 * AJAX handler for the "repair failed enrollments" tool.
	 */
	public function ajax_repair_enrollments(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! Capabilities::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'tutor-course-bundles' ) ), 403 );
		}

		$result = tcb()->get( \SpaceWork\TutorCourseBundles\Tutor\EnrollmentService::class )->repair_failed_enrollments( 50 );

		wp_send_json_success( $result );
	}

	/**
	 * Warn about missing or unpublished courses on the edit screen.
	 */
	public function render_warnings(): void {
		$screen = get_current_screen();

		if ( ! $screen instanceof \WP_Screen || PostTypes::POST_TYPE !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}

		$post_id = (int) get_the_ID();

		if ( $post_id <= 0 ) {
			return;
		}

		$problems = array();

		foreach ( $this->bundles->get_courses( $post_id ) as $course ) {
			if ( ! $course->exists() ) {
				$problems[] = sprintf(
					/* translators: %d: course ID */
					__( 'Course #%d has been deleted and will be hidden from learners.', 'tutor-course-bundles' ),
					$course->course_id
				);
			} elseif ( ! $course->is_available() ) {
				$problems[] = sprintf(
					/* translators: %s: course title */
					__( '“%s” is not published, so learners cannot open it.', 'tutor-course-bundles' ),
					$course->get_title()
				);
			}
		}

		if ( array() === $problems ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Tutor Course Bundles', 'tutor-course-bundles' ) . '</strong></p><ul style="list-style:disc;margin-left:20px">';

		foreach ( $problems as $problem ) {
			echo '<li>' . esc_html( $problem ) . '</li>';
		}

		echo '</ul></div>';
	}

	/**
	 * Load the builder script on the bundle edit screen.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_assets( $hook_suffix ): void {
		$screen = get_current_screen();

		if ( ! $screen instanceof \WP_Screen || PostTypes::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script(
			'tcb-admin-bundle-builder',
			TCB_URL . 'assets/js/admin-bundle-builder.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			TCB_VERSION,
			true
		);

		wp_localize_script(
			'tcb-admin-bundle-builder',
			'tcbAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'searching'   => __( 'Searching…', 'tutor-course-bundles' ),
					'noResults'   => __( 'No courses found.', 'tutor-course-bundles' ),
					'alreadydded' => __( 'Already added.', 'tutor-course-bundles' ),
					'error'       => __( 'Search failed. Please try again.', 'tutor-course-bundles' ),
					'draft'       => __( 'Draft', 'tutor-course-bundles' ),
				),
			)
		);
	}
}
