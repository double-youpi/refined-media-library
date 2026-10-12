<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary;

use DoubleYoupi\Core\Build;

use DoubleYoupi\Core\AbstractModule;
use DoubleYoupi\Admin\Kses;

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
 *  3. Explicit confirmation required before anything moves
 *  4. AJAX move to the trash, with feedback (X moved, Y kept, Z errors)
 *  5. A "Recently removed" section below, where each file can be put back or
 *     erased for good. Nothing on this screen erases a file in one step.
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
		$is_orphan_tab = isset( $_GET['tab'] ) && 'orphans' === sanitize_key( wp_unslash( $_GET['tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection.

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
				'nonceDelete'  => wp_create_nonce( 'doubleyoupi_ml_orphan_delete' ),
				'nonceRestore' => wp_create_nonce( 'doubleyoupi_ml_orphan_restore' ),
				'noncePurge'   => wp_create_nonce( 'doubleyoupi_ml_orphan_purge' ),
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
					/*
					 * Chaque message compté vient en deux versions, singulier et
					 * pluriel, comme foundOne/foundMany plus haut. Les comptes
					 * sont connus côté navigateur, donc _n() ne peut pas servir :
					 * les deux formes partent ensemble et le script choisit.
					 */
					/* translators: %d: number of media files to be moved to the trash. */
					'confirmLabelOne'  => __( 'I confirm moving %d media file to the trash.', 'doubleyoupi-refined-media-library' ),
					/* translators: %d: number of media files to be moved to the trash. */
					'confirmLabelMany' => __( 'I confirm moving %d media files to the trash.', 'doubleyoupi-refined-media-library' ),
					'deleteBtn'    => __( 'Move selected media to the trash', 'doubleyoupi-refined-media-library' ),
					'deleting'     => __( 'Moving to the trash…', 'doubleyoupi-refined-media-library' ),
					/* translators: %d: number of media files moved to the trash. */
					'deletedOne'   => __( '%d file moved to the trash.', 'doubleyoupi-refined-media-library' ),
					/* translators: %d: number of media files moved to the trash. */
					'deletedMany'  => __( '%d files moved to the trash.', 'doubleyoupi-refined-media-library' ),
					/* translators: %d: number of errors encountered. */
					'errorsOne'    => __( '%d error.', 'doubleyoupi-refined-media-library' ),
					/* translators: %d: number of errors encountered. */
					'errorsMany'   => __( '%d errors.', 'doubleyoupi-refined-media-library' ),
					/* translators: %d: number of media files left alone because they are in use. */
					'keptOne'      => __( '%d file kept: it is in use.', 'doubleyoupi-refined-media-library' ),
					/* translators: %d: number of media files left alone because they are in use. */
					'keptMany'     => __( '%d files kept: they are in use.', 'doubleyoupi-refined-media-library' ),
					/* translators: %d: number of restored media files. */
					'restoredOne'  => __( '%d file restored.', 'doubleyoupi-refined-media-library' ),
					/* translators: %d: number of restored media files. */
					'restoredMany' => __( '%d files restored.', 'doubleyoupi-refined-media-library' ),
					/* translators: %d: number of permanently deleted media files. */
					'purgedOne'    => __( '%d file permanently deleted.', 'doubleyoupi-refined-media-library' ),
					/* translators: %d: number of permanently deleted media files. */
					'purgedMany'   => __( '%d files permanently deleted.', 'doubleyoupi-refined-media-library' ),
					/* translators: %d: number of media files about to be erased. */
					'confirmPurgeOne'  => __( 'Permanently delete %d file? It is erased from the server, and this cannot be undone.', 'doubleyoupi-refined-media-library' ),
					/* translators: %d: number of media files about to be erased. */
					'confirmPurgeMany' => __( 'Permanently delete %d files? They are erased from the server, and this cannot be undone.', 'doubleyoupi-refined-media-library' ),
					'trashEmpty'   => __( 'Nothing has been moved to the trash from this screen.', 'doubleyoupi-refined-media-library' ),
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

		<?php $this->render_trash_section(); ?>
		<?php
	}

	/**
	 * "Recently removed": what this screen put in the trash, and the two ways
	 * out of it.
	 *
	 * Rendered on page load rather than fetched, so the section is already
	 * there for someone arriving to undo something without scanning first.
	 */
	private function render_trash_section(): void {
		$trashed = OrphanScanner::trashed_media();
		?>
		<div id="dy-orphan-trash" class="dy-orphan-trash">
			<h2><?php esc_html_e( 'Recently removed', 'doubleyoupi-refined-media-library' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %d: number of days WordPress keeps files in the trash. */
					esc_html__( 'Nothing here has been erased: each file is still on the server, so a page that displays it keeps working. Put a file back and it returns to the media library with its folders. WordPress empties its trash after %d days.', 'doubleyoupi-refined-media-library' ),
					(int) ( defined( 'EMPTY_TRASH_DAYS' ) ? EMPTY_TRASH_DAYS : 30 )
				);
				?>
			</p>

			<p id="dy-orphan-trash-empty" <?php echo $trashed ? 'style="display:none"' : ''; ?>>
				<?php esc_html_e( 'Nothing has been moved to the trash from this screen.', 'doubleyoupi-refined-media-library' ); ?>
			</p>

			<table class="wp-list-table widefat fixed striped media" id="dy-orphan-trash-table" <?php echo $trashed ? '' : 'style="display:none"'; ?>>
				<thead>
					<tr>
						<th><?php esc_html_e( 'Preview', 'doubleyoupi-refined-media-library' ); ?></th>
						<th><?php esc_html_e( 'File', 'doubleyoupi-refined-media-library' ); ?></th>
						<th><?php esc_html_e( 'Removed', 'doubleyoupi-refined-media-library' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'doubleyoupi-refined-media-library' ); ?></th>
					</tr>
				</thead>
				<tbody id="dy-orphan-trash-tbody">
					<?php
					foreach ( $trashed as $post ) {
						echo wp_kses( self::trash_row_html( $post ), Kses::ui_tags() );
					}
					?>
				</tbody>
			</table>

			<p id="dy-orphan-trash-actions" <?php echo $trashed ? '' : 'style="display:none"'; ?>>
				<button type="button" class="button" id="dy-orphan-purge-all">
					<?php esc_html_e( 'Empty the trash', 'doubleyoupi-refined-media-library' ); ?>
				</button>
				<span class="spinner" id="dy-orphan-trash-spinner" style="float:none;visibility:hidden;margin-left:4px"></span>
			</p>

			<div id="dy-orphan-trash-done" style="display:none"></div>
		</div>
		<?php
	}

	/**
	 * One row of the trash table. Shared by the initial render and by the
	 * AJAX answer, so a file just moved looks exactly like one that was
	 * already there.
	 */
	public static function trash_row_html( \WP_Post $post ): string {
		$thumb = wp_get_attachment_image( $post->ID, [ 60, 60 ], true );
		if ( ! $thumb ) {
			$thumb = '<span class="dashicons dashicons-media-default" aria-hidden="true"></span>';
		}
		$file = get_attached_file( $post->ID );

		return sprintf(
			'<tr data-id="%1$d">'
				. '<td>%2$s</td>'
				. '<td>%3$s</td>'
				. '<td>%4$s</td>'
				. '<td>'
					. '<button type="button" class="button dy-orphan-restore">%5$s</button> '
					. '<button type="button" class="button dy-orphan-purge">%6$s</button>'
				. '</td>'
			. '</tr>',
			(int) $post->ID,
			wp_kses_post( $thumb ),
			esc_html( $file ? basename( $file ) : $post->post_title ),
			esc_html( get_the_modified_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $post ) ),
			esc_html__( 'Restore', 'doubleyoupi-refined-media-library' ),
			esc_html__( 'Delete permanently', 'doubleyoupi-refined-media-library' )
		);
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
