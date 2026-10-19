<?php declare(strict_types=1);
namespace DoubleYoupi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module catalog, SOLO VERSION (generated in WP.org builds).
 *
 * Lists only the plugins published on WordPress.org (Refined Admin Dashboard
 * and Refined Media Library): a free plugin must not describe modules that
 * are not available to its users.
 *   - `available_ids()` returns only the module(s) ACTUALLY bundled
 *     (SoloConfig::present_modules()), so only those get a persistent toggle;
 *   - the module(s) bundled here are promoted to "available", so each solo
 *     plugin features its own module;
 *   - OverviewPage decides "bundled here vs. showcase" via ModuleRegistry::has().
 *
 * A module marked `available` but NOT bundled (Refined Admin Dashboard in the
 * Media Library solo) becomes a showcase card with an install button.
 *
 * Source of truth: build/standalone/shell/ModuleCatalog.php.
 */
final class ModuleCatalog {

	public const STATUS_AVAILABLE   = 'available';
	public const STATUS_COMING_SOON = 'coming-soon';

	/**
	 * @return array<int, array{id:string, name:string, description:string, icon:string, status:string, settings_url:?string}>
	 */
	public static function all(): array {
		$entries = [
			[
				'id'           => 'admin',
				'name'         => __( 'Refined Admin Dashboard', 'doubleyoupi-refined-media-library' ),
				'description'  => __( 'A full rethink of the WordPress admin: smart dashboard, reworked navigation, redesigned content tables, dark mode, and your branding.', 'doubleyoupi-refined-media-library' ),
				'icon'         => 'dashicons-admin-customizer',
				// Published on WordPress.org on 14 Oct 2026, so the OTHER solo
				// builds show it as an installable showcase card (and the media
				// library settings may say it is free too). In its own build it
				// is a present module anyway (`with_present_modules_promoted()`).
				'status'       => self::STATUS_AVAILABLE,
				'settings_url' => null,
			],
			[
				'id'           => 'media-library',
				'name'         => __( 'Refined Media Library', 'doubleyoupi-refined-media-library' ),
				'description'  => __( 'Folders and collections, drag & drop, quick preview, duplicate & orphan cleanup, plus WebP/AVIF conversion on upload.', 'doubleyoupi-refined-media-library' ),
				'icon'         => 'dashicons-format-gallery',
				'status'       => self::STATUS_AVAILABLE,
				'settings_url' => admin_url( 'admin.php?page=doubleyoupi-media-library' ),
			],
		];

		return self::with_present_modules_promoted( $entries );
	}

	/**
	 * Promotes the module(s) bundled in THIS build to "available".
	 *
	 * The statuses above describe the RANGE (which modules already exist as a
	 * standalone plugin), not the current build. A module shipped inside this
	 * plugin is available by definition: the user is running it right now.
	 * Without this pass, a solo plugin could list its own module under
	 * "Coming soon" while the module powers the very page being displayed
	 * (Refined Admin Dashboard did, in its own solo build).
	 *
	 * Consequence, valid for every present and future solo build: each plugin
	 * puts its own module forward as a real card (toggle + settings link) and
	 * showcases the rest of the range.
	 *
	 * @param array<int, array{id:string, name:string, description:string, icon:string, status:string, settings_url:?string}> $entries
	 * @return array<int, array{id:string, name:string, description:string, icon:string, status:string, settings_url:?string}>
	 */
	private static function with_present_modules_promoted( array $entries ): array {
		if ( ! class_exists( SoloConfig::class ) ) {
			return $entries;
		}
		$present = SoloConfig::present_modules();
		if ( empty( $present ) ) {
			return $entries;
		}

		foreach ( $entries as $i => $entry ) {
			if ( ! in_array( $entry['id'], $present, true ) ) {
				continue;
			}
			$entries[ $i ]['status'] = self::STATUS_AVAILABLE;
			if ( empty( $entry['settings_url'] ) ) {
				$entries[ $i ]['settings_url'] = self::settings_url_for( $entry['id'] );
			}
		}

		return $entries;
	}

	/**
	 * Admin page of a module, when it has one. Used only to restore the link of
	 * a module promoted by `with_present_modules_promoted()`: the entries above
	 * deliberately carry `null` while the module is not deliverable.
	 */
	private static function settings_url_for( string $id ): ?string {
		$pages = [
			'admin'         => 'doubleyoupi-admin',
			'media-library' => 'doubleyoupi-media-library',
		];

		return isset( $pages[ $id ] ) ? admin_url( 'admin.php?page=' . $pages[ $id ] ) : null;
	}

	/** @return array|null */
	public static function find( string $id ): ?array {
		foreach ( self::all() as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * IDs of the modules actually bundled (= activatable) in this solo plugin.
	 *
	 * @return string[]
	 */
	public static function available_ids(): array {
		return SoloConfig::present_modules();
	}

	/**
	 * Showcase metadata for the two published plugins.
	 *
	 * @return array{tier:'free'|'paid', wporg_slug:?string, marketing_url:string, screenshots:string[]}
	 */
	public static function vitrine( string $id ): array {
		// Real screenshots (WebP) where they exist, SVG placeholders elsewhere.
		$shot = static function ( string $module ): array {
			$ext = in_array( $module, [ 'admin' ], true ) ? 'webp' : 'svg';
			return [
				"assets/images/vitrine/{$module}-1.{$ext}",
				"assets/images/vitrine/{$module}-2.{$ext}",
				"assets/images/vitrine/{$module}-3.{$ext}",
			];
		};

		$map = [
			'admin'         => [ 'tier' => 'free', 'wporg_slug' => 'doubleyoupi-refined-admin-dashboard', 'marketing_url' => 'https://doubleyoupi.com/plugins/refined-admin-dashboard' ],
			'media-library' => [ 'tier' => 'free', 'wporg_slug' => 'doubleyoupi-refined-media-library',   'marketing_url' => 'https://doubleyoupi.com/plugins/refined-media-library' ],
		];

		$entry = $map[ $id ] ?? [ 'tier' => 'free', 'wporg_slug' => null, 'marketing_url' => 'https://doubleyoupi.com' ];
		$entry['screenshots'] = $shot( $id );
		return $entry;
	}
}
