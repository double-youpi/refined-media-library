/* global doubleyoupiOrphanAdmin */
jQuery( function ( $ ) {
	'use strict';

	var i18n        = doubleyoupiOrphanAdmin.i18n;
	var ajaxUrl     = doubleyoupiOrphanAdmin.ajaxUrl;
	var nonceScan   = doubleyoupiOrphanAdmin.nonceScan;
	var nonceDelete = doubleyoupiOrphanAdmin.nonceDelete;
	var nonceRestore = doubleyoupiOrphanAdmin.nonceRestore;
	var noncePurge   = doubleyoupiOrphanAdmin.noncePurge;

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

	// ── Recently removed ──────────────────────────────────────────────────────
	var $trashTable   = $( '#dy-orphan-trash-table' );
	var $trashTbody   = $( '#dy-orphan-trash-tbody' );
	var $trashEmpty   = $( '#dy-orphan-trash-empty' );
	var $trashActions = $( '#dy-orphan-trash-actions' );
	var $trashSpinner = $( '#dy-orphan-trash-spinner' );
	var $trashDone    = $( '#dy-orphan-trash-done' );

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
		$confirmLabel.text( compte( 'confirmLabel', n ) );
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

		/*
		 * Le compte rendu ne dit que ce qui a eu lieu. « 0 fichier mis à la
		 * corbeille » n'apprend rien, et oblige à trancher un pluriel dont la
		 * règle change d'une langue à l'autre pour zéro.
		 *
		 * Les fichiers conservés sont ceux que le serveur a refusé de bouger :
		 * une vérification fraîche les a trouvés utilisés. Leurs lignes restent
		 * dans le tableau exprès, ce ne sont pas des orphelins.
		 */
		var parts = [];
		if ( data.deleted.length ) { parts.push( compte( 'deleted', data.deleted.length ) ); }
		if ( data.kept && data.kept.length ) { parts.push( compte( 'kept', data.kept.length ) ); }
		if ( data.errors.length ) { parts.push( compte( 'errors', data.errors.length ) ); }
		var msg = parts.join( ' ' );
		$done
			.html( '<div class="notice notice-success inline"><p>' + escHtml( msg ) + '</p></div>' )
			.show();

		addTrashRows( data.rows );
	}

	// ── Recently removed: putting back, and erasing ───────────────────────────

	/*
	 * The section shows itself when it has something to hold and hides when it
	 * runs out, so an empty trash never sits there as a row of furniture.
	 */
	function syncTrashVisibility() {
		var has = $trashTbody.children( 'tr' ).length > 0;
		$trashTable.toggle( has );
		$trashActions.toggle( has );
		$trashEmpty.toggle( ! has );
	}

	function trashMessage( text ) {
		$trashDone
			.html( '<div class="notice notice-success inline"><p>' + escHtml( text ) + '</p></div>' )
			.show();
	}

	// Rows arrive from the server already built, so a file that has just been
	// moved is rendered by the same code as one that was already there.
	function addTrashRows( rows ) {
		if ( ! rows || ! rows.length ) {
			return;
		}
		$trashTbody.prepend( rows.join( '' ) );
		syncTrashVisibility();
	}

	$trashTbody.on( 'click', '.dy-orphan-restore', function () {
		var $row = $( this ).closest( 'tr' );
		var id   = $row.data( 'id' );
		$( this ).prop( 'disabled', true );

		$.post( ajaxUrl, { action: 'doubleyoupi_ml_orphan_restore', nonce: nonceRestore, ids: [ id ] } )
			.done( function ( response ) {
				if ( ! response.success || ! response.data.restored.length ) {
					$row.find( '.dy-orphan-restore' ).prop( 'disabled', false );
					return;
				}
				$row.fadeOut( 200, function () {
					$( this ).remove();
					syncTrashVisibility();
				} );
				trashMessage( compte( 'restored', response.data.restored.length ) );
			} );
	} );

	$trashTbody.on( 'click', '.dy-orphan-purge', function () {
		if ( ! window.confirm( compte( 'confirmPurge', 1 ) ) ) {
			return;
		}
		var $row = $( this ).closest( 'tr' );
		var id   = $row.data( 'id' );
		$( this ).prop( 'disabled', true );

		$.post( ajaxUrl, { action: 'doubleyoupi_ml_orphan_purge', nonce: noncePurge, ids: [ id ] } )
			.done( function ( response ) {
				if ( ! response.success || ! response.data.purged.length ) {
					$row.find( '.dy-orphan-purge' ).prop( 'disabled', false );
					return;
				}
				$row.fadeOut( 200, function () {
					$( this ).remove();
					syncTrashVisibility();
				} );
				trashMessage( compte( 'purged', response.data.purged.length ) );
			} );
	} );

	$( '#dy-orphan-purge-all' ).on( 'click', function () {
		var count = $trashTbody.children( 'tr' ).length;
		if ( ! count || ! window.confirm( compte( 'confirmPurge', count ) ) ) {
			return;
		}
		var $btn = $( this );
		$btn.prop( 'disabled', true );
		$trashSpinner.css( 'visibility', 'visible' );

		$.post( ajaxUrl, { action: 'doubleyoupi_ml_orphan_purge', nonce: noncePurge, all: '1' } )
			.done( function ( response ) {
				if ( ! response.success ) {
					return;
				}
				$trashTbody.empty();
				syncTrashVisibility();
				trashMessage( compte( 'purged', response.data.purged.length ) );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
				$trashSpinner.css( 'visibility', 'hidden' );
			} );
	} );

	/*
	 * Choisit la forme d'un message compté.
	 *
	 * Les comptes ne sont connus qu'ici, dans le navigateur, donc _n() de
	 * WordPress ne peut pas trancher côté serveur : les deux formes sont
	 * envoyées et le choix se fait à l'affichage.
	 */
	function compte( cle, n ) {
		var forme = i18n[ cle + ( n > 1 ? 'Many' : 'One' ) ];
		return String( forme ).replace( '%d', n );
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
