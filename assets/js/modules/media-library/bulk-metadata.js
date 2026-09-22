/* global wp, doubleyoupiMediaFolders */
( function ( $ ) {
	'use strict';

	if ( ! window.doubleyoupiMediaFolders ) {
		return;
	}

	var $overlay = null;

	// i18n catalogue (localized via wp_localize_script → doubleyoupiMediaFolders.i18n).
	// Fallback to English if a key is missing so the UI never shows a blank.
	var I18N = ( window.doubleyoupiMediaFolders && window.doubleyoupiMediaFolders.i18n ) || {};
	function t( key, fallback ) {
		return I18N[ key ] || fallback;
	}
	// Minimal sprintf for a single %d substitution (matches PHP catalogue keys).
	function fmt( str, n ) {
		return String( str ).replace( '%d', n );
	}

	/*
	 * Strategy: hook into the Backbone selection Collection directly.
	 *
	 * We wait for the frame to expose a state with a 'selection' Collection,
	 * then listen to add/remove/reset events on it. This is more reliable than
	 * MutationObserver (which can't detect CSS visibility changes) and avoids
	 * any dependency on non-standard WP events.
	 *
	 * The fallback setInterval retries until the frame & state are available.
	 */

	var hookInterval = setInterval( function () {
		if ( ! window.wp || ! wp.media || ! wp.media.frame ) {
			return;
		}
		var state = null;
		try { state = wp.media.frame.state(); } catch ( e ) {}
		if ( ! state ) {
			return;
		}
		var selection = state.get( 'selection' );
		if ( ! selection ) {
			return;
		}

		// Success — hook the selection and stop polling.
		clearInterval( hookInterval );
		hookSelection( selection );

		// If the state changes (e.g. user navigates to Upload tab), re-hook.
		wp.media.frame.on( 'content:render', function () {
			try {
				var newSel = wp.media.frame.state().get( 'selection' );
				if ( newSel && newSel !== selection ) {
					selection = newSel;
					hookSelection( selection );
				}
			} catch ( e ) {}
		} );
	}, 400 );

	function hookSelection( selection ) {
		selection.on( 'add remove reset', function () {
			scheduleUpdate( selection );
		} );
		// Reflect initial state.
		updateBulkButton( selection.length );
	}

	/*
	 * Switching the selection from one media to another fires `remove` (→ 0)
	 * then `add` (→ 1) within the same tick. Reacting synchronously removed
	 * then re-injected the button on every click → visible flicker (the button
	 * showed up only ~1 time out of 2). We coalesce all selection events of a
	 * frame into a single update on the next animation frame, so the button
	 * only reflects the FINAL count and never flickers.
	 */
	var rafPending = false;
	function scheduleUpdate( selection ) {
		if ( rafPending ) {
			return;
		}
		rafPending = true;
		window.requestAnimationFrame( function () {
			rafPending = false;
			updateBulkButton( selection.length );
		} );
	}

	function updateBulkButton( count ) {
		if ( count > 0 ) {
			if ( ! $( '#dy-bulk-meta-btn' ).length ) {
				injectBulkButton( count );
			} else {
				$( '#dy-bulk-meta-btn' ).text( fmt( t( 'editMetadataCount', 'Edit metadata (%d)' ), count ) );
			}
		} else {
			$( '#dy-bulk-meta-btn' ).remove();
		}
	}

	function injectBulkButton( count ) {
		/*
		 * Library page only, and one toolbar only.
		 *
		 * `.media-toolbar-secondary` matches every media toolbar in the
		 * document, and a screen accumulates them: one per picker modal opened,
		 * plus the page's own. jQuery then prepends a COPY into each, which is
		 * how three "Edit metadata" buttons ended up scattered across a picker.
		 *
		 * Beyond the duplication, this button does not belong in a picker at
		 * all: there, a selection means "about to be inserted", not "about to
		 * be bulk-edited". Two meanings for one selection is a trap, and the
		 * modal already has its own action in the footer.
		 */
		if ( ! document.body.classList.contains( 'upload-php' ) ) {
			return;
		}
		var $toolbar = $( '.media-toolbar-secondary' ).first();
		if ( ! $toolbar.length ) {
			return;
		}
		var $btn = $( '<button type="button" class="button" id="dy-bulk-meta-btn"></button>' )
			.text( fmt( t( 'editMetadataCount', 'Edit metadata (%d)' ), count ) );

		// Sit to the RIGHT of the Folder / Collection bulk bar (folder-tree.js)
		// when it is present, otherwise leftmost in the secondary toolbar.
		var $bar = $( '#dy-bulk-location-bar' );
		if ( $bar.length ) {
			$btn.insertAfter( $bar );
		} else {
			$toolbar.prepend( $btn );
		}
	}

	// Use event delegation so the button works even after DOM refreshes.
	$( document ).on( 'click', '#dy-bulk-meta-btn', openModal );

	// ── Modal ─────────────────────────────────────────────────────────────────

	function openModal() {
		var ids = [];
		try {
			ids = wp.media.frame.state().get( 'selection' ).pluck( 'id' );
		} catch ( e ) {}
		if ( ! ids.length ) {
			return;
		}

		closeModal();

		var many       = ids.length > 1;
		var selectedTxt = many
			? fmt( t( 'filesSelectedMany', '%d files selected' ), ids.length )
			: t( 'filesSelectedOne', '1 file selected' );
		var applyTxt   = many
			? fmt( t( 'applyToMany', 'Apply to %d files' ), ids.length )
			: t( 'applyToOne', 'Apply to 1 file' );

		$overlay = $( '<div class="dy-bulk-overlay"></div>' );
		var $dialog = $( '<div class="dy-bulk-dialog"></div>' ).appendTo( $overlay );

		var $header = $( '<div class="dy-bulk-dialog__header"></div>' ).appendTo( $dialog );
		$( '<p class="dy-bulk-dialog__eyebrow"></p>' ).text( t( 'bulkEditEyebrow', 'Bulk edit' ) ).appendTo( $header );
		$( '<h3></h3>' ).text( selectedTxt ).appendTo( $header );

		$( '<p class="dy-bulk-dialog__hint"></p>' )
			.text( t( 'bulkKeepHint', 'Leave a field empty to keep each file’s existing value unchanged.' ) )
			.appendTo( $dialog );

		var $altField = $( '<div class="dy-bulk-dialog__field"></div>' ).appendTo( $dialog );
		$( '<label for="dy-bulk-alt"></label>' ).text( t( 'altLabel', 'Alt text' ) ).appendTo( $altField );
		$( '<input type="text" id="dy-bulk-alt" autocomplete="off">' )
			.attr( 'placeholder', t( 'altDescribe', 'Describe the image…' ) ).appendTo( $altField );

		var $titleField = $( '<div class="dy-bulk-dialog__field"></div>' ).appendTo( $dialog );
		$( '<label for="dy-bulk-title"></label>' ).text( t( 'title', 'Title' ) ).appendTo( $titleField );
		$( '<input type="text" id="dy-bulk-title" autocomplete="off">' )
			.attr( 'placeholder', t( 'titlePlaceholder', 'File title…' ) ).appendTo( $titleField );

		var $footer = $( '<div class="dy-bulk-dialog__footer"></div>' ).appendTo( $dialog );
		$( '<button type="button" class="dy-bulk-btn dy-bulk-btn-cancel" id="dy-bulk-cancel"></button>' )
			.text( t( 'cancel', 'Cancel' ) ).appendTo( $footer );
		$( '<button type="button" class="dy-bulk-btn dy-bulk-btn-apply" id="dy-bulk-apply"></button>' )
			.text( applyTxt ).appendTo( $footer );

		$overlay.appendTo( 'body' );

		// Close on overlay click.
		$overlay.on( 'click', function ( e ) {
			if ( $( e.target ).is( '.dy-bulk-overlay' ) ) {
				closeModal();
			}
		} );

		$( '#dy-bulk-cancel' ).on( 'click', closeModal );

		$( '#dy-bulk-apply' ).on( 'click', function () {
			var alt   = $( '#dy-bulk-alt' ).val().trim();
			var title = $( '#dy-bulk-title' ).val().trim();

			if ( ! alt && ! title ) {
				closeModal();
				return;
			}

			var $btn = $( '#dy-bulk-apply' );
			$btn.prop( 'disabled', true ).text( t( 'applying', 'Applying…' ) );

			window.fetch( doubleyoupiMediaFolders.restBulk, {
				method:  'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   doubleyoupiMediaFolders.nonce,
				},
				body: JSON.stringify( { ids: ids, alt: alt, title: title } ),
			} )
			.then( function ( r ) {
				if ( ! r.ok ) { throw new Error( r.status ); }
				return r.json();
			} )
			.then( function () {
				// Update the in-memory Backbone models so the sidebar refreshes.
				try {
					wp.media.frame.state().get( 'selection' ).each( function ( model ) {
						if ( alt )   { model.set( 'alt',   alt ); }
						if ( title ) { model.set( 'title', title ); }
					} );
				} catch ( e ) {}
				closeModal();
			} )
			.catch( function () {
				$btn.prop( 'disabled', false ).text( t( 'retry', 'Retry' ) );
			} );
		} );

		// Focus first field.
		setTimeout( function () {
			$( '#dy-bulk-alt' ).trigger( 'focus' );
		}, 50 );
	}

	function closeModal() {
		if ( $overlay ) {
			$overlay.remove();
			$overlay = null;
		}
	}

	// Close on Escape.
	$( document ).on( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && $overlay ) {
			closeModal();
		}
	} );

} )( jQuery );
