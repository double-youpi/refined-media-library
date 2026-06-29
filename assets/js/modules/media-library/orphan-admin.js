/* global doubleyoupiOrphanAdmin */
jQuery( function ( $ ) {
	'use strict';

	var i18n        = doubleyoupiOrphanAdmin.i18n;
	var ajaxUrl     = doubleyoupiOrphanAdmin.ajaxUrl;
	var nonceScan   = doubleyoupiOrphanAdmin.nonceScan;
	var nonceDelete = doubleyoupiOrphanAdmin.nonceDelete;

	var $scanBtn       = $( '#dy-orphan-scan-btn' );
	var $scanSpinner   = $( '#dy-orphan-spinner' );
	var $results       = $( '#dy-orphan-results' );
	var $summary       = $( '#dy-orphan-summary' );
	var $tbody         = $( '#dy-orphan-tbody' );
	var $checkAll      = $( '#dy-orphan-check-all' );
	// The confirmation block is rendered twice (above AND below the table);
	// these selectors deliberately match BOTH copies, kept in sync below.
	var $confirmBlock  = $( '.dy-orphan-confirm' );
	var $confirmCb     = $( '.dy-orphan-confirm-cb' );
	var $confirmLabel  = $( '.dy-orphan-confirm-label' );
	var $deleteBtn     = $( '.dy-orphan-delete-btn' );
	var $deleteSpinner = $( '.dy-orphan-delete-spinner' );
	var $done          = $( '#dy-orphan-done' );

	// ── Scan ──────────────────────────────────────────────────────────────────

	$scanBtn.on( 'click', function () {
		$scanBtn.prop( 'disabled', true );
		$scanSpinner.css( 'visibility', 'visible' );
		$results.hide();
		$done.hide().html( '' );
		$tbody.html( '' );

		$.post( ajaxUrl, {
			action: 'doubleyoupi_ml_orphan_scan',
			nonce:  nonceScan,
		} )
		.done( function ( response ) {
			if ( ! response.success ) {
				return;
			}
			var data = response.data;

			if ( data.count === 0 ) {
				$summary.html(
					'<span class="dashicons dashicons-yes-alt" style="color:#00a32a;vertical-align:-4px;margin-right:4px"></span>' +
					i18n.noOrphans
				);
				$results.show();
				$confirmBlock.hide();
				return;
			}

			$summary.text(
				( data.count > 1 ? i18n.foundMany : i18n.foundOne ).replace( '%d', data.count )
			);
			renderTable( data.orphans );
			$results.show();
			$confirmBlock.show();
			updateState();
		} )
		.always( function () {
			$scanBtn.prop( 'disabled', false );
			$scanSpinner.css( 'visibility', 'hidden' );
		} );
	} );

	// ── Table ─────────────────────────────────────────────────────────────────

	function renderTable( orphans ) {
		$tbody.html( '' );
		orphans.forEach( function ( item ) {
			var thumb = item.thumbnail
				? '<img src="' + escHtml( item.thumbnail ) + '" width="40" height="40" style="object-fit:cover;border-radius:2px" alt="">'
				: '<span class="dy-no-thumb dashicons dashicons-format-image"></span>';

			var $tr = $( '<tr>' )
				.attr( 'data-id', item.id )
				.html(
					'<td class="check-column">' +
						'<input type="checkbox" class="dy-orphan-cb" value="' + item.id + '">' +
					'</td>' +
					'<td>' + thumb + '</td>' +
					'<td>' +
						'<strong>' + escHtml( item.title ) + '</strong>' +
						'<br><span class="description">' + escHtml( item.filename ) + '</span>' +
					'</td>' +
					'<td>' + escHtml( item.mime ) + '</td>' +
					'<td>' + escHtml( item.size ) + '</td>' +
					'<td>' + escHtml( item.date ) + '</td>'
				);
			$tbody.append( $tr );
		} );
	}

	// ── Checkboxes ────────────────────────────────────────────────────────────

	$checkAll.on( 'change', function () {
		$( '.dy-orphan-cb' ).prop( 'checked', this.checked );
		updateState();
	} );

	$tbody.on( 'change', '.dy-orphan-cb', function () {
		var total   = $( '.dy-orphan-cb' ).length;
		var checked = $( '.dy-orphan-cb:checked' ).length;
		$checkAll.prop( 'indeterminate', checked > 0 && checked < total );
		$checkAll.prop( 'checked', total > 0 && checked === total );
		updateState();
	} );

	function updateState() {
		var n = $( '.dy-orphan-cb:checked' ).length;
		$confirmLabel.text( i18n.confirmLabel.replace( '%d', n ) );
		// Reset confirmation whenever selection changes.
		$confirmCb.prop( 'checked', false );
		$deleteBtn.prop( 'disabled', true );
		if ( n > 0 ) {
			$confirmBlock.show();
		} else {
			$confirmBlock.hide();
		}
	}

	// ── Confirmation checkbox ─────────────────────────────────────────────────

	$confirmCb.on( 'change', function () {
		// Keep the top and bottom confirmation checkboxes mirrored.
		$confirmCb.prop( 'checked', this.checked );
		var hasSelection = $( '.dy-orphan-cb:checked' ).length > 0;
		$deleteBtn.prop( 'disabled', ! this.checked || ! hasSelection );
	} );

	// ── Delete ────────────────────────────────────────────────────────────────

	$deleteBtn.on( 'click', function () {
		var ids = $( '.dy-orphan-cb:checked' ).map( function () {
			return parseInt( $( this ).val(), 10 );
		} ).get();

		if ( ! ids.length ) {
			return;
		}

		$deleteBtn.prop( 'disabled', true );
		$deleteSpinner.css( 'visibility', 'visible' );

		$.post( ajaxUrl, {
			action: 'doubleyoupi_ml_orphan_delete',
			nonce:  nonceDelete,
			ids:    ids,
		} )
		.done( function ( response ) {
			if ( ! response.success ) {
				return;
			}
			var data = response.data;
			var pending = data.deleted.length;

			if ( ! pending ) {
				showDone( data );
				return;
			}

			data.deleted.forEach( function ( id ) {
				$( 'tr[data-id="' + id + '"]' ).fadeOut( 200, function () {
					$( this ).remove();
					pending--;
					if ( pending === 0 ) {
						showDone( data );
					}
				} );
			} );
		} )
		.always( function () {
			$deleteSpinner.css( 'visibility', 'hidden' );
			$confirmBlock.hide();
			$confirmCb.prop( 'checked', false );
		} );
	} );

	function showDone( data ) {
		var remaining = $( '.dy-orphan-cb' ).length;
		if ( remaining === 0 ) {
			$results.hide();
		} else {
			$summary.text(
				( remaining > 1 ? i18n.remainingMany : i18n.remainingOne ).replace( '%d', remaining )
			);
			updateState();
		}

		var msg = i18n.deleted.replace( '%d', data.deleted.length );
		if ( data.errors.length ) {
			msg += ' ' + i18n.errors.replace( '%d', data.errors.length );
		}
		$done
			.html( '<div class="notice notice-success inline"><p>' + escHtml( msg ) + '</p></div>' )
			.show();
	}

	// ── Helper ────────────────────────────────────────────────────────────────

	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}
} );
