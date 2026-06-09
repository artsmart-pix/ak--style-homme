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
			var $msg      = $box.find( '.aod-cod__msg' );
			var $hint     = $box.find( '.aod-cod__free-hint' );

			// Modèle « configurateur + panier » : on choisit des variantes dans le
			// configurateur, puis « Ajouter » crée une ligne dans le tableau du panier.
			var hasOptions = $box.find( '.aod-cod__config' ).length > 0;
			// Articles ajoutés : { qty, opt:{si:valeur}, names:[…], supp, imgId }.
			var cart = [];

			// Quantité saisie dans le configurateur (avant ajout).
			function cfgQty() {
				return Math.max( 1, parseInt( $box.find( '.aod-cod__cfg-qty' ).val(), 10 ) || 1 );
			}

			// Quantité totale : somme du panier (avec options) ou champ unique (sans options).
			function totalQty() {
				if ( ! hasOptions ) {
					return Math.max( 1, parseInt( $form.find( 'input[name="items[0][qty]"]' ).val(), 10 ) || 1 );
				}
				var t = 0;
				for ( var i = 0; i < cart.length; i++ ) { t += cart[ i ].qty; }
				return t;
			}

			// Sous-total : palier sur la quantité TOTALE + suppléments par article.
			function productsSubtotal() {
				if ( ! hasOptions ) {
					var q = totalQty();
					return tierUnit( q ) * q;
				}
				var unit = tierUnit( totalQty() );
				var sub  = 0;
				for ( var i = 0; i < cart.length; i++ ) {
					sub += ( unit + cart[ i ].supp ) * cart[ i ].qty;
				}
				return sub;
			}

			// Met en évidence le palier actif selon la quantité totale.
			function updateTierHint() {
				if ( ! tiers.length ) { return; }
				var qty = totalQty();
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

			// Place la galerie WooCommerce sur la photo de la variante choisie.
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
				if ( index < 0 ) { return; }
				goToSlide( $gallery, index );
			}

			// Seuil de livraison gratuite (0 = désactivé).
			function freeThreshold() {
				return ( AOD_COD.free_shipping && parseFloat( AOD_COD.free_shipping.threshold ) ) || 0;
			}

			// Tarif de base d'un mode de livraison pour la wilaya sélectionnée.
			function baseShip( type ) {
				var code = parseInt( $wilaya.val(), 10 );
				var w    = AOD_COD.wilayas[ code ];
				if ( ! w ) { return 0; }
				return ( type === 'desk' ) ? ( parseFloat( w.desk ) || 0 ) : ( parseFloat( w.home ) || 0 );
			}

			// La wilaya sélectionnée est-elle en livraison gratuite permanente ?
			function wilayaIsFree() {
				var code = parseInt( $wilaya.val(), 10 );
				var w    = AOD_COD.wilayas[ code ];
				return !! ( w && w.free );
			}

			// (Re)construit le tableau du panier à partir de `cart`.
			function renderCart() {
				var $table = $box.find( '.aod-cod__cart' );
				var $body  = $box.find( '.aod-cod__cart-body' );
				if ( ! $table.length ) { return; }
				$body.empty();
				if ( ! cart.length ) { $table.attr( 'hidden', 'hidden' ); return; }
				$table.removeAttr( 'hidden' );
				var tpl  = $box.find( '.aod-cod__row-tpl' ).html();
				var unit = tierUnit( totalQty() );
				for ( var i = 0; i < cart.length; i++ ) {
					var it   = cart[ i ];
					var $row = $( tpl );
					$row.attr( 'data-idx', i );
					$row.find( '.aod-cod__cart-variant' ).text( it.names.join( ' · ' ) );
					$row.find( '.aod-cod__cart-qty' ).text( '×' + it.qty );
					$row.find( '.aod-cod__cart-price' ).text( fmt( ( unit + it.supp ) * it.qty ) );
					$body.append( $row );
				}
			}

			// Recalcule cartes de livraison + récapitulatif + incitation au seuil.
			function render() {
				var subtotal = productsSubtotal();
				var th       = freeThreshold();
				var free     = wilayaIsFree() || ( th > 0 && subtotal >= th );

				updateTierHint();

				$box.find( '.aod-cod__radio-price' ).each( function () {
					var $p = $( this );
					var v  = baseShip( $p.data( 'type' ) );
					if ( v > 0 && free ) {
						$p.html( '<s>' + fmt( v ) + '</s> ' + AOD_COD.i18n.free );
					} else {
						$p.text( v > 0 ? fmt( v ) : '' );
					}
				} );

				var type = $form.find( 'input[name="delivery"]:checked' ).val();
				var base = baseShip( type );
				var ship = free ? 0 : base;
				$box.find( '.aod-cod__subtotal' ).text( fmt( subtotal ) );
				$box.find( '.aod-cod__shipping' ).text( free && base > 0 ? AOD_COD.i18n.free : ( ship > 0 ? fmt( ship ) : '—' ) );
				$box.find( '.aod-cod__total' ).text( fmt( subtotal + ship ) );

				if ( th > 0 && free ) {
					$hint.text( AOD_COD.i18n.free_active ).removeAttr( 'hidden' );
				} else if ( th > 0 && subtotal > 0 ) {
					$hint.text( AOD_COD.i18n.free_hint.replace( '%s', fmt( th - subtotal ) ) ).removeAttr( 'hidden' );
				} else {
					$hint.attr( 'hidden', 'hidden' ).text( '' );
				}
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

			$form.on( 'change input', 'select[name="commune"], input[name="delivery"], input[type="number"], .aod-cod__optsec input[type="radio"]', render );

			// Stepper quantité : boutons − / + agissant sur l'input de leur propre groupe.
			$form.on( 'click', '.aod-cod__step', function () {
				var step = parseInt( $( this ).attr( 'data-step' ), 10 ) || 0;
				var $inp = $( this ).closest( '.aod-cod__stepper' ).find( 'input[type="number"]' );
				var min  = parseInt( $inp.attr( 'min' ), 10 );
				if ( isNaN( min ) ) { min = 1; }
				var cur  = parseInt( $inp.val(), 10 );
				if ( isNaN( cur ) ) { cur = min; }
				$inp.val( Math.max( min, cur + step ) ).trigger( 'change' );
			} );

			// Réinitialise le configurateur après ajout.
			function resetConfig() {
				var $cfg = $box.find( '.aod-cod__config' );
				$cfg.find( 'input[type="radio"]' ).prop( 'checked', false );
				$cfg.find( '.aod-cod__opt' ).removeClass( 'is-selected' );
				$cfg.find( '.aod-cod__cfg-qty' ).val( 1 );
			}

			// « Ajouter cet article » : lit la sélection, l'ajoute au panier, réinitialise.
			$form.on( 'click', '.aod-cod__additem', function () {
				var $cfg    = $box.find( '.aod-cod__config' );
				var $cmsg   = $cfg.find( '.aod-cod__config-msg' );
				var opt     = {};
				var names   = [];
				var supp    = 0;
				var imgId   = 0;
				var missing = false;
				$cfg.find( '.aod-cod__optsec' ).each( function () {
					var si   = $( this ).data( 'si' );
					var $sel = $( this ).find( 'input[type="radio"]:checked' );
					if ( $sel.length ) {
						opt[ si ] = $sel.val();
						names.push( $sel.attr( 'data-name' ) || $sel.val() );
						supp += parseFloat( $sel.attr( 'data-price' ) ) || 0;
						var iid = parseInt( $sel.attr( 'data-img-id' ), 10 ) || 0;
						if ( iid ) { imgId = iid; }
					} else {
						missing = true;
					}
				} );
				if ( missing ) {
					$cmsg.text( AOD_COD.i18n.choose_option || AOD_COD.i18n.required );
					return;
				}
				$cmsg.text( '' );
				cart.push( { qty: cfgQty(), opt: opt, names: names, supp: supp, imgId: imgId } );
				resetConfig();
				renderCart();
				render();
			} );

			// Retirer un article du panier.
			$form.on( 'click', '.aod-cod__rowremove', function () {
				var idx = parseInt( $( this ).closest( '.aod-cod__cart-row' ).attr( 'data-idx' ), 10 );
				if ( idx >= 0 ) {
					cart.splice( idx, 1 );
					renderCart();
					render();
				}
			} );

			renderCart();
			render();

			if ( hasOptions ) {
				// Choisir une valeur « avec photo » change la grande image + marque la carte.
				$form.on( 'change', '.aod-cod__optsec input[type="radio"]', function () {
					var $sec = $( this ).closest( '.aod-cod__optsec' );
					$sec.find( '.aod-cod__opt' ).removeClass( 'is-selected' );
					$( this ).closest( '.aod-cod__opt' ).addClass( 'is-selected' );
					if ( $sec.hasClass( 'is-visual' ) ) {
						swapGallery( $( this ) );
					}
				} );
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
					qty: totalQty() || 1
				};
			}

			function pushLead() {
				var phone = ( $form.find( 'input[name="phone"]' ).val() || '' ).replace( /\D+/g, '' );
				if ( phone.length < 8 ) { return; }
				leadStarted = true;
				$.post( AOD_COD.ajax_url, collectLead() );
			}

			function scheduleLead() {
				if ( leadTimer ) { clearTimeout( leadTimer ); }
				leadTimer = setTimeout( pushLead, 900 );
			}

			$form.find( 'input[name="phone"]' ).on( 'blur', pushLead );
			$form.on( 'input change', 'input[name="name"], input[name="phone"], input[type="number"], .aod-cod__optsec input[type="radio"], select[name="wilaya"], select[name="commune"], input[name="delivery"]', function () {
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

				var itemsData = [];
				if ( hasOptions ) {
					if ( ! cart.length ) {
						return showError( AOD_COD.i18n.cart_empty || AOD_COD.i18n.choose_option || AOD_COD.i18n.required );
					}
					for ( var i = 0; i < cart.length; i++ ) {
						itemsData.push( { qty: cart[ i ].qty, opt: cart[ i ].opt } );
					}
				} else {
					itemsData.push( { qty: totalQty(), opt: {} } );
				}

				if ( ! name || ! wilaya || ! commune ) {
					return showError( AOD_COD.i18n.required );
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
				$.each( itemsData, function ( li, it ) {
					postData[ 'items[' + li + '][qty]' ] = it.qty;
					$.each( it.opt, function ( si, v ) {
						postData[ 'items[' + li + '][opt][' + si + ']' ] = v;
					} );
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
