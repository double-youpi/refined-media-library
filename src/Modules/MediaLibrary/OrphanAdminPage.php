<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary;

use DoubleYoupi\Core\Build;

use DoubleYoupi\Core\AbstractModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Unused media" tab of the Refined Media Library page.
 *
 * No longer registered as a standalone sub-menu — integrated as a tab
 * in the main Media Library page via MediaLibrary::render_page().
 *
 * UX flow:
 *  1. "Scan" button → AJAX → list of orphans (thumbnail, name, size, date)
 *  2. Multiple selection via checkboxes
 *  3. Explicit confirmation required before deletion
 *  4. AJAX deletion with feedback (X deleted, Y errors)
 */
class OrphanAdminPage extends AbstractModule {

	public function id(): string {
		return 'media-library-orphan-page';
	}

	public function name(): string {
		return 'Refined Media Library — Orphan Admin Page';
	}

	public function boot(): void {
		// Assets are enqueued by MediaLibrary::render_page() on the orphans tab.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function enqueue_assets( string $hook ): void {
		// Load only on the Refined Media Library page, orphans tab.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_ml_page    = str_contains( $hook, 'doubleyoupi-media-library' );
		$is_orphan_tab = ( $_GET['tab'] ?? 'settings' ) === 'orphans'; // phpcs:ignore

		if ( ! $is_ml_page || ! $is_orphan_tab ) {
			return;
		}

		$css = Build::dir( __FILE__, 'assets/css/modules/media-library/orphan-admin.css' );
		if ( file_exists( $css ) ) {
			wp_enqueue_style(
				'doubleyoupi-orphan-admin',
				Build::url( __FILE__, 'assets/css/modules/media-library/orphan-admin.css' ),
				[],
				DOUBLEYOUPI_VERSION
			);
		}

		$js = Build::dir( __FILE__, 'assets/js/modules/media-library/orphan-admin.js' );
		if ( file_exists( $js ) ) {
			wp_enqueue_script(
				'doubleyoupi-orphan-admin',
				Build::url( __FILE__, 'assets/js/modules/media-library/orphan-admin.js' ),
				[ 'jquery' ],
				DOUBLEYOUPI_VERSION,
				true
			);
			wp_localize_script( 'doubleyoupi-orphan-admin', 'doubleyoupiOrphanAdmin', [
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonceScan'   => wp_create_nonce( 'doubleyoupi_ml_orphan_scan' ),
				'nonceDelete' => wp_create_nonce( 'doubleyoupi_ml_orphan_delete' ),
				'i18n'        => [
					'scanning'     => __( 'Scanning…', 'doubleyoupi-refined-media-library' ),
					'noOrphans'    => __( 'No unused media found. Your library is clean!', 'doubleyoupi-refined-media-library' ),
					/* translators: %d: number of unused media files found. */
					'foundOne'     => __( '%d unused media file found.', 'doubleyoupi-refined-media-library' ),
					/* translators: %d: number of unused media files found. */
					'foundMany'    => __( '%d unused media files found.', 'doubleyoupi-refined-media-library' ),
					/* translators: %d: number of unused media files remaining. */
					'remainingOne' => __( '%d unused media file remaining.', 'doubleyoupi-refined-media-library' ),
					/* translators: %d: number of unused media files remaining. */
					'remainingMany' => __( '%d unused media files remaining.', 'doubleyoupi-refined-media-library' ),
					/* translators: %d: number of media files to be deleted. */
					'confirmLabel' => __( 'I confirm the permanent deletion of %d media files.', 'doubleyoupi-refined-media-library' ),
					'deleteBtn'    => __( 'Delete selected media', 'doubleyoupi-refined-media-library' ),
					'deleting'     => __( 'Deleting…', 'doubleyoupi-refined-media-library' ),
					/* translators: %d: number of deleted media files. */
					'deleted'      => __( '%d files deleted.', 'doubleyoupi-refined-media-library' ),
					/* translators: %d: number of errors encountered. */
					'errors'       => __( '%d errors.', 'doubleyoupi-refined-media-library' ),
				],
			] );
		}
	}

	/**
	 * Tab content — rendered without a .wrap wrapper (handled by MediaLibrary::render_page).
	 */
	public function render_tab(): void {
		?>
		<p class="description">
			<?php esc_html_e( 'Media files not referenced in any post, page, featured image, or custom field.', 'doubleyoupi-refined-media-library' ); ?>
		</p>

		<p>
			<button type="button" class="button button-primary" id="dy-orphan-scan-btn">
				<?php esc_html_e( 'Scan for unused media', 'doubleyoupi-refined-media-library' ); ?>
			</button>
			<span class="spinner" id="dy-orphan-spinner" style="float:none;visibility:hidden;margin-left:4px"></span>
		</p>

		<div id="dy-orphan-results" style="display:none">
			<p id="dy-orphan-summary"></p>

			<form id="dy-orphan-form">
				<?php $this->render_confirm_block( 'top' ); ?>

				<table class="wp-list-table widefat fixed striped media" id="dy-orphan-table">
					<thead>
						<tr>
							<th scope="col" class="manage-column column-cb check-column">
								<input type="checkbox" id="dy-orphan-check-all">
							</th>
							<th><?php esc_html_e( 'Preview', 'doubleyoupi-refined-media-library' ); ?></th>
							<th><?php esc_html_e( 'File', 'doubleyoupi-refined-media-library' ); ?></th>
							<th><?php esc_html_e( 'Type', 'doubleyoupi-refined-media-library' ); ?></th>
							<th><?php esc_html_e( 'Size', 'doubleyoupi-refined-media-library' ); ?></th>
							<th><?php esc_html_e( 'Uploaded', 'doubleyoupi-refined-media-library' ); ?></th>
						</tr>
					</thead>
					<tbody id="dy-orphan-tbody"></tbody>
				</table>

				<?php $this->render_confirm_block( 'bottom' ); ?>
			</form>

			<div id="dy-orphan-done" style="display:none"></div>
		</div>
		<?php
	}

	/**
	 * Deletion confirmation block. Rendered twice (above and below the table)
	 * so the confirmation stays in reach whether the user is at the top or the
	 * bottom of a long list. Both copies are kept in sync by orphan-admin.js,
	 * hence classes (not unique IDs) on the shared controls.
	 *
	 * @param string $position 'top' or 'bottom' (drives the spacing modifier).
	 */
	private function render_confirm_block( string $position ): void {
		$modifier = 'top' === $position ? 'top' : 'bottom';
		?>
		<div class="dy-orphan-confirm dy-orphan-confirm--<?php echo esc_attr( $modifier ); ?>" style="display:none">
			<svg class="dy-orphan-confirm__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
				<path d="M12 9v4M12 17h.01"/>
			</svg>
			<label class="dy-orphan-confirm__check">
				<input type="checkbox" class="dy-orphan-confirm-cb">
				<span class="dy-orphan-confirm-label"></span>
			</label>
			<div class="dy-orphan-confirm__actions">
				<button type="button" class="button button-primary dy-orphan-delete-btn" disabled>
					<?php esc_html_e( 'Delete selected media', 'doubleyoupi-refined-media-library' ); ?>
				</button>
				<span class="spinner dy-orphan-delete-spinner" style="float:none;visibility:hidden;margin:0"></span>
			</div>
		</div>
		<?php
	}
}
