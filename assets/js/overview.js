/**
 * overview.js — Double Youpi overview page: module toggles with auto-save.
 *
 * Each switch flip persists the module state immediately via admin-ajax
 * (action `doubleyoupi_modules_toggle`). By the time the user leaves the page, the
 * setting is already saved — no need to click "Save modules" (the button is
 * hidden as soon as this script runs; the form remains as a JS-less fallback).
 *
 * UX:
 *   - optimistic card update (state, label, Settings link, counter);
 *   - "saving → saved" micro-feedback on the switch, revert + toast on failure;
 *   - subtle "Reload to apply" button for modules that repaint the admin chrome
 *     (Refined Admin Dashboard), revealed only when the state differs from
 *     page-load — on both activation and deactivation.
 *
 * Vanilla JS, no dependencies (no jQuery).
 */
( function () {
	'use strict';

	var cfg = window.doubleyoupiOverview;
	if ( ! cfg ) {
		return;
	}

	var wrap = document.querySelector( '.dy-overview' );
	if ( ! wrap ) {
		return;
	}

	// Signal to CSS that auto-save is active: hides the "Save modules" button
	// and reveals the "Changes are saved automatically." hint.
	wrap.classList.add( 'dy-overview--js' );

	var i18n          = cfg.i18n || {};
	var reloadModules = cfg.reloadModules || [];
	var NBSP          = ' ';

	// Snapshot of state at page load — used to detect whether a reload-required
	// module (Admin) has returned to its initial state (→ hide the button).
	var initialState = {};

	var cards = Array.prototype.slice.call(
		wrap.querySelectorAll( '.dy-overview__card[data-module-id]' )
	);

	cards.forEach( function ( card ) {
		var id    = card.getAttribute( 'data-module-id' );
		var input = card.querySelector( '.dy-overview__switch input[type="checkbox"]' );
		if ( ! input ) {
			return; // "coming soon" cards have no switch.
		}
		initialState[ id ] = input.checked;
		input.addEventListener( 'change', function () {
			handleToggle( card, id, input );
		} );
	} );

	// "Reload to apply" buttons simply reload the page.
	wrap.querySelectorAll( '.dy-overview__reload' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			window.location.reload();
		} );
	} );

	// ─────────────────────────────────────────────────────────────
	// Module toggle
	// ─────────────────────────────────────────────────────────────
	function handleToggle( card, id, input ) {
		var enabled = input.checked;

		// 1. Optimistic UI: reflect the new state immediately.
		applyCardState( card, enabled );
		updateCounter();
		setSwitchState( card, 'saving' );

		if ( reloadModules.indexOf( id ) !== -1 ) {
			toggleReloadPill( card, enabled !== initialState[ id ] );
		}

		// 2. Persistance.
		var body = new URLSearchParams();
		body.set( 'action', cfg.action );
		body.set( 'nonce', cfg.nonce );
		body.set( 'module_id', id );
		body.set( 'enabled', enabled ? '1' : '0' );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( 'save_failed' );
				}
				setSwitchState( card, 'saved' );
				if ( json.data && typeof json.data.enabled_count !== 'undefined' ) {
					setCounter( json.data.enabled_count, json.data.available_count );
				}
			} )
			.catch( function () {
				// 3. Revert: restore the switch and card to their previous state.
				input.checked = ! enabled;
				applyCardState( card, input.checked );
				updateCounter();
				setSwitchState( card, 'error' );
				if ( reloadModules.indexOf( id ) !== -1 ) {
					toggleReloadPill( card, input.checked !== initialState[ id ] );
				}
				showToast( i18n.saveError || 'Could not save.' );
			} );
	}

	// ─────────────────────────────────────────────────────────────
	// Visual card update
	// ─────────────────────────────────────────────────────────────
	function applyCardState( card, enabled ) {
		card.classList.toggle( 'is-active', enabled );
		card.classList.toggle( 'is-inactive', ! enabled );

		var status = card.querySelector( '.dy-overview__status' );
		if ( status ) {
			status.textContent = enabled ? i18n.statusActive : i18n.statusInactive;
		}

		var label = card.querySelector( '.dy-overview__switch-label' );
		if ( label ) {
			label.textContent = enabled ? i18n.switchOn : i18n.switchOff;
		}

		syncSettingsLink( card, enabled );
	}

	// Add or remove the "Settings" link without a page reload.
	function syncSettingsLink( card, enabled ) {
		var url = card.getAttribute( 'data-settings-url' );
		if ( ! url ) {
			return;
		}
		var foot = card.querySelector( '.dy-overview__card-foot' );
		var link = card.querySelector( '.dy-overview__settings-link' );

		if ( enabled && ! link && foot ) {
			link = document.createElement( 'a' );
			link.className   = 'dy-overview__settings-link';
			link.href        = url;
			link.textContent = i18n.settings || 'Settings';
			// Insert before the optional "Reload to apply" button.
			var pill = card.querySelector( '.dy-overview__reload' );
			if ( pill ) {
				foot.insertBefore( link, pill );
			} else {
				foot.appendChild( link );
			}
		} else if ( ! enabled && link ) {
			link.parentNode.removeChild( link );
		}
	}

	// ─────────────────────────────────────────────────────────────
	// Switch micro-feedback (saving / saved / error)
	// ─────────────────────────────────────────────────────────────
	function setSwitchState( card, state ) {
		var sw = card.querySelector( '.dy-overview__switch' );
		if ( ! sw ) {
			return;
		}
		sw.classList.remove( 'is-saving', 'is-saved', 'is-error' );

		if ( state === 'saving' ) {
			sw.classList.add( 'is-saving' );
			hideSaved( card );
			return;
		}

		sw.classList.add( state === 'error' ? 'is-error' : 'is-saved' );
		window.clearTimeout( sw._dyTimer );
		sw._dyTimer = window.setTimeout( function () {
			sw.classList.remove( 'is-saved', 'is-error' );
		}, 1600 );

		// Show an explicit "✓ Saved" confirmation next to the toggle (for a few seconds).
		if ( state === 'saved' ) {
			showSaved( card );
		} else {
			hideSaved( card );
		}
	}

	// ─────────────────────────────────────────────────────────────
	// Transient "✓ Saved" badge next to the toggle
	// ─────────────────────────────────────────────────────────────
	function showSaved( card ) {
		var badge = card.querySelector( '.dy-overview__saved' );
		if ( ! badge ) {
			return;
		}
		window.clearTimeout( badge._dyHide );
		window.clearTimeout( badge._dyGone );
		badge.hidden = false;
		// Force a reflow so the fade-in transition starts from the hidden state.
		void badge.offsetWidth;
		badge.classList.add( 'is-in' );
		badge._dyHide = window.setTimeout( function () {
			badge.classList.remove( 'is-in' );
			badge._dyGone = window.setTimeout( function () {
				badge.hidden = true;
			}, 280 );
		}, 2200 );
	}

	function hideSaved( card ) {
		var badge = card.querySelector( '.dy-overview__saved' );
		if ( ! badge ) {
			return;
		}
		window.clearTimeout( badge._dyHide );
		window.clearTimeout( badge._dyGone );
		badge.classList.remove( 'is-in' );
		badge.hidden = true;
	}

	// ─────────────────────────────────────────────────────────────
	// "Reload to apply" button
	// ─────────────────────────────────────────────────────────────
	function toggleReloadPill( card, show ) {
		var pill = card.querySelector( '.dy-overview__reload' );
		if ( ! pill ) {
			return;
		}
		window.clearTimeout( pill._dyTimer );

		if ( show ) {
			pill.hidden = false;
			// Force a reflow so the entry transition plays.
			void pill.offsetWidth;
			pill.classList.add( 'is-visible' );
		} else {
			pill.classList.remove( 'is-visible' );
			pill._dyTimer = window.setTimeout( function () {
				pill.hidden = true;
			}, 320 );
		}
	}

	// ─────────────────────────────────────────────────────────────
	// "X / Y active modules" counter in the hero section
	// ─────────────────────────────────────────────────────────────
	var counterRow = wrap.querySelector( '[data-dy-counter]' );

	function setCounter( enabled, available ) {
		if ( ! counterRow ) {
			return;
		}
		var value = counterRow.querySelector( '.dy-overview__hero-meta-value' );
		if ( value ) {
			value.innerHTML = enabled + NBSP + '/' + NBSP + available;
		}
		counterRow.setAttribute( 'data-state', enabled > 0 ? 'active' : 'idle' );

		// Small "bump" animation on every change.
		counterRow.classList.remove( 'is-bumped' );
		void counterRow.offsetWidth;
		counterRow.classList.add( 'is-bumped' );
	}

	// Recount locally from the switches (optimistic update before the server
	// confirmation, which will then overwrite with the authoritative value).
	function updateCounter() {
		var total = 0;
		var on    = 0;
		cards.forEach( function ( card ) {
			var input = card.querySelector( '.dy-overview__switch input[type="checkbox"]' );
			if ( ! input ) {
				return;
			}
			total++;
			if ( input.checked ) {
				on++;
			}
		} );
		setCounter( on, total );
	}

	// ─────────────────────────────────────────────────────────────
	// Error toast
	// ─────────────────────────────────────────────────────────────
	var toastRegion;

	function showToast( message ) {
		if ( ! toastRegion ) {
			toastRegion = document.createElement( 'div' );
			toastRegion.className = 'dy-overview__toasts';
			toastRegion.setAttribute( 'role', 'status' );
			toastRegion.setAttribute( 'aria-live', 'polite' );
			document.body.appendChild( toastRegion );
		}

		var toast = document.createElement( 'div' );
		toast.className   = 'dy-overview__toast';
		toast.textContent = message;
		toastRegion.appendChild( toast );

		void toast.offsetWidth;
		toast.classList.add( 'is-visible' );

		window.setTimeout( function () {
			toast.classList.remove( 'is-visible' );
			window.setTimeout( function () {
				if ( toast.parentNode ) {
					toast.parentNode.removeChild( toast );
				}
			}, 320 );
		}, 3400 );
	}
}() );

