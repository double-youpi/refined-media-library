<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary\Migration\Adapters;

use DoubleYoupi\Modules\MediaLibrary\Migration\Contracts\SourceAdapter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapter for FileBird (NinjaTeam), version ≥ 4.0 — custom tables.
 *
 * Schema (confirmed from includes/Install.php on the WordPress.org SVN):
 *   {prefix}fbv                  id, name, parent, type, ord, created_by
 *                                  └ type: 0 = folder, 1 = collection
 *   {prefix}fbv_attachment_folder folder_id, attachment_id   (composite PK)
 *
 * Conveniently, `type` already distinguishes folders from collections — direct
 * mapping to our two taxonomies. `ord` → folder sort order. `created_by`
 * (per-user folders in Pro) is flattened to global folders in v1.
 *
 * Note: legacy FileBird ≤ 3.9 (taxonomy `nt_wmc_folder`) is not handled by this
 * adapter — it targets modern installations that use custom tables.
 */
final class FileBirdAdapter implements SourceAdapter {

	use ChecksSchema;

	private const T_FOLDERS = 'fbv';
	private const T_RELS    = 'fbv_attachment_folder';

	public function key(): string {
		return 'filebird';
	}

	public function label(): string {
		return 'FileBird';
	}

	public function is_present(): bool {
		return $this->table_exists( self::T_FOLDERS )
			&& $this->table_exists( self::T_RELS );
	}

	public function count_terms(): int {
		global $wpdb;
		$table = $wpdb->prefix . self::T_FOLDERS;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB
	}

	public function count_relations(): int {
		global $wpdb;
		$table = $wpdb->prefix . self::T_RELS;
		// Number of relation rows = work units paginated by read_relations().
		// In the common case (1 attachment = 1 folder) this also equals
		// the number of classified attachments.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB
	}

	public function read_terms(): array {
		global $wpdb;
		$table    = $wpdb->prefix . self::T_FOLDERS;
		$has_type = $this->has_column( self::T_FOLDERS, 'type' );
		$has_ord  = $this->has_column( self::T_FOLDERS, 'ord' );

		$rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $row ) {
			$type   = $has_type ? (int) ( $row['type'] ?? 0 ) : 0;
			$out[]  = [
				'src_id' => (int) $row['id'],
				'name'   => (string) $row['name'],
				'parent' => (int) ( $row['parent'] ?? 0 ),
				'order'  => $has_ord ? (int) ( $row['ord'] ?? 0 ) : 0,
				'kind'   => $type === 1 ? 'collection' : 'folder',
			];
		}
		return $out;
	}

	public function read_relations( int $offset, int $limit ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::T_RELS;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration read; %i binds the table identifier, nothing to cache
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT attachment_id, folder_id FROM %i ORDER BY attachment_id ASC LIMIT %d OFFSET %d',
				$table,
				$limit,
				$offset
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $row ) {
			$out[] = [
				'att_id' => (int) $row['attachment_id'],
				'src_id' => (int) $row['folder_id'],
			];
		}
		return $out;
	}
}
