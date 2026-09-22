<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary;

use DoubleYoupi\Core\Build;

use DoubleYoupi\Core\AbstractModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects duplicate uploads by comparing the MD5 hash of each file.
 *
 * On upload: stores the hash in post_meta. If an attachment with the same hash
 * already exists, marks the new one as a duplicate (storing a reference to the original).
 *
 * The "duplicate" badge is exposed via the REST field `doubleyoupi_duplicate_of` and
 * displayed in list view via a dedicated column.
 */
class DuplicateDetector extends AbstractModule {

	public const META_HASH = '_doubleyoupi_ml_hash';
	public const META_DUPE = '_doubleyoupi_ml_is_duplicate';

	public function id(): string {
		return 'media-library-duplicates';
	}

	public function name(): string {
		return 'Refined Media Library — Duplicate Detector';
	}

	public function boot(): void {
		// Priority 30: runs after MetadataAutoFiller (10) and FolderManager (20).
		add_action( 'add_attachment', [ $this, 'on_add_attachment' ], 30, 1 );

		// Expose the duplicate field in the REST API (Grid Backbone media library).
		add_action( 'rest_api_init', [ $this, 'register_rest_fields' ] );

		// Duplicate badge in Grid View (Backbone).
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// Dedicated column in list view (wp-admin/upload.php).
		add_filter( 'manage_media_columns', [ $this, 'add_column' ] );
		add_action( 'manage_media_custom_column', [ $this, 'render_column' ], 10, 2 );
	}

	public function enqueue_assets( string $hook ): void {
		$is_media_page   = $hook === 'upload.php';
		$is_media_loaded = did_action( 'wp_enqueue_media' ) > 0;

		if ( ! $is_media_page && ! $is_media_loaded ) {
			return;
		}

		$js = Build::dir( __FILE__, 'assets/js/modules/media-library/duplicate-badge.js' );
		if ( file_exists( $js ) ) {
			wp_enqueue_script(
				'dy-duplicate-badge',
				Build::url( __FILE__, 'assets/js/modules/media-library/duplicate-badge.js' ),
				[ 'jquery', 'media-views' ],
				DOUBLEYOUPI_VERSION,
				true
			);
		}
	}

	public function on_add_attachment( int $post_id ): void {
		$file = get_attached_file( $post_id );
		if ( ! $file || ! is_file( $file ) ) {
			return;
		}

		$hash = md5_file( $file );
		if ( ! $hash ) {
			return;
		}

		update_post_meta( $post_id, self::META_HASH, $hash );

		// Direct wpdb query: faster than WP_Query with meta_query on large
		// libraries (avoids the double join on wp_postmeta).
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot hash lookup on the upload path; caching a just-written hash would be stale by design
		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = %s AND meta_value = %s AND post_id != %d
				LIMIT 1",
				self::META_HASH,
				$hash,
				$post_id
			)
		);

		if ( $existing_id > 0 ) {
			update_post_meta( $post_id, self::META_DUPE, $existing_id );
		}
	}

	public function register_rest_fields(): void {
		register_rest_field(
			'attachment',
			'doubleyoupi_duplicate_of',
			[
				'get_callback' => static function ( array $post ): ?int {
					$dupe = (int) get_post_meta( $post['id'], self::META_DUPE, true );
					return $dupe > 0 ? $dupe : null;
				},
				'schema'       => [
					'description' => 'ID of the original attachment if this file is a duplicate.',
					'type'        => [ 'integer', 'null' ],
					'context'     => [ 'view', 'edit' ],
				],
			]
		);
	}

	/** @param array<string,string> $columns */
	public function add_column( array $columns ): array {
		$columns['doubleyoupi_duplicate'] = __( 'Duplicate', 'doubleyoupi-refined-media-library' );
		return $columns;
	}

	public function render_column( string $column_name, int $post_id ): void {
		if ( $column_name !== 'doubleyoupi_duplicate' ) {
			return;
		}
		$original_id = (int) get_post_meta( $post_id, self::META_DUPE, true );
		if ( $original_id > 0 ) {
			printf(
				'<a href="%s" title="%s">%s</a>',
				esc_url( admin_url( 'post.php?post=' . $original_id . '&action=edit' ) ),
				esc_attr__( 'View original', 'doubleyoupi-refined-media-library' ),
				esc_html__( 'Duplicate', 'doubleyoupi-refined-media-library' )
			);
		} else {
			echo '—';
		}
	}
}
