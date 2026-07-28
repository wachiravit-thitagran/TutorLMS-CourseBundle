<?php
/**
 * Environment and dependency checks.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies the host environment before the plugin wires anything up.
 *
 * Nothing in here touches Tutor LMS Pro. We only look for the free plugin's
 * public bootstrap function and its course post type.
 */
final class Compatibility {

	public const MIN_PHP   = '8.1';
	public const MIN_WP    = '6.5';
	public const MIN_TUTOR = '2.6.0';

	/**
	 * Collected failure messages.
	 *
	 * @var string[]
	 */
	private array $errors = array();

	/**
	 * Whether every hard requirement is satisfied.
	 */
	public function requirements_met(): bool {
		$this->errors = array();

		if ( version_compare( PHP_VERSION, self::MIN_PHP, '<' ) ) {
			$this->errors[] = sprintf(
				/* translators: 1: required PHP version, 2: current PHP version */
				__( 'Tutor Course Bundles requires PHP %1$s or newer. You are running %2$s.', 'tutor-course-bundles' ),
				self::MIN_PHP,
				PHP_VERSION
			);
		}

		if ( version_compare( get_bloginfo( 'version' ), self::MIN_WP, '<' ) ) {
			$this->errors[] = sprintf(
				/* translators: 1: required WordPress version, 2: current WordPress version */
				__( 'Tutor Course Bundles requires WordPress %1$s or newer. You are running %2$s.', 'tutor-course-bundles' ),
				self::MIN_WP,
				get_bloginfo( 'version' )
			);
		}

		if ( ! self::tutor_active() ) {
			$this->errors[] = __( 'Tutor Course Bundles requires the Tutor LMS plugin to be installed and active.', 'tutor-course-bundles' );
		} elseif ( defined( 'TUTOR_VERSION' ) && version_compare( (string) TUTOR_VERSION, self::MIN_TUTOR, '<' ) ) {
			$this->errors[] = sprintf(
				/* translators: 1: required Tutor LMS version, 2: current Tutor LMS version */
				__( 'Tutor Course Bundles requires Tutor LMS %1$s or newer. You are running %2$s.', 'tutor-course-bundles' ),
				self::MIN_TUTOR,
				(string) TUTOR_VERSION
			);
		}

		return array() === $this->errors;
	}

	/**
	 * Whether Tutor LMS (free core) is loaded.
	 */
	public static function tutor_active(): bool {
		return function_exists( 'tutor' ) && function_exists( 'tutor_utils' );
	}

	/**
	 * Whether WooCommerce is loaded.
	 */
	public static function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'WC' );
	}

	/**
	 * Whether WooCommerce Action Scheduler is available for background jobs.
	 */
	public static function action_scheduler_active(): bool {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Tutor LMS course post type slug, resolved defensively.
	 */
	public static function course_post_type(): string {
		if ( function_exists( 'tutor' ) ) {
			$tutor = tutor();

			if ( is_object( $tutor ) && ! empty( $tutor->course_post_type ) ) {
				return (string) $tutor->course_post_type;
			}
		}

		return 'courses';
	}

	/**
	 * Print admin notices for every unmet requirement.
	 */
	public function register_notices(): void {
		$errors = $this->errors;

		add_action(
			'admin_notices',
			static function () use ( $errors ): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}

				foreach ( $errors as $error ) {
					printf(
						'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
						esc_html__( 'Tutor Course Bundles:', 'tutor-course-bundles' ),
						esc_html( $error )
					);
				}
			}
		);
	}
}
