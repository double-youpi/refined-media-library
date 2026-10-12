<?php declare(strict_types=1);
namespace DoubleYoupi\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper for injecting the Double Youpi SVG icon sprite.
 *
 * The sprite lives in `assets/images/dy-modules-sprite.svg` and contains one
 * <symbol> per icon (modules + helpers: folder/check/arrow). Admin pages that
 * need an icon call `IconSprite::queue()` during rendering — the sprite is then
 * printed once at the start of `admin_footer`, just before the parser resolves
 * the <use href="#dy-icon-…"> references.
 *
 * Why not a standard enqueue_style: the sprite must be inlined in the same
 * origin as the <use> elements to avoid CORS issues, and <symbol> definitions
 * cannot be lazy-loaded via <link>. This is an intentional direct print.
 */
final class IconSprite {

	private static bool $queued = false;
	private static bool $printed = false;

	/**
	 * Call this from any page that needs the sprite. No-op if already called
	 * during the same request.
	 */
	public static function queue(): void {
		if ( self::$queued ) {
			return;
		}
		self::$queued = true;
		// Priority 5: early in admin_footer, before scripts that might inspect
		// the DOM. The sprite is hidden with display:none and has no layout impact.
		add_action( 'admin_footer', [ self::class, 'print_sprite' ], 5 );
	}

	/**
	 * Prints the SVG sprite content inline. No escaping: the file is versioned
	 * inside the plugin (no user input), so the output is intentional.
	 * The leading <svg style="display:none"> tag ensures nothing is visually rendered.
	 */
	public static function print_sprite(): void {
		if ( self::$printed ) {
			return;
		}
		self::$printed = true;

		$path = DOUBLEYOUPI_DIR . 'assets/images/dy-modules-sprite.svg';
		if ( ! file_exists( $path ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file shipped with the plugin.
		// The file opens with an authoring comment, which wp_kses() would turn into text.
		echo wp_kses( (string) preg_replace( '/<!--.*?-->/s', '', (string) file_get_contents( $path ) ), Kses::svg_tags() );
	}

	/**
	 * Renders the HTML for a sprite icon use. Prefer this over writing <svg><use>
	 * by hand in templates — it also ensures queue() is called.
	 *
	 * @param string $id     Symbol ID without prefix (e.g., 'admin', 'media-library').
	 * @param string $class  Additional CSS classes to apply to the <svg>.
	 * @param int    $size   Pixel size (width AND height). Defaults to 24.
	 *
	 * @return string Safe HTML ready to print directly.
	 */
	public static function svg( string $id, string $class = '', int $size = 24 ): string {
		self::queue();

		$safe_id    = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $id ) );
		$safe_class = esc_attr( trim( 'dy-icon ' . $class ) );
		$safe_size  = max( 8, min( 256, $size ) );

		return sprintf(
			'<svg class="%s" width="%d" height="%d" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#dy-icon-%s"></use></svg>',
			$safe_class,
			$safe_size,
			$safe_size,
			esc_attr( $safe_id )
		);
	}
}
