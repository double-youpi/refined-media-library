/**
 * "Import" tab for Refined Media Library.
 *
 * Drives the migration from a competing folder plugin:
 *   1. list detected sources (GET /sources)
 *   2. dry-run preview (POST /{source}/preview) — no writes
 *   3. import in ticks (POST /{source}/run in a loop until done=true)
 *   4. targeted rollback (POST /{source}/rollback)
 *
 * Vanilla JS, no dependencies. UI uses 100% native WordPress classes.
 */
( function () {
	'use strict';

	var cfg = window.doubleyoupiMlMigration;
	if ( ! cfg ) {
		return;
	}
	var root = document.getElementById( 'dy-ml-migration' );
	if ( ! root ) {
		return;
	}
	var i18n = cfg.i18n || {};

	function t( key ) {
		return i18n[ key ] || key;
	}

	/** Minimal sprintf: supports %s / %d / %1$d %2$d %3$d. */
	function fmt( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( str )
			.replace( /%(\d+)\$[ds]/g, function ( _m, n ) {
				return args[ parseInt( n, 10 ) - 1 ];
			} )
			.replace( /%[ds]/g, function () {
				return args[ i++ ];
			} );
	}

	function api( method, path, body ) {
		return fetch( cfg.restBase + path, {
			method: method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce
			},
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( r ) {
			if ( ! r.ok ) {
				throw new Error( 'HTTP ' + r.status );
			}
			return r.json();
		} );
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		attrs = attrs || {};
		Object.keys( attrs ).forEach( function ( k ) {
			if ( k === 'class' ) {
				node.className = attrs[ k ];
			} else if ( k === 'text' ) {
				node.textContent = attrs[ k ];
			} else {
				node.setAttribute( k, attrs[ k ] );
			}
		} );
		( children || [] ).forEach( function ( c ) {
			node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
		} );
		return node;
	}

	function clear() {
		root.removeAttribute( 'data-loading' );
		root.innerHTML = '';
	}

	function showError() {
		var n = el( 'div', { class: 'notice notice-error inline' }, [ el( 'p', { text: t( 'error' ) } ) ] );
		root.appendChild( n );
	}

	// -- 1. Source list -------------------------------------------------------

	function loadSources() {
		api( 'GET', '/sources' ).then( renderSources ).catch( function () {
			clear();
			showError();
		} );
	}

	function renderSources( sources ) {
		clear();
		if ( ! sources.length ) {
			root.appendChild( el( 'div', { class: 'notice notice-info inline' }, [ el( 'p', { text: t( 'noSources' ) } ) ] ) );
			return;
		}

		root.appendChild( el( 'h2', { text: t( 'detected' ) } ) );

		// No <thead>: in Refined Admin Dashboard's dark admin skin, a thead
		// renders as a floating band that overlaps the rows. The heading
		// above is sufficient — the columns are self-explanatory.
		var table = el( 'table', { class: 'wp-list-table widefat fixed striped' } );
		var tbody = el( 'tbody' );

		sources.forEach( function ( src ) {
			var summary = fmt( t( 'sourceSummary' ), src.folders, src.relations );
			var nameCell = el( 'td', {}, [ el( 'strong', { text: src.label } ), el( 'br' ), el( 'span', { class: 'description', text: summary } ) ] );

			var actionCell = el( 'td' );
			var previewBtn = el( 'button', { class: 'button button-primary', type: 'button', text: t( 'preview' ) } );
			previewBtn.addEventListener( 'click', function () {
				startPreview( src );
			} );
			actionCell.appendChild( previewBtn );

			var undoCell = el( 'td' );
			if ( src.migrated > 0 ) {
				var badge = el( 'span', { class: 'description', text: t( 'alreadyImported' ) + ' ' } );
				var undoBtn = el( 'button', { class: 'button button-link-delete', type: 'button', text: t( 'rollback' ) } );
				undoBtn.addEventListener( 'click', function () {
					doRollback( src );
				} );
				undoCell.appendChild( badge );
				undoCell.appendChild( undoBtn );
			}

			tbody.appendChild( el( 'tr', {}, [ nameCell, actionCell, undoCell ] ) );
		} );

		table.appendChild( tbody );
		root.appendChild( table );
	}

	// -- 2. Preview (dry-run) ------------------------------------------------

	function startPreview( src ) {
		clear();
		root.appendChild( el( 'p', {}, [ el( 'span', { class: 'spinner is-active', style: 'float:none' } ), el( 'span', { text: ' ' + t( 'previewing' ) } ) ] ) );

		api( 'POST', '/' + src.key + '/preview' ).then( function ( data ) {
			renderPreview( src, data );
		} ).catch( function () {
			clear();
			showError();
		} );
	}

	// Inline icons (HTML parser handles SVG set via innerHTML on a span).
	var ICON_FOLDER     = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>';
	var ICON_COLLECTION = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 2 7l10 5 10-5-10-5z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/></svg>';

	function iconSpan( markup ) {
		var span = document.createElement( 'span' );
		span.className = 'dy-ml-node__icon';
		span.setAttribute( 'aria-hidden', 'true' );
		span.innerHTML = markup;
		return span;
	}

	// Running index so rows can stagger their reveal; reset per preview.
	var nodeSeq = 0;

	function treeList( nodes, depth ) {
		var ul = el( 'ul', { class: depth === 0 ? 'dy-ml-tree' : 'dy-ml-tree__sub' } );
		nodes.forEach( function ( node ) {
			var isCollection = node.kind === 'collection';
			var hasChildren  = node.children && node.children.length;

			var row = el( 'div', {
				class: 'dy-ml-node',
				'data-kind': isCollection ? 'collection' : 'folder',
				style: '--i:' + ( nodeSeq++ ),
			} );
			row.appendChild( iconSpan( isCollection ? ICON_COLLECTION : ICON_FOLDER ) );
			row.appendChild( el( 'span', { class: 'dy-ml-node__name', text: node.name } ) );
			if ( isCollection ) {
				row.appendChild( el( 'span', { class: 'dy-ml-node__tag', text: t( 'collection' ) } ) );
			}

			var li = el( 'li', { class: 'dy-ml-tree__item' }, [ row ] );
			if ( hasChildren ) {
				li.appendChild( treeList( node.children, depth + 1 ) );
			}
			ul.appendChild( li );
		} );
		return ul;
	}

	function renderPreview( src, data ) {
		clear();
		root.appendChild( el( 'h2', { text: t( 'previewTitle' ) + ': ' + src.label } ) );
		root.appendChild( el( 'div', { class: 'notice notice-info inline' }, [
			el( 'p', { text: fmt( t( 'previewCounts' ), data.folders, data.collections, data.relations ) } )
		] ) );

		if ( data.tree && data.tree.length ) {
			nodeSeq = 0;
			root.appendChild( treeList( data.tree, 0 ) );
		}

		var go = el( 'button', { class: 'button button-primary', type: 'button', text: t( 'confirmImport' ) } );
		go.addEventListener( 'click', function () {
			runImport( src );
		} );
		var cancel = el( 'button', { class: 'button', type: 'button', text: t( 'cancel' ), style: 'margin-left:8px' } );
		cancel.addEventListener( 'click', loadSources );

		root.appendChild( el( 'p', {}, [ go, cancel ] ) );
	}

	// -- 3. Tick-based import -------------------------------------------------

	function runImport( src ) {
		clear();
		root.appendChild( el( 'h2', { text: src.label } ) );
		var status = el( 'p', { text: t( 'importing' ) } );
		var bar = el( 'progress', { max: '100', value: '0', style: 'width:100%;max-width:400px;display:block' } );
		root.appendChild( status );
		root.appendChild( bar );

		function tick( reset ) {
			api( 'POST', '/' + src.key + '/run', { reset: !! reset } ).then( function ( p ) {
				bar.value = p.percent;
				status.textContent = p.phase === 'relations' ? t( 'phaseRelations' ) : t( 'phaseTerms' );
				if ( p.done ) {
					renderDone( src, p );
				} else {
					tick( false );
				}
			} ).catch( function () {
				clear();
				showError();
			} );
		}
		tick( true );
	}

	function renderDone( src, p ) {
		clear();
		var msg = fmt( t( 'doneMessage' ), p.terms_done, p.rel_done );
		root.appendChild( el( 'div', { class: 'notice notice-success inline' }, [ el( 'p', { text: msg } ) ] ) );
		var link = el( 'a', { class: 'button button-primary', href: 'upload.php', text: t( 'viewLibrary' ) } );
		var back = el( 'button', { class: 'button', type: 'button', text: t( 'cancel' ), style: 'margin-left:8px' } );
		back.addEventListener( 'click', loadSources );
		root.appendChild( el( 'p', {}, [ link, back ] ) );
	}

	// -- 4. Rollback ----------------------------------------------------------

	function doRollback( src ) {
		window.doubleyoupiDialog.confirm( {
			title:        t( 'rollbackConfirm' ),
			confirmLabel: t( 'rollback' ),
			cancelLabel:  t( 'cancel' ),
			danger:       true
		} ).then( function ( ok ) {
			if ( ! ok ) { return; }
			clear();
			root.appendChild( el( 'p', {}, [ el( 'span', { class: 'spinner is-active', style: 'float:none' } ), el( 'span', { text: ' ' + t( 'rollingBack' ) } ) ] ) );
			api( 'POST', '/' + src.key + '/rollback' ).then( function ( data ) {
				clear();
				root.appendChild( el( 'div', { class: 'notice notice-success inline' }, [ el( 'p', { text: fmt( t( 'rolledBack' ), data.deleted ) } ) ] ) );
				setTimeout( loadSources, 1200 );
			} ).catch( function () {
				clear();
				showError();
			} );
		} );
	}

	// Boot
	loadSources();
}() );
