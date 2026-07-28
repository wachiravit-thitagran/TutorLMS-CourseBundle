<?php
/**
 * Template resolution with theme overrides.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Frontend;

use SpaceWork\TutorCourseBundles\Infrastructure\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Loads plugin templates, letting themes override any of them by dropping a
 * file into `tutor-course-bundles/` in the (child) theme.
 */
final class TemplateLoader {

	public const THEME_DIRECTORY = 'tutor-course-bundles';

	/**
	 * Register frontend hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'template_include', array( $this, 'filter_template_include' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'body_class', array( $this, 'filter_body_class' ) );
	}

	/**
	 * Resolve a template file path.
	 *
	 * Lookup order: child theme → parent theme → plugin.
	 *
	 * @param string $template Template name without extension, e.g. "single-bundle".
	 */
	public function locate( string $template ): string {
		$relative = self::THEME_DIRECTORY . '/' . ltrim( $template, '/' ) . '.php';
		$found    = locate_template( array( $relative ), false, false );

		if ( '' === $found ) {
			$found = TCB_PATH . 'templates/' . ltrim( $template, '/' ) . '.php';
		}

		/**
		 * Filter the resolved template path.
		 *
		 * @param string $found    Absolute path.
		 * @param string $template Template name.
		 */
		return (string) apply_filters( 'tcb/bundle/template_path', $found, $template );
	}

	/**
	 * Render a template.
	 *
	 * @param string               $template Template name.
	 * @param array<string, mixed> $args     Variables extracted into scope.
	 */
	public function render( string $template, array $args = array() ): void {
		$path = $this->locate( $template );

		if ( ! is_readable( $path ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $args, EXTR_SKIP );

		include $path;
	}

	/**
	 * Render a template to a string.
	 *
	 * @param string               $template Template name.
	 * @param array<string, mixed> $args     Variables.
	 */
	public function get_html( string $template, array $args = array() ): string {
		ob_start();
		$this->render( $template, $args );

		return (string) ob_get_clean();
	}

	/**
	 * Swap in the plugin's single/archive templates when the theme has none.
	 *
	 * @param string $template Theme-resolved template.
	 */
	public function filter_template_include( $template ) {
		if ( is_singular( PostTypes::POST_TYPE ) ) {
			$theme_template = locate_template(
				array(
					'single-' . PostTypes::POST_TYPE . '.php',
					self::THEME_DIRECTORY . '/single-bundle.php',
				)
			);

			return '' !== $theme_template ? $theme_template : TCB_PATH . 'templates/single-bundle.php';
		}

		if ( is_post_type_archive( PostTypes::POST_TYPE ) || is_tax( array( PostTypes::TAXONOMY_CAT, PostTypes::TAXONOMY_TAG ) ) ) {
			$theme_template = locate_template(
				array(
					'archive-' . PostTypes::POST_TYPE . '.php',
					self::THEME_DIRECTORY . '/archive-bundle.php',
				)
			);

			return '' !== $theme_template ? $theme_template : TCB_PATH . 'templates/archive-bundle.php';
		}

		return $template;
	}

	/**
	 * Enqueue frontend assets only where they are needed.
	 */
	public function enqueue_assets(): void {
		$needed = is_singular( PostTypes::POST_TYPE )
			|| is_post_type_archive( PostTypes::POST_TYPE )
			|| is_tax( array( PostTypes::TAXONOMY_CAT, PostTypes::TAXONOMY_TAG ) );

		/**
		 * Filter whether frontend assets should load on the current request.
		 *
		 * Shortcode users on unrelated pages can force this to true.
		 *
		 * @param bool $needed Whether to enqueue.
		 */
		$needed = (bool) apply_filters( 'tcb/frontend/enqueue_assets', $needed );

		if ( ! $needed ) {
			return;
		}

		wp_enqueue_style(
			'tcb-frontend',
			TCB_URL . 'assets/css/frontend.css',
			array(),
			TCB_VERSION
		);

		wp_enqueue_script(
			'tcb-frontend',
			TCB_URL . 'assets/js/frontend-bundle.js',
			array(),
			TCB_VERSION,
			true
		);

		wp_localize_script(
			'tcb-frontend',
			'tcbFrontend',
			array(
				'restUrl' => esc_url_raw( rest_url( 'tcb/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'confirmEnroll' => __( 'Enroll in this bundle?', 'tutor-course-bundles' ),
					'working'       => __( 'Working…', 'tutor-course-bundles' ),
					'error'         => __( 'Something went wrong. Please try again.', 'tutor-course-bundles' ),
				),
			)
		);
	}

	/**
	 * Add a body class so themes can target bundle pages.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public function filter_body_class( $classes ) {
		if ( is_singular( PostTypes::POST_TYPE ) ) {
			$classes[] = 'tcb-single-bundle';
		}

		if ( is_post_type_archive( PostTypes::POST_TYPE ) ) {
			$classes[] = 'tcb-bundle-archive';
		}

		return $classes;
	}
}
