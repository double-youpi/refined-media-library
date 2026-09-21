<?php declare(strict_types=1);
namespace DoubleYoupi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module catalog — SOLO VERSION (generated in WP.org builds).
 *
 * Mirror of the suite catalog: same 8 modules + tools, same statuses
 * "available / coming-soon". The solo difference lies elsewhere:
 *   - `available_ids()` returns only the module(s) ACTUALLY bundled
 *     (SoloConfig::present_modules()), so only those get a persistent toggle;
 *   - the module(s) bundled here are promoted to "available" whatever their
 *     range status, so each solo plugin features its own module;
 *   - OverviewPage decides "bundled here vs. showcase" via ModuleRegistry::has().
 *
 * Modules marked `available` but NOT bundled (e.g. Refined Admin Dashboard in
 * the Media Library solo) become showcase cards for the broader product range.
 *
 * Source of truth: build/standalone/shell/ModuleCatalog.php. Metadata is
 * intentionally kept as a mirror of the suite catalog; a future Phase II
 * refactor will extract this metadata into a single shared source.
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
				// Range status: not yet published as a standalone plugin on
				// WordPress.org, hence "coming soon" in the OTHER solo builds.
				// In its own build it is promoted to "available" by
				// `with_present_modules_promoted()`.
				'status'       => self::STATUS_COMING_SOON,
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
			[
				'id'           => 'performance',
				'name'         => __( 'Simple Performance', 'doubleyoupi-refined-media-library' ),
				'description'  => __( 'Caching, CSS/JS, lazy-load, responsive WebP/AVIF delivery, self-hosted fonts, and a native Cloudflare CDN.', 'doubleyoupi-refined-media-library' ),
				'icon'         => 'dashicons-performance',
				// Shown as "coming soon" in solo builds until its own free plugin
				// is published on WordPress.org.
				'status'       => self::STATUS_COMING_SOON,
				'settings_url' => null,
			],
			[
				'id'           => 'static-site',
				'name'         => __( 'Simple Static', 'doubleyoupi-refined-media-library' ),
				'description'  => __( 'Static HTML export to shrink your attack surface and host free on Cloudflare Pages, Netlify or GitHub Pages.', 'doubleyoupi-refined-media-library' ),
				'icon'         => 'dashicons-download',
				'status'       => self::STATUS_COMING_SOON,
				'settings_url' => null,
			],
			// Toolkit is no longer a standalone module/product: it is bundled
			// for free with Refined Admin Dashboard (its toggles live in the
			// "Double Youpi > Tools" page). No showcase card here.
			[
				'id'           => 'seo-a11y',
				'name'         => __( 'Simple SEO & Accessibility', 'doubleyoupi-refined-media-library' ),
				'description'  => __( 'Completes Rank Math on accessibility: auto aria-labels, AI alt-text, and a WCAG audit.', 'doubleyoupi-refined-media-library' ),
				'icon'         => 'dashicons-universal-access-alt',
				'status'       => self::STATUS_COMING_SOON,
				'settings_url' => null,
			],
			[
				'id'           => 'metrics',
				'name'         => __( 'Simple Metrics', 'doubleyoupi-refined-media-library' ),
				'description'  => __( 'PageSpeed Insights tracking in the admin: per-page history over time, on mobile and desktop.', 'doubleyoupi-refined-media-library' ),
				'icon'         => 'dashicons-chart-line',
				'status'       => self::STATUS_COMING_SOON,
				'settings_url' => null,
			],
			[
				'id'           => 'backups',
				'name'         => __( 'Simple Backups', 'doubleyoupi-refined-media-library' ),
				'description'  => __( 'Scheduled full or DB-only backups, with rotation and one-click restore (auto-snapshot first).', 'doubleyoupi-refined-media-library' ),
				'icon'         => 'dashicons-backup',
				'status'       => self::STATUS_COMING_SOON,
				'settings_url' => null,
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
			'performance'   => 'doubleyoupi-performance',
			'static-site'   => 'doubleyoupi-static',
			'metrics'       => 'doubleyoupi-metrics',
			'backups'       => 'doubleyoupi-backups',
			'staging'       => 'doubleyoupi-staging',
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
	 * Showcase metadata for the product range. Mirror of the suite version.
	 *
	 * @return array{tier:'free'|'paid', wporg_slug:?string, marketing_url:string, screenshots:string[]}
	 */
	public static function vitrine( string $id ): array {
		$shot = static fn( string $module ): array => [
			"assets/images/vitrine/{$module}-1.svg",
			"assets/images/vitrine/{$module}-2.svg",
			"assets/images/vitrine/{$module}-3.svg",
		];

		$map = [
			'admin'         => [ 'tier' => 'free', 'wporg_slug' => null,                   'marketing_url' => 'https://doubleyoupi.com/plugins/refined-admin-dashboard' ],
			'media-library' => [ 'tier' => 'free', 'wporg_slug' => 'doubleyoupi-refined-media-library', 'marketing_url' => 'https://doubleyoupi.com/plugins/refined-media-library' ],
			'performance'   => [ 'tier' => 'free', 'wporg_slug' => null,                   'marketing_url' => 'https://doubleyoupi.com/simple-performance' ],
			'static-site'   => [ 'tier' => 'free', 'wporg_slug' => null,                   'marketing_url' => 'https://doubleyoupi.com/simple-static' ],
			'seo-a11y'      => [ 'tier' => 'free', 'wporg_slug' => null,                   'marketing_url' => 'https://doubleyoupi.com/simple-seo-accessibility' ],
			'toolkit'       => [ 'tier' => 'paid', 'wporg_slug' => null,                   'marketing_url' => 'https://doubleyoupi.com/toolkit' ],
			'metrics'       => [ 'tier' => 'paid', 'wporg_slug' => null,                   'marketing_url' => 'https://doubleyoupi.com/simple-metrics' ],
			'backups'       => [ 'tier' => 'paid', 'wporg_slug' => null,                   'marketing_url' => 'https://doubleyoupi.com/simple-backups' ],
			'staging'       => [ 'tier' => 'paid', 'wporg_slug' => null,                   'marketing_url' => 'https://doubleyoupi.com/simple-staging' ],
		];

		$entry = $map[ $id ] ?? [ 'tier' => 'paid', 'wporg_slug' => null, 'marketing_url' => 'https://doubleyoupi.com' ];
		$entry['screenshots'] = $shot( $id );
		return $entry;
	}
}
