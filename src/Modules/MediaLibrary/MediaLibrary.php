<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary;

use DoubleYoupi\Admin\AdminMenu;
use DoubleYoupi\Core\AbstractModule;
use DoubleYoupi\Modules\MediaLibrary\Migration\MigrationManager;
use DoubleYoupi\Modules\MediaLibrary\Migration\SourceDetector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module 07 — Refined Media Library
 *
 * Single entry point for the module. Orchestrates the boot of all sub-classes.
 * Manages the tabbed admin page: Settings | Unused media.
 */
class MediaLibrary extends AbstractModule {

	public function id(): string {
		return 'media-library';
	}

	public function name(): string {
		return 'Refined Media Library';
	}

	public function boot(): void {
		Settings::instance()->boot();
		FolderTaxonomy::instance()->boot();
		CollectionTaxonomy::instance()->boot();
		FolderManager::instance()->boot();
		CollectionManager::instance()->boot();
		MetadataAutoFiller::instance()->boot();
		ImageConverter::instance()->boot();
		DuplicateDetector::instance()->boot();
		WhereUsed::instance()->boot();
		OrphanScanner::instance()->boot();
		OrphanAdminPage::instance()->boot();
		MigrationManager::instance()->boot();
		MediaManagerConflict::instance()->boot();

		// Tabbed page: callback on this class to manage the active tab.
		AdminMenu::add_page(
			__( 'Refined Media Library', 'doubleyoupi-refined-media-library' ),
			__( 'Media Library', 'doubleyoupi-refined-media-library' ),
			'doubleyoupi-media-library',
			[ $this, 'render_page' ]
		);

		// Body class `dy-media-library` — lets SCSS target the
		// Refined Admin Dashboard + Refined Media Library combination (e.g. collections inherit
		// the accent colour when both modules are active).
		add_filter( 'admin_body_class', [ $this, 'body_class' ] );
	}

	/**
	 * Adds the `dy-media-library` class to the admin body tag so SCSS
	 * can target the combination of active modules. The class is applied
	 * on ALL admin pages (not only upload.php) because the media library
	 * can be opened as a modal from any screen (post editor, customizer, etc.).
	 */
	public function body_class( $classes ) {
		$classes = (string) $classes;
		if ( false === strpos( ' ' . $classes . ' ', ' dy-media-library ' ) ) {
			$classes .= ' dy-media-library';
		}
		return $classes;
	}

	/**
	 * Render the tabbed admin page.
	 * Uses native WP tab markup (nav-tab-wrapper / nav-tab / nav-tab-active).
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		$page_url    = admin_url( 'admin.php?page=doubleyoupi-media-library' );

		$tabs = [
			'settings' => __( 'Settings', 'doubleyoupi-refined-media-library' ),
			'orphans'  => __( 'Unused media', 'doubleyoupi-refined-media-library' ),
		];

		// Import tab is shown only when a competing source is detected
		// (active plugin or residual data) — otherwise it is hidden entirely.
		if ( SourceDetector::has_any() ) {
			$tabs['import'] = __( 'Import', 'doubleyoupi-refined-media-library' );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Refined Media Library', 'doubleyoupi-refined-media-library' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( $slug === 'settings' ? $page_url : $page_url . '&tab=' . $slug ); ?>"
						class="nav-tab <?php echo $current_tab === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="tab-content" style="margin-top:20px">
				<?php
				match ( $current_tab ) {
					'orphans' => OrphanAdminPage::instance()->render_tab(),
					'import'  => MigrationManager::instance()->render_tab(),
						default   => Settings::instance()->render_tab(),
				};
				?>
			</div>
		</div>
		<?php
	}
}
