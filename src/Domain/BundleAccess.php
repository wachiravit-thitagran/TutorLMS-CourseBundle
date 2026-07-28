<?php
/**
 * A single entitlement: this user, this bundle, granted by this source.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Row in wp_tcb_bundle_access.
 *
 * This is the ledger entry that answers "why does this learner have access?".
 * Everything downstream — enrollment, revocation, refunds — keys off it.
 */
final class BundleAccess {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_ACTIVE    = 'active';
	public const STATUS_EXPIRED   = 'expired';
	public const STATUS_REVOKED   = 'revoked';
	public const STATUS_REFUNDED  = 'refunded';
	public const STATUS_CANCELLED = 'cancelled';

	public const SOURCE_WOOCOMMERCE  = 'woocommerce_order';
	public const SOURCE_MANUAL       = 'manual';
	public const SOURCE_FREE         = 'free_enrollment';
	public const SOURCE_ADMIN        = 'admin';
	public const SOURCE_SUBSCRIPTION = 'subscription';
	public const SOURCE_IMPORT       = 'import';

	/**
	 * Constructor.
	 *
	 * @param int         $id          Row ID.
	 * @param int         $bundle_id   Bundle post ID.
	 * @param int         $user_id     User ID.
	 * @param string      $source_type One of the SOURCE_* constants.
	 * @param int         $source_id   Identifier within the source (order ID, etc.).
	 * @param string      $status      One of the STATUS_* constants.
	 * @param string|null $granted_at  UTC datetime.
	 * @param string|null $starts_at   UTC datetime, null for immediate.
	 * @param string|null $expires_at  UTC datetime, null for lifetime.
	 * @param string|null $revoked_at  UTC datetime.
	 * @param string      $note        Free-form admin note.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $bundle_id,
		public readonly int $user_id,
		public readonly string $source_type = self::SOURCE_MANUAL,
		public readonly int $source_id = 0,
		public readonly string $status = self::STATUS_PENDING,
		public readonly ?string $granted_at = null,
		public readonly ?string $starts_at = null,
		public readonly ?string $expires_at = null,
		public readonly ?string $revoked_at = null,
		public readonly string $note = ''
	) {}

	/**
	 * Hydrate from a database row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['bundle_id'] ?? 0 ),
			(int) ( $row['user_id'] ?? 0 ),
			(string) ( $row['source_type'] ?? self::SOURCE_MANUAL ),
			(int) ( $row['source_id'] ?? 0 ),
			(string) ( $row['status'] ?? self::STATUS_PENDING ),
			self::nullable( $row['granted_at'] ?? null ),
			self::nullable( $row['starts_at'] ?? null ),
			self::nullable( $row['expires_at'] ?? null ),
			self::nullable( $row['revoked_at'] ?? null ),
			(string) ( $row['note'] ?? '' )
		);
	}

	/**
	 * Normalise MySQL zero-dates and empty strings to null.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function nullable( $value ): ?string {
		if ( empty( $value ) || '0000-00-00 00:00:00' === $value ) {
			return null;
		}

		return (string) $value;
	}

	/**
	 * Whether the entitlement currently grants access.
	 *
	 * Active status alone is not enough: a scheduled start date in the future
	 * or a past expiry both mean "no access right now".
	 */
	public function is_usable(): bool {
		if ( self::STATUS_ACTIVE !== $this->status ) {
			return false;
		}

		$now = time();

		if ( null !== $this->starts_at && strtotime( $this->starts_at . ' UTC' ) > $now ) {
			return false;
		}

		if ( $this->is_expired() ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether the expiry date has passed.
	 */
	public function is_expired(): bool {
		if ( null === $this->expires_at ) {
			return false;
		}

		return strtotime( $this->expires_at . ' UTC' ) < time();
	}

	/**
	 * Whether the status represents a terminated entitlement.
	 */
	public function is_terminated(): bool {
		return in_array(
			$this->status,
			array( self::STATUS_REVOKED, self::STATUS_REFUNDED, self::STATUS_CANCELLED, self::STATUS_EXPIRED ),
			true
		);
	}

	/**
	 * Human-readable status label.
	 */
	public function get_status_label(): string {
		$labels = array(
			self::STATUS_PENDING   => __( 'Pending', 'tutor-course-bundles' ),
			self::STATUS_ACTIVE    => __( 'Active', 'tutor-course-bundles' ),
			self::STATUS_EXPIRED   => __( 'Expired', 'tutor-course-bundles' ),
			self::STATUS_REVOKED   => __( 'Revoked', 'tutor-course-bundles' ),
			self::STATUS_REFUNDED  => __( 'Refunded', 'tutor-course-bundles' ),
			self::STATUS_CANCELLED => __( 'Cancelled', 'tutor-course-bundles' ),
		);

		return $labels[ $this->status ] ?? $this->status;
	}

	/**
	 * Array form for REST and templates.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'           => $this->id,
			'bundle_id'    => $this->bundle_id,
			'user_id'      => $this->user_id,
			'source_type'  => $this->source_type,
			'source_id'    => $this->source_id,
			'status'       => $this->status,
			'status_label' => $this->get_status_label(),
			'granted_at'   => $this->granted_at,
			'starts_at'    => $this->starts_at,
			'expires_at'   => $this->expires_at,
			'revoked_at'   => $this->revoked_at,
			'note'         => $this->note,
			'usable'       => $this->is_usable(),
			'expired'      => $this->is_expired(),
		);
	}
}
