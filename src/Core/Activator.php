<?php declare(strict_types=1);
namespace DoubleYoupi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activation / deactivation — SOLO VERSION (generated in WP.org builds).
 *
 * Decoupled from the suite: no license cron. All installation mechanics
 * (default options, taxonomy registration, flush) are delegated to
 * Installer::run(), driven by SoloConfig.
 */
final class Activator {

	public static function activate(): void {
		Installer::run();

		// "Plugin activated" notice — displayed briefly in the admin.
		set_transient( 'doubleyoupi_activated_notice', 1, 60 );
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
