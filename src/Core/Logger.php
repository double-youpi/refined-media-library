<?php declare(strict_types=1);
namespace DoubleYoupi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight logger — a structured wrapper around error_log().
 *
 * The previous implementation wrote to a dedicated DB table (created by
 * the legacy Activator). The v0.9.39 decoupling no longer creates that table,
 * so writing to DB would be fatal. We fall back to error_log(), which writes
 * to debug.log when WP_DEBUG_LOG is active, or to the system PHP log otherwise.
 *
 * The public API (debug/info/warning/error) is preserved to avoid breaking
 * existing modules. The read methods (recent/clear) are now no-ops since there
 * is no longer a queryable store. A future refactor may reintroduce storage if
 * a Logs/Diagnostics module warrants it.
 */
final class Logger {

	public const LEVEL_DEBUG   = 'debug';
	public const LEVEL_INFO    = 'info';
	public const LEVEL_WARNING = 'warning';
	public const LEVEL_ERROR   = 'error';

	public static function debug( string $event, string $message = '', array $context = [] ): void {
		self::log( self::LEVEL_DEBUG, $event, $message, $context );
	}

	public static function info( string $event, string $message = '', array $context = [] ): void {
		self::log( self::LEVEL_INFO, $event, $message, $context );
	}

	public static function warning( string $event, string $message = '', array $context = [] ): void {
		self::log( self::LEVEL_WARNING, $event, $message, $context );
	}

	public static function error( string $event, string $message = '', array $context = [] ): void {
		self::log( self::LEVEL_ERROR, $event, $message, $context );
	}

	public static function log( string $level, string $event, string $message = '', array $context = [] ): void {
		// Hook for external listeners (e.g., a future Diagnostics module
		// that wants to persist specific events).
		do_action( 'doubleyoupi_event', $event, $level, $message, $context );

		// System trace: only in debug mode for warning/error levels
		// (debug/info would flood debug.log in production).
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		if ( ! in_array( $level, [ self::LEVEL_WARNING, self::LEVEL_ERROR ], true ) ) {
			return;
		}

		$ctx = empty( $context ) ? '' : ' ' . wp_json_encode( $context );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( "[Double Youpi][{$level}] {$event}: {$message}{$ctx}" );
	}

	/**
	 * Reads recent events — no-op since the legacy decoupling.
	 * Kept to avoid breaking future callers; always returns an empty array.
	 * Will be re-implemented if a Diagnostics module reintroduces persistent storage.
	 *
	 * @return array<int, object>
	 */
	public static function recent( int $limit = 50, ?string $level = null, ?string $event = null ): array {
		unset( $limit, $level, $event );
		return [];
	}

	public static function purge_old(): int {
		return 0;
	}

	public static function clear(): int {
		return 0;
	}
}
