/* global wp */
jQuery( function () {
	'use strict';

	if ( ! window.wp || ! wp.media || ! wp.media.view ) {
		return;
	}

	var Original = wp.media.view.Attachment;

	wp.media.view.Attachment = Original.extend( {
		render: function () {
			Original.prototype.render.apply( this, arguments );

			var duplicateOf = this.model.get( 'doubleyoupi_duplicate_of' );
			if ( duplicateOf ) {
				this.$el.addClass( 'dy-is-duplicate' );
				if ( ! this.$( '.dy-duplicate-badge' ).length ) {
					this.$( '.thumbnail' ).append(
						'<span class="dy-duplicate-badge">Duplicate</span>'
					);
				}
			}

			return this;
		},
	} );
} );
