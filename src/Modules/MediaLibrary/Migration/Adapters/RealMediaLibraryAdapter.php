<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary\Migration\Adapters;

use DoubleYoupi\Modules\MediaLibrary\Migration\Contracts\SourceAdapter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapter for Real Media Library (devowl.io) — custom tables.
 *
 * Schema (devowl compiled build — columns verified at runtime via SHOW COLUMNS):
 *   {prefix}realmedialibrary        id, name, parent, type, ord, slug…
 *                                     └ type: 0 = folder, 1 = collection, 2 = gallery
 *   {prefix}realmedialibrary_posts  attachment, fid (folder id), nr (sort order of
 *                                     the attachment WITHIN the folder — not imported in v1)
 *
 * Gallery (type 2) is treated as a collection. The per-folder attachment order (`nr`)
 * has no equivalent in our model — this is an accepted data loss in v1.
 */
final class RealMediaLibraryAdapter implements SourceAdapter {

	use ChecksSchema;

	private const T_FOLDERS = 'realmedialibrary';
	private const T_RELS    = 'realmedialibrary_posts';

	public function key(): string {
		return 'rml';
	}

	public function label(): string {
		return 'Real Media Library';
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
		$table   = $wpdb->prefix . self::T_RELS;
		$fid_col = $this->has_column( self::T_RELS, 'fid' ) ? 'fid' : 'folder';
		// fid = -1 = unclassified attachment (root) → excluded.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE `{$fid_col}` > 0" ); // phpcs:ignore WordPress.DB
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
			// RML uses parent = -1 for root items → normalize to 0.
			$parent = (int) ( $row['parent'] ?? 0 );
			$out[]  = [
				'src_id' => (int) $row['id'],
				'name'   => (string) $row['name'],
				'parent' => $parent > 0 ? $parent : 0,
				'order'  => $has_ord ? (int) ( $row['ord'] ?? 0 ) : 0,
				// type 0 = folder; 1 (collection) and 2 (gallery) → collection.
				'kind'   => $type === 0 ? 'folder' : 'collection',
			];
		}
		return $out;
	}

	public function read_relations( int $offset, int $limit ): array {
		global $wpdb;
		$table   = $wpdb->prefix . self::T_RELS;
		// Column names checked defensively (devowl compiled build).
		$att_col = $this->has_column( self::T_RELS, 'attachment' ) ? 'attachment' : 'post_id';
		$fid_col = $this->has_column( self::T_RELS, 'fid' ) ? 'fid' : 'folder';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration read; %i binds the table/column identifiers, nothing to cache
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT %i AS att_id, %i AS src_id FROM %i WHERE %i > 0 ORDER BY %i ASC LIMIT %d OFFSET %d',
			$att_col,
			$fid_col,
			$table,
			$fid_col,
			$att_col,
			$limit,
			$offset
		), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $row ) {
			$out[] = [
				'att_id' => (int) $row['att_id'],
				'src_id' => (int) $row['src_id'],
			];
		}
		return $out;
	}
}
