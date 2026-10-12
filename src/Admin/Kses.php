<?php declare(strict_types=1);
namespace DoubleYoupi\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kses — allowlists for the markup our admin screens compose themselves.
 *
 * Icons (inline SVG), the rail, the chipbars and the dashboard cards are built
 * from escaped fragments, then printed as a whole. Printing them through
 * wp_kses() with these lists escapes them at the point of output, as the
 * WordPress.org guidelines ask (review of 12/10/2026), instead of relying on a
 * phpcs:ignore that says the fragments were escaped earlier.
 *
 * Keys are lower case: wp_kses() compares names in lower case and writes the
 * attribute names it keeps in lower case too (viewbox). That is harmless for
 * SVG inside an HTML page: the HTML parser puts viewBox, preserveAspectRatio
 * and the like back in their SVG case. Comments are not supported: strip them
 * before filtering, or wp_kses() turns them into text.
 */
final class Kses {

	/** Presentation attributes shared by every SVG element. */
	private const SVG_ATTRS = [
		'class', 'id', 'style', 'transform', 'fill', 'fill-opacity', 'fill-rule',
		'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit',
		'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'opacity', 'clip-rule',
		'clip-path', 'mask', 'vector-effect', 'color', 'display', 'visibility',
		'aria-hidden', 'aria-label', 'aria-labelledby', 'role', 'focusable', 'pathlength',
	];

	/** Element => its own attributes, on top of SVG_ATTRS. */
	private const SVG_ELEMENTS = [
		'svg'            => [ 'xmlns', 'xmlns:xlink', 'viewbox', 'width', 'height', 'x', 'y', 'preserveaspectratio', 'version' ],
		'g'              => [],
		'defs'           => [],
		'symbol'         => [ 'viewbox', 'preserveaspectratio' ],
		'use'            => [ 'href', 'xlink:href', 'x', 'y', 'width', 'height' ],
		'title'          => [],
		'desc'           => [],
		'path'           => [ 'd', 'pathlength' ],
		'circle'         => [ 'cx', 'cy', 'r', 'pathlength' ],
		'ellipse'        => [ 'cx', 'cy', 'rx', 'ry' ],
		'rect'           => [ 'x', 'y', 'width', 'height', 'rx', 'ry' ],
		'line'           => [ 'x1', 'y1', 'x2', 'y2' ],
		'polyline'       => [ 'points' ],
		'polygon'        => [ 'points' ],
		'text'           => [ 'x', 'y', 'dx', 'dy', 'text-anchor', 'font-size', 'font-family', 'font-weight', 'dominant-baseline' ],
		'tspan'          => [ 'x', 'y', 'dx', 'dy' ],
		'lineargradient' => [ 'x1', 'y1', 'x2', 'y2', 'gradientunits', 'gradienttransform' ],
		'radialgradient' => [ 'cx', 'cy', 'r', 'fx', 'fy', 'gradientunits', 'gradienttransform' ],
		'stop'           => [ 'offset', 'stop-color', 'stop-opacity' ],
		'clippath'       => [ 'clippathunits' ],
		'mask'           => [ 'x', 'y', 'width', 'height', 'maskunits' ],
		'pattern'        => [ 'x', 'y', 'width', 'height', 'patternunits', 'patterntransform' ],
	];

	/** Interface attributes WordPress does not treat as global. */
	private const UI_ATTRS = [
		'tabindex', 'draggable', 'hidden', 'inert', 'spellcheck', 'aria-pressed', 'aria-selected',
		'aria-haspopup', 'aria-orientation', 'aria-modal', 'aria-busy', 'aria-checked',
		'aria-disabled', 'aria-valuenow', 'aria-valuemin', 'aria-valuemax', 'aria-owns',
		'aria-posinset', 'aria-setsize', 'aria-level', 'aria-roledescription', 'aria-atomic',
		'aria-relevant', 'aria-keyshortcuts', 'aria-invalid', 'aria-required',
	];

	/** @var array<string, array<string, bool>>|null */
	private static ?array $svg = null;

	/** @var array<string, array<string, bool>>|null */
	private static ?array $ui = null;

	/**
	 * Allowed SVG markup.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function svg_tags(): array {
		if ( null === self::$svg ) {
			self::$svg = [];
			foreach ( self::SVG_ELEMENTS as $element => $own ) {
				self::$svg[ $element ] = array_fill_keys( array_merge( self::SVG_ATTRS, $own ), true );
				self::$svg[ $element ]['data-*'] = true;
			}
		}
		return self::$svg;
	}

	/**
	 * Allowed markup for our composed interface fragments: what a post may
	 * contain, plus SVG, form controls and interface attributes.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function ui_tags(): array {
		if ( null === self::$ui ) {
			$tags = wp_kses_allowed_html( 'post' );
			$form = [ 'type', 'name', 'value', 'checked', 'disabled', 'readonly', 'required', 'placeholder', 'min', 'max', 'step', 'size', 'maxlength', 'pattern', 'autocomplete', 'form', 'multiple', 'accept' ];
			$tags['input']    = array_fill_keys( $form, true );
			$tags['select']   = array_fill_keys( [ 'name', 'disabled', 'required', 'multiple', 'size', 'form' ], true );
			$tags['option']   = array_fill_keys( [ 'value', 'selected', 'disabled', 'label' ], true );
			$tags['optgroup'] = array_fill_keys( [ 'label', 'disabled' ], true );
			$tags['template'] = [];
			$tags['kbd']      = [];
			$tags['nav']      = $tags['nav'] ?? [];
			$tags             = array_map( '_wp_add_global_attributes', $tags );
			foreach ( $tags as $element => $attrs ) {
				$tags[ $element ] = array_merge( $attrs, array_fill_keys( self::UI_ATTRS, true ) );
			}
			$tags['button'] = array_merge( $tags['button'] ?? [], array_fill_keys( [ 'type', 'name', 'value', 'disabled', 'form' ], true ) );
			$tags['a']      = array_merge( $tags['a'] ?? [], array_fill_keys( [ 'href', 'target', 'rel', 'download' ], true ) );
			$tags['img']    = array_merge( $tags['img'] ?? [], array_fill_keys( [ 'src', 'srcset', 'sizes', 'alt', 'width', 'height', 'loading', 'decoding' ], true ) );
			self::$ui = array_merge( $tags, self::svg_tags() );
		}
		return self::$ui;
	}

	/** An inline SVG, filtered. */
	public static function svg( string $svg ): string {
		return wp_kses( $svg, self::svg_tags() );
	}

	/** A composed interface fragment, filtered. */
	public static function ui( string $html ): string {
		return wp_kses( $html, self::ui_tags() );
	}
}
