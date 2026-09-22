<?php declare(strict_types=1);
namespace DoubleYoupi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build — where the running code actually lives.
 *
 * Every Double Youpi build shares one namespace and one set of constants.
 * That is fine for the suite, and it was fine while only one standalone
 * could be active. Install two of them (Refined Admin Dashboard and Refined
 * Media Library, the likely pair) and the second one loses: the constants
 * are already defined, so `DOUBLEYOUPI_DIR` keeps pointing at the FIRST
 * plugin. Its autoloader then looks for its own classes in the other
 * plugin's folder and the site dies on a fatal.
 *
 * So each build registers its own root here, and a module asks for its
 * assets by the file that needs them:
 *
 *     Build::url( __FILE__, 'assets/js/module.js' )
 *     Build::dir( __FILE__, 'assets/css/module.css' )
 *
 * The longest registered directory containing that file wins, which is
 * always the plugin the file was shipped in. Nothing is registered in a
 * plain suite install: the constants answer, exactly as before.
 *
 * Shared code (src/Admin, src/Core) keeps using the constants on purpose.
 * Those files and their assets exist in every build, so the first root is
 * as good as any.
 */
final class Build {

	/** @var array<string,string> normalised directory (trailing slash) => URL (trailing slash) */
	private static array $roots = [];

	/** Called once per build, from its main plugin file. */
	public static function register( string $dir, string $url ): void {
		$dir = wp_normalize_path( $dir );
		if ( '' === $dir ) {
			return;
		}
		self::$roots[ trailingslashit( $dir ) ] = trailingslashit( $url );
	}

	/** Absolute URL of a file shipped next to `$file`. */
	public static function url( string $file, string $rel = '' ): string {
		return self::root( $file )[1] . ltrim( $rel, '/' );
	}

	/** Absolute path of a file shipped next to `$file`. */
	public static function dir( string $file, string $rel = '' ): string {
		return self::root( $file )[0] . ltrim( $rel, '/' );
	}

	/**
	 * Every root a Double Youpi build has claimed, the constant included.
	 * Used to tell our own files from a third party's.
	 *
	 * @return string[] normalised directories, trailing slash.
	 */
	public static function roots(): array {
		$roots = array_keys( self::$roots );
		if ( defined( 'DOUBLEYOUPI_DIR' ) ) {
			$roots[] = trailingslashit( wp_normalize_path( (string) DOUBLEYOUPI_DIR ) );
		}
		return array_values( array_unique( array_filter( $roots ) ) );
	}

	/**
	 * @return array{0:string,1:string} [ directory, URL ], both trailing-slashed.
	 */
	private static function root( string $file ): array {
		$file = wp_normalize_path( $file );
		$best = '';
		foreach ( self::$roots as $dir => $url ) {
			if ( str_starts_with( $file, $dir ) && strlen( $dir ) > strlen( $best ) ) {
				$best = $dir;
			}
		}
		if ( '' !== $best ) {
			return [ $best, self::$roots[ $best ] ];
		}
		return [
			defined( 'DOUBLEYOUPI_DIR' ) ? trailingslashit( wp_normalize_path( (string) DOUBLEYOUPI_DIR ) ) : '',
			defined( 'DOUBLEYOUPI_URL' ) ? trailingslashit( (string) DOUBLEYOUPI_URL ) : '',
		];
	}
}
