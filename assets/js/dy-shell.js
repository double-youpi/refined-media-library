/**
 * dy-shell.js — Standalone Double Youpi admin skin.
 *
 * Animates the underline track (.dy-track) of the DY tab chipbar, rendered by
 * DyShell.php on Double Youpi pages when the Refined Admin Dashboard module is
 * not active. The track slides to the current chip, follows hover, and returns
 * to the current chip on mouseleave.
 *
 * Standalone port of the "Chip-bar underline track" section from
 * assets/js/modules/admin/admin.js. Vanilla JS, no dependencies.
 */
( function () {
	'use strict';

	var chipbar = document.querySelector( '.dy-chipbar' );
	if ( ! chipbar ) {
		return;
	}

	var inner = chipbar.querySelector( '.dy-chipbar-inner' ) || chipbar;

	var track = chipbar.querySelector( '.dy-track' );
	if ( ! track ) {
		track = document.createElement( 'span' );
		track.className = 'dy-track';
		inner.appendChild( track );
	}

	function moveTrackTo( el ) {
		if ( ! el ) {
			track.style.width = '0';
			return;
		}
		var r1 = inner.getBoundingClientRect();
		var r2 = el.getBoundingClientRect();
		track.style.transform = 'translateX(' + ( r2.left - r1.left + inner.scrollLeft ) + 'px)';
		track.style.width = r2.width + 'px';
	}

	function currentChip() {
		return chipbar.querySelector( '.dy-chip.current' );
	}

	moveTrackTo( currentChip() );
	window.addEventListener( 'resize', function () { moveTrackTo( currentChip() ); } );

	chipbar.addEventListener( 'mouseover', function ( e ) {
		var chip = e.target.closest( '.dy-chip' );
		if ( chip ) {
			moveTrackTo( chip );
		}
	} );
	chipbar.addEventListener( 'mouseleave', function () {
		moveTrackTo( currentChip() );
	} );
	chipbar.addEventListener( 'focusin', function ( e ) {
		var chip = e.target.closest( '.dy-chip' );
		if ( chip ) {
			moveTrackTo( chip );
		}
	} );
}() );
