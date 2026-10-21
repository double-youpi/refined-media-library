/* global wp */
( function ( $ ) {
	'use strict';

	if ( ! window.wp || ! wp.media ) {
		return;
	}

	/*
	 * Injects a MIME type filter <select> into the media toolbar.
	 * Uses the native WP Backbone 'type' prop (maps to post_mime_type in
	 * the query-attachments AJAX handler — no PHP changes needed).
	 *
	 * Labels come from PHP via doubleyoupiMediaFolders.i18n (proper WP i18n).
	 */

	var dyData        = window.doubleyoupiMediaFolders || {};
	var i18n          = dyData.i18n || {};
	var currentUserId = parseInt( dyData.currentUserId, 10 ) || 0;
	var showMine      = dyData.mineFilter === '1';

	var types = [
		{ value: '',               label: i18n.allTypes  || 'All types' },
		{ value: 'image',          label: i18n.images    || 'Images' },
		{ value: 'video',          label: i18n.videos    || 'Videos' },
		{ value: 'audio',          label: i18n.audio     || 'Audio' },
		{ value: 'application',    label: i18n.documents || 'Documents' },
		{ value: 'image/svg+xml',  label: i18n.svg       || 'SVG' },
	];

	function getCollection() {
		try {
			return wp.media.frame.content.get().collection;
		} catch ( e ) {
			return null;
		}
	}

	function injectTypeFilter() {
		if ( $( '#dy-type-filter' ).length ) {
			return;
		}

		/*
		 * Inject *after* the existing attachment-filters selects in the secondary
		 * toolbar (All media, All dates) so it visually groups with
		 * the other filters — not on the right side with the search.
		 *
		 * Fallback: prepend to .media-toolbar-primary if the secondary side or
		 * its filters can't be found.
		 */
		var options = types.map( function ( t ) {
			return '<option value="' + t.value + '">' + t.label + '</option>';
		} ).join( '' );

		var $select = $( '<select id="dy-type-filter" class="attachment-filters">' + options + '</select>' );
		// Accessible name (audit of 21/10/2026): WordPress's own, already
		// translated, "Filter by type"; else the first option.
		var l10n = window.wp && wp.media && wp.media.view && wp.media.view.l10n;
		$select.attr( 'aria-label', ( l10n && l10n.filterByType ) || ( types[0] && types[0].label ) || '' );

		$select.on( 'change', function () {
			var val = $( this ).val();
			var col = getCollection();
			if ( col ) {
				col.props.set( { type: val || null } );
			}
		} );

		/*
		 * The browsing toolbar, and only that one.
		 *
		 * A media frame has two `.media-toolbar-secondary`: the one above the
		 * grid, and the one in the footer next to the Select button. Taking the
		 * first match in the document was right only by luck, as long as the
		 * grid existed. On the "Upload files" tab it does not, so the type
		 * filter dropped into the footer and sat next to the Select button.
		 *
		 * Anchoring on `.attachments-browser` says what we mean, and `:visible`
		 * keeps it out of the modals a screen has already closed.
		 */
		var $browse     = $( '.attachments-browser .media-toolbar-secondary' ).filter( ':visible' );
		var $secondary  = ( $browse.length ? $browse : $( '.attachments-browser .media-toolbar-secondary' ) ).first();
		if ( ! $secondary.length ) { return; }

		var $lastFilter = $secondary.find( 'select.attachment-filters' ).last();

		if ( $lastFilter.length ) {
			$lastFilter.after( $select );
		} else {
			$secondary.append( $select );
		}
	}

	/*
	 * "Mine" toggle button — filters the library to the current user's own
	 * uploads. Only rendered when the library actually holds uploads from more
	 * than one author (gated server-side via doubleyoupiMediaFolders.mineFilter),
	 * so a solo site never sees a pointless control. Filters by the native
	 * `author` query prop, the same one WP's own "Mine" filter uses.
	 */
	function injectMineButton() {
		if ( ! showMine ) { return; }
		if ( ! /\/upload\.php/.test( window.location.pathname ) ) { return; }
		if ( $( '#dy-mine-filter' ).length ) { return; }

		var $type = $( '#dy-type-filter' );
		if ( ! $type.length ) { return; } // sit right after the type filter

		var label = i18n.mine || 'Mine';
		var icon  = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="8" r="3.4"></circle><path d="M5.5 20a6.5 6.5 0 0 1 13 0"></path></svg>';
		var $btn  = $(
			'<button type="button" id="dy-mine-filter" class="dy-mine-btn" aria-pressed="false" aria-label="' + label + '" title="' + label + '">' +
				icon + '<span class="dy-mine-btn__label">' + label + '</span>' +
			'</button>'
		);

		$btn.on( 'click', function () {
			var on = $btn.attr( 'aria-pressed' ) !== 'true';
			$btn.attr( 'aria-pressed', on ? 'true' : 'false' ).toggleClass( 'is-active', on );
			var col = getCollection();
			if ( col ) {
				col.props.set( { author: on ? ( currentUserId || null ) : null } );
			}
		} );

		$type.after( $btn );
	}

	/*
	 * Inject a "Search" submit button next to the grid view's search input,
	 * mirroring the list view's <button>Search</button>.
	 *
	 * Why: WP grid uses a live (typing) search via Backbone props, but users
	 * expect a button for parity with the list view. The button on click
	 * re-applies the search prop (the live behaviour also remains).
	 */
	function injectSearchButton() {
		// Only on upload.php grid (not the modal) to avoid leaking into editor pickers.
		if ( ! /\/upload\.php/.test( window.location.pathname ) ) { return; }
		if ( $( '#dy-grid-search-btn' ).length ) { return; }

		var $input = $( '.media-toolbar input[type="search"]#media-search-input' ).first();
		if ( ! $input.length ) { return; }

		var $btn = $( '<button type="button" id="dy-grid-search-btn" class="button button-primary">' +
			( i18n.searchAction || 'Search' ) + '</button>' );

		$btn.on( 'click', function ( e ) {
			e.preventDefault();
			try {
				var col = wp.media.frame.content.get().collection;
				if ( col ) { col.props.set( { search: $input.val() } ); }
			} catch ( err ) {}
		} );

		$input.after( $btn );
	}

	/*
	 * The modal search has only a visually-hidden <label class="media-search-
	 * input-label"> and no placeholder → the field reads as an empty, unlabelled
	 * box. We keep the label for screen readers (proper a11y) but mirror its text
	 * into the input's placeholder so the field is self-describing and not blank.
	 */
	function fillSearchPlaceholder() {
		$( '.media-toolbar #media-search-input, .media-toolbar input.search, .media-frame input#media-search-input' ).each( function () {
			var $input = $( this );
			if ( $input.attr( 'placeholder' ) ) { return; }
			var id = $input.attr( 'id' );
			var labelText = ( id ? $( '.media-search-input-label[for="' + id + '"]' ).first().text().trim() : '' )
				|| $( '.media-search-input-label' ).first().text().trim()
				|| ( i18n.searchAction || 'Search' );
			$input.attr( 'placeholder', labelText );
		} );
	}

	/*
	 * Detail-panel close button. The right-hand .media-sidebar opens whenever a
	 * media is selected; we add a × that BOTH closes the panel and deselects the
	 * current item (resetting the Backbone selection → our CSS :has() rule
	 * collapses the sidebar and the tile loses its selected state).
	 */
	function injectDetailClose() {
		$( '.media-modal .media-sidebar' ).each( function () {
			var $sb = $( this );
			if ( $sb.find( '.dy-detail-close' ).length ) { return; }
			var $btn = $( '<button type="button" class="dy-detail-close" aria-label="' +
				( i18n.close || 'Close' ) + '">×</button>' );
			$btn.on( 'click', function ( e ) {
				e.preventDefault();
				try { wp.media.frame.state().get( 'selection' ).reset(); } catch ( err ) {}
			} );
			$sb.prepend( $btn );
		} );
	}

	/*
	 * Some targets render lazily: the toolbar appears on open, but the search
	 * input and the right-hand .media-sidebar only render once content/selection
	 * is ready. All injectors are idempotent, so we just run them repeatedly for
	 * a short window after each trigger to catch whatever renders late.
	 */
	function runInjectors() {
		injectTypeFilter();
		injectMineButton();
		injectSearchButton();
		fillSearchPlaceholder();
		injectDetailClose();
	}
	/*
	 * The targets appear at their own pace: the toolbar on open, the grid and
	 * the details pane later. Every injector is idempotent, so we simply try
	 * again for a while.
	 *
	 * The tail beyond 1.6s is not padding. The type filter now insists on the
	 * browsing toolbar (see injectTypeFilter) instead of falling back to
	 * whatever toolbar exists first, so on a library slow to render there is
	 * nothing to inject into during the early attempts. Without these last
	 * tries the filter would simply never appear.
	 */
	function runInjectorsBurst() {
		[ 120, 300, 600, 1000, 1600, 2600, 4000 ].forEach( function ( d ) {
			setTimeout( runInjectors, d );
		} );
	}

	// Hook into every MediaFrame open + selection change to (re)inject.
	if ( wp.media.view && wp.media.view.MediaFrame ) {
		var OrigFrame = wp.media.view.MediaFrame.prototype.initialize;
		wp.media.view.MediaFrame.prototype.initialize = function () {
			OrigFrame.apply( this, arguments );
			this.on( 'open', function () {
				runInjectorsBurst();
				// The sidebar appears when a media is selected → re-inject then.
				try {
					this.state().get( 'selection' )
						.on( 'add remove reset', function () { setTimeout( runInjectors, 60 ); } );
				} catch ( e ) {}
			}, this );
			this.on( 'content:render', function () { setTimeout( runInjectors, 80 ); }, this );
		};
	}

	// On upload.php the frame is already open.
	$( function () {
		runInjectorsBurst();
	} );

} )( jQuery );
