<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary;

use DoubleYoupi\Core\AbstractModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings for the Refined Media Library module.
 *
 * Six global options, no per-image configuration.
 * Getters are static so they can be called without dependency injection
 * from any other class within the module.
 */
class Settings extends AbstractModule {

	public const OPTION_FOLDERS       = 'doubleyoupi_ml_folders';
	public const OPTION_COLLECTIONS   = 'doubleyoupi_ml_collections';
	public const OPTION_AUTO_METADATA = 'doubleyoupi_ml_auto_metadata';
	public const OPTION_WEBP_UPLOAD   = 'doubleyoupi_ml_webp_upload';
	public const OPTION_AVIF_UPLOAD   = 'doubleyoupi_ml_avif_upload';
	public const OPTION_IMAGE_QUALITY = 'doubleyoupi_ml_image_quality';
	public const SETTINGS_GROUP       = 'doubleyoupi_media_library';

	private const QUALITY_MIN     = 40;
	private const QUALITY_MAX     = 95;
	private const QUALITY_DEFAULT = 82;

	public function id(): string {
		return 'media-library-settings';
	}

	public function name(): string {
		return 'Refined Media Library — Settings';
	}

	private const AJAX_ACTION = 'doubleyoupi_ml_save_setting';

	/** Short key (JS) → WP option name. */
	private const KEY_MAP = [
		'folders'       => self::OPTION_FOLDERS,
		'collections'   => self::OPTION_COLLECTIONS,
		'auto_metadata' => self::OPTION_AUTO_METADATA,
		'webp_upload'   => self::OPTION_WEBP_UPLOAD,
		'avif_upload'   => self::OPTION_AVIF_UPLOAD,
		'image_quality' => self::OPTION_IMAGE_QUALITY,
	];

	public function boot(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'ajax_save_setting' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Auto-saves a setting (toggle or quality) from the cards, without a submit button.
	 * The options.php form remains the fallback when JS is unavailable.
	 */
	public function ajax_save_setting(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( (string) $_POST['key'] ) ) : '';
		if ( ! isset( self::KEY_MAP[ $key ] ) ) {
			wp_send_json_error( [ 'message' => 'unknown_key' ] );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';

		if ( 'image_quality' === $key ) {
			$value = $this->sanitize_quality( $raw );
		} else {
			$value = rest_sanitize_boolean( $raw );
			// Refuse to enable WebP/AVIF if the server does not support it or if
			// an external module (M04) is already driving conversion.
			if ( $value && in_array( $key, [ 'webp_upload', 'avif_upload' ], true ) ) {
				$support = ImageConverter::supported_formats();
				$ok      = 'webp_upload' === $key ? (bool) $support['webp'] : (bool) $support['avif'];
				$piloted = is_object( apply_filters( 'doubleyoupi_perf_image_converter', null ) );
				if ( ! $ok || $piloted ) {
					wp_send_json_error( [ 'message' => 'not_available' ] );
				}
			}
		}

		update_option( self::KEY_MAP[ $key ], $value, false );
		wp_send_json_success( [ 'key' => $key, 'value' => $value ] );
	}

	/** Enqueue the auto-save JS on the Settings tab of the media library. */
	public function enqueue_assets( string $hook ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		if ( 'doubleyoupi-media-library' !== $page || ( '' !== $tab && 'settings' !== $tab ) ) {
			return;
		}
		$rel = 'assets/js/modules/media-library/ml-settings.js';
		$abs = DOUBLEYOUPI_DIR . $rel;
		if ( ! file_exists( $abs ) ) {
			return;
		}
		wp_enqueue_script(
			'doubleyoupi-ml-settings',
			DOUBLEYOUPI_URL . $rel,
			[ 'jquery' ],
			(string) ( filemtime( $abs ) ?: DOUBLEYOUPI_VERSION ),
			true
		);
		wp_localize_script( 'doubleyoupi-ml-settings', 'doubleyoupiMlSettings', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
		] );
	}

