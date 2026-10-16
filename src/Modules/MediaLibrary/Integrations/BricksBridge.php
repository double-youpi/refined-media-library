<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary\Integrations;

use DoubleYoupi\Modules\MediaLibrary\FolderManager;
use DoubleYoupi\Modules\MediaLibrary\MediaManagerConflict;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks that bring the media library's folders and collections into Bricks.
 *
 * Bricks 2.4 opens its own media browser in the builder and shows folders only
 * from a registered "folder provider" (it ships one, for HappyFiles). This
 * class wires ours in, and nothing here names a Bricks class: the provider
 * itself implements Bricks interfaces, so it is only ever loaded inside the
 * registration callback, once Bricks has declared them. Without Bricks, or
 * with Bricks older than 2.4, none of these hooks ever fires.
 *
 * Booted once even when Refined Admin Dashboard and Refined Media Library are
 * both active: they share this class, and the static guard keeps a single set
 * of hooks.
 */
final class BricksBridge {

	/** Never "happyfiles": registering that ID would replace Bricks' own adapter. */
	public const PROVIDER_ID = 'doubleyoupi-rml';

	/** Per-user memory of the folder open in the Bricks media browser. */
	private const OPEN_FOLDER_KEY = 'doubleyoupi_ml_bricks_open_';

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'bricks/media_folder_providers/register', [ self::class, 'register_provider' ] );
		add_filter( 'bricks/media_folder_provider', [ self::class, 'choose_provider' ], 10, 3 );
		add_action( 'add_attachment', [ self::class, 'file_upload_into_open_folder' ], 25 );
		// After Bricks has called wp_enqueue_media() for the builder (priority 10).
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_builder_media_window' ], 20 );
	}

	/**
	 * Fired by Bricks once its folder registry is ready.
	 */
	public static function register_provider(): void {
		if ( ! interface_exists( '\Bricks\Media_Folder_Provider' ) || ! class_exists( '\Bricks\Media_Folder_Providers' ) ) {
			return;
		}
		\Bricks\Media_Folder_Providers::register( new BricksFolderProvider() );
	}

	/**
	 * Pick ours whenever it is available.
	 *
	 * Available means the media library is the folder manager in charge on this
	 * site (MediaManagerConflict). When HappyFiles holds that role, ours reports
	 * itself unavailable and Bricks keeps HappyFiles; when the user has switched
	 * to Refined Media Library, HappyFiles may still be available to Bricks, and
	 * the folders the user actually sees must win.
	 *
	 * @param mixed $selected
	 * @param mixed $available
	 * @param mixed $post_type
	 * @return mixed
	 */
	public static function choose_provider( $selected, $available, $post_type ) {
		if ( 'attachment' === $post_type && is_array( $available ) && isset( $available[ self::PROVIDER_ID ] ) ) {
			return self::PROVIDER_ID;
		}
		return $selected;
	}

	/**
	 * Remember which folder the user has open in the Bricks media browser.
	 *
	 * Bricks sends the open folder along with an upload only when its provider
	 * is HappyFiles. It does tell every provider which folder the grid shows:
	 * each time the user opens one, the grid query reaches apply_query(). Kept
	 * per user, briefly, so the upload that follows lands where the user is.
	 *
	 * @param int|string $folder_id Positive ID, "unfiled", or empty.
	 */
	public static function remember_open_folder( $folder_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check inside Bricks' own nonce-verified grid request
		$action = isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( ! wp_doing_ajax() || 'bricks_get_pages' !== $action ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$id = is_numeric( $folder_id ) ? absint( $folder_id ) : 0;
		if ( $id > 0 ) {
			set_transient( self::OPEN_FOLDER_KEY . $user_id, $id, 2 * HOUR_IN_SECONDS );
		} else {
			delete_transient( self::OPEN_FOLDER_KEY . $user_id );
		}
	}

	/**
	 * File an upload made from the Bricks builder into the folder (or
	 * collection) open at that moment.
	 */
	public static function file_upload_into_open_folder( int $attachment_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the core upload handlers verify their nonce before add_attachment fires
		$from_builder = isset( $_REQUEST['bricks-is-builder'] ) && is_scalar( $_REQUEST['bricks-is-builder'] )
			&& '1' === sanitize_text_field( wp_unslash( (string) $_REQUEST['bricks-is-builder'] ) );
		// Bricks filed it itself (its HappyFiles path): leave it alone.
		$bricks_did_it = ! empty( $_REQUEST['bricksMediaFolderId'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $from_builder || $bricks_did_it || ! class_exists( '\Bricks\Media_Folder_Providers' ) ) {
			return;
		}
		$provider = \Bricks\Media_Folder_Providers::get_active( 'attachment' );
		if ( ! $provider instanceof BricksFolderProvider ) {
			return;
		}
		$open = (int) get_transient( self::OPEN_FOLDER_KEY . get_current_user_id() );
		if ( $open > 0 ) {
			$provider->assign_attachment( $attachment_id, $open );
		}
	}

	/**
	 * The "WordPress: Media library" window of the builder.
	 *
	 * Bricks can open the classic WordPress media window instead of its own
	 * browser. The builder runs on the front end, where the media library never
	 * loaded its folder panel (admin screens only). Load it there, for the
	 * builder and nobody else: visitors never get a byte of it.
	 */
	public static function enqueue_builder_media_window(): void {
		if ( ! function_exists( 'bricks_is_builder_main' ) || ! bricks_is_builder_main() ) {
			return;
		}
		if ( ! current_user_can( 'upload_files' ) || ! did_action( 'wp_enqueue_media' ) ) {
			return;
		}
		if ( ! MediaManagerConflict::instance()->rml_renders_media_ui() ) {
			return;
		}
		FolderManager::instance()->enqueue_assets( 'bricks-builder' );
	}
}
