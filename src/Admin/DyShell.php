<?php declare(strict_types=1);
namespace DoubleYoupi\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Standalone Double Youpi admin skin (dark only).
 *
 * Gives Double Youpi pages (Overview, Tools, Media Library…) a premium dark look
 * plus a tab chipbar, EVEN when the Refined Admin Dashboard module is absent
 * (standalone plugin) or disabled (suite). Intentional scope: CONTENT + CHIPBAR
 * only — the admin bar and the left WordPress menu remain native (we only add
 * sub-pages to the menu via AdminMenu).
 *
 * Yields when the global Admin redesign is running: that redesign defines the
 * DOUBLEYOUPI_ADMIN_SKIN_ACTIVE constant, which we check here. This prevents
 * a double chipbar or any chrome conflict.
 *
 * The CSS (dark tokens + chipbar, scoped to body.dy-shell) lives in
 * assets/css/admin-overview.css, already enqueued on DY pages by
 * OverviewPage::maybe_enqueue_styles().
 */
final class DyShell {

	public static function register(): void {
		add_filter( 'admin_body_class', [ self::class, 'body_class' ] );
		// Priority 1: before notice rendering (always later than in_admin_header),
		// to flush the queue on Double Youpi pages.
		add_action( 'in_admin_header', [ self::class, 'suppress_foreign_notices' ], 1 );
		add_action( 'in_admin_header', [ self::class, 'render_chipbar' ], 8 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	/**
	 * Suppresses all third-party (and core) notices on Double Youpi pages in
	 * standalone mode — the premium experience must stay clean. This is a
	 * deliberate choice; it is a common and accepted practice on a plugin's
	 * own pages.
	 *
	 * Our own notices are rendered directly in the markup (not via these hooks),
	 * so they are unaffected.
	 */
	public static function suppress_foreign_notices(): void {
		if ( ! self::is_active() ) {
			return;
		}
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
	}

	/**
	 * Is the standalone skin active on the current request?
	 * Yes if: we are on a Double Youpi page AND the global Admin redesign is
	 * not running (otherwise it provides the chrome).
	 */
	private static function is_active(): bool {
		if ( defined( 'DOUBLEYOUPI_ADMIN_SKIN_ACTIVE' ) ) {
			return false;
		}
		return self::is_dy_page();
	}

	/**
	 * Double Youpi page = ?page=doubleyoupi or ?page=doubleyoupi-*.
	 */
	private static function is_dy_page(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display read, no action taken.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return $page === AdminMenu::SLUG || str_starts_with( $page, AdminMenu::SLUG . '-' );
	}

	public static function body_class( $classes ) {
		if ( self::is_active() ) {
			$classes = (string) $classes . ' dy-shell';
		}
		return $classes;
	}

	public static function enqueue(): void {
		if ( ! self::is_active() ) {
			return;
		}
		$js = DOUBLEYOUPI_DIR . 'assets/js/dy-shell.js';
		if ( ! file_exists( $js ) ) {
			return;
		}
		wp_enqueue_script(
			'doubleyoupi-shell',
			DOUBLEYOUPI_URL . 'assets/js/dy-shell.js',
			[],
			(string) ( filemtime( $js ) ?: DOUBLEYOUPI_VERSION ),
			true
		);
	}

	/**
	 * Renders the Double Youpi tab chipbar at the top of the content column
	 * (#wpcontent). Source: the sub-pages actually registered under the
	 * Double Youpi menu ($submenu[AdminMenu::SLUG]).
	 */
	public static function render_chipbar(): void {
		if ( ! self::is_active() ) {
			return;
		}

		global $submenu;
		$items = $submenu[ AdminMenu::SLUG ] ?? [];
		if ( ! is_array( $items ) || count( $items ) < 2 ) {
			return; // Single page: no tabs to display.
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		echo '<nav class="dy-chipbar" id="dy-shell-chipbar" aria-label="' . esc_attr__( 'Double Youpï', 'doubleyoupi-refined-media-library' ) . '">';
		echo '<div class="dy-chipbar-inner">';

		foreach ( $items as $item ) {
			// $item: [0] menu_title, [1] capability, [2] menu_slug.
			$slug  = isset( $item[2] ) ? (string) $item[2] : '';
			$title = isset( $item[0] ) ? wp_strip_all_tags( (string) $item[0] ) : '';
			if ( '' === $slug || '' === $title ) {
				continue;
			}
			$url     = admin_url( 'admin.php?page=' . $slug );
			$classes = 'dy-chip' . ( $slug === $current ? ' current' : '' );

			printf(
				'<a class="%1$s" href="%2$s"%3$s>%4$s</a>',
				esc_attr( $classes ),
				esc_url( $url ),
				$slug === $current ? ' aria-current="page"' : '',
				esc_html( $title )
			);
		}

		echo '<span class="dy-track" aria-hidden="true"></span>';
		echo '</div>';
		echo '</nav>';
	}
}
