<?php
/**
 * MCP / WordPress Abilities integration.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles;

defined( 'ABSPATH' ) || exit;

final class MCP {
	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	public static function register_category(): void {
		wp_register_ability_category(
			'tutorlms-bundle',
			array(
				'label'       => 'Tutor Course Bundles',
				'description' => 'Tutor Course Bundle management and reporting abilities.',
			)
		);
	}

	public static function register_abilities(): void {
		self::register_rest_ability( 'list-bundles', 'List Course Bundles', 'GET', '/tcb/v1/bundles', array(
			'page' => array( 'type' => 'integer', 'minimum' => 1 ),
			'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ),
			'search' => array( 'type' => 'string' ),
			'category' => array( 'type' => 'string' ),
		), true );

		self::register_rest_ability( 'get-bundle', 'Get Course Bundle', 'GET', '/tcb/v1/bundles/{id}', array(
			'id' => array( 'type' => 'integer', 'minimum' => 1 ),
		), true, array( 'id' ) );

		self::register_rest_ability( 'create-bundle', 'Create Course Bundle', 'POST', '/tcb/v1/bundles', array(
			'title' => array( 'type' => 'string' ),
			'content' => array( 'type' => 'string' ),
			'status' => array( 'type' => 'string' ),
			'price' => array( 'type' => 'number' ),
			'sale_price' => array( 'type' => 'number' ),
			'access_type' => array( 'type' => 'string' ),
		), false, array( 'title' ) );

		self::register_rest_ability( 'update-bundle', 'Update Course Bundle', 'PATCH', '/tcb/v1/bundles/{id}', array(
			'id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'title' => array( 'type' => 'string' ),
			'content' => array( 'type' => 'string' ),
			'status' => array( 'type' => 'string' ),
			'price' => array( 'type' => 'number' ),
			'sale_price' => array( 'type' => 'number' ),
			'access_type' => array( 'type' => 'string' ),
		), false, array( 'id' ) );

		self::register_rest_ability( 'delete-bundle', 'Delete Course Bundle', 'DELETE', '/tcb/v1/bundles/{id}', array(
			'id' => array( 'type' => 'integer', 'minimum' => 1 ),
		), false, array( 'id' ), true );

		self::register_rest_ability( 'get-bundle-progress', 'Get Bundle Progress', 'GET', '/tcb/v1/bundles/{id}/progress', array(
			'id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'user_id' => array( 'type' => 'integer', 'minimum' => 0 ),
		), true, array( 'id' ) );
	}

	private static function register_rest_ability( string $name, string $label, string $method, string $route, array $properties, bool $readonly, array $required = array(), bool $destructive = false ): void {
		wp_register_ability(
			'tutorlms-bundle/' . $name,
			array(
				'label'       => $label,
				'description' => $label . ' through the plugin REST contract.',
				'category'    => 'tutorlms-bundle',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => $properties,
					'required'   => $required,
				),
				'execute_callback'    => static function ( array $input ) use ( $method, $route ) {
					return self::dispatch( $method, $route, $input );
				},
				'permission_callback' => static function () { return is_user_logged_in(); },
				'meta'                => self::meta( $readonly, $destructive ),
			)
		);
	}

	private static function dispatch( string $method, string $route, array $input ) {
		foreach ( $input as $key => $value ) {
			$route = str_replace( '{' . $key . '}', (string) $value, $route );
		}

		$request = new \WP_REST_Request( $method, $route );
		foreach ( $input as $key => $value ) {
			if ( false === strpos( $route, '{' . $key . '}' ) ) {
				$request->set_param( $key, $value );
			}
		}

		$response = rest_do_request( $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $response instanceof \WP_REST_Response && $response->get_status() >= 400 ) {
			$data = $response->get_data();
			return new \WP_Error( 'tutorlms_bundle_mcp_error', is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'Bundle request failed.', array( 'status' => $response->get_status(), 'response' => $data ) );
		}

		return $response instanceof \WP_REST_Response ? $response->get_data() : $response;
	}

	private static function meta( bool $readonly, bool $destructive = false ): array {
		return array(
			'mcp' => array( 'public' => true, 'type' => 'tool' ),
			'annotations' => array(
				'readonly' => $readonly,
				'destructive' => $destructive,
				'idempotent' => $readonly,
				'openWorldHint' => ! $readonly,
			),
		);
	}
}
