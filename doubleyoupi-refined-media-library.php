<?php
/**
 * Plugin Name:       Double Youpï Refined Media Library
 * Plugin URI:        https://doubleyoupi.com/plugins/refined-media-library
 * Description:       Nested folders with drag-and-drop, collections, duplicate and orphan cleanup, auto alt text and WebP/AVIF for the WordPress media library, without the per-image fiddling.
 * Version:           0.3.25
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Double Youpï
 * Author URI:        https://doubleyoupi.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       doubleyoupi-refined-media-library
 * Domain Path:       /languages
 *
 * @package DoubleYoupi
 *
 * Built from the Double Youpi monorepo by bin/build-standalone.php. Do not
 * edit here. Source of truth: src/Modules/ + build/standalone/shell/.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Conflict detection BEFORE any autoloading ────────────────────────────────
// If a plugin that BUNDLES this one is active, this standalone steps aside: it
// would only duplicate what is already there, and its DoubleYoupi\Core\*
// classes would collide. Nothing is deactivated and no data is touched, so
// turning the container off brings this copy straight back. Never silent.
// The closure keeps its working variables out of the global scope. Its result
// does land there, so it carries the plugin prefix: WordPress.org requires
// four characters at least, and refused the short `dy` in June 2026.
// It returns the position of the active container, not its message: this
// runs before `init`, too early to translate (see the notices below).
$doubleyoupi_standalone_container = ( static function (): ?int {
	$containers = array(
		'doubleyoupi/doubleyoupi.php',
		'doubleyoupi-refined-admin-dashboard/doubleyoupi-refined-admin-dashboard.php',
	);
	$active  = (array) get_option( 'active_plugins', array() );
	$network = is_multisite()
		? (array) get_site_option( 'active_sitewide_plugins', array() )
		: array();
	foreach ( $containers as $index => $basename ) {
		if ( in_array( $basename, $active, true )
			|| array_key_exists( $basename, $network ) ) {
			return $index;
		}
	}
	return null;
} )();

if ( $doubleyoupi_standalone_container !== null ) {
	add_action( 'admin_notices', static function () use ( $doubleyoupi_standalone_container ): void {
		// Translated here, at display time, in the same order as the list above.
		$notices = array(
			__( 'Another Double Youpï plugin that already includes Double Youpï Refined Media Library is active, so this standalone copy stepped aside to stay out of its way. Want to run it on its own? Deactivate the other plugin first.', 'doubleyoupi-refined-media-library' ),
			__( 'Double Youpï Refined Admin Dashboard is active, and it includes this whole media library: folders, collections and the detail panel. This copy stepped aside. Your folders, collections and settings are untouched, and the dashboard is using them right now. Want the media library on its own? Deactivate Refined Admin Dashboard first.', 'doubleyoupi-refined-media-library' ),
		);
		echo '<div class="notice notice-warning"><p>'
			. esc_html( $notices[ $doubleyoupi_standalone_container ] ?? '' )
			. '</p></div>';
	} );
	return;
}

// ── Constants ────────────────────────────────────────────────────────────────
// Guarded: another Double Youpi build (the other standalone) may already be
// active and have defined them first. Its values stay, and this build still
// finds its own files, because the autoloader and Core\Build below are bound
// to THIS directory rather than to the constant.
defined( 'DOUBLEYOUPI_VERSION' )  || define( 'DOUBLEYOUPI_VERSION', '0.3.25' );
defined( 'DOUBLEYOUPI_FILE' )     || define( 'DOUBLEYOUPI_FILE', __FILE__ );
defined( 'DOUBLEYOUPI_DIR' )      || define( 'DOUBLEYOUPI_DIR', plugin_dir_path( __FILE__ ) );
defined( 'DOUBLEYOUPI_URL' )      || define( 'DOUBLEYOUPI_URL', plugin_dir_url( __FILE__ ) );
defined( 'DOUBLEYOUPI_BASENAME' ) || define( 'DOUBLEYOUPI_BASENAME', plugin_basename( __FILE__ ) );

// ── Minimal PSR-4 autoloader (DoubleYoupi\ → src/), no Composer required ────
// Bound to __DIR__, never to DOUBLEYOUPI_DIR: with two standalone builds
// active, each one must load the classes it actually ships. PHP walks every
// registered autoloader, so a class this build does not carry is found by the
// other one, and the shared core is loaded once from whichever came first.
spl_autoload_register( static function ( string $class ): void {
	$prefix = 'DoubleYoupi\\';
	$len    = strlen( $prefix );
	if ( strncmp( $class, $prefix, $len ) !== 0 ) {
		return;
	}
	$rel  = str_replace( '\\', '/', substr( $class, $len ) );
	$file = __DIR__ . '/src/' . $rel . '.php';
	if ( is_file( $file ) ) {
		// require_once, jamais require : un nom de classe malformé, venu
		// d'une autre extension ou d'un outil, peut désigner le même
		// fichier par un chemin différent. Sans _once, PHP le relit et
		// meurt sur une redéclaration.
		require_once $file;
	}
} );

// Where this build lives, so its modules resolve their own assets even when
// the constants point at the other one (see Core\Build).
\DoubleYoupi\Core\Build::register( __DIR__, plugin_dir_url( __FILE__ ) );

// Activation runs after `plugins_loaded`, so the modules below have not been
// instantiated yet, nor have the hooks they set in their constructors (a
// module that keeps a setting on upgrade listens to `doubleyoupi_installing`).
// Instantiate them first: constructors only add hooks, nothing boots here.
register_activation_hook( __FILE__, static function (): void {
	\DoubleYoupi\Modules\MediaLibrary\MediaLibrary::instance();
	\DoubleYoupi\Core\Activator::activate();
} );
register_deactivation_hook( __FILE__, array( '\DoubleYoupi\Core\Activator', 'deactivate' ) );

add_action( 'plugins_loaded', static function (): void {
	\DoubleYoupi\Core\ModuleRegistry::register(
		\DoubleYoupi\Modules\MediaLibrary\MediaLibrary::instance()
	);

	\DoubleYoupi\Core\Plugin::instance()->boot();
}, 5 );
