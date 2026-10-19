<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary;

use DoubleYoupi\Admin\AdminMenu;
use DoubleYoupi\Core\AbstractModule;
use DoubleYoupi\Core\Build;
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

	/**
	 * The toggle note is registered here, and not in boot(), on purpose.
	 *
	 * boot() only runs when the module is ENABLED, and the note matters most
	 * when it is not: it is what tells the reader what turning it back on
	 * would bring. The constructor runs through instance(), which the entry
	 * file calls whatever the toggle says.
	 */
	protected function __construct() {
		parent::__construct();
		add_filter( 'doubleyoupi_filter_module_toggle_note', [ $this, 'toggle_note' ], 10, 2 );
		add_action( 'doubleyoupi_installing', [ $this, 'backfill_maintenance' ] );
	}

	/**
	 * Les installations existantes gardent les deux fonctions d'entretien.
	 *
	 * Le badge de doublon et l'onglet des médias inutilisés ont longtemps
	 * tourné sans interrupteur, donc allumés pour tout le monde. Leur donner
	 * un interrupteur éteint par défaut suffirait à les faire disparaître à
	 * la première mise à jour, sans que personne l'ait demandé.
	 *
	 * On écrit donc explicitement « allumé » sur une montée de version, avant
	 * que les valeurs par défaut du build ne s'appliquent : elles ne touchent
	 * qu'aux options absentes, donc elles respecteront ce choix. Une
	 * installation neuve, elle, ne passe pas ici et reçoit bien l'extinction.
	 */
	public function backfill_maintenance( bool $fresh ): void {
		if ( $fresh ) {
			return;
		}
		if ( null === get_option( Settings::OPTION_DUPLICATES, null ) ) {
			add_option( Settings::OPTION_DUPLICATES, 1 );
		}
	}

	public function id(): string {
		return 'media-library';
	}

	/**
	 * One sentence under the Overview toggle: what turning this off changes.
	 *
	 * Second sentence only when the standalone plugin sits alongside this
	 * build, which means it has stepped aside. Reactivating it is the way to
	 * keep the media library without the rest, so the reader should know it is
	 * there rather than discover an empty Media screen.
	 */
	public function toggle_note( string $note, string $module_id ): string {
		if ( 'media-library' !== $module_id ) {
			return $note;
		}

		$note = __( 'Turning the media library off hands the Media screen back to WordPress. Your folders and your collections are kept, and come back if you turn it on again.', 'doubleyoupi-refined-media-library' );

		if ( self::standalone_stands_aside() ) {
			$note .= ' ' . __( 'Double Youpï Refined Media Library is installed and standing aside: reactivate it if you want the media library without the rest.', 'doubleyoupi-refined-media-library' );
		}

		return $note;
	}

	/**
	 * Is the standalone media library plugin installed next to THIS build?
	 *
	 * False inside the standalone itself, where the question makes no sense.
	 */
	private static function standalone_stands_aside(): bool {
		$standalone = 'doubleyoupi-refined-media-library/doubleyoupi-refined-media-library.php';

		if ( defined( 'DOUBLEYOUPI_BASENAME' ) && DOUBLEYOUPI_BASENAME === $standalone ) {
			return false;
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( $standalone );
	}

	public function name(): string {
		return 'Refined Media Library';
	}

	public function boot(): void {
		Settings::instance()->boot();
		FolderTaxonomy::instance()->boot();
		CollectionTaxonomy::instance()->boot();
		FolderManager::instance()->boot();
		// Folders and collections in the Bricks builder's media browser. Only
		// hooks: nothing runs, and no Bricks class is touched, unless Bricks 2.4+
		// fires them.
		Integrations\BricksBridge::boot();
		CollectionManager::instance()->boot();
		MetadataAutoFiller::instance()->boot();
		ImageConverter::instance()->boot();
		WhereUsed::instance()->boot();

		// Le badge de doublon calcule une empreinte à chaque téléversement,
		// expose des champs REST et ajoute une colonne : ce coût permanent
		// mérite un interrupteur, éteint par défaut sur une installation neuve.
		if ( Settings::duplicates_enabled() ) {
			DuplicateDetector::instance()->boot();
		}

		// Le scanner de médias inutilisés, lui, ne coûte rien au repos : il
		// n'expose que des gestionnaires AJAX, donc il ne travaille que quand
		// on le lui demande. Pas d'interrupteur, l'onglet est toujours là.
		OrphanScanner::instance()->boot();
		OrphanAdminPage::instance()->boot();
		MigrationManager::instance()->boot();
		MediaManagerConflict::instance()->boot();
		( new FeedbackOwner() )->boot();

		// Tabbed page: callback on this class to manage the active tab.
		AdminMenu::add_page(
			static fn(): string => __( 'Refined Media Library', 'doubleyoupi-refined-media-library' ),
			static fn(): string => __( 'Media Library', 'doubleyoupi-refined-media-library' ),
			'doubleyoupi-media-library',
			[ $this, 'render_page' ]
		);

		// Body class `dy-media-library` — lets SCSS target the
		// Refined Admin Dashboard + Refined Media Library combination (e.g. collections inherit
		// the accent colour when both modules are active).
		add_filter( 'admin_body_class', [ $this, 'body_class' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_shell_light' ] );
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
		// Light theme (Refined Media Library alone): on every screen too, for
		// the same reason, a media window can open anywhere.
		if ( Settings::light_theme_enabled() && false === strpos( ' ' . $classes . ' ', ' dy-ml-light ' ) ) {
			$classes .= ' dy-ml-light';
		}
		return $classes;
	}

	/**
	 * Light values for the library's own pages and the Double Youpï pages
	 * around them (see shell-light.css). Loaded whatever the setting: the
	 * sheet is inert without a light class, so the Settings switch and the
	 * theme switch of Refined Admin Dashboard both apply without a reload.
	 */
	public function enqueue_shell_light( string $hook ): void {
		if ( ! str_contains( $hook, 'doubleyoupi' ) ) {
			return;
		}
		$rel = 'assets/css/modules/media-library/shell-light.css';
		$abs = Build::dir( __FILE__, $rel );
		if ( ! file_exists( $abs ) ) {
			return;
		}
		wp_enqueue_style(
			'doubleyoupi-ml-shell-light',
			Build::url( __FILE__, $rel ),
			[],
			(string) ( filemtime( $abs ) ?: DOUBLEYOUPI_VERSION )
		);
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
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		$page_url    = admin_url( 'admin.php?page=doubleyoupi-media-library' );

		$tabs = [
			'settings' => __( 'Settings', 'doubleyoupi-refined-media-library' ),
		];

		$tabs['orphans'] = __( 'Unused media', 'doubleyoupi-refined-media-library' );

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
				match ( true ) {
					'orphans' === $current_tab
						=> OrphanAdminPage::instance()->render_tab(),
					'import' === $current_tab
						=> MigrationManager::instance()->render_tab(),
					default
						=> Settings::instance()->render_tab(),
				};
				?>
			</div>
		</div>
		<?php
	}
}
