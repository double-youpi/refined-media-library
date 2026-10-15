/* global wp, doubleyoupiDuplicateBadge, jQuery */
( function ( $ ) {
	'use strict';

	if ( ! window.wp || ! wp.media || ! wp.media.view || ! wp.media.view.Attachment ) {
		return;
	}

	// Translated server-side (DuplicateDetector::enqueue_assets). The English
	// word stays only as a fallback if the localized object is missing.
	var label = ( window.doubleyoupiDuplicateBadge && doubleyoupiDuplicateBadge.label ) || 'Duplicate';

	/*
	 * Patch the prototype, never replace the class.
	 *
	 * The grid draws its tiles with wp.media.view.Attachment.Library, and every
	 * media window with its siblings. Those subclasses are derived from
	 * Attachment when media-views loads, so assigning a new class to
	 * wp.media.view.Attachment afterwards reaches none of them: the badge was
	 * never drawn. Replacing the class also dropped its static members
	 * (Attachment.Library, Attachment.Details...). A prototype method is
	 * inherited by every subclass that does not redefine render, which none of
	 * them do.
	 *
	 * Done at load, not on DOM ready: media-views is a dependency, so the class
	 * exists now, and the first tiles must already carry the badge.
	 */
	var proto = wp.media.view.Attachment.prototype;
	var originalRender = proto.render;

	proto.render = function () {
		var result = originalRender.apply( this, arguments );

		if ( this.model && this.model.get( 'doubleyoupi_duplicate_of' ) ) {
			this.$el.addClass( 'dy-is-duplicate' );
			if ( ! this.$( '.dy-duplicate-badge' ).length ) {
				// Text node, never HTML: a translation is data, not markup.
				this.$( '.thumbnail' ).append(
					$( '<span class="dy-duplicate-badge"></span>' ).text( label )
				);
			}
		}

		return result;
	};
}( jQuery ) );
