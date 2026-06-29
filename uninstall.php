<?php
/**
 * Uninstall handler — non-destructive by default. Purges options only if
 * the user has opted in (option `doubleyoupi_purge_on_uninstall` = 1).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Inline closure: no variables leak into the global scope.
( static function (): void {
	if ( ! (bool) get_option( 'doubleyoupi_purge_on_uninstall', 0 ) ) {
		return;
	}

	$options = array (
		0 => 'doubleyoupi_ml_folders',
		1 => 'doubleyoupi_ml_collections',
		2 => 'doubleyoupi_ml_auto_metadata',
		3 => 'doubleyoupi_ml_webp_upload',
		4 => 'doubleyoupi_ml_avif_upload',
		5 => 'doubleyoupi_ml_image_quality',
		6 => 'doubleyoupi_refined_media_library_installed_version',
		7 => 'doubleyoupi_purge_on_uninstall',
);
	foreach ( $options as $option ) {
		delete_option( $option );
	}
	delete_transient( 'doubleyoupi_activated_notice' );
	delete_transient( 'dy_ml_initial_hash_scan_needed' );
} )();
