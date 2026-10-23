<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary\Integrations;

use DoubleYoupi\Modules\MediaLibrary\CollectionTaxonomy;
use DoubleYoupi\Modules\MediaLibrary\FolderManager;
use DoubleYoupi\Modules\MediaLibrary\FolderService;
use DoubleYoupi\Modules\MediaLibrary\FolderTaxonomy;
use DoubleYoupi\Modules\MediaLibrary\MediaManagerConflict;
use DoubleYoupi\Modules\MediaLibrary\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The media library's folders and collections, as a Bricks folder provider.
 *
 * LOADED ONLY BY BricksBridge::register_provider(), after it has checked that
 * Bricks declared its interfaces. Never reference this class anywhere else:
 * autoloading it without Bricks would be a fatal error.
 *
 * Folders map one to one: one tree, one folder per file, the stored sibling
 * order, the same rules as the media library panel (FolderService).
 *
 * Collections do not fit Bricks' single tree on their own, so they come as a
 * branch of it: a "Collections" entry at the end of the tree, with one child
 * per collection. Opening a collection filters the grid. Moving files onto a
 * collection ADDS them to it and leaves their folder alone, which is how a
 * collection works in the library; Bricks lets a provider decide that ("when
 * their native storage supports multiple folders"). Creating an entry inside
 * "Collections" creates a collection. The "Collections" entry itself is not
 * a stored object: it can be opened, not renamed, moved or deleted.
 *
 * IDs cannot clash: folders and collections are rows of the same wp_terms
 * table, so their term IDs are distinct, and the "Collections" entry uses a
 * number no term will reach.
 */
final class BricksFolderProvider implements \Bricks\Media_Folder_Provider, \Bricks\Media_Folder_Item_Provider {

	/** ID of the virtual "Collections" entry: 2^31 - 1, safe in JavaScript and far beyond any term ID. */
	public const COLLECTIONS_ROOT = 2147483647;

	public function get_id() {
		return BricksBridge::PROVIDER_ID;
	}

	public function get_label() {
		return 'Refined Media Library';
	}

	public function is_available() {
		return Settings::folders_enabled()
			&& current_user_can( 'upload_files' )
			&& taxonomy_exists( FolderTaxonomy::TAXONOMY )
			&& MediaManagerConflict::instance()->rml_renders_media_ui();
	}

	public function can_manage() {
		// Same gate as the media library panel's folder routes.
		return $this->is_available() && FolderManager::can_manage_folders();
	}

	public function can_assign() {
		// Each file is checked again with edit_post when it is moved.
		return $this->is_available();
	}

	public function get_folders() {
		if ( ! $this->is_available() ) {
			return [];
		}
		$folders = FolderService::ordered_folders();

		if ( $this->collections_on() ) {
			$folders[] = [
				'id'     => self::COLLECTIONS_ROOT,
				'name'   => __( 'Collections', 'doubleyoupi-refined-media-library' ),
				'parent' => 0,
				'count'  => $this->count_query( [ $this->collection_clause( 'EXISTS' ) ] ),
			];
			foreach ( $this->collections() as $collection ) {
				$folders[] = [
					'id'     => (int) $collection->term_id,
					'name'   => html_entity_decode( $collection->name, ENT_QUOTES, 'UTF-8' ),
					'parent' => self::COLLECTIONS_ROOT,
					'count'  => (int) $collection->count,
				];
			}
		}

		return $folders;
	}

	/**
	 * Totals for "All" and "No folder", the two entries Bricks draws itself.
	 *
	 * @return array{all:int,unfiled:int}
	 */
	public function get_scope_counts() {
		return [
			'all'     => $this->count_query( [] ),
			'unfiled' => $this->count_query( [ [ 'taxonomy' => FolderTaxonomy::TAXONOMY, 'operator' => 'NOT EXISTS' ] ] ),
		];
	}

	public function apply_query( $query_args, $folder_id ) {
		if ( ! $this->is_available() ) {
			return $query_args;
		}
		// The upload that may follow lands in what the user is looking at.
		BricksBridge::remember_open_folder( $folder_id );

		if ( '' === $folder_id || 0 === $folder_id || null === $folder_id ) {
			return $query_args;
		}

		$clause = $this->clause_for( $folder_id );
		if ( null === $clause ) {
			return $query_args;
		}

		$tax_query   = isset( $query_args['tax_query'] ) && is_array( $query_args['tax_query'] ) ? $query_args['tax_query'] : [];
		$tax_query[] = $clause;
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- folder browsing is a taxonomy relationship by nature
		$query_args['tax_query'] = $tax_query;

		return $query_args;
	}

	/**
	 * Folder and collection IDs that hold one file.
	 *
	 * @return int[]|\WP_Error
	 */
	public function get_item_folder_ids( $item_id ) {
		if ( ! $this->is_available() ) {
			return [];
		}
		$taxonomies = $this->collections_on()
			? [ FolderTaxonomy::TAXONOMY, CollectionTaxonomy::TAXONOMY ]
			: [ FolderTaxonomy::TAXONOMY ];
		$ids = wp_get_object_terms( absint( $item_id ), $taxonomies, [ 'fields' => 'ids' ] );
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
	}

	/**
	 * Move a file to a folder (alone), or add it to a collection.
	 *
	 * @return true|\WP_Error
	 */
	public function assign_attachment( $attachment_id, $folder_id ) {
		$attachment_id = absint( $attachment_id );
		$folder_id     = absint( $folder_id );

		if ( ! $this->can_assign() || ! current_user_can( 'edit_post', $attachment_id ) || 'attachment' !== get_post_type( $attachment_id ) ) {
			return new \WP_Error( 'doubleyoupi_folder_forbidden', __( 'Access denied.', 'doubleyoupi-refined-media-library' ) );
		}

		if ( self::COLLECTIONS_ROOT === $folder_id ) {
			return $this->locked_error();
		}

		if ( $folder_id > 0 && $this->is_collection( $folder_id ) ) {
			// A collection gathers files from anywhere: add, never replace.
			$result = wp_set_object_terms( $attachment_id, [ $folder_id ], CollectionTaxonomy::TAXONOMY, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			wp_update_term_count_now( [ $folder_id ], CollectionTaxonomy::TAXONOMY );
			return true;
		}

		return FolderService::assign( $attachment_id, $folder_id );
	}

	/**
	 * Create, rename, move or delete, then hand back the refreshed tree.
	 *
	 * @param array<string,mixed> $input Unslashed request input.
	 * @return array{folders:array}|\WP_Error
	 */
	public function manage_folder( $input ) {
		if ( ! $this->can_manage() ) {
			return new \WP_Error( 'doubleyoupi_folder_forbidden', __( 'Access denied.', 'doubleyoupi-refined-media-library' ) );
		}
		$input = is_array( $input ) ? $input : [];

		$result = $this->with_write_lock( function () use ( $input ) {
			return $this->apply_operation( $input );
		} );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return [ 'folders' => $this->get_folders() ];
	}

	// -------------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $input
	 * @return true|int|\WP_Error
	 */
	private function apply_operation( array $input ) {
		$operation = isset( $input['operation'] ) ? sanitize_key( (string) $input['operation'] ) : '';
		$folder_id = isset( $input['folderId'] ) ? absint( $input['folderId'] ) : 0;
		$name      = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '';
		$parent    = isset( $input['parent'] ) ? absint( $input['parent'] ) : 0;
		$position  = isset( $input['position'] ) && is_numeric( $input['position'] ) ? max( 0, (int) $input['position'] ) : null;

		$root_touched       = self::COLLECTIONS_ROOT === $folder_id;
		$collection_target  = $folder_id > 0 && ! $root_touched && $this->is_collection( $folder_id );
		$parent_is_root     = self::COLLECTIONS_ROOT === $parent;
		$parent_is_collection = $parent > 0 && ! $parent_is_root && $this->is_collection( $parent );

		switch ( $operation ) {
			case 'create':
				if ( $parent_is_root ) {
					return $this->create_collection( $name );
				}
				if ( $parent_is_collection ) {
					return $this->locked_error();
				}
				return FolderService::create( $name, $parent, $position );

			case 'rename':
				if ( $root_touched ) {
					return $this->locked_error();
				}
				if ( $collection_target ) {
					return $this->rename_collection( $folder_id, $name );
				}
				return FolderService::rename( $folder_id, $name );

			case 'move':
				// Collections have no place among folders, folders none among
				// collections, and the "Collections" entry stays where it is.
				if ( $root_touched || $collection_target || $parent_is_root || $parent_is_collection ) {
					return $this->locked_error();
				}
				return FolderService::move( $folder_id, $parent, $position );

			case 'delete':
				if ( $root_touched ) {
					return $this->locked_error();
				}
				if ( $collection_target ) {
					$deleted = wp_delete_term( $folder_id, CollectionTaxonomy::TAXONOMY );
					return ( $deleted && ! is_wp_error( $deleted ) )
						? true
						: new \WP_Error( 'doubleyoupi_collection_delete', __( 'Could not delete collection.', 'doubleyoupi-refined-media-library' ) );
				}
				return FolderService::delete( $folder_id );
		}

		return new \WP_Error( 'doubleyoupi_folder_invalid', __( 'Something went wrong. Please try again.', 'doubleyoupi-refined-media-library' ) );
	}

	/**
	 * @return int|\WP_Error
	 */
	private function create_collection( string $name ) {
		if ( ! $this->collections_on() ) {
			return $this->locked_error();
		}
		$name = trim( $name );
		if ( '' === $name ) {
			return new \WP_Error( 'doubleyoupi_folder_invalid', __( 'Something went wrong. Please try again.', 'doubleyoupi-refined-media-library' ) );
		}
		if ( $this->collection_name_exists( $name ) ) {
			return $this->duplicate_error();
		}
		$result = wp_insert_term( $name, CollectionTaxonomy::TAXONOMY );
		return is_wp_error( $result ) ? $result : (int) $result['term_id'];
	}

	/**
	 * @return true|\WP_Error
	 */
	private function rename_collection( int $collection_id, string $name ) {
		$name = trim( $name );
		if ( '' === $name ) {
			return new \WP_Error( 'doubleyoupi_folder_invalid', __( 'Something went wrong. Please try again.', 'doubleyoupi-refined-media-library' ) );
		}
		if ( $this->collection_name_exists( $name, $collection_id ) ) {
			return $this->duplicate_error();
		}
		$result = wp_update_term( $collection_id, CollectionTaxonomy::TAXONOMY, [ 'name' => $name ] );
		return is_wp_error( $result ) ? $result : true;
	}

	private function collection_name_exists( string $name, int $exclude = 0 ): bool {
		$needle = mb_strtolower( $name );
		foreach ( $this->collections() as $collection ) {
			if ( (int) $collection->term_id !== $exclude
				&& mb_strtolower( html_entity_decode( $collection->name, ENT_QUOTES, 'UTF-8' ) ) === $needle ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The tax_query clause for an opened entry, or null for none.
	 *
	 * @param int|string $folder_id
	 * @return array<string,mixed>|null
	 */
	private function clause_for( $folder_id ): ?array {
		if ( 'unfiled' === $folder_id ) {
			return [ 'taxonomy' => FolderTaxonomy::TAXONOMY, 'operator' => 'NOT EXISTS' ];
		}
		$id = absint( $folder_id );
		if ( ! $id ) {
			return null;
		}
		if ( self::COLLECTIONS_ROOT === $id ) {
			return $this->collections_on() ? $this->collection_clause( 'EXISTS' ) : null;
		}
		$taxonomy = $this->is_collection( $id ) ? CollectionTaxonomy::TAXONOMY : FolderTaxonomy::TAXONOMY;
		return [
			'taxonomy'         => $taxonomy,
			'field'            => 'term_id',
			'terms'            => [ $id ],
			'include_children' => false,
		];
	}

	/** @return array<string,string> */
	private function collection_clause( string $operator ): array {
		return [ 'taxonomy' => CollectionTaxonomy::TAXONOMY, 'operator' => $operator ];
	}

	private function is_collection( int $term_id ): bool {
		if ( ! $this->collections_on() ) {
			return false;
		}
		$term = get_term( $term_id, CollectionTaxonomy::TAXONOMY );
		return $term instanceof \WP_Term;
	}

	private function collections_on(): bool {
		return Settings::collections_enabled() && taxonomy_exists( CollectionTaxonomy::TAXONOMY );
	}

	/** @return \WP_Term[] */
	private function collections(): array {
		$terms = get_terms( [
			'taxonomy'   => CollectionTaxonomy::TAXONOMY,
			'hide_empty' => false,
			'orderby'    => 'name',
		] );
		return is_wp_error( $terms ) ? [] : $terms;
	}

	/**
	 * Number of library files matching a tax_query, with the statuses the
	 * media library itself counts.
	 *
	 * @param array<int,array<string,mixed>> $tax_query
	 */
	private function count_query( array $tax_query ): int {
		$args = [
			'post_type'      => 'attachment',
			'post_status'    => current_user_can( 'read_private_posts' ) ? [ 'inherit', 'private' ] : [ 'inherit' ],
			'posts_per_page' => 1,
			'fields'         => 'ids',
		];
		if ( $tax_query ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- counting files per folder is a taxonomy relationship by nature
			$args['tax_query'] = $tax_query;
		}
		$query = new \WP_Query( $args );
		return (int) $query->found_posts;
	}

	/**
	 * Serialise folder writes across requests, as Bricks' own adapter does:
	 * two quick drags must not interleave their order rewrites.
	 *
	 * @param callable $callback
	 * @return mixed
	 */
	private function with_write_lock( callable $callback ) {
		global $wpdb;
		$lock = 'doubleyoupi_ml_folders_' . get_current_blog_id();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory locks are connection state, never cached
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock ) );
		if ( '1' !== (string) $got ) {
			return new \WP_Error( 'doubleyoupi_folder_locked', __( 'Something went wrong. Please try again.', 'doubleyoupi-refined-media-library' ) );
		}
		try {
			return $callback();
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory locks are connection state, never cached
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private function locked_error(): \WP_Error {
		return new \WP_Error(
			'doubleyoupi_collections_locked',
			__( 'Collections are kept apart from folders: this change is not possible here.', 'doubleyoupi-refined-media-library' )
		);
	}

	private function duplicate_error(): \WP_Error {
		return new \WP_Error(
			'doubleyoupi_folder_duplicate',
			__( 'A folder or collection with this name already exists here.', 'doubleyoupi-refined-media-library' )
		);
	}
}