	public function register_settings(): void {
		register_setting( self::SETTINGS_GROUP, self::OPTION_FOLDERS, [
			'type'              => 'boolean',
			'default'           => true,
			'sanitize_callback' => 'rest_sanitize_boolean',
		] );
		register_setting( self::SETTINGS_GROUP, self::OPTION_COLLECTIONS, [
			'type'              => 'boolean',
			'default'           => true,
			'sanitize_callback' => 'rest_sanitize_boolean',
		] );
		register_setting( self::SETTINGS_GROUP, self::OPTION_AUTO_METADATA, [
			'type'              => 'boolean',
			'default'           => true,
			'sanitize_callback' => 'rest_sanitize_boolean',
		] );
		register_setting( self::SETTINGS_GROUP, self::OPTION_WEBP_UPLOAD, [
			'type'              => 'boolean',
			'default'           => true,
			'sanitize_callback' => 'rest_sanitize_boolean',
		] );
		register_setting( self::SETTINGS_GROUP, self::OPTION_AVIF_UPLOAD, [
			'type'              => 'boolean',
			'default'           => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
		] );
		register_setting( self::SETTINGS_GROUP, self::OPTION_IMAGE_QUALITY, [
			'type'              => 'integer',
			'default'           => self::QUALITY_DEFAULT,
			'sanitize_callback' => [ $this, 'sanitize_quality' ],
		] );
	}

	// -------------------------------------------------------------------------
	// Static getters — used by other classes within the module.
	// -------------------------------------------------------------------------

	public static function folders_enabled(): bool {
		return (bool) get_option( self::OPTION_FOLDERS, true );
	}

	public static function collections_enabled(): bool {
		return (bool) get_option( self::OPTION_COLLECTIONS, true );
	}

	public static function auto_metadata_enabled(): bool {
		return (bool) get_option( self::OPTION_AUTO_METADATA, true );
	}

	public static function webp_upload_enabled(): bool {
		return (bool) get_option( self::OPTION_WEBP_UPLOAD, true );
	}

	public static function avif_upload_enabled(): bool {
		return (bool) get_option( self::OPTION_AVIF_UPLOAD, false );
	}

	public static function image_quality(): int {
		$q = (int) get_option( self::OPTION_IMAGE_QUALITY, self::QUALITY_DEFAULT );
		return max( self::QUALITY_MIN, min( self::QUALITY_MAX, $q ) );
	}

	public function sanitize_quality( mixed $input ): int {
		$q = (int) $input;
		return max( self::QUALITY_MIN, min( self::QUALITY_MAX, $q ) );
	}

	// -------------------------------------------------------------------------
	// Tab rendering (without .wrap wrapper — handled by MediaLibrary::render_page).
	// -------------------------------------------------------------------------

