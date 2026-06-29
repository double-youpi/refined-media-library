<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary\Migration\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema introspection helpers for custom-table-based adapters.
 *
 * Third-party plugin authors rename or add columns between versions, so we check
 * for the existence of tables and columns at runtime rather than hard-coding them,
 * and degrade gracefully (returning a default value) when an expected column is absent.
 */
trait ChecksSchema {

	/** Whether the table exists. `$name` is WITHOUT the prefix (e.g. 'fbv'). */
	protected function table_exists( string $name ): bool {
		global $wpdb;
		$table = $wpdb->prefix . $name;
		// Table names cannot be bound parameters; the prefix comes from $wpdb
		// (trusted) and $name is an internal literal.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB
		return $found === $table;
	}

	/**
	 * Returns the column names for a table (without prefix). Empty if the table does not exist.
	 *
	 * @return string[]
	 */
	protected function columns( string $name ): array {
		global $wpdb;
		$table = $wpdb->prefix . $name;
		// %i (identifier placeholder, WP 6.2+) lets us bind the table name safely.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema introspection, nothing to cache
		$cols  = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
		return is_array( $cols ) ? array_map( 'strval', $cols ) : [];
	}

	protected function has_column( string $table, string $column ): bool {
		return in_array( $column, $this->columns( $table ), true );
	}
}
