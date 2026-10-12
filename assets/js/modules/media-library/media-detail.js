/* global wp, doubleyoupiMediaFolders, wpApiSettings */
( function ( $, window ) {
	'use strict';

	if ( ! window.doubleyoupiMediaFolders ) {
		return;
	}

	/*
	 * Finder-style detail panel for the media library.
	 *
	 * When a user clicks an attachment on upload.php (mode-grid):
	 *   - WP's default fullscreen edit modal is suppressed
	 *   - A slide-in panel opens on the right (CSS Grid column 3)
	 *   - The panel shows metadata, editable fields, where-used, actions
	 *
	 * Layout: column 1 = folder tree | column 2 = grid | column 3 = detail
	 */

	var nonce   = doubleyoupiMediaFolders.nonce;
	// Built by WordPress (see FolderManager), because the shape of a REST URL
	// depends on the permalink structure and cannot be guessed from the site
	// address: with "Plain" permalinks it is `/index.php?rest_route=/wp/v2/media/`,
	// not `/wp-json/wp/v2/media/`. The two fallbacks only cover a stale cached
	// script, and are ordered from most to least reliable.
	var restWP  = doubleyoupiMediaFolders.restMedia
		|| ( ( window.wpApiSettings && wpApiSettings.root ) ? wpApiSettings.root + 'wp/v2/media/' : null )
		|| ( window.location.origin + '/wp-json/wp/v2/media/' );

	/*
	 * Appends a query string to a REST URL. On a site with "Plain" permalinks
	 * that URL already carries one (`?rest_route=…`), so a second `?` makes
	 * WordPress answer 404 rather than read the parameter. Reported from a
	 * clean install with no other plugin.
	 */
	function restQuery( url, query ) {
		return url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + query;
	}
	var restWhere = doubleyoupiMediaFolders.whereUsed;
	var i18n     = doubleyoupiMediaFolders.i18n || {};

	// If Folders / Collections are disabled, we hide their section from the detail
	// panel (otherwise we would offer to classify a media item while the feature
	// is off). Strings '1'/'0' (wp_localize casts booleans to strings).
	var foldersEnabled     = doubleyoupiMediaFolders.foldersEnabled !== '0';
	var collectionsEnabled = doubleyoupiMediaFolders.collectionsEnabled !== '0';

	// lastField: id of the editable field the user worked in last (alt, title,
	// caption, description). carryField: the one to focus again on the next
	// item when navigating with prev/next, so a captioning run stays hands-on.
	// initial: the four field values as they arrived from the server. Dirty is a
	// comparison against them, not a "something was typed" flag, so undoing an
	// edit by hand quietly puts the Save button back to sleep.
	var state = { open: false, id: null, dirty: false, lastField: null, carryField: null, initial: null };

	var EDITABLE_FIELDS = '#dy-dp-title, #dy-dp-alt, #dy-dp-caption, #dy-dp-description';
	var FIELD_KEYS      = {
		'dy-dp-title':       'title',
		'dy-dp-alt':         'alt_text',
		'dy-dp-caption':     'caption',
		'dy-dp-description': 'description'
	};

	// ── Hook the Manage frame ──────────────────────────────────────────────

	$( function () {
		// Try immediately (WP's media.js runs its ready handler before ours)
		hookFrameIfReady();
		// Fallback: listen for the event (in case of race conditions)
		$( '#wp-media-grid, .wrap' ).on( 'wp-media-grid-ready', function ( e, frame ) {
			if ( frame ) { hookFrame( frame ); }
		} );

		// Entering (or leaving) WP's "Bulk select" mode should start from a clean
		// slate: close the detail panel and clear any prior single selection so no
		// stale accent border (.selected / .details / .dy-detail-active) lingers on
		// a media item the user is no longer actually selecting.
		$( document ).on( 'click', '.select-mode-toggle-button', function () {
			if ( state.open ) { closePanel(); }
			// Defer so this runs AFTER WP has toggled the mode, then reset.
			setTimeout( function () {
				try {
					var sel = wp.media.frame.state().get( 'selection' );
					if ( sel ) { sel.reset(); }
				} catch ( e ) {}
				$( '.attachment.details, .attachment.selected' ).removeClass( 'details selected' );
			}, 0 );
		} );
	} );

	function hookFrameIfReady() {
		var frame = window.wp && wp.media && wp.media.frames ? wp.media.frames.browse : null;
		if ( frame ) {
			hookFrame( frame );
		} else {
			setTimeout( hookFrameIfReady, 200 );
		}
	}

	function hookFrame( frame ) {
		if ( frame._dyDetailHooked ) { return; }
		frame._dyDetailHooked = true;

		// Replace WP's default "open fullscreen edit modal" behaviour.
		frame.off( 'edit:attachment' );
		frame.on( 'edit:attachment', function ( model ) {
			var id = model ? String( model.get( 'id' ) ) : null;
			if ( ! id ) { return; }
			// Toggle: second click on same attachment closes the panel.
			if ( state.open && state.id === id ) {
				closePanel();
				return;
			}
			// Leaving an item with pending edits saves them, exactly like using
			// the prev/next arrows. One rule for every way out of a media file.
			if ( state.open && state.dirty && id !== String( state.id ) ) {
				saveBeforeLeaving();
			}
			openPanel( id, model );
		} );
	}

	// ── Grid column count locking ──────────────────────────────────────────
	// Prevents the saccade caused by auto-fill recalculating column count
	// when .wrap padding-right shrinks the container. By computing the current
	// column count and locking it via --dy-cols, items resize smoothly instead
	// of snapping to a new layout.

	var unlockColsTimer = null;
	var GRID_UNLOCK_MS  = 300; // matches margin-right transition on .wrap children

	function lockGridColumns() {
		if ( unlockColsTimer ) {
			clearTimeout( unlockColsTimer );
			unlockColsTimer = null;
		}
		try {
			var att = document.querySelector( '.attachments' );
			if ( ! att ) { return; }
			var template = window.getComputedStyle( att ).gridTemplateColumns;
			if ( template && template !== 'none' ) {
				var cols = template.split( ' ' ).filter( function ( c ) { return c.trim(); } ).length;
				if ( cols > 0 ) {
					document.body.style.setProperty( '--dy-cols', cols );
				}
			}
		} catch ( e ) {}
	}

	function unlockGridColumns() {
		try {
			document.body.style.removeProperty( '--dy-cols' );
		} catch ( e ) {}
	}

	// ── Open / close ───────────────────────────────────────────────────────

	function openPanel( id, model ) {
		state.open = true;
		state.id   = String( id ); // always store as string for consistent comparison
		state.dirty = false;

		// Highlight the active attachment (WP uses data-id, not id="post-N")
		$( '.attachment.dy-detail-active' ).removeClass( 'dy-detail-active' );
		$( '.attachment[data-id="' + id + '"]' ).addClass( 'dy-detail-active' );

		// Create the panel if it doesn't exist yet.
		if ( ! $( '#dy-detail-panel' ).length ) {
			createPanel();
		}

		// Lock the grid column count BEFORE opening — prevents auto-fill from
		// dropping a column when the .wrap padding-right shrinks the container.
		// Items will smoothly resize within the same N columns instead of snapping.
		lockGridColumns();

		// Open: add class to .attachments-browser AND body (for :has() to work + width transition).
		$( '.attachments-browser.dy-has-folder-panel' ).addClass( 'dy-detail-open' );

		// Apply saved detail width via CSS custom property on body
		var savedDW = localStorage.getItem( 'dy-dw' );
		if ( savedDW ) {
			document.body.style.setProperty( '--dy-dw', savedDW + 'px' );
		}

		// Show skeleton while loading.
		$( '.dy-dp-filename' ).text( i18n.loading || 'Loading…' );
		$( '.dy-dp-body' ).html( '<div class="dy-dp-loading">' + esc( i18n.loading || 'Loading…' ) + '</div>' );

		// If the Backbone model has basic info, render immediately.
		if ( model ) {
			renderPartial( model );
		}

		// Fetch full data from WP REST API.
		fetchAndRender( id );
	}

	function closePanel() {
		// Closing is one more way of leaving a media file: same rule.
		saveBeforeLeaving();

		state.open       = false;
		state.id         = null;
		state.dirty      = false;
		state.lastField  = null;
		state.carryField = null;
		state.initial    = null;

		$( '.attachments-browser' ).removeClass( 'dy-detail-open' );
		$( '.attachment.dy-detail-active' ).removeClass( 'dy-detail-active' );
		// --dy-dw stays set for next open; removing dy-detail-open collapses column 3 to 0px

		// Restore auto-fill after the close transition so the grid stays stable
		// while margin-right animates back, then becomes responsive again.
		if ( unlockColsTimer ) {
			clearTimeout( unlockColsTimer );
		}
		unlockColsTimer = setTimeout( function () {
			unlockGridColumns();
			unlockColsTimer = null;
		}, GRID_UNLOCK_MS );
	}

	// ── Panel DOM ──────────────────────────────────────────────────────────

	function createPanel() {
		var $panel = $(
			'<div id="dy-detail-panel">' +
				'<div class="dy-dp-inner">' +
					'<div class="dy-rh-left" id="dy-rh-detail" title="' + esc( i18n.dragToResize ) + '"></div>' +
					'<div class="dy-dp-header">' +
						'<button type="button" class="dy-dp-nav dy-dp-prev" title="' + esc( i18n.previous ) + '">&#8249;</button>' +
						'<button type="button" class="dy-dp-nav dy-dp-next" title="' + esc( i18n.next ) + '">&#8250;</button>' +
						'<span class="dy-dp-filename"></span>' +
						'<button type="button" class="dy-dp-close" title="' + esc( i18n.close ) + '">&#10005;</button>' +
					'</div>' +
					'<div class="dy-dp-body"></div>' +
				'</div>' +
			'</div>'
		);

		$( '.attachments-browser' ).append( $panel );

		// Detail panel resize via dedicated DOM handle (left edge)
		$( '#dy-rh-detail' ).on( 'mousedown', function ( e ) {
			e.preventDefault();
			e.stopPropagation();

			var startX  = e.clientX;
			var $inner  = $( '.dy-dp-inner' );
			var startDW = parseInt( $inner.outerWidth(), 10 );

			$( document ).on( 'mousemove.dydw', function ( e2 ) {
				var delta = startX - e2.clientX; // drag left = wider
				var newW  = Math.max( 260, Math.min( 720, startDW + delta ) );
				document.body.style.setProperty( '--dy-dw', newW + 'px' );
			} );

			$( document ).on( 'mouseup.dydw', function () {
				$( document ).off( '.dydw' );
				var w = parseInt( document.body.style.getPropertyValue( '--dy-dw' ), 10 ) || 340;
				localStorage.setItem( 'dy-dw', String( w ) );
			} );
		} );
	}

	// ── Event delegation — robust to any DOM rebuild ──────────────────────
	// Bound at module load time. Works whether or not the panel exists yet.
	$( document ).on( 'click', '.dy-dp-close', function ( e ) {
		e.preventDefault();
		e.stopPropagation();
		closePanel();
	} );

	$( document ).on( 'click', '.dy-dp-prev', function ( e ) {
		e.preventDefault();
		e.stopPropagation();
		navigate( -1 );
	} );

	$( document ).on( 'click', '.dy-dp-next', function ( e ) {
		e.preventDefault();
		e.stopPropagation();
		navigate( 1 );
	} );

	// ── Preview height resize (drag the handle under the image) ────────────
	// Stores the chosen aspect-ratio globally so the viewport stays consistent
	// across images and reloads (object-fit:contain keeps each one correct).
	$( document ).on( 'mousedown', '.dy-dp-resize', function ( e ) {
		e.preventDefault();
		var $preview = $( this ).prevAll( '.dy-dp-preview' ).first();
		if ( ! $preview.length ) { return; }
		var startY = e.clientY;
		var startH = $preview.outerHeight();
		var pw     = $preview.outerWidth();
		$( 'body' ).addClass( 'dy-dp-resizing' );
		$( document ).on( 'mousemove.dyph', function ( e2 ) {
			var newH = Math.max( 120, Math.min( window.innerHeight * 0.7, startH + ( e2.clientY - startY ) ) );
			$preview[ 0 ].style.aspectRatio = String( clampPar( pw / newH ) );
		} );
		$( document ).on( 'mouseup.dyph', function () {
			$( document ).off( '.dyph' );
			$( 'body' ).removeClass( 'dy-dp-resizing' );
			var par = parseFloat( $preview[ 0 ].style.aspectRatio );
			if ( par > 0 ) { localStorage.setItem( 'dy-par', String( par ) ); }
		} );
	} );

	// ── Lightbox gallery (click the image preview to open) ─────────────────
	$( document ).on( 'click', '.dy-dp-preview[data-full]', function ( e ) {
		e.preventDefault();
		openLightbox( String( $( this ).attr( 'data-id' ) ) );
	} );

	var lb = { el: null, list: [], idx: 0 };

	function lbAttachments() {
		var $a = $( '.attachments li.attachment' );
		if ( ! $a.length ) { $a = $( '.attachments .attachment' ); }
		return $a;
	}

	function lbModel( id ) {
		try { return wp.media.frame.content.get().collection.get( parseInt( id, 10 ) ); }
		catch ( e ) { return null; }
	}

	function lbBestUrl( model ) {
		var sizes = model.get( 'sizes' );
		if ( sizes ) {
			if ( sizes.large && sizes.large.url ) { return sizes.large.url; }
			if ( sizes.full && sizes.full.url )   { return sizes.full.url; }
		}
		return model.get( 'url' ) || null;
	}

	// Build the gallery in the same order as the grid (= the order arrow-key
	// navigation uses), restricted to images.
	function buildImageList() {
		var list = [];
		lbAttachments().each( function () {
			var id = String( $( this ).attr( 'data-id' ) || '' );
			if ( ! id || id === '0' ) { return; }
			var model = lbModel( id );
			if ( ! model || ! /^image\//.test( model.get( 'mime' ) || '' ) ) { return; }
			var u = lbBestUrl( model );
			if ( u ) { list.push( { id: id, url: u, title: model.get( 'title' ) || model.get( 'filename' ) || '' } ); }
		} );
		return list;
	}

	function openLightbox( startId ) {
		var list = buildImageList();
		if ( ! list.length ) { return; }
		var idx = 0;
		for ( var i = 0; i < list.length; i++ ) {
			if ( list[ i ].id === String( startId ) ) { idx = i; break; }
		}
		lb.list = list;
		lb.idx  = idx;
		if ( ! lb.el ) { createLightbox(); }
		document.body.classList.add( 'dy-lb-open' );
		renderLightbox();
		requestAnimationFrame( function () { lb.el.classList.add( 'is-open' ); } );
	}

	function createLightbox() {
		var svgPrev  = '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7"></path></svg>';
		var svgNext  = '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 5l7 7-7 7"></path></svg>';
		var svgClose = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"></path></svg>';

		var el = document.createElement( 'div' );
		el.className = 'dy-lightbox';
		el.innerHTML =
			'<div class="dy-lb-backdrop"></div>' +
			'<button type="button" class="dy-lb-close" title="' + esc( i18n.close ) + '" aria-label="' + esc( i18n.close ) + '">' + svgClose + '</button>' +
			'<button type="button" class="dy-lb-nav dy-lb-prev" title="' + esc( i18n.previous ) + '" aria-label="' + esc( i18n.previous ) + '">' + svgPrev + '</button>' +
			'<button type="button" class="dy-lb-nav dy-lb-next" title="' + esc( i18n.next ) + '" aria-label="' + esc( i18n.next ) + '">' + svgNext + '</button>' +
			'<figure class="dy-lb-stage"><img class="dy-lb-img" alt=""><figcaption class="dy-lb-caption"></figcaption></figure>' +
			'<div class="dy-lb-counter"></div>';
		document.body.appendChild( el );
		lb.el = el;
		el.querySelector( '.dy-lb-backdrop' ).addEventListener( 'click', closeLightbox );
		el.querySelector( '.dy-lb-close' ).addEventListener( 'click', closeLightbox );
		el.querySelector( '.dy-lb-prev' ).addEventListener( 'click', function () { stepLightbox( -1 ); } );
		el.querySelector( '.dy-lb-next' ).addEventListener( 'click', function () { stepLightbox( 1 ); } );
	}

	function renderLightbox() {
		var item = lb.list[ lb.idx ];
		if ( ! item || ! lb.el ) { return; }
		var img = lb.el.querySelector( '.dy-lb-img' );
		// Quick crossfade with a blur mask while the next image decodes.
		img.classList.add( 'is-swapping' );
		var loader = new Image();
		loader.onload = loader.onerror = function () {
			img.src = item.url;
			requestAnimationFrame( function () { img.classList.remove( 'is-swapping' ); } );
		};
		loader.src = item.url;
		lb.el.querySelector( '.dy-lb-caption' ).textContent = item.title || '';
		lb.el.querySelector( '.dy-lb-counter' ).textContent = ( lb.idx + 1 ) + ' / ' + lb.list.length;
		lb.el.querySelector( '.dy-lb-prev' ).style.visibility = lb.idx > 0 ? 'visible' : 'hidden';
		lb.el.querySelector( '.dy-lb-next' ).style.visibility = lb.idx < lb.list.length - 1 ? 'visible' : 'hidden';
		[ lb.idx - 1, lb.idx + 1 ].forEach( function ( n ) {
			if ( lb.list[ n ] ) { var pre = new Image(); pre.src = lb.list[ n ].url; }
		} );
	}

	function stepLightbox( dir ) {
		var n = lb.idx + dir;
		if ( n < 0 || n >= lb.list.length ) { return; }
		lb.idx = n;
		renderLightbox();
	}

	function closeLightbox() {
		if ( ! lb.el ) { return; }
		lb.el.classList.remove( 'is-open' );
		document.body.classList.remove( 'dy-lb-open' );
		// Sync the panel to the last image viewed in the lightbox.
		var viewed = lb.list[ lb.idx ] ? lb.list[ lb.idx ].id : null;
		if ( viewed && state.open && String( viewed ) !== String( state.id ) ) {
			openPanel( viewed, lbModel( viewed ) );
		}
	}

	// ── Quick render from Backbone model (before REST reply) ───────────────

	function renderPartial( model ) {
		var title = model.get( 'title' ) || model.get( 'filename' ) || '';
		$( '.dy-dp-filename' ).text( title );

		var thumbUrl = model.get( 'thumb' ) ? model.get( 'thumb' ).src : null;
		var mime     = model.get( 'mime' ) || '';
		var isImage  = /^image\//.test( mime );

		if ( thumbUrl && isImage ) {
			$( '.dy-dp-body' ).html(
				'<div class="dy-dp-preview">' +
					'<img src="' + esc( thumbUrl ) + '" class="dy-dp-thumb-img" alt="">' +
				'</div>' +
				'<div class="dy-dp-loading">' + esc( i18n.loadingDetails || 'Loading details…' ) + '</div>'
			);
		}
	}

	// ── Full fetch + render ────────────────────────────────────────────────

	/*
	 * Fetches one attachment and renders the panel.
	 *
	 * `context=edit` needs `edit_post` on that file. An author browsing the
	 * library sees everyone's uploads but may only edit their own, so clicking
	 * someone else's file used to answer 403 and print "Loading error. Error:
	 * 403" — a status code where an explanation was due. We ask again without
	 * that context, which any logged-in user may read, and show the panel in
	 * read-only form: same information, no controls that would fail.
	 */
	function fetchAndRender( id ) {
		window.fetch( restQuery( restWP + id, 'context=edit' ), {
			headers: { 'X-WP-Nonce': nonce },
		} )
		.then( function ( r ) {
			if ( r.status === 403 ) { return fetchReadOnly( id ); }
			if ( ! r.ok ) { throw new Error( r.status ); }
			return r.json().then( function ( data ) {
				if ( String( state.id ) !== String( id ) ) { return; }
				renderFull( data );
			} );
		} )
		.catch( function ( err ) {
			if ( String( state.id ) === String( id ) ) {
				$( '.dy-dp-body' ).html(
					'<p style="padding:20px;color:#888">' + esc( i18n.loadError || 'Loading error.' ) +
					'<br><small>' + esc( String( err ) ) + '</small></p>'
				);
			}
		} );
	}

	function fetchReadOnly( id ) {
		return window.fetch( restWP + id, { headers: { 'X-WP-Nonce': nonce } } )
			.then( function ( r ) {
				if ( ! r.ok ) { throw new Error( r.status ); }
				return r.json();
			} )
			.then( function ( data ) {
				if ( String( state.id ) !== String( id ) ) { return; }
				renderFull( data, true );
			} );
	}

	// ── Full render ────────────────────────────────────────────────────────

	function renderFull( data, readOnly ) {
		var title    = ( data.title && data.title.raw ) || ( data.title && data.title.rendered ) || '';
		var altText  = data.alt_text || '';
		var caption  = ( data.caption && data.caption.raw ) || '';
		var descr    = ( data.description && data.description.raw ) || '';
		// The read-only fetch uses the `view` context, which returns rendered
		// HTML instead of the raw field. Strip the wrapper so the textarea shows
		// text rather than markup.
		if ( readOnly ) {
			caption = caption || stripHtml( ( data.caption && data.caption.rendered ) || '' );
			descr   = descr   || stripHtml( ( data.description && data.description.rendered ) || '' );
		}
		var mime     = data.mime_type || '';
		var url      = data.source_url || '';
		var isImage  = data.media_type === 'image';
		var details  = data.media_details || {};

		// Thumbnail URL: prefer medium, then thumbnail, then original.
		var thumbUrl = isImage
			? ( details.sizes && ( ( details.sizes.medium && details.sizes.medium.source_url ) ||
			    ( details.sizes.thumbnail && details.sizes.thumbnail.source_url ) || url ) )
			: null;

		var date = data.date
			? new Date( data.date ).toLocaleDateString( i18n.dateLocale || undefined, { day: 'numeric', month: 'long', year: 'numeric' } )
			: '';

		var filesize = details.filesize ? formatSize( details.filesize ) : null;
		var dims     = details.width && details.height ? details.width + ' × ' + details.height + ' px' : null;

		// Update filename in header.
		$( '.dy-dp-filename' ).text( title || data.slug || '' );

		// Preview. For images, aspect-ratio drives the height so it grows
		// proportionally with the panel width; a drag handle underneath resizes it,
		// and clicking opens a full-screen lightbox gallery.
		var previewHtml;
		if ( thumbUrl ) {
			previewHtml =
				'<div class="dy-dp-preview" style="aspect-ratio:' + previewAspect( details ) + '" data-full="' + esc( url ) + '" data-id="' + data.id + '">' +
					'<img src="' + esc( thumbUrl ) + '" class="dy-dp-thumb-img" alt="">' +
					'<button type="button" class="dy-dp-expand" title="' + esc( i18n.viewFullSize || 'View full size' ) + '" aria-label="' + esc( i18n.viewFullSize || 'View full size' ) + '">' +
						'<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M9 4H4v5M15 4h5v5M15 20h5v-5M9 20H4v-5"></path></svg>' +
					'</button>' +
				'</div>' +
				'<div class="dy-dp-resize" title="' + esc( i18n.dragToResize ) + '" role="separator" aria-orientation="horizontal"><span class="dy-dp-resize-grip"></span></div>';
		} else {
			previewHtml = '<div class="dy-dp-preview"><div class="dy-dp-thumb-icon"><span class="dashicons ' + mimeIcon( mime ) + '"></span></div></div>';
		}

		// Metadata table.
		var metaRows = '';
		if ( date )     { metaRows += '<tr><td>' + esc( i18n.date )       + '</td><td>' + esc( date ) + '</td></tr>'; }
		if ( mime )     { metaRows += '<tr><td>' + esc( i18n.mimeType )   + '</td><td>' + esc( mime ) + '</td></tr>'; }
		if ( dims )     { metaRows += '<tr><td>' + esc( i18n.dimensions ) + '</td><td>' + esc( dims ) + '</td></tr>'; }
		if ( filesize ) { metaRows += '<tr><td>' + esc( i18n.size )       + '</td><td>' + esc( filesize ) + '</td></tr>'; }

		// ── Folder & collections location section ─────────────────────────
		var folderIds      = data[ 'doubleyoupi-media-folders' ] || [];
		var collectionIds  = data[ 'doubleyoupi-media-collections' ] || [];
		var folderHtml      = foldersEnabled ? buildFolderSection( folderIds, data.id ) : '';
		var collectionsHtml = collectionsEnabled ? buildCollectionsSection( collectionIds, data.id ) : '';

		// Where a file lives: folder on the left, collections on the right once
		// the panel is dragged wide enough (a container query does the split,
		// since the width comes from the user's drag, not the viewport).
		// With only one of the two enabled there is nothing to pair, so the
		// wrapper is skipped entirely.
		var duoHtml = ( folderHtml && collectionsHtml )
			? '<div class="dy-dp-duo-ct"><div class="dy-dp-duo">' + folderHtml + collectionsHtml + '</div></div>'
			: folderHtml + collectionsHtml;

		// Build body.
		var body =
			previewHtml +

			'<div class="dy-dp-section">' +
				'<div class="dy-dp-section-title">' + esc( i18n.information ) + '</div>' +
				'<table class="dy-dp-meta">' + metaRows + '</table>' +
			'</div>' +

			duoHtml +

			// The four native text fields, grouped in one section: four separate
			// sections would push everything else below the fold. Order follows
			// how they are written: name it, describe it for accessibility,
			// then caption and description for the sites that need them.
			'<div class="dy-dp-section dy-dp-fields">' +
				'<label class="dy-dp-field-label" for="dy-dp-title">' + esc( i18n.title ) + '</label>' +
				'<input type="text" id="dy-dp-title" class="dy-dp-input" value="' + esc( title ) + '">' +

				'<label class="dy-dp-field-label" for="dy-dp-alt">' + esc( i18n.altText ) + '</label>' +
				'<textarea id="dy-dp-alt" class="dy-dp-input dy-dp-textarea" rows="2" ' +
					'placeholder="' + esc( i18n.altPlaceholder ) + '">' + esc( altText ) + '</textarea>' +

				'<label class="dy-dp-field-label" for="dy-dp-caption">' + esc( i18n.caption || 'Caption' ) + '</label>' +
				'<textarea id="dy-dp-caption" class="dy-dp-input dy-dp-textarea" rows="2">' + esc( caption ) + '</textarea>' +

				'<label class="dy-dp-field-label" for="dy-dp-description">' + esc( i18n.description || 'Description' ) + '</label>' +
				'<textarea id="dy-dp-description" class="dy-dp-input dy-dp-textarea" rows="3">' + esc( descr ) + '</textarea>' +
			'</div>' +

			'<div class="dy-dp-section">' +
				'<div class="dy-dp-section-title">' + esc( i18n.fileUrl ) + '</div>' +
				'<div class="dy-dp-url-row">' +
					'<input type="text" class="dy-dp-input dy-dp-url-input" value="' + esc( url ) + '" readonly tabindex="-1">' +
					'<button type="button" class="dy-dp-copy-btn" data-url="' + esc( url ) + '">' + esc( i18n.copy ) + '</button>' +
				'</div>' +
			'</div>' +

			'<div class="dy-dp-section" id="dy-dp-where-used-section">' +
				'<div class="dy-dp-section-title">' + esc( i18n.whereUsed ) + '</div>' +
				'<p class="dy-dp-loading" style="padding:0;margin:0;font-size:12px">' + esc( i18n.loadingShort || i18n.loading ) + '</p>' +
			'</div>' +

			( readOnly
				? '<div class="dy-dp-section dy-dp-readonly"><p>' + esc( i18n.readOnlyNotice || 'You can view this file but not edit it.' ) + '</p></div>'
				: '<div class="dy-dp-actions">' +
					'<button type="button" class="dy-dp-save button" data-id="' + data.id + '">' + esc( i18n.save ) + '</button>' +
					'<button type="button" class="dy-dp-delete-btn" data-id="' + data.id + '">' + esc( i18n.deleteAction ) + '</button>' +
				'</div>' );

		$( '.dy-dp-body' ).html( body );

		if ( readOnly ) {
			// Everything stays readable, nothing pretends to be actionable.
			$( '.dy-dp-body' ).find( EDITABLE_FIELDS ).prop( 'readonly', true );
			$( '.dy-dp-body' ).find( '.dy-dp-folder-trigger, .dy-dp-col-add-select, .dy-dp-col-remove' ).prop( 'disabled', true );
		}

		// Snapshot of what the server sent, so "modified" means actually different.
		state.initial = {
			alt_text:    altText,
			title:       title,
			caption:     caption,
			description: descr
		};

		// Bind events.
		bindPanelEvents( data.id );
		refreshDirty();

		// Coming from prev/next: put the cursor back in the field the user was
		// working in on the previous item.
		applyCarriedFocus();

		// Fetch where-used asynchronously.
		fetchWhereUsed( data.id );

		// Surface third-party compat fields (Polylang language selector, Yoast
		// sitemap toggle, etc.) injected via `attachment_fields_to_edit`.
		renderCompatFields( data.id );
	}

	// ── Third-party compat fields ────────────────────────────────────────────
	// Our panel is built from the `wp/v2/media` REST API, which does NOT expose
	// fields added by plugins via `attachment_fields_to_edit`
	// (Polylang language selector, Yoast/RankMath sitemap exclusion, ACF media fields, etc.).
	//
	// We therefore fetch the compat markup exactly as the native modal does, via
	// the `get-attachment` AJAX action (= wp_prepare_attachment_for_js → get_compat_media
	// _markup), inject it as-is, and relay each change to
	// `save-attachment-compat` (with the returned nonce) — native save, so
	// multilingual features and similar integrations remain fully functional in our UI.
	//
	// Note: we do NOT use wp.media.view.AttachmentCompat directly — its save()
	// method assumes a `this.controller` (media frame context) that our standalone
	// panel does not have, which would cause it to crash on the first edit.
	function renderCompatFields( id ) {
		if ( ! window.wp || ! wp.ajax || ! wp.ajax.post ) { return; }

		wp.ajax.post( 'get-attachment', { id: id } ).done( function ( attData ) {
			// The panel may have switched to a different attachment while the request was in flight.
			if ( String( state.id ) !== String( id ) ) { return; }

			var item = attData && attData.compat && attData.compat.item;
			if ( ! item || ! String( item ).trim() ) {
				return; // no third-party fields → avoid rendering an empty section.
			}

			var updateNonce = ( attData.nonces && attData.nonces.update ) || '';

			var $section = $(
				'<div class="dy-dp-section dy-dp-compat">' +
					'<div class="dy-dp-section-title">' + esc( i18n.moreFields || 'More fields' ) + '</div>' +
				'</div>'
			);
			// Important: do NOT use the `compat-item` class — WP core hides it
			// (`.sidebar-for-errors .compat-item { display:none }`) because our
			// panel lives inside `.attachments-browser`, which sometimes carries
			// `.sidebar-for-errors`. We use a custom class instead.
			var $form = $( '<form class="dy-dp-compat-form"></form>' ).html( item );
			$section.append( $form );

			// Insert immediately before the action bar (Save / Edit / Delete).
			var $actions = $( '.dy-dp-body .dy-dp-actions' );
			if ( $actions.length ) {
				$actions.before( $section );
			} else {
				$( '.dy-dp-body' ).append( $section );
			}

			// Prevent native form submission and forward changes to the compat save handler.
			$form.on( 'submit', function ( e ) { e.preventDefault(); } );
			$form.on( 'change', 'input, select, textarea', function () {
				var data = { id: id, nonce: updateNonce };
				$form.serializeArray().forEach( function ( pair ) {
					data[ pair.name ] = pair.value;
				} );
				wp.ajax.post( 'save-attachment-compat', data );
			} );
		} );
	}

	// ── Location helpers ───────────────────────────────────────────────────

	function getFolderName( id ) {
		var st = window.doubleyoupiMediaState;
		if ( ! st || ! st.folders ) { return '#' + id; }
		var found = st.folders.filter( function ( f ) { return f.id === id; } )[ 0 ];
		return found ? found.name : '#' + id;
	}

	function getCollectionName( id ) {
		var st = window.doubleyoupiMediaState;
		if ( ! st || ! st.collections ) { return '#' + id; }
		var found = st.collections.filter( function ( c ) { return c.id === id; } )[ 0 ];
		return found ? found.name : '#' + id;
	}

	function buildFolderSection( folderIds, attId ) {
		var currentId = folderIds.length ? folderIds[ 0 ] : 0;
		var label     = currentId ? getFolderName( currentId ) : ( i18n.noFolderOption || 'No folder' );

		// Custom trigger that opens a hierarchical folder popover (the native
		// <select> cannot render a real tree).
		return (
			'<div class="dy-dp-section" id="dy-dp-folder-section">' +
				'<div class="dy-dp-section-title">' + esc( i18n.folder ) + '</div>' +
				'<button type="button" class="dy-dp-folder-trigger" data-att-id="' + attId + '" data-current="' + currentId + '" aria-haspopup="true">' +
					'<span class="dashicons dashicons-category dy-dp-folder-trigger__icon"></span>' +
					'<span class="dy-dp-folder-trigger__label">' + esc( label ) + '</span>' +
					'<span class="dashicons dashicons-arrow-down-alt2 dy-dp-folder-trigger__caret"></span>' +
				'</button>' +
			'</div>'
		);
	}

	// ── Folder picker popover (hierarchical) ───────────────────────────────

	var folderPopEl      = null;
	var folderPopTrigger = null;

	function buildFolderTreeData( flat ) {
		var byId = {};
		var roots = [];
		flat.forEach( function ( f ) { byId[ f.id ] = { id: f.id, name: f.name, parent: f.parent, children: [] }; } );
		flat.forEach( function ( f ) {
			if ( f.parent && byId[ f.parent ] ) { byId[ f.parent ].children.push( byId[ f.id ] ); }
			else { roots.push( byId[ f.id ] ); }
		} );
		return roots;
	}

	function renderFolderPopItems( nodes, depth, currentId ) {
		var html = '';
		nodes.forEach( function ( n ) {
			var cur = n.id === currentId;
			html += '<button type="button" class="dy-folder-pop__item' + ( cur ? ' is-current' : '' ) +
				'" role="menuitemradio" aria-checked="' + cur + '" data-id="' + n.id + '" style="padding-left:' + ( 12 + depth * 15 ) + 'px">' +
				'<span class="dashicons dashicons-category dy-folder-pop__icon"></span>' +
				'<span class="dy-folder-pop__name">' + esc( n.name ) + '</span>' +
				( cur ? '<span class="dashicons dashicons-yes dy-folder-pop__check"></span>' : '' ) +
				'</button>';
			if ( n.children.length ) { html += renderFolderPopItems( n.children, depth + 1, currentId ); }
		} );
		return html;
	}

	function closeFolderPopover() {
		if ( ! folderPopEl ) { return; }
		if ( folderPopEl.parentNode ) { folderPopEl.parentNode.removeChild( folderPopEl ); }
		folderPopEl = null;
		if ( folderPopTrigger ) { folderPopTrigger.setAttribute( 'aria-expanded', 'false' ); }
		folderPopTrigger = null;
		document.removeEventListener( 'mousedown', onFolderPopOutside, true );
		document.removeEventListener( 'keydown', onFolderPopKey, true );
		window.removeEventListener( 'resize', closeFolderPopover, true );
	}

	function onFolderPopOutside( e ) {
		if ( folderPopEl && ! folderPopEl.contains( e.target ) && e.target !== folderPopTrigger && ! folderPopTrigger.contains( e.target ) ) {
			closeFolderPopover();
		}
	}

	function onFolderPopKey( e ) {
		if ( ! folderPopEl ) { return; }
		var items = Array.prototype.slice.call( folderPopEl.querySelectorAll( '.dy-folder-pop__item' ) );
		if ( ! items.length ) { return; }
		var idx = items.indexOf( document.activeElement );
		// stopPropagation matters: without it this Escape also reaches the panel's
		// own keydown handler, which closes the whole detail panel behind the
		// popover instead of just dismissing the folder list.
		if ( e.key === 'Escape' ) { e.preventDefault(); e.stopPropagation(); var t = folderPopTrigger; closeFolderPopover(); if ( t ) { t.focus(); } }
		else if ( e.key === 'ArrowDown' ) { e.preventDefault(); items[ ( idx + 1 ) % items.length ].focus(); }
		else if ( e.key === 'ArrowUp' ) { e.preventDefault(); items[ ( idx - 1 + items.length ) % items.length ].focus(); }
	}

	function assignFolderTo( attId, folderId, trigger ) {
		window.fetch( doubleyoupiMediaFolders.restBase + '/' + folderId + '/assign', {
			method:  'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
			body:    JSON.stringify( { attachment_ids: [ attId ] } ),
		} ).then( function () {
			if ( window.doubleyoupiReloadFolders ) { window.doubleyoupiReloadFolders(); }
		} );
		if ( trigger ) {
			trigger.setAttribute( 'data-current', folderId );
			var l = trigger.querySelector( '.dy-dp-folder-trigger__label' );
			if ( l ) { l.textContent = folderId ? getFolderName( folderId ) : ( i18n.noFolderOption || 'No folder' ); }
		}
	}

	function openFolderPopover( trigger, attId, currentId ) {
		closeFolderPopover();
		var st    = window.doubleyoupiMediaState;
		var roots = buildFolderTreeData( ( st && st.folders ) ? st.folders : [] );

		var pop = document.createElement( 'div' );
		pop.className = 'dy-folder-pop';
		pop.setAttribute( 'role', 'menu' );
		pop.innerHTML =
			'<button type="button" class="dy-folder-pop__item' + ( currentId === 0 ? ' is-current' : '' ) +
				'" role="menuitemradio" aria-checked="' + ( currentId === 0 ) + '" data-id="0" style="padding-left:12px">' +
				'<span class="dashicons dashicons-dismiss dy-folder-pop__icon"></span>' +
				'<span class="dy-folder-pop__name">' + esc( i18n.noFolderOption || 'No folder' ) + '</span>' +
				( currentId === 0 ? '<span class="dashicons dashicons-yes dy-folder-pop__check"></span>' : '' ) +
			'</button>' +
			renderFolderPopItems( roots, 0, currentId );

		document.body.appendChild( pop );

		var r  = trigger.getBoundingClientRect();
		var pw = Math.max( r.width, 220 );
		pop.style.width = pw + 'px';
		var left = Math.max( 8, Math.min( r.left, window.innerWidth - pw - 8 ) );
		var top  = r.bottom + 4;
		if ( top + pop.offsetHeight > window.innerHeight - 8 ) {
			top = Math.max( 8, r.top - pop.offsetHeight - 4 );
		}
		pop.style.left = left + 'px';
		pop.style.top  = top + 'px';
		pop.style.transformOrigin = 'top left';

		folderPopEl      = pop;
		folderPopTrigger = trigger;
		trigger.setAttribute( 'aria-expanded', 'true' );
		requestAnimationFrame( function () { pop.classList.add( 'is-open' ); } );

		var cur = pop.querySelector( '.dy-folder-pop__item.is-current' ) || pop.querySelector( '.dy-folder-pop__item' );
		if ( cur ) { cur.focus(); }

		pop.addEventListener( 'click', function ( e ) {
			var item = e.target.closest( '.dy-folder-pop__item' );
			if ( ! item ) { return; }
			assignFolderTo( attId, parseInt( item.getAttribute( 'data-id' ), 10 ) || 0, trigger );
			closeFolderPopover();
		} );

		document.addEventListener( 'mousedown', onFolderPopOutside, true );
		document.addEventListener( 'keydown', onFolderPopKey, true );
		window.addEventListener( 'resize', closeFolderPopover, true );
	}

	function buildCollectionsSection( collectionIds, attId ) {
		var st          = window.doubleyoupiMediaState;
		var collections = ( st && st.collections ) ? st.collections : [];

		var tags = collectionIds.map( function ( cid ) {
			return (
				'<span class="dy-dp-col-tag" data-col-id="' + cid + '" data-att-id="' + attId + '">' +
					esc( getCollectionName( cid ) ) +
					'<button type="button" class="dy-dp-col-remove" title="' + esc( i18n.remove ) + '">×</button>' +
				'</span>'
			);
		} ).join( '' );

		// Add-to-collection select (shows collections not yet assigned)
		var available = collections.filter( function ( c ) { return collectionIds.indexOf( c.id ) === -1; } );
		var addSelect = '';
		if ( available.length ) {
			var opts = '<option value="">' + esc( i18n.addToCollection ) + '</option>';
			available.forEach( function ( c ) {
				opts += '<option value="' + c.id + '">' + esc( c.name ) + '</option>';
			} );
			addSelect = '<select class="dy-dp-col-add-select" data-att-id="' + attId + '">' + opts + '</select>';
		}

		return (
			'<div class="dy-dp-section" id="dy-dp-collections-section">' +
				'<div class="dy-dp-section-title">' + esc( i18n.collections ) + '</div>' +
				'<div class="dy-dp-col-tags">' + ( tags || '<span class="dy-dp-col-none">' + esc( i18n.noCollections ) + '</span>' ) + '</div>' +
				addSelect +
			'</div>'
		);
	}

	function bindPanelEvents( id ) {
		var $body = $( '.dy-dp-body' );

		// Save.
		$body.find( '.dy-dp-save' ).on( 'click', function () {
			saveAttachment( id, $( this ) );
		} );

		// Copy URL.
		$body.find( '.dy-dp-copy-btn' ).on( 'click', function () {
			var $btn = $( this );
			navigator.clipboard.writeText( $btn.data( 'url' ) ).then( function () {
				$btn.text( i18n.copied );
				setTimeout( function () { $btn.text( i18n.copy ); }, 2000 );
			} );
		} );

		// Delete.
		$body.find( '.dy-dp-delete-btn' ).on( 'click', function () {
			deleteAttachment( id );
		} );

		// Dirty tracking: every keystroke re-compares against the server values.
		$body.find( EDITABLE_FIELDS ).on( 'input', refreshDirty );

		// Remember the field the user works in, so prev/next can land there again.
		$body.find( EDITABLE_FIELDS ).on( 'focusin', function () {
			state.lastField = this.id;
		} );

		// Folder change.
		$body.find( '.dy-dp-folder-trigger' ).on( 'click', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			if ( folderPopEl ) { closeFolderPopover(); return; }
			openFolderPopover( this, parseInt( $( this ).data( 'att-id' ), 10 ), parseInt( $( this ).attr( 'data-current' ), 10 ) || 0 );
		} );

		// Remove from collection.
		$body.find( '.dy-dp-col-remove' ).on( 'click', function () {
			var $tag   = $( this ).closest( '.dy-dp-col-tag' );
			var colId  = parseInt( $tag.data( 'col-id' ), 10 );
			var attId  = parseInt( $tag.data( 'att-id' ), 10 );
			var restCols = doubleyoupiMediaFolders.restCollections;
			window.fetch( restCols + '/' + colId + '/remove', {
				method:  'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body:    JSON.stringify( { attachment_ids: [ attId ] } ),
			} ).then( function () {
				// Re-fetch instead of just removing the tag → lets the section
				// recompute itself (in particular, the add-to-collection select
				// reappears when all collections were already assigned).
				fetchAndRender( attId );
				if ( window.doubleyoupiReloadCollections ) { window.doubleyoupiReloadCollections(); }
			} );
		} );

		// Add to collection.
		$body.find( '.dy-dp-col-add-select' ).on( 'change', function () {
			var val   = $( this ).val();
			if ( ! val ) { return; }
			var colId = parseInt( val, 10 );
			var attId = parseInt( $( this ).data( 'att-id' ), 10 );
			var restCols = doubleyoupiMediaFolders.restCollections;
			window.fetch( restCols + '/' + colId + '/add', {
				method:  'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body:    JSON.stringify( { attachment_ids: [ attId ] } ),
			} ).then( function () {
				// Refresh the detail panel to reflect updated collections
				fetchAndRender( attId );
				if ( window.doubleyoupiReloadCollections ) { window.doubleyoupiReloadCollections(); }
			} );
		} );
	}

	// ── Where used ────────────────────────────────────────────────────────

	function fetchWhereUsed( id ) {
		window.fetch( restWhere + '/' + id, {
			headers: { 'X-WP-Nonce': nonce },
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( usages ) {
			// state.id is always String(), id from data.id is integer — cast both.
			if ( String( state.id ) !== String( id ) ) { return; }
			var $section = $( '#dy-dp-where-used-section' );
			if ( ! $section.length ) { return; }

			if ( ! Array.isArray( usages ) || ! usages.length ) {
				$section.find( '.dy-dp-loading' ).replaceWith(
					'<p class="dy-dp-used-empty">' + esc( i18n.notUsedInContent ) + '</p>'
				);
				return;
			}

			var items = usages.map( function ( u ) {
				return '<li><a href="' + esc( u.edit_url || u.url || '#' ) + '" target="_blank">' +
					esc( u.title || i18n.untitled ) + '</a></li>';
			} ).join( '' );

			$section.find( '.dy-dp-loading' ).replaceWith(
				'<ul class="dy-dp-used-list">' + items + '</ul>'
			);
		} )
		.catch( function () {
			$( '#dy-dp-where-used-section .dy-dp-loading' ).replaceWith(
				'<p class="dy-dp-used-empty">—</p>'
			);
		} );
	}

	// ── Save / delete ──────────────────────────────────────────────────────

	// The four editable fields, read from the panel as it is right now.
	function collectFields() {
		return {
			alt_text:    $( '#dy-dp-alt' ).val() || '',
			title:       $( '#dy-dp-title' ).val() || '',
			caption:     $( '#dy-dp-caption' ).val() || '',
			description: $( '#dy-dp-description' ).val() || ''
		};
	}

	// Persists the fields for one attachment and mirrors them into the
	// Backbone model so the grid stays in sync. Resolves on success.
	function persistFields( id, fields ) {
		return window.fetch( restWP + id, {
			method:  'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
			body:    JSON.stringify( fields ),
		} )
		.then( function ( r ) {
			if ( ! r.ok ) { throw new Error( r.status ); }
			return r.json();
		} )
		.then( function ( saved ) {
			try {
				var model = wp.media.frame.content.get().collection.get( parseInt( id, 10 ) );
				if ( model ) {
					model.set( {
						title:       fields.title,
						alt:         fields.alt_text,
						caption:     fields.caption,
						description: fields.description
					} );
				}
			} catch ( e ) {}
			return saved;
		} );
	}

	function saveAttachment( id, $btn ) {
		// Clicking Save with nothing changed is a reflex, not a mistake: answer
		// it on the button, but do not claim changes were saved and do not send
		// a pointless request.
		if ( ! state.dirty ) {
			$btn.prop( 'disabled', true ).text( i18n.saved );
			setTimeout( function () {
				$btn.prop( 'disabled', false ).text( i18n.save );
			}, 1200 );
			return;
		}

		$btn.prop( 'disabled', true ).text( i18n.saving );

		var sent = collectFields();

		persistFields( id, sent )
		.then( function () {
			$btn.text( i18n.saved );

			// Same confirmation whichever way the save was triggered. The button
			// answers in place, the toast is the signal the user learns to trust,
			// and it would be odd for it to go missing on the one save they asked
			// for explicitly.
			if ( window.doubleyoupiShowToast ) {
				window.doubleyoupiShowToast( i18n.savedOnLeave || 'Changes saved', 'success' );
			}

			// What was just saved becomes the new reference, so the button
			// settles back to its quiet state and the field dots clear.
			if ( String( state.id ) === String( id ) ) {
				state.initial = sent;
				refreshDirty();
			}
			setTimeout( function () {
				$btn.prop( 'disabled', false ).text( i18n.save );
			}, 2000 );
		} )
		.catch( function () {
			$btn.prop( 'disabled', false ).text( i18n.errorRetry );
		} );
	}

	// ── Unsaved state ──────────────────────────────────────────────────────
	// The Save button is the single signal. At rest it is a quiet outline; the
	// moment a value differs from what the server sent it fills with the accent
	// and wakes up once. Each changed field also gets a dot next to its label,
	// so on a four-field panel you can see what you touched.

	function refreshDirty() {
		var $save = $( '.dy-dp-save' );
		if ( ! state.initial ) {
			$save.removeClass( 'is-dirty' );
			return;
		}

		var now   = collectFields();
		var dirty = false;

		$( EDITABLE_FIELDS ).each( function () {
			var key      = FIELD_KEYS[ this.id ];
			var changed  = key && now[ key ] !== state.initial[ key ];
			dirty        = dirty || !! changed;
			$( 'label[for="' + this.id + '"]' ).toggleClass( 'is-modified', !! changed );
		} );

		state.dirty = dirty;
		$save.toggleClass( 'is-dirty', dirty );
		$save.attr( 'title', dirty ? ( i18n.unsavedHint || '' ) : null );
	}

	// Saves pending edits when the user moves on, rather than blocking the move
	// with a dialog: on a captioning run, a confirm on every item is unusable.
	// WordPress's own attachment modal saves as you go for the same reason.
	// Failures are never silent, otherwise the text would vanish unnoticed.
	function saveBeforeLeaving() {
		if ( ! state.dirty || ! state.id ) { return; }
		var leavingId = state.id;
		state.dirty   = false;

		persistFields( leavingId, collectFields() )
			.then( function () {
				if ( window.doubleyoupiShowToast ) {
					window.doubleyoupiShowToast( i18n.savedOnLeave || 'Changes saved', 'success' );
				}
			} )
			.catch( function () {
				if ( window.doubleyoupiShowToast ) {
					window.doubleyoupiShowToast( i18n.saveOnLeaveFailed || 'Could not save your changes', 'error' );
				}
			} );
	}

	// ── Carried focus ──────────────────────────────────────────────────────
	// After prev/next, drop the cursor back where it was on the previous item,
	// at the end of whatever text is already there.

	function applyCarriedFocus() {
		var fieldId = state.carryField;
		state.carryField = null;
		if ( ! fieldId ) { return; }
		var el = document.getElementById( fieldId );
		if ( ! el ) { return; }
		el.focus();
		try {
			var len = el.value.length;
			el.setSelectionRange( len, len );
		} catch ( e ) {}
	}

	function deleteAttachment( id ) {
		window.doubleyoupiDialog.confirm( {
			title:        i18n.deleteAction,
			message:      i18n.deleteFileConfirm,
			confirmLabel: i18n.deleteAction,
			cancelLabel:  i18n.cancel,
			danger:       true
		} ).then( function ( ok ) {
			if ( ! ok ) { return; }
			window.fetch( restQuery( restWP + id, 'force=true' ), {
				method:  'DELETE',
				headers: { 'X-WP-Nonce': nonce },
			} )
			.then( function ( r ) {
				if ( ! r.ok ) { throw new Error( r.status ); }
				// Remove from Backbone collection + grid.
				$( '.attachment[data-id="' + id + '"]' ).fadeOut( 200, function () { $( this ).remove(); } );
				// The file is gone: nothing left to save on the way out.
				state.dirty = false;
				closePanel();
			} )
			.catch( function () {
				window.doubleyoupiDialog.alert( { title: i18n.deleteFileFailed } );
			} );
		} );
	}

	// ── Navigation (prev/next) ─────────────────────────────────────────────

	function navigate( direction ) {
		var $attachments = $( '.attachments li.attachment' );
		if ( ! $attachments.length ) {
			$attachments = $( '.attachments .attachment' );
		}

		// WP uses data-id (not id="post-N") on .attachment elements.
		var $current = $( '.attachment[data-id="' + state.id + '"]' );
		var idx      = $attachments.index( $current );

		if ( idx === -1 ) {
			return;
		}

		var newIdx = idx + direction;
		if ( newIdx < 0 || newIdx >= $attachments.length ) { return; }

		var $target = $attachments.eq( newIdx );
		var newId   = String( $target.attr( 'data-id' ) || '' );
		if ( ! newId || newId === '0' ) { return; }

		var model = null;
		try {
			model = wp.media.frame.content.get().collection.get( parseInt( newId, 10 ) );
		} catch ( e ) {}

		// Moving on with prev/next is a deliberate "next one": pending edits are
		// saved on the way out, and the cursor lands in the same field on the
		// next item.
		saveBeforeLeaving();
		state.carryField = state.lastField;

		openPanel( newId, model );
	}

	// ── Keyboard shortcuts ─────────────────────────────────────────────────

	$( document ).on( 'keydown', function ( e ) {
		// Lightbox takes priority over the panel while it is open.
		if ( document.body.classList.contains( 'dy-lb-open' ) ) {
			if ( e.key === 'Escape' )                                   { e.preventDefault(); closeLightbox(); }
			else if ( e.key === 'ArrowLeft'  || e.key === 'ArrowUp' )   { e.preventDefault(); stepLightbox( -1 ); }
			else if ( e.key === 'ArrowRight' || e.key === 'ArrowDown' ) { e.preventDefault(); stepLightbox( 1 ); }
			return;
		}
		if ( ! state.open ) { return; }

		// Cmd/Ctrl + arrow moves to the next item even from inside a field, so a
		// captioning run never needs the mouse: type, Cmd+Right, type again.
		if ( ( e.metaKey || e.ctrlKey ) && ( e.key === 'ArrowLeft' || e.key === 'ArrowRight' ) ) {
			e.preventDefault();
			navigate( e.key === 'ArrowRight' ? 1 : -1 );
			return;
		}

		if ( $( e.target ).is( 'input, textarea, select' ) ) { return; }

		if ( e.key === 'Escape' ) {
			closePanel();
		} else if ( e.key === 'ArrowLeft' || e.key === 'ArrowUp' ) {
			e.preventDefault();
			navigate( -1 );
		} else if ( e.key === 'ArrowRight' || e.key === 'ArrowDown' ) {
			e.preventDefault();
			navigate( 1 );
		}
	} );

	// (Unsaved-changes guard now lives in the edit:attachment handler above,
	// so it can use the async designed dialog without blocking the click.)

	// ── Helpers ───────────────────────────────────────────────────────────

	// Clamp a preview aspect-ratio (width / height) to sane bounds so neither
	// very wide nor very tall images blow out the panel.
	function clampPar( p ) {
		return Math.max( 0.45, Math.min( 3, p ) );
	}

	// Aspect-ratio for the preview box: a height the user has chosen (stored)
	// wins; otherwise the image's own ratio, so each one looks right by default.
	function previewAspect( details ) {
		var stored = parseFloat( localStorage.getItem( 'dy-par' ) );
		if ( stored && stored > 0 ) { return clampPar( stored ); }
		if ( details && details.width && details.height ) {
			return clampPar( details.width / details.height );
		}
		return 1.5;
	}

	function formatSize( bytes ) {
		if ( bytes < 1024 )     { return bytes + ' ' + ( i18n.sizeBytes || 'B' ); }
		if ( bytes < 1048576 )  { return Math.round( bytes / 1024 ) + ' ' + ( i18n.sizeKB || 'KB' ); }
		return ( bytes / 1048576 ).toFixed( 1 ) + ' ' + ( i18n.sizeMB || 'MB' );
	}

	function mimeIcon( mime ) {
		if ( /^image\//.test( mime ) )  { return 'dashicons-format-image'; }
		if ( /^video\//.test( mime ) )  { return 'dashicons-format-video'; }
		if ( /^audio\//.test( mime ) )  { return 'dashicons-format-audio'; }
		if ( /pdf/.test( mime ) )       { return 'dashicons-media-document'; }
		return 'dashicons-media-default';
	}

	// The `view` context returns rendered HTML (a <p> wrapper, entities). The
	// panel shows plain text, so unwrap it using the browser's own parser.
	function stripHtml( html ) {
		if ( ! html ) { return ''; }
		var d = window.document.createElement( 'div' );
		d.innerHTML = html;
		return ( d.textContent || '' ).trim();
	}

	function esc( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

} )( jQuery, window );