	public function render_tab(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Check for external control (M04) and the server's actual capabilities.
		$external_converter = apply_filters( 'doubleyoupi_perf_image_converter', null );
		$piloted_externally = is_object( $external_converter );
		$external_label     = $piloted_externally && method_exists( $external_converter, 'name' )
			? (string) $external_converter->name()
			: __( 'Optimization', 'doubleyoupi-refined-media-library' );
		$support = ImageConverter::supported_formats();
		$webp_ok = (bool) $support['webp'];
		$avif_ok = (bool) $support['avif'];

		$piloted_msg = $piloted_externally
			/* translators: %s: name of the module piloting image conversion */
			? sprintf( __( 'Piloted by %s. Settings managed there.', 'doubleyoupi-refined-media-library' ), $external_label )
			: '';
		$webp_block = ! $webp_ok ? __( 'This server cannot generate WebP images.', 'doubleyoupi-refined-media-library' ) : $piloted_msg;
		$avif_block = ! $avif_ok ? __( 'This server cannot generate AVIF images.', 'doubleyoupi-refined-media-library' ) : $piloted_msg;
		?>
		<div class="dy-mlset">
			<form method="post" action="options.php" class="dy-mlset__form">
				<?php settings_fields( self::SETTINGS_GROUP ); ?>

				<section class="dy-mlset__group">
					<p class="dy-mlset__eyebrow"><?php esc_html_e( 'Organisation', 'doubleyoupi-refined-media-library' ); ?></p>
					<div class="dy-mlset__cards">
						<?php
						self::toggle_card( [
							'key'    => 'folders',
							'option' => self::OPTION_FOLDERS,
							'on'     => self::folders_enabled(),
							'name'   => __( 'Folders', 'doubleyoupi-refined-media-library' ),
							'desc'   => __( 'Organise the media library into folders', 'doubleyoupi-refined-media-library' ),
							'icon'   => 'folders',
						] );
						self::toggle_card( [
							'key'    => 'collections',
							'option' => self::OPTION_COLLECTIONS,
							'on'     => self::collections_enabled(),
							'name'   => __( 'Collections', 'doubleyoupi-refined-media-library' ),
							'desc'   => __( 'Group media into cross-folder collections', 'doubleyoupi-refined-media-library' ),
							'icon'   => 'collections',
						] );
						self::toggle_card( [
							'key'    => 'auto_metadata',
							'option' => self::OPTION_AUTO_METADATA,
							'on'     => self::auto_metadata_enabled(),
							'name'   => __( 'Auto alt & title', 'doubleyoupi-refined-media-library' ),
							'desc'   => __( 'Fill alt text and title from filename on upload (if empty)', 'doubleyoupi-refined-media-library' ),
							'icon'   => 'text',
						] );
						?>
					</div>
				</section>

				<section class="dy-mlset__group">
					<p class="dy-mlset__eyebrow"><?php esc_html_e( 'Conversion', 'doubleyoupi-refined-media-library' ); ?></p>
					<div class="dy-mlset__cards">
						<?php
						self::toggle_card( [
							'key'       => 'webp_upload',
							'option'    => self::OPTION_WEBP_UPLOAD,
							'on'        => self::webp_upload_enabled() && ! $piloted_externally && $webp_ok,
							'name'      => __( 'WebP on upload', 'doubleyoupi-refined-media-library' ),
							'desc'      => __( 'Generate a WebP version of each image on upload', 'doubleyoupi-refined-media-library' ),
							'icon'      => 'image',
							'blocked'   => $piloted_externally || ! $webp_ok,
							'block_msg' => $webp_block,
						] );
						self::toggle_card( [
							'key'       => 'avif_upload',
							'option'    => self::OPTION_AVIF_UPLOAD,
							'on'        => self::avif_upload_enabled() && ! $piloted_externally && $avif_ok,
							'name'      => __( 'AVIF on upload', 'doubleyoupi-refined-media-library' ),
							'desc'      => __( 'Generate an AVIF version too (requires Imagick)', 'doubleyoupi-refined-media-library' ),
							'icon'      => 'image',
							'blocked'   => $piloted_externally || ! $avif_ok,
							'block_msg' => $avif_block,
						] );
						self::quality_card();
						?>
					</div>
				</section>

				<?php // JS-less fallback: when JS is active, auto-save hides this button (see ml-settings.js / _ml-settings.scss). ?>
				<p class="dy-mlset__actions">
					<?php submit_button( __( 'Save changes', 'doubleyoupi-refined-media-library' ), 'primary', 'submit', false ); ?>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders a toggle card (state: default / active / blocked). The control is a real
	 * <input type=checkbox> (JS auto-save + form fallback); the switch is purely visual.
	 *
	 * @param array<string,mixed> $a
	 */
	private static function toggle_card( array $a ): void {
		$blocked = ! empty( $a['blocked'] );
		$on      = ! $blocked && ! empty( $a['on'] );
		$state   = $blocked ? 'blocked' : ( $on ? 'active' : 'default' );
		?>
		<label class="dy-mlset__card" data-state="<?php echo esc_attr( $state ); ?>">
			<span class="dy-mlset__icon" aria-hidden="true"><?php self::icon( (string) $a['icon'] ); ?></span>
			<span class="dy-mlset__body">
				<span class="dy-mlset__name"><?php echo esc_html( (string) $a['name'] ); ?></span>
				<span class="dy-mlset__desc"><?php echo esc_html( (string) $a['desc'] ); ?></span>
				<?php if ( $blocked && '' !== (string) ( $a['block_msg'] ?? '' ) ) : ?>
					<span class="dy-mlset__note">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
						<span><?php echo esc_html( (string) $a['block_msg'] ); ?></span>
					</span>
				<?php endif; ?>
			</span>
			<input type="checkbox" class="dy-mlset__input"
				data-key="<?php echo esc_attr( (string) $a['key'] ); ?>"
				name="<?php echo esc_attr( (string) $a['option'] ); ?>" value="1"
				<?php checked( $on ); ?> <?php disabled( $blocked ); ?>>
			<span class="dy-mlset__switch" aria-hidden="true">
				<span class="dy-mlset__track"><span class="dy-mlset__thumb"></span></span>
			</span>
			<span class="dy-mlset__saved" role="status" aria-hidden="true">
				<svg viewBox="0 0 12 12" class="dy-mlset__saved-icon" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" d="M2.5 6.2 5 8.5l4.5-5"/></svg>
				<?php esc_html_e( 'Saved', 'doubleyoupi-refined-media-library' ); ?>
			</span>
		</label>
		<?php
	}

	/** "Conversion quality" card: slider 40–95 with live value display, auto-saves on release. */
	private static function quality_card(): void {
		$q = self::image_quality();
		?>
		<div class="dy-mlset__card dy-mlset__card--range" data-state="active">
			<span class="dy-mlset__icon" aria-hidden="true"><?php self::icon( 'quality' ); ?></span>
			<span class="dy-mlset__body">
				<span class="dy-mlset__name"><?php esc_html_e( 'Conversion quality', 'doubleyoupi-refined-media-library' ); ?></span>
				<span class="dy-mlset__desc"><?php esc_html_e( 'Higher means better quality and larger files. Default 82.', 'doubleyoupi-refined-media-library' ); ?></span>
				<span class="dy-mlset__range-row">
					<input type="range" class="dy-mlset__range" id="dy_ml_quality"
						data-key="image_quality"
						name="<?php echo esc_attr( self::OPTION_IMAGE_QUALITY ); ?>"
						min="<?php echo esc_attr( (string) self::QUALITY_MIN ); ?>"
						max="<?php echo esc_attr( (string) self::QUALITY_MAX ); ?>"
						value="<?php echo esc_attr( (string) $q ); ?>">
					<output class="dy-mlset__range-val" id="dy-mlset-quality-val"><?php echo esc_html( (string) $q ); ?></output>
				</span>
			</span>
			<span class="dy-mlset__saved" role="status" aria-hidden="true">
				<svg viewBox="0 0 12 12" class="dy-mlset__saved-icon" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" d="M2.5 6.2 5 8.5l4.5-5"/></svg>
				<?php esc_html_e( 'Saved', 'doubleyoupi-refined-media-library' ); ?>
			</span>
		</div>
		<?php
	}

	/** Inline SVG icon (static, controlled) for a settings card. */
	private static function icon( string $name ): void {
		$icons = [
			'folders'     => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2.5h8a2 2 0 0 1 2 2V17a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
			'collections' => '<path d="m12 3 9 5-9 5-9-5 9-5z"/><path d="m3 13 9 5 9-5"/><path d="m3 17 9 5 9-5"/>',
			'text'        => '<path d="M5 6V4h14v2"/><path d="M9 20h6"/><path d="M12 4v16"/>',
			'image'       => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.6"/><path d="m21 15-5-5L6 20"/>',
			'quality'     => '<path d="M4 21v-6M4 11V3M12 21v-9M12 8V3M20 21v-4M20 13V3M1 15h6M9 8h6M17 17h6"/>',
		];
		$p = $icons[ $name ] ?? '';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static controlled SVG, no user input involved.
		echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
	}
}
