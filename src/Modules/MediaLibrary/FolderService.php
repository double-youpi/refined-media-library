<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Folder rules shared by every surface that edits folders.
 *
 * The media library panel talks to FolderManager's REST routes; the Bricks
 * builder talks to the Bricks folder provider. Both must leave the folders in
 * the same state for the same gesture, so the rules live here once: sibling
 * order, cycle detection, sibling name clashes, one folder per file, counts.
 *
 * Stateless, static, no hooks: safe to call from anywhere once the folder
 * taxonomy is registered.
 */
final class FolderService {

	/**
	 * Every folder, flat, in tree order: parents before children, siblings by
	 * their stored order (ties broken by name, naturally).
	 *
	 * @return array<int,array{id:int,name:string,parent:int,count:int}>
	 */
	public static function ordered_folders(): array {
		$terms = get_terms( [
			'taxonomy'   => FolderTaxonomy::TAXONOMY,
			'hide_empty' => false,
		] );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}

		$by_parent = [];
		$known     = [];
		foreach ( $terms as $term ) {
			$known[ (int) $term->term_id ] = true;
		}
		foreach ( $terms as $term ) {
			$parent = (int) $term->parent;
			// A parent that no longer exists would orphan the branch: show it at
			// the top level rather than lose it.
			if ( $parent > 0 && ! isset( $known[ $parent ] ) ) {
				$parent = 0;
			}
			$by_parent[ $parent ][] = [
				'id'     => (int) $term->term_id,
				// Terms are stored HTML-encoded (`A &amp; B`); consumers get text.
				'name'   => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
				'parent' => $parent,
				'count'  => (int) $term->count,
				'order'  => (int) get_term_meta( (int) $term->term_id, FolderTaxonomy::META_ORDER, true ),
			];
		}
		foreach ( $by_parent as &$siblings ) {
			usort(
				$siblings,
				static fn( $a, $b ) => ( $a['order'] <=> $b['order'] ) ?: strnatcasecmp( $a['name'], $b['name'] )
			);
		}
		unset( $siblings );

		$flat = [];
		$walk = static function ( int $parent ) use ( &$walk, &$flat, $by_parent ): void {
			foreach ( $by_parent[ $parent ] ?? [] as $folder ) {
				unset( $folder['order'] );
				$flat[] = $folder;
				$walk( $folder['id'] );
			}
		};
		$walk( 0 );

