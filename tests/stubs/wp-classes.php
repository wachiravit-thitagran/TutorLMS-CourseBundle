<?php
/**
 * Minimal WordPress class stubs for the unit suite.
 *
 * Brain Monkey replaces WordPress *functions*; these fill in the handful of
 * *classes* the domain layer type-hints against. They are deliberately dumb —
 * anything that needs real WordPress behaviour belongs in the integration suite.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Stub of WP_Post.
	 */
	class WP_Post {

		public int $ID = 0;
		public string $post_title = '';
		public string $post_content = '';
		public string $post_status = 'publish';
		public string $post_type = 'tcb_bundle';
		public string $post_name = '';
		public int $post_author = 1;
		public string $post_date_gmt = '2026-01-01 00:00:00';
		public int $post_parent = 0;

		/**
		 * Constructor.
		 *
		 * @param array<string, mixed> $props Property overrides.
		 */
		public function __construct( array $props = array() ) {
			foreach ( $props as $key => $value ) {
				if ( property_exists( $this, $key ) ) {
					$this->$key = $value;
				}
			}
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Stub of WP_Error.
	 */
	class WP_Error {

		/**
		 * Error codes mapped to messages.
		 *
		 * @var array<string, string[]>
		 */
		protected array $errors = array();

		/**
		 * Error codes mapped to data.
		 *
		 * @var array<string, mixed>
		 */
		protected array $error_data = array();

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data.
		 */
		public function __construct( string $code = '', string $message = '', $data = '' ) {
			if ( '' === $code ) {
				return;
			}

			$this->errors[ $code ][] = $message;

			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}

		/**
		 * First error code.
		 */
		public function get_error_code(): string {
			$codes = array_keys( $this->errors );

			return $codes[0] ?? '';
		}

		/**
		 * First error message.
		 *
		 * @param string $code Optional code.
		 */
		public function get_error_message( string $code = '' ): string {
			$code = '' === $code ? $this->get_error_code() : $code;

			return $this->errors[ $code ][0] ?? '';
		}

		/**
		 * Error data.
		 *
		 * @param string $code Optional code.
		 * @return mixed
		 */
		public function get_error_data( string $code = '' ) {
			$code = '' === $code ? $this->get_error_code() : $code;

			return $this->error_data[ $code ] ?? null;
		}

		/**
		 * Attach extra data.
		 *
		 * @param mixed  $data Data.
		 * @param string $code Optional code.
		 */
		public function add_data( $data, string $code = '' ): void {
			$code                      = '' === $code ? $this->get_error_code() : $code;
			$this->error_data[ $code ] = $data;
		}
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * Stub of WP_User.
	 */
	class WP_User {

		public int $ID = 0;
		public string $display_name = '';
		public string $user_email = '';
		public string $user_login = '';

		/**
		 * Constructor.
		 *
		 * @param array<string, mixed> $props Property overrides.
		 */
		public function __construct( array $props = array() ) {
			foreach ( $props as $key => $value ) {
				if ( property_exists( $this, $key ) ) {
					$this->$key = $value;
				}
			}
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Stub of is_wp_error().
	 *
	 * @param mixed $thing Value to test.
	 */
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'tutor' ) ) {
	/**
	 * Minimal Tutor bootstrap stub.
	 */
	function tutor(): object {
		return (object) array( 'course_post_type' => 'courses' );
	}
}

if ( ! function_exists( 'tutor_utils' ) ) {
	/**
	 * Return the Tutor utility object selected by a unit test.
	 */
	function tutor_utils(): object {
		return $GLOBALS['tcb_test_tutor_utils'] ?? new stdClass();
	}
}
