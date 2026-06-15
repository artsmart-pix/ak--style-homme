/**
 * Boutique Femme — interactions front-end (vanilla, sans dépendance).
 *  - Header dynamique au scroll
 *  - Menu mobile (off-canvas)
 *  - Révélation des sections au scroll
 *  - Formulaire de contact en AJAX
 */
( function () {
	'use strict';

	var doc = document;

	function ready( fn ) {
		if ( doc.readyState !== 'loading' ) { fn(); }
		else { doc.addEventListener( 'DOMContentLoaded', fn ); }
	}

	/* ---- Header : compacte au scroll ---------------------------------- */
	function header() {
		var el = doc.querySelector( '[data-header]' );
		if ( ! el ) { return; }
		var onScroll = function () {
			el.classList.toggle( 'is-scrolled', window.pageYOffset > 24 );
		};
		onScroll();
		window.addEventListener( 'scroll', onScroll, { passive: true } );
	}

	/* ---- Menu mobile off-canvas --------------------------------------- */
	function offcanvas() {
		var burger = doc.querySelector( '[data-burger]' );
		var panel  = doc.querySelector( '[data-offcanvas]' );
		if ( ! burger || ! panel ) { return; }

		function open() {
			panel.hidden = false;
			// reflow pour l'animation
			void panel.offsetWidth;
			panel.classList.add( 'is-open' );
			burger.setAttribute( 'aria-expanded', 'true' );
			doc.body.classList.add( 'bf-no-scroll' );
		}
		function close() {
			panel.classList.remove( 'is-open' );
			burger.setAttribute( 'aria-expanded', 'false' );
			doc.body.classList.remove( 'bf-no-scroll' );
			window.setTimeout( function () { panel.hidden = true; }, 300 );
		}

		burger.addEventListener( 'click', open );
		panel.querySelectorAll( '[data-offcanvas-close]' ).forEach( function ( b ) {
			b.addEventListener( 'click', close );
		} );
		panel.querySelectorAll( 'a' ).forEach( function ( a ) {
			a.addEventListener( 'click', close );
		} );
		doc.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && ! panel.hidden ) { close(); }
		} );
	}

	/* ---- Révélation au scroll ----------------------------------------- */
	function reveals() {
		var items = doc.querySelectorAll( '.reveal' );
		if ( ! items.length ) { return; }

		if ( ! ( 'IntersectionObserver' in window ) ||
			window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			items.forEach( function ( el ) { el.classList.add( 'is-visible' ); } );
			return;
		}

		var io = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					entry.target.classList.add( 'is-visible' );
					io.unobserve( entry.target );
				}
			} );
		}, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' } );

		items.forEach( function ( el ) { io.observe( el ); } );
	}

	/* ---- Tri boutique : dropdown custom (remplace le popup natif) ------ */
	function orderby() {
		var selects = doc.querySelectorAll( '.woocommerce-ordering select.orderby' );
		if ( ! selects.length ) { return; }

		selects.forEach( function ( sel ) {
			if ( sel.dataset.bfReady ) { return; }
			sel.dataset.bfReady = '1';

			var form = sel.closest( 'form' );
			var opts = Array.prototype.slice.call( sel.options );

			var wrap = doc.createElement( 'div' );
			wrap.className = 'bf-orderby';

			var btn = doc.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'bf-orderby__btn';
			btn.setAttribute( 'aria-haspopup', 'listbox' );
			btn.setAttribute( 'aria-expanded', 'false' );
			btn.innerHTML = '<span class="bf-orderby__label"></span>' +
				'<svg class="bf-orderby__chev" width="14" height="14" viewBox="0 0 24 24" fill="none" ' +
				'stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
				'<polyline points="6 9 12 15 18 9"/></svg>';
			var label = btn.querySelector( '.bf-orderby__label' );

			var list = doc.createElement( 'ul' );
			list.className = 'bf-orderby__list';
			list.setAttribute( 'role', 'listbox' );
			list.hidden = true;

			opts.forEach( function ( o, i ) {
				var li = doc.createElement( 'li' );
				li.className = 'bf-orderby__opt';
				li.setAttribute( 'role', 'option' );
				li.dataset.value = o.value;
				li.tabIndex = -1;
				li.textContent = o.text;
				if ( o.selected ) { li.setAttribute( 'aria-selected', 'true' ); label.textContent = o.text; }
				li.addEventListener( 'click', function () { choose( i ); } );
				list.appendChild( li );
			} );
			if ( ! label.textContent ) { label.textContent = opts.length ? opts[0].text : ''; }

			// On masque le select natif (gardé pour le fallback no-JS et la soumission).
			sel.classList.add( 'bf-visually-hidden' );
			sel.setAttribute( 'tabindex', '-1' );
			sel.setAttribute( 'aria-hidden', 'true' );
			wrap.appendChild( btn );
			wrap.appendChild( list );
			sel.parentNode.insertBefore( wrap, sel );

			function open() {
				list.hidden = false;
				wrap.classList.add( 'is-open' );
				btn.setAttribute( 'aria-expanded', 'true' );
				var cur = list.querySelector( '[aria-selected="true"]' );
				if ( cur ) { cur.focus(); }
			}
			function close() {
				list.hidden = true;
				wrap.classList.remove( 'is-open' );
				btn.setAttribute( 'aria-expanded', 'false' );
			}
			function toggle() { list.hidden ? open() : close(); }

			function choose( i ) {
				if ( sel.selectedIndex === i && form ) { /* même choix : on recharge quand même */ }
				sel.selectedIndex = i;
				label.textContent = opts[ i ].text;
				list.querySelectorAll( '.bf-orderby__opt' ).forEach( function ( li, k ) {
					if ( k === i ) { li.setAttribute( 'aria-selected', 'true' ); }
					else { li.removeAttribute( 'aria-selected' ); }
				} );
				close();
				// WooCommerce soumet le formulaire au change ; on le fait nous-mêmes pour fiabilité.
				sel.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				if ( form && typeof form.requestSubmit === 'function' ) { form.requestSubmit(); }
				else if ( form ) { form.submit(); }
			}

			btn.addEventListener( 'click', toggle );
			doc.addEventListener( 'click', function ( e ) {
				if ( ! wrap.contains( e.target ) ) { close(); }
			} );
			wrap.addEventListener( 'keydown', function ( e ) {
				var items = Array.prototype.slice.call( list.querySelectorAll( '.bf-orderby__opt' ) );
				var idx = items.indexOf( doc.activeElement );
				if ( e.key === 'Escape' ) { close(); btn.focus(); }
				else if ( e.key === 'ArrowDown' ) { e.preventDefault(); if ( list.hidden ) { open(); } else if ( idx < items.length - 1 ) { items[ idx + 1 ].focus(); } }
				else if ( e.key === 'ArrowUp' ) { e.preventDefault(); if ( idx > 0 ) { items[ idx - 1 ].focus(); } }
				else if ( ( e.key === 'Enter' || e.key === ' ' ) && idx > -1 ) { e.preventDefault(); choose( idx ); }
			} );
		} );
	}

	/* ---- Formulaire de contact (AJAX) --------------------------------- */
	function contact() {
		var form = doc.getElementById( 'bf-contact-form' );
		if ( ! form || typeof window.BF === 'undefined' ) { return; }
		var msg    = form.querySelector( '.bf-form__msg' );
		var submit = form.querySelector( '.bf-form__submit' );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			msg.className = 'bf-form__msg';

			var required = form.querySelectorAll( '[required]' );
			var ok = true;
			required.forEach( function ( f ) {
				if ( ! String( f.value ).trim() ) { ok = false; f.classList.add( 'is-invalid' ); }
				else { f.classList.remove( 'is-invalid' ); }
			} );
			if ( ! ok ) {
				msg.textContent = window.BF.i18n.fill;
				msg.classList.add( 'is-error' );
				return;
			}

			var data = new FormData( form );
			data.append( 'action', 'bf_contact' );
			data.append( 'nonce', window.BF.nonce );

			submit.disabled = true;
			var label = submit.textContent;
			submit.textContent = window.BF.i18n.sending;

			fetch( window.BF.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( res && res.success ) {
						form.reset();
						msg.textContent = ( res.data && res.data.message ) || window.BF.i18n.sent;
						msg.classList.add( 'is-ok' );
					} else {
						msg.textContent = ( res && res.data && res.data.message ) || window.BF.i18n.error;
						msg.classList.add( 'is-error' );
					}
				} )
				.catch( function () {
					msg.textContent = window.BF.i18n.error;
					msg.classList.add( 'is-error' );
				} )
				.finally( function () {
					submit.disabled = false;
					submit.textContent = label;
				} );
		} );
	}

	ready( function () {
		header();
		offcanvas();
		reveals();
		orderby();
		contact();
	} );
} )();
