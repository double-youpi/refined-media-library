<?php declare(strict_types=1);
namespace DoubleYoupi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central registry for Double Youpi modules.
 *
 * The plugin entry point (doubleyoupi.php) registers active modules
 * before calling Plugin::boot(). Plugin::boot() then calls Registry::boot()
 * to trigger the boot of each available module.
 *
 * Inter-module communication: always via hooks, never via Registry::get().
 */
final class ModuleRegistry {

	private static array $modules = [];
	private static bool  $booted  = false;

	/** @var array<string,true> Ids already booted, so a later call starts only what is new. */
	private static array $booted_ids = [];

	/**
	 * Registers a module in the registry.
	 * Must be called before Plugin::boot().
	 */
	public static function register( ModuleInterface $module ): void {
		self::$modules[ $module->id() ] = $module;
	}

	/**
	 * Boots all modules that are both available and enabled by the user.
	 *
	 * Incremental: a module is booted once and only once, but a later call
	 * picks up whatever was registered in between. Two standalone builds can
	 * be active at once and each registers its own module on the same hook:
	 * refusing the second call would leave the second build dormant.
	 *
	 * Two gates: `is_available()` (module's technical prerequisites — PHP extensions,
	 * third-party plugins, etc.) AND `ModuleSettings::is_enabled()` (user toggle
	 * from the Overview page). Both must pass for boot() to be called.
	 */
	public static function boot(): void {
		$fresh = false;
		foreach ( self::$modules as $id => $module ) {
			if ( isset( self::$booted_ids[ $id ] ) ) {
				continue;
			}
			self::$booted_ids[ $id ] = true;
			self::$booted            = true;
			$fresh                   = true;
			if ( ! $module->is_available() ) {
				continue;
			}
			if ( ! ModuleSettings::is_enabled( $module->id() ) ) {
				continue;
			}
			$module->boot();
			do_action( 'doubleyoupi_module_booted', $module->id(), $module );
		}

		if ( $fresh ) {
			do_action( 'doubleyoupi_all_modules_booted', self::$modules );
		}
	}

	/**
	 * Returns a module by its ID, or null if it is not registered.
	 * For internal use only — modules must not couple to each other directly.
	 */
	public static function get( string $id ): ?ModuleInterface {
		return self::$modules[ $id ] ?? null;
	}

	/** @return ModuleInterface[] */
	public static function all(): array {
		return self::$modules;
	}

	public static function has( string $id ): bool {
		return isset( self::$modules[ $id ] );
	}
}
