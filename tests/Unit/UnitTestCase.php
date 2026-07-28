<?php
/**
 * Base class for the isolated unit suite.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Wires Brain Monkey up and stubs the small set of WordPress functions the
 * domain layer touches.
 *
 * Anything that needs a database belongs in the integration suite; if a test
 * here starts needing more than a handful of stubs, that is the signal it has
 * drifted out of unit territory.
 */
abstract class UnitTestCase extends PHPUnitTestCase {

	/**
	 * In-memory post meta store, keyed "postID:metaKey".
	 *
	 * @var array<string, mixed>
	 */
	protected array $meta = array();

	/**
	 * In-memory option store.
	 *
	 * @var array<string, mixed>
	 */
	protected array $options = array();

	/**
	 * In-memory object cache.
	 *
	 * @var array<string, mixed>
	 */
	protected array $cache = array();

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->meta    = array();
		$this->options = array();
		$this->cache   = array();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = array() ) {
				if ( is_object( $args ) ) {
					$args = get_object_vars( $args );
				}

				return is_array( $args ) ? array_merge( $defaults, $args ) : $defaults;
			}
		);

		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key = '', $single = false ) {
				$value = $this->meta[ $post_id . ':' . $key ] ?? '';

				return $single ? $value : array( $value );
			}
		);

		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) {
				$this->meta[ $post_id . ':' . $key ] = $value;

				return true;
			}
		);

		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) {
				unset( $this->meta[ $post_id . ':' . $key ] );

				return true;
			}
		);

		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => $this->options[ $name ] ?? $default
		);

		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;

				return true;
			}
		);

		Functions\when( 'add_option' )->alias(
			function ( $name, $value ) {
				if ( array_key_exists( $name, $this->options ) ) {
					return false;
				}

				$this->options[ $name ] = $value;

				return true;
			}
		);

		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );

				return true;
			}
		);

		Functions\when( 'wp_cache_get' )->alias(
			function ( $key, $group = '' ) {
				return $this->cache[ $group . '|' . $key ] ?? false;
			}
		);

		Functions\when( 'wp_cache_set' )->alias(
			function ( $key, $value, $group = '' ) {
				$this->cache[ $group . '|' . $key ] = $value;

				return true;
			}
		);

		Functions\when( 'wp_cache_delete' )->alias(
			function ( $key, $group = '' ) {
				unset( $this->cache[ $group . '|' . $key ] );

				return true;
			}
		);

		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_time' )->alias(
			static fn( $type = 'mysql' ) => 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time()
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'absint' )->alias( static fn( $value ) => abs( (int) $value ) );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_trim_words' )->alias(
			static function ( $text, $num_words = 55, $more = null ) {
				$words = preg_split( '/\s+/', trim( (string) $text ) ) ?: array();

				if ( count( $words ) <= $num_words ) {
					return implode( ' ', $words );
				}

				return implode( ' ', array_slice( $words, 0, $num_words ) ) . ( $more ?? '…' );
			}
		);
	}

	/**
	 * Tear down.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();

		parent::tearDown();
	}

	/**
	 * Seed the in-memory meta store for a post.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $meta    Key/value pairs.
	 */
	protected function givenMeta( int $post_id, array $meta ): void {
		foreach ( $meta as $key => $value ) {
			$this->meta[ $post_id . ':' . $key ] = $value;
		}
	}

	/**
	 * Build a stub post and make get_post() return it.
	 *
	 * @param array<string, mixed> $props Post properties.
	 */
	protected function givenPost( array $props = array() ): \WP_Post {
		$post = new \WP_Post(
			array_merge(
				array(
					'ID'        => 1,
					'post_type' => \SpaceWork\TutorCourseBundles\Infrastructure\PostTypes::POST_TYPE,
				),
				$props
			)
		);

		Functions\when( 'get_post' )->alias(
			static function ( $input = null ) use ( $post ) {
				if ( $input instanceof \WP_Post ) {
					return $input;
				}

				return ( null === $input || (int) $input === $post->ID ) ? $post : null;
			}
		);

		Functions\when( 'get_the_title' )->justReturn( $post->post_title );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/bundle/' . $post->ID );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( '' );

		return $post;
	}
}
