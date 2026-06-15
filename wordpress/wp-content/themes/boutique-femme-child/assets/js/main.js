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
		contact();
	} );
} )();
