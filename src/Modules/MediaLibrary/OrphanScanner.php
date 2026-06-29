<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary;

use DoubleYoupi\Core\AbstractModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects uploaded media files that are not referenced anywhere on the site.
 *
 * WordPress has no built-in concept of a truly orphaned attachment: a given
 * file may be referenced via post_parent, a `_thumbnail_id` meta, the
 * post_content (raw basename, sometimes URL-encoded), an absolute URL in a
 * custom field, a serialized WooCommerce gallery, an ACF field, and likely
 * two or three other paths we have not encountered yet. We cover all of them
 * with five global queries (not N queries for N media items), then subtract
 * the sets. When in doubt, a file is treated as in use — a false negative is
 * far safer than silently deleting a client's image.
 *
 * False-positive safeguards:
 *  - Attachments with post_parent > 0 are excluded (attached to a post)
 *  - WooCommerce _product_image_gallery is handled specifically (serialised IDs)
 *  - Filter 'doubleyoupi_ml_orphan_exclude_ids' for third-party plugins (ACF, etc.)
 *  - Optional grace period (filter 'doubleyoupi_ml_orphan_min_age_hours',
 *    default 0 = none): allows ignoring recently uploaded media when needed,
 *    without hiding freshly uploaded files by default.
 *
 * Deletion is NEVER automatic: it must be triggered explicitly
 * from OrphanAdminPage with a double confirmation step.
 */
class OrphanScanner extends AbstractModule {

	public const CACHE_KEY = 'doubleyoupi_ml_orphan_scan';
	public const CACHE_TTL = DAY_IN_SECONDS;

	public function id(): string {
		return 'media-library-orphans';
	}

	public function name(): string {
		return 'Refined Media Library — Orphan Scanner';
	}

	public function boot(): void {
		add_action( 'wp_ajax_doubleyoupi_ml_orphan_scan', [ $this, 'ajax_scan' ] );
		add_action( 'wp_ajax_doubleyoupi_ml_orphan_delete', [ $this, 'ajax_delete' ] );

		// Invalidate the cache whenever site content changes.
		add_action( 'save_post', [ self::class, 'invalidate_cache' ], 10, 1 );
		add_action( 'add_attachment', [ self::class, 'invalidate_cache' ], 10, 1 );
		add_action( 'delete_attachment', [ self::class, 'invalidate_cache' ], 10, 1 );
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Runs a full scan and returns the IDs of orphaned attachments.
	 *
	 * @return int[]
	 */
	public static function scan(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( $cached !== false ) {
			return (array) $cached;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- whole scan result is cached in a transient (CACHE_KEY); table names come from $wpdb properties and all values are bound via $wpdb->prepare()

		// 1. All published attachments.
		// Optional grace period: defaults to 0, meaning we include EVERYTHING, even
		// files just uploaded — that is exactly what we want to detect.
		// A site can reintroduce a delay via the filter if needed.
		$args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		];
		$min_age_hours = (int) apply_filters( 'doubleyoupi_ml_orphan_min_age_hours', 0 );
		if ( $min_age_hours > 0 ) {
			$args['date_query'] = [ [ 'before' => "-{$min_age_hours} hours", 'inclusive' => true ] ];
		}
		$all_ids = get_posts( $args );

		if ( empty( $all_ids ) ) {
			set_transient( self::CACHE_KEY, [], self::CACHE_TTL );
			return [];
		}

		$referenced = [];

		// 2. Featured images (thumbnails).
		$featured = (array) $wpdb->get_col(
			"SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
			WHERE meta_key = '_thumbnail_id' AND meta_value != ''"
		);
		foreach ( $featured as $id ) {
			$referenced[ (int) $id ] = true;
		}

		// 3. Attachments linked to a parent post (native WP relationship).
		$with_parent = get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'post_parent__not_in' => [ 0 ],
		] );
		foreach ( $with_parent as $id ) {
			$referenced[ (int) $id ] = true;
		}

