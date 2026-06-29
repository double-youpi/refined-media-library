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

	/**
	 * Registers a module in the registry.
	 * Must be called before Plugin::boot().
	 */
	public static function register( ModuleInterface $module ): void {
		self::$modules[ $module->id() ] = $module;
	}

	/**
	 * Boots all modules that are both available and enabled by the user.
	 * Idempotent: a duplicate call is silently ignored.
	 *
	 * Two gates: `is_available()` (module's technical prerequisites — PHP extensions,
	 * third-party plugins, etc.) AND `ModuleSettings::is_enabled()` (user toggle
	 * from the Overview page). Both must pass for boot() to be called.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		foreach ( self::$modules as $module ) {
			if ( ! $module->is_available() ) {
				continue;
			}
			if ( ! ModuleSettings::is_enabled( $module->id() ) ) {
				continue;
			}
			$module->boot();
			do_action( 'doubleyoupi_module_booted', $module->id(), $module );
		}

		do_action( 'doubleyoupi_all_modules_booted', self::$modules );
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
