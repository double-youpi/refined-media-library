<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary\Migration;

use DoubleYoupi\Core\Build;

use DoubleYoupi\Core\AbstractModule;
use DoubleYoupi\Modules\MediaLibrary\CollectionTaxonomy;
use DoubleYoupi\Modules\MediaLibrary\FolderTaxonomy;
use DoubleYoupi\Modules\MediaLibrary\Migration\Contracts\SourceAdapter;
use DoubleYoupi\Admin\Access;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrator for the migration from third-party folder plugins.
 *
 * Drives:
 *   - source detection (the "Import" tab of the Refined Media Library page)
 *   - REST routes (preview / run / rollback)
 *   - the idempotent, resumable, and reversible batch engine
 *
 * Product guarantees:
 *   - NON-DESTRUCTIVE: we read from the source and write to OUR taxonomies; we
 *     never touch the source plugin's data.
 *   - DECOUPLED: no class from the source plugin is loaded (SQL/taxonomy reads only).
 *   - REVERSIBLE: every term created is tagged (_doubleyoupi_migrated_from) → targeted rollback.
 */
final class MigrationManager extends AbstractModule {

	private const REST_NS    = 'doubleyoupi/v1';
	private const META_FROM  = '_doubleyoupi_migrated_from';
	private const REL_BATCH  = 200;

	public function id(): string {
		return 'media-library-migration';
	}

	public function name(): string {
		return 'Refined Media Library — Migration';
	}

