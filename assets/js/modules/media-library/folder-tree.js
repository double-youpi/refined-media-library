/**
 * Media Library — folder & collection panel, drag & drop, filters.
 *
 * This module hooks into `wp.media` (Backbone) internally. WordPress has never
 * published an official API for extending the media library on the JS side — the
 * grid is a Backbone MediaFrame inherited from pre-React Gutenberg, and everything
 * done here (intercepting a collection query, injecting a panel, listening to
 * select:single) amounts to politely asking non-public internals to remain
 * compatible. If a WP update breaks something, this is the first place to look.
 */

/* global wp, doubleyoupiMediaFolders */
( function ( $, window ) {
	'use strict';

	if ( ! window.doubleyoupiMediaFolders ) {
		return;
	}

	var restBase        = doubleyoupiMediaFolders.restBase;
	var restCollections = doubleyoupiMediaFolders.restCollections;
	var nonce           = doubleyoupiMediaFolders.nonce;
	var i18n            = doubleyoupiMediaFolders.i18n;

	// Feature flags: a disabled section remains visible but inert, with
	// a label ("Folders disabled"). Defaults to true when absent.
	var foldersEnabled     = doubleyoupiMediaFolders.foldersEnabled !== '0';
	var collectionsEnabled = doubleyoupiMediaFolders.collectionsEnabled !== '0';
	var settingsUrl        = doubleyoupiMediaFolders.settingsUrl || '';
	var ajaxUrl            = doubleyoupiMediaFolders.ajaxUrl || '';
	var enableNonce        = doubleyoupiMediaFolders.enableNonce || '';

	// The admin-sidebar watcher is wired once per page (see watchAdminMenuWidth).
	var adminMenuWatched   = false;

	/*
	 * sprintf-lite — supports %s, %d, and positional %1$s / %2$d.
	 * Used to inject runtime values into translated PHP strings (e.g. names, counts).
	 * Why: WordPress translations live in PHP, but counts/names are computed in JS.
	 */
	function fmt( template ) {
		if ( typeof template !== 'string' ) { return ''; }
		var args = Array.prototype.slice.call( arguments, 1 );
		var i    = 0;
		return template
			.replace( /%(\d+)\$[ds]/g, function ( _, n ) {
				return args[ parseInt( n, 10 ) - 1 ];
			} )
			.replace( /%[ds]/g, function () {
				return args[ i++ ];
			} );
	}

	/*
	 * Shared state — readable by media-detail.js and other scripts.
	 */
	window.doubleyoupiMediaState = window.doubleyoupiMediaState || {
		currentFolder:     0,
		currentCollection: null,
		folders:           [],
		collections:       [],
	};
	var dyState = window.doubleyoupiMediaState;

	/*
	 * ── Reveal curtain handshake ────────────────────────────────────────────
	 * CSS hides `.media-frame` + `#dy-folder-panel` via
	 * `body.upload-php:not(.dy-ml-ready)` to avoid the FOUC where Backbone
	 * renders the wide native grid before we inject the folder panel and the
	 * layout re-flows. We flip the class once `injectPanel` has appended
	 * `#dy-folder-panel` (idempotent — only fires the first time).
	 *
	 * Double rAF : the FIRST frame commits the just-injected panel layout to
	 * the browser; the SECOND frame flips the class, so the transition has a
	 * real "before" state and actually plays instead of jumping to the end.
	 * (Single rAF can collapse start + end into one paint on Chromium.)
	 *
	 * A 1500ms hard safety in the boot block flips the class unconditionally
	 * so the grid is never permanently hidden if anything in the init throws
	 * before `injectPanel` runs.
	 */
	function markFrameReady() {
		if ( document.body.classList.contains( 'dy-ml-ready' ) ) { return; }
		requestAnimationFrame( function () {
			requestAnimationFrame( function () {
				document.body.classList.add( 'dy-ml-ready' );
			} );
		} );
	}

	/*
	 * TIMING
	 * ──────
	 * WP's media.js runs its $(function(){}) first, creating AttachmentsBrowser.
	 * Our handler fires after — .attachments-browser is already in the DOM.
	 */
	$( function () {
		/*
		 * WHICH SCREEN IS THIS?
		 * ─────────────────────
		 * This has to be the very first question, and everything below depends
		 * on the answer.
		 *
		 * These assets deliberately load far beyond the media library: the
		 * picker modal can be opened from anywhere WordPress has enqueued
		 * media, and it has to be dressed wherever it opens. On such a screen
		 * the ONLY thing that belongs to RML is that modal. The screen's own
		 * chrome — its title, its notices, its list table — is somebody else's.
		 *
		 * Getting this wrong is not cosmetic. Without the guard, every boot
		 * step below ran on post lists and taxonomy term lists, mounting the
		 * folder panel over screens that have nothing to do with media.
		 */
		if ( ! isLibraryScreen() ) {
			bootPickerOnly();
			return;
		}

		// Detect grid vs list mode (only upload.php has both — modal is always grid).
		// In list mode we navigate via URL (?doubleyoupi_folder=N); in grid mode we use Backbone.
		var listMode = isListMode();

		if ( ! listMode ) {
			// ── Grid mode ─────────────────────────────────────────────
			initMediaAjaxHook();

			// Sync state from URL BEFORE first refetch so direct loads of
			// upload.php?mode=grid&doubleyoupi_folder=N (or doubleyoupi_collection=N) apply the filter.
			syncStateFromUrl();

			// Panel into every browser Backbone renders, now and later.
			patchMediaViews();

			injectPanel( $( '.attachments-browser' ).first() );

			// Safety net retry
			setTimeout( function () {
				injectPanel( $( '.attachments-browser' ).first() );
			}, 400 );

			// If URL had a filter, WP's first fetch likely went out before our state
			// was set. Force a refetch with the active filter once Backbone is ready.
			if ( dyState.currentFolder !== 0 || ( dyState.currentCollection !== null && dyState.currentCollection !== 0 ) ) {
				setTimeout( function () {
					markFolderActive( dyState.currentFolder );
					markCollectionActive( dyState.currentCollection );
					refreshAttachments();
				}, 700 );
			}
		} else {
			// ── List mode ─────────────────────────────────────────────
			// No .attachments-browser exists; inject directly into the wrap so
			// position:fixed pulls it onto the viewport regardless of host.
			injectPanelStandalone();
			// Initialize active folder/collection from URL params
			syncStateFromUrl();
			// Intercept the WP_List_Table bulk-action form submit so our two
			// custom actions (doubleyoupi_move_to_folder / doubleyoupi_add_to_collection) open
			// the picker modal instead of POSTing to upload.php.
			hookListBulkActions();
		}

		// Patch the view-switch links so toggling between grid/list keeps the filter.
		// Call once now (list view's links exist immediately) and again after Backbone
		// renders the grid toolbar.
		patchViewSwitchLinks();
		setTimeout( patchViewSwitchLinks, 500 );
		setTimeout( patchViewSwitchLinks, 1500 );

		// Measure the actual title row height so the panels start exactly below it.
		measureTitleHeight();
		setTimeout( measureTitleHeight, 200 ); // after fonts load
		$( window ).on( 'resize.dyTitleH', debounce( measureTitleHeight, 100 ) );

		// Watch scroll : when the title goes above the admin bar, expand the panel
		// to take the full viewport height (with a 20px gutter).
		hookPanelScrollExpand();

		// Publish #screen-meta's height so the brand + side panels shift down with it.
		observeScreenMeta();

		// Toggle body.dy-all-loaded when all attachments are displayed → CSS
		// hides .load-more-wrapper. WP natively renders `.load-more-count` AND
		// `.load-more` even when everything is loaded: we need a heuristic
		// to hide them when there is nothing more to load.
		hookLoadMoreObserver();

		// Notices: one home, to the right of the wordmark (see above).
		hookNoticeRelocation();

		// Hard safety for the reveal curtain — see markFrameReady() above.
		// If anything in the init throws before injectPanel runs, this still
		// flips body.dy-ml-ready so the grid is never permanently hidden.
		setTimeout( markFrameReady, 1500 );
	} );

	/*
	 * Observe `.load-more-count` text and compare displayed vs total. On
	 * upload.php (grid mode), the WP markup is:
	 *   <p class="load-more-count">Showing 19 of 19 media items</p>
	 *   <button class="load-more">Load more</button>
	 * (Backbone re-renders on each load, so we observe both attribute
	 * AND childList changes.)
	 *
	 * When `displayed === total`, we set body.dy-all-loaded → SCSS hides
	 * the wrapper. If the user applies a filter that reduces the total,
	 * the observer picks that up too.
	 *
	 * Locale-agnostic note: we extract both numbers via /(\d[\d\s]*)/g
	 * without depending on the text language ("Showing X of Y" vs locale equivalent).
	 */
	function hookLoadMoreObserver() {
		var body = document.body;
		function check() {
			var countEl = document.querySelector( '.load-more-count' );
			if ( ! countEl ) {
				body.classList.remove( 'dy-all-loaded' );
				return;
			}
			var text = countEl.textContent || '';
			var nums = text.match( /\d[\d\s]*/g );
			if ( ! nums || nums.length < 2 ) {
				body.classList.remove( 'dy-all-loaded' );
				return;
			}
			var displayed = parseInt( nums[0].replace( /\s/g, '' ), 10 );
			var total     = parseInt( nums[1].replace( /\s/g, '' ), 10 );
			if ( ! isNaN( displayed ) && ! isNaN( total ) && displayed >= total ) {
				body.classList.add( 'dy-all-loaded' );
			} else {
				body.classList.remove( 'dy-all-loaded' );
			}
		}

		// Initial pass + observer for dynamic changes.
		check();

		var browser = document.querySelector( '.attachments-browser' );
		if ( ! browser ) { return; }
		var observer = new MutationObserver( debounce( check, 80 ) );
		observer.observe( browser, {
			childList: true,
			subtree: true,
			characterData: true,
		} );
	}

	/*
	 * Toggle body.dy-panel-expanded based on whether the page heading is
	 * still visible in the viewport. CSS does the actual transition on the
	 * panel's `top` property → smooth grow.
	 *
	 * Passive scroll listener rather than IntersectionObserver: on
	 * upload.php in grid mode, scroll events are sometimes captured by an
	 * intermediate container that IO does not follow. requestAnimationFrame +
	 * a passive listener keep the cost negligible.
	 */
	/*
	 * When the WP Screen Options / Help panel (#screen-meta) is open it pushes the
	 * in-flow content (the grid) down. The brand and the two side panels are
	 * position:fixed, so they would NOT move — we mirror the shift by publishing
	 * the panel's live height as --dy-screen-meta-h (0 when closed). A ResizeObserver
	 * tracks open/close + content/viewport changes.
	 */
	function observeScreenMeta() {
		var sm = window.document.getElementById( 'screen-meta' );
		if ( ! sm || typeof window.ResizeObserver === 'undefined' ) { return; }
		var apply = function () {
			window.document.body.style.setProperty( '--dy-screen-meta-h', ( sm.offsetHeight || 0 ) + 'px' );
		};
		new window.ResizeObserver( apply ).observe( sm );
		apply();
	}

	function hookPanelScrollExpand() {
		var titleEl = document.querySelector( '.wrap > h1.wp-heading-inline, .wrap > h1' );
		if ( ! titleEl ) { return; }

		var adminBarH = parseInt( $( '#wpadminbar' ).outerHeight(), 10 ) || 32;
		var threshold = adminBarH + 1;
		var ticking   = false;

		function update() {
			ticking = false;
			var titleBottom = titleEl.getBoundingClientRect().bottom;
			document.body.classList.toggle( 'dy-panel-expanded', titleBottom < threshold );
		}

		function onScroll() {
			if ( ! ticking ) {
				window.requestAnimationFrame( update );
				ticking = true;
			}
		}

		window.addEventListener( 'scroll', onScroll, { passive: true } );
		window.addEventListener( 'resize', onScroll, { passive: true } );

		// When the Admin module is active, scrolling lives inside #wpcontent
		// (overflow-y: auto on the "content" grid area), not on window.
		// Without this listener, body.dy-panel-expanded would never trigger
		// in admin mode → the panel stays pinned at the top.
		var wpcontent = document.getElementById( 'wpcontent' );
		if ( wpcontent ) {
			wpcontent.addEventListener( 'scroll', onScroll, { passive: true } );
		}

		// Initial state
		update();
	}

	/*
	 * Is this the media library page, the one screen RML owns end to end?
	 *
	 * The body class is the only answer worth trusting. WordPress derives it
	 * from the hook suffix in admin-header.php, so `upload-php` is on the
	 * media library and on nothing else — it does not depend on which plugins
	 * enqueued what, nor on markup any of them may also render.
	 *
	 * Do NOT infer the screen from the DOM. That was the bug: list mode used
	 * to be detected by the presence of `#posts-filter`, which reads as "a
	 * server-rendered list table" and is therefore true on upload.php in list
	 * mode — but equally true on edit.php for every post type, and on
	 * edit-tags.php for every taxonomy. Wherever a plugin had called
	 * wp_enqueue_media() (ACF, page builders, theme options all do it widely),
	 * those screens loaded this file, matched the heuristic, and grew a folder
	 * panel. A shape shared by four core screens cannot identify one of them.
	 */
	function isLibraryScreen() {
		return document.body.classList.contains( 'upload-php' );
	}

	/*
	 * Boot for every OTHER admin screen: dress the picker modal, touch nothing
	 * else. No panel on <body>, no title measuring, no notice relocation, no
	 * curtain — all of that belongs to a screen we do not own here.
	 *
	 * The modal's own panel is mounted by the view patches, on render and on
	 * open, and it lives inside .attachments-browser so it leaves with the
	 * modal.
	 */
	function bootPickerOnly() {
		// Folder/collection filtering of the picker's queries.
		initMediaAjaxHook();
		patchMediaViews();
		// A modal may already be on screen if something opened one on load.
		placePanelInVisibleModal();
	}

	/*
	 * Teach Backbone's media views to carry the panel.
	 *
	 * Shared by the library page and by every other screen, because a picker
	 * modal behaves the same wherever it opens. Installed once — the patched
	 * prototypes are global, and this file runs once per page.
	 */
	function patchMediaViews() {
		if ( patchMediaViews.done ) { return; }
		patchMediaViews.done = true;

		// Future renders (modals, state changes).
		if ( window.wp && wp.media && wp.media.view && wp.media.view.AttachmentsBrowser ) {
			var origRender = wp.media.view.AttachmentsBrowser.prototype.render;
			wp.media.view.AttachmentsBrowser.prototype.render = function () {
				origRender.apply( this, arguments );
				injectPanel( this.$el );
				// A render usually happens while the view is still detached,
				// so "what is visible" is only settled a tick later. Re-assert
				// then: this is what catches the browser WordPress builds when
				// you come back from the "Upload files" tab.
				setTimeout( placePanelInVisibleModal, 0 );
				setTimeout( placePanelInVisibleModal, 250 );
				return this;
			};
		}

		/*
		 * Opening a modal does not always render its browser: WordPress
		 * reuses a view it has already rendered, and our render patch never
		 * fires. Without this, reopening a picker showed no folders at all.
		 */
		if ( window.wp && wp.media && wp.media.view && wp.media.view.Modal ) {
			var origModalOpen = wp.media.view.Modal.prototype.open;
			wp.media.view.Modal.prototype.open = function () {
				var result = origModalOpen.apply( this, arguments );
				setTimeout( placePanelInVisibleModal, 0 );
				setTimeout( placePanelInVisibleModal, 250 );
				return result;
			};
		}
	}

	/*
	 * Two indicators of list mode, evaluated on upload.php ONLY (isLibraryScreen
	 * has already answered the screen question — see the note there on why the
	 * third test must never be asked anywhere else):
	 *  - URL contains ?mode=list
	 *  - Absence of .media-frame.mode-grid
	 *  - DOM has #posts-filter form (server-side rendered list table)
	 */
	function isListMode() {
		if ( /[?&]mode=list\b/.test( window.location.search ) ) { return true; }
		if ( $( '.media-frame.mode-grid' ).length ) { return false; }
		return $( '#posts-filter' ).length > 0;
	}

	/*
	 * List-mode injection : no .attachments-browser exists. The panel itself
	 * is position:fixed so its DOM location doesn't matter for layout — we
	 * append to <body> (NOT .wrap) for the same two reasons documented on
	 * injectPanel above : avoid being descendant of .wrap (whose curtain
	 * transition transforms transform:translateY → none, creating a temporary
	 * containing block that mispositions the panel) and avoid being destroyed
	 * by any later Backbone re-render of the host.
	 */
	function injectPanelStandalone() {
		if ( $( '#dy-folder-panel' ).length ) { return; }
		$( 'body' ).append( buildPanel() );

		var fw = localStorage.getItem( 'dy-fw' ) || '220';
		document.body.style.setProperty( '--dy-fw', fw + 'px' );

		$( '#dy-add-folder-btn' ).on( 'click', onAddFolder );
		$( '#dy-add-collection-btn' ).on( 'click', onAddCollection );

		relocateAddMediaButton();
		watchForeignActions();
		mountPanelBrand();

		initFolderResize();
		loadFolderTree();
		loadCollections();

		// List-mode equivalent of the grid-mode handshake — flip the curtain
		// once the standalone panel is mounted (markFrameReady is idempotent).
		markFrameReady();
	}

	/*
	 * Read ?doubleyoupi_folder / ?doubleyoupi_collection from URL on boot so the panel highlights
	 * the active item that matches what the server-rendered list table shows.
	 */
	function syncStateFromUrl() {
		var params = new URLSearchParams( window.location.search );
		var fid = parseInt( params.get( 'doubleyoupi_folder' ), 10 );
		var cid = parseInt( params.get( 'doubleyoupi_collection' ), 10 );
		if ( ! isNaN( fid ) ) { dyState.currentFolder = fid; }
		if ( ! isNaN( cid ) ) { dyState.currentCollection = cid; }
	}

	/*
	 * Navigate to the list view URL with the requested folder/collection filter.
	 * Used as the "click handler" in list mode.
	 */
	function navigateListView( params ) {
		var url = new URL( window.location.href );
		// Always preserve mode=list
		url.searchParams.set( 'mode', 'list' );
		// Clear filters that we may set
		url.searchParams.delete( 'doubleyoupi_folder' );
		url.searchParams.delete( 'doubleyoupi_collection' );
		// Apply requested params
		Object.keys( params || {} ).forEach( function ( k ) {
			if ( params[ k ] !== null && params[ k ] !== undefined && params[ k ] !== 0 ) {
				url.searchParams.set( k, params[ k ] );
			}
		} );
		window.location.href = url.toString();
	}

	/*
	 * List view bulk-actions interception.
	 *
	 * WP renders two <select name="action"> / <select name="action2"> dropdowns
	 * (top + bottom of the table) plus an Apply button. Our custom actions
	 * 'doubleyoupi_move_to_folder' and 'doubleyoupi_add_to_collection' need a follow-up modal
	 * (folder/collection picker) — they don't POST to upload.php like normal
	 * WP bulk actions.
	 *
	 * On submit: read both action selects, find which one is set to our value,
	 * collect the checked IDs, and open the appropriate picker.
	 */
	function hookListBulkActions() {
		$( document ).on( 'submit', '#posts-filter', function ( e ) {
			var actionTop    = $( '#bulk-action-selector-top' ).val();
			var actionBottom = $( '#bulk-action-selector-bottom' ).val();
			var action       = ( actionTop && actionTop !== '-1' ) ? actionTop : actionBottom;

			if ( action !== 'doubleyoupi_move_to_folder' && action !== 'doubleyoupi_add_to_collection' ) {
				// Let WP handle native bulk actions (delete, etc.)
				return;
			}

			e.preventDefault();
			e.stopPropagation();

			var ids = $( '#the-list input[name="media[]"]:checked' ).map( function () {
				return parseInt( this.value, 10 );
			} ).get().filter( function ( v ) { return !! v; } );

			if ( ! ids.length ) {
				window.doubleyoupiDialog.alert( { title: i18n.noItemsSelected || 'No media selected.' } );
				return;
			}

			if ( action === 'doubleyoupi_move_to_folder' ) {
				openBulkFolderPicker( ids );
			} else {
				openBulkCollectionPicker( ids );
			}
		} );
	}

	/*
	 * When the user toggles between grid and list view (.view-switch links),
	 * preserve the active folder / collection filter by appending it to the
	 * target href.
	 *
	 * Called on boot AND after every selectFolder()/selectCollection() so the
	 * link's href stays in sync with current state.
	 */
	function patchViewSwitchLinks() {
		$( '.view-switch a, .filter-links a, a.view-list, a.view-grid' ).each( function () {
			var $a = $( this );
			var href = $a.attr( 'href' );
			if ( ! href ) { return; }

			// Build absolute URL so we can mutate searchParams safely.
			var url;
			try {
				url = new URL( href, window.location.origin );
			} catch ( e ) { return; }

			url.searchParams.delete( 'doubleyoupi_folder' );
			url.searchParams.delete( 'doubleyoupi_collection' );

			if ( dyState.currentFolder && dyState.currentFolder !== 0 ) {
				url.searchParams.set( 'doubleyoupi_folder', dyState.currentFolder );
			}
			if ( dyState.currentCollection && dyState.currentCollection !== 0 ) {
				url.searchParams.set( 'doubleyoupi_collection', dyState.currentCollection );
			}

			$a.attr( 'href', url.pathname + url.search + url.hash );
		} );
	}

	/*
	 * Measure the bottom Y of the page heading row (h1 + page-title-action + hr.wp-header-end)
	 * relative to the admin bar bottom, then publish it to CSS as --dy-title-h.
	 *
	 * Why: a hardcoded value breaks when the locale changes the title length, when the
	 * font loads late (FOUT), or when the user zooms. Measuring once at boot + on resize
	 * gives a precise offset so the panels sit exactly below the title with no overlap.
	 */
	function measureTitleHeight() {
		var $title = $( '.wrap > h1.wp-heading-inline, .wrap > h1' ).first();
		if ( ! $title.length ) { return; }

		var $hr     = $( '.wrap > hr.wp-header-end' ).first();
		var $action = $( '.wrap > .page-title-action' ).first();

		/*
		 * getBoundingClientRect is viewport-relative: if the page is scrolled,
		 * the heading may have a NEGATIVE bottom, and our calculation would return 0.
		 * → We normalise to document-relative coordinates by adding scrollY.
		 *
		 * In admin mode (Double Youpi), scrolling lives on #wpcontent (the "content"
		 * grid area with overflow-y: auto), NOT on window. Without adding
		 * wpcontent.scrollTop, a re-measure taken while scrolled internally
		 * computes a falsely low bottom → titleH falls back to 8px (breathing
		 * room only) → the folder panel slides too far up.
		 */
		var wpcontent = document.getElementById( 'wpcontent' );
		var innerScroll = ( wpcontent && document.body.classList.contains( 'dy-admin' ) ) ? wpcontent.scrollTop : 0;
		var scrollY = ( window.pageYOffset || document.documentElement.scrollTop || 0 ) + innerScroll;

		var bottoms = [ $title[ 0 ].getBoundingClientRect().bottom + scrollY ];
		if ( $hr.length )     { bottoms.push( $hr[ 0 ].getBoundingClientRect().bottom + scrollY ); }
		if ( $action.length ) { bottoms.push( $action[ 0 ].getBoundingClientRect().bottom + scrollY ); }

		var maxBottomDoc = Math.max.apply( null, bottoms );
		var adminBarH    = $( '#wpadminbar' ).outerHeight() || 32;
		var titleH       = Math.max( 0, Math.round( maxBottomDoc - adminBarH ) ) + 8; // 8px breathing room

		// Safety guard: if the result is trivially small (<= 16px, i.e.
		// nothing beyond the breathing room alone), measurement is unreliable
		// (hidden heading, unexpected scroll, etc.). Leave the existing value
		// untouched rather than overwriting a valid measurement.
		if ( titleH <= 16 ) { return; }

		document.body.style.setProperty( '--dy-title-h', titleH + 'px' );
	}

	function debounce( fn, wait ) {
		var t;
		return function () {
			var ctx = this, args = arguments;
			clearTimeout( t );
			t = setTimeout( function () { fn.apply( ctx, args ); }, wait );
		};
	}

	// ── Panel injection ───────────────────────────────────────────────────────

	/*
	 * Is this attachments browser the one inside a media picker modal, rather
	 * than the media library page?
	 *
	 * The same script runs in both places, and almost every layout decision
	 * below differs between them: where the panel is mounted, how it is
	 * positioned, whether the screen wordmark belongs on screen at all. Naming
	 * that difference once beats re-deriving it by accident at four call sites,
	 * which is how the modal ended up inheriting the page's layout wholesale.
	 */
	function isModalBrowser( $browser ) {
		if ( $browser && $browser.length && $browser.closest( '.media-modal' ).length ) {
			return true;
		}
		/*
		 * Ancestry alone is not enough. Backbone renders an AttachmentsBrowser
		 * BEFORE inserting it into the modal, and our render patch runs at that
		 * moment: `closest('.media-modal')` is still empty, and the view would
		 * be mistaken for the page's own grid.
		 *
		 * So fall back on the screen. The media library page is the only place
		 * with a browser of its own; anywhere else, every attachments browser
		 * belongs to a picker. Appending into a view not yet inserted is fine,
		 * it travels with it.
		 */
		return ! document.body.classList.contains( 'upload-php' );
	}

	/*
	 * The attachments browser the user is actually looking at.
	 *
	 * A screen accumulates media modals: WordPress keeps each frame's markup
	 * after it closes, and switching to "Upload files" and back builds a second
	 * browser inside the same modal. Several are in the DOM, one is on screen,
	 * and the panel has to be in that one — being in any other means being
	 * invisible, which is how the panel silently went missing after a tab
	 * round-trip.
	 *
	 * So the target is re-derived from what is VISIBLE, at every trigger,
	 * rather than trusted from whichever view happened to render last. That is
	 * what makes this hold under an order of renders we do not control.
	 */
	function visibleModalBrowser() {
		var modals = document.querySelectorAll( '.media-modal' );
		for ( var i = 0; i < modals.length; i++ ) {
			if ( modals[ i ].getBoundingClientRect().height <= 0 ) { continue; }
			var browser = modals[ i ].querySelector( '.attachments-browser' );
			if ( browser ) { return $( browser ); }
		}
		return null;
	}

	// Re-assert the panel's place. Cheap and idempotent: safe to call often,
	// and on any trigger that might have moved the ground under it.
	function placePanelInVisibleModal() {
		var $browser = visibleModalBrowser();
		if ( $browser ) { injectPanel( $browser ); }
	}

	function injectPanel( $browser ) {
		if ( ! $browser || ! $browser.length ) { return; }
		var inModal = isModalBrowser( $browser );
		/*
		 * Mount the dy-has-folder-panel class on the browser on EVERY call (so
		 * Backbone re-renders that replace .attachments-browser still get the
		 * overflow:visible treatment), then short-circuit if the panel exists
		 * ANYWHERE in the document — not just inside this browser.
		 *
		 * Why anywhere : the panel is appended to <body>, not to $browser. Two
		 * reasons to keep it out of .attachments-browser :
		 *   1. Backbone re-renders attachments-browser by replacing its content
		 *      (this.$el.html(...)) — a panel that lived inside got destroyed,
		 *      our render-patch then re-injected a fresh one, and its arrival
		 *      animation fired again. That was the "second animation".
		 *   2. The .wrap curtain animates `transform: translateY(6px) → 0`.
		 *      Per spec, any transformed ancestor becomes the containing block
		 *      for position:fixed descendants, so during the ~460ms transform
		 *      transition the panel's left/top were measured from .wrap, not
		 *      the viewport. When the transform completed, it jumped. That was
		 *      the "mispositioned ghost" appearance.
		 * <body> has no transform and no Backbone-driven re-render, so both
		 * symptoms vanish.
		 *
		 * ALL OF THAT HOLDS FOR THE LIBRARY PAGE, AND ONLY THERE. Inside a
		 * picker modal the same choice is exactly wrong, and was the whole of
		 * what users saw as a broken integration:
		 *   · the panel kept the page's position:fixed, anchored to the WP
		 *     admin sidebar, so it landed wherever that sidebar happens to be
		 *     rather than against the modal;
		 *   · at z-index 100 under a modal at 160000 it was painted behind the
		 *     overlay, so the picker simply had no folders at all, while the
		 *     toolbar above still stepped aside for a panel that wasn't there;
		 *   · living on <body>, it outlived the modal and stayed on screen,
		 *     over the post editor, until the page was reloaded.
		 * In a modal the panel belongs INSIDE .attachments-browser: that box is
		 * position:relative, it is the containing block the modal CSS below
		 * expects, and it goes away when the modal does.
		 */
		$browser.addClass( 'dy-has-folder-panel' );

		// In a modal, the browser we were handed is not necessarily the one on
		// screen: a render can fire for a browser belonging to a closed modal.
		// What is visible wins.
		var $host     = inModal ? ( visibleModalBrowser() || $browser ) : $( 'body' );

		if ( inModal ) {
			/*
			 * Marks the modal as ours to dress. A dark folder panel bolted onto
			 * an otherwise stock white modal reads as unfinished, which is
			 * precisely how this looked. The class carries the theme in CSS.
			 *
			 * It is set from here rather than on every `.media-modal` so that a
			 * picker we have deliberately stepped away from (another folder
			 * plugin owning the media UI) keeps WordPress's own appearance.
			 */
			$host.closest( '.media-modal' ).addClass( 'dy-ml-modal' );
		}
		var $existing = $( '#dy-folder-panel' );

		if ( $existing.length ) {
			// Already built. If the context changed, MOVE it instead of
			// rebuilding: the loaded tree, the scroll position and every bound
			// handler come along, and no second REST round-trip is needed.
			if ( ! $existing.parent().is( $host ) ) {
				$existing.appendTo( $host );
			}
			return;
		}

		$host.append( buildPanel() );

		// Apply saved widths as CSS custom properties on body (panels are position:fixed,
		// .wrap padding reads vars too — body is the common ancestor for everything).
		var fw = localStorage.getItem( 'dy-fw' ) || '220';
		document.body.style.setProperty( '--dy-fw', fw + 'px' );

		$( '#dy-add-folder-btn' ).on( 'click', onAddFolder );
		$( '#dy-add-collection-btn' ).on( 'click', onAddCollection );

		if ( ! inModal ) {
			/*
			 * These four belong to the library PAGE and to nothing else: the
			 * relocated "Add Media" action, the foreign buttons lifted out of
			 * the page heading, the screen wordmark, and the drag-to-resize
			 * handle.
			 *
			 * A picker modal has its own chrome, its own title, and its own
			 * width (--dy-modal-fw, which follows the modal rather than a
			 * stored preference). Running any of this there is what put a large
			 * "Refined Media Library" wordmark across the post editor, and left
			 * a resize handle writing to a variable the modal ignores.
			 */
			relocateAddMediaButton();
			watchForeignActions();
			mountPanelBrand();
			initFolderResize();
		}

		loadFolderTree();
		loadCollections();
		// Retrofit pass: the frame has just been rendered; a new wp.Uploader
		// instance may have been created between our boot timers. This call
		// is idempotent (early-return + _dyBound).
		hookUploader();

		// Panel is in the DOM and the layout has been re-flowed — flip the
		// reveal curtain. Idempotent : the safety-net retry call to injectPanel
		// (400ms later) short-circuits at line 454 and never reaches here.
		markFrameReady();
	}

	/*
	 * Relocate the native "Add Media" action (the <a.page-title-action> WP
	 * emits in the heading row) up into the folder panel header as a sticky
	 * primary CTA. The panel itself is position: fixed, so the button stays
	 * visible regardless of scroll.
	 *
	 * Strategy : we CLONE the original anchor (preserving its href to
	 * media-new.php as a no-JS fallback), then forward clicks back to the
	 * original via .click(). The original keeps WP's bound handler (the
	 * .aria-button-if-js conversion that opens the upload modal) — we never
	 * have to re-implement that wiring. The original is hidden via the
	 * `dy-action-relocated` class which CSS picks up.
	 */
	function relocateAddMediaButton() {
		var $orig = $( '.wrap > .page-title-action' ).first();
		if ( ! $orig.length ) { return; }
		if ( $( '#dy-add-media-action' ).length ) { return; }

		// Read label + href from the original (cheap and locale-safe).
		var href  = $orig.attr( 'href' ) || '#';
		var label = $orig.text().trim();

		var $clone = $(
			'<a id="dy-add-media-action" class="dy-add-media-action" href="' + href + '" role="button">' +
				'<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>' +
				'<span class="dy-add-media-action__label">' + esc( label ) + '</span>' +
			'</a>'
		);

		// Forward clicks to the original — keeps WP's modal/upload handler
		// in charge of the actual upload flow. We swallow the default nav
		// only when the original handles it (preventDefault inside WP's
		// handler will still propagate to our event); otherwise the href
		// fallback takes over naturally.
		$clone.on( 'click', function ( e ) {
			e.preventDefault();
			$orig[ 0 ].click();
		} );

		// Mount at the very top of the panel, above the show-all chips.
		$( '#dy-folder-panel' ).prepend( $clone );

		// Hide the original. We don't `display: none` it because some WP
		// internals may still measure or focus it ; the class drives a
		// CSS-only collapse (`position: absolute; opacity: 0; pointer-
		// events: none`) so it's invisible but functional.
		$orig.addClass( 'dy-action-relocated' );
	}

	/*
	 * Sweep action buttons that OTHER plugins inject into the page heading row
	 * (WP convention: <a class="page-title-action"> as siblings of the <h1>,
	 * e.g. Elementor's "Generate with Elementor AI"). Our <h1> is visually
	 * hidden, so these leak under it and break the layout. We MOVE them (not
	 * clone — preserving their bound handlers) into a discreet group at the
	 * bottom of the panel, just above Settings.
	 *
	 * Idempotent. A MutationObserver + a couple of delayed sweeps catch buttons
	 * injected asynchronously (some plugins add theirs after DOM-ready).
	 */
	function relocateForeignActions() {
		var host = window.document.getElementById( 'dy-foreign-actions' );
		var wrap = window.document.querySelector( '.wrap' );
		if ( ! host || ! wrap ) { return; }

		var hr     = wrap.querySelector( 'hr.wp-header-end' );
		var strict = ! hr; // no header-end marker → only touch .page-title-action (safe)
		var kids   = Array.prototype.slice.call( wrap.children ); // static snapshot (we move during the loop)

		for ( var i = 0; i < kids.length; i++ ) {
			var el = kids[ i ];
			if ( hr && el === hr ) { break; }                                   // stop at the heading-row end
			if ( el === host || el.id === 'dy-add-media-action' ) { continue; }
			if ( el.tagName === 'H1' || el.classList.contains( 'wp-heading-inline' ) ) { continue; }
			if ( el.classList.contains( 'dy-action-relocated' ) ) { continue; } // our native "Add media" (hidden)

			var isAction;
			if ( strict ) {
				// No <hr class="wp-header-end"> boundary on this screen → only the
				// safe, explicit convention (a bare .page-title-action).
				isAction = el.classList.contains( 'page-title-action' );
			} else {
				// Heading row = everything before hr.wp-header-end. Relocate any
				// action element OR a wrapper that holds one — e.g. Elementor wraps
				// its "Generate with AI" link in <div id="e-image-ai-media-library">.
				isAction = el.classList.contains( 'page-title-action' ) ||
					el.tagName === 'A' || el.tagName === 'BUTTON' ||
					!! el.querySelector( 'a, button' );
			}
			if ( ! isAction ) { continue; }

			el.classList.add( 'dy-foreign-action' );
			host.appendChild( el ); // MOVE — keeps the element and its event handlers intact
			flattenForeignControl( el );
		}
	}

	/*
	 * Dissolve a relocated control's INNER boxes so only our outer .dy-foreign-action
	 * shows (no button-in-button). Third-party styles often live in a <style> rule
	 * (e.g. Elementor's Emotion class .css-…) that an external stylesheet can't
	 * reliably beat — so we strip the inner chrome with INLINE !important, which
	 * always wins. Wrappers and the link itself become display:contents so their
	 * icon + label flow into our single row (the link stays clickable).
	 */
	function flattenForeignControl( root ) {
		var kids = root.querySelectorAll( '*' );
		Array.prototype.forEach.call( kids, function ( n ) {
			var s      = n.style;
			var tag    = n.tagName;
			var isIcon = tag === 'I' || tag === 'IMG' || tag === 'SVG' ||
				( n.className && /icon/i.test( n.className.toString() ) );
			// Strip the element's own box chrome on every descendant.
			s.setProperty( 'background', 'transparent', 'important' );
			s.setProperty( 'border', '0', 'important' );
			s.setProperty( 'box-shadow', 'none', 'important' );
			s.setProperty( 'border-radius', '0', 'important' );
			s.setProperty( 'margin', '0', 'important' );
			s.setProperty( 'padding', '0', 'important' );
			if ( isIcon ) { return; } // keep the icon a box (sized by our CSS)
			s.setProperty( 'max-width', '100%', 'important' );
			s.setProperty( 'min-width', '0', 'important' );
			s.setProperty( 'width', 'auto', 'important' );
			// Any non-icon wrapper/control dissolves from layout (display:contents)
			// so its icon + text flow straight into our single row — universal,
			// regardless of how the third-party plugin nests its markup. Links and
			// buttons stay clickable when display:contents.
			s.setProperty( 'display', 'contents', 'important' );
		} );
	}

	var foreignActionsObserver = null;
	function watchForeignActions() {
		relocateForeignActions();
		if ( foreignActionsObserver || typeof window.MutationObserver === 'undefined' ) { return; }
		var wrap = window.document.querySelector( '.wrap' );
		if ( ! wrap ) { return; }
		// Catch buttons injected after our first pass (Elementor & co. add late).
		foreignActionsObserver = new window.MutationObserver( function () { relocateForeignActions(); } );
		foreignActionsObserver.observe( wrap, { childList: true } );
		window.setTimeout( relocateForeignActions, 600 );
		window.setTimeout( relocateForeignActions, 1800 );
	}

	/*
	 * ── Admin notices: give them one home ───────────────────────────────────
	 *
	 * WordPress leaves a notice wherever the plugin that printed it hooked in.
	 * Core relocates SOME of them into `.wrap`, where our CSS indents them past
	 * the folder panel. Everything else stays put: `all_admin_notices` output,
	 * `.update-nag` banners, and anything a plugin injects later with its own
	 * JS. Those land at the far left of the content column, which is exactly
	 * where the fixed "Refined Media Library" wordmark sits, so they print on
	 * top of it.
	 *
	 * Chasing every possible parent in CSS is a losing game: the next plugin
	 * invents a new one. Instead every notice is moved into a single container
	 * that is a direct child of `.wrap`, just before the media frame, so it
	 * inherits the very indentation the grid uses. One place to style, one
	 * place to reason about. A MutationObserver catches late arrivals.
	 *
	 * Refined Admin Dashboard owns notices when it is active (it collects them
	 * into its notices centre). Its dock is printed server-side, so it is in the
	 * DOM before any of this runs: if we see it, we stand down rather than
	 * fight over the same nodes.
	 */
	var NOTICE_SELECTOR = '.notice, div.updated, div.error, .update-nag';

	// Containers where a "notice" is a working part of the screen rather than a
	// message to the user. Moving those would break the feature that owns them
	// (upload errors live inside the media frame, for one).
	var NOTICE_SKIP = [
		'#dy-ml-notices',
		'.media-frame',
		'.media-modal',
		'.wp-list-table',
		'.postbox',
		'#screen-meta',
		'#screen-options-wrap',
		'#contextual-help-wrap',
		'.dy-notices-modal',
		'.dy-notice-dock'
	].join( ',' );

	var noticeHome = null;

	function noticesAreOwnedElsewhere() {
		return !! window.document.getElementById( 'dy-notice-dock' );
	}

	function getNoticeHome() {
		if ( noticeHome && noticeHome.isConnected ) { return noticeHome; }

		var wrap = window.document.querySelector( '.wrap' );
		if ( ! wrap ) { return null; }

		noticeHome = window.document.getElementById( 'dy-ml-notices' );
		if ( noticeHome ) { return noticeHome; }

		noticeHome = window.document.createElement( 'div' );
		noticeHome.id = 'dy-ml-notices';

		// Before the grid (or the list-mode form), never before the <h1>: the
		// heading stays the first thing a screen reader meets.
		var before = wrap.querySelector( ':scope > .media-frame, :scope > #posts-filter' );
		if ( before ) {
			wrap.insertBefore( noticeHome, before );
		} else {
			wrap.appendChild( noticeHome );
		}
		return noticeHome;
	}

	function isRelocatableNotice( el ) {
		if ( ! el || el.nodeType !== 1 ) { return false; }
		if ( el.getAttribute( 'data-dy-relocated' ) === '1' ) { return false; }
		// `.inline` is WordPress's own "leave me where I am" convention, used for
		// messages attached to a row or a field. `.update-nag` is the exception:
		// it is a full-width banner that happens to carry the class.
		if ( el.classList.contains( 'inline' ) && ! el.classList.contains( 'update-nag' ) ) { return false; }
		if ( el.closest( NOTICE_SKIP ) ) { return false; }
		return true;
	}

	function relocateNotices( scope ) {
		if ( noticesAreOwnedElsewhere() ) { return; }

		var root = scope && scope.nodeType === 1 ? scope : window.document.getElementById( 'wpbody-content' );
		if ( ! root ) { return; }

		var found = [];
		try {
			if ( root.matches && root.matches( NOTICE_SELECTOR ) ) { found.push( root ); }
			found = found.concat( [].slice.call( root.querySelectorAll( NOTICE_SELECTOR ) ) );
		} catch ( e ) {
			return;
		}

		var home = null;
		found.forEach( function ( el ) {
			if ( ! isRelocatableNotice( el ) ) { return; }
			home = home || getNoticeHome();
			if ( ! home ) { return; }
			el.setAttribute( 'data-dy-relocated', '1' );
			home.appendChild( el );
		} );

		syncNoticeHomeState();
	}

	/*
	 * The container may hold nothing but notices someone else hides
	 * (`.hide-if-js`, a plugin's own cross-sell banner we suppress), or the
	 * user may have dismissed the last visible one. CSS cannot ask "did any
	 * child end up visible", so the answer is published as an attribute.
	 */
	function syncNoticeHomeState() {
		if ( ! noticeHome || ! noticeHome.isConnected ) { return; }
		var anyVisible = [].slice.call( noticeHome.children ).some( function ( el ) {
			return el.offsetParent !== null;
		} );
		noticeHome.setAttribute( 'data-empty', anyVisible ? 'false' : 'true' );
	}

	function hookNoticeRelocation() {
		if ( noticesAreOwnedElsewhere() ) { return; }

		relocateNotices();

		// Dismissing the last visible notice must collapse the container too.
		$( window.document ).on( 'click.dyNotices', '#dy-ml-notices .notice-dismiss, #dy-ml-notices [data-dismiss]', function () {
			window.setTimeout( syncNoticeHomeState, 0 );
		} );

		// Plugins print notices from their own scripts, sometimes seconds in.
		if ( ! window.MutationObserver ) { return; }
		var host = window.document.getElementById( 'wpbody-content' );
		if ( ! host ) { return; }

		var pending = false;
		var observer = new window.MutationObserver( function ( records ) {
			if ( pending ) { return; }
			var worth = records.some( function ( r ) { return r.addedNodes && r.addedNodes.length; } );
			if ( ! worth ) { return; }
			pending = true;
			window.requestAnimationFrame( function () {
				pending = false;
				relocateNotices();
			} );
		} );
		observer.observe( host, { childList: true, subtree: true } );
	}

	/*
	 * Brand wordmark at the very top of the panel ("Refined Media Library",
	 * one word per line, "Refined" accented + italic). The native page <h1>
	 * is visually hidden (kept for screen readers), so this becomes the visual
	 * identity of the screen. Decorative → aria-hidden. Idempotent. Must run
	 * AFTER relocateAddMediaButton so it lands ABOVE the Add Media action.
	 */
	function mountPanelBrand() {
		if ( $( '#dy-panel-brand' ).length ) { return; }
		// Appended to <body> as its OWN fixed element (NOT inside #dy-folder-panel)
		// so it carries neither the panel background nor its right border. CSS pins
		// it at the panel's left/top; the panel starts 32px below it.
		$( 'body' ).append(
			'<div id="dy-panel-brand" aria-hidden="true">' +
				'<span class="dy-panel-brand__word dy-panel-brand__refined">Refined</span>' +
				'<span class="dy-panel-brand__word">Media Library</span>' +
			'</div>'
		);
		syncBrandHeight();
		if ( window.document.fonts && window.document.fonts.ready ) {
			window.document.fonts.ready.then( syncBrandHeight );
		}
		$( window ).on( 'resize', syncBrandHeight );
		watchAdminMenuWidth();
	}

	// Publish the brand's rendered height so the folder panel can sit exactly
	// 32px below it (CSS: top = adminbar + gutter + var(--dy-brand-h) + 32px).
	function syncBrandHeight() {
		var el = window.document.getElementById( 'dy-panel-brand' );
		if ( ! el ) { return; }
		window.document.body.style.setProperty( '--dy-brand-h', el.offsetHeight + 'px' );
	}

	/*
	 * ── WP admin sidebar width, measured rather than assumed ────────────────
	 *
	 * The panel and its brand are position:fixed and must sit just right of the
	 * WP admin rail. Hard-coding that offset (160px expanded / 36px folded) is
	 * wrong on a large share of real sites: admin-enhancer plugins (Ultimate
	 * Client Dash and friends) and plain site CSS routinely rewrite
	 * `#adminmenu, #adminmenuback, #adminmenuwrap { width: … }`. The rail then
	 * covers the panel and the whole screen reads as broken.
	 *
	 * So we publish the rail's real geometry as --dy-adminmenu-w; the CSS keeps
	 * 160/36px only as the no-JS fallback. We measure the rail's RIGHT edge, not
	 * its width: that also survives a rail which does not start at x=0.
	 *
	 * #adminmenuback is the painted rail (the one whose width those plugins set);
	 * the wrap and the menu itself are fallbacks for markup we have not seen.
	 */
	function adminMenuRail() {
		var doc = window.document;
		return doc.getElementById( 'adminmenuback' ) ||
			doc.getElementById( 'adminmenuwrap' ) ||
			doc.getElementById( 'adminmenu' );
	}

	function syncAdminMenuWidth() {
		var rail = adminMenuRail();
		if ( ! rail ) { return; }

		// Below 782px WP takes the rail off-canvas (hidden or translated out):
		// the measure reads 0 or negative. Leave the variable untouched — the
		// mobile media query already pins the panel to the gutter.
		var right = Math.round( rail.getBoundingClientRect().right );
		if ( right <= 0 ) { return; }

		window.document.body.style.setProperty( '--dy-adminmenu-w', right + 'px' );
	}

	/*
	 * ── Vertical anchor, measured the same way as the width ─────────────────
	 *
	 * The panel's top is computed from the admin bar (admin bar + gutter +
	 * brand + 32px). That holds on a native admin, where nothing sits between
	 * the admin bar and the media screen. Refined Admin Dashboard puts two
	 * rows there, its section bar and the page header, so a fixed panel lands
	 * on top of them.
	 *
	 * Rather than hard-code another product's chrome, we measure the row the
	 * panel should line up with: the media toolbar in grid view, the table nav
	 * in list view. Published as --dy-panel-top, which the CSS reads only when
	 * Refined Admin Dashboard is active.
	 */
	function contentAnchor() {
		return window.document.querySelector( '.media-toolbar, .tablenav.top, .wp-filter' );
	}

	function syncPanelTop() {
		var body = window.document.body;
		if ( ! body.classList.contains( 'dy-admin' ) ) { return; }
		var anchor = contentAnchor();
		if ( ! anchor ) { return; }
		// Document position, not viewport: the panel is fixed, and this must
		// not move when the page scrolls.
		// The reveal curtain lowers .wrap by up to 6px (translateY) while the
		// page composes, and the first measure runs during it. A transform
		// resizes nothing, so the ResizeObserver never corrects it: take the
		// curtain's offset out of the measure.
		var wrap = anchor.closest( '.wrap' );
		var lift = 0;
		if ( wrap && window.DOMMatrixReadOnly ) {
			var t = window.getComputedStyle( wrap ).transform;
			if ( t && 'none' !== t ) { lift = new window.DOMMatrixReadOnly( t ).m42; }
		}
		var top = Math.round( anchor.getBoundingClientRect().top - lift + ( window.pageYOffset || 0 ) );
		if ( top <= 0 ) { return; }
		body.style.setProperty( '--dy-panel-top', top + 'px' );
	}

	function watchAdminMenuWidth() {
		if ( adminMenuWatched ) { return; }
		adminMenuWatched = true;

		syncAdminMenuWidth();
		syncPanelTop();

		var rail = adminMenuRail();
		if ( rail && typeof window.ResizeObserver !== 'undefined' ) {
			// Covers everything at once: the "Collapse menu" transition, the
			// responsive auto-fold, and any plugin resizing the rail later.
			new window.ResizeObserver( syncAdminMenuWidth ).observe( rail );
		} else {
			// No ResizeObserver: the collapse toggle is the one width change we
			// can anticipate. Re-measure across its 0.2s transition.
			$( window.document ).on( 'click', '#collapse-menu', function () {
				var frames = 0;
				( function tick() {
					syncAdminMenuWidth();
					if ( ++frames < 24 ) { window.requestAnimationFrame( tick ); }
				} )();
			} );
		}

		$( window ).on( 'resize', function () {
			syncAdminMenuWidth();
			syncPanelTop();
		} );

		// The header above us grows and shrinks (a notice appears, the title
		// wraps): re-measure whenever the content area changes height.
		var anchored = contentAnchor();
		if ( anchored && typeof window.ResizeObserver !== 'undefined' ) {
			new window.ResizeObserver( syncPanelTop ).observe( window.document.body );
		}
		$( window ).on( 'load', syncPanelTop );
	}

	/*
	 * Panel DOM structure:
	 *
	 *  #dy-folder-panel
	 *  ├── #dy-folder-panel-header  (flex-shrink: 0)
	 *  ├── #dy-folder-tree          (flex: 2 — scrollable, takes 2/3 of space)
	 *  ├── .dy-collections-section  (flex: 1 — always visible, takes 1/3)
	 *  │   ├── #dy-collection-panel-header
	 *  │   └── #dy-collection-list (scrollable)
	 *  └── .dy-rh-right             (resize handle, position absolute)
	 */
	function buildPanel() {
		// "+" add button only rendered when the section is enabled.
		var addFolderBtn = foldersEnabled
			? '<button type="button" id="dy-add-folder-btn" title="' + esc( i18n.newFolder ) + '">+</button>'
			: '';
		var addCollectionBtn = collectionsEnabled
			? '<button type="button" id="dy-add-collection-btn" title="' + esc( i18n.newCollection ) + '">+</button>'
			: '';
		// Initial content: "disabled" message when the section is off (the
		// loaders short-circuit early and do not overwrite it).
		// "Disabled" message + "Enable" link (re-enables the option in one click).
		function disabledMsg( label, feature ) {
			var link = ajaxUrl
				? ' <a href="#" class="dy-panel-enable" data-feature="' + feature + '">' + esc( i18n.enable ) + '</a>'
				: '';
			return '<p class="dy-panel-disabled">' + esc( label ) + link + '</p>';
		}
		var folderTreeInit     = foldersEnabled ? '' : disabledMsg( i18n.foldersDisabled, 'folders' );
		var collectionListInit = collectionsEnabled ? '' : disabledMsg( i18n.collectionsDisabled, 'collections' );
		// Show-orphans (= media without a folder) only makes sense when Folders is enabled.
		var showOrphans = foldersEnabled
			? '<div id="dy-show-orphans" class="dy-show-all" data-id="-1">' +
					'<span class="dashicons dashicons-minus"></span>' +
					'<span class="dy-show-all__label">' + esc( i18n.showOrphans ) + '</span>' +
					// Left empty on purpose: filled by loadPanelCounts() once the
					// real number is known. A placeholder zero would be a wrong
					// number on screen for as long as the request takes.
					'<span class="dy-show-all__count" aria-hidden="true"></span>' +
				'</div>'
			: '';
		// Panel footer: "Settings" link → RML settings page.
		var settingsFooter = settingsUrl
			? '<a class="dy-panel-settings" href="' + esc( settingsUrl ) + '">' +
					'<span class="dashicons dashicons-admin-generic"></span>' +
					'<span class="dy-panel-settings__label">' + esc( i18n.panelSettings ) + '</span>' +
				'</a>'
			: '';

		return $(
			'<div id="dy-folder-panel"' +
				( foldersEnabled ? '' : ' class="dy-folders-off"' ) + '>' +
				// Show-all + show-orphans quick filters at top
				'<div id="dy-show-all" class="dy-show-all" data-id="0">' +
					'<span class="dashicons dashicons-admin-media"></span>' +
					'<span class="dy-show-all__label">' + esc( i18n.showAll ) + '</span>' +
					'<span class="dy-show-all__count" aria-hidden="true"></span>' +
				'</div>' +
				showOrphans +

				// Folders section
				'<div id="dy-folder-panel-header">' +
					'<span class="dy-panel-label">' + esc( i18n.foldersLabel ) + '</span>' +
					addFolderBtn +
				'</div>' +
				'<div id="dy-folder-tree">' + folderTreeInit + '</div>' +

				// Collections section
				'<div class="dy-collections-section">' +
					'<div id="dy-collection-panel-header">' +
						'<span class="dy-panel-label">' + esc( i18n.collections ) + '</span>' +
						addCollectionBtn +
					'</div>' +
					'<div id="dy-collection-list">' + collectionListInit + '</div>' +
				'</div>' +

				// Container for action buttons relocated from other plugins
				// (filled by relocateForeignActions(); stays empty/hidden otherwise).
				'<div id="dy-foreign-actions" class="dy-foreign-actions"></div>' +
				settingsFooter +
				'<div class="dy-rh-right" id="dy-rh-folder"></div>' +
			'</div>'
		);
	}

	// "Enable" link on a disabled section: re-enables the option then reloads the page.
	$( document ).on( 'click', '.dy-panel-enable', function ( e ) {
		e.preventDefault();
		if ( ! ajaxUrl || ! enableNonce ) { return; }
		var feature = $( this ).attr( 'data-feature' );
		var $link   = $( this ).addClass( 'is-loading' );
		$.post( ajaxUrl, { action: 'doubleyoupi_ml_enable_feature', nonce: enableNonce, feature: feature } )
			.done( function ( res ) {
				if ( res && res.success ) { window.location.reload(); }
				else { $link.removeClass( 'is-loading' ); }
			} )
			.fail( function () { $link.removeClass( 'is-loading' ); } );
	} );

	// ── Folder tree ───────────────────────────────────────────────────────────

	/*
	 * Counts for "Show all media" and "Media without folder".
	 *
	 * These two entries are views like any other, and they were the only ones
	 * without a number. The badge is the panel's existing one, reused: a count
	 * in parentheses would have been a third way of writing the same thing,
	 * after the folder badge and the page's own heading.
	 *
	 * Kept silent on failure. A stale number is worse than no number, so the
	 * badge simply empties rather than keeping a figure we can no longer vouch
	 * for.
	 */
	function loadPanelCounts() {
		/*
		 * The badges are looked up when the ANSWER comes back, never before.
		 *
		 * In a picker modal the panel is built inside an attachments browser
		 * that Backbone has not inserted into the page yet. A lookup at call
		 * time searches the document and finds nothing, and an early return
		 * there means the request is never sent at all: the counts stayed
		 * blank in every modal while working on the library page. By the time
		 * the fetch resolves the browser is in place.
		 */
		var ecrire = function ( total, sansDossier ) {
			var $all     = $( '#dy-show-all .dy-show-all__count' );
			var $orphans = $( '#dy-show-orphans .dy-show-all__count' );
			$all.text( total );
			$orphans.text( sansDossier );
			return $all.length || $orphans.length;
		};

		window.fetch( restBase + '/counts', { headers: { 'X-WP-Nonce': nonce } } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( c ) {
				if ( ! c || typeof c.total !== 'number' ) { throw new Error( 'bad payload' ); }
				// One retry: on a slow panel the answer can still beat the markup.
				if ( ! ecrire( c.total, c.unassigned ) ) {
					setTimeout( function () { ecrire( c.total, c.unassigned ); }, 400 );
				}
			} )
			.catch( function () {
				// A stale number is worse than none: empty the badges rather
				// than keep a figure we can no longer vouch for.
				ecrire( '', '' );
			} );
	}

	function loadFolderTree() {
		/*
		 * Deliberately before the folders guard, and inside this function rather
		 * than beside its dozen call sites.
		 *
		 * Every mutation that can move these totals already ends here: a file
		 * dropped on a folder, a folder deleted, an upload (through
		 * dyRefreshCountsSoon), a change from the details pane (through
		 * window.doubleyoupiReloadFolders). Hanging the counts off the same point means
		 * they inherit that whole web for free, instead of being refreshed at
		 * ten sites and forgotten at the eleventh.
		 *
		 * Before the guard because the total on "Show all media" stands even
		 * when the Folders section is switched off.
		 */
		loadPanelCounts();
		if ( ! foldersEnabled ) { return; }
		window.fetch( restBase, { headers: { 'X-WP-Nonce': nonce } } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( tree ) {
				dyState.folders = flattenTree( tree );
				renderTree( tree );
			} )
			.catch( function () {
				$( '#dy-folder-tree' ).html( '<p class="dy-panel-empty">' + esc( i18n.couldNotLoadFolders ) + '</p>' );
			} );
	}

	function flattenTree( nodes, result ) {
		result = result || [];
		( nodes || [] ).forEach( function ( n ) {
			result.push( { id: n.id, name: n.name, parent: n.parent } );
			if ( n.children && n.children.length ) { flattenTree( n.children, result ); }
		} );
		return result;
	}

	function renderTree( tree ) {
		var $t = $( '#dy-folder-tree' );
		if ( ! $t.length ) { return; }
		$t.html( '' );

		if ( tree && tree.length ) {
			renderNodes( tree, $t, 0 );
		} else {
			$t.html( '<p class="dy-panel-empty">' + esc( i18n.noFoldersYet ) + '</p>' );
		}

		markFolderActive( dyState.currentFolder );
		markShowAllActive();
	}

	function markShowAllActive() {
		var isAll     = dyState.currentFolder === 0  && dyState.currentCollection === null;
		var isOrphans = dyState.currentFolder === -1 && dyState.currentCollection === null;
		$( '#dy-show-all' ).toggleClass( 'is-active', isAll );
		$( '#dy-show-orphans' ).toggleClass( 'is-active', isOrphans );
	}

	function renderNodes( nodes, $cont, depth ) {
		nodes.slice().sort( function ( a, b ) { return a.order - b.order; } ).forEach( function ( node ) {
			var pad     = 14 + depth * 20;
			var hasKids = node.children && node.children.length;
			// No spacer for folders without children — only show toggle when needed.
			// Children render expanded by default, so the chevron starts open too.
			var toggle  = hasKids
				? '<span class="dy-folder-toggle dashicons dashicons-arrow-right-alt2 is-open" data-id="' + node.id + '"></span>'
				: '';

			var $item = $(
				'<div class="dy-folder-item" data-id="' + node.id + '" style="padding-left:' + pad + 'px">' +
					toggle +
					'<span class="dy-folder-item__icon dashicons dashicons-category"></span>' +
					'<span class="dy-folder-item__name">' + esc( node.name ) + '</span>' +
					'<span class="dy-folder-item__count">' + node.count + '</span>' +
					'<button class="dy-item-menu-btn dy-folder-menu-btn" type="button" aria-haspopup="true" aria-label="' + esc( i18n.moreActions ) + '">' +
						'<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="19" cy="12" r="1.7"/></svg>' +
					'</button>' +
				'</div>'
			);

			$cont.append( $item );
			makeFolderDropTarget( $item, node.id );

			if ( hasKids ) {
				// --dy-rail-x positions the guide rail under THIS folder's chevron
				// (chevron center ≈ pad + 7), so it descends toward the children.
				var railX = pad + 7;
				var $kids = $( '<div class="dy-folder-children" data-parent="' + node.id + '" style="--dy-rail-x:' + railX + 'px">' );
				renderNodes( node.children, $kids, depth + 1 );
				$cont.append( $kids );
			}
		} );
	}

	function markFolderActive( id ) {
		$( '.dy-folder-item' ).removeClass( 'is-active' );
		if ( id > 0 ) {
			$( '.dy-folder-item[data-id="' + id + '"]' ).addClass( 'is-active' );
		}
		markShowAllActive();
	}

	// ── Folder interactions ───────────────────────────────────────────────────

	$( document ).on( 'click', '#dy-folder-tree .dy-folder-item', function ( e ) {
		if ( $( e.target ).closest( '.dy-folder-actions, .dy-folder-toggle' ).length ) { return; }
		selectFolder( parseInt( $( this ).data( 'id' ), 10 ) );
	} );

	$( document ).on( 'click', '#dy-show-all', function () {
		selectFolder( 0 );
	} );

	$( document ).on( 'click', '#dy-show-orphans', function () {
		selectFolder( -1 );
	} );

	$( document ).on( 'click', '#dy-folder-tree .dy-folder-toggle', function ( e ) {
		e.stopPropagation();
		var id      = $( this ).data( 'id' );
		var $kids   = $( '.dy-folder-children[data-parent="' + id + '"]' );
		var wasOpen = $( this ).hasClass( 'is-open' );
		$( this ).toggleClass( 'is-open', ! wasOpen );
		$kids.toggle( ! wasOpen );
	} );

	function selectFolder( id ) {

		// In list mode, navigate to URL-filtered list view (server-side filter).
		if ( isListMode() ) {
			navigateListView( { doubleyoupi_folder: id } );
			return;
		}

		dyState.currentFolder     = id;
		dyState.currentCollection = null;
		markFolderActive( id );
		markCollectionActive( null );
		refreshAttachments();
		patchViewSwitchLinks();
	}

	// ── Folder drag: re-parent (drop INTO) + reorder (drop BETWEEN) ────────────
	//
	// Distinct from media drag (which uses `application/x-dy-media` + the
	// document-level doubleyoupiDragIds dispatch). Folder drag keys on `folderDrag`.

	var folderDrag      = null; // { id, el }
	var autoExpandTimer = null;
	var autoExpandId    = null;

	function idOf( el ) { return parseInt( el.getAttribute( 'data-id' ), 10 ) || 0; }

	// Direct child .dy-folder-item elements of a given parent (0 = root).
	function childFolderItems( parentId ) {
		var container = parentId > 0
			? document.querySelector( '.dy-folder-children[data-parent="' + parentId + '"]' )
			: document.getElementById( 'dy-folder-tree' );
		if ( ! container ) { return []; }
		return Array.prototype.slice.call( container.children ).filter( function ( c ) {
			return c.classList && c.classList.contains( 'dy-folder-item' );
		} );
	}

	function folderParentOf( itemEl ) {
		var p = itemEl.parentElement;
		if ( p && p.classList.contains( 'dy-folder-children' ) ) {
			return parseInt( p.getAttribute( 'data-parent' ), 10 ) || 0;
		}
		return 0;
	}

	// A folder can't be dropped into itself or one of its own descendants.
	function isForbiddenFolderTarget( target ) {
		if ( ! folderDrag ) { return true; }
		if ( target === folderDrag.el ) { return true; }
		var kids = folderDrag.el.nextElementSibling;
		if ( kids && kids.classList.contains( 'dy-folder-children' ) &&
			kids.getAttribute( 'data-parent' ) === String( folderDrag.id ) ) {
			return kids.contains( target );
		}
		return false;
	}

	function folderDropIntent( target, clientY ) {
		var rect = target.getBoundingClientRect();
		var y    = clientY - rect.top;
		if ( y < rect.height * 0.30 ) { return 'before'; }
		if ( y > rect.height * 0.70 ) { return 'after'; }
		return 'into';
	}

	function clearFolderDropIndicator() {
		$( '#dy-folder-tree .dy-folder-item' ).removeClass( 'dy-drop-into dy-drop-before dy-drop-after' );
		$( '#dy-show-all' ).removeClass( 'dy-drop-into' );
	}

	function cancelAutoExpand() {
		if ( autoExpandTimer ) { clearTimeout( autoExpandTimer ); autoExpandTimer = null; }
		autoExpandId = null;
	}

	// Expand a collapsed folder after hovering it ~0.7s while dragging into it.
	function scheduleAutoExpand( target, targetId, intent ) {
		if ( intent !== 'into' ) { cancelAutoExpand(); return; }
		if ( autoExpandId === targetId ) { return; }
		cancelAutoExpand();
		var toggle = target.querySelector( '.dy-folder-toggle' );
		var $kids  = $( '.dy-folder-children[data-parent="' + targetId + '"]' );
		if ( ! toggle || ! $kids.length || $kids.is( ':visible' ) ) { return; }
		autoExpandId    = targetId;
		autoExpandTimer = setTimeout( function () {
			$( toggle ).addClass( 'is-open' );
			$kids.show();
			autoExpandTimer = null;
		}, 700 );
	}

	function persistFolderReorder( parentId, ids ) {
		apiFetch( restBase + '/reorder', 'POST', { parent_id: parentId, ids: ids } )
			.then( function () { loadFolderTree(); } )
			.catch( function () {
				showToast( i18n.folderMoveFailed || 'Move failed', 'error' );
				loadFolderTree();
			} );
	}

	function applyFolderMove( draggedId, target, intent ) {
		var targetId = idOf( target );
		var newParent, ids;
		if ( intent === 'into' ) {
			newParent = targetId;
			ids = childFolderItems( targetId ).map( idOf ).filter( function ( x ) { return x !== draggedId; } );
			ids.push( draggedId );
		} else {
			newParent = folderParentOf( target );
			ids = childFolderItems( newParent ).map( idOf ).filter( function ( x ) { return x !== draggedId; } );
			var ti       = ids.indexOf( targetId );
			var insertAt = intent === 'before' ? ti : ti + 1;
			ids.splice( insertAt < 0 ? ids.length : insertAt, 0, draggedId );
		}
		persistFolderReorder( newParent, ids );
	}

	$( document ).on( 'mouseenter', '#dy-folder-tree .dy-folder-item', function () {
		if ( ! this.getAttribute( 'draggable' ) ) { this.setAttribute( 'draggable', 'true' ); }
	} );

	$( document ).on( 'dragstart', '#dy-folder-tree .dy-folder-item', function ( e ) {
		var id = idOf( this );
		if ( ! id ) { return; }
		folderDrag = { id: id, el: this };
		var dt = e.originalEvent.dataTransfer;
		// Custom type (not "Files") so WP's uploader overlay doesn't treat this as
		// a file drop; clearData first, like the media drag does.
		try { dt.clearData(); } catch ( _e ) {}
		try {
			dt.setData( 'application/x-dy-folder', String( id ) );
			dt.effectAllowed = 'move';
		} catch ( err ) {}
		$( 'body' ).addClass( 'dy-dragging-folder' );
		this.classList.add( 'dy-folder-dragging' );
	} );

	// Clear ALL folder-drag state. Called on dragend AND on drop — the drop
	// triggers loadFolderTree(), which re-renders the panel, so clearing here
	// guarantees the dragged item's faded state is reset even if dragend is
	// missed when its element is replaced.
	function endFolderDrag() {
		clearFolderDropIndicator();
		if ( folderDrag && folderDrag.el ) { folderDrag.el.classList.remove( 'dy-folder-dragging' ); }
		folderDrag = null;
		$( 'body' ).removeClass( 'dy-dragging-folder' );
		cancelAutoExpand();
	}

	$( document ).on( 'dragend', '#dy-folder-tree .dy-folder-item', endFolderDrag );

	$( document ).on( 'dragover', '#dy-folder-tree .dy-folder-item', function ( e ) {
		if ( ! folderDrag ) { return; }
		e.preventDefault();
		e.originalEvent.dataTransfer.dropEffect = 'move';
		if ( isForbiddenFolderTarget( this ) ) { clearFolderDropIndicator(); cancelAutoExpand(); return; }
		var intent = folderDropIntent( this, e.originalEvent.clientY );
		clearFolderDropIndicator();
		$( this ).addClass( 'dy-drop-' + intent );
		scheduleAutoExpand( this, idOf( this ), intent );
	} );

	$( document ).on( 'drop', '#dy-folder-tree .dy-folder-item', function ( e ) {
		if ( ! folderDrag ) { return; }
		e.preventDefault();
		e.stopPropagation();
		var draggedId = folderDrag.id;
		if ( ! isForbiddenFolderTarget( this ) ) {
			applyFolderMove( draggedId, this, folderDropIntent( this, e.originalEvent.clientY ) );
		}
		endFolderDrag();
	} );

	// Drop onto "Show all media" → move to the root.
	$( document ).on( 'dragover', '#dy-show-all', function ( e ) {
		if ( ! folderDrag ) { return; }
		e.preventDefault();
		e.originalEvent.dataTransfer.dropEffect = 'move';
		clearFolderDropIndicator();
		$( '#dy-show-all' ).addClass( 'dy-drop-into' );
	} );

	$( document ).on( 'drop', '#dy-show-all', function ( e ) {
		if ( ! folderDrag ) { return; }
		e.preventDefault();
		e.stopPropagation();
		var ids = childFolderItems( 0 ).map( idOf ).filter( function ( x ) { return x !== folderDrag.id; } );
		ids.push( folderDrag.id );
		persistFolderReorder( 0, ids );
		endFolderDrag();
	} );

	// ── Folder CRUD ───────────────────────────────────────────────────────────

	// parentId optional — defaults to the currently open folder (used by the
	// context menu's "New subfolder", which passes an explicit parent).
	function onAddFolder( parentId ) {
		var parent = ( typeof parentId === 'number' )
			? parentId
			: ( dyState.currentFolder > 0 ? dyState.currentFolder : 0 );
		window.doubleyoupiDialog.prompt( {
			title:        i18n.newFolder,
			label:        i18n.folderName,
			confirmLabel: i18n.create,
			cancelLabel:  i18n.cancel
		} ).then( function ( name ) {
			if ( ! name || ! name.trim() ) { return; }
			apiFetch( restBase, 'POST', {
				name:      name.trim(),
				parent_id: parent,
			} ).then( function () { loadFolderTree(); } );
		} );
	}

	function renameFolder( id, current ) {
		window.doubleyoupiDialog.prompt( {
			title:        i18n.rename,
			label:        i18n.folderName,
			value:        current,
			confirmLabel: i18n.rename,
			cancelLabel:  i18n.cancel
		} ).then( function ( name ) {
			if ( ! name || ! name.trim() || name.trim() === current ) { return; }
			apiFetch( restBase + '/' + id, 'PUT', { name: name.trim() } ).then( function () { loadFolderTree(); } );
		} );
	}

	function deleteFolder( id, name ) {
		window.doubleyoupiDialog.confirm( {
			title:        i18n.delete,
			message:      fmt( i18n.deleteFolderConfirm, name ),
			confirmLabel: i18n.deleteAction,
			cancelLabel:  i18n.cancel,
			danger:       true
		} ).then( function ( ok ) {
			if ( ! ok ) { return; }
			apiFetch( restBase + '/' + id, 'DELETE' ).then( function () {
				if ( dyState.currentFolder === id ) { selectFolder( 0 ); }
				loadFolderTree();
				refreshAttachments();
				showToast( fmt( i18n.folderDeleted, name ), 'success' );
			} ).catch( function () {
				showToast( i18n.folderDeleteFailed, 'error' );
			} );
		} );
	}

	// ── Context menu (right-click / ⋯ button on folders & collections) ────────

	var dyMenuEl = null;

	function closeContextMenu() {
		if ( ! dyMenuEl ) { return; }
		if ( dyMenuEl.parentNode ) { dyMenuEl.parentNode.removeChild( dyMenuEl ); }
		dyMenuEl = null;
		document.removeEventListener( 'mousedown', onMenuOutside, true );
		document.removeEventListener( 'keydown', onMenuKey, true );
		window.removeEventListener( 'scroll', closeContextMenu, true );
		window.removeEventListener( 'resize', closeContextMenu, true );
	}

	function onMenuOutside( e ) {
		if ( dyMenuEl && ! dyMenuEl.contains( e.target ) ) { closeContextMenu(); }
	}

	function onMenuKey( e ) {
		if ( ! dyMenuEl ) { return; }
		var items = Array.prototype.slice.call( dyMenuEl.querySelectorAll( '.dy-ctx-menu__item' ) );
		if ( ! items.length ) { return; }
		var idx = items.indexOf( document.activeElement );
		if ( e.key === 'Escape' ) { e.preventDefault(); closeContextMenu(); }
		else if ( e.key === 'ArrowDown' ) { e.preventDefault(); items[ ( idx + 1 ) % items.length ].focus(); }
		else if ( e.key === 'ArrowUp' ) { e.preventDefault(); items[ ( idx - 1 + items.length ) % items.length ].focus(); }
	}

	// items: [{ label, icon (dashicon slug), danger, onClick }]
	function openContextMenu( items, x, y ) {
		closeContextMenu();
		var menu = document.createElement( 'div' );
		menu.className = 'dy-ctx-menu';
		menu.setAttribute( 'role', 'menu' );
		items.forEach( function ( it ) {
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'dy-ctx-menu__item' + ( it.danger ? ' is-danger' : '' );
			b.setAttribute( 'role', 'menuitem' );
			b.innerHTML = '<span class="dy-ctx-menu__icon dashicons dashicons-' + it.icon + '"></span>' +
				'<span class="dy-ctx-menu__label">' + esc( it.label ) + '</span>';
			b.addEventListener( 'click', function ( ev ) { ev.stopPropagation(); closeContextMenu(); it.onClick(); } );
			menu.appendChild( b );
		} );
		document.body.appendChild( menu );

		// Keep the menu inside the viewport; scale-in from the nearest corner.
		var mw = menu.offsetWidth;
		var mh = menu.offsetHeight;
		var px = Math.max( 8, Math.min( x, window.innerWidth - mw - 8 ) );
		var py = Math.max( 8, Math.min( y, window.innerHeight - mh - 8 ) );
		menu.style.left = px + 'px';
		menu.style.top  = py + 'px';
		menu.style.transformOrigin = ( py < y ? 'bottom' : 'top' ) + ' ' + ( px < x ? 'right' : 'left' );

		dyMenuEl = menu;
		requestAnimationFrame( function () { menu.classList.add( 'is-open' ); } );
		var first = menu.querySelector( '.dy-ctx-menu__item' );
		if ( first ) { first.focus(); }

		document.addEventListener( 'mousedown', onMenuOutside, true );
		document.addEventListener( 'keydown', onMenuKey, true );
		window.addEventListener( 'scroll', closeContextMenu, true );
		window.addEventListener( 'resize', closeContextMenu, true );
	}

	function openFolderMenu( id, name, x, y ) {
		openContextMenu( [
			{ label: i18n.newSubfolder, icon: 'plus-alt2', onClick: function () { onAddFolder( id ); } },
			{ label: i18n.rename, icon: 'edit', onClick: function () { renameFolder( id, name ); } },
			{ label: i18n.moveTo, icon: 'move', onClick: function () { openMovePicker( id, x, y ); } },
			{ label: i18n.delete, icon: 'trash', danger: true, onClick: function () { deleteFolder( id, name ); } }
		], x, y );
	}

	// Keyboard-accessible alternative to drag-reparenting: a picker of valid
	// destinations (excludes the folder itself + its descendants — no cycles).
	function descendantIds( folderId ) {
		var byParent = {};
		( dyState.folders || [] ).forEach( function ( f ) {
			( byParent[ f.parent ] = byParent[ f.parent ] || [] ).push( f.id );
		} );
		var out = [];
		var stack = [ folderId ];
		while ( stack.length ) {
			( byParent[ stack.pop() ] || [] ).forEach( function ( c ) { out.push( c ); stack.push( c ); } );
		}
		return out;
	}

	function moveFolderTo( folderId, parentId ) {
		apiFetch( restBase + '/' + folderId, 'PUT', { parent_id: parentId } )
			.then( function () { loadFolderTree(); } )
			.catch( function () { showToast( i18n.folderMoveFailed || 'Move failed', 'error' ); } );
	}

	function openMovePicker( folderId, x, y ) {
		var exclude = {};
		exclude[ folderId ] = true;
		descendantIds( folderId ).forEach( function ( d ) { exclude[ d ] = true; } );

		var byParent = {};
		( dyState.folders || [] ).forEach( function ( f ) {
			( byParent[ f.parent ] = byParent[ f.parent ] || [] ).push( f );
		} );

		var items = [ { label: i18n.topLevel || 'Top level', icon: 'admin-home', onClick: function () { moveFolderTo( folderId, 0 ); } } ];
		( function walk( parent, depth ) {
			( byParent[ parent ] || [] ).forEach( function ( f ) {
				if ( exclude[ f.id ] ) { return; } // skip self/descendants (and their subtree)
				var indent = depth > 0 ? new Array( depth + 1 ).join( ' ' ) : '';
				items.push( { label: indent + f.name, icon: 'category', onClick: function () { moveFolderTo( folderId, f.id ); } } );
				walk( f.id, depth + 1 );
			} );
		} )( 0, 0 );

		openContextMenu( items, x, y );
	}

	function openCollectionMenu( id, name, x, y ) {
		openContextMenu( [
			{ label: i18n.rename, icon: 'edit', onClick: function () { renameCollection( id, name ); } },
			{ label: i18n.deleteCollection, icon: 'trash', danger: true, onClick: function () { deleteCollection( id, name ); } }
		], x, y );
	}

	// Folder triggers: ⋯ button + right-click.
	$( document ).on( 'click', '#dy-folder-tree .dy-folder-menu-btn', function ( e ) {
		e.stopPropagation();
		e.preventDefault();
		var $item = $( this ).closest( '.dy-folder-item' );
		var r = this.getBoundingClientRect();
		openFolderMenu( parseInt( $item.data( 'id' ), 10 ), $item.find( '.dy-folder-item__name' ).text() || i18n.thisFolder, r.right, r.bottom + 4 );
	} );
	$( document ).on( 'contextmenu', '#dy-folder-tree .dy-folder-item', function ( e ) {
		e.preventDefault();
		var $item = $( this );
		openFolderMenu( parseInt( $item.data( 'id' ), 10 ), $item.find( '.dy-folder-item__name' ).text() || i18n.thisFolder, e.clientX, e.clientY );
	} );

	// ── Collections ───────────────────────────────────────────────────────────

	function loadCollections() {
		if ( ! collectionsEnabled ) { return; }
		window.fetch( restCollections, { headers: { 'X-WP-Nonce': nonce } } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( cols ) {
				dyState.collections = cols;
				renderCollections( cols );
			} )
			.catch( function () {
				$( '#dy-collection-list' ).html( '<p class="dy-panel-empty">' + esc( i18n.couldNotLoadCollections ) + '</p>' );
			} );
	}

	function renderCollections( cols ) {
		var $list = $( '#dy-collection-list' );
		if ( ! $list.length ) { return; }
		$list.html( '' );

		if ( ! cols || ! cols.length ) {
			$list.html( '<p class="dy-panel-empty">' + esc( i18n.noCollectionsYet ) + '</p>' );
			return;
		}

		cols.forEach( function ( col ) {
			var $item = $(
				'<div class="dy-collection-item" data-id="' + col.id + '" style="padding-left:14px">' +
					'<span class="dy-collection-item__icon dashicons dashicons-star-filled"></span>' +
					'<span class="dy-collection-item__name">' + esc( col.name ) + '</span>' +
					'<span class="dy-collection-item__count">' + col.count + '</span>' +
					'<button class="dy-item-menu-btn dy-collection-menu-btn" type="button" aria-haspopup="true" aria-label="' + esc( i18n.moreActions ) + '">' +
						'<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="19" cy="12" r="1.7"/></svg>' +
					'</button>' +
				'</div>'
			);
			makeCollectionDropTarget( $item, col.id );
			$list.append( $item );
		} );

		markCollectionActive( dyState.currentCollection );
	}

	function markCollectionActive( id ) {
		$( '.dy-collection-item' ).removeClass( 'is-active' );
		if ( id !== null ) {
			$( '.dy-collection-item[data-id="' + id + '"]' ).addClass( 'is-active' );
		}
		markShowAllActive();
	}

	$( document ).on( 'click', '#dy-collection-list .dy-collection-item', function ( e ) {
		if ( $( e.target ).closest( '.dy-folder-actions' ).length ) { return; }
		selectCollection( parseInt( $( this ).data( 'id' ), 10 ) );
	} );

	function selectCollection( id ) {

		// In list mode, navigate to URL-filtered list view.
		if ( isListMode() ) {
			navigateListView( { doubleyoupi_collection: id } );
			return;
		}

		dyState.currentCollection = id;
		dyState.currentFolder     = 0;
		markCollectionActive( id );
		markFolderActive( null );
		patchViewSwitchLinks();
		refreshAttachments();
	}

	// ── Collection CRUD ───────────────────────────────────────────────────────

	function onAddCollection() {
		window.doubleyoupiDialog.prompt( {
			title:        i18n.newCollection || 'New collection',
			label:        i18n.collectionName,
			confirmLabel: i18n.create,
			cancelLabel:  i18n.cancel
		} ).then( function ( name ) {
			if ( ! name || ! name.trim() ) { return; }
			apiFetch( restCollections, 'POST', { name: name.trim() } )
				.then( function () { loadCollections(); } );
		} );
	}

	function renameCollection( id, current ) {
		window.doubleyoupiDialog.prompt( {
			title:        i18n.renameCollection || 'Rename',
			label:        i18n.collectionName,
			value:        current,
			confirmLabel: i18n.rename,
			cancelLabel:  i18n.cancel
		} ).then( function ( name ) {
			if ( ! name || ! name.trim() || name.trim() === current ) { return; }
			apiFetch( restCollections + '/' + id, 'PUT', { name: name.trim() } )
				.then( function () { loadCollections(); } );
		} );
	}

	function deleteCollection( id, name ) {
		window.doubleyoupiDialog.confirm( {
			title:        i18n.deleteCollection || 'Delete collection',
			message:      fmt( i18n.deleteCollectionConfirm, name ),
			confirmLabel: i18n.deleteAction,
			cancelLabel:  i18n.cancel,
			danger:       true
		} ).then( function ( ok ) {
			if ( ! ok ) { return; }
			apiFetch( restCollections + '/' + id, 'DELETE' ).then( function () {
				if ( dyState.currentCollection === id ) { selectCollection( null ); }
				loadCollections();
				showToast( fmt( i18n.collectionDeleted, name ), 'success' );
			} ).catch( function () {
				showToast( i18n.collectionDeleteFailed, 'error' );
			} );
		} );
	}

	// Collection triggers: ⋯ button + right-click.
	$( document ).on( 'click', '#dy-collection-list .dy-collection-menu-btn', function ( e ) {
		e.stopPropagation();
		e.preventDefault();
		var $item = $( this ).closest( '.dy-collection-item' );
		var r = this.getBoundingClientRect();
		openCollectionMenu( parseInt( $item.data( 'id' ), 10 ), $item.find( '.dy-collection-item__name' ).text() || i18n.thisCollection, r.right, r.bottom + 4 );
	} );
	$( document ).on( 'contextmenu', '#dy-collection-list .dy-collection-item', function ( e ) {
		e.preventDefault();
		var $item = $( this );
		openCollectionMenu( parseInt( $item.data( 'id' ), 10 ), $item.find( '.dy-collection-item__name' ).text() || i18n.thisCollection, e.clientX, e.clientY );
	} );

	// ── Drag & drop ───────────────────────────────────────────────────────────

	/*
	 * On dragstart, capture the IDs of dragged items.
	 * If the dragged attachment is in the WP selection, use all selected.
	 * Otherwise, just the single dragged item.
	 *
	 * We store in window.doubleyoupiDragIds so drop handlers can read them.
	 */
	$( document ).on( 'dragstart', '.attachments .attachment', function ( e ) {
		// WP uses data-id (not id="post-N") on its Backbone-rendered .attachment elements.
		var id = parseInt( $( this ).attr( 'data-id' ), 10 );
		if ( ! id ) { return; }

		var selectedIds = [];
		try {
			selectedIds = wp.media.frame.state().get( 'selection' ).pluck( 'id' );
		} catch ( err ) {}

		if ( selectedIds.indexOf( id ) === -1 ) {
			selectedIds = [ id ];
		}

		window.doubleyoupiDragIds = selectedIds;

		/*
		 * Set a custom data type so WP's uploader overlay (which triggers on
		 * 'Files' type) does NOT activate.
		 * body.dy-dragging-media CSS hides .uploader-window as belt-and-suspenders.
		 *
		 * Note: clearData() can throw in Firefox — keep it in the try block.
		 * effectAllowed must be set AFTER setData (spec requirement).
		 */
		var dt = e.originalEvent.dataTransfer;
		try { dt.clearData(); } catch ( _e ) {}
		try {
			dt.setData( 'application/x-dy-media', String( id ) );
			dt.effectAllowed = 'move';
		} catch ( err ) {}

		$( 'body' ).addClass( 'dy-dragging-media' );
	} );

	$( document ).on( 'dragend', '.attachments .attachment', function () {
		window.doubleyoupiDragIds = null;
		$( 'body' ).removeClass( 'dy-dragging-media' );
	} );

	/*
	 * CENTRALIZED DROP DISPATCH at document level.
	 *
	 * The element-level drop handlers (in makeFolderDropTarget /
	 * makeCollectionDropTarget) sometimes don't fire because:
	 * - User releases just OFF the small drop target
	 * - Some intermediate element cancels the drop event
	 *
	 * Solution: handle drop at document level. We track the LAST hovered drop
	 * target via dragenter/dragleave, then on drop we dispatch to the right
	 * action (folder assign / collection add / show-all reset).
	 */
	var dyHoveredTarget = null;

	document.addEventListener( 'dragenter', function ( e ) {
		if ( ! window.doubleyoupiDragIds || ! window.doubleyoupiDragIds.length ) { return; }
		var t = e.target.closest && e.target.closest( '.dy-collection-item, .dy-folder-item, .dy-show-all' );
		if ( t ) {
			dyHoveredTarget = t;
			t.classList.add( 'dy-drop-target' );
		}
	}, true );

	document.addEventListener( 'dragleave', function ( e ) {
		if ( ! window.doubleyoupiDragIds ) { return; }
		var t = e.target.closest && e.target.closest( '.dy-collection-item, .dy-folder-item, .dy-show-all' );
		if ( t && ! t.contains( e.relatedTarget ) ) {
			t.classList.remove( 'dy-drop-target' );
		}
	}, true );

	// Globally allow drop during media drag (preventDefault on every dragover)
	document.addEventListener( 'dragover', function ( e ) {
		if ( window.doubleyoupiDragIds && window.doubleyoupiDragIds.length ) {
			e.preventDefault();
		}
	}, true );

	// Centralized drop handler — dispatches to the right action based on the
	// LAST hovered target (which is more reliable than e.target at release time).
	document.addEventListener( 'drop', function ( e ) {
		if ( ! window.doubleyoupiDragIds || ! window.doubleyoupiDragIds.length ) { return; }
		var ids = window.doubleyoupiDragIds.slice();
		var target = dyHoveredTarget || ( e.target.closest && e.target.closest( '.dy-collection-item, .dy-folder-item, .dy-show-all' ) );


		// Consume the event to prevent WP plupload/uploader from processing it.
		e.preventDefault();
		e.stopPropagation();
		e.stopImmediatePropagation();

		// Brute-force hide WP uploader window if it sneaked in (plupload sometimes
		// shows the overlay despite our preventDefault, because it's hooked at
		// document level too — race condition between handlers).
		setTimeout( function () {
			$( '.uploader-window, .uploader-window-content' ).hide();
		}, 0 );
		setTimeout( function () {
			$( '.uploader-window, .uploader-window-content' ).hide();
		}, 100 );

		if ( ! target ) {
			window.doubleyoupiDragIds = null;
			dyHoveredTarget = null;
			$( 'body' ).removeClass( 'dy-dragging-media' );
			return;
		}

		// Clear visual state
		document.querySelectorAll( '.dy-drop-target' ).forEach( function ( el ) {
			el.classList.remove( 'dy-drop-target' );
		} );
		window.doubleyoupiDragIds = null;
		dyHoveredTarget = null;
		$( 'body' ).removeClass( 'dy-dragging-media' );

		// Dispatch
		if ( target.classList.contains( 'dy-collection-item' ) ) {
			handleCollectionDrop( target, ids );
		} else if ( target.classList.contains( 'dy-folder-item' ) ) {
			handleFolderDrop( target, ids );
		} else if ( target.classList.contains( 'dy-show-all' ) ) {
			// Drop on "Show all" = move to root (no folder)
			handleFolderDrop( target, ids, 0 );
		}
	}, true );

	function handleFolderDrop( targetEl, ids, forceFolderId ) {
		var folderId = ( forceFolderId !== undefined ) ? forceFolderId : parseInt( targetEl.getAttribute( 'data-id' ), 10 );
		var folderName = targetEl.querySelector( '.dy-folder-item__name, .dy-show-all__label' );
		folderName = folderName ? folderName.textContent : i18n.folderFallback;

		apiFetch( restBase + '/' + folderId + '/assign', 'POST', { attachment_ids: ids } )
			.then( function ( response ) {
				loadFolderTree();
				notifyDropResult( response, folderName, 'folder' );
				if ( isListMode() ) {
					// List view is server-rendered — reload to reflect the new state.
					setTimeout( function () { window.location.reload(); }, 800 );
				} else {
					refreshAttachments();
				}
			} )
			.catch( function ( err ) {
				showToast( i18n.folderMoveFailed, 'error' );
			} );
	}

	function handleCollectionDrop( targetEl, ids ) {
		var collectionId = parseInt( targetEl.getAttribute( 'data-id' ), 10 );
		var colName = targetEl.querySelector( '.dy-collection-item__name' );
		colName = colName ? colName.textContent : i18n.collectionFallback;

		apiFetch( restCollections + '/' + collectionId + '/add', 'POST', { attachment_ids: ids } )
			.then( function ( response ) {
				loadCollections();
				notifyDropResult( response, colName, 'collection' );
				if ( isListMode() ) {
					setTimeout( function () { window.location.reload(); }, 800 );
				}
			} )
			.catch( function ( err ) {
				showToast( i18n.collectionAddFailed, 'error' );
			} );
	}

	function notifyDropResult( response, name, kind ) {
		// folder uses {assigned, already}, collection uses {added, already}
		var newCount = ( response && ( response.assigned || response.added ) ) ?
			( response.assigned || response.added ).length : 0;
		var dupCount = ( response && response.already ) ? response.already.length : 0;
		var isFolder = kind === 'folder';

		if ( newCount > 0 && dupCount === 0 ) {
			var msg = newCount === 1
				? fmt( isFolder ? i18n.movedOne : i18n.addedOne, name )
				: fmt( isFolder ? i18n.movedMany : i18n.addedMany, newCount, name );
			showToast( msg, 'success' );
		} else if ( newCount === 0 && dupCount > 0 ) {
			showToast( fmt( dupCount === 1 ? i18n.alreadyInOne : i18n.alreadyInAll, name ), 'warning' );
		} else if ( newCount > 0 && dupCount > 0 ) {
			showToast( fmt( isFolder ? i18n.movedAndDup : i18n.addedAndDup, newCount, dupCount ), 'mixed' );
		}
	}

	// Make attachments draggable on mouseenter.
	$( document ).on( 'mouseenter', '.attachments .attachment', function () {
		if ( ! this.getAttribute( 'draggable' ) ) { this.setAttribute( 'draggable', 'true' ); }
		// A competing manager (e.g. FileBird) may have made this item
		// jQuery-UI-draggable. That hijacks mousedown and stops our native HTML5
		// dragstart from firing — destroy it so our drag-to-folder keeps working
		// when RML owns the page.
		try {
			if ( window.jQuery && jQuery.fn.draggable && this.classList.contains( 'ui-draggable' ) ) {
				jQuery( this ).draggable( 'destroy' );
				this.setAttribute( 'draggable', 'true' );
			}
		} catch ( e ) {}
	} );

	/*
	 * ── List view drag & drop ─────────────────────────────────────────────
	 * In list mode the rows are <tr id="post-N" class="...attachment...">.
	 * We mirror the grid-view dragstart logic so users can drag rows onto
	 * the folder/collection panel items. The drop handler is shared (centralized
	 * at document level) and dispatches based on the panel target element.
	 */
	$( document ).on( 'mouseenter', '#the-list tr', function () {
		if ( ! this.getAttribute( 'draggable' ) ) { this.setAttribute( 'draggable', 'true' ); }
	} );

	$( document ).on( 'dragstart', '#the-list tr', function ( e ) {
		// WP_List_Table rows have id="post-N"
		var idMatch = ( $( this ).attr( 'id' ) || '' ).match( /post-(\d+)/ );
		var id = idMatch ? parseInt( idMatch[ 1 ], 10 ) : 0;
		if ( ! id ) { return; }

		// If this row is in a multi-checkbox selection, drag all selected.
		var selectedIds = $( '#the-list input[name="media[]"]:checked' ).map( function () {
			return parseInt( this.value, 10 );
		} ).get();

		if ( selectedIds.indexOf( id ) === -1 ) {
			selectedIds = [ id ];
		}

		window.doubleyoupiDragIds = selectedIds;

		var dt = e.originalEvent.dataTransfer;
		try { dt.clearData(); } catch ( _e ) {}
		try {
			dt.setData( 'application/x-dy-media', String( id ) );
			dt.effectAllowed = 'move';
		} catch ( err ) {}

		$( 'body' ).addClass( 'dy-dragging-media' );
	} );

	$( document ).on( 'dragend', '#the-list tr', function () {
		window.doubleyoupiDragIds = null;
		$( 'body' ).removeClass( 'dy-dragging-media' );
	} );

	// makeFolderDropTarget kept as no-op for back-compat — drop handling
	// is now centralized at document level (see "CENTRALIZED DROP DISPATCH" below).
	function makeFolderDropTarget( $el, folderId ) {
		// no-op
		void $el; void folderId;
	}

	function makeCollectionDropTarget( $el, collectionId ) {
		// no-op — drop handling centralized at document level
		void $el; void collectionId;
	}

	// ── Bulk selection toolbar — Add to folder / collection buttons ───────────

	/*
	 * WP's multi-select toolbar appears when the user clicks "Select" mode.
	 * We inject two buttons (add to folder / add to collection) into .media-toolbar-secondary.
	 * We watch for changes to the WP selection Backbone collection.
	 */
	/*
	 * Robustly track the WP selection so the bulk location bar stays in sync.
	 * The Backbone `selection` collection is REPLACED whenever the frame state
	 * changes (tab switch, re-render). Hooking it only once (as before) left the
	 * bar listening to a stale collection — it silently stopped appearing. We
	 * mirror bulk-metadata.js: poll until ready, then re-hook on content:render.
	 */
	function hookBulkSelection() {
		var interval = setInterval( function () {
			if ( ! window.wp || ! wp.media || ! wp.media.frame ) { return; }
			var state = null;
			try { state = wp.media.frame.state(); } catch ( e ) {}
			if ( ! state ) { return; }
			var selection = state.get( 'selection' );
			if ( ! selection ) { return; }

			clearInterval( interval );
			bindBulkSelection( selection );

			wp.media.frame.on( 'content:render', function () {
				try {
					var newSel = wp.media.frame.state().get( 'selection' );
					if ( newSel && newSel !== selection ) {
						selection = newSel;
						bindBulkSelection( selection );
					}
				} catch ( e ) {}
			} );
		}, 400 );
	}

	function bindBulkSelection( selection ) {
		selection.on( 'add remove reset selection:single selection:unsingle', function () {
			syncBulkLocationBar( selection );
		} );
		syncBulkLocationBar( selection );
	}

	/*
	 * Show the Folder / Collection bulk buttons whenever there is a selection
	 * (same threshold as the "Edit metadata" button), and keep them to the LEFT
	 * of that button. Remove them when the selection is empty.
	 */
	function syncBulkLocationBar( selection ) {
		/*
		 * Library page only. In a picker these two buttons were duplicated into
		 * every media toolbar on the screen and landed on top of the date
		 * filter; see the matching note in bulk-metadata.js for why they have
		 * no place there even when they land correctly.
		 */
		if ( ! document.body.classList.contains( 'upload-php' ) ) {
			$( '#dy-bulk-location-bar' ).remove();
			return;
		}

		if ( selection.length < 1 ) {
			$( '#dy-bulk-location-bar' ).remove();
			return;
		}

		var $toolbar = $( '.media-toolbar-secondary' ).first();
		if ( ! $toolbar.length ) { return; }

		var $bar = $( '#dy-bulk-location-bar' );
		if ( ! $bar.length ) {
			$bar = buildBulkLocationBar( selection );
		}

		// Keep the bar before the "Edit metadata" button (bulk-metadata.js) when
		// it is present, otherwise leftmost in the secondary toolbar.
		var $meta = $( '#dy-bulk-meta-btn' );
		if ( $meta.length ) {
			$bar.insertBefore( $meta );
		} else {
			$toolbar.prepend( $bar );
		}
	}

	function buildBulkLocationBar( selection ) {
		var $bar = $(
			'<div id="dy-bulk-location-bar">' +
				'<button type="button" class="button dy-bulk-folder-btn" id="dy-bulk-assign-folder">' + esc( i18n.foldersLabel || 'Folders' ) + ' ↓</button>' +
				'<button type="button" class="button dy-bulk-col-btn" id="dy-bulk-add-collection">' + esc( i18n.collections || 'Collections' ) + ' ↓</button>' +
			'</div>'
		);
		$bar.find( '#dy-bulk-assign-folder' ).on( 'click', function () {
			openBulkFolderPicker( selection.pluck( 'id' ) );
		} );
		$bar.find( '#dy-bulk-add-collection' ).on( 'click', function () {
			openBulkCollectionPicker( selection.pluck( 'id' ) );
		} );
		return $bar;
	}

	function openBulkFolderPicker( ids ) {
		var folders = dyState.folders || [];
		if ( ! folders.length ) { window.doubleyoupiDialog.alert( { title: i18n.noFoldersYet } ); return; }

		var opts = '<option value="0">' + esc( i18n.rootNoFolder ) + '</option>';
		folders.forEach( function ( f ) {
			opts += '<option value="' + f.id + '">' + esc( f.name ) + '</option>';
		} );

		var title = ids.length === 1
			? i18n.bulkMoveTitle1
			: ( i18n.bulkMoveTitle || '' ).replace( '%d', ids.length );
		var $dlg = $(
			'<div class="dy-bulk-overlay">' +
				'<div class="dy-bulk-dialog">' +
					'<div class="dy-bulk-dialog__header"><h3>' + esc( title ) + '</h3></div>' +
					'<div class="dy-bulk-dialog__field">' +
						'<label>' + esc( i18n.destinationFolder ) + '</label>' +
						'<select class="dy-bulk-folder-select">' + opts + '</select>' +
					'</div>' +
					'<div class="dy-bulk-dialog__footer">' +
						'<button type="button" class="dy-bulk-btn dy-bulk-btn-cancel">' + esc( i18n.cancel ) + '</button>' +
						'<button type="button" class="dy-bulk-btn dy-bulk-btn-apply" id="dy-bulk-folder-apply">' + esc( i18n.move ) + '</button>' +
					'</div>' +
				'</div>' +
			'</div>'
		).appendTo( 'body' );

		$dlg.find( '.dy-bulk-btn-cancel' ).on( 'click', function () { $dlg.remove(); } );
		$dlg.find( '#dy-bulk-folder-apply' ).on( 'click', function () {
			var folderId = parseInt( $dlg.find( '.dy-bulk-folder-select' ).val(), 10 );
			apiFetch( restBase + '/' + folderId + '/assign', 'POST', { attachment_ids: ids } )
				.then( function () {
					$dlg.remove();
					if ( isListMode() ) {
						window.location.reload();
						return;
					}
					var col = getAttachmentCollection();
					if ( col ) { col.fetch(); }
					loadFolderTree();
				} );
		} );
	}

	function openBulkCollectionPicker( ids ) {
		var collections = dyState.collections || [];
		if ( ! collections.length ) { window.doubleyoupiDialog.alert( { title: i18n.noCollectionsYet } ); return; }

		var opts = '<option value="">' + esc( i18n.chooseCollection ) + '</option>';
		collections.forEach( function ( c ) {
			opts += '<option value="' + c.id + '">' + esc( c.name ) + '</option>';
		} );

		var title = ids.length === 1
			? i18n.bulkAddColTitle1
			: ( i18n.bulkAddColTitle || '' ).replace( '%d', ids.length );
		var $dlg = $(
			'<div class="dy-bulk-overlay">' +
				'<div class="dy-bulk-dialog">' +
					'<div class="dy-bulk-dialog__header"><h3>' + esc( title ) + '</h3></div>' +
					'<div class="dy-bulk-dialog__field">' +
						'<label>' + esc( i18n.collections ) + '</label>' +
						'<select class="dy-bulk-col-select">' + opts + '</select>' +
					'</div>' +
					'<div class="dy-bulk-dialog__footer">' +
						'<button type="button" class="dy-bulk-btn dy-bulk-btn-cancel">' + esc( i18n.cancel ) + '</button>' +
						'<button type="button" class="dy-bulk-btn dy-bulk-btn-apply" id="dy-bulk-col-apply">' + esc( i18n.add ) + '</button>' +
					'</div>' +
				'</div>' +
			'</div>'
		).appendTo( 'body' );

		$dlg.find( '.dy-bulk-btn-cancel' ).on( 'click', function () { $dlg.remove(); } );
		$dlg.find( '#dy-bulk-col-apply' ).on( 'click', function () {
			var colId = parseInt( $dlg.find( '.dy-bulk-col-select' ).val(), 10 );
			if ( ! colId ) { return; }
			apiFetch( restCollections + '/' + colId + '/add', 'POST', { attachment_ids: ids } )
				.then( function () {
					$dlg.remove();
					if ( isListMode() ) {
						window.location.reload();
						return;
					}
					loadCollections();
				} );
		} );
	}

	// Hook bulk selection after the frame is ready
	$( function () {
		setTimeout( hookBulkSelection, 600 );
	} );

	// ── Resize — folder panel right edge ─────────────────────────────────────

	function initFolderResize() {
		var $handle = $( '#dy-rh-folder' );
		if ( ! $handle.length ) { return; }

		$handle.on( 'mousedown', function ( e ) {
			e.preventDefault();
			var startX = e.clientX;
			var startW = $( '#dy-folder-panel' ).outerWidth();

			// Max width must match the CSS cap `min(35vw, 360px)` on
			// `#dy-folder-panel` — otherwise `--dy-fw` keeps growing beyond
			// the visual width of the sidebar, and the grid (which reads
			// `--dy-fw` via margin-left) continues to shrink even after
			// the sidebar has already stopped expanding.
			function maxAllowed() {
				return Math.min( window.innerWidth * 0.35, 360 );
			}

			$( document ).on( 'mousemove.dyfw', function ( e2 ) {
				var max = maxAllowed();
				var newW = Math.max( 200, Math.min( max, startW + e2.clientX - startX ) );
				document.body.style.setProperty( '--dy-fw', newW + 'px' );
			} );

			$( document ).on( 'mouseup.dyfw', function () {
				$( document ).off( '.dyfw' );
				var w = parseInt( document.body.style.getPropertyValue( '--dy-fw' ), 10 ) || 220;
				localStorage.setItem( 'dy-fw', String( w ) );
			} );
		} );
	}

	// ── Upload into current folder / collection ──────────────────────────────

	/*
	 * Patch wp.Uploader so that any new media uploaded while the user has a
	 * folder or collection selected gets auto-assigned to it server-side.
	 *
	 * Two plupload bindings per uploader instance :
	 *  - BeforeUpload : inject doubleyoupi_folder_id / doubleyoupi_collection_id into multipart
	 *    params. Read from dyState AT UPLOAD TIME (not patch time) so the
	 *    target follows whatever the user is currently viewing — even if they
	 *    navigated to a different folder between opening the uploader and
	 *    actually dropping a file.
	 *  - FileUploaded : refresh sidebar counts so badges update live. Debounced
	 *    because batched uploads fire this once per file.
	 *
	 * TIMING — WHY THIS IS HARD :
	 *  On upload.php grid mode, WordPress creates wp.media.view.UploaderWindow
	 *  (and the underlying wp.Uploader instance) BEFORE our $(document).ready
	 *  handler fires — because both depend on `media-views` and the order of
	 *  ready handlers is not guaranteed. Patching `wp.Uploader.prototype.init`
	 *  too late only catches FUTURE instances, leaving the existing global
	 *  drag-drop overlay unbound.
	 *
	 *  Belt-and-suspenders fix :
	 *    1. Patch the prototype as early as possible (script parse time).
	 *    2. Also retrofit any wp.Uploader instance we can already find.
	 *    3. Keep a single source of truth (bindToInstance) so a future
	 *       retrofit pass can't double-bind the same instance.
	 */
	var dyUploaderHooked = false;
	var dyRefreshCountsSoon = debounce( function () {
		loadFolderTree();
		loadCollections();
	}, 500 );

	function bindToUploaderInstance( plUploader ) {
		if ( ! plUploader || plUploader._dyBound ) { return; }
		plUploader._dyBound = true;

		plUploader.bind( 'BeforeUpload', function ( up ) {
			up.settings.multipart_params = up.settings.multipart_params || {};
			if ( dyState.currentFolder > 0 ) {
				up.settings.multipart_params.doubleyoupi_folder_id = dyState.currentFolder;
			} else {
				delete up.settings.multipart_params.doubleyoupi_folder_id;
			}
			if ( dyState.currentCollection && dyState.currentCollection > 0 ) {
				up.settings.multipart_params.doubleyoupi_collection_id = dyState.currentCollection;
			} else {
				delete up.settings.multipart_params.doubleyoupi_collection_id;
			}
		} );

		plUploader.bind( 'FileUploaded', function () {
			dyRefreshCountsSoon();
		} );
	}

	/*
	 * Walk every known place a wp.Uploader instance might live and bind it.
	 * Idempotent thanks to plUploader._dyBound.
	 */
	function retrofitExistingUploaders() {
		try {
			if ( window.wp && wp.media && wp.media.frame ) {
				// Modal/inline uploader (Add Media button → media frame).
				if ( wp.media.frame.uploader && wp.media.frame.uploader.uploader ) {
					bindToUploaderInstance( wp.media.frame.uploader.uploader );
				}
				// Some frames expose it directly as .uploader (older Backbone views).
				if ( wp.media.frame.uploader && wp.media.frame.uploader.uploader === undefined && wp.media.frame.uploader.bind ) {
					bindToUploaderInstance( wp.media.frame.uploader );
				}
			}
		} catch ( _e ) {
			// Best-effort retrofit ; swallow errors silently.
		}
	}

	function hookUploader() {
		if ( dyUploaderHooked ) {
			// Still try to retrofit — a new frame might have spawned a new uploader.
			retrofitExistingUploaders();
			return;
		}
		if ( ! window.wp || ! wp.Uploader || ! wp.Uploader.prototype || ! wp.Uploader.prototype.init ) {
			return;
		}

		var orig = wp.Uploader.prototype.init;
		var patched = function () {
			orig.apply( this, arguments );
			bindToUploaderInstance( this.uploader );
		};
		patched._dyPatched = true;
		wp.Uploader.prototype.init = patched;
		dyUploaderHooked = true;

		// Catch any instance that was already created before our patch landed.
		retrofitExistingUploaders();
	}

	/*
	 * Call hookUploader() AS EARLY AS POSSIBLE (script parse time, before
	 * $(document).ready). wp.Uploader is defined as soon as media-views loads,
	 * which is our declared dependency — so it's already there by the time
	 * this IIFE runs.
	 *
	 * Multiple retries afterwards catch deferred uploader instantiation
	 * (e.g. wp.media.frame is built lazily on first Add Media click).
	 */
	hookUploader();
	setTimeout( hookUploader, 100 );
	setTimeout( hookUploader, 600 );
	setTimeout( hookUploader, 1500 );

	// ── Helpers ───────────────────────────────────────────────────────────────

	function getAttachmentCollection() {
		try { return wp.media.frame.content.get().collection; } catch ( e ) { return null; }
	}

	/*
	 * Force the attachments collection to refetch with our custom params.
	 * WP's whitelist strips doubleyoupi_folder/doubleyoupi_collection, so collection.props.set()
	 * alone doesn't propagate. We trigger refetch explicitly on the mirroring
	 * Query and rely on initMediaAjaxHook to inject the params.
	 */
	function refreshAttachments() {
		var col = getAttachmentCollection();
		if ( ! col ) { return; }
		try {
			/*
			 * _requery(true) is WP Backbone's official way to force a fresh refetch:
			 * - Clears the Query.get cache (sets queries=[])
			 * - Creates a new Query from current props
			 * - Calls mirror() to that new Query
			 * - Triggers fetch which goes through our wp.media.ajax hook (injects doubleyoupi_folder)
			 */
			if ( typeof col._requery === 'function' ) {
				col._requery( true );
			} else if ( col.mirroring && typeof col.mirroring.fetch === 'function' ) {
				col.mirroring._hasMore = true;
				col.mirroring.fetch( { reset: true } );
			}
		} catch ( e ) {
		}
	}

	/*
	 * Inject doubleyoupi_folder and doubleyoupi_collection into every query-attachments AJAX call.
	 *
	 * WP's wp.media.model.Query.get builds args from a hardcoded whitelist
	 * (see wp-includes/js/media-models.js Query.get). Custom props like
	 * doubleyoupi_folder are NOT in that whitelist, so setting collection.props doesn't
	 * propagate to the AJAX request.
	 *
	 * This hook intercepts wp.media.ajax to inject our params from the global
	 * window.doubleyoupiMediaState — bypassing the whitelist entirely.
	 */
	function initMediaAjaxHook() {
		if ( ! window.wp || ! wp.media || ! wp.media.ajax ) { return; }
		if ( wp.media.ajax._dyHooked ) { return; }

		var origAjax = wp.media.ajax;
		var hooked = function ( action, options ) {
			var opts = ( typeof action === 'object' ) ? action : options;
			if ( opts && opts.data && opts.data.action === 'query-attachments' ) {
				opts.data.query = opts.data.query || {};
				if ( dyState.currentFolder !== undefined && dyState.currentFolder !== 0 ) {
					opts.data.query.doubleyoupi_folder = dyState.currentFolder;
				} else {
					delete opts.data.query.doubleyoupi_folder;
				}
				if ( dyState.currentCollection !== null && dyState.currentCollection !== 0 ) {
					opts.data.query.doubleyoupi_collection = dyState.currentCollection;
				} else {
					delete opts.data.query.doubleyoupi_collection;
				}
			}
			return origAjax.call( this, action, options );
		};
		hooked._dyHooked = true;
		// Copy over any other properties from origAjax
		for ( var k in origAjax ) {
			if ( origAjax.hasOwnProperty( k ) ) { hooked[ k ] = origAjax[ k ]; }
		}
		wp.media.ajax = hooked;
	}

	function apiFetch( url, method, body ) {
		var opts = { method: method, headers: { 'X-WP-Nonce': nonce } };
		if ( body ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( body );
		}
		return window.fetch( url, opts ).then( function ( r ) { return r.json(); } );
	}

	function esc( str ) {
		return String( str ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}

	/*
	 * Toast notification — variants : 'success' (default, blue), 'warning' (orange),
	 * 'mixed' (purple), 'error' (red). Slides in from bottom, auto-dismiss 2.8s.
	 */
	function showToast( message, variant ) {
		var $existing = $( '.dy-toast' );
		$existing.remove();

		var $toast = $( '<div class="dy-toast"></div>' )
			.addClass( 'dy-toast--' + ( variant || 'success' ) )
			.text( message );
		$( 'body' ).append( $toast );

		// Force reflow then animate in
		// eslint-disable-next-line no-unused-expressions
		$toast[ 0 ].offsetHeight;
		$toast.addClass( 'dy-toast--visible' );

		setTimeout( function () {
			$toast.removeClass( 'dy-toast--visible' );
			setTimeout( function () { $toast.remove(); }, 300 );
		}, 2800 );
	}
	window.doubleyoupiShowToast = showToast;

	// Expose reload functions for other scripts
	window.doubleyoupiReloadFolders     = loadFolderTree;
	window.doubleyoupiReloadCollections = loadCollections;

} )( jQuery, window );
