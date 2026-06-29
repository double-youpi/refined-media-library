<?php declare(strict_types=1);
namespace DoubleYoupi\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hero quips for the Overview page (solo mode).
 *
 * In place of the "X / Y active modules" counter — which is meaningless when
 * only one module is bundled — a random "mock boast" quip is shown in the accent color.
 *
 * Two pools:
 *   - generic()  : work for ANY standalone plugin (or the suite).
 *   - specific() : specific to a given module (reference its function).
 * Each future standalone plugin adds its quips to the specific() map.
 *
 * Translatable strings; French translations are provided via build/i18n/extras
 * (other locales fall back to English).
 */
final class HeroQuips {

	/**
	 * Full pool for a given module (generic + specific quips).
	 *
	 * @return string[]
	 */
	public static function for_module( string $module_id ): array {
		return array_merge( self::generic(), self::specific( $module_id ) );
	}

	/**
	 * A random quip for the given module, or '' if the pool is empty.
	 */
	public static function random( string $module_id ): string {
		$pool = self::for_module( $module_id );
		if ( empty( $pool ) ) {
			return '';
		}
		return (string) $pool[ array_rand( $pool ) ];
	}

	/**
	 * Generic quips (reusable by any standalone plugin).
	 *
	 * @return string[]
	 */
	public static function generic(): array {
		return [
			__( "Objectively the best plugin you've installed in the last five minutes.", 'doubleyoupi-refined-media-library' ),
			__( 'Built different. We have a certificate and everything.', 'doubleyoupi-refined-media-library' ),
			__( 'Zero shortcodes were harmed in the making of this plugin.', 'doubleyoupi-refined-media-library' ),
			__( 'Awarded Plugin of the Year by a panel of one.', 'doubleyoupi-refined-media-library' ),
			__( 'Refined enough to make your theme nervous.', 'doubleyoupi-refined-media-library' ),
			__( 'We benchmarked it against perfection. Perfection lost.', 'doubleyoupi-refined-media-library' ),
			__( 'Your other plugins are taking this personally.', 'doubleyoupi-refined-media-library' ),
			__( "Currently winning an argument it wasn't invited to.", 'doubleyoupi-refined-media-library' ),
			__( 'Suspiciously good for something this free.', 'doubleyoupi-refined-media-library' ),
			__( 'The bar was high. We brought a ladder.', 'doubleyoupi-refined-media-library' ),
			__( 'Compared to nothing, it is literally the best.', 'doubleyoupi-refined-media-library' ),
			__( 'Powered by talent and a concerning amount of confidence.', 'doubleyoupi-refined-media-library' ),
			__( 'Reviews are in: we wrote them ourselves.', 'doubleyoupi-refined-media-library' ),
			__( 'Finally, a plugin that matches your impeccable taste.', 'doubleyoupi-refined-media-library' ),
			__( 'Great taste. You installed this, after all.', 'doubleyoupi-refined-media-library' ),
			__( 'Two icons in one admin: you and this plugin.', 'doubleyoupi-refined-media-library' ),
			__( 'People with taste end up here. Welcome.', 'doubleyoupi-refined-media-library' ),
			__( 'Smart move installing this. We expected nothing less from you.', 'doubleyoupi-refined-media-library' ),
		];
	}

	/**
	 * Module-specific quips (reference its function).
	 *
	 * @return string[]
	 */
	private static function specific( string $module_id ): array {
		$map = [
			'media-library' => [
				__( "Probably the finest media library you'll configure all day.", 'doubleyoupi-refined-media-library' ),
				__( 'The plugin your media library brags about.', 'doubleyoupi-refined-media-library' ),
				__( "Your media library finally has a personality. It's smug.", 'doubleyoupi-refined-media-library' ),
			],
		];
		return $map[ $module_id ] ?? [];
	}
}
