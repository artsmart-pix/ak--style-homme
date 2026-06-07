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
			// Produit variable : une ligne quantité par couleur (.aod-cod__color).
			var $colorRows = $form.find( '.aod-cod__color' );
			var isVariable = $colorRows.length > 0;

			var $hint = $box.find( '.aod-cod__free-hint' );

			// Sous-total produits : somme (prix × quantité) par couleur, sinon prix × quantité globale.
			function productsSubtotal() {
				if ( isVariable ) {
					var sum = 0;
					$colorRows.each( function () {
						var $row = $( this );
						var q    = parseInt( $row.find( '.aod-cod__color-qtyinput' ).val(), 10 ) || 0;
						if ( q > 0 ) {
							sum += ( parseFloat( $row.data( 'price' ) ) || 0 ) * q;
						}
					} );
					return sum;
				}
				var qty = Math.max( 1, parseInt( $qty.val(), 10 ) || 1 );
				return tierUnit( qty ) * qty;
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

			// Quantité totale (toutes couleurs) — utile pour le lead.
			function totalQty() {
				if ( ! isVariable ) {
					return Math.max( 1, parseInt( $qty.val(), 10 ) || 1 );
				}
				var t = 0;
				$colorRows.each( function () {
					t += parseInt( $( this ).find( '.aod-cod__color-qtyinput' ).val(), 10 ) || 0;
				} );
				return t;
			}

			// Remplace la grande photo de la galerie WooCommerce par celle de la couleur choisie.
			function swapGallery( $radio ) {
				var full = $radio.attr( 'data-img-full' ) || $radio.attr( 'data-img' );
				if ( ! full ) { return; }
				var srcset  = $radio.attr( 'data-srcset' ) || '';
				var $gallery = $( '.woocommerce-product-gallery' );
				if ( ! $gallery.length ) { return; }
				// Image principale = première de la galerie (gère flexslider/photoswipe).
				var $cell = $gallery.find( '.woocommerce-product-gallery__image' ).first();
				if ( ! $cell.length ) { $cell = $gallery; }
				var $img = $cell.find( 'img' ).first();
				if ( ! $img.length ) { return; }
				$img.attr( 'src', full );
				if ( srcset ) { $img.attr( 'srcset', srcset ); } else { $img.removeAttr( 'srcset' ); }
				$img.removeAttr( 'data-src' ).removeAttr( 'sizes' );
				$img.attr( 'data-large_image', full );
				// Lien (zoom/lightbox) + miniature de la cellule.
				$cell.attr( 'data-thumb', full );
				$cell.find( 'a' ).first().attr( 'href', full );
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

			$form.on( 'change input', 'select[name="commune"], input[name="qty"], input[name="delivery"], .aod-cod__color-qtyinput', render );

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

			if ( isVariable ) {
				// Cliquer une couleur (vignette/nom) montre sa grande photo.
				$form.on( 'click', '.aod-cod__color-pick', function () {
					swapGallery( $( this ).closest( '.aod-cod__color' ) );
				} );
				// Éditer la quantité d'une couleur montre aussi sa photo.
				$form.on( 'focus', '.aod-cod__color-qtyinput', function () {
					swapGallery( $( this ).closest( '.aod-cod__color' ) );
				} );
				// Boutons +/- de quantité.
				$form.on( 'click', '.aod-cod__qstep', function () {
					var $row   = $( this ).closest( '.aod-cod__color' );
					var $input = $row.find( '.aod-cod__color-qtyinput' );
					var step   = parseInt( $( this ).data( 'step' ), 10 ) || 0;
					var val    = Math.max( 0, ( parseInt( $input.val(), 10 ) || 0 ) + step );
					$input.val( val ).trigger( 'change' );
					$row.toggleClass( 'is-selected', val > 0 );
					swapGallery( $row );
				} );
				// Garde-fou : pas de quantité négative + marque la ligne active.
				$form.on( 'input change', '.aod-cod__color-qtyinput', function () {
					var v = parseInt( $( this ).val(), 10 ) || 0;
					if ( v < 0 ) { v = 0; $( this ).val( 0 ); }
					$( this ).closest( '.aod-cod__color' ).toggleClass( 'is-selected', v > 0 );
				} );
				// Photo initiale = première couleur.
				swapGallery( $colorRows.first() );
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
			$form.on( 'input change', 'input[name="name"], input[name="phone"], input[name="qty"], .aod-cod__color-qtyinput, select[name="wilaya"], select[name="commune"], input[name="delivery"]', function () {
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

				// Quantités par couleur (produit variable).
				var colorQty = {};
				if ( isVariable ) {
					$colorRows.each( function () {
						var $row = $( this );
						var q    = parseInt( $row.find( '.aod-cod__color-qtyinput' ).val(), 10 ) || 0;
						if ( q > 0 ) { colorQty[ $row.data( 'id' ) ] = q; }
					} );
				}

				if ( ! name || ! wilaya || ! commune ) {
					return showError( AOD_COD.i18n.required );
				}
				if ( isVariable && ! Object.keys( colorQty ).length ) {
					return showError( AOD_COD.i18n.choose_color || AOD_COD.i18n.required );
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
					lead_token: leadToken
				};
				if ( isVariable ) {
					// Envoie color_qty[ID]=quantité pour chaque couleur choisie.
					$.each( colorQty, function ( id, q ) {
						postData[ 'color_qty[' + id + ']' ] = q;
					} );
				} else {
					postData.qty = $qty.val();
				}

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
