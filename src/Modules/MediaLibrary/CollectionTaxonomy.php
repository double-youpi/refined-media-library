<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary;

use DoubleYoupi\Core\AbstractModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flat taxonomy `doubleyoupi_media_collection` on attachment.
 *
 * Key difference vs FolderTaxonomy:
 *  - hierarchical = false (flat collections, no tree)
 *  - An attachment can belong to MULTIPLE collections (append=true)
 *  - An attachment belongs to only ONE folder at a time (append=false)
 */
class CollectionTaxonomy extends AbstractModule {

	public const TAXONOMY = 'doubleyoupi_media_collection';

	public function id(): string {
		return 'media-library-collection-taxonomy';
	}

	public function name(): string {
		return 'Refined Media Library — Collection Taxonomy';
	}

	public function boot(): void {
		if ( ! Settings::collections_enabled() ) {
			return;
		}
		add_action( 'init', [ $this, 'register_taxonomy' ], 10 );
		add_filter( 'ajax_query_attachments_args', [ $this, 'filter_ajax_query' ], 11, 1 );

		// List View media library filter (wp-admin/upload.php?mode=list&doubleyoupi_collection=N).
		add_action( 'pre_get_posts', [ $this, 'apply_collection_filter_list' ], 10, 1 );
	}

	/**
	 * Apply the collection filter to the list view WP_Query.
	 * Counterpart to FolderTaxonomy::apply_folder_filter_list().
	 */
	public function apply_collection_filter_list( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( $query->get( 'post_type' ) !== 'attachment' ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$collection_id = isset( $_GET['doubleyoupi_collection'] ) ? (int) $_GET['doubleyoupi_collection'] : 0;

		// Same as FolderTaxonomy: neutralise WP's auto-detection of the query_var to
		// avoid the double tax_query slug=N AND term_id=N that returns zero results.
		$query->set( 'doubleyoupi_collection', '' );

		if ( $collection_id <= 0 ) {
			return;
		}

		// Preserve any existing tax_query clauses (e.g. doubleyoupi_folder)
		$existing   = $query->get( 'tax_query' ) ?: [];
		$existing[] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'taxonomy'         => self::TAXONOMY,
			'field'            => 'term_id',
			'terms'            => [ $collection_id ],
			'include_children' => false,
		];
		$query->set( 'tax_query', $existing ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
	}

	public static function register_static(): void {
		self::instance()->register_taxonomy();
	}

	public function register_taxonomy(): void {
		register_taxonomy(
			self::TAXONOMY,
			'attachment',
			[
				'label'             => __( 'Collections', 'doubleyoupi-refined-media-library' ),
				'labels'            => [
					'name'          => __( 'Collections', 'doubleyoupi-refined-media-library' ),
					'singular_name' => __( 'Collection', 'doubleyoupi-refined-media-library' ),
					'add_new_item'  => __( 'New collection', 'doubleyoupi-refined-media-library' ),
					'edit_item'     => __( 'Edit collection', 'doubleyoupi-refined-media-library' ),
					'not_found'     => __( 'No collections found', 'doubleyoupi-refined-media-library' ),
				],
				'hierarchical'      => false,
				'public'            => false,
				'show_ui'           => false,
				'show_in_menu'      => false,
				'show_in_nav_menus' => false,
				'show_admin_column' => false,
				'show_in_rest'      => true,
				'rest_base'         => 'doubleyoupi-media-collections',
				'rewrite'           => false,
				// Same as FolderTaxonomy: query_var must be set so WP does not strip
				// doubleyoupi_collection via array_intersect_key in wp_ajax_query_attachments.
				'query_var'         => 'doubleyoupi_collection',
				// _update_generic_term_count: counts ALL attachments with this term,
				// including orphans (post_parent = 0). Otherwise the badge count drifts from the grid.
				'update_count_callback' => '_update_generic_term_count',
				// Managing the tree is for editors (23/10/2026); filing a file
				// (assign_terms) stays open to anyone who can upload.
				'capabilities'      => [
					'manage_terms' => FolderManager::manage_folders_cap(),
					'edit_terms'   => FolderManager::manage_folders_cap(),
					'delete_terms' => FolderManager::manage_folders_cap(),
					'assign_terms' => 'upload_files',
				],
			]
		);
	}

	/**
	 * Filter the Backbone grid by collection.
	 *
	 * @param array<string,mixed> $query
	 * @return array<string,mixed>
	 */
	public function filter_ajax_query( array $query ): array {
		/*
		 * Same as FolderTaxonomy::filter_ajax_query — WP strips doubleyoupi_collection via
		 * array_intersect_key before invoking this filter. We read from $_REQUEST.
		 */
		$collection_id = 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_REQUEST['query']['doubleyoupi_collection'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$collection_id = (int) $_REQUEST['query']['doubleyoupi_collection'];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_REQUEST['doubleyoupi_collection'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$collection_id = (int) $_REQUEST['doubleyoupi_collection'];
		}
		unset( $query['doubleyoupi_collection'] );

		if ( $collection_id <= 0 ) {
			return $query;
		}

		$existing              = $query['tax_query'] ?? [];
		$existing[]            = [  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'taxonomy'         => self::TAXONOMY,
			'field'            => 'term_id',
			'terms'            => [ $collection_id ],
			'include_children' => false,
		];
		$query['tax_query'] = $existing; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		return $query;
	}
}
