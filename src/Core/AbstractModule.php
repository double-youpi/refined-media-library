<?php
namespace DoubleYoupi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for all Double Youpi modules.
 *
 * Provides the singleton pattern and a default is_available() that always returns true.
 * Modules with prerequisites override is_available().
 */
abstract class AbstractModule implements ModuleInterface {

	private static array $instances = [];

	/**
	 * Returns the singleton instance of the calling module.
	 * Uses late static binding so each subclass gets its own instance.
	 */
	final public static function instance(): static {
		$class = static::class;
		if ( ! isset( self::$instances[ $class ] ) ) {
			self::$instances[ $class ] = new static();
		}
		return self::$instances[ $class ];
	}

	/**
	 * Protected constructor — instantiate via ::instance() only.
	 */
	protected function __construct() {}

	/**
	 * By default, all modules are available.
	 * Override if the module depends on a PHP extension or a third-party plugin.
	 */
	public function is_available(): bool {
		return true;
	}
}
