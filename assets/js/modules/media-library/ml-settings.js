/* global jQuery, doubleyoupiMlSettings */
/**
 * Auto-save for the Settings page (Refined Media Library), card layout.
 *
 * Every toggle and the quality slider persist immediately via admin-ajax
 * (action doubleyoupi_ml_save_setting). A transient "✓ Saved" indicator confirms.
 * The "Save changes" button is hidden as soon as this script runs; the
 * options.php form remains the save path when JS is unavailable.
 */
jQuery( function ( $ ) {
	'use strict';

	var cfg = window.doubleyoupiMlSettings;
	if ( ! cfg ) {
		return;
	}
	var $root = $( '.dy-mlset' );
	if ( ! $root.length ) {
		return;
	}

	// Hide "Save changes" (auto-save is active).
	$root.addClass( 'dy-mlset--js' );

	function flashSaved( $card ) {
		var card = $card[ 0 ];
		window.clearTimeout( card._dyHide );
		$card.addClass( 'is-saved' );
		card._dyHide = window.setTimeout( function () {
			$card.removeClass( 'is-saved' );
		}, 2200 );
	}

	function revert( $card ) {
		var $input = $card.find( '.dy-mlset__input' );
		if ( $input.length ) {
			$input.prop( 'checked', ! $input.prop( 'checked' ) );
			$card.attr( 'data-state', $input.prop( 'checked' ) ? 'active' : 'default' );
			if ( 'light_theme' === $input.data( 'key' ) ) {
				document.body.classList.toggle( 'dy-ml-light', $input.prop( 'checked' ) );
			}
		}
	}

	function save( key, value, $card ) {
		$.post( cfg.ajaxUrl, {
			action: 'doubleyoupi_ml_save_setting',
			nonce:  cfg.nonce,
			key:    key,
			value:  value,
		} )
			.done( function ( res ) {
				if ( res && res.success ) {
					flashSaved( $card );
				} else {
					revert( $card );
				}
			} )
			.fail( function () {
				revert( $card );
			} );
	}

	// ── Toggles ────────────────────────────────────────────────────────────
	$root.on( 'change', '.dy-mlset__input', function () {
		var $input = $( this );
		var $card  = $input.closest( '.dy-mlset__card' );
		var on     = $input.prop( 'checked' );
		$card.attr( 'data-state', on ? 'active' : 'default' );
		// The theme shows at once: the stylesheets react to the body class alone.
		if ( 'light_theme' === $input.data( 'key' ) ) {
			document.body.classList.toggle( 'dy-ml-light', on );
		}
		save( $input.data( 'key' ), on ? '1' : '0', $card );
	} );

	// ── Quality slider: live value display + auto-save on release (debounced) ──────
	var $range = $root.find( '.dy-mlset__range' );
	var $val   = $( '#dy-mlset-quality-val' );
	var qTimer;

	$range.on( 'input', function () {
		$val.text( this.value );
	} );
	$range.on( 'change', function () {
		var v     = this.value;
		var $card = $( this ).closest( '.dy-mlset__card' );
		window.clearTimeout( qTimer );
		qTimer = window.setTimeout( function () {
			save( 'image_quality', v, $card );
		}, 150 );
	} );
} );