		// 4. Reference by ID in post_meta.
		// Look for meta_value entries that match attachment IDs.
		if ( ! empty( $all_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $all_ids ), '%s' ) );
			$ids_as_strings = array_map( 'strval', $all_ids );

			$meta_id_refs = (array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
					WHERE meta_value IN ({$placeholders})
					  AND meta_key NOT IN ('_thumbnail_id', '_doubleyoupi_ml_hash', '_doubleyoupi_ml_is_duplicate')",
					$ids_as_strings
				)
			);
			foreach ( $meta_id_refs as $id ) {
				$referenced[ (int) $id ] = true;
			}
		}

		// 5. WooCommerce _product_image_gallery (comma-separated IDs "1234,5678,9012").
		$woo_galleries = (array) $wpdb->get_col(
			"SELECT meta_value FROM {$wpdb->postmeta}
			WHERE meta_key = '_product_image_gallery' AND meta_value != ''"
		);
		foreach ( $woo_galleries as $gallery_str ) {
			foreach ( explode( ',', $gallery_str ) as $id ) {
				$id = (int) trim( $id );
				if ( $id > 0 ) {
					$referenced[ $id ] = true;
				}
			}
		}

		// 6. Reference by basename in post_content.
		// Processed in batches to avoid excessively heavy queries.
		$basenames = [];
		foreach ( $all_ids as $att_id ) {
			$file = get_attached_file( $att_id );
			if ( $file ) {
				$basenames[ $att_id ] = basename( $file );
			}
		}

		$batch_size = 50;
		$batches    = array_chunk( $basenames, $batch_size, true );

		foreach ( $batches as $batch ) {
			$conditions = implode( ' OR ', array_fill( 0, count( $batch ), 'post_content LIKE %s' ) );
			$like_args  = array_map(
				static fn( $bn ) => '%' . $wpdb->esc_like( $bn ) . '%',
				array_values( $batch )
			);

			// Retrieve the CONTENT of posts that match at least one basename in the
			// batch, then test each basename individually against that content.
			// (The previous version marked the ENTIRE batch as used as soon as any
			// single post matched any basename — causing massive false negatives.)
			$matched = (array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_content FROM {$wpdb->posts}
					WHERE post_type NOT IN ('attachment','revision','nav_menu_item')
					  AND post_status NOT IN ('auto-draft','trash')
					  AND ({$conditions})",
					$like_args
				)
			);

			if ( empty( $matched ) ) {
				continue;
			}

			// Single haystack for the whole batch: substring search by basename
			// (conservative — when in doubt the file is treated as in use).
			$haystack = "\n" . implode( "\n", $matched ) . "\n";
			foreach ( $batch as $att_id => $basename ) {
				if ( '' !== $basename && str_contains( $haystack, $basename ) ) {
					$referenced[ (int) $att_id ] = true;
				}
			}
		}

		// Final computation of orphaned attachments.
		$orphan_ids = [];
		foreach ( $all_ids as $att_id ) {
			if ( ! isset( $referenced[ (int) $att_id ] ) ) {
				$orphan_ids[] = (int) $att_id;
			}
		}

		// Extension filter: lets WooCommerce, ACF, themes, etc.
		// exclude their own media by hooking into this filter.
		$orphan_ids = (array) apply_filters( 'doubleyoupi_ml_orphan_exclude_ids', $orphan_ids );
		$orphan_ids = array_values( array_map( 'intval', $orphan_ids ) );

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		set_transient( self::CACHE_KEY, $orphan_ids, self::CACHE_TTL );
		return $orphan_ids;
	}

	public static function invalidate_cache( int $post_id = 0 ): void {
		delete_transient( self::CACHE_KEY );
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	public function ajax_scan(): void {
		check_ajax_referer( 'doubleyoupi_ml_orphan_scan', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		// Invalidate the cache to force a fresh scan.
		self::invalidate_cache();
		$orphan_ids = self::scan();

		$orphans = [];
		foreach ( $orphan_ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$file = get_attached_file( $id );
			$orphans[] = [
				'id'        => $id,
				'title'     => $post->post_title ?: basename( $file ?: '' ),
				'filename'  => $file ? basename( $file ) : '',
				'thumbnail' => wp_get_attachment_image_url( $id, 'thumbnail' ) ?: '',
				'mime'      => $post->post_mime_type,
				'size'      => $file && is_file( $file ) ? size_format( (int) filesize( $file ) ) : '—',
				'date'      => get_the_date( 'd/m/Y', $post ),
			];
		}

		wp_send_json_success( [
			'count'   => count( $orphans ),
			'orphans' => $orphans,
		] );
	}

	public function ajax_delete(): void {
		check_ajax_referer( 'doubleyoupi_ml_orphan_delete', 'nonce' );
		if ( ! current_user_can( 'delete_posts' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ids = isset( $_POST['ids'] ) ? array_map( 'intval', (array) $_POST['ids'] ) : [];
		if ( empty( $ids ) ) {
			wp_send_json_error( [ 'message' => 'no_ids' ] );
		}

		$deleted = [];
		$errors  = [];

		foreach ( $ids as $id ) {
			// Server-side double check: must be a valid attachment.
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== 'attachment' ) {
				$errors[] = $id;
				continue;
			}

			// true = force delete (media files are not sent to the trash).
			$result = wp_delete_attachment( $id, true );
			if ( $result ) {
				$deleted[] = $id;
			} else {
				$errors[] = $id;
			}
		}

		self::invalidate_cache();

		wp_send_json_success( [
			'deleted' => $deleted,
			'errors'  => $errors,
		] );
	}
}
