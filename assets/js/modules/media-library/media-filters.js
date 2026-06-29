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

	var i18n = ( window.doubleyoupiMediaFolders && window.doubleyoupiMediaFolders.i18n ) || {};

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

		$select.on( 'change', function () {
			var val = $( this ).val();
			var col = getCollection();
			if ( col ) {
				col.props.set( { type: val || null } );
			}
		} );

		var $secondary  = $( '.media-toolbar-secondary' ).first();
		var $lastFilter = $secondary.find( 'select.attachment-filters' ).last();

		if ( $lastFilter.length ) {
			$lastFilter.after( $select );
		} else if ( $secondary.length ) {
			$secondary.append( $select );
		} else {
			$( '.media-toolbar-primary' ).first().prepend( $select );
		}
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
		injectSearchButton();
		fillSearchPlaceholder();
		injectDetailClose();
	}
	function runInjectorsBurst() {
		[ 120, 300, 600, 1000, 1600 ].forEach( function ( d ) {
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
