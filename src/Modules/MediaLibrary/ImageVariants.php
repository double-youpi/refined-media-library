<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The WebP and AVIF copies ImageConverter writes beside an image
 * (`photo.jpg` → `photo.jpg.webp`), found again for the APIs.
 *
 * Nothing records them at conversion time, so each lookup checks the disk.
 * Callers only reach this class when a front asks for the fields (a GraphQL
 * field it selected, or the REST field), and PHP caches the stat calls for
 * the rest of the request.
 */
final class ImageVariants {

	public const FORMATS = [ 'webp', 'avif' ];

	/**
	 * Every existing copy of an attachment, by format then by size name
	 * (`full` for the attached file itself).
	 *
	 * @return array<string, array<string, array{url:string, width:int, height:int}>>
	 */
	public static function all( int $attachment_id ): array {
		$out = [];
		foreach ( self::sources( $attachment_id ) as $size => $source ) {
			foreach ( self::FORMATS as $format ) {
				if ( is_file( ImageConverter::variant_path( $source['path'], $format ) ) ) {
					$out[ $format ][ $size ] = [
						'url'    => $source['url'] . '.' . $format,
						'width'  => $source['width'],
						'height' => $source['height'],
					];
				}
			}
		}
		return $out;
	}

	/**
	 * URL of one copy, or null when there is none.
	 */
	public static function url( int $attachment_id, string $format, string $size = 'full' ): ?string {
		$sources = self::sources( $attachment_id );
		if ( ! isset( $sources[ $size ] ) || ! in_array( $format, self::FORMATS, true ) ) {
			return null;
		}
		$source = $sources[ $size ];
		return is_file( ImageConverter::variant_path( $source['path'], $format ) ) ? $source['url'] . '.' . $format : null;
	}

	/**
	 * A srcset of the copies in one format, for a `<source type="image/…">`:
	 * the sizes whose proportions match the reference size, as WordPress
	 * builds its own srcset. Null when no copy qualifies.
	 */
	public static function srcset( int $attachment_id, string $format, string $size = 'full' ): ?string {
		$sources = self::sources( $attachment_id );
		if ( ! isset( $sources[ $size ] ) || ! in_array( $format, self::FORMATS, true ) ) {
			return null;
		}
		$ref   = $sources[ $size ];
		$parts = [];
		foreach ( $sources as $source ) {
			if ( $source['width'] < 1 || $source['height'] < 1 ) {
				continue;
			}
			if ( ! wp_image_matches_ratio( $ref['width'], $ref['height'], $source['width'], $source['height'] ) ) {
				continue;
			}
			if ( is_file( ImageConverter::variant_path( $source['path'], $format ) ) ) {
				$parts[ $source['width'] ] = $source['url'] . '.' . $format . ' ' . $source['width'] . 'w';
			}
		}
		if ( ! $parts ) {
			return null;
		}
		ksort( $parts );
		return implode( ', ', $parts );
	}

	/**
	 * Path, URL and dimensions of the attached file and of each size.
	 *
	 * @return array<string, array{path:string, url:string, width:int, height:int}>
	 */
	private static function sources( int $attachment_id ): array {
		static $cache = [];
		if ( isset( $cache[ $attachment_id ] ) ) {
			return $cache[ $attachment_id ];
		}
		$path = get_attached_file( $attachment_id );
		$url  = wp_get_attachment_url( $attachment_id );
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! $path || ! $url || ! is_array( $meta ) || ! wp_attachment_is_image( $attachment_id ) ) {
			$cache[ $attachment_id ] = [];
			return [];
		}
		$out = [
			'full' => [
				'path'   => $path,
				'url'    => $url,
				'width'  => (int) ( $meta['width'] ?? 0 ),
				'height' => (int) ( $meta['height'] ?? 0 ),
			],
		];
		$dir_path = trailingslashit( dirname( $path ) );
		$dir_url  = trailingslashit( dirname( $url ) );
		foreach ( (array) ( $meta['sizes'] ?? [] ) as $name => $size ) {
			if ( empty( $size['file'] ) ) {
				continue;
			}
			$out[ (string) $name ] = [
				'path'   => $dir_path . $size['file'],
				'url'    => $dir_url . $size['file'],
				'width'  => (int) ( $size['width'] ?? 0 ),
				'height' => (int) ( $size['height'] ?? 0 ),
			];
		}
		$cache[ $attachment_id ] = $out;
		return $out;
	}
}
