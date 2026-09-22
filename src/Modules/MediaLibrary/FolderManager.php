<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary;

use DoubleYoupi\Core\Build;

use DoubleYoupi\Core\AbstractModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Folder CRUD via REST + drag & drop + media library assets.
 *
 * REST routes (namespace doubleyoupi/v1):
 *   GET    /media-folders              → full folder tree
 *   POST   /media-folders              → create a folder
 *   PUT    /media-folders/{id}         → rename / move / reorder
 *   DELETE /media-folders/{id}         → delete (media files are not deleted)
 *   POST   /media-folders/{id}/assign  → assign media to this folder
 *   POST   /media-bulk-edit            → batch-edit alt text and title
 *
 * Uploading "into the current folder" is handled by reading $_POST['doubleyoupi_folder_id']
 * injected by JS (folder-tree.js) into the Backbone uploader's FormData.
 */
class FolderManager extends AbstractModule {

	private const REST_NS = 'doubleyoupi/v1';

	public function id(): string {
		return 'media-library-folders';
	}

	public function name(): string {
		return 'Refined Media Library — Folder Manager';
	}

	public function boot(): void {
		// The RML panel (folders + collections) is ALWAYS present when the Media
		// Library module is active — even if both Folders AND Collections are
		// disabled: the panel then renders both sections in an inactive state with
		// an "Enable" link to re-activate them in one click. The bare WP media
		// library is never exposed (see flags + AJAX enable in enqueue_assets).
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_doubleyoupi_ml_enable_feature', [ $this, 'ajax_enable_feature' ] );
		// Priority 20: runs after MetadataAutoFiller (10) and before DuplicateDetector (30).
		add_action( 'add_attachment', [ $this, 'assign_taxonomies_on_upload' ], 20, 1 );
		// A new upload may introduce a second uploader → refresh the "Mine" gate.
		add_action( 'add_attachment', [ $this, 'flush_uploader_cache' ] );

		// Bulk actions on upload.php list view : Move to folder / Add to collection.
		// No server-side handler is needed — JS intercepts the form submit
		// and opens the picker modal, which calls the REST endpoint directly.
		add_filter( 'bulk_actions-upload', [ $this, 'register_bulk_actions' ] );

		// List View column cleanup: remove noise columns (comments and the duplicate
		// detector column) that add no value in an enriched media library. Priority 99
		// ensures this runs AFTER DuplicateDetector (priority 10) which adds 'doubleyoupi_duplicate'.
		add_filter( 'manage_media_columns', [ $this, 'remove_noise_columns' ], 99 );
		add_filter( 'manage_upload_sortable_columns', [ $this, 'remove_noise_columns' ], 99 );
	}

	/**
	 * Remove the comments and duplicate columns from the upload.php list view.
	 *
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public function remove_noise_columns( array $columns ): array {
		unset( $columns['comments'], $columns['doubleyoupi_duplicate'] );
		return $columns;
	}

	/**
	 * Whether the media library holds attachments from more than one author.
	 * Used to gate the "Mine" filter button (pointless on a solo site).
	 *
	 * Cached in a transient — cleared on every new upload — so the lookup only
	 * runs occasionally even on large libraries.
	 */
	private function has_multiple_uploaders(): bool {
		$cached = get_transient( 'doubleyoupi_ml_multi_uploader' );
		if ( false !== $cached ) {
			return '1' === $cached;
		}

		global $wpdb;
		// Read-only, no user input; LIMIT 2 lets the engine stop as soon as a
		// second distinct author is seen.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$authors = $wpdb->get_col( "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type = 'attachment' LIMIT 2" );
		$multi   = count( $authors ) > 1;

		set_transient( 'doubleyoupi_ml_multi_uploader', $multi ? '1' : '0', DAY_IN_SECONDS );
		return $multi;
	}

	/**
	 * Invalidate the multi-uploader cache (hooked on add_attachment).
	 */
	public function flush_uploader_cache(): void {
		delete_transient( 'doubleyoupi_ml_multi_uploader' );
	}

