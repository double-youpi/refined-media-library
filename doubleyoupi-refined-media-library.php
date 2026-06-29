<?php
/**
 * Plugin Name:       Double Youpï Refined Media Library
 * Plugin URI:        https://doubleyoupi.com/plugins/refined-media-library
 * Description:       Nested folders with drag-and-drop, collections, duplicate and orphan cleanup, auto alt text and WebP/AVIF for the WordPress media library, without the per-image fiddling.
 * Version:           0.1.3
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
// If the full Double Youpi suite is active, the standalone steps aside to
// prevent any DoubleYoupi\Core\* class collisions. Never silent.
// Inline closure: no variables leak into the global scope.
if ( ( static function (): bool {
	$active = (array) get_option( 'active_plugins', array() );
	if ( in_array( 'doubleyoupi/doubleyoupi.php', $active, true ) ) {
		return true;
	}
	if ( is_multisite() ) {
		$network = (array) get_site_option( 'active_sitewide_plugins', array() );
		return array_key_exists( 'doubleyoupi/doubleyoupi.php', $network );
	}
	return false;
} )() ) {
	add_action( 'admin_notices', static function (): void {
		echo '<div class="notice notice-warning"><p>'
			. esc_html__( 'You are already running the full Double Youpi suite, and it includes Double Youpï Refined Media Library. This standalone copy stepped aside to stay out of its way. Want to run it on its own? Deactivate the suite first.', 'doubleyoupi-refined-media-library' )
			. '</p></div>';
	} );
	return;
}

define( 'DOUBLEYOUPI_VERSION', '0.1.3' );
define( 'DOUBLEYOUPI_FILE', __FILE__ );
define( 'DOUBLEYOUPI_DIR', plugin_dir_path( __FILE__ ) );
define( 'DOUBLEYOUPI_URL', plugin_dir_url( __FILE__ ) );
define( 'DOUBLEYOUPI_BASENAME', plugin_basename( __FILE__ ) );

// ── Minimal PSR-4 autoloader (DoubleYoupi\ → src/) — no Composer required ────
spl_autoload_register( static function ( string $class ): void {
	$prefix = 'DoubleYoupi\\';
	$len    = strlen( $prefix );
	if ( strncmp( $class, $prefix, $len ) !== 0 ) {
		return;
	}
	$rel  = str_replace( '\\', '/', substr( $class, $len ) );
	$file = DOUBLEYOUPI_DIR . 'src/' . $rel . '.php';
	if ( is_file( $file ) ) {
		require $file;
	}
} );

register_activation_hook( __FILE__, array( '\DoubleYoupi\Core\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\DoubleYoupi\Core\Activator', 'deactivate' ) );

add_action( 'plugins_loaded', static function (): void {
	\DoubleYoupi\Core\ModuleRegistry::register(
		\DoubleYoupi\Modules\MediaLibrary\MediaLibrary::instance()
	);

	\DoubleYoupi\Core\Plugin::instance()->boot();
}, 5 );
