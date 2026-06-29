<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary;

use DoubleYoupi\Core\AbstractModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hierarchical taxonomy `doubleyoupi_media_folder` on the `attachment` post type.
 *
 * The parent/child tree is managed natively by WordPress (hierarchical=true).
 * Sibling order (drag & drop) is stored in term meta META_ORDER.
 *
 * Grid media library filtering goes through the ajax_query_attachments_args hook
 * (Backbone.js framework — not React). JS injects `doubleyoupi_folder` into the collection
 * props; PHP then filters the corresponding WP_Query.
 */
class FolderTaxonomy extends AbstractModule {

	public const TAXONOMY   = 'doubleyoupi_media_folder';
	public const META_ORDER = '_doubleyoupi_folder_order';

	public function id(): string {
		return 'media-library-taxonomy';
	}

	public function name(): string {
		return 'Refined Media Library — Folder Taxonomy';
	}

	public function boot(): void {
		if ( ! Settings::folders_enabled() ) {
			return;
		}
		add_action( 'init', [ $this, 'register_taxonomy' ], 10 );
		add_action( 'rest_api_init', [ $this, 'add_rest_fields' ], 10 );

		// Grid media library filter (Backbone / wp_ajax_query-attachments).
		add_filter( 'ajax_query_attachments_args', [ $this, 'filter_ajax_query' ], 10, 1 );

		// List View media library filter (wp-admin/upload.php).
		add_action( 'restrict_manage_posts', [ $this, 'render_folder_filter' ], 10 );
		add_action( 'pre_get_posts', [ $this, 'apply_folder_filter_list' ], 10, 1 );
	}

	/**
	 * Called from Activator::activate() to register the taxonomy
	 * before flush_rewrite_rules() (required for show_in_rest=true).
	 */
	public static function register_static(): void {
		self::instance()->register_taxonomy();
	}

	public function register_taxonomy(): void {
		register_taxonomy(
			self::TAXONOMY,
			'attachment',
			[
				'label'             => __( 'Folders', 'doubleyoupi-refined-media-library' ),
				'labels'            => [
					'name'          => __( 'Folders', 'doubleyoupi-refined-media-library' ),
					'singular_name' => __( 'Folder', 'doubleyoupi-refined-media-library' ),
					'add_new_item'  => __( 'Add folder', 'doubleyoupi-refined-media-library' ),
					'edit_item'     => __( 'Edit folder', 'doubleyoupi-refined-media-library' ),
					'update_item'   => __( 'Update folder', 'doubleyoupi-refined-media-library' ),
					'not_found'     => __( 'No folders found', 'doubleyoupi-refined-media-library' ),
				],
				'hierarchical'      => true,
				'public'            => false,
				'show_ui'           => false,
				'show_in_menu'      => false,
				'show_in_nav_menus' => false,
				'show_admin_column' => false,
				'show_in_rest'      => true,
				'rest_base'         => 'doubleyoupi-media-folders',
				'rewrite'           => false,
				/*
				 * query_var must be 'doubleyoupi_folder' (NOT false). Otherwise WP strips
				 * our param via array_intersect_key in wp_ajax_query_attachments
				 * (the whitelist is built from $t->query_var).
				 */
				'query_var'         => 'doubleyoupi_folder',
				/*
				 * IMPORTANT: use _update_generic_term_count, which counts ALL term
				 * relationships. The default _update_post_term_count only counts
				 * attachments whose parent post is published → mismatch between
				 * the badge count and the actual folder contents (orphans excluded).
				 */
				'update_count_callback' => '_update_generic_term_count',
				'capabilities'      => [
					'manage_terms' => 'upload_files',
					'edit_terms'   => 'upload_files',
					'delete_terms' => 'upload_files',
					'assign_terms' => 'upload_files',
				],
			]
		);
	}

	public function add_rest_fields(): void {
		// Expose folder order in the REST API (used by JS to build the tree).
		register_rest_field(
			self::TAXONOMY,
			'doubleyoupi_order',
			[
				'get_callback' => static function ( array $term ): int {
					return (int) get_term_meta( $term['id'], self::META_ORDER, true );
				},
				'update_callback' => static function ( mixed $value, \WP_Term $term ): void {
					update_term_meta( $term->term_id, self::META_ORDER, (int) $value );
				},
				'schema' => [
					'description' => 'Sort order among siblings.',
					'type'        => 'integer',
					'context'     => [ 'view', 'edit' ],
				],
			]
		);
	}

