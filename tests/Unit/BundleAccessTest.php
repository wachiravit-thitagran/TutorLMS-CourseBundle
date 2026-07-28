<?php
/**
 * Unit tests for the entitlement state machine.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Tests\Unit;

use SpaceWork\TutorCourseBundles\Domain\BundleAccess;
use SpaceWork\TutorCourseBundles\Tests\Support\Scenarios;

/**
 * The rules that decide whether a learner can open a bundle right now.
 *
 * @covers \SpaceWork\TutorCourseBundles\Domain\BundleAccess
 */
final class BundleAccessTest extends UnitTestCase {

	/**
	 * Every state described in tests/fixtures/access-states.json.
	 *
	 * @dataProvider provideAccessStates
	 *
	 * @param string               $name     Fixture key.
	 * @param array<string, mixed> $spec     Fixture body.
	 */
	public function test_access_state_matrix( string $name, array $spec ): void {
		$access = new BundleAccess(
			1,
			10,
			20,
			BundleAccess::SOURCE_MANUAL,
			0,
			(string) $spec['status'],
			gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			$this->offsetOrNull( $spec['starts_in_days'] ?? null ),
			$this->offsetOrNull( $spec['expires_in_days'] ?? null )
		);

		$expected = $spec['expected'];

		$this->assertSame(
			$expected['usable'],
			$access->is_usable(),
			"[{$name}] is_usable() did not match the fixture."
		);

		$this->assertSame(
			$expected['expired'],
			$access->is_expired(),
			"[{$name}] is_expired() did not match the fixture."
		);

		$this->assertSame(
			$expected['terminated'],
			$access->is_terminated(),
			"[{$name}] is_terminated() did not match the fixture."
		);
	}

	/**
	 * Fixture-driven data provider.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function provideAccessStates(): array {
		$cases = array();

		foreach ( Scenarios::load_fixture( 'access-states' ) as $key => $spec ) {
			$cases[ $key ] = array( $key, $spec );
		}

		return $cases;
	}

	/**
	 * A datetime offset from now, or null.
	 *
	 * @param int|null $days Offset in days.
	 */
	private function offsetOrNull( ?int $days ): ?string {
		if ( null === $days ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', time() + ( $days * DAY_IN_SECONDS ) );
	}

	/**
	 * MySQL zero-dates and empty strings must come back as null, not as
	 * strings that later parse to 1970.
	 */
	public function test_from_row_normalises_empty_dates(): void {
		$access = BundleAccess::from_row(
			array(
				'id'          => '5',
				'bundle_id'   => '10',
				'user_id'     => '20',
				'source_type' => BundleAccess::SOURCE_WOOCOMMERCE,
				'source_id'   => '99',
				'status'      => BundleAccess::STATUS_ACTIVE,
				'granted_at'  => '2026-01-01 00:00:00',
				'starts_at'   => '0000-00-00 00:00:00',
				'expires_at'  => '',
				'revoked_at'  => null,
			)
		);

		$this->assertSame( 5, $access->id );
		$this->assertSame( 99, $access->source_id );
		$this->assertNull( $access->starts_at );
		$this->assertNull( $access->expires_at );
		$this->assertNull( $access->revoked_at );
		$this->assertSame( '2026-01-01 00:00:00', $access->granted_at );
	}

	/**
	 * A lifetime entitlement never expires, no matter how old it is.
	 */
	public function test_lifetime_access_never_expires(): void {
		$access = new BundleAccess(
			1,
			10,
			20,
			BundleAccess::SOURCE_WOOCOMMERCE,
			500,
			BundleAccess::STATUS_ACTIVE,
			gmdate( 'Y-m-d H:i:s', time() - ( 3650 * DAY_IN_SECONDS ) )
		);

		$this->assertFalse( $access->is_expired() );
		$this->assertTrue( $access->is_usable() );
	}

	/**
	 * A start date exactly in the past opens access.
	 */
	public function test_access_opens_once_start_date_passes(): void {
		$access = new BundleAccess(
			1,
			10,
			20,
			BundleAccess::SOURCE_MANUAL,
			0,
			BundleAccess::STATUS_ACTIVE,
			null,
			gmdate( 'Y-m-d H:i:s', time() - 60 )
		);

		$this->assertTrue( $access->is_usable() );
	}

	/**
	 * to_array() is what the REST layer and templates consume, so its shape is
	 * part of the contract.
	 */
	public function test_to_array_exposes_the_public_contract(): void {
		$access = new BundleAccess( 7, 10, 20, BundleAccess::SOURCE_FREE, 0, BundleAccess::STATUS_ACTIVE );
		$data   = $access->to_array();

		foreach ( array( 'id', 'bundle_id', 'user_id', 'source_type', 'source_id', 'status', 'status_label', 'granted_at', 'starts_at', 'expires_at', 'revoked_at', 'note', 'usable', 'expired' ) as $key ) {
			$this->assertArrayHasKey( $key, $data, "to_array() is missing the '{$key}' key." );
		}

		$this->assertTrue( $data['usable'] );
		$this->assertFalse( $data['expired'] );
	}

	/**
	 * Unknown statuses fall back to the raw string rather than an empty label.
	 */
	public function test_unknown_status_label_falls_back_to_the_raw_value(): void {
		$access = new BundleAccess( 1, 10, 20, BundleAccess::SOURCE_MANUAL, 0, 'something_new' );

		$this->assertSame( 'something_new', $access->get_status_label() );
	}
}
