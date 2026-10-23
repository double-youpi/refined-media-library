<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary;

use DoubleYoupi\Core\AbstractModule;
use DoubleYoupi\Admin\Access;

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
 * Nothing is erased here. The screen's delete action moves files to the trash,
 * where they keep their file on the server and can be put back in one click;
 * erasing for good is a separate, explicit act on that trash (ajax_purge), and
 * WordPress empties what is left after EMPTY_TRASH_DAYS. No detector can be
 * right about every way a theme or plugin points at a file, so this screen is
 * built never to be the last word.
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
		add_action( 'wp_ajax_doubleyoupi_ml_orphan_restore', [ $this, 'ajax_restore' ] );
		add_action( 'wp_ajax_doubleyoupi_ml_orphan_purge', [ $this, 'ajax_purge' ] );

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

		// 6. Reference by name in post_content.
		//
		// Searched by filename STEM rather than by basename: WordPress derives
		// every resized copy from the same stem (image.png becomes
		// image-300x200.png), so a page that only ever displays a thumbnail never
		// contains the original's basename. Hunting the basename alone reported
		// those originals as unused. See search_needle() for the short-name guard.
		$needles = [];
		foreach ( $all_ids as $att_id ) {
			$file = get_attached_file( $att_id );
			if ( $file ) {
				$needles[ $att_id ] = self::search_needle( basename( $file ) );
			}
		}

		self::mark_by_name(
			$needles,
			$referenced,
			"SELECT post_content FROM {$wpdb->posts}
			WHERE post_type NOT IN ('attachment','revision','nav_menu_item')
			  AND post_status NOT IN ('auto-draft','trash')
			  AND (%CONDITIONS%)",
			'post_content'
		);

		/*
		 * 7. The places nothing above ever looked at.
		 *
		 * Narrowed each time to the files still unaccounted for, so these extra
		 * queries only ever run against the handful of candidates left rather than
		 * the whole library.
		 */
		$left = self::still_unreferenced( $needles, $referenced );

		// 7a. A URL sitting in a custom field. Page builders and custom-field
		// plugins routinely store an image's URL instead of its ID, and pass 4
		// above only ever matched an ID. One Bricks or ACF page was enough to have
		// its images reported as unused.
		if ( $left ) {
			self::mark_by_name(
				$left,
				$referenced,
				self::meta_reference_sql(),
				'pm.meta_value'
			);
			$left = self::still_unreferenced( $left, $referenced );
		}

		// 7b. Options: the site logo, the header image, widgets, theme settings,
		// most builders' global data. No post table points at any of those, and
		// offering to delete a site's logo is exactly the mistake this tool must
		// never make.
		if ( $left ) {
			self::mark_by_name(
				$left,
				$referenced,
				"SELECT option_value FROM {$wpdb->options} WHERE (%CONDITIONS%)",
				'option_value'
			);
			$left = self::still_unreferenced( $left, $referenced );
		}

		// 7c. The logo and the header image are stored as bare IDs in the theme
		// mods, where no filename appears for 7b to find.
		if ( $left ) {
			foreach ( self::theme_attachment_ids() as $id ) {
				$referenced[ $id ] = true;
			}
			$left = self::still_unreferenced( $left, $referenced );
		}

		// 7d. A bare ID, with no filename anywhere near it.
		//
		// [gallery ids="12,34"], an ACF gallery holding a serialised array of IDs,
		// a builder storing {"id":12}, an <img> carrying only class="wp-image-12".
		// Pass 4 above only matched a meta_value that IS the ID and nothing else,
		// so every one of these read as unused.
		if ( $left ) {
			self::mark_by_id( array_keys( $left ), $referenced );
			$left = self::still_unreferenced( $left, $referenced );
		}

		// 8. The rest of the network.
		//
		// Names only, never IDs — see mark_across_network().
		if ( $left && is_multisite() ) {
			self::mark_across_network( $left, $referenced );
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

	/**
	 * Media this screen has put in the trash, newest first.
	 *
	 * @return \WP_Post[]
	 */
	public static function trashed_media(): array {
		return get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'trash',
			'posts_per_page' => 200,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		] );
	}

	/**
	 * Put files back where they were. The attachment returns to the status it
	 * had before, so it reappears in the media library with its folders and its
	 * metadata untouched: nothing was ever taken apart.
	 */
	public function ajax_restore(): void {
		check_ajax_referer( 'doubleyoupi_ml_orphan_restore', 'nonce' );
		if ( ! current_user_can( Access::CAP ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		$ids      = self::posted_ids();
		$restored = [];
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'attachment' !== $post->post_type || 'trash' !== $post->post_status ) {
				continue;
			}
			if ( ! current_user_can( 'delete_post', $id ) ) {
				continue;
			}
			if ( wp_untrash_post( $id ) ) {
				$restored[] = $id;
			}
		}

		self::invalidate_cache();
		wp_send_json_success( [ 'restored' => $restored ] );
	}

	/**
	 * Attachment IDs posted by the screen, unslashed and cast to positive ints.
	 * Callers have already checked the nonce.
	 *
	 * @return int[]
	 */
	private static function posted_ids(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by every caller (check_ajax_referer).
		return isset( $_POST['ids'] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) ) ) : [];
	}

	/**
	 * Erase for good. The only place in this module where a file actually
	 * leaves the server, and it takes an explicit act on a list the user is
	 * looking at, after the file has already spent time in the trash.
	 */
	public function ajax_purge(): void {
		check_ajax_referer( 'doubleyoupi_ml_orphan_purge', 'nonce' );
		if ( ! current_user_can( Access::CAP ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		$ids = self::posted_ids();
		$all = isset( $_POST['all'] ) ? sanitize_text_field( wp_unslash( $_POST['all'] ) ) : '';
		if ( '1' === $all ) {
			$ids = array_map( static fn( $post ) => (int) $post->ID, self::trashed_media() );
		}

		$purged = [];
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			// Only ever from the trash: a file still in the library cannot be
			// erased through this route, whatever the request says.
			if ( ! $post || 'attachment' !== $post->post_type || 'trash' !== $post->post_status ) {
				continue;
			}
			if ( ! current_user_can( 'delete_post', $id ) ) {
				continue;
			}
			if ( wp_delete_attachment( $id, true ) ) {
				$purged[] = $id;
			}
		}

		self::invalidate_cache();
		wp_send_json_success( [ 'purged' => $purged ] );
	}

	/**
	 * The string to hunt for when looking a file up by name.
	 *
	 * WordPress names every resized copy after the same stem: image.png yields
	 * image-300x200.png. Searching the full basename therefore misses a page
	 * that only ever shows a thumbnail, and the original looked unused.
	 *
	 * A very short stem would match half the database, so those keep their full
	 * basename. The asymmetry is deliberate: a file wrongly kept costs a few
	 * kilobytes, a file wrongly deleted cannot be brought back.
	 */
	private static function search_needle( string $basename ): string {
		$stem = (string) pathinfo( $basename, PATHINFO_FILENAME );
		return ( strlen( $stem ) >= 4 ) ? $stem : $basename;
	}

	/**
	 * Of these candidates, the ones still not known to be referenced.
	 *
	 * @param array<int,string> $needles   attachment id => needle
	 * @param array<int,bool>   $referenced
	 * @return array<int,string>
	 */
	private static function still_unreferenced( array $needles, array $referenced ): array {
		return array_filter(
			$needles,
			static fn( $id ) => ! isset( $referenced[ (int) $id ] ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Mark every candidate whose needle appears in the column the query returns.
	 *
	 * The query carries a %CONDITIONS% marker where the batch's LIKE clauses go.
	 * Rows are fetched and then tested needle by needle, rather than trusting the
	 * LIKE to say WHICH candidate matched: one row matching any needle in the
	 * batch would otherwise mark the whole batch as used.
	 *
	 * @param array<int,string> $needles attachment id => needle
	 * @param array<int,bool>   $referenced passed by reference, filled in place
	 */
	private static function mark_by_name( array $needles, array &$referenced, string $sql, string $column ): void {
		if ( empty( $needles ) ) {
			return;
		}
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- caller caches the whole scan in a transient; table names come from $wpdb properties; the query is a fixed template whose only variable part is N copies of a fixed column name followed by `LIKE %s`, and every value is bound via $wpdb->prepare()
		foreach ( array_chunk( $needles, 50, true ) as $batch ) {
			$conditions = implode( ' OR ', array_fill( 0, count( $batch ), $column . ' LIKE %s' ) );
			$like_args  = array_map(
				static fn( $needle ) => '%' . $wpdb->esc_like( $needle ) . '%',
				array_values( $batch )
			);

			// The marker is swapped before prepare() sees the query, so the only
			// placeholders it receives are the `%s` of each LIKE clause.
			$query   = str_replace( '%CONDITIONS%', $conditions, $sql );
			$matched = (array) $wpdb->get_col( $wpdb->prepare( $query, $like_args ) );
			if ( empty( $matched ) ) {
				continue;
			}

			$haystack = "\n" . implode( "\n", $matched ) . "\n";
			foreach ( $batch as $att_id => $needle ) {
				if ( '' !== $needle && str_contains( $haystack, $needle ) ) {
					$referenced[ (int) $att_id ] = true;
				}
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Mark the candidates whose ID appears somewhere as an attachment reference.
	 *
	 * A bare number is worthless as a search term: 12 occurs in prices, dates
	 * and every other ID on the site, and matching it anywhere would keep every
	 * file forever. So the ID only counts when it sits in a shape that means
	 * "this is an attachment": the class WordPress writes on an inserted image,
	 * a gallery's id list, a JSON key, a serialised array entry.
	 *
	 * This one stays on the current site. An ID means nothing outside the site
	 * that issued it, which is why the network pass matches names instead.
	 *
	 * @param int[]           $ids
	 * @param array<int,bool> $referenced passed by reference, filled in place
	 */
	private static function mark_by_id( array $ids, array &$referenced ): void {
		if ( empty( $ids ) ) {
			return;
		}
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- caller caches the whole scan in a transient; table names come from $wpdb properties; the query is a fixed template whose only variable part is N copies of a fixed column name followed by `LIKE %s`, and every value is bound via $wpdb->prepare()
		foreach ( array_chunk( $ids, 50 ) as $batch ) {
			$likes = [];
			foreach ( $batch as $id ) {
				$id = (int) $id;
				// Narrow enough that the database returns the rows worth reading
				// rather than every row containing the digits. Precision comes
				// from id_appears_as_reference() below.
				foreach ( [ "wp-image-{$id}", "attachment_{$id}", "attachment-{$id}", "i:{$id};", ":\"{$id}\";" ] as $needle ) {
					$likes[] = '%' . $wpdb->esc_like( $needle ) . '%';
				}
			}
			// Shapes that carry an id list without naming any single id, added
			// once for the whole batch: gallery shortcodes and block attributes.
			foreach ( [ '[gallery', 'ids="', "ids='", '"ids":', '"id":' ] as $marker ) {
				$likes[] = '%' . $wpdb->esc_like( $marker ) . '%';
			}

			$rows = [];
			foreach ( [
				"SELECT post_content FROM {$wpdb->posts}
				WHERE post_type NOT IN ('attachment','revision','nav_menu_item')
				  AND post_status NOT IN ('auto-draft','trash')
				  AND (%CONDITIONS%)" => 'post_content',
				"SELECT pm.meta_value FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type NOT IN ('attachment','revision','nav_menu_item')
				  AND p.post_status NOT IN ('auto-draft','trash')
				  AND pm.meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata','_doubleyoupi_ml_hash','_doubleyoupi_ml_is_duplicate')
				  AND (%CONDITIONS%)" => 'pm.meta_value',
				"SELECT option_value FROM {$wpdb->options} WHERE (%CONDITIONS%)" => 'option_value',
			] as $sql => $column ) {
				$conditions = implode( ' OR ', array_fill( 0, count( $likes ), $column . ' LIKE %s' ) );
				$query      = str_replace( '%CONDITIONS%', $conditions, $sql );
				$rows[]     = implode(
					"\n",
					(array) $wpdb->get_col( $wpdb->prepare( $query, $likes ) )
				);
			}

			$haystack = implode( "\n", $rows );
			if ( '' === trim( $haystack ) ) {
				continue;
			}

			// Structured values are read by decoding them, never by pattern.
			$structured = [];
			foreach ( $rows as $blob ) {
				foreach ( explode( "\n", $blob ) as $value ) {
					self::collect_structured_ids( maybe_unserialize( $value ), $structured );
				}
			}

			foreach ( $batch as $id ) {
				$id = (int) $id;
				if ( isset( $structured[ $id ] ) || self::id_appears_as_reference( $haystack, $id ) ) {
					$referenced[ $id ] = true;
				}
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Attachment IDs a stored value points at, read from its structure.
	 *
	 * Two shapes count, and nothing else:
	 *   - a number sitting under a key that names media ("id", "ids",
	 *     "attachment_id", "gallery"...), which is how blocks and builders
	 *     write a single image;
	 *   - a plain list whose every entry is a number, which is how a gallery
	 *     field stores its selection.
	 * A number found anywhere else is just a number.
	 *
	 * @param mixed           $value
	 * @param array<int,bool> $found passed by reference, filled in place
	 */
	private static function collect_structured_ids( $value, array &$found, bool $under_media_key = false, int $depth = 0 ): void {
		if ( $depth > 8 ) {
			return;
		}

		if ( is_string( $value ) ) {
			// A JSON blob stored as text: decode it and walk that too.
			$trimmed = ltrim( $value );
			if ( '' !== $trimmed && ( $trimmed[0] === '{' || $trimmed[0] === '[' ) ) {
				$decoded = json_decode( $value, true );
				if ( is_array( $decoded ) ) {
					self::collect_structured_ids( $decoded, $found, $under_media_key, $depth + 1 );
					return;
				}
			}
		}

		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			if ( $under_media_key && (int) $value > 0 ) {
				$found[ (int) $value ] = true;
			}
			return;
		}

		if ( ! is_array( $value ) ) {
			return;
		}

		// A list of nothing but numbers: a media selection, in every gallery
		// field we have met. Its keys are positions, never IDs, which is the
		// whole reason this is decoded rather than pattern-matched.
		if ( $value && array_is_list( $value ) ) {
			$only_numbers = true;
			foreach ( $value as $entry ) {
				if ( ! is_int( $entry ) && ! ( is_string( $entry ) && ctype_digit( $entry ) ) ) {
					$only_numbers = false;
					break;
				}
			}
			if ( $only_numbers ) {
				foreach ( $value as $entry ) {
					if ( (int) $entry > 0 ) {
						$found[ (int) $entry ] = true;
					}
				}
				return;
			}
		}

		foreach ( $value as $key => $entry ) {
			self::collect_structured_ids(
				$entry,
				$found,
				is_string( $key ) && self::is_media_key( $key ),
				$depth + 1
			);
		}
	}

	/**
	 * Does this key name announce a media reference?
	 */
	private static function is_media_key( string $key ): bool {
		return in_array(
			strtolower( $key ),
			[ 'id', 'ids', 'image', 'images', 'image_id', 'image_ids', 'attachment', 'attachment_id', 'attachment_ids', 'thumbnail_id', 'gallery', 'media', 'media_id', 'logo', 'icon', 'background_image', 'poster' ],
			true
		);
	}

	/**
	 * Does this ID appear in a shape that means "attachment", rather than as a
	 * number that happens to be there?
	 *
	 * Every pattern ends on a "not followed by a digit" guard, so 12 never
	 * matches inside 123. The list covers what WordPress and the common
	 * plugins actually write:
	 *   class="wp-image-12"            an image inserted in the content
	 *   [caption id="attachment_12"]   the caption shortcode
	 *   [gallery ids="12,34"]          the gallery shortcode
	 *   {"ids":[12,34]}                a gallery block
	 *   {"id":12}                      a media block, a builder's own data
	 *
	 * Serialised data is NOT handled here. In a:2:{i:0;i:16;i:1;i:999;} the
	 * i:0; and i:1; are array KEYS, and a pattern looking for "i:5;" duly found
	 * the sixth slot of an unrelated widget list and declared image 5 in use.
	 * Those values are decoded instead, in collect_structured_ids().
	 */
	private static function id_appears_as_reference( string $haystack, int $id ): bool {
		$n = (string) $id;
		$patterns = [
			'/wp-image-' . $n . '(?!\d)/',
			'/attachment[_-]' . $n . '(?!\d)/',
			'/"ids?"\s*:\s*\[?\s*(?:\d+\s*,\s*)*' . $n . '(?!\d)/',
			'/\bids\s*=\s*["\'][\d,\s]*?' . $n . '(?!\d)/',
		];
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $haystack ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Where a filename can legitimately appear in postmeta and count as a use.
	 *
	 * Built on demand rather than stored, because $wpdb's table names follow
	 * switch_to_blog() and this same query has to run against each site of a
	 * network.
	 *
	 * Attachments are excluded, and so are the two meta keys WordPress uses to
	 * record where a file lives. Without that, every file matched its own
	 * _wp_attached_file and the scan found no orphan at all.
	 */
	private static function meta_reference_sql(): string {
		global $wpdb;
		return "SELECT pm.meta_value FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE p.post_type NOT IN ('attachment','revision','nav_menu_item')
			  AND p.post_status NOT IN ('auto-draft','trash')
			  AND pm.meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata','_doubleyoupi_ml_hash','_doubleyoupi_ml_is_duplicate')
			  AND (%CONDITIONS%)";
	}

	/**
	 * Attachment IDs the theme holds without ever naming the file: the site logo
	 * and the header image.
	 *
	 * @return int[]
	 */
	private static function theme_attachment_ids(): array {
		$ids    = [];
		$header = get_theme_mod( 'header_image_data' );
		foreach ( [ get_theme_mod( 'custom_logo' ), get_option( 'site_logo' ), is_object( $header ) ? ( $header->attachment_id ?? 0 ) : 0 ] as $value ) {
			$id = (int) $value;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Look for these files in the other sites of the network.
	 *
	 * On multisite each site owns its own posts, postmeta and options tables, so
	 * every query above only ever saw the site running the scan. A file uploaded
	 * to one site and used on another was reported as unused, and offered for
	 * deletion — which is how a network loses an image that a second site was
	 * displaying all along.
	 *
	 * Only names are matched here, never IDs. An ID means nothing outside the
	 * site that issued it: attachment 42 on the shop has no relation to
	 * attachment 42 on the blog, and treating one as a reference to the other
	 * would keep files that really are unused.
	 *
	 * @param array<int,string> $needles attachment id => needle
	 * @param array<int,bool>   $referenced passed by reference, filled in place
	 */
	private static function mark_across_network( array $needles, array &$referenced ): void {
		global $wpdb;

		$current = get_current_blog_id();
		$sites   = get_sites( [
			// 0 means every site. A network large enough for this to hurt can cap
			// it through the filter, knowing that a site left out is a site whose
			// references we cannot see.
			'number' => (int) apply_filters( 'doubleyoupi_ml_orphan_network_site_limit', 0 ),
			'fields' => 'ids',
		] );

		foreach ( $sites as $blog_id ) {
			$blog_id = (int) $blog_id;
			if ( $blog_id === $current || empty( $needles ) ) {
				continue;
			}

			switch_to_blog( $blog_id );
			// $wpdb's table names follow the switch, so the queries below read the
			// other site's tables.
			self::mark_by_name(
				$needles,
				$referenced,
				"SELECT post_content FROM {$wpdb->posts}
				WHERE post_type NOT IN ('attachment','revision','nav_menu_item')
				  AND post_status NOT IN ('auto-draft','trash')
				  AND (%CONDITIONS%)",
				'post_content'
			);
			self::mark_by_name(
				$needles,
				$referenced,
				self::meta_reference_sql(),
				'pm.meta_value'
			);
			self::mark_by_name(
				$needles,
				$referenced,
				"SELECT option_value FROM {$wpdb->options} WHERE (%CONDITIONS%)",
				'option_value'
			);
			restore_current_blog();

			$needles = self::still_unreferenced( $needles, $referenced );
		}
	}

	public static function invalidate_cache( int $post_id = 0 ): void {
		delete_transient( self::CACHE_KEY );
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	public function ajax_scan(): void {
		check_ajax_referer( 'doubleyoupi_ml_orphan_scan', 'nonce' );
		if ( ! current_user_can( Access::CAP ) ) {
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
		if ( ! current_user_can( Access::CAP ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		$ids = self::posted_ids();
		if ( empty( $ids ) ) {
			wp_send_json_error( [ 'message' => 'no_ids' ] );
		}

		/*
		 * Recompute the orphan set before deleting anything.
		 *
		 * The list on screen comes from a scan that may be minutes or days old,
		 * on a tab that may have been open all along. In between, someone can
		 * have put one of these files to use. Deletion here is permanent, the
		 * file is not even sent to the trash, so the answer to "is this still
		 * unused" has to be taken now rather than trusted from the page.
		 */
		self::invalidate_cache();
		$still_orphan = array_flip( self::scan() );

		$deleted = [];
		$errors  = [];
		$kept    = [];

		foreach ( $ids as $id ) {
			// Server-side double check: must be a valid attachment.
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== 'attachment' || ! current_user_can( 'delete_post', $id ) ) {
				$errors[] = $id;
				continue;
			}

			// Used again since the scan: left alone, and reported as such.
			if ( ! isset( $still_orphan[ $id ] ) ) {
				$kept[] = $id;
				continue;
			}

			/*
			 * To the trash, not erased.
			 *
			 * No detector is ever going to be right about every way a theme or
			 * a plugin can point at a file, so the screen must not be the last
			 * word. Trashing keeps the file on the server: it leaves the media
			 * library, and a page that still displays it goes on working, which
			 * turns a wrong answer here into something a click undoes.
			 *
			 * WordPress empties its trash after EMPTY_TRASH_DAYS, so nothing
			 * accumulates forever, and restore_and_purge below gives both
			 * halves of the decision back to the user in the meantime.
			 */
			$result = wp_trash_post( $id );
			if ( $result ) {
				$deleted[] = $id;
			} else {
				$errors[] = $id;
			}
		}

		self::invalidate_cache();

		// The rows for what just moved, so the trash section below fills in
		// without a page reload and a file just removed looks exactly like one
		// that was already there.
		$rows = [];
		foreach ( $deleted as $id ) {
			$post = get_post( $id );
			if ( $post ) {
				$rows[] = OrphanAdminPage::trash_row_html( $post );
			}
		}

		wp_send_json_success( [
			'deleted' => $deleted,
			'errors'  => $errors,
			'kept'    => $kept,
			'rows'    => $rows,
		] );
	}
}