		return $flat;
	}

	/**
	 * IDs of the children of $parent, in their stored order.
	 *
	 * @return int[]
	 */
	public static function sibling_ids( int $parent, int $exclude = 0 ): array {
		$ids = [];
		foreach ( self::ordered_folders() as $folder ) {
			if ( $folder['parent'] === $parent && $folder['id'] !== $exclude ) {
				$ids[] = $folder['id'];
			}
		}
		return $ids;
	}

	public static function exists( int $folder_id ): bool {
		if ( $folder_id <= 0 ) {
			return false;
		}
		$term = get_term( $folder_id, FolderTaxonomy::TAXONOMY );
		return $term instanceof \WP_Term;
	}

	/**
	 * Whether re-parenting $folder_id under $new_parent would create a cycle,
	 * i.e. the new parent is the folder itself or one of its descendants.
	 */
	public static function would_create_cycle( int $new_parent, int $folder_id ): bool {
		if ( $new_parent <= 0 ) {
			return false;
		}
		if ( $new_parent === $folder_id ) {
			return true;
		}
		$ancestors = get_ancestors( $new_parent, FolderTaxonomy::TAXONOMY, 'taxonomy' );
		return in_array( $folder_id, array_map( 'intval', (array) $ancestors ), true );
	}

	/**
	 * Whether a sibling of $parent (other than $exclude) already carries $name.
	 * Compared case-insensitively, as a person reading the tree would.
	 */
	public static function sibling_name_exists( string $name, int $parent, int $exclude = 0 ): bool {
		$needle = mb_strtolower( trim( $name ) );
		foreach ( self::ordered_folders() as $folder ) {
			if ( $folder['parent'] === $parent && $folder['id'] !== $exclude
				&& mb_strtolower( $folder['name'] ) === $needle ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Store a sibling order: each ID gets its index.
	 *
	 * @param int[] $ids
	 */
	public static function write_order( array $ids ): void {
		foreach ( array_values( $ids ) as $index => $id ) {
			update_term_meta( (int) $id, FolderTaxonomy::META_ORDER, $index );
		}
	}

	/**
	 * Create a folder under $parent, at $position among its siblings (null =
	 * last). Returns the new ID.
	 *
	 * @return int|\WP_Error
	 */
	public static function create( string $name, int $parent, ?int $position = null ) {
		$name = trim( $name );
		if ( '' === $name ) {
			return new \WP_Error( 'doubleyoupi_folder_invalid', __( 'Something went wrong. Please try again.', 'doubleyoupi-refined-media-library' ) );
		}
		if ( $parent > 0 && ! self::exists( $parent ) ) {
			return new \WP_Error( 'doubleyoupi_folder_invalid', __( 'Something went wrong. Please try again.', 'doubleyoupi-refined-media-library' ) );
		}
		if ( self::sibling_name_exists( $name, $parent ) ) {
			return self::duplicate_error();
		}

		$siblings = self::sibling_ids( $parent );
		$result   = wp_insert_term( $name, FolderTaxonomy::TAXONOMY, [ 'parent' => $parent ] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$id    = (int) $result['term_id'];
		$index = null === $position ? count( $siblings ) : max( 0, min( $position, count( $siblings ) ) );
		array_splice( $siblings, $index, 0, [ $id ] );
		self::write_order( $siblings );

		return $id;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function rename( int $folder_id, string $name ) {
		$name = trim( $name );
		$term = get_term( $folder_id, FolderTaxonomy::TAXONOMY );
		if ( '' === $name || ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'doubleyoupi_folder_invalid', __( 'Something went wrong. Please try again.', 'doubleyoupi-refined-media-library' ) );
		}
		if ( self::sibling_name_exists( $name, (int) $term->parent, $folder_id ) ) {
			return self::duplicate_error();
		}
		$result = wp_update_term( $folder_id, FolderTaxonomy::TAXONOMY, [ 'name' => $name ] );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Move a folder under $parent, at $position among the destination's
	 * children (the moved folder not counted; null = last), and rewrite the
	 * order of both sibling groups.
	 *
	 * @return true|\WP_Error
	 */
	public static function move( int $folder_id, int $parent, ?int $position = null ) {
		$term = get_term( $folder_id, FolderTaxonomy::TAXONOMY );
		if ( ! $term instanceof \WP_Term || ( $parent > 0 && ! self::exists( $parent ) ) ) {
			return new \WP_Error( 'doubleyoupi_folder_invalid', __( 'Something went wrong. Please try again.', 'doubleyoupi-refined-media-library' ) );
		}
		if ( self::would_create_cycle( $parent, $folder_id ) ) {
			return new \WP_Error( 'doubleyoupi_folder_cycle', __( 'A folder cannot be moved into itself or one of its subfolders.', 'doubleyoupi-refined-media-library' ) );
		}
		$name            = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
		$previous_parent = (int) $term->parent;
		if ( $previous_parent !== $parent && self::sibling_name_exists( $name, $parent, $folder_id ) ) {
			return self::duplicate_error();
		}

		$destination = self::sibling_ids( $parent, $folder_id );
		$previous    = self::sibling_ids( $previous_parent, $folder_id );
		$index       = null === $position ? count( $destination ) : max( 0, min( $position, count( $destination ) ) );
		array_splice( $destination, $index, 0, [ $folder_id ] );

		if ( $previous_parent !== $parent ) {
			$result = wp_update_term( $folder_id, FolderTaxonomy::TAXONOMY, [ 'parent' => $parent ] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			self::write_order( $previous );
		}
		self::write_order( $destination );

		return true;
	}

	/**
	 * Delete a folder. Its files stay in the library, without a folder; its
	 * subfolders move up one level (WordPress re-parents them).
	 *
	 * @return true|\WP_Error
	 */
	public static function delete( int $folder_id ) {
		if ( ! self::exists( $folder_id ) ) {
			return new \WP_Error( 'doubleyoupi_folder_invalid', __( 'Something went wrong. Please try again.', 'doubleyoupi-refined-media-library' ) );
		}
		$result = wp_delete_term( $folder_id, FolderTaxonomy::TAXONOMY );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $result ? true : new \WP_Error( 'doubleyoupi_folder_delete', __( 'Could not delete folder.', 'doubleyoupi-refined-media-library' ) );
	}

	/**
	 * Put a file in a folder, alone: one folder per file. $folder_id 0 takes it
	 * out of every folder. Counts of every folder touched are refreshed.
	 *
	 * @return true|\WP_Error
	 */
	public static function assign( int $attachment_id, int $folder_id ) {
		if ( 'attachment' !== get_post_type( $attachment_id ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new \WP_Error( 'doubleyoupi_folder_forbidden', __( 'Access denied.', 'doubleyoupi-refined-media-library' ) );
		}
		if ( $folder_id > 0 && ! self::exists( $folder_id ) ) {
			return new \WP_Error( 'doubleyoupi_folder_invalid', __( 'Something went wrong. Please try again.', 'doubleyoupi-refined-media-library' ) );
		}

		$current = wp_get_object_terms( $attachment_id, FolderTaxonomy::TAXONOMY, [ 'fields' => 'ids' ] );
		$current = is_array( $current ) ? array_map( 'intval', $current ) : [];

		$result = wp_set_object_terms( $attachment_id, $folder_id > 0 ? [ $folder_id ] : [], FolderTaxonomy::TAXONOMY, false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Same reason as FolderManager::rest_assign_folder(): the count of a
		// non-public taxonomy is not always refreshed by wp_set_object_terms().
		$affected = array_unique( array_filter( array_merge( $current, [ $folder_id ] ) ) );
		if ( $affected ) {
			wp_update_term_count_now( $affected, FolderTaxonomy::TAXONOMY );
		}
		return true;
	}

	private static function duplicate_error(): \WP_Error {
		return new \WP_Error(
			'doubleyoupi_folder_duplicate',
			__( 'A folder or collection with this name already exists here.', 'doubleyoupi-refined-media-library' )
		);
	}
}
