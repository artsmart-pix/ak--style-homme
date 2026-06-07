/* AOD COD Form — front logic */
( function ( $ ) {
	'use strict';

	function fmt( n ) {
		n = Math.round( ( parseFloat( n ) || 0 ) * 100 ) / 100;
		return n.toLocaleString( 'fr-DZ' ) + ' ' + AOD_COD.currency;
	}

	$( function () {
		$( '.aod-cod' ).each( function () {
			var $box      = $( this );
			var $form     = $box.find( '.aod-cod__form' );
			var price     = parseFloat( $box.data( 'price' ) ) || 0;
			var productId = $box.data( 'product' );
			// Paliers de prix par quantité (packs) — produits simples.
			var tiers     = $box.data( 'tiers' ) || [];
			if ( typeof tiers === 'string' ) {
				try { tiers = JSON.parse( tiers ); } catch ( e ) { tiers = []; }
			}
			if ( ! Array.isArray( tiers ) ) { tiers = []; }

			// Prix unitaire applicable pour une quantité (palier le plus avantageux atteint).
			function tierUnit( qty ) {
				var unit = price, bestMin = 1;
				for ( var i = 0; i < tiers.length; i++ ) {
					var min = parseInt( tiers[ i ].min, 10 ) || 0;
					var p   = parseFloat( tiers[ i ].price ) || 0;
					if ( min >= 2 && p > 0 && p < price && qty >= min && min >= bestMin ) {
						bestMin = min;
						unit    = p;
					}
				}
				return unit;
			}
			var $wilaya   = $form.find( 'select[name="wilaya"]' );
			var $commune  = $form.find( 'select[name="commune"]' );
			var $qty      = $form.find( 'input[name="qty"]' );
			var $msg      = $box.find( '.aod-cod__msg' );
			// Sections d'options (Taille, Couleur…) : une valeur à choisir par section.
			var $optSecs   = $form.find( '.aod-cod__optsec' );
			var hasOptions = $optSecs.length > 0;

			var $hint = $box.find( '.aod-cod__free-hint' );

			// Somme des suppléments de prix des options sélectionnées.
			function optionSupplement() {
				var supp = 0;
				$optSecs.each( function () {
					var $sel = $( this ).find( 'input[type="radio"]:checked' );
					if ( $sel.length ) { supp += parseFloat( $sel.attr( 'data-price' ) ) || 0; }
				} );
				return supp;
			}

			// Sous-total produits : (prix de base palier + suppléments) × quantité.
			function productsSubtotal() {
				var qty = Math.max( 1, parseInt( $qty.val(), 10 ) || 1 );
				return ( tierUnit( qty ) + optionSupplement() ) * qty;
			}

			// Met en évidence le palier actif selon la quantité courante.
			function updateTierHint() {
				if ( ! tiers.length ) { return; }
				var qty = Math.max( 1, parseInt( $qty.val(), 10 ) || 1 );
				var bestMin = 1;
				for ( var i = 0; i < tiers.length; i++ ) {
					var min = parseInt( tiers[ i ].min, 10 ) || 0;
					if ( min >= 2 && qty >= min && min >= bestMin ) { bestMin = min; }
				}
				$box.find( '.aod-cod__tiers li' ).each( function () {
					var m = parseInt( $( this ).attr( 'data-min' ), 10 ) || 0;
					$( this ).toggleClass( 'is-active', m === bestMin );
				} );
			}

			// Quantité totale — utile pour le lead.
			function totalQty() {
				return Math.max( 1, parseInt( $qty.val(), 10 ) || 1 );
			}

			// Place la galerie WooCommerce sur la photo de la couleur choisie.
			// Les photos d'options sont injectées comme de VRAIES diapos du carrousel
			// côté PHP (chaque diapo porte `data-aod-attachment="<ID média>"`). On se
			// contente donc de naviguer vers la bonne diapo : on n'écrase plus aucune
			// image, et toutes les photos restent accessibles via les flèches.
			function swapGallery( $radio ) {
				var imgId = parseInt( $radio.attr( 'data-img-id' ), 10 ) || 0;
				if ( ! imgId ) { return; }
				var $gallery = $( '.woocommerce-product-gallery' );
				if ( ! $gallery.length ) { return; }
				var $slides = $gallery.find( '.woocommerce-product-gallery__image' ).not( '.clone' );
				var index = -1;
				$slides.each( function ( i ) {
					if ( parseInt( $( this ).attr( 'data-aod-attachment' ), 10 ) === imgId ) {
						index = i;
						return false;
					}
				} );
				if ( index < 0 ) { return; } // Photo absente de la galerie.
				goToSlide( $gallery, index );
			}

			// Seuil de livraison gratuite (0 = désactivé).
			function freeThreshold() {
				return ( AOD_COD.free_shipping && parseFloat( AOD_COD.free_shipping.threshold ) ) || 0;
			}

			// Cascade wilaya -> communes
			$wilaya.on( 'change', function () {
				var code = parseInt( $( this ).val(), 10 );
				var data = AOD_COD.wilayas[ code ];
				$commune.empty().append(
					$( '<option>' ).val( '' ).text( AOD_COD.i18n.choose_commune )
				);
				if ( data && data.communes ) {
					$.each( data.communes, function ( i, c ) {
						$commune.append( $( '<option>' ).val( c.v ).text( c.l ) );
					} );
					$commune.prop( 'disabled', false );
				} else {
					$commune.prop( 'disabled', true );
				}
				render();
			} );

			$form.on( 'change input', 'select[name="commune"], input[name="qty"], input[name="delivery"], .aod-cod__optsec input[type="radio"]', render );

			// Stepper quantité : boutons − / +. On respecte le minimum et on
			// déclenche `change` pour que le prix et le lead se recalculent.
			$form.on( 'click', '.aod-cod__step', function () {
				var step = parseInt( $( this ).attr( 'data-step' ), 10 ) || 0;
				var min  = parseInt( $qty.attr( 'min' ), 10 );
				if ( isNaN( min ) ) { min = 1; }
				var cur  = parseInt( $qty.val(), 10 );
				if ( isNaN( cur ) ) { cur = min; }
				$qty.val( Math.max( min, cur + step ) ).trigger( 'change' );
			} );

			// Tarif de base d'un mode de livraison pour la wilaya sélectionnée.
			function baseShip( type ) {
				var code = parseInt( $wilaya.val(), 10 );
				var w    = AOD_COD.wilayas[ code ];
				if ( ! w ) { return 0; }
				return ( type === 'desk' ) ? ( parseFloat( w.desk ) || 0 ) : ( parseFloat( w.home ) || 0 );
			}

			function subtotalNow() {
				return productsSubtotal();
			}

			// Recalcule cartes de livraison + récap + incitation au seuil.
			function render() {
				var subtotal = subtotalNow();
				var th       = freeThreshold();
				var free     = th > 0 && subtotal >= th;

				updateTierHint();

				// Prix sur chaque carte de mode de livraison.
				$box.find( '.aod-cod__radio-price' ).each( function () {
					var $p = $( this );
					var v  = baseShip( $p.data( 'type' ) );
					if ( v > 0 && free ) {
						$p.html( '<s>' + fmt( v ) + '</s> ' + AOD_COD.i18n.free );
					} else {
						$p.text( v > 0 ? fmt( v ) : '' );
					}
				} );

				// Récapitulatif.
				var type = $form.find( 'input[name="delivery"]:checked' ).val();
				var base = baseShip( type );
				var ship = free ? 0 : base;
				$box.find( '.aod-cod__subtotal' ).text( fmt( subtotal ) );
				$box.find( '.aod-cod__shipping' ).text( free && base > 0 ? AOD_COD.i18n.free : ( ship > 0 ? fmt( ship ) : '—' ) );
				$box.find( '.aod-cod__total' ).text( fmt( subtotal + ship ) );

				// Incitation au seuil.
				if ( th > 0 && free ) {
					$hint.text( AOD_COD.i18n.free_active ).removeAttr( 'hidden' );
				} else if ( th > 0 && subtotal > 0 ) {
					$hint.text( AOD_COD.i18n.free_hint.replace( '%s', fmt( th - subtotal ) ) ).removeAttr( 'hidden' );
				} else {
					$hint.attr( 'hidden', 'hidden' ).text( '' );
				}
			}

			render();

			if ( hasOptions ) {
				// Sélectionner une valeur « avec photo » change la grande image + marque la carte.
				$form.on( 'change', '.aod-cod__optsec input[type="radio"]', function () {
					var $sec = $( this ).closest( '.aod-cod__optsec' );
					$sec.find( '.aod-cod__opt' ).removeClass( 'is-selected' );
					$( this ).closest( '.aod-cod__opt' ).addClass( 'is-selected' );
					if ( $sec.hasClass( 'is-visual' ) ) {
						swapGallery( $( this ) );
					}
				} );
				// Photo initiale = première valeur visuelle pré-cochée, le cas échéant.
				var $firstVisual = $form.find( '.aod-cod__optsec.is-visual input[type="radio"]:checked' ).first();
				if ( $firstVisual.length ) { swapGallery( $firstVisual ); }
			}

			/* ---- Capture « panier abandonné » (prospect à rappeler) ---- */
			var leadToken   = 'l' + Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2, 8 );
			var leadStarted = false;
			var leadTimer   = null;

			function collectLead() {
				return {
					action: 'aod_cod_lead',
					nonce: AOD_COD.nonce,
					token: leadToken,
					product_id: productId,
					name: $form.find( 'input[name="name"]' ).val(),
					phone: $form.find( 'input[name="phone"]' ).val(),
					wilaya: $wilaya.val(),
					commune: $commune.val(),
					delivery: $form.find( 'input[name="delivery"]:checked' ).val(),
					qty: totalQty()
				};
			}

			function pushLead() {
				var phone = ( $form.find( 'input[name="phone"]' ).val() || '' ).replace( /\D+/g, '' );
				if ( phone.length < 8 ) { return; }
				leadStarted = true;
				$.post( AOD_COD.ajax_url, collectLead() ); // fire-and-forget
			}

			function scheduleLead() {
				if ( leadTimer ) { clearTimeout( leadTimer ); }
				leadTimer = setTimeout( pushLead, 900 );
			}

			// Enregistre dès que le téléphone perd le focus…
			$form.find( 'input[name="phone"]' ).on( 'blur', pushLead );
			// …puis à chaque modification une fois la saisie engagée.
			$form.on( 'input change', 'input[name="name"], input[name="phone"], input[name="qty"], .aod-cod__optsec input[type="radio"], select[name="wilaya"], select[name="commune"], input[name="delivery"]', function () {
				if ( leadStarted ) {
					scheduleLead();
				} else {
					var phone = ( $form.find( 'input[name="phone"]' ).val() || '' ).replace( /\D+/g, '' );
					if ( phone.length >= 9 ) { scheduleLead(); }
				}
			} );

			// Soumission
			$form.on( 'submit', function ( e ) {
				e.preventDefault();
				$msg.removeClass( 'is-error is-success' ).text( '' );

				var name    = $form.find( 'input[name="name"]' ).val().trim();
				var phone   = $form.find( 'input[name="phone"]' ).val().replace( /\D+/g, '' );
				var wilaya  = $wilaya.val();
				var commune = $commune.val();

				// Sélection des options : une valeur par section.
				var optSel     = {};
				var optMissing = false;
				$optSecs.each( function () {
					var si   = $( this ).data( 'si' );
					var $sel = $( this ).find( 'input[type="radio"]:checked' );
					if ( $sel.length ) { optSel[ si ] = $sel.val(); }
					else { optMissing = true; }
				} );

				if ( ! name || ! wilaya || ! commune ) {
					return showError( AOD_COD.i18n.required );
				}
				if ( hasOptions && optMissing ) {
					return showError( AOD_COD.i18n.choose_option || AOD_COD.i18n.required );
				}
				if ( ! /^0[5-7][0-9]{8}$/.test( phone ) ) {
					return showError( AOD_COD.i18n.phone_invalid );
				}

				var $btn = $form.find( '.aod-cod__submit' );
				$btn.prop( 'disabled', true );
				var original = $btn.text();
				$btn.text( AOD_COD.i18n.sending );

				var postData = {
					action: 'aod_cod_submit',
					nonce: AOD_COD.nonce,
					product_id: productId,
					name: name,
					phone: phone,
					wilaya: wilaya,
					commune: commune,
					address: $form.find( 'input[name="address"]' ).val(),
					delivery: $form.find( 'input[name="delivery"]:checked' ).val(),
					qty: $qty.val(),
					lead_token: leadToken
				};
				// Envoie opt[section]=valeur pour chaque section choisie.
				$.each( optSel, function ( si, v ) {
					postData[ 'opt[' + si + ']' ] = v;
				} );

				$.post( AOD_COD.ajax_url, postData ).done( function ( res ) {
					if ( res && res.success ) {
						$msg.addClass( 'is-success' ).text( res.data.message );
						if ( res.data.redirect ) {
							window.location.href = res.data.redirect;
						}
					} else {
						showError( ( res && res.data && res.data.message ) || 'Erreur.' );
						$btn.prop( 'disabled', false ).text( original );
					}
				} ).fail( function ( xhr ) {
					var m = ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) || 'Erreur réseau.';
					showError( m );
					$btn.prop( 'disabled', false ).text( original );
				} );
			} );

			function showError( m ) {
				$msg.addClass( 'is-error' ).text( m );
			}
		} );

		// Flèches ‹ › superposées sur la grande photo pour parcourir toutes les
		// images du produit. La galerie est unique sur la page : on initialise une
		// seule fois, hors de la boucle des formulaires.
		initGalleryNav();
	} );

	// Pilote la galerie WooCommerce (flexslider) : direction ('prev'/'next') ou
	// index de diapo (nombre). On privilégie l'API flexslider ; à défaut on simule
	// un clic sur la navigation/les miniatures natives.
	function goToSlide( $gallery, target ) {
		if ( $.fn.flexslider && $gallery.find( '.flex-viewport' ).length ) {
			try { $gallery.flexslider( target ); return; } catch ( e ) {}
		}
		if ( typeof target === 'number' ) {
			var $thumb = $gallery.find( '.flex-control-nav li' ).eq( target ).find( 'a, img' ).first();
			if ( $thumb.length ) { $thumb.trigger( 'click' ); }
			return;
		}
		var $link = $gallery.find( '.flex-direction-nav .flex-' + target + ' a' );
		if ( $link.length ) { $link.trigger( 'click' ); }
	}

	// Boutons précédent/suivant sur la galerie WooCommerce (flexslider).
	function initGalleryNav() {
		var $gallery = $( '.woocommerce-product-gallery' );
		if ( ! $gallery.length ) { return; }
		// Déjà initialisé (script chargé deux fois) → on ne duplique pas.
		if ( $gallery.find( '> .aod-gallery-nav' ).length ) { return; }
		// Compter les vraies diapos (hors clones que flexslider ajoute après init).
		var slideCount = $gallery.find( '.woocommerce-product-gallery__image' ).not( '.clone' ).length;
		if ( slideCount < 2 ) { return; } // une seule image : pas de navigation.

		var labels = ( window.AOD_COD && AOD_COD.i18n ) || {};
		var prevLabel = labels.prev_image || 'Image précédente';
		var nextLabel = labels.next_image || 'Image suivante';

		var $prev = $( '<button type="button" class="aod-gallery-nav aod-gallery-nav--prev" aria-label="' + prevLabel + '">‹</button>' );
		var $next = $( '<button type="button" class="aod-gallery-nav aod-gallery-nav--next" aria-label="' + nextLabel + '">›</button>' );

		$prev.on( 'click', function ( e ) { e.preventDefault(); goToSlide( $gallery, 'prev' ); } );
		$next.on( 'click', function ( e ) { e.preventDefault(); goToSlide( $gallery, 'next' ); } );

		$gallery.append( $prev, $next );
	}
} )( jQuery );
