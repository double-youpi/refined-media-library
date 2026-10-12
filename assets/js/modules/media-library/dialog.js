/*
 * Refined Media Library — designed dialog system.
 *
 * Drop-in, on-brand replacement for the browser's native window.confirm /
 * window.prompt / window.alert, styled to match the dark RML admin theme.
 *
 * Exposes window.doubleyoupiDialog with three Promise-returning methods:
 *   doubleyoupiDialog.confirm({ title, message, confirmLabel, cancelLabel, danger })  -> Promise<boolean>
 *   doubleyoupiDialog.prompt({ title, label, value, placeholder, confirmLabel, cancelLabel }) -> Promise<string|null>
 *   doubleyoupiDialog.alert({ title, message, okLabel })  -> Promise<void>
 *
 * Vanilla JS, no dependencies. Focus-trapped, Esc to cancel, Enter to confirm,
 * backdrop click cancels, focus is restored to the opener on close.
 */
( function ( window, document ) {
	'use strict';

	if ( window.doubleyoupiDialog ) { return; }

	var REDUCED = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	var active  = null; // { overlay, resolve, opener }

	function esc( s ) {
		return String( s == null ? '' : s )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}

	function focusables( root ) {
		return Array.prototype.slice.call(
			root.querySelectorAll( 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])' )
		).filter( function ( el ) { return ! el.disabled && el.offsetParent !== null; } );
	}

	function close( result ) {
		if ( ! active ) { return; }
		var cur = active;
		active  = null;
		var ov  = cur.overlay;
		ov.classList.remove( 'is-open' );

		var done = function () {
			if ( ov.parentNode ) { ov.parentNode.removeChild( ov ); }
			if ( cur.opener && cur.opener.focus ) { try { cur.opener.focus(); } catch ( e ) {} }
			cur.resolve( result );
		};

		if ( REDUCED ) { done(); return; }

		var fired = false;
		var onEnd = function () {
			if ( fired ) { return; }
			fired = true;
			ov.removeEventListener( 'transitionend', onEnd );
			done();
		};
		ov.addEventListener( 'transitionend', onEnd );
		setTimeout( onEnd, 260 ); // safety net if transitionend doesn't fire
	}

	function open( kind, opts ) {
		opts = opts || {};
		if ( active ) { close( null ); }

		var opener   = document.activeElement;
		var isPrompt = kind === 'prompt';
		var isAlert  = kind === 'alert';
		var confirmLabel = opts.confirmLabel || ( isAlert ? ( opts.okLabel || 'OK' ) : 'OK' );
		var cancelLabel  = opts.cancelLabel || 'Cancel';

		var overlay = document.createElement( 'div' );
		overlay.className = 'dy-dialog-overlay';
		overlay.setAttribute( 'role', 'presentation' );

		var titleId = 'dy-dialog-title';
		var html = '<div class="dy-dialog" role="' + ( isAlert ? 'alertdialog' : 'dialog' ) +
			'" aria-modal="true" aria-labelledby="' + titleId + '">';
		if ( opts.eyebrow ) { html += '<div class="dy-dialog__eyebrow">' + esc( opts.eyebrow ) + '</div>'; }
		html += '<h2 class="dy-dialog__title" id="' + titleId + '">' + esc( opts.title || '' ) + '</h2>';
		if ( opts.message ) { html += '<p class="dy-dialog__message">' + esc( opts.message ) + '</p>'; }
		if ( isPrompt ) {
			html += '<div class="dy-dialog__field">';
			if ( opts.label ) {
				html += '<label class="dy-dialog__label" for="dy-dialog-input">' + esc( opts.label ) + '</label>';
			}
			html += '<input type="text" id="dy-dialog-input" class="dy-dialog__input" value="' +
				esc( opts.value || '' ) + '" placeholder="' + esc( opts.placeholder || '' ) + '" autocomplete="off">';
			html += '</div>';
		}
		html += '<div class="dy-dialog__actions">';
		if ( ! isAlert ) {
			html += '<button type="button" class="dy-dialog__btn dy-dialog__btn--ghost" data-act="cancel">' +
				esc( cancelLabel ) + '</button>';
		}
		html += '<button type="button" class="dy-dialog__btn dy-dialog__btn--primary' +
			( opts.danger ? ' dy-dialog__btn--danger' : '' ) + '" data-act="confirm">' +
			esc( confirmLabel ) + '</button>';
		html += '</div></div>';
		overlay.innerHTML = html;

		document.body.appendChild( overlay );
		var dialog = overlay.querySelector( '.dy-dialog' );
		var input  = overlay.querySelector( '#dy-dialog-input' );

		// Cancel value differs by kind: prompt -> null, confirm -> false, alert -> undefined.
		var cancelResult = isPrompt ? null : ( isAlert ? undefined : false );
		var confirmValue = function () { return isPrompt ? ( input ? input.value : '' ) : true; };

		return new Promise( function ( resolve ) {
			active = { overlay: overlay, resolve: resolve, opener: opener };

			overlay.addEventListener( 'click', function ( e ) {
				var act = e.target.getAttribute && e.target.getAttribute( 'data-act' );
				if ( act === 'confirm' ) { close( confirmValue() ); }
				else if ( act === 'cancel' ) { close( cancelResult ); }
				else if ( e.target === overlay ) { close( cancelResult ); } // backdrop
			} );

			overlay.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Escape' ) {
					e.preventDefault();
					close( cancelResult );
				} else if ( e.key === 'Enter' && ( isPrompt || isAlert ) ) {
					e.preventDefault();
					close( confirmValue() );
				} else if ( e.key === 'Tab' ) {
					var f = focusables( dialog );
					if ( ! f.length ) { return; }
					var first = f[ 0 ];
					var last  = f[ f.length - 1 ];
					if ( e.shiftKey && document.activeElement === first ) { e.preventDefault(); last.focus(); }
					else if ( ! e.shiftKey && document.activeElement === last ) { e.preventDefault(); first.focus(); }
				}
			} );

			requestAnimationFrame( function () {
				overlay.classList.add( 'is-open' );
				if ( input ) { input.focus(); input.select(); }
				else {
					var c = overlay.querySelector( '[data-act="confirm"]' );
					if ( c ) { c.focus(); }
				}
			} );
		} );
	}

	window.doubleyoupiDialog = {
		confirm: function ( opts ) { return open( 'confirm', opts ); },
		prompt:  function ( opts ) { return open( 'prompt', opts ); },
		alert:   function ( opts ) { return open( 'alert', opts ); }
	};
} )( window, document );
