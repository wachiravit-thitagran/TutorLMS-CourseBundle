<?php
declare(strict_types=1);
namespace SpaceWork\TutorCourseBundles\Tests\Integration;

use SpaceWork\TutorCourseBundles\Tests\Support\TestCase;
use SpaceWork\TutorCourseBundles\Domain\Bundle;
final class P2CommerceTest extends TestCase {
	public function test_rest_create_and_metadata_update_synchronize_final_product_once(): void {
		$this->require_woocommerce();
		wp_set_current_user( $this->seeder->admin() );
		$calls   = array();
		$capture = static function ( $product, $bundle ) use ( &$calls ) {
			$calls[] = $bundle;
		};
		add_action( 'tcb/woocommerce/product_synced', $capture, 10, 2 );
		try {
			$request = new \WP_REST_Request( 'POST', '/tcb/v1/bundles' );
			$request->set_body_params(
				array(
					'title'       => 'Paid initial',
					'status'      => 'publish',
					'access_type' => 'paid',
					'price'       => 123,
					'sale_price'  => 100,
				)
			);
			$response = rest_do_request( $request );
			$this->assertSame( 201, $response->get_status() );
			$id      = $response->get_data()['id'];
			$product = wc_get_product( (int) get_post_meta( $id, Bundle::META_WC_PRODUCT_ID, true ) );
			$this->assertInstanceOf( \WC_Product::class, $product );
			$this->assertSame( '123', $product->get_regular_price() );
			$this->assertSame( '100', $product->get_sale_price() );
			$this->assertCount( 1, $calls );
			$request = new \WP_REST_Request( 'PATCH', '/tcb/v1/bundles/' . $id );
			$request->set_body_params(
				array(
					'price'      => 200,
					'sale_price' => 150,
				)
			);
			$this->assertSame( 200, rest_do_request( $request )->get_status() );
			$product = wc_get_product( $product->get_id() );
			$this->assertSame( '200', $product->get_regular_price() );
			$this->assertSame( '150', $product->get_sale_price() );
			$this->assertCount( 2, $calls );
			$request = new \WP_REST_Request( 'PATCH', '/tcb/v1/bundles/' . $id );
			$request->set_body_params(
				array(
					'title'  => 'Final aggregate',
					'price'  => 300,
					'status' => 'draft',
				)
			);
			$this->assertSame( 200, rest_do_request( $request )->get_status() );
			$product = wc_get_product( $product->get_id() );
			$this->assertSame( '300', $product->get_regular_price() );
			$this->assertSame( 'Final aggregate', $product->get_name() );
			$this->assertSame( 'draft', $product->get_status() );
			$this->assertCount( 3, $calls );
		} finally {
			remove_action( 'tcb/woocommerce/product_synced', $capture );}
	}
	public function test_registered_ability_saves_paid_bundle_product(): void {
		$this->require_woocommerce();
		wp_set_current_user( $this->seeder->admin() );
		$ability = wp_get_ability( 'tutorlms-bundle/create-bundle' );
		$this->assertNotNull( $ability );
		$result = $ability->execute(
			array(
				'title'       => 'Ability paid',
				'status'      => 'publish',
				'access_type' => 'paid',
				'price'       => 70,
			)
		);
		$this->assertNotWPError( $result );
		$id      = $result['id'];
		$product = wc_get_product( (int) get_post_meta( $id, Bundle::META_WC_PRODUCT_ID, true ) );
		$this->assertInstanceOf( \WC_Product::class, $product );
		$this->assertSame( '70', $product->get_regular_price() );
		$ability = wp_get_ability( 'tutorlms-bundle/update-bundle' );
		$result  = $ability->execute(
			array(
				'id'         => $id,
				'price'      => 80,
				'sale_price' => 75,
			)
		);
		$this->assertNotWPError( $result );
		$product = wc_get_product( $product->get_id() );
		$this->assertSame( '80', $product->get_regular_price() );
		$this->assertSame( '75', $product->get_sale_price() );
		$result = $ability->execute(
			array(
				'id'          => $id,
				'access_type' => 'free',
			)
		);
		$this->assertNotWPError( $result );
		$product = wc_get_product( $product->get_id() );
		$this->assertSame( 'draft', $product->get_status() );
	}
	public function test_checkout_product_failure_surfaces_rest_error_and_retry_repairs(): void {
		$this->require_woocommerce();
		wp_set_current_user( $this->seeder->admin() );
		$fault = static function () {
			throw new \RuntimeException( 'Injected product failure' );
		};
		add_action( 'woocommerce_before_product_object_save', $fault );
		$request = new \WP_REST_Request( 'POST', '/tcb/v1/bundles' );
		$request->set_body_params(
			array(
				'title'       => 'Fault bundle',
				'access_type' => 'paid',
				'price'       => 60,
			)
		);
		try {
			$response = rest_do_request( $request );
		} finally {
			remove_action( 'woocommerce_before_product_object_save', $fault );}
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'tcb_product_sync_failed', $response->get_data()['code'] );
		$id      = $response->get_data()['data']['bundle_id'];
		$request = new \WP_REST_Request( 'PATCH', '/tcb/v1/bundles/' . $id );
		$request->set_body_params( array( 'price' => 60 ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertInstanceOf( \WC_Product::class, wc_get_product( (int) get_post_meta( $id, Bundle::META_WC_PRODUCT_ID, true ) ) );
	}

	public function test_failed_metadata_write_does_not_report_success_or_sync_stale_price(): void {
		$this->require_woocommerce();
		wp_set_current_user( $this->seeder->admin() );
		$built = $this->seeder->bundle_with_courses(
			1,
			array(
				'access_type' => 'paid',
				'price'       => 40,
			)
		);
		$id    = $built['bundle'];
		tcb()->get( \SpaceWork\TutorCourseBundles\Commerce\CommerceManager::class )->sync_saved_bundle( $id );
		$fault = static function ( $sql ) {
			return str_starts_with( $sql, 'UPDATE `' ) && str_contains( $sql, 'postmeta' ) ? 'INVALID P2 META FAILURE' : $sql;
		};
		global $wpdb;
		$old = $wpdb->suppress_errors( true );
		add_filter( 'query', $fault );
		$request = new \WP_REST_Request( 'PATCH', '/tcb/v1/bundles/' . $id );
		$request->set_body_params( array( 'price' => 50 ) );
		try {
			$response = rest_do_request( $request );
		} finally {
			remove_filter( 'query', $fault );
			$wpdb->suppress_errors( $old );}
		$this->assertSame( 500, $response->get_status() );
	}
}
