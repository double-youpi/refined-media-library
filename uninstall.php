<?php
/**
 * Uninstall handler — non-destructive by default. Purges options only if
 * the user has opted in (option `doubleyoupi_purge_on_uninstall` = 1).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// The closure keeps its working variables out of the global scope. Its result
// does land there, so it carries the plugin prefix: WordPress.org requires
// four characters at least, and refused the short `dy` in June 2026.
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
		6 => 'doubleyoupi_ml_duplicates',
		7 => 'doubleyoupi_hide_feedback',
		8 => 'doubleyoupi_settings_owner',
		9 => 'doubleyoupi_refined_media_library_installed_version',
		10 => 'doubleyoupi_purge_on_uninstall',
);
	foreach ( $options as $option ) {
		delete_option( $option );
	}
	delete_transient( 'doubleyoupi_activated_notice' );
	delete_transient( 'doubleyoupi_ml_initial_hash_scan_needed' );
	// Ancien nom, servi jusqu'à la 0.3.1 : les sites installés le portent encore.
	delete_transient( 'dy_ml_initial_hash_scan_needed' );
} )();