/**
 * Product showcase — presentation modal + carousel.
 *
 * [data-dy-vitrine] cards (modules not bundled in this plugin) open a shared
 * modal [data-dy-modal] populated on the fly from their data attributes:
 * badge, title, description, screenshot carousel, and a contextual action
 * (free WP.org install / coming soon / discover the suite).
 *
 * Vanilla JS, no hard dependencies; wp.updates is used when available,
 * otherwise falls back to the native WordPress install screen.
 */
( function () {
	'use strict';

	var cfg  = window.doubleyoupiOverview || {};
	var i18n = cfg.i18n || {};

	var modal = document.querySelector( '[data-dy-modal]' );
	var grid  = document.querySelector( '.dy-overview__grid' );
	if ( ! modal || ! grid ) {
		return;
	}

	var track    = modal.querySelector( '[data-dy-carousel-track]' );
	var dotsWrap = modal.querySelector( '[data-dy-carousel-dots]' );
	var panel    = modal.querySelector( '.dy-overview__modal-panel' );
	var lastFocus = null;

	var slides  = [];
	var current = 0;

	// ── Open from a showcase card ────────────────────────────────────────────
	grid.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-dy-vitrine-open]' );
		if ( ! btn ) {
			return;
		}
		var card = btn.closest( '[data-dy-vitrine]' );
		if ( card ) {
			openFromCard( card );
		}
	} );

	function openFromCard( card ) {
		var data = {
			name:      card.getAttribute( 'data-name' ) || '',
			desc:      card.getAttribute( 'data-desc' ) || '',
			tier:      card.getAttribute( 'data-tier' ) || 'paid',
			badge:     card.getAttribute( 'data-badge' ) || '',
			installable: card.getAttribute( 'data-installable' ) === '1',
			slug:      card.getAttribute( 'data-wporg-slug' ) || '',
			marketing: card.getAttribute( 'data-marketing-url' ) || '',
			shots:     parseJSON( card.getAttribute( 'data-screenshots' ) )
		};

		// Badge
		var badge = modal.querySelector( '[data-dy-modal-badge]' );
		badge.textContent = data.badge;
		badge.className = 'dy-overview__badge dy-overview__badge--' + ( data.tier === 'free' ? 'free' : 'paid' );

		modal.querySelector( '[data-dy-modal-title]' ).textContent = data.name;
		modal.querySelector( '[data-dy-modal-desc]' ).textContent  = data.desc;

		buildCarousel( data.shots, data.name );
		buildActions( data );

		openModal();
	}

	// ── Carousel ─────────────────────────────────────────────────────────────
	function buildCarousel( shots, alt ) {
		track.innerHTML    = '';
		dotsWrap.innerHTML = '';
		slides  = [];
		current = 0;

		if ( ! shots.length ) {
			shots = [ '' ];
		}

		shots.forEach( function ( src, idx ) {
			var slide = document.createElement( 'div' );
			slide.className = 'dy-overview__carousel-slide';
			if ( src ) {
				var img = document.createElement( 'img' );
				img.src = src;
				img.alt = alt + ' ' + ( idx + 1 );
				img.loading = 'lazy';
				slide.appendChild( img );
			}
			track.appendChild( slide );
			slides.push( slide );

			var dot = document.createElement( 'button' );
			dot.type = 'button';
			dot.className = 'dy-overview__carousel-dot';
			dot.setAttribute( 'aria-label', String( idx + 1 ) );
			dot.addEventListener( 'click', function () { goTo( idx ); } );
			dotsWrap.appendChild( dot );
		} );

		var single = slides.length < 2;
		modal.querySelector( '[data-dy-carousel-prev]' ).hidden = single;
		modal.querySelector( '[data-dy-carousel-next]' ).hidden = single;
		dotsWrap.hidden = single;

		goTo( 0 );
	}

	function goTo( idx ) {
		if ( ! slides.length ) { return; }
		current = ( idx + slides.length ) % slides.length;
		track.style.transform = 'translateX(' + ( -current * 100 ) + '%)';
		Array.prototype.forEach.call( dotsWrap.children, function ( dot, i ) {
			dot.classList.toggle( 'is-active', i === current );
		} );
	}

	modal.querySelector( '[data-dy-carousel-prev]' ).addEventListener( 'click', function () { goTo( current - 1 ); } );
	modal.querySelector( '[data-dy-carousel-next]' ).addEventListener( 'click', function () { goTo( current + 1 ); } );

	// ── Contextual action ────────────────────────────────────────────────────
	function buildActions( data ) {
		var wrap = modal.querySelector( '[data-dy-modal-actions]' );
		wrap.innerHTML = '';

		if ( data.tier === 'free' && data.installable && data.slug ) {
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'dy-overview__btn dy-overview__btn--primary';
			btn.textContent = i18n.install || 'Install for free';
			btn.addEventListener( 'click', function () { installPlugin( data.slug, btn ); } );
			wrap.appendChild( btn );
		} else if ( data.tier === 'free' ) {
			var soon = document.createElement( 'span' );
			soon.className = 'dy-overview__btn dy-overview__btn--ghost is-disabled';
			soon.textContent = i18n.comingSoon || 'Soon on WordPress.org';
			wrap.appendChild( soon );
		}

		// Always include a "Discover" link to the product page (suite or details).
		if ( data.marketing ) {
			var link = document.createElement( 'a' );
			link.className = 'dy-overview__btn ' + ( data.tier === 'free' ? 'dy-overview__btn--ghost' : 'dy-overview__btn--primary' );
			link.href = data.marketing;
			link.target = '_blank';
			link.rel = 'noopener noreferrer';
			link.textContent = i18n.discover || 'Discover on doubleyoupi.com';
			wrap.appendChild( link );
		}
	}

	function installPlugin( slug, btn ) {
		var fallback = function () {
			if ( cfg.installBaseUrl ) {
				window.open( cfg.installBaseUrl + encodeURIComponent( slug ), '_blank', 'noopener' );
			}
		};

		if ( window.wp && window.wp.updates && typeof window.wp.updates.installPlugin === 'function' ) {
			btn.disabled = true;
			btn.textContent = i18n.installing || 'Installing…';
			try {
				window.wp.updates.installPlugin( {
					slug: slug,
					success: function () { btn.textContent = i18n.installed || 'Installed ✓'; },
					error: function () { btn.disabled = false; btn.textContent = i18n.installError || 'Install failed'; fallback(); }
				} );
			} catch ( err ) {
				btn.disabled = false;
				fallback();
			}
		} else {
			fallback();
		}
	}

	// ── Open / close ─────────────────────────────────────────────────────────
	function openModal() {
		lastFocus = document.activeElement;
		modal.hidden = false;
		modal.setAttribute( 'aria-hidden', 'false' );
		document.body.classList.add( 'dy-modal-open' );
		// Force reflow then apply the entry class (CSS transition).
		void modal.offsetWidth;
		modal.classList.add( 'is-open' );
		var closeBtn = modal.querySelector( '[data-dy-modal-close]' );
		if ( closeBtn ) { closeBtn.focus(); }
		document.addEventListener( 'keydown', onKeydown );
	}

	function closeModal() {
		modal.classList.remove( 'is-open' );
		modal.setAttribute( 'aria-hidden', 'true' );
		document.body.classList.remove( 'dy-modal-open' );
		document.removeEventListener( 'keydown', onKeydown );
		window.setTimeout( function () { modal.hidden = true; }, 240 );
		if ( lastFocus && typeof lastFocus.focus === 'function' ) { lastFocus.focus(); }
	}

	function onKeydown( e ) {
		if ( e.key === 'Escape' ) { closeModal(); }
		else if ( e.key === 'ArrowLeft' ) { goTo( current - 1 ); }
		else if ( e.key === 'ArrowRight' ) { goTo( current + 1 ); }
		else if ( e.key === 'Tab' ) { trapFocus( e ); }
	}

	function trapFocus( e ) {
		var focusables = panel.querySelectorAll( 'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])' );
		if ( ! focusables.length ) { return; }
		var first = focusables[0];
		var last  = focusables[ focusables.length - 1 ];
		if ( e.shiftKey && document.activeElement === first ) { e.preventDefault(); last.focus(); }
		else if ( ! e.shiftKey && document.activeElement === last ) { e.preventDefault(); first.focus(); }
	}

	Array.prototype.forEach.call( modal.querySelectorAll( '[data-dy-modal-close]' ), function ( el ) {
		el.addEventListener( 'click', closeModal );
	} );

	function parseJSON( str ) {
		try { var v = JSON.parse( str ); return Array.isArray( v ) ? v : []; }
		catch ( e ) { return []; }
	}
}() );
