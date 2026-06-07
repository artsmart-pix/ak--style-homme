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

			// Remplace la grande photo de la galerie WooCommerce par celle de la couleur choisie.
			function swapGallery( $radio ) {
				var full = $radio.attr( 'data-img-full' ) || $radio.attr( 'data-img' );
				if ( ! full ) { return; }
				var srcset  = $radio.attr( 'data-srcset' ) || '';
				var w       = $radio.attr( 'data-img-w' ) || '';
				var h       = $radio.attr( 'data-img-h' ) || '';
				var $gallery = $( '.woocommerce-product-gallery' );
				if ( ! $gallery.length ) { return; }
				// Image principale = première de la galerie (gère flexslider/photoswipe).
				var $cell = $gallery.find( '.woocommerce-product-gallery__image' ).first();
				if ( ! $cell.length ) { $cell = $gallery; }
				// `.not( '.zoomImg' )` : ignorer le calque de zoom injecté par jquery.zoom.
				var $img = $cell.find( 'img' ).not( '.zoomImg' ).first();
				if ( ! $img.length ) { return; }
				$img.attr( 'src', full );
				if ( srcset ) { $img.attr( 'srcset', srcset ); } else { $img.removeAttr( 'srcset' ); }
				$img.removeAttr( 'data-src' ).removeAttr( 'sizes' );
				$img.attr( 'data-large_image', full );
				// Dimensions lues par PhotoSwipe : sans ça la lightbox garde le ratio
				// de l'image d'origine et déforme la nouvelle photo.
				if ( w ) { $img.attr( 'data-large_image_width', w ); }
				if ( h ) { $img.attr( 'data-large_image_height', h ); }
				// Lien (lightbox/PhotoSwipe) + miniature de la cellule.
				$cell.attr( 'data-thumb', full );
				$cell.find( 'a' ).first().attr( 'href', full );
				// La galerie est un carrousel (flexslider) : on vient de remplacer la 1re
				// diapo, mais la diapo affichée peut être une autre → l'utilisateur resterait
				// sur l'image précédente. On force WooCommerce à revenir sur la 1re diapo.
				$gallery.trigger( 'woocommerce_gallery_reset_slide_position' );
				// Ré-initialise le zoom au survol : WooCommerce mémorise la grande image au
				// premier rendu, donc sans ça le zoom afficherait toujours la photo d'origine.
				if ( $.fn.zoom ) {
					$cell.trigger( 'zoom.destroy' );
					// jquery.zoom charge sa grande image de façon asynchrone et superpose
					// un calque `.zoomImg` (opacity 0) sur la photo. Entre deux changements,
					// l'ancien calque peut survivre au `zoom.destroy` et rester superposé :
					// au survol/zoom il réapparaît → on voit la couleur précédente. On le
					// retire explicitement avant de recréer le zoom sur la nouvelle photo.
					$cell.find( '.zoomImg' ).remove();
					$cell.zoom( { url: full, touch: false } );
				}
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
	} );
} )( jQuery );
