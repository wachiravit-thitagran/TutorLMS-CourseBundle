<?php
declare( strict_types = 1 );
namespace SpaceWork\TutorCourseBundles\Tests\Integration;
use SpaceWork\TutorCourseBundles\Infrastructure\LockManager;
use SpaceWork\TutorCourseBundles\Tests\Support\TestCase;
final class EnrollmentLockTest extends TestCase {
	public function test_database_lock_is_owned_by_its_connection(): void {
		$owner = new LockManager();
		$key = 'connection_regression';
		$name = ( new \ReflectionMethod( LockManager::class, 'option_name' ) )->invoke( $owner, $key );
		$rival = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->assertTrue( $owner->acquire( $key ) );
		try {
			$this->assertSame( '0', (string) $rival->get_var( $rival->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) );
			$this->assertSame( '0', (string) $rival->get_var( $rival->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ) );
		} finally {
			$owner->release( $key );
		}
		try {
			$this->assertSame( '1', (string) $rival->get_var( $rival->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) );
		} finally {
			$rival->get_var( $rival->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			$rival->close();
		}
	}
	public function test_non_owner_cannot_release_an_active_lock(): void {
		$owner = new LockManager();
		$other = new LockManager();
		$this->assertTrue( $owner->acquire( 'ownership_regression' ) );
		try {
			$other->release( 'ownership_regression' );
			$this->assertFalse( $other->acquire( 'ownership_regression' ) );
			$this->assertFalse( $owner->acquire( 'ownership_regression' ) );
		} finally {
			$owner->release( 'ownership_regression' );
		}
		$this->assertTrue( $other->acquire( 'ownership_regression' ) );
		$other->release( 'ownership_regression' );
	}
}
