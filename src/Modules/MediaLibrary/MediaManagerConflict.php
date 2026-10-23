<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary;

use DoubleYoupi\Core\AbstractModule;
use DoubleYoupi\Admin\Kses;
use DoubleYoupi\Admin\Access;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the case where another media-library manager (FileBird, HappyFiles…)
 * is active alongside Refined Media Library.
 *
 * Two folder UIs on upload.php overlap. Only ONE owner renders at a time:
 *
 *   - Owner = RML       → RML renders its UI and neutralises the competitor's
 *                         (CSS, per known plugin).
 *   - Owner = competitor→ RML steps aside: FolderManager skips ALL of its
 *                         media-page assets (clean, zero overlap), the
 *                         competitor shows alone.
 *
 * The default owner is the LAST ACTIVATED manager. WordPress stores no install
 * date, but activating a plugin (which always follows an install) fires
 * `activated_plugin` — so the most recently activated manager wins. A persistent
 * notice always offers to switch to the other one.
 */
class MediaManagerConflict extends AbstractModule {

	/** Owner of the media page: 'rml' or a competitor key. */
	public const OPTION = 'doubleyoupi_ml_owner';

	/** Nonce action for the switch link. */
	private const SWITCH_ACTION = 'doubleyoupi_ml_set_owner';

	/**
	 * Known competing media-library managers.
	 *
	 * - plugins : candidate basenames (free/pro variants) for is_plugin_active().
	 * - hide    : CSS shipped when RML owns, to neutralise the competitor's UI.
	 *
	 * FileBird, Real Media Library and Folders are verified against the live
	 * plugins. HappyFiles is best-effort (its free version is no longer on
	 * WordPress.org, so it could not be inspected). Enhanced Media Library is
	 * intentionally absent: it adds toolbar filters, not an overlapping side
	 * panel, so there is nothing to own or hide (its import is handled
	 * separately by SourceDetector).
	 *
	 * `folders` holds regular expressions matched against the DIRECTORY of each
	 * active plugin. Exact basenames are not enough: the paid editions live in
	 * their own directory (`filebird-pro`, `happyfiles-pro`), and a directory can
	 * be renamed on install. Matching the directory catches every edition, which
	 * is what a conflict is about — a competing UI being on screen.
	 *
	 * @var array<string,array{label:string,plugins:string[],folders:string[],hide:string}>
	 */
	private const COMPETITORS = [
		'filebird'           => [
			'label'   => 'FileBird',
			'plugins' => [ 'filebird/filebird.php' ],
			'folders' => [ '#^filebird#' ],
			'hide'    => '#filebird-root{display:none!important}'
				. 'body.filebird-upload-php.upload-php .wrap>.media-frame{margin-left:min(var(--dy-fw,220px),35vw,360px)!important}',
		],
		'happyfiles'         => [
			'label'   => 'HappyFiles',
			'plugins' => [ 'happyfiles-pro/happyfiles.php', 'happyfiles/happyfiles.php' ],
			'folders' => [ '#^happyfiles#' ],
			// Best-effort: HappyFiles' free build is closed on WordPress.org and
			// could not be inspected. The "step aside" path works regardless.
			'hide'    => '#happyfiles-categories,.happyfiles-categories,#happyfiles-folders{display:none!important}',
		],
		'real-media-library' => [
			'label'   => 'Real Media Library',
			'plugins' => [ 'real-media-library/index.php', 'real-media-library-lite/index.php' ],
			'folders' => [ '#^real-media-library#' ],
			'hide'    => '.rml-container{display:none!important}',
		],
		'folders'            => [
			'label'   => 'Folders',
			'plugins' => [ 'folders/folders.php' ],
			// Anchored on both ends: "folders" is a common word, and a directory
			// such as `my-folders-helper` is not Premio's plugin.
			'folders' => [ '#^folders$#' ],
			'hide'    => '.wcp-container{display:none!important}',
		],
	];

	public function id(): string {
		return 'media-library-conflict';
	}

