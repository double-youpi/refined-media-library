<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralised i18n string catalogue for the Refined Media Library module.
 *
 * All user-facing strings used by the JS frontend (folder-tree.js,
 * media-detail.js, media-filters.js, etc.) are declared here and injected
 * via wp_localize_script from FolderManager::enqueue_assets().
 *
 * Why a dedicated class rather than an inline array:
 *   - FolderManager::enqueue_assets() stays readable (~70 lines instead of 197)
 *   - The i18n catalogue lives in one file that is easy to grep
 *   - Future string scanners (POT generators) have a single place to parse
 *
 * Key conventions (JS side):
 *   - camelCase
 *   - sprintf substitutions: %s, %d, %1$d %2$s (helper fmt() in folder-tree.js)
 *   - Categories grouped by commented sections
 */
final class FolderManagerI18n {

	/**
	 * Full map of i18n strings exposed to the JS frontend.
	 *
	 * @return array<string,string>
	 */
	public static function strings(): array {
		return [
			// ── Folder actions ─────────────────────────────────────────────
			'foldersLabel'            => __( 'Folders', 'doubleyoupi-refined-media-library' ),
			'newFolder'               => __( 'New folder', 'doubleyoupi-refined-media-library' ),
			'newSubfolder'            => __( 'New subfolder', 'doubleyoupi-refined-media-library' ),
			'moveTo'                  => __( 'Move to…', 'doubleyoupi-refined-media-library' ),
			'topLevel'                => __( 'Top level', 'doubleyoupi-refined-media-library' ),
			'moreActions'             => __( 'More actions', 'doubleyoupi-refined-media-library' ),
			'rename'                  => __( 'Rename', 'doubleyoupi-refined-media-library' ),
			'delete'                  => __( 'Delete folder', 'doubleyoupi-refined-media-library' ),
			'deleteConfirm'           => __( 'Delete this folder? Media files will not be deleted.', 'doubleyoupi-refined-media-library' ),
			'noFolder'                => __( 'No folder', 'doubleyoupi-refined-media-library' ),
			'allFolders'              => __( 'All folders', 'doubleyoupi-refined-media-library' ),
			'noFoldersYet'            => __( 'No folders yet.', 'doubleyoupi-refined-media-library' ),
			'couldNotLoadFolders'     => __( 'Could not load folders.', 'doubleyoupi-refined-media-library' ),
			'showAll'                 => __( 'Show all media', 'doubleyoupi-refined-media-library' ),
			'showOrphans'             => __( 'Media without folder', 'doubleyoupi-refined-media-library' ),

			// ── Collections ────────────────────────────────────────────────
			'collections'             => __( 'Collections', 'doubleyoupi-refined-media-library' ),
			'newCollection'           => __( 'New collection', 'doubleyoupi-refined-media-library' ),
			'renameCollection'        => __( 'Rename collection', 'doubleyoupi-refined-media-library' ),
			'deleteCollection'        => __( 'Delete collection', 'doubleyoupi-refined-media-library' ),
			'deleteColConfirm'        => __( 'Delete this collection? Media files will not be deleted.', 'doubleyoupi-refined-media-library' ),
			'noCollectionsYet'        => __( 'No collections yet.', 'doubleyoupi-refined-media-library' ),
			'couldNotLoadCollections' => __( 'Could not load collections.', 'doubleyoupi-refined-media-library' ),

			// ── Disabled sections + panel footer ────────────────────────────
			'foldersDisabled'         => __( 'Folders are disabled.', 'doubleyoupi-refined-media-library' ),
			'collectionsDisabled'     => __( 'Collections are disabled.', 'doubleyoupi-refined-media-library' ),
			'enable'                  => __( 'Enable', 'doubleyoupi-refined-media-library' ),
			'panelSettings'           => __( 'Settings', 'doubleyoupi-refined-media-library' ),

			// ── Where used ────────────────────────────────────────────────
			'whereUsed'               => __( 'Where used', 'doubleyoupi-refined-media-library' ),
			'notUsed'                 => __( 'Not used anywhere', 'doubleyoupi-refined-media-library' ),

			// ── Bulk dialogs ──────────────────────────────────────────────
			'destinationFolder'       => __( 'Destination folder', 'doubleyoupi-refined-media-library' ),
			'rootNoFolder'            => __( '— Root (no folder) —', 'doubleyoupi-refined-media-library' ),
			'chooseCollection'        => __( '— Choose a collection —', 'doubleyoupi-refined-media-library' ),
			'cancel'                  => __( 'Cancel', 'doubleyoupi-refined-media-library' ),
			'move'                    => __( 'Move', 'doubleyoupi-refined-media-library' ),
			'add'                     => __( 'Add', 'doubleyoupi-refined-media-library' ),
			'create'                  => __( 'Create', 'doubleyoupi-refined-media-library' ),
			'deleteAction'            => __( 'Delete', 'doubleyoupi-refined-media-library' ),
			'discard'                 => __( 'Discard', 'doubleyoupi-refined-media-library' ),
			'folderName'              => __( 'Folder name', 'doubleyoupi-refined-media-library' ),
			'collectionName'          => __( 'Collection name', 'doubleyoupi-refined-media-library' ),
			/* translators: %d: number of selected media files. */
			'bulkMoveTitle'           => __( 'Move %d files to a folder', 'doubleyoupi-refined-media-library' ),
			'bulkMoveTitle1'          => __( 'Move 1 file to a folder', 'doubleyoupi-refined-media-library' ),
			/* translators: %d: number of selected media files. */
			'bulkAddColTitle'         => __( 'Add %d files to a collection', 'doubleyoupi-refined-media-library' ),
			'bulkAddColTitle1'        => __( 'Add 1 file to a collection', 'doubleyoupi-refined-media-library' ),

			// ── Confirms (use %s for the entity name) ─────────────────────
			/* translators: %s: folder name. */
			'deleteFolderConfirm'     => __( "Delete folder « %s »?

Media inside won’t be deleted — they will be moved to « Media without folder ».", 'doubleyoupi-refined-media-library' ),
			/* translators: %s: collection name. */
			'deleteCollectionConfirm' => __( "Delete collection « %s »?

Media files won’t be deleted — they will simply be removed from this collection.", 'doubleyoupi-refined-media-library' ),
			'thisFolder'              => __( 'this folder', 'doubleyoupi-refined-media-library' ),
			'thisCollection'          => __( 'this collection', 'doubleyoupi-refined-media-library' ),

			// ── Toast messages (use %s / %d / %1$d %2$s for substitution) ─
			/* translators: %s: folder name. */
			'folderDeleted'           => __( 'Folder « %s » deleted', 'doubleyoupi-refined-media-library' ),
			/* translators: %s: collection name. */
			'collectionDeleted'       => __( 'Collection « %s » deleted', 'doubleyoupi-refined-media-library' ),
			'folderDeleteFailed'      => __( 'Could not delete folder', 'doubleyoupi-refined-media-library' ),
			'collectionDeleteFailed'  => __( 'Could not delete collection', 'doubleyoupi-refined-media-library' ),
			'folderMoveFailed'        => __( 'Move failed', 'doubleyoupi-refined-media-library' ),
			'collectionAddFailed'     => __( 'Add failed', 'doubleyoupi-refined-media-library' ),
			'folderFallback'          => __( 'folder', 'doubleyoupi-refined-media-library' ),
			'collectionFallback'      => __( 'collection', 'doubleyoupi-refined-media-library' ),

			/* translators: %s: folder name. */
			'movedOne'                => __( '1 file moved to « %s »', 'doubleyoupi-refined-media-library' ),
			/* translators: %1$d: number of files; %2$s: folder name. */
			'movedMany'               => __( '%1$d files moved to « %2$s »', 'doubleyoupi-refined-media-library' ),
			/* translators: %s: collection name. */
			'addedOne'                => __( '1 file added to « %s »', 'doubleyoupi-refined-media-library' ),
			/* translators: %1$d: number of files; %2$s: collection name. */
			'addedMany'               => __( '%1$d files added to « %2$s »', 'doubleyoupi-refined-media-library' ),
			/* translators: %s: collection name. */
			'alreadyInOne'            => __( 'Already in « %s »', 'doubleyoupi-refined-media-library' ),
			/* translators: %s: collection name. */
			'alreadyInAll'            => __( 'All already in « %s »', 'doubleyoupi-refined-media-library' ),
			/* translators: %1$d: number of files moved; %2$d: number already present. */
			'movedAndDup'             => __( '%1$d moved, %2$d already present', 'doubleyoupi-refined-media-library' ),
			/* translators: %1$d: number of files added; %2$d: number already present. */
			'addedAndDup'             => __( '%1$d added, %2$d already present', 'doubleyoupi-refined-media-library' ),

			// ── Detail panel ──────────────────────────────────────────────
			'loading'                 => __( 'Loading…', 'doubleyoupi-refined-media-library' ),
			'loadingDetails'          => __( 'Loading details…', 'doubleyoupi-refined-media-library' ),
			'loadingShort'            => __( 'Loading…', 'doubleyoupi-refined-media-library' ),
			'loadError'               => __( 'Loading error.', 'doubleyoupi-refined-media-library' ),
			'previous'                => __( 'Previous', 'doubleyoupi-refined-media-library' ),
			'next'                    => __( 'Next', 'doubleyoupi-refined-media-library' ),
			'close'                   => __( 'Close', 'doubleyoupi-refined-media-library' ),
			'dragToResize'            => __( 'Drag to resize', 'doubleyoupi-refined-media-library' ),
			'viewFullSize'            => __( 'View full size', 'doubleyoupi-refined-media-library' ),

			'information'             => __( 'Information', 'doubleyoupi-refined-media-library' ),
			'date'                    => __( 'Date', 'doubleyoupi-refined-media-library' ),
			'mimeType'                => __( 'Type', 'doubleyoupi-refined-media-library' ),
			'dimensions'              => __( 'Dimensions', 'doubleyoupi-refined-media-library' ),
			'size'                    => __( 'Size', 'doubleyoupi-refined-media-library' ),
			'altText'                 => __( 'Alternative text', 'doubleyoupi-refined-media-library' ),
			'altPlaceholder'          => __( 'Describe the image for accessibility…', 'doubleyoupi-refined-media-library' ),
			'title'                   => __( 'Title', 'doubleyoupi-refined-media-library' ),
			'fileUrl'                 => __( 'File URL', 'doubleyoupi-refined-media-library' ),
			'copy'                    => __( 'Copy', 'doubleyoupi-refined-media-library' ),
			'copied'                  => __( 'Copied!', 'doubleyoupi-refined-media-library' ),

			'folder'                  => __( 'Folder', 'doubleyoupi-refined-media-library' ),
			'noFolderOption'          => __( '— No folder —', 'doubleyoupi-refined-media-library' ),
			'addToCollection'         => __( '+ Add to a collection', 'doubleyoupi-refined-media-library' ),
			'noCollections'           => __( 'No collection', 'doubleyoupi-refined-media-library' ),
			'remove'                  => __( 'Remove', 'doubleyoupi-refined-media-library' ),

			'save'                    => __( 'Save', 'doubleyoupi-refined-media-library' ),
			'edit'                    => __( 'Edit', 'doubleyoupi-refined-media-library' ),
			'deleteAction'            => __( 'Delete', 'doubleyoupi-refined-media-library' ),
			'saving'                  => __( 'Saving…', 'doubleyoupi-refined-media-library' ),
			'saved'                   => __( 'Saved ✓', 'doubleyoupi-refined-media-library' ),
			'errorRetry'              => __( 'Error — try again', 'doubleyoupi-refined-media-library' ),

			'notUsedInContent'        => __( 'Not used in content.', 'doubleyoupi-refined-media-library' ),
			'untitled'                => __( '(untitled)', 'doubleyoupi-refined-media-library' ),

			'deleteFileConfirm'       => __( 'Permanently delete this file? This action cannot be undone.', 'doubleyoupi-refined-media-library' ),
			'deleteFileFailed'        => __( 'Could not delete this file.', 'doubleyoupi-refined-media-library' ),
			'unsavedConfirm'          => __( 'Unsaved changes will be lost. Continue?', 'doubleyoupi-refined-media-library' ),

			// Locale tag for JS date formatting (e.g. "fr-FR", "en-US").
			'dateLocale'              => str_replace( '_', '-', get_locale() ),

			// ── File size units ──────────────────────────────────────────
			'sizeBytes'               => __( 'B', 'doubleyoupi-refined-media-library' ),
			'sizeKB'                  => __( 'KB', 'doubleyoupi-refined-media-library' ),
			'sizeMB'                  => __( 'MB', 'doubleyoupi-refined-media-library' ),

			// ── Type filter (media-filters.js) ───────────────────────────
			'allTypes'                => __( 'All types', 'doubleyoupi-refined-media-library' ),
			'images'                  => __( 'Images', 'doubleyoupi-refined-media-library' ),
			'videos'                  => __( 'Videos', 'doubleyoupi-refined-media-library' ),
			'audio'                   => __( 'Audio', 'doubleyoupi-refined-media-library' ),
			'documents'               => __( 'Documents', 'doubleyoupi-refined-media-library' ),
			'svg'                     => __( 'SVG', 'doubleyoupi-refined-media-library' ),

			// ── Search action button (grid view, mirrors list view) ──────
			'searchAction'            => __( 'Search', 'doubleyoupi-refined-media-library' ),

			// ── Bulk actions on list view ────────────────────────────────
			'noItemsSelected'         => __( 'No media selected.', 'doubleyoupi-refined-media-library' ),

			// ── Bulk metadata dialog (bulk-metadata.js) ──────────────────
			/* translators: %d: number of selected media files. */
			'editMetadataCount'       => __( 'Edit metadata (%d)', 'doubleyoupi-refined-media-library' ),
			'bulkEditEyebrow'         => __( 'Bulk edit', 'doubleyoupi-refined-media-library' ),
			'filesSelectedOne'        => __( '1 file selected', 'doubleyoupi-refined-media-library' ),
			/* translators: %d: number of selected media files. */
			'filesSelectedMany'       => __( '%d files selected', 'doubleyoupi-refined-media-library' ),
			'bulkKeepHint'            => __( 'Leave a field empty to keep each file’s existing value unchanged.', 'doubleyoupi-refined-media-library' ),
			'altLabel'                => __( 'Alt text', 'doubleyoupi-refined-media-library' ),
			'altDescribe'             => __( 'Describe the image…', 'doubleyoupi-refined-media-library' ),
			'titlePlaceholder'        => __( 'File title…', 'doubleyoupi-refined-media-library' ),
			'applyToOne'              => __( 'Apply to 1 file', 'doubleyoupi-refined-media-library' ),
			/* translators: %d: number of selected media files. */
			'applyToMany'             => __( 'Apply to %d files', 'doubleyoupi-refined-media-library' ),
			'applying'                => __( 'Applying…', 'doubleyoupi-refined-media-library' ),
			'retry'                   => __( 'Retry', 'doubleyoupi-refined-media-library' ),

			// ── Detail panel : third-party compat fields section ───────────
			'moreFields'              => __( 'More fields', 'doubleyoupi-refined-media-library' ),
		];
	}
}
