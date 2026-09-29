/* global wp, doubleyoupiMediaFolders */
( function ( $ ) {
	'use strict';

	if ( ! window.doubleyoupiMediaFolders ) {
		return;
	}

	var i18n = doubleyoupiMediaFolders.i18n;

	/*
	 * Strategy: extend wp.media.view.Attachment.Details.render().
	 * This view is rendered every time the user clicks an attachment in the
	 * sidebar. It's the most reliable hook — no custom events needed.
	 *
	 * wp.media.view.Attachment.Details is available after media-views is loaded
	 * (we depend on it). We extend its render() before any frame opens.
	 */
	$( function () {
		if ( ! window.wp || ! wp.media || ! wp.media.view ) {
			return;
		}

		// Bail if the Details view doesn't exist (unlikely given our dependency).
		if ( ! wp.media.view.Attachment || ! wp.media.view.Attachment.Details ) {
			return;
		}

		var OrigDetails = wp.media.view.Attachment.Details;

		wp.media.view.Attachment.Details = OrigDetails.extend( {
			render: function () {
				// Run WP's original render first.
				OrigDetails.prototype.render.apply( this, arguments );

				// Inject our panel after WP's content is in place.
				var model = this.model;
				var el    = this.el;
				injectWhereUsed( model, el );

				return this;
			},
		} );

		// Also extend TwoColumn if it exists (used on upload.php Manage frame).
		if ( wp.media.view.Attachment.Details.TwoColumn ) {
			var OrigTwo = wp.media.view.Attachment.Details.TwoColumn;
			wp.media.view.Attachment.Details.TwoColumn = OrigTwo.extend( {
				render: function () {
					OrigTwo.prototype.render.apply( this, arguments );
					injectWhereUsed( this.model, this.el );
					return this;
				},
			} );
		}
	} );

	// ── Fetch & inject ────────────────────────────────────────────────────────

	function injectWhereUsed( model, detailsEl ) {
		// Remove any stale panel (from the previous selection).
		$( detailsEl ).find( '.dy-where-used' ).remove();

		var $panel = $(
			'<div class="dy-where-used">' +
				'<p class="dy-where-used__title">' + escHtml( i18n.whereUsed ) + '</p>' +
				'<p class="dy-where-used__loading">Loading…</p>' +
			'</div>'
		);
		$( detailsEl ).append( $panel );

		var id = model.get( 'id' );
		if ( ! id ) {
			$panel.find( '.dy-where-used__loading' ).replaceWith(
				'<p class="dy-where-used__empty">—</p>'
			);
			return;
		}

		window.fetch( doubleyoupiMediaFolders.whereUsed + '/' + id, {
			headers: { 'X-WP-Nonce': doubleyoupiMediaFolders.nonce },
		} )
		.then( function ( r ) {
			if ( ! r.ok ) { throw new Error( r.status ); }
			return r.json();
		} )
		.then( function ( usages ) {
			var $target = $panel.find( '.dy-where-used__loading' );
			if ( ! Array.isArray( usages ) || usages.length === 0 ) {
				$target.replaceWith(
					'<p class="dy-where-used__empty">' + escHtml( i18n.notUsed ) + '</p>'
				);
				return;
			}

			var items = usages.map( function ( u ) {
				var icon  = postTypeIcon( u.post_type );
				var title = u.title || '(no title)';
				var href  = u.edit_url || u.url || '#';
				// On a network, a use can live on another site. Saying which one
				// is the whole point: an unlabelled row would read as a page of
				// this site that cannot be found.
				var site  = u.site_name
					? '<span class="dy-where-used__site">' + escHtml( u.site_name ) + '</span>'
					: '';
				return (
					'<li><a href="' + escHtml( href ) + '" target="_blank">' +
						'<span class="dashicons ' + icon + '"></span>' +
						escHtml( title ) + site +
					'</a></li>'
				);
			} );

			$target.replaceWith(
				'<ul class="dy-where-used__list">' + items.join( '' ) + '</ul>'
			);
		} )
		.catch( function () {
			$panel.find( '.dy-where-used__loading' ).replaceWith(
				'<p class="dy-where-used__empty">—</p>'
			);
		} );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	function postTypeIcon( type ) {
		var map = {
			post:    'dashicons-admin-post',
			page:    'dashicons-admin-page',
			product: 'dashicons-cart',
		};
		return map[ type ] || 'dashicons-admin-generic';
	}

	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

} )( jQuery );
