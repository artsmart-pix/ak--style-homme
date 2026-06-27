/**
 * AK Style — moteur d'animations « spectaculaire » (vanilla, sans dépendance).
 *  - Barre de progression de scroll
 *  - Curseur personnalisé (point néon + anneau, suivi fluide)
 *  - Boutons magnétiques
 *  - Inclinaison 3D des cartes au survol
 * Tout est désactivé au tactile et si prefers-reduced-motion.
 */
( function () {
	'use strict';

	var reduce = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	var fine   = window.matchMedia( '(hover: hover) and (pointer: fine)' ).matches;
	var doc = document;

	function ready( fn ) {
		if ( doc.readyState !== 'loading' ) { fn(); }
		else { doc.addEventListener( 'DOMContentLoaded', fn ); }
	}

	/* ---- Barre de progression de scroll ------------------------------- */
	function progress() {
		if ( reduce ) { return; }
		var bar = doc.createElement( 'div' );
		bar.className = 'ak-progress';
		doc.body.appendChild( bar );
		function update() {
			var h = doc.documentElement;
			var max = h.scrollHeight - h.clientHeight;
			var p = max > 0 ? h.scrollTop / max : 0;
			bar.style.transform = 'scaleX(' + p + ')';
		}
		update();
		window.addEventListener( 'scroll', update, { passive: true } );
		window.addEventListener( 'resize', update );
	}

	/* ---- Curseur personnalisé ----------------------------------------- */
	function cursor() {
		if ( reduce || ! fine ) { return; }
		var dot  = doc.createElement( 'div' ); dot.className = 'ak-cursor';
		var ring = doc.createElement( 'div' ); ring.className = 'ak-cursor-ring';
		doc.body.appendChild( dot ); doc.body.appendChild( ring );

		var mx = window.innerWidth / 2, my = window.innerHeight / 2;
		var rx = mx, ry = my;
		window.addEventListener( 'mousemove', function ( e ) {
			mx = e.clientX; my = e.clientY;
			dot.style.transform = 'translate(' + mx + 'px,' + my + 'px) translate(-50%,-50%)';
		}, { passive: true } );

		( function loop() {
			rx += ( mx - rx ) * 0.18; ry += ( my - ry ) * 0.18;
			ring.style.transform = 'translate(' + rx + 'px,' + ry + 'px) translate(-50%,-50%)';
			window.requestAnimationFrame( loop );
		} )();

		var hot = 'a, button, .bf-btn, .bf-cat, input, textarea, [role="option"], .woocommerce ul.products li.product';
		doc.addEventListener( 'mouseover', function ( e ) {
			if ( e.target.closest( hot ) ) { doc.body.classList.add( 'ak-cursor-hot' ); }
		} );
		doc.addEventListener( 'mouseout', function ( e ) {
			if ( e.target.closest( hot ) ) { doc.body.classList.remove( 'ak-cursor-hot' ); }
		} );
	}

	/* ---- Boutons magnétiques ------------------------------------------ */
	function magnetic() {
		if ( reduce || ! fine ) { return; }
		var btns = doc.querySelectorAll( '.bf-btn' );
		btns.forEach( function ( b ) {
			b.addEventListener( 'mousemove', function ( e ) {
				var r = b.getBoundingClientRect();
				var x = e.clientX - r.left - r.width / 2;
				var y = e.clientY - r.top - r.height / 2;
				b.style.transform = 'translate(' + ( x * 0.25 ) + 'px,' + ( y * 0.35 ) + 'px)';
			} );
			b.addEventListener( 'mouseleave', function () { b.style.transform = ''; } );
		} );
	}

	/* ---- Inclinaison 3D des cartes ------------------------------------ */
	function tilt() {
		if ( reduce || ! fine ) { return; }
		var cards = doc.querySelectorAll( '.bf-cat, .woocommerce ul.products li.product' );
		cards.forEach( function ( c ) {
			c.addEventListener( 'mousemove', function ( e ) {
				var r = c.getBoundingClientRect();
				var px = ( e.clientX - r.left ) / r.width - 0.5;
				var py = ( e.clientY - r.top ) / r.height - 0.5;
				c.style.transform = 'perspective(900px) rotateX(' + ( -py * 6 ) + 'deg) rotateY(' + ( px * 7 ) + 'deg) translateY(-6px)';
			} );
			c.addEventListener( 'mouseleave', function () { c.style.transform = ''; } );
		} );
	}

	/* ---- Parallax du héros (contenu + photo en profondeur) ------------ */
	function parallaxHero() {
		if ( reduce ) { return; }
		var hero  = doc.querySelector( '.bf-hero' );
		var inner = hero && hero.querySelector( '.bf-hero__inner' );
		if ( ! hero || ! inner ) { return; }
		function onScroll() {
			var y = window.pageYOffset;
			if ( y > window.innerHeight * 1.1 ) { return; }
			inner.style.transform = 'translateY(' + ( y * 0.34 ) + 'px)';
			inner.style.opacity = String( Math.max( 0, 1 - y / ( window.innerHeight * 0.85 ) ) );
		}
		onScroll();
		window.addEventListener( 'scroll', onScroll, { passive: true } );
	}

	/* ---- Distorsion liquide des images au survol (filtre SVG) --------- */
	function distortion() {
		var holder = doc.createElement( 'div' );
		holder.className = 'ak-svg-filters';
		holder.setAttribute( 'aria-hidden', 'true' );
		holder.innerHTML =
			'<svg xmlns="http://www.w3.org/2000/svg">' +
			'<filter id="ak-distort" x="-20%" y="-20%" width="140%" height="140%">' +
			'<feTurbulence type="fractalNoise" baseFrequency="0.01 0.012" numOctaves="2" result="n">' +
			'<animate attributeName="baseFrequency" dur="7s" ' +
			'values="0.008 0.01;0.016 0.022;0.008 0.01" repeatCount="indefinite"/>' +
			'</feTurbulence>' +
			'<feDisplacementMap in="SourceGraphic" in2="n" scale="14" ' +
			'xChannelSelector="R" yChannelSelector="G"/>' +
			'</filter></svg>';
		doc.body.appendChild( holder );
	}

	/* ---- Transitions entre pages (volet dégradé) --------------------- */
	function pageTransition() {
		if ( reduce ) { return; }
		var ov = doc.createElement( 'div' );
		ov.className = 'ak-transition';
		doc.body.appendChild( ov );

		doc.addEventListener( 'click', function ( e ) {
			var a = e.target.closest( 'a' );
			if ( ! a ) { return; }
			if ( a.target === '_blank' || a.hasAttribute( 'download' ) ) { return; }
			if ( e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0 ) { return; }
			if ( a.closest( '.aod-lang-switcher, .aod-lang' ) ) { return; }
			var href = a.getAttribute( 'href' ) || '';
			if ( ! href || href.charAt( 0 ) === '#' ||
				href.indexOf( 'mailto:' ) === 0 || href.indexOf( 'tel:' ) === 0 ||
				href.indexOf( 'javascript:' ) === 0 ) { return; }
			var url;
			try { url = new URL( a.href, window.location.href ); } catch ( err ) { return; }
			if ( url.origin !== window.location.origin ) { return; }
			// même page (ancre interne) : on laisse le navigateur faire.
			if ( url.pathname === window.location.pathname && url.search === window.location.search ) { return; }

			e.preventDefault();
			ov.classList.add( 'is-exit' );
			window.setTimeout( function () { window.location.href = a.href; }, 480 );
		} );

		// Retour via cache navigateur (bfcache) : on retire le volet.
		window.addEventListener( 'pageshow', function ( ev ) {
			if ( ev.persisted ) { ov.classList.remove( 'is-exit' ); }
		} );
	}

	/* ---- Apparition en cascade des cartes produit -------------------- */
	function cardsReveal() {
		var cards = doc.querySelectorAll( '.bf-card' );
		if ( ! cards.length ) { return; }
		if ( reduce || ! ( 'IntersectionObserver' in window ) ) { return; } // laissées visibles
		var io = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( e ) {
				if ( e.isIntersecting ) { e.target.classList.add( 'is-in' ); io.unobserve( e.target ); }
			} );
		}, { threshold: 0.1, rootMargin: '0px 0px -6% 0px' } );
		cards.forEach( function ( c, i ) {
			c.classList.add( 'bf-card--anim' );
			c.style.transitionDelay = ( ( i % 3 ) * 90 ) + 'ms';
			io.observe( c );
		} );
	}

	ready( function () {
		progress();
		cursor();
		magnetic();
		tilt();
		parallaxHero();
		distortion();
		pageTransition();
		cardsReveal();
	} );
} )();
