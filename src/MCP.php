<?php
/**
 * MCP / WordPress Abilities integration.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Course Bundle abilities for MCP Adapter.
 */
final class MCP {

	/**
	 * Register hooks when the WordPress Abilities API is available.
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	/**
	 * Register the Course Bundle category.
	 */
	public static function register_category(): void {
		wp_register_ability_category(
			'tutorlms-bundle',
			array(
				'label'       => 'Tutor Course Bundles',
				'description' => 'Tutor Course Bundle management and reporting abilities.',
			)
		);
	}

	/**
	 * Register MCP-visible bundle abilities.
	 */
	public static function register_abilities(): void {
		$definitions = array(
			'list-bundles'        => array(
				'label'       => 'List Course Bundles',
				'description' => 'Lists course bundles with optional pagination, search, and category filtering.',
				'method'      => 'GET',
				'route'       => '/tcb/v1/bundles',
				'readonly'    => true,
				'properties'  => array(
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 50,
					),
					'search'   => array( 'type' => 'string' ),
					'category' => array( 'type' => 'string' ),
				),
			),
			'get-bundle'          => array(
				'label'       => 'Get Course Bundle',
				'description' => 'Retrieves the details and configuration of a course bundle by ID.',
				'method'      => 'GET',
				'route'       => '/tcb/v1/bundles/{id}',
				'readonly'    => true,
				'properties'  => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'    => array( 'id' ),
			),
			'create-bundle'       => array(
				'label'       => 'Create Course Bundle',
				'description' => 'Creates a new course bundle using the supplied title, content, pricing, status, and access settings.',
				'method'      => 'POST',
				'route'       => '/tcb/v1/bundles',
				'readonly'    => false,
				'properties'  => self::bundle_fields(),
				'required'    => array( 'title' ),
			),
			'update-bundle'       => array(
				'label'       => 'Update Course Bundle',
				'description' => 'Updates the editable properties of an existing course bundle.',
				'method'      => 'PATCH',
				'route'       => '/tcb/v1/bundles/{id}',
				'readonly'    => false,
				'properties'  => array_merge(
					array(
						'id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					self::bundle_fields()
				),
				'required'    => array( 'id' ),
			),
			'delete-bundle'       => array(
				'label'       => 'Delete Course Bundle',
				'description' => 'Deletes the specified course bundle according to the plugin\'s normal deletion rules.',
				'method'      => 'DELETE',
				'route'       => '/tcb/v1/bundles/{id}',
				'readonly'    => false,
				'destructive' => true,
				'properties'  => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'    => array( 'id' ),
			),
			'get-bundle-progress' => array(
				'label'       => 'Get Bundle Progress',
				'description' => 'Retrieves learner progress information for the courses contained in a bundle.',
				'method'      => 'GET',
				'route'       => '/tcb/v1/bundles/{id}/progress',
				'readonly'    => true,
				'properties'  => array(
					'id'      => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'user_id' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				),
				'required'    => array( 'id' ),
			),
		);

		foreach ( $definitions as $name => $definition ) {
			self::register_rest_ability( $name, $definition );
		}
	}

	/**
	 * Shared create/update bundle fields.
	 *
	 * @return array<string,mixed>
	 */
	private static function bundle_fields(): array {
		return array(
			'title'       => array( 'type' => 'string' ),
			'content'     => array( 'type' => 'string' ),
			'status'      => array( 'type' => 'string' ),
			'price'       => array( 'type' => 'number' ),
			'sale_price'  => array( 'type' => 'number' ),
			'access_type' => array( 'type' => 'string' ),
		);
	}

	/**
	 * Register one REST-backed ability.
	 *
	 * @param string              $name       Ability suffix.
	 * @param array<string,mixed> $definition Ability definition.
	 */
	private static function register_rest_ability( string $name, array $definition ): void {
		$readonly    = (bool) $definition['readonly'];
		$destructive = ! empty( $definition['destructive'] );

		wp_register_ability(
			'tutorlms-bundle/' . $name,
			array(
				'label'               => (string) $definition['label'],
				'description'         => (string) $definition['description'],
				'category'            => 'tutorlms-bundle',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => (array) $definition['properties'],
					'required'   => isset( $definition['required'] ) ? (array) $definition['required'] : array(),
				),
				'execute_callback'    => static function ( array $input ) use ( $definition ) {
					return self::dispatch(
						(string) $definition['method'],
						(string) $definition['route'],
						$input
					);
				},
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
				'meta'                => self::meta( $readonly, $destructive ),
			)
		);
	}

	/**
	 * Dispatch through the plugin REST API so its permission callbacks remain authoritative.
	 *
	 * @param string              $method REST method.
	 * @param string              $route  REST route.
	 * @param array<string,mixed> $input  Ability input.
	 * @return mixed
	 */
	private static function dispatch( string $method, string $route, array $input ) {
		foreach ( $input as $key => $value ) {
			$route = str_replace( '{' . $key . '}', (string) $value, $route );
		}

		$request = new \WP_REST_Request( $method, $route );

		foreach ( $input as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = rest_do_request( $request );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response instanceof \WP_REST_Response && $response->get_status() >= 400 ) {
			$data = $response->get_data();

			return new \WP_Error(
				'tutorlms_bundle_mcp_error',
				is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'Bundle request failed.',
				array(
					'status'   => $response->get_status(),
					'response' => $data,
				)
			);
		}

		return $response instanceof \WP_REST_Response ? $response->get_data() : $response;
	}

	/**
	 * Shared MCP metadata.
	 *
	 * @param bool $is_readonly Whether the operation is read-only.
	 * @param bool $destructive Whether the operation is destructive.
	 * @return array<string,mixed>
	 */
	private static function meta( bool $is_readonly, bool $destructive = false ): array {
		return array(
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations' => array(
				'readonly'      => $is_readonly,
				'destructive'   => $destructive,
				'idempotent'    => $is_readonly,
				'openWorldHint' => ! $is_readonly,
			),
		);
	}
}
