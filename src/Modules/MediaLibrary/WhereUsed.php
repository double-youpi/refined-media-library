<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary;

use DoubleYoupi\Core\AbstractModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * For each attachment, returns the list of posts/pages that use it.
 *
 * Works on demand via a REST endpoint.
 * Results are cached (transient, 1 hour) and invalidated on save_post.
 *
 * Tables scanned:
 *   1. wp_posts.post_content   (LIKE %basename%)
 *   2. wp_postmeta             (featured image _thumbnail_id)
 *   3. wp_postmeta             (meta_value = attachment_id — ACF image field, etc.)
 *   4. wp_postmeta             (meta_value LIKE %basename% — URLs in custom fields)
 *   5. wp_posts.post_parent    (attachment linked to a parent post)
 */
class WhereUsed extends AbstractModule {

	private const CACHE_TTL   = 3600; // 1 hour
	private const REST_ROUTE  = '/where-used/(?P<id>\d+)';
	private const REST_NS     = 'doubleyoupi/v1';

	public function id(): string {
		return 'media-library-where-used';
	}

	public function name(): string {
		return 'Refined Media Library — Where Used';
	}

	public function boot(): void {
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'save_post', [ $this, 'invalidate_cache' ], 10, 1 );
	}

	public function register_rest_routes(): void {
		register_rest_route(
			self::REST_NS,
			self::REST_ROUTE,
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_where_used' ],
				// Tie access to the specific attachment: this endpoint exposes the
				// list of posts/pages referencing it (with edit links), so require
				// the caller to be able to edit that attachment, not just upload.
				'permission_callback' => static fn( $request ) => current_user_can( 'edit_post', (int) $request['id'] ),
				'args'                => [
					'id' => [
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					],
				],
			]
		);
	}

	public function rest_where_used( \WP_REST_Request $request ): \WP_REST_Response {
		$id     = (int) $request->get_param( 'id' );
		$usages = self::find_usages( $id );

		// Per-post capability filter: never disclose a referencing post (its
		// title, permalink and edit link) to a user who cannot edit that post.
		// Done here, per-request, so the shared per-attachment cache built by
		// find_usages() stays user-agnostic.
		$usages = array_values(
			array_filter(
				$usages,
				static fn( array $row ): bool => current_user_can( 'edit_post', (int) $row['id'] )
			)
		);

		return new \WP_REST_Response( $usages );
	}

	public function invalidate_cache( int $post_id ): void {
		// Invalidate the cache for all attachments when a post is updated.
		// We do not know which attachments are used in that post, so we purge globally.
		// The cache is lightweight (1 h TTL, modest size), so a global purge is acceptable.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk purge of our own transients; this IS the cache-invalidation path
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_doubleyoupi_ml_wu_%'
			   OR option_name LIKE '_transient_timeout_doubleyoupi_ml_wu_%'"
		);
	}

	/**
	 * Returns the posts/pages that use a given attachment.
	 *
	 * @return array<int, array{id:int, title:string, url:string, post_type:string, edit_url:string}>
	 */
	public static function find_usages( int $attachment_id ): array {
		$cache_key = 'doubleyoupi_ml_wu_' . $attachment_id;
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			return (array) $cached;
		}

		$post = get_post( $attachment_id );
		if ( ! $post || $post->post_type !== 'attachment' ) {
			return [];
		}

		$file     = get_attached_file( $attachment_id );
		$basename = $file ? basename( $file ) : '';
		$url      = wp_get_attachment_url( $attachment_id );
		$results  = [];
		$seen     = [];

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- result cached per-attachment in a transient (see $cache_key); table names from $wpdb properties, values bound via $wpdb->prepare()

		// 1. Featured image (thumbnail).
		$featured = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_type, p.post_status
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE pm.meta_key = '_thumbnail_id' AND pm.meta_value = %s
				  AND p.post_status NOT IN ('auto-draft','trash','revision')",
				(string) $attachment_id
			)
		);
		foreach ( $featured as $row ) {
			$id = (int) $row->ID;
			if ( ! isset( $seen[ $id ] ) ) {
				$seen[ $id ] = true;
				$results[]   = self::format_row( $row );
			}
		}

		// 2. Reference by ID in post_meta (ACF image field, etc.).
		if ( $attachment_id > 0 ) {
			$meta_id = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT p.ID, p.post_title, p.post_type, p.post_status
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					WHERE pm.meta_value = %s
					  AND pm.meta_key != '_thumbnail_id'
					  AND p.post_type NOT IN ('attachment','revision','nav_menu_item')
					  AND p.post_status NOT IN ('auto-draft','trash')
					LIMIT 50",
					(string) $attachment_id
				)
			);
			foreach ( $meta_id as $row ) {
				$id = (int) $row->ID;
				if ( ! isset( $seen[ $id ] ) ) {
					$seen[ $id ] = true;
					$results[]   = self::format_row( $row );
				}
			}
		}

		// 3. Reference by basename in post_content (native HTML, Gutenberg JSON blocks, shortcodes).
		if ( $basename ) {
			$content_refs = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_type, post_status
					FROM {$wpdb->posts}
					WHERE post_content LIKE %s
					  AND post_type NOT IN ('attachment','revision','nav_menu_item')
					  AND post_status NOT IN ('auto-draft','trash')
					LIMIT 50",
					'%' . $wpdb->esc_like( $basename ) . '%'
				)
			);
			foreach ( $content_refs as $row ) {
				$id = (int) $row->ID;
				if ( ! isset( $seen[ $id ] ) ) {
					$seen[ $id ] = true;
					$results[]   = self::format_row( $row );
				}
			}
		}

		// 4. URL reference in post_meta.
		if ( $basename ) {
			$meta_url = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT p.ID, p.post_title, p.post_type, p.post_status
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					WHERE pm.meta_value LIKE %s
					  AND p.post_type NOT IN ('attachment','revision','nav_menu_item')
					  AND p.post_status NOT IN ('auto-draft','trash')
					LIMIT 50",
					'%' . $wpdb->esc_like( $basename ) . '%'
				)
			);
			foreach ( $meta_url as $row ) {
				$id = (int) $row->ID;
				if ( ! isset( $seen[ $id ] ) ) {
					$seen[ $id ] = true;
					$results[]   = self::format_row( $row );
				}
			}
		}

		// 5. Attachment directly linked to a parent post.
		if ( (int) $post->post_parent > 0 ) {
			$parent = get_post( $post->post_parent );
			if ( $parent && ! isset( $seen[ $parent->ID ] ) ) {
				$seen[ $parent->ID ] = true;
				$results[]           = self::format_row( $parent );
			}
		}

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// 6. The other sites of the network.
		if ( $basename && is_multisite() ) {
			foreach ( self::network_usages( $basename ) as $row ) {
				$results[] = $row;
			}
		}

		set_transient( $cache_key, $results, self::CACHE_TTL );
		return $results;
	}

	/**
	 * Uses of this file found on the OTHER sites of the network.
	 *
	 * Each site owns its own tables, so everything above only ever saw the site
	 * being browsed. Telling someone a file is used nowhere, while a second
	 * site of their network displays it on its home page, is how a file gets
	 * deleted by hand with a clear conscience.
	 *
	 * Matched by name only. An ID belongs to the site that issued it: number 42
	 * on the shop is not number 42 on the blog, and reading one as the other
	 * would invent uses that do not exist.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function network_usages( string $basename ): array {
		global $wpdb;

		$needle  = '%' . $wpdb->esc_like( $basename ) . '%';
		$current = get_current_blog_id();
		$rows    = [];

		$sites = get_sites( [
			'number' => (int) apply_filters( 'doubleyoupi_ml_where_used_network_site_limit', 0 ),
			'fields' => 'ids',
		] );

		foreach ( $sites as $blog_id ) {
			$blog_id = (int) $blog_id;
			if ( $blog_id === $current || count( $rows ) >= 50 ) {
				continue;
			}

			switch_to_blog( $blog_id );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- caller caches the result per attachment; table names from $wpdb properties, value bound via $wpdb->prepare()
			$found = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT p.ID, p.post_title, p.post_type, p.post_status
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					WHERE p.post_type NOT IN ('attachment','revision','nav_menu_item')
					  AND p.post_status NOT IN ('auto-draft','trash')
					  AND ( p.post_content LIKE %s OR pm.meta_value LIKE %s )
					LIMIT 20",
					$needle,
					$needle
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			// Built while still switched, so the permalink and the edit link
			// point at the site that actually holds the page.
			$site_name = get_bloginfo( 'name' );
			foreach ( $found as $row ) {
				$formatted              = self::format_row( $row );
				$formatted['site_id']   = $blog_id;
				$formatted['site_name'] = (string) $site_name;
				$rows[]                 = $formatted;
			}

			restore_current_blog();
		}

		return $rows;
	}

	/**
	 * A row is site-agnostic by default; network_usages() adds site_id and
	 * site_name when the page lives on another site of the network.
	 *
	 * @return array{id:int, title:string, url:string, post_type:string, edit_url:string}
	 */
	private static function format_row( object $row ): array {
		$id = (int) $row->ID;
		return [
			'id'        => $id,
			'title'     => (string) $row->post_title,
			'url'       => (string) get_permalink( $id ),
			'post_type' => (string) $row->post_type,
			'edit_url'  => (string) get_edit_post_link( $id, 'raw' ),
		];
	}
}
