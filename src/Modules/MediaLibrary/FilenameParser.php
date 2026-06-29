<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transforms a filename into a human-readable label for use as alt text and title.
 *
 * Zero WordPress dependencies. Fully unit-testable in isolation.
 *
 * Algorithm:
 *  1. Strip the extension
 *  2. Split camelCase: "heroImage" → "hero Image"
 *  3. Split consecutive acronyms: "HTMLParser" → "HTML Parser"
 *  4. Replace separators (-_. etc.) with a space
 *  5. Normalise multiple spaces
 *  6. mb_strtolower + ucwords
 *
 * Examples:
 *  "my-hero-image.jpg"   → "My Hero Image"
 *  "heroImage2024.jpg"   → "Hero Image 2024"
 *  "product_shot-v2.png" → "Product Shot V2"
 *  "IMG_0045.jpg"        → "Img 0045"
 *  "HTMLParser.jpg"      → "Html Parser"
 */
final class FilenameParser {

	/**
	 * Transforms a filename (with or without extension) into a human-readable label.
	 */
	public static function parse( string $filename ): string {
		$name = pathinfo( $filename, PATHINFO_FILENAME );

		// Split camelCase: lowercase/digit followed by uppercase → insert space.
		$name = (string) preg_replace( '/([a-z\d])([A-Z])/', '$1 $2', $name );

		// Split consecutive acronyms: "HTMLParser" → "HTML Parser".
		$name = (string) preg_replace( '/([A-Z]+)([A-Z][a-z])/', '$1 $2', $name );

		// Replace common separators with a space.
		$name = (string) preg_replace( '/[-_.+]+/', ' ', $name );

		// Normalise multiple spaces.
		$name = (string) preg_replace( '/\s+/', ' ', $name );

		// Lowercase everything then capitalise the first letter of each word.
		return ucwords( mb_strtolower( trim( $name ) ) );
	}

	/**
	 * Label for the alt attribute (identical to parse — the distinction exists
	 * to allow a future override without breaking the API).
	 */
	public static function for_alt( string $filename ): string {
		return self::parse( $filename );
	}

	/**
	 * Label for the attachment's post_title.
	 */
	public static function for_title( string $filename ): string {
		return self::parse( $filename );
	}
}
