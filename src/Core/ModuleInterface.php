<?php
namespace DoubleYoupi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract that every Double Youpi module must implement.
 *
 * A module is a self-contained functional unit: it activates, deactivates,
 * and communicates with other modules via hooks — never through direct class coupling.
 */
interface ModuleInterface {

	/**
	 * Unique module identifier, in kebab-case.
	 * Example: 'admin', 'performance', 'media-library'.
	 * Used as the key in the registry, DB options, and build slugs.
	 */
	public function id(): string;

	/**
	 * Human-readable module name (English, used in the UI and logs).
	 */
	public function name(): string;

	/**
	 * Registers all WordPress hooks for the module.
	 * Called once by ModuleRegistry after plugins_loaded.
	 * Must never trigger direct side effects — only add_action/add_filter calls.
	 */
	public function boot(): void;

	/**
	 * Returns whether the module can run in the current environment.
	 * Allows short-circuiting boot when a dependency is missing
	 * (e.g., a required PHP extension is absent, or a required third-party plugin is inactive).
	 */
	public function is_available(): bool;
}