	public function name(): string {
		return 'Refined Media Library — Manager Conflict';
	}

	public function boot(): void {
		add_action( 'activated_plugin', [ $this, 'on_plugin_activated' ], 10, 1 );
		add_action( 'load-upload.php', [ $this, 'maybe_handle_switch' ] );
		add_action( 'admin_notices', [ $this, 'render_notice' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_neutralize_css' ] );
	}

	// -------------------------------------------------------------------------
	// State
	// -------------------------------------------------------------------------

	/**
	 * Keys of competing managers whose plugin is currently active.
	 *
	 * @return string[]
	 */
	public function active_competitors(): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$dirs = $this->active_plugin_dirs();

		$active = [];
		foreach ( self::COMPETITORS as $key => $cfg ) {
			$found = false;
			foreach ( $cfg['plugins'] as $basename ) {
				if ( is_plugin_active( $basename ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				foreach ( $cfg['folders'] as $pattern ) {
					foreach ( $dirs as $dir ) {
						if ( preg_match( $pattern, $dir ) ) {
							$found = true;
							break 2;
						}
					}
				}
			}
			if ( $found ) {
				$active[] = $key;
			}
		}
		return $active;
	}

	/**
	 * Directory of every active plugin, network-wide activations included.
	 *
	 * @return string[]
	 */
	private function active_plugin_dirs(): array {
		$paths = (array) get_option( 'active_plugins', [] );
		if ( is_multisite() ) {
			$paths = array_merge( $paths, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
		}
		$dirs = [];
		foreach ( $paths as $path ) {
			$dir = strtok( (string) $path, '/' );
			if ( is_string( $dir ) && '' !== $dir ) {
				$dirs[] = strtolower( $dir );
			}
		}
		return array_unique( $dirs );
	}

	public function has_conflict(): bool {
		return [] !== $this->active_competitors();
	}

	/**
	 * Resolved owner of the media page: 'rml' or an active competitor key.
	 */
	public function owner(): string {
		$active = $this->active_competitors();
		if ( [] === $active ) {
			return 'rml';
		}
		$stored = (string) get_option( self::OPTION, '' );
		if ( 'rml' === $stored ) {
			return 'rml';
		}
		if ( in_array( $stored, $active, true ) ) {
			return $stored;
		}
		// Unset, or points to a now-inactive competitor: default to RML.
		return 'rml';
	}

	/** Whether RML should render its media-page UI (queried by FolderManager). */
	public function rml_renders_media_ui(): bool {
		return ! $this->has_conflict() || 'rml' === $this->owner();
	}

	// -------------------------------------------------------------------------
	// "Last activated wins"
	// -------------------------------------------------------------------------

	/**
	 * Whenever RML or a known competitor is activated, it becomes the owner.
	 *
	 * @param string $plugin Plugin basename being activated.
	 */
	public function on_plugin_activated( $plugin ): void {
		$plugin = (string) $plugin;
		if ( defined( 'DOUBLEYOUPI_BASENAME' ) && $plugin === DOUBLEYOUPI_BASENAME ) {
			update_option( self::OPTION, 'rml' );
			return;
		}
		foreach ( self::COMPETITORS as $key => $cfg ) {
			if ( in_array( $plugin, $cfg['plugins'], true ) ) {
				update_option( self::OPTION, $key );
				return;
			}
		}
	}

	// -------------------------------------------------------------------------
	// Switch (GET link in the notice → option update → redirect)
	// -------------------------------------------------------------------------

	public function maybe_handle_switch(): void {
		if ( ! isset( $_GET['doubleyoupi_ml_set_owner'] ) ) {
			return;
		}
		check_admin_referer( self::SWITCH_ACTION );
		if ( ! current_user_can( Access::CAP ) ) {
			return;
		}
		$target = sanitize_key( wp_unslash( (string) $_GET['doubleyoupi_ml_set_owner'] ) );
		$valid  = array_merge( [ 'rml' ], array_keys( self::COMPETITORS ) );
		if ( in_array( $target, $valid, true ) ) {
			update_option( self::OPTION, $target );
		}
		wp_safe_redirect( remove_query_arg( [ 'doubleyoupi_ml_set_owner', '_wpnonce' ] ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// UI
	// -------------------------------------------------------------------------

	/** Persistent notice on upload.php explaining who owns the page + switch. */
	public function render_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}
		if ( ! $this->has_conflict() || ! current_user_can( Access::CAP ) ) {
			return;
		}

		$active     = $this->active_competitors();
		$comp_key   = $active[0];
		$comp_label = self::COMPETITORS[ $comp_key ]['label'];
		$owner      = $this->owner();

		if ( 'rml' === $owner ) {
			/* translators: %s: name of the competing media-library plugin (e.g. FileBird). */
			$message = sprintf( __( 'Refined Media Library is managing the media library. %s is also active.', 'doubleyoupi-refined-media-library' ), $comp_label );
			/* translators: %s: name of the competing media-library plugin. */
			$button  = sprintf( __( 'Let %s manage it', 'doubleyoupi-refined-media-library' ), $comp_label );
			$target  = $comp_key;
		} else {
			$comp_label = self::COMPETITORS[ $owner ]['label'];
			/* translators: %s: name of the competing media-library plugin currently managing the page. */
			$message = sprintf( __( '%s is managing the media library. Refined Media Library has stepped aside.', 'doubleyoupi-refined-media-library' ), $comp_label );
			$button  = __( 'Use Refined Media Library', 'doubleyoupi-refined-media-library' );
			$target  = 'rml';
		}

		$url = wp_nonce_url(
			add_query_arg( 'doubleyoupi_ml_set_owner', $target, admin_url( 'upload.php' ) ),
			self::SWITCH_ACTION
		);

		// When the competitor still holds folders, importing them is the useful
		// next step, not switching back and forth. We only offer it when there is
		// something to import: the adapter reads the competitor's own tables, so
		// it answers for the data, not for the plugin being installed.
		$import = '';
		if ( 'rml' === $owner && class_exists( Migration\SourceDetector::class ) ) {
			$adapter = Migration\SourceDetector::get( $comp_key );
			if ( $adapter && $adapter->is_present() ) {
				$import = sprintf(
					'<a href="%1$s" class="button button-primary" style="margin-left:6px">%2$s</a>',
					esc_url( admin_url( 'admin.php?page=doubleyoupi-media-library&tab=import' ) ),
					/* translators: %s: name of the competing media-library plugin (e.g. FileBird). */
					esc_html( sprintf( __( 'Import folders from %s', 'doubleyoupi-refined-media-library' ), $comp_label ) )
				);
			}
		}

		printf(
			'<div class="notice notice-info"><p>%1$s %2$s<a href="%3$s" class="button" style="margin-left:6px">%4$s</a></p></div>',
			esc_html( $message ),
			wp_kses( $import, Kses::ui_tags() ),
			esc_url( $url ),
			esc_html( $button )
		);
	}

	/**
	 * When RML owns, hide every active competitor's UI on upload.php.
	 *
	 * Emitted as inline CSS via the styles API (a registered src-less handle),
	 * rather than echoing a style tag, per the WP.org enqueue guideline. The
	 * payload is a static literal assembled from class constants.
	 */
	public function enqueue_neutralize_css(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}
		if ( ! $this->has_conflict() || 'rml' !== $this->owner() ) {
			return;
		}

		$css = '';
		foreach ( $this->active_competitors() as $key ) {
			$css .= self::COMPETITORS[ $key ]['hide'];
		}
		if ( '' === $css ) {
			return;
		}

		wp_register_style( 'doubleyoupi-ml-neutralize', false, [], DOUBLEYOUPI_VERSION );
		wp_enqueue_style( 'doubleyoupi-ml-neutralize' );
		wp_add_inline_style( 'doubleyoupi-ml-neutralize', $css );
	}
}