	public function boot(): void {
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	// -------------------------------------------------------------------------
	// Assets — only on the Import tab of the Refined Media Library page.
	// -------------------------------------------------------------------------

	public function enqueue_assets( string $hook ): void {
		$is_ml_page = str_contains( $hook, 'doubleyoupi-media-library' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch for asset enqueue; no state change
		$tab           = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		$is_import_tab = ( 'import' === $tab );
		if ( ! $is_ml_page || ! $is_import_tab ) {
			return;
		}

		$css = Build::dir( __FILE__, 'assets/css/modules/media-library/migration.css' );
		if ( file_exists( $css ) ) {
			wp_enqueue_style(
				'doubleyoupi-ml-migration',
				Build::url( __FILE__, 'assets/css/modules/media-library/migration.css' ),
				[],
				DOUBLEYOUPI_VERSION
			);
		}

		// Designed dialog system (the rollback action uses it).
		\DoubleYoupi\Modules\MediaLibrary\FolderManager::enqueue_dialog_assets( DOUBLEYOUPI_VERSION );

		$js = Build::dir( __FILE__, 'assets/js/modules/media-library/migration.js' );
		if ( file_exists( $js ) ) {
			wp_enqueue_script(
				'doubleyoupi-ml-migration',
				Build::url( __FILE__, 'assets/js/modules/media-library/migration.js' ),
				[ 'doubleyoupi-ml-dialog' ],
				DOUBLEYOUPI_VERSION,
				true
			);
			wp_localize_script( 'doubleyoupi-ml-migration', 'doubleyoupiMlMigration', [
				'restBase' => rest_url( self::REST_NS . '/media-migration' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'i18n'     => MigrationI18n::strings(),
			] );
		}
	}

	// -------------------------------------------------------------------------
	// REST
	// -------------------------------------------------------------------------

	public function register_rest_routes(): void {
		// Site-wide import: previewing, running and rolling back all create or
		// delete folder/collection terms across the whole site, so these routes
		// require full admin capability (not merely upload_files).
		$perm = static fn() => current_user_can( Access::CAP );

		register_rest_route( self::REST_NS, '/media-migration/sources', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_sources' ],
			'permission_callback' => $perm,
		] );

		register_rest_route( self::REST_NS, '/media-migration/(?P<source>[a-z0-9\-]+)/preview', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'rest_preview' ],
			'permission_callback' => $perm,
		] );

		register_rest_route( self::REST_NS, '/media-migration/(?P<source>[a-z0-9\-]+)/run', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'rest_run' ],
			'permission_callback' => $perm,
			'args'                => [
				'reset' => [ 'type' => 'boolean', 'default' => false ],
			],
		] );

		register_rest_route( self::REST_NS, '/media-migration/(?P<source>[a-z0-9\-]+)/rollback', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'rest_rollback' ],
			'permission_callback' => $perm,
		] );
	}

	/** List of present sources along with their counters. */
	public function rest_sources( \WP_REST_Request $request ): \WP_REST_Response {
		$out = [];
		foreach ( SourceDetector::present() as $adapter ) {
			$out[] = [
				'key'       => $adapter->key(),
				'label'     => $adapter->label(),
				'active'    => $this->plugin_active_hint( $adapter ),
				'folders'   => $adapter->count_terms(),
				'relations' => $adapter->count_relations(),
				'migrated'  => $this->migrated_count( $adapter->key() ),
			];
		}
		return new \WP_REST_Response( $out, 200 );
	}

	/** Preview (dry-run): tree + counters, NO writes. */
	public function rest_preview( \WP_REST_Request $request ): \WP_REST_Response {
		$adapter = SourceDetector::get( (string) $request->get_param( 'source' ) );
		if ( ! $adapter || ! $adapter->is_present() ) {
			return new \WP_REST_Response( [ 'message' => 'Source not found.' ], 404 );
		}

		$terms       = $adapter->read_terms();
		$folders     = 0;
		$collections = 0;
		foreach ( $terms as $t ) {
			if ( $t['kind'] === 'collection' ) {
				$collections++;
			} else {
				$folders++;
			}
		}

		return new \WP_REST_Response( [
			'tree'        => $this->build_tree( $terms ),
			'folders'     => $folders,
			'collections' => $collections,
			'relations'   => $adapter->count_relations(),
		], 200 );
	}

	/**
	 * Processes one migration tick. The JS keeps calling until `done = true`.
	 *
	 * Pass 1 (phase 'terms')     : creates all folders/collections in a single shot.
	 * Pass 2 (phase 'relations') : assigns attachments in batches of REL_BATCH.
	 */
	public function rest_run( \WP_REST_Request $request ): \WP_REST_Response {
		$source  = (string) $request->get_param( 'source' );
		$adapter = SourceDetector::get( $source );
		if ( ! $adapter || ! $adapter->is_present() ) {
			return new \WP_REST_Response( [ 'message' => 'Source not found.' ], 404 );
		}

		$reset = (bool) $request->get_param( 'reset' );
		$state = $reset ? null : MigrationState::load();
		if ( ! $state || $state->source !== $source ) {
			$state              = new MigrationState( $source );
			$state->terms_total = $adapter->count_terms();
			$state->rel_total   = $adapter->count_relations();
		}

		if ( $state->phase === 'terms' ) {
			$this->process_terms( $adapter, $state );
			$state->phase  = 'relations';
			$state->offset = 0;
			$state->save();
			return new \WP_REST_Response( $this->progress( $state ), 200 );
		}

		if ( $state->phase === 'relations' ) {
			$batch = $adapter->read_relations( $state->offset, self::REL_BATCH );
			$this->process_relations( $batch, $state );
			$state->offset  += count( $batch );
			$state->rel_done = min( $state->offset, $state->rel_total );

			$finished = count( $batch ) < self::REL_BATCH || $state->offset >= $state->rel_total;
			if ( $finished ) {
				$this->finalize( $state );
				$state->phase = 'done';
				MigrationState::clear();
				return new \WP_REST_Response( $this->progress( $state ), 200 );
			}

			$state->save();
			return new \WP_REST_Response( $this->progress( $state ), 200 );
		}

		return new \WP_REST_Response( $this->progress( $state ), 200 );
	}

	/** Deletes only the terms created by this source's migration. */
	public function rest_rollback( \WP_REST_Request $request ): \WP_REST_Response {
		$source  = (string) $request->get_param( 'source' );
		$deleted = 0;

		$terms = get_terms( [
			'taxonomy'   => [ FolderTaxonomy::TAXONOMY, CollectionTaxonomy::TAXONOMY ],
			'hide_empty' => false,
			'meta_key'   => self::META_FROM, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => $source,          // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		] );

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$res = wp_delete_term( $term->term_id, $term->taxonomy );
				if ( $res === true ) {
					$deleted++;
				}
			}
		}

		MigrationState::clear();
		return new \WP_REST_Response( [ 'deleted' => $deleted ], 200 );
	}

	// -------------------------------------------------------------------------
	// Engine
	// -------------------------------------------------------------------------

	/**
	 * Creates all terms (folders + collections), parents before children, recording
	 * the src_id → term mapping in the migration state.
	 */
	private function process_terms( SourceAdapter $adapter, MigrationState $state ): void {
		$terms  = $adapter->read_terms();
		$source = $state->source;

		foreach ( $this->bfs_order( $terms ) as $term ) {
			$src_id = $term['src_id'];
			if ( isset( $state->map[ $src_id ] ) ) {
				continue; // already migrated (resuming / re-run)
			}

			$is_collection = $term['kind'] === 'collection';
			$taxonomy      = $is_collection ? CollectionTaxonomy::TAXONOMY : FolderTaxonomy::TAXONOMY;

			// Parent is only relevant for folders (collections are flat).
			$parent = 0;
			if ( ! $is_collection && $term['parent'] > 0 && isset( $state->map[ $term['parent'] ] ) ) {
				$candidate = $state->map[ $term['parent'] ];
				if ( $candidate['tax'] === FolderTaxonomy::TAXONOMY ) {
					$parent = $candidate['tid'];
				}
			}

			$result    = wp_insert_term( $term['name'], $taxonomy, [ 'parent' => $parent ] );
			$is_new    = ! is_wp_error( $result );
			$term_id   = 0;

			if ( $is_new ) {
				$term_id = (int) $result['term_id'];
				// Reversibility tag + sort order: set ONLY on a newly created term, to
				// avoid claiming ownership (or letting rollback delete) a folder that
				// the user had already created manually.
				update_term_meta( $term_id, self::META_FROM, $source );
				if ( ! $is_collection ) {
					update_term_meta( $term_id, FolderTaxonomy::META_ORDER, $term['order'] );
				}
			} elseif ( $result->get_error_code() === 'term_exists' ) {
				$term_id = (int) $result->get_error_data( 'term_exists' );
			}

			if ( $term_id > 0 ) {
				$state->map[ $src_id ] = [ 'tid' => $term_id, 'tax' => $taxonomy ];
			}
		}

		$state->terms_done = count( $state->map );
	}

	/**
	 * Assigns a batch of attachments to their target terms.
	 *
	 * @param array<int,array{att_id:int,src_id:int}> $batch
	 */
	private function process_relations( array $batch, MigrationState $state ): void {
		foreach ( $batch as $rel ) {
			$entry = $state->map[ $rel['src_id'] ] ?? null;
			if ( ! $entry ) {
				continue; // source folder not migrated (orphan) → skip
			}

			$att_id = $rel['att_id'];
			if ( get_post_type( $att_id ) !== 'attachment' ) {
				continue; // relation pointing to a deleted attachment
			}

			if ( $entry['tax'] === FolderTaxonomy::TAXONOMY ) {
				// One attachment = one folder. Do not overwrite an existing
				// Double Youpi classification (re-migration / multi-source).
				$current = wp_get_object_terms( $att_id, FolderTaxonomy::TAXONOMY, [ 'fields' => 'ids' ] );
				if ( is_array( $current ) && ! empty( $current ) ) {
					continue;
				}
				wp_set_object_terms( $att_id, [ $entry['tid'] ], FolderTaxonomy::TAXONOMY );
			} else {
				// Collections: append — an attachment can belong to multiple collections.
				wp_set_object_terms( $att_id, [ $entry['tid'] ], CollectionTaxonomy::TAXONOMY, true );
			}
		}
	}

	/**
	 * Recounts all touched terms in a single pass (not O(n) per attachment).
	 *
	 * We call _update_generic_term_count() DIRECTLY rather than wp_update_term_count_now():
	 * the latter falls back to _update_post_term_count (which excludes orphaned attachments
	 * that have no published post parent) instead of the taxonomy's generic callback.
	 * The generic counter counts all relations — that is the correct count for a media
	 * library (see the same choice in FolderTaxonomy::register_taxonomy).
	 */
	private function finalize( MigrationState $state ): void {
		$by_tax = [ FolderTaxonomy::TAXONOMY => [], CollectionTaxonomy::TAXONOMY => [] ];
		foreach ( $state->map as $entry ) {
			$by_tax[ $entry['tax'] ][] = $entry['tid'];
		}
		foreach ( $by_tax as $tax => $ids ) {
			$ids = array_unique( array_filter( $ids ) );
			if ( ! empty( $ids ) ) {
				_update_generic_term_count( $ids, get_taxonomy( $tax ) );
				clean_term_cache( $ids, $tax );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Sorts terms parents-before-children (BFS), robust against missing parents
	 * or cycles: a term whose parent cannot be found is treated as a root item.
	 *
	 * @param array<int,array{src_id:int,name:string,parent:int,order:int,kind:string}> $terms
	 * @return array<int,array{src_id:int,name:string,parent:int,order:int,kind:string}>
	 */
	private function bfs_order( array $terms ): array {
		$by_id    = [];
		$children = [];
		foreach ( $terms as $t ) {
			$by_id[ $t['src_id'] ] = $t;
		}
		foreach ( $terms as $t ) {
			$parent = ( $t['parent'] > 0 && isset( $by_id[ $t['parent'] ] ) ) ? $t['parent'] : 0;
			$children[ $parent ][] = $t['src_id'];
		}

		$ordered = [];
		$visited = [];
		$queue   = $children[0] ?? [];
		while ( ! empty( $queue ) ) {
			$id = array_shift( $queue );
			if ( isset( $visited[ $id ] ) ) {
				continue; // cycle guard
			}
			$visited[ $id ] = true;
			$ordered[]      = $by_id[ $id ];
			foreach ( $children[ $id ] ?? [] as $child ) {
				$queue[] = $child;
			}
		}

		// Safety net: terms never reached (pure cycle) → appended as root items.
		foreach ( $terms as $t ) {
			if ( ! isset( $visited[ $t['src_id'] ] ) ) {
				$ordered[] = $t;
			}
		}
		return $ordered;
	}

	/**
	 * Builds a nested tree for the preview.
	 *
	 * @param array<int,array{src_id:int,name:string,parent:int,order:int,kind:string}> $terms
	 * @return array<int,array<string,mixed>>
	 */
	private function build_tree( array $terms ): array {
		$nodes = [];
		foreach ( $terms as $t ) {
			$nodes[ $t['src_id'] ] = [
				'name'     => $t['name'],
				'kind'     => $t['kind'],
				'children' => [],
			];
		}
		$tree = [];
		foreach ( $terms as $t ) {
			$id = $t['src_id'];
			if ( $t['parent'] > 0 && isset( $nodes[ $t['parent'] ] ) && $t['kind'] !== 'collection' ) {
				$nodes[ $t['parent'] ]['children'][] = &$nodes[ $id ];
			} else {
				$tree[] = &$nodes[ $id ];
			}
		}
		return $tree;
	}

	private function migrated_count( string $source ): int {
		$terms = get_terms( [
			'taxonomy'   => [ FolderTaxonomy::TAXONOMY, CollectionTaxonomy::TAXONOMY ],
			'hide_empty' => false,
			'fields'     => 'ids',
			'meta_key'   => self::META_FROM, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => $source,          // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		] );
		return is_array( $terms ) ? count( $terms ) : 0;
	}

	/** Heuristic for "plugin still active" (loosely — we look for any sign of data presence). */
	private function plugin_active_hint( SourceAdapter $adapter ): bool {
		return $adapter->is_present();
	}

	/** @return array<string,mixed> */
	private function progress( MigrationState $state ): array {
		return [
			'phase'       => $state->phase,
			'percent'     => $state->percent(),
			'terms_done'  => $state->terms_done,
			'terms_total' => $state->terms_total,
			'rel_done'    => $state->rel_done,
			'rel_total'   => $state->rel_total,
			'done'        => $state->phase === 'done',
		];
	}

	// -------------------------------------------------------------------------
	// "Import" tab
	// -------------------------------------------------------------------------

	public function render_tab(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		?>
		<p class="description">
			<?php esc_html_e( 'Import folders and collections from another media library plugin. Your media stay in place. This only re-creates the folder structure inside Refined Media Library, and the source plugin is never modified, so you can verify the result before removing it.', 'doubleyoupi-refined-media-library' ); ?>
		</p>
		<div id="dy-ml-migration" data-loading="1">
			<p><span class="spinner is-active" style="float:none"></span></p>
		</div>
		<?php
	}
}
