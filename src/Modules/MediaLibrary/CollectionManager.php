<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary;

use DoubleYoupi\Core\AbstractModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collection CRUD via REST API.
 *
 * Difference vs FolderManager:
 *  - Flat collections (no parent/child tree)
 *  - A media file can belong to MULTIPLE collections (append=true)
 *  - /add and /remove endpoints handle membership without overwriting
 *
 * REST routes (namespace doubleyoupi/v1):
 *   GET    /media-collections              → list all collections
 *   POST   /media-collections              → create a collection
 *   PUT    /media-collections/{id}         → rename
 *   DELETE /media-collections/{id}         → delete (media files are not deleted)
 *   POST   /media-collections/{id}/add     → add media files to the collection
 *   POST   /media-collections/{id}/remove  → remove media files from the collection
 */
class CollectionManager extends AbstractModule {

	private const REST_NS = 'doubleyoupi/v1';

	public function id(): string {
		return 'media-library-collections';
	}

	public function name(): string {
		return 'Refined Media Library — Collection Manager';
	}

	public function boot(): void {
		if ( ! Settings::collections_enabled() ) {
			return;
		}
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	public function register_rest_routes(): void {
		$perm = static fn() => current_user_can( 'upload_files' );

		register_rest_route( self::REST_NS, '/media-collections', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_get_collections' ],
				'permission_callback' => $perm,
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rest_create_collection' ],
				'permission_callback' => $perm,
				'args'                => [
					'name' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
				],
			],
		] );

		register_rest_route( self::REST_NS, '/media-collections/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'rest_update_collection' ],
				'permission_callback' => $perm,
				'args'                => [
					'name' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				],
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'rest_delete_collection' ],
				'permission_callback' => $perm,
			],
		] );

		register_rest_route( self::REST_NS, '/media-collections/(?P<id>\d+)/add', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'rest_add_to_collection' ],
			'permission_callback' => $perm,
			'args'                => [
				'attachment_ids' => [ 'type' => 'array', 'required' => true, 'items' => [ 'type' => 'integer' ] ],
			],
		] );

		register_rest_route( self::REST_NS, '/media-collections/(?P<id>\d+)/remove', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'rest_remove_from_collection' ],
			'permission_callback' => $perm,
			'args'                => [
				'attachment_ids' => [ 'type' => 'array', 'required' => true, 'items' => [ 'type' => 'integer' ] ],
			],
		] );
	}

	// ── Handlers ──────────────────────────────────────────────────────────────

	public function rest_get_collections( \WP_REST_Request $request ): \WP_REST_Response {
		$terms = get_terms( [
			'taxonomy'   => CollectionTaxonomy::TAXONOMY,
			'hide_empty' => false,
			'orderby'    => 'name',
		] );

		if ( is_wp_error( $terms ) ) {
			return new \WP_REST_Response( [], 200 );
		}

		// Force-recount with the current callback (_update_generic_term_count)
		// to fix any historical counts computed with the old default.
		$term_ids = array_map( static fn( $t ) => (int) $t->term_id, $terms );
		if ( ! empty( $term_ids ) ) {
			wp_update_term_count_now( $term_ids, CollectionTaxonomy::TAXONOMY );
			$terms = get_terms( [
				'taxonomy'   => CollectionTaxonomy::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
			] );
		}

		return new \WP_REST_Response(
			array_values( array_map( static fn( $t ) => [
				'id'    => $t->term_id,
				'name'  => $t->name,
				'count' => (int) $t->count,
			], $terms ) ),
			200
		);
	}

	public function rest_create_collection( \WP_REST_Request $request ): \WP_REST_Response {
		$result = wp_insert_term( (string) $request->get_param( 'name' ), CollectionTaxonomy::TAXONOMY );

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( [ 'message' => $result->get_error_message() ], 422 );
		}

		$term = get_term( $result['term_id'], CollectionTaxonomy::TAXONOMY );
		return new \WP_REST_Response( [
			'id'    => $term->term_id,
			'name'  => $term->name,
			'count' => 0,
		], 201 );
	}

	public function rest_update_collection( \WP_REST_Request $request ): \WP_REST_Response {
		$id   = (int) $request->get_param( 'id' );
		$name = (string) $request->get_param( 'name' );

		if ( $name ) {
			$r = wp_update_term( $id, CollectionTaxonomy::TAXONOMY, [ 'name' => $name ] );
			if ( is_wp_error( $r ) ) {
				return new \WP_REST_Response( [ 'message' => $r->get_error_message() ], 422 );
			}
		}

		return new \WP_REST_Response( [ 'id' => $id, 'updated' => true ], 200 );
	}

	public function rest_delete_collection( \WP_REST_Request $request ): \WP_REST_Response {
		$id     = (int) $request->get_param( 'id' );
		$result = wp_delete_term( $id, CollectionTaxonomy::TAXONOMY );

		if ( is_wp_error( $result ) || $result === false ) {
			return new \WP_REST_Response( [ 'message' => __( 'Could not delete collection.', 'doubleyoupi-refined-media-library' ) ], 422 );
		}

		return new \WP_REST_Response( [ 'deleted' => true ], 200 );
	}

	public function rest_add_to_collection( \WP_REST_Request $request ): \WP_REST_Response {
		$id  = (int) $request->get_param( 'id' );
		$ids = array_map( 'intval', (array) $request->get_param( 'attachment_ids' ) );

		$added   = [];
		$already = [];
		foreach ( $ids as $att_id ) {
			if ( ! current_user_can( 'edit_post', $att_id ) ) { continue; }

			$current = wp_get_object_terms( $att_id, CollectionTaxonomy::TAXONOMY, [ 'fields' => 'ids' ] );
			$current = is_array( $current ) ? array_map( 'intval', $current ) : [];
			if ( in_array( $id, $current, true ) ) {
				$already[] = $att_id;
				continue;
			}

			$r = wp_set_object_terms( $att_id, [ $id ], CollectionTaxonomy::TAXONOMY, true );
			if ( ! is_wp_error( $r ) ) { $added[] = $att_id; }
		}

		// Force WP to recount the term — wp_set_object_terms doesn't always update
		// term_count immediately for non-public taxonomies.
		if ( ! empty( $added ) ) {
			wp_update_term_count_now( [ $id ], CollectionTaxonomy::TAXONOMY );
		}

		return new \WP_REST_Response( [ 'added' => $added, 'already' => $already ], 200 );
	}

	public function rest_remove_from_collection( \WP_REST_Request $request ): \WP_REST_Response {
		$id  = (int) $request->get_param( 'id' );
		$ids = array_map( 'intval', (array) $request->get_param( 'attachment_ids' ) );

		$removed = [];
		foreach ( $ids as $att_id ) {
			if ( ! current_user_can( 'edit_post', $att_id ) ) { continue; }
			$r = wp_remove_object_terms( $att_id, [ $id ], CollectionTaxonomy::TAXONOMY );
			if ( ! is_wp_error( $r ) && $r !== false ) { $removed[] = $att_id; }
		}

		return new \WP_REST_Response( [ 'removed' => $removed ], 200 );
	}
}