	/**
	 * Filter the Grid media library WP_Query based on the selected folder.
	 * `doubleyoupi_folder = 0`  : all media (no filter)
	 * `doubleyoupi_folder > 0`  : media in that folder
	 * `doubleyoupi_folder = -1` : media without a folder
	 *
	 * @param array<string,mixed> $query
	 * @return array<string,mixed>
	 */
	public function filter_ajax_query( array $query ): array {
		/*
		 * With query_var: 'doubleyoupi_folder' on the taxonomy, WP whitelists $query['doubleyoupi_folder'].
		 * We read from $query first (whitelisted), falling back to $_REQUEST.
		 */
		$folder_id = 0;
		if ( isset( $query['doubleyoupi_folder'] ) ) {
			$folder_id = (int) $query['doubleyoupi_folder'];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_REQUEST['query']['doubleyoupi_folder'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$folder_id = (int) $_REQUEST['query']['doubleyoupi_folder'];
		}
		unset( $query['doubleyoupi_folder'] );

		if ( $folder_id > 0 ) {
			$query['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy'         => self::TAXONOMY,
					'field'            => 'term_id',
					'terms'            => [ $folder_id ],
					'include_children' => false,
				],
			];
		} elseif ( $folder_id === -1 ) {
			$query['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => self::TAXONOMY,
					'operator' => 'NOT EXISTS',
				],
			];
		}

		return $query;
	}

	/**
	 * Render a filter dropdown in the media library list view.
	 */
	public function render_folder_filter(): void {
		global $pagenow;
		if ( $pagenow !== 'upload.php' ) {
			return;
		}

		$terms = get_terms( [
			'taxonomy'   => self::TAXONOMY,
			'hide_empty' => false,
			'orderby'    => 'meta_value_num',
			'meta_key'   => self::META_ORDER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['doubleyoupi_folder'] ) ? (int) $_GET['doubleyoupi_folder'] : 0;

		echo '<select name="doubleyoupi_folder" id="dy-folder-filter">';
		echo '<option value="0">' . esc_html__( 'All folders', 'doubleyoupi-refined-media-library' ) . '</option>';
		echo '<option value="-1"' . selected( $current, -1, false ) . '>' . esc_html__( 'No folder', 'doubleyoupi-refined-media-library' ) . '</option>';

		foreach ( $terms as $term ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $term->term_id,
				selected( $current, $term->term_id, false ),
				esc_html( $term->name )
			);
		}

		echo '</select>';
	}

	/**
	 * Apply the folder filter to the list view WP_Query.
	 */
	public function apply_folder_filter_list( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( $query->get( 'post_type' ) !== 'attachment' ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$folder_id = isset( $_GET['doubleyoupi_folder'] ) ? (int) $_GET['doubleyoupi_folder'] : 0;

		/*
		 * IMPORTANT: neutralise WP's auto-detection of the taxonomy query_var.
		 *
		 * We declared query_var: 'doubleyoupi_folder' in register_taxonomy() (required so
		 * the param is not stripped via array_intersect_key in the grid view's
		 * AJAX query-attachments handler). Side-effect: on upload.php?doubleyoupi_folder=143,
		 * WP_Query::parse_tax_query() automatically adds a `slug=143` filter
		 * (the default field is 'slug') on the taxonomy. Combined with our
		 * tax_query term_id=143 in an AND → zero results
		 * (no term has the slug "143").
		 *
		 * Fix: clear the query_var before parse_tax_query() sees it.
		 */
		$query->set( 'doubleyoupi_folder', '' );

		if ( $folder_id > 0 ) {
			$query->set( 'tax_query', [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy'         => self::TAXONOMY,
					'field'            => 'term_id',
					'terms'            => [ $folder_id ],
					'include_children' => false,
				],
			] );
		} elseif ( $folder_id === -1 ) {
			$query->set( 'tax_query', [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => self::TAXONOMY,
					'operator' => 'NOT EXISTS',
				],
			] );
		}
	}
}
