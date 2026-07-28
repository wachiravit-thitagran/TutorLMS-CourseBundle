<?php
/**
 * Plugin container and bootstrapper.
 *
 * @package SpaceWork\TutorCourseBundles
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorCourseBundles;

use SpaceWork\TutorCourseBundles\Admin\BundleBuilder;
use SpaceWork\TutorCourseBundles\Admin\BundleColumns;
use SpaceWork\TutorCourseBundles\Admin\EnrollmentManager;
use SpaceWork\TutorCourseBundles\Admin\Menu;
use SpaceWork\TutorCourseBundles\Admin\Reports;
use SpaceWork\TutorCourseBundles\Admin\Settings;
use SpaceWork\TutorCourseBundles\Commerce\CommerceManager;
use SpaceWork\TutorCourseBundles\Commerce\FreeEnrollmentGateway;
use SpaceWork\TutorCourseBundles\Commerce\RefundHandler;
use SpaceWork\TutorCourseBundles\Commerce\WooCommerceGateway;
use SpaceWork\TutorCourseBundles\Frontend\AccessController;
use SpaceWork\TutorCourseBundles\Frontend\ProgressController;
use SpaceWork\TutorCourseBundles\Frontend\Shortcodes;
use SpaceWork\TutorCourseBundles\Frontend\TemplateLoader;
use SpaceWork\TutorCourseBundles\Infrastructure\AccessRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\BundleRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\EnrollmentRepository;
use SpaceWork\TutorCourseBundles\Infrastructure\LockManager;
use SpaceWork\TutorCourseBundles\Infrastructure\PostTypes;
use SpaceWork\TutorCourseBundles\Rest\BundleController;
use SpaceWork\TutorCourseBundles\Rest\EnrollmentController;
use SpaceWork\TutorCourseBundles\Support\Migration;
use SpaceWork\TutorCourseBundles\Tutor\CompletionService;
use SpaceWork\TutorCourseBundles\Tutor\CourseAccessResolver;
use SpaceWork\TutorCourseBundles\Tutor\DashboardIntegration;
use SpaceWork\TutorCourseBundles\Tutor\EnrollmentService;
use SpaceWork\TutorCourseBundles\Tutor\TutorAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Service container. Deliberately tiny: lazily instantiated singletons keyed by
 * class name, with a couple of hand-wired factories for objects that need
 * constructor arguments.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Resolved services.
	 *
	 * @var array<string, object>
	 */
	private array $services = array();

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {}

	/**
	 * Retrieve the singleton.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Bootstrap the plugin.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		load_plugin_textdomain( 'tutor-course-bundles', false, dirname( TCB_BASENAME ) . '/languages' );

		$compatibility = new Compatibility();

		if ( ! $compatibility->requirements_met() ) {
			$compatibility->register_notices();
			return;
		}

		$this->services[ Compatibility::class ] = $compatibility;

		// Run pending schema upgrades (e.g. after a plugin file update).
		Migration::maybe_upgrade();

		// Always-on subsystems.
		$this->get( PostTypes::class )->register_hooks();
		$this->get( CommerceManager::class )->register_hooks();
		$this->get( EnrollmentService::class )->register_hooks();
		$this->get( CompletionService::class )->register_hooks();
		$this->get( TemplateLoader::class )->register_hooks();
		$this->get( Shortcodes::class )->register_hooks();
		$this->get( AccessController::class )->register_hooks();
		$this->get( ProgressController::class )->register_hooks();
		$this->get( DashboardIntegration::class )->register_hooks();
		$this->get( BundleController::class )->register_hooks();
		$this->get( EnrollmentController::class )->register_hooks();

		if ( is_admin() ) {
			$this->get( Menu::class )->register_hooks();
			$this->get( BundleBuilder::class )->register_hooks();
			$this->get( BundleColumns::class )->register_hooks();
			$this->get( Settings::class )->register_hooks();
			$this->get( EnrollmentManager::class )->register_hooks();
			$this->get( Reports::class )->register_hooks();
		}

		/**
		 * Fires once every plugin subsystem is wired up.
		 *
		 * @param Plugin $plugin Container instance.
		 */
		do_action( 'tcb/booted', $this );
	}

	// phpcs:disable Squiz.Commenting.FunctionComment.IncorrectTypeHint -- PHP has no native class-string type.
	/**
	 * Resolve a service by class name.
	 *
	 * @template T of object
	 * @param class-string<T> $service_class Fully qualified class name.
	 * @return T
	 */
	public function get( string $service_class ): object {
		if ( isset( $this->services[ $service_class ] ) ) {
			/**
			 * Resolved service.
			 *
			 * @var T $service
			 */
			$service = $this->services[ $service_class ];

			return $service;
		}

		$this->services[ $service_class ] = $this->make( $service_class );

		/**
		 * Resolved service.
		 *
		 * @var T $service
		 */
		$service = $this->services[ $service_class ];

		return $service;
	}
	// phpcs:enable Squiz.Commenting.FunctionComment.IncorrectTypeHint

	/**
	 * Build a service instance.
	 *
	 * @param class-string $service_class Fully qualified class name.
	 */
	private function make( string $service_class ): object {
		switch ( $service_class ) {
			case BundleRepository::class:
				return new BundleRepository();

			case AccessRepository::class:
				return new AccessRepository();

			case EnrollmentRepository::class:
				return new EnrollmentRepository();

			case TutorAdapter::class:
				return new TutorAdapter();

			case CourseAccessResolver::class:
				return new CourseAccessResolver(
					$this->get( EnrollmentRepository::class ),
					$this->get( AccessRepository::class ),
					$this->get( TutorAdapter::class )
				);

			case EnrollmentService::class:
				return new EnrollmentService(
					$this->get( BundleRepository::class ),
					$this->get( AccessRepository::class ),
					$this->get( EnrollmentRepository::class ),
					$this->get( TutorAdapter::class ),
					$this->get( CourseAccessResolver::class ),
					$this->get( LockManager::class )
				);

			case CompletionService::class:
				return new CompletionService(
					$this->get( BundleRepository::class ),
					$this->get( AccessRepository::class ),
					$this->get( TutorAdapter::class )
				);

			case WooCommerceGateway::class:
				return new WooCommerceGateway(
					$this->get( BundleRepository::class ),
					$this->get( AccessRepository::class )
				);

			case FreeEnrollmentGateway::class:
				return new FreeEnrollmentGateway( $this->get( BundleRepository::class ) );

			case RefundHandler::class:
				return new RefundHandler(
					$this->get( AccessRepository::class ),
					$this->get( EnrollmentService::class )
				);

			case CommerceManager::class:
				return new CommerceManager(
					$this->get( BundleRepository::class ),
					$this->get( AccessRepository::class ),
					$this->get( EnrollmentService::class ),
					$this->get( WooCommerceGateway::class ),
					$this->get( FreeEnrollmentGateway::class ),
					$this->get( RefundHandler::class ),
					$this->get( LockManager::class )
				);

			case BundleBuilder::class:
				return new BundleBuilder( $this->get( BundleRepository::class ) );

			case BundleColumns::class:
				return new BundleColumns( $this->get( BundleRepository::class ) );

			case EnrollmentManager::class:
				return new EnrollmentManager(
					$this->get( BundleRepository::class ),
					$this->get( AccessRepository::class ),
					$this->get( EnrollmentService::class )
				);

			case Reports::class:
				return new Reports(
					$this->get( BundleRepository::class ),
					$this->get( AccessRepository::class )
				);

			case Shortcodes::class:
				return new Shortcodes(
					$this->get( BundleRepository::class ),
					$this->get( TemplateLoader::class )
				);

			case AccessController::class:
				return new AccessController(
					$this->get( BundleRepository::class ),
					$this->get( AccessRepository::class ),
					$this->get( EnrollmentService::class )
				);

			case ProgressController::class:
				return new ProgressController(
					$this->get( BundleRepository::class ),
					$this->get( TutorAdapter::class )
				);

			case DashboardIntegration::class:
				return new DashboardIntegration(
					$this->get( BundleRepository::class ),
					$this->get( AccessRepository::class ),
					$this->get( TemplateLoader::class )
				);

			case BundleController::class:
				return new BundleController(
					$this->get( BundleRepository::class ),
					$this->get( ProgressController::class )
				);

			case EnrollmentController::class:
				return new EnrollmentController(
					$this->get( BundleRepository::class ),
					$this->get( AccessRepository::class ),
					$this->get( EnrollmentService::class )
				);

			default:
				return new $service_class();
		}
	}
}
