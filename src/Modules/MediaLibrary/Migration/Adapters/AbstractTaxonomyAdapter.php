<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary\Migration\Adapters;

use DoubleYoupi\Modules\MediaLibrary\Migration\Contracts\SourceAdapter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared base for sources that use a hierarchical WordPress taxonomy
 * (HappyFiles, Folders/Premio, WP Media Folder, Enhanced Media Library).
 *
 * We query the database DIRECTLY (wp_terms / wp_term_taxonomy / wp_term_relationships)
 * rather than going through get_terms(): the source plugin may be deactivated — its
 * taxonomy is then no longer registered, but its terms survive in the database
 * (that is our primary use case: recovering an orphaned folder tree).
 *
 * A subclass only needs to provide: the key/label, the taxonomy slug, and
 * optionally the term meta key that holds the folder sort order.
 */
abstract class AbstractTaxonomyAdapter implements SourceAdapter {

	abstract public function key(): string;

	abstract public function label(): string;

	/** Exact slug of the source taxonomy (e.g. 'media_folder'). */
	abstract protected function taxonomy(): string;

	/**
	 * Term meta key holding the folder sort order (integer), or null if the source
	 * does not store an order — falls back to alphabetical sorting (order 0).
	 */
	protected function order_meta_key(): ?string {
		return null;
	}

	public function is_present(): bool {
		return $this->count_terms() > 0;
	}

	public function count_terms(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration read of (possibly orphaned) source-plugin taxonomy tables; nothing to cache
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
			$this->taxonomy()
		) );
	}

	public function count_relations(): int {
		global $wpdb;
		// Relation rows = work units paginated by read_relations().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration read of (possibly orphaned) source-plugin taxonomy tables; nothing to cache
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*)
			 FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 WHERE tt.taxonomy = %s",
			$this->taxonomy()
		) );
	}

	public function read_terms(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration read of (possibly orphaned) source-plugin taxonomy tables; nothing to cache
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.term_id, t.name, tt.parent
			 FROM {$wpdb->terms} t
			 INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
			 WHERE tt.taxonomy = %s",
			$this->taxonomy()
		), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$order_key = $this->order_meta_key();
		$out       = [];
		foreach ( $rows as $row ) {
			$tid   = (int) $row['term_id'];
			$out[] = [
				'src_id' => $tid,
				'name'   => (string) $row['name'],
				'parent' => (int) ( $row['parent'] ?? 0 ),
				'order'  => $order_key ? (int) get_term_meta( $tid, $order_key, true ) : 0,
				'kind'   => 'folder',
			];
		}
		return $out;
	}

	public function read_relations( int $offset, int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration read of (possibly orphaned) source-plugin taxonomy tables; nothing to cache
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT tr.object_id AS att_id, tt.term_id AS src_id
			 FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 WHERE tt.taxonomy = %s
			 ORDER BY tr.object_id ASC
			 LIMIT %d OFFSET %d",
			$this->taxonomy(),
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
