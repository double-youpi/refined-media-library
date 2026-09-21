<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * i18n strings for the migration "Import" tab, injected into JS
 * (migration.js) via wp_localize_script from MigrationManager::enqueue_assets().
 *
 * sprintf-style substitutions on the JS side via the fmt() helper: %s, %d.
 */
final class MigrationI18n {

	/** @return array<string,string> */
	public static function strings(): array {
		return [
			'noSources'      => __( 'No other folder plugin detected. Nothing to import.', 'doubleyoupi-refined-media-library' ),
			'detected'       => __( 'Detected plugins', 'doubleyoupi-refined-media-library' ),
			/* translators: %1$d: folder count, %2$d: media count */
			'sourceSummary'  => __( '%1$d folders · %2$d media', 'doubleyoupi-refined-media-library' ),
			'alreadyImported'=> __( 'Already imported', 'doubleyoupi-refined-media-library' ),
			'preview'        => __( 'Preview', 'doubleyoupi-refined-media-library' ),
			'import'         => __( 'Import', 'doubleyoupi-refined-media-library' ),
			'rollback'       => __( 'Undo this import', 'doubleyoupi-refined-media-library' ),
			'previewing'     => __( 'Reading source…', 'doubleyoupi-refined-media-library' ),
			'previewTitle'   => __( 'Import preview', 'doubleyoupi-refined-media-library' ),
			/* translators: %1$d: folders, %2$d: collections, %3$d: media */
			'previewCounts'  => __( '%1$d folders, %2$d collections and %3$d media will be imported.', 'doubleyoupi-refined-media-library' ),
			'confirmImport'  => __( 'Start import', 'doubleyoupi-refined-media-library' ),
			'cancel'         => __( 'Cancel', 'doubleyoupi-refined-media-library' ),
			'importing'      => __( 'Importing…', 'doubleyoupi-refined-media-library' ),
			'phaseTerms'     => __( 'Creating folders…', 'doubleyoupi-refined-media-library' ),
			'phaseRelations' => __( 'Assigning media…', 'doubleyoupi-refined-media-library' ),
			/* translators: %1$d: folders+collections, %2$d: media */
			'doneMessage'    => __( 'Done. %1$d folders and %2$d media imported.', 'doubleyoupi-refined-media-library' ),
			'viewLibrary'    => __( 'Open the media library', 'doubleyoupi-refined-media-library' ),
			'rollbackConfirm'=> __( 'Remove all folders created by this import? Your media and the source plugin are left untouched.', 'doubleyoupi-refined-media-library' ),
			'rollingBack'    => __( 'Undoing…', 'doubleyoupi-refined-media-library' ),
			/* translators: %d: number of folders removed */
			'rolledBack'     => __( '%d imported folders removed.', 'doubleyoupi-refined-media-library' ),
			'error'          => __( 'Something went wrong. Please try again.', 'doubleyoupi-refined-media-library' ),
			'folder'         => __( 'Folder', 'doubleyoupi-refined-media-library' ),
			'collection'     => __( 'Collection', 'doubleyoupi-refined-media-library' ),
		];
	}
}
