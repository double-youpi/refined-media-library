<?php declare(strict_types=1);
namespace DoubleYoupi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists the enabled/disabled state of each module.
 *
 * A single WordPress option (an associative array of id => bool) rather than N
 * separate options: atomic update, single query, and one key that does not pollute wp_options.
 *
 * Default convention: a module not listed in the option is considered ENABLED.
 * This means everything works out of the box on a fresh install without any
 * user intervention. The Overview page explicitly writes the value (true OR false)
 * when the user flips a switch — absence still means "enabled by default".
 */
final class ModuleSettings {

	public const OPTION = 'doubleyoupi_modules_enabled';

	/**
	 * Is the module enabled? Defaults to true (see the class docblock).
	 */
	public static function is_enabled( string $module_id ): bool {
		$state = self::all();
		// If the key is absent, assume enabled.
		if ( ! array_key_exists( $module_id, $state ) ) {
			return true;
		}
		return (bool) $state[ $module_id ];
	}

	/**
	 * Enables or disables a module. Validates the id against the catalog —
	 * arbitrary keys must never be written to the database.
	 */
	public static function set_enabled( string $module_id, bool $enabled ): void {
		if ( ! in_array( $module_id, ModuleCatalog::available_ids(), true ) ) {
			return;
		}
		$state                = self::all();
		$state[ $module_id ]  = $enabled;
		update_option( self::OPTION, $state, true ); // read on every page (Installer)
	}

	/**
	 * Raw [id => bool] array. Always an array, even if the option does not exist.
	 *
	 * @return array<string, bool>
	 */
	public static function all(): array {
		$raw = get_option( self::OPTION, [] );
		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * Resets the state (useful on uninstall or for debugging).
	 */
	public static function reset(): void {
		delete_option( self::OPTION );
	}
}