	/**
	 * Add custom bulk action options to the upload.php list-table dropdown.
	 *
	 * @param array<string,string> $actions
	 * @return array<string,string>
	 */
	public function register_bulk_actions( array $actions ): array {
		if ( Settings::folders_enabled() ) {
			$actions['doubleyoupi_move_to_folder'] = __( 'Move to folder…', 'doubleyoupi-refined-media-library' );
		}
		if ( Settings::collections_enabled() ) {
			$actions['doubleyoupi_add_to_collection'] = __( 'Add to collection…', 'doubleyoupi-refined-media-library' );
		}
		return $actions;
	}

	/**
	 * Anti-FOUC reveal curtain, attached as inline CSS to the media-library
	 * stylesheet (handle `dy-media-library`) via wp_add_inline_style().
	 *
	 * What this prevents : on the upload page, the WP native page-title-action
	 * button "Add New Media File" and the empty grid placeholder would flash
	 * before folder-tree.js relocates them into the folder panel. The curtain
	 * keeps `.wrap` hidden until JS adds `body.dy-ml-ready`. It's emitted inline
	 * in <head> (right after the stylesheet) so it applies before paint; the
	 * transition rule is part of it so the reveal stays smooth.
	 *
	 * Returns the minified CSS payload (no <style> wrapper — the API adds it).
	 */
	private static function reveal_curtain_css(): string {
		return 'body.upload-php:not(.dy-ml-ready) .wrap{opacity:0;transform:translateY(6px);pointer-events:none}'
			. 'body.upload-php:not(.dy-ml-ready) #dy-folder-panel{opacity:0;pointer-events:none}'
			. 'body.upload-php .wrap{transition:opacity .38s cubic-bezier(.22,.7,.2,1) .08s,transform .46s cubic-bezier(.22,.7,.2,1) .08s}';
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	/**
	 * Enqueue the shared dialog component (css + js, handle `doubleyoupi-ml-dialog`).
	 * Idempotent — safe to call from any RML screen that needs designed
	 * confirm/prompt/alert (upload.php and the RML settings tabs).
	 */
	public static function enqueue_dialog_assets( $ver ): void {
		$css = Build::dir( __FILE__, 'assets/css/modules/media-library/dialog.css' );
		if ( file_exists( $css ) ) {
			wp_enqueue_style( 'doubleyoupi-ml-dialog', Build::url( __FILE__, 'assets/css/modules/media-library/dialog.css' ), [], $ver );
		}
		$js = Build::dir( __FILE__, 'assets/js/modules/media-library/dialog.js' );
		if ( file_exists( $js ) ) {
			wp_enqueue_script( 'doubleyoupi-ml-dialog', Build::url( __FILE__, 'assets/js/modules/media-library/dialog.js' ), [], $ver, true );
		}
	}

	public function enqueue_assets( string $hook ): void {
		// Pages where media-library CSS is loaded: media library, upload page,
		// media modal selector, and single attachment edit.
		$is_media_page   = $hook === 'upload.php' || $hook === 'media-new.php';
		$is_media_loaded = did_action( 'wp_enqueue_media' ) > 0;

		// Detect single attachment edit page via screen object (get_post_type() at this point
		// returns null because $post is not yet set up in admin_enqueue_scripts hook).
		$is_attachment_edit = false;
		if ( $hook === 'post.php' && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			$is_attachment_edit = $screen && $screen->post_type === 'attachment';
		}

		if ( ! $is_media_page && ! $is_media_loaded && ! $is_attachment_edit ) {
			return;
		}

		// Another media-library manager (FileBird…) may own the page. If RML has
		// stepped aside, skip ALL of our media-page assets so the two folder UIs
		// never overlap. (MediaManagerConflict renders the switch notice itself.)
		if ( ! MediaManagerConflict::instance()->rml_renders_media_ui() ) {
			return;
		}

		// In development mode, force a fresh load on every request.
		// In production, use the plugin version as a stable cache-buster.
		$ver = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? time() : DOUBLEYOUPI_VERSION;

		$css = Build::dir( __FILE__, 'assets/css/modules/media-library/media-library.css' );
		if ( file_exists( $css ) ) {
			wp_enqueue_style(
				'doubleyoupi-media-library',
				Build::url( __FILE__, 'assets/css/modules/media-library/media-library.css' ),
				[],
				$ver
			);
			// Anti-FOUC curtain (upload page only): inline in <head> via the API
			// so it applies before paint, hiding the native button + empty grid
			// until folder-tree.js adds body.dy-ml-ready.
			if ( $hook === 'upload.php' ) {
				wp_add_inline_style( 'doubleyoupi-media-library', self::reveal_curtain_css() );
			}
		}

		// Designed dialog system (replaces native confirm/prompt/alert).
		self::enqueue_dialog_assets( $ver );

		$js = Build::dir( __FILE__, 'assets/js/modules/media-library/folder-tree.js' );
		if ( file_exists( $js ) ) {
			// media-views: critical dependency — ensures Backbone wp.media is loaded.
			// doubleyoupi-ml-dialog: folder/collection CRUD confirmations use it.
			wp_enqueue_script(
				'doubleyoupi-media-folders',
				Build::url( __FILE__, 'assets/js/modules/media-library/folder-tree.js' ),
				[ 'jquery', 'media-views', 'doubleyoupi-ml-dialog' ],
				$ver,
				true
			);
			wp_localize_script( 'doubleyoupi-media-folders', 'doubleyoupiMediaFolders', [
				'restBase'           => rest_url( self::REST_NS . '/media-folders' ),
				'restCollections'    => rest_url( self::REST_NS . '/media-collections' ),
				'restBulk'           => rest_url( self::REST_NS . '/media-bulk-edit' ),
				'whereUsed'          => rest_url( self::REST_NS . '/where-used' ),
				// Core media route, built server-side on purpose. With "Plain"
				// permalinks the REST root is `/index.php?rest_route=/`, which the
				// JS cannot guess from the site address.
				'restMedia'          => rest_url( 'wp/v2/media/' ),
				'nonce'              => wp_create_nonce( 'wp_rest' ),
				// Explicit strings: wp_localize_script casts booleans (false → "").
				'foldersEnabled'     => Settings::folders_enabled() ? '1' : '0',
				'collectionsEnabled' => Settings::collections_enabled() ? '1' : '0',
				// Empty for anyone who cannot open that page: the panel hides the
				// link when there is no URL, rather than offering a door that
				// answers 403. Authors and editors browse the library too.
				'settingsUrl'        => current_user_can( 'manage_options' )
					? admin_url( 'admin.php?page=doubleyoupi-media-library' )
					: '',
				'currentUserId'      => get_current_user_id(),
				// "Mine" filter button only makes sense when several people upload.
				'mineFilter'         => $this->has_multiple_uploaders() ? '1' : '0',
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'enableNonce'        => wp_create_nonce( 'doubleyoupi_ml_enable_feature' ),
				'i18n'               => FolderManagerI18n::strings(),
			] );
		}

		// Supplementary scripts that depend on doubleyoupiMediaFolders.
		foreach ( [
			'dy-where-used'    => 'where-used.js',
			'dy-bulk-metadata' => 'bulk-metadata.js',
			'dy-media-filters' => 'media-filters.js',
			'dy-media-detail'  => 'media-detail.js',
		] as $handle => $filename ) {
			$path = Build::dir( __FILE__, 'assets/js/modules/media-library/' ) . $filename;
			if ( file_exists( $path ) ) {
				wp_enqueue_script(
					$handle,
					Build::url( __FILE__, 'assets/js/modules/media-library/' ) . $filename,
					[ 'jquery', 'media-views', 'doubleyoupi-media-folders' ],
					$ver,
					true
				);
			}
		}
	}

	/**
	 * Enables a feature (Folders or Collections) from the panel's "Enable" link.
	 * Sets the option to 1 and returns a success response — JS then reloads the
	 * page to reinitialise the section with its full functionality.
	 */
	public function ajax_enable_feature(): void {
		check_ajax_referer( 'doubleyoupi_ml_enable_feature', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$feature = isset( $_POST['feature'] ) ? sanitize_key( wp_unslash( (string) $_POST['feature'] ) ) : '';
		$option  = match ( $feature ) {
			'folders'     => Settings::OPTION_FOLDERS,
			'collections' => Settings::OPTION_COLLECTIONS,
			default       => '',
		};
		if ( '' === $option ) {
			wp_send_json_error( [ 'message' => 'unknown_feature' ] );
		}
		update_option( $option, true, false );
		wp_send_json_success( [ 'feature' => $feature ] );
	}

	// -------------------------------------------------------------------------
	// Upload into the current folder / collection
	// -------------------------------------------------------------------------

	/**
	 * Assign the freshly uploaded attachment to the folder and/or collection
	 * the user is currently viewing.
	 *
	 * Two channels feed this :
	 *   - $_POST['doubleyoupi_folder_id']     → grid mode multipart upload (Backbone wp.Uploader)
	 *   - $_POST['doubleyoupi_collection_id'] → same on the collection side
	 *
	 * An immediate term count refresh is forced on the affected terms so that
	 * the REST response returns an up-to-date badge count on the next call
	 * (loadFolderTree / loadCollections triggered by the JS `FileUploaded` event).
	 */
	public function assign_taxonomies_on_upload( int $post_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$folder_id = isset( $_POST['doubleyoupi_folder_id'] ) ? (int) $_POST['doubleyoupi_folder_id'] : 0;
		if ( $folder_id > 0 && term_exists( $folder_id, FolderTaxonomy::TAXONOMY ) ) {
			wp_set_object_terms( $post_id, [ $folder_id ], FolderTaxonomy::TAXONOMY );
			wp_update_term_count_now( [ $folder_id ], FolderTaxonomy::TAXONOMY );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$collection_id = isset( $_POST['doubleyoupi_collection_id'] ) ? (int) $_POST['doubleyoupi_collection_id'] : 0;
		if ( $collection_id > 0 && term_exists( $collection_id, CollectionTaxonomy::TAXONOMY ) ) {
			// append=true: a media file can belong to multiple collections.
			wp_set_object_terms( $post_id, [ $collection_id ], CollectionTaxonomy::TAXONOMY, true );
			wp_update_term_count_now( [ $collection_id ], CollectionTaxonomy::TAXONOMY );
		}
	}

	// -------------------------------------------------------------------------
	// REST routes
	// -------------------------------------------------------------------------

	/*
	 * REST namespace `doubleyoupi/v1` rather than squatting `wp/v2/media`.
	 * We do not touch WordPress core: if the internal spec ever changes
	 * (and it will), our REST surface remains isolated and independently
	 * versionable. The `v1` is not cosmetic — it is a contract.
	 */
	/**
	 * Totals behind the two non-folder entries of the panel.
	 *
	 * Both numbers have to agree with what clicking the entry actually shows,
	 * so they are counted with the SAME query the grid runs, minus the folder
	 * filter:
	 *   · statuses: `inherit`, plus `private` for whoever may read private
	 *     posts — exactly what wp_ajax_query_attachments allows;
	 *   · "without folder": the `NOT EXISTS` clause from
	 *     FolderTaxonomy::filter_ajax_query, not a subtraction of folder counts.
	 *     Subtracting would be wrong the moment one file sits in two folders.
	 *
	 * `posts_per_page => 1` with `fields => 'ids'`: we only ever read
	 * found_posts, so nothing but the count crosses the wire.
	 */
	public function rest_get_counts(): \WP_REST_Response {
		$statuses = [ 'inherit' ];
		if ( current_user_can( 'read_private_posts' ) ) {
			$statuses[] = 'private';
		}

		$base = [
			'post_type'              => 'attachment',
			'post_status'            => $statuses,
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];

		$all = new \WP_Query( $base );

		$orphans = new \WP_Query( array_merge( $base, [
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'tax_query' => [
				[
					'taxonomy' => FolderTaxonomy::TAXONOMY,
					'operator' => 'NOT EXISTS',
				],
			],
		] ) );

		return new \WP_REST_Response( [
			'total'      => (int) $all->found_posts,
			'unassigned' => (int) $orphans->found_posts,
		], 200 );
	}

	public function register_rest_routes(): void {
		$perm = static fn() => current_user_can( 'upload_files' );

		register_rest_route( self::REST_NS, '/media-folders', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_get_folders' ],
				'permission_callback' => $perm,
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rest_create_folder' ],
				'permission_callback' => $perm,
				'args'                => [
					'name'      => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
					'parent_id' => [ 'type' => 'integer', 'default' => 0 ],
					'order'     => [ 'type' => 'integer', 'default' => 0 ],
				],
			],
		] );

		register_rest_route( self::REST_NS, '/media-folders/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'rest_update_folder' ],
				'permission_callback' => $perm,
				'args'                => [
					'name'      => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'parent_id' => [ 'type' => 'integer' ],
					'order'     => [ 'type' => 'integer' ],
				],
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'rest_delete_folder' ],
				'permission_callback' => $perm,
			],
		] );

		/*
		 * Counts for the two panel entries that are not folders: "Show all
		 * media" and "Media without folder". A route of its own rather than two
		 * more keys on /media-folders, so the tree endpoint keeps returning a
		 * plain list and its shape stays a contract. The panel fetches both in
		 * parallel, so this costs no extra wait.
		 */
		register_rest_route( self::REST_NS, '/media-folders/counts', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_get_counts' ],
			'permission_callback' => $perm,
		] );

		register_rest_route( self::REST_NS, '/media-folders/reorder', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'rest_reorder_folders' ],
			'permission_callback' => $perm,
			'args'                => [
				'parent_id' => [ 'type' => 'integer', 'default' => 0 ],
				'ids'       => [ 'type' => 'array', 'required' => true, 'items' => [ 'type' => 'integer' ] ],
			],
		] );

		register_rest_route( self::REST_NS, '/media-folders/(?P<id>\d+)/assign', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'rest_assign_folder' ],
			'permission_callback' => $perm,
			'args'                => [
				'attachment_ids' => [ 'type' => 'array', 'required' => true, 'items' => [ 'type' => 'integer' ] ],
			],
		] );

		register_rest_route( self::REST_NS, '/media-bulk-edit', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'rest_bulk_edit' ],
			'permission_callback' => $perm,
			'args'                => [
				'ids'   => [ 'type' => 'array', 'required' => true, 'items' => [ 'type' => 'integer' ] ],
				'alt'   => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				'title' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Handlers REST
	// -------------------------------------------------------------------------

	public function rest_get_folders( \WP_REST_Request $request ): \WP_REST_Response {
		$terms = get_terms( [
			'taxonomy'   => FolderTaxonomy::TAXONOMY,
			'hide_empty' => false,
		] );

		if ( is_wp_error( $terms ) ) {
			return new \WP_REST_Response( [], 200 );
		}

		/*
		 * Force-recount all folder terms with the current update_count_callback
		 * (_update_generic_term_count). This corrects historical counts that were
		 * computed with the default _update_post_term_count which excluded orphan
		 * attachments (post_parent=0). After first call, counts are accurate and
		 * recount becomes a no-op for unchanged data.
		 */
		$term_ids = array_map( static fn( $t ) => (int) $t->term_id, $terms );
		if ( ! empty( $term_ids ) ) {
			wp_update_term_count_now( $term_ids, FolderTaxonomy::TAXONOMY );
			// Refresh local terms with new counts
			$terms = get_terms( [
				'taxonomy'   => FolderTaxonomy::TAXONOMY,
				'hide_empty' => false,
			] );
		}

		// Build the tree from the flat list.
		$map = [];
		foreach ( $terms as $term ) {
			$map[ $term->term_id ] = [
				'id'       => $term->term_id,
				// WordPress stores term names HTML-encoded (`A &amp; B`). The panel escapes
				// every value before inserting it, so sending the encoded form would
				// escape it twice and print the entity. Decode here: the client gets
				// plain text and does its own escaping.
				'name'     => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
				'parent'   => $term->parent,
				'order'    => (int) get_term_meta( $term->term_id, FolderTaxonomy::META_ORDER, true ),
				'count'    => (int) $term->count,
				'children' => [],
			];
		}

		$tree = [];
		foreach ( $map as $id => &$node ) {
			if ( $node['parent'] > 0 && isset( $map[ $node['parent'] ] ) ) {
				$map[ $node['parent'] ]['children'][] = &$node;
			} else {
				$tree[] = &$node;
			}
		}
		unset( $node );

		// Sort siblings by their stored order at EVERY depth, not just the root,
		// so drag-reordering is reflected throughout the tree.
		$this->sort_tree_by_order( $tree );

		return new \WP_REST_Response( array_values( $tree ), 200 );
	}

	/**
	 * Recursively sort a folder tree's siblings by their `order` value.
	 *
	 * @param array<int,array<string,mixed>> $nodes Passed by reference.
	 */
	private function sort_tree_by_order( array &$nodes ): void {
		usort( $nodes, static fn( $a, $b ) => $a['order'] <=> $b['order'] );
		foreach ( $nodes as &$node ) {
			if ( ! empty( $node['children'] ) ) {
				$this->sort_tree_by_order( $node['children'] );
			}
		}
		unset( $node );
	}

	/**
	 * Whether re-parenting $folder_id under $new_parent would create a cycle —
	 * i.e. the new parent is the folder itself or one of its descendants.
	 */
	private function would_create_cycle( int $new_parent, int $folder_id ): bool {
		if ( $new_parent <= 0 ) {
			return false;
		}
		if ( $new_parent === $folder_id ) {
			return true;
		}
		$ancestors = get_ancestors( $new_parent, FolderTaxonomy::TAXONOMY, 'taxonomy' );
		return in_array( $folder_id, array_map( 'intval', (array) $ancestors ), true );
	}

	public function rest_create_folder( \WP_REST_Request $request ): \WP_REST_Response {
		$name      = (string) $request->get_param( 'name' );
		$parent_id = (int) $request->get_param( 'parent_id' );
		$order     = (int) $request->get_param( 'order' );

		$result = wp_insert_term( $name, FolderTaxonomy::TAXONOMY, [
			'parent' => $parent_id > 0 ? $parent_id : 0,
		] );

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( [ 'message' => $result->get_error_message() ], 422 );
		}

		$term_id = $result['term_id'];
		update_term_meta( $term_id, FolderTaxonomy::META_ORDER, $order );

		$term = get_term( $term_id, FolderTaxonomy::TAXONOMY );
		return new \WP_REST_Response( [
			'id'       => $term_id,
			'name'     => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
			'parent'   => $term->parent,
			'order'    => $order,
			'count'    => 0,
			'children' => [],
		], 201 );
	}

	public function rest_update_folder( \WP_REST_Request $request ): \WP_REST_Response {
		$term_id = (int) $request->get_param( 'id' );
		$args    = [];

		if ( $request->has_param( 'name' ) ) {
			$args['name'] = (string) $request->get_param( 'name' );
		}
		if ( $request->has_param( 'parent_id' ) ) {
			$parent_id = (int) $request->get_param( 'parent_id' );
			if ( $this->would_create_cycle( $parent_id, $term_id ) ) {
				return new \WP_REST_Response(
					[ 'message' => __( 'A folder cannot be moved into itself or one of its subfolders.', 'doubleyoupi-refined-media-library' ) ],
					422
				);
			}
			$args['parent'] = $parent_id;
		}

		if ( ! empty( $args ) ) {
			$result = wp_update_term( $term_id, FolderTaxonomy::TAXONOMY, $args );
			if ( is_wp_error( $result ) ) {
				return new \WP_REST_Response( [ 'message' => $result->get_error_message() ], 422 );
			}
		}

		if ( $request->has_param( 'order' ) ) {
			update_term_meta( $term_id, FolderTaxonomy::META_ORDER, (int) $request->get_param( 'order' ) );
		}

		return new \WP_REST_Response( [ 'id' => $term_id, 'updated' => true ], 200 );
	}

	/**
	 * Persist a sibling order (and optional re-parent) after a drag.
	 *
	 * Body: { parent_id: int, ids: int[] } where `ids` is the full ordered list
	 * of the children of `parent_id`. Each id is moved under `parent_id` (if it
	 * was elsewhere) and given an `order` equal to its index. Rejected wholesale
	 * if any move would create a cycle.
	 */
	public function rest_reorder_folders( \WP_REST_Request $request ): \WP_REST_Response {
		$parent_id = (int) $request->get_param( 'parent_id' );
		$ids       = array_map( 'intval', (array) $request->get_param( 'ids' ) );

		foreach ( $ids as $id ) {
			if ( $this->would_create_cycle( $parent_id, $id ) ) {
				return new \WP_REST_Response(
					[ 'message' => __( 'A folder cannot be moved into itself or one of its subfolders.', 'doubleyoupi-refined-media-library' ) ],
					422
				);
			}
		}

		foreach ( $ids as $index => $id ) {
			$term = get_term( $id, FolderTaxonomy::TAXONOMY );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			if ( (int) $term->parent !== $parent_id ) {
				wp_update_term( $id, FolderTaxonomy::TAXONOMY, [ 'parent' => $parent_id ] );
			}
			update_term_meta( $id, FolderTaxonomy::META_ORDER, $index );
		}

		return new \WP_REST_Response( [ 'reordered' => true ], 200 );
	}

	public function rest_delete_folder( \WP_REST_Request $request ): \WP_REST_Response {
		$term_id = (int) $request->get_param( 'id' );
		$result  = wp_delete_term( $term_id, FolderTaxonomy::TAXONOMY );

		if ( is_wp_error( $result ) || $result === false ) {
			return new \WP_REST_Response( [ 'message' => __( 'Could not delete folder.', 'doubleyoupi-refined-media-library' ) ], 422 );
		}

		return new \WP_REST_Response( [ 'deleted' => true ], 200 );
	}

	public function rest_assign_folder( \WP_REST_Request $request ): \WP_REST_Response {
		$folder_id      = (int) $request->get_param( 'id' );
		$attachment_ids = array_map( 'intval', (array) $request->get_param( 'attachment_ids' ) );

		$assigned        = [];
		$already         = [];
		$affected_folders = [];

		foreach ( $attachment_ids as $att_id ) {
			if ( ! current_user_can( 'edit_post', $att_id ) ) {
				continue;
			}
			$current = wp_get_object_terms( $att_id, FolderTaxonomy::TAXONOMY, [ 'fields' => 'ids' ] );
			$current = is_array( $current ) ? array_map( 'intval', $current ) : [];

			if ( $folder_id > 0 && in_array( $folder_id, $current, true ) ) {
				$already[] = $att_id;
				continue;
			}

			// folder_id = 0 → remove from any folder (move to root).
			if ( $folder_id === 0 ) {
				if ( empty( $current ) ) {
					$already[] = $att_id;
					continue;
				}
				wp_set_object_terms( $att_id, [], FolderTaxonomy::TAXONOMY );
			} else {
				wp_set_object_terms( $att_id, [ $folder_id ], FolderTaxonomy::TAXONOMY );
			}
			$assigned[] = $att_id;

			// Track all folders affected (old + new) for term_count refresh
			$affected_folders = array_merge( $affected_folders, $current );
			if ( $folder_id > 0 ) { $affected_folders[] = $folder_id; }
		}

		// Force WP to recount affected terms — wp_set_object_terms doesn't always
		// update term_count immediately for non-public taxonomies.
		$affected_folders = array_unique( array_filter( $affected_folders ) );
		if ( ! empty( $affected_folders ) ) {
			wp_update_term_count_now( $affected_folders, FolderTaxonomy::TAXONOMY );
		}

		return new \WP_REST_Response( [ 'assigned' => $assigned, 'already' => $already ], 200 );
	}

	public function rest_bulk_edit( \WP_REST_Request $request ): \WP_REST_Response {
		$ids   = array_map( 'intval', (array) $request->get_param( 'ids' ) );
		$alt   = (string) $request->get_param( 'alt' );
		$title = (string) $request->get_param( 'title' );

		$updated = [];
		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			if ( $alt !== '' ) {
				update_post_meta( $id, '_wp_attachment_image_alt', $alt );
			}
			if ( $title !== '' ) {
				wp_update_post( [ 'ID' => $id, 'post_title' => $title ] );
			}
			$updated[] = $id;
		}

		return new \WP_REST_Response( [ 'updated' => $updated ], 200 );
	}
}
