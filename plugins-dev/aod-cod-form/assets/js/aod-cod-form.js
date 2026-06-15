/* AOD COD Form — front logic */
( function ( $ ) {
	'use strict';

	function fmt( n ) {
		n = Math.round( ( parseFloat( n ) || 0 ) * 100 ) / 100;
		return n.toLocaleString( 'fr-DZ' ) + ' ' + AOD_COD.currency;
	}

	// Normalise un nom de commune comme le serveur (accents, casse, y≈i, ponctuation),
	// pour comparer la commune choisie aux communes qui ont réellement un bureau.
	function normCommune( s ) {
		return ( s == null ? '' : String( s ) )
			.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' )
			.toLowerCase()
			.replace( /y/g, 'i' )
			.replace( /[^a-z0-9]+/g, '' );
	}

	$( function () {
		$( '.aod-cod' ).each( function () {
			var $box      = $( this );
			var $form     = $box.find( '.aod-cod__form' );
			var price     = parseFloat( $box.data( 'price' ) ) || 0;
			var productId = $box.data( 'product' );

			// Modèle « cartes d'offres » : le client choisit une offre (1 produit, 2 produits…)
			// puis, si le produit a des variantes, une variante pour chaque unité de l'offre.
			var $offers = $box.find( '.aod-cod__offers' );
			var $cards  = $offers.find( '.aod-cod__offer-card' );
			// Les panneaux de variantes sont imbriqués DANS chaque carte (accordéon).
			var $panels = $offers.find( '.aod-cod__offer-panel' );
			var hasOptions = $panels.length > 0;

			var $wilaya  = $form.find( 'select[name="wilaya"]' );
			var $commune = $form.find( 'select[name="commune"]' );
			var $msg     = $box.find( '.aod-cod__msg' );
			var $cmsg    = $box.find( '.aod-cod__config-msg' );
			var $hint    = $box.find( '.aod-cod__free-hint' );

			// Filtrage du mode « bureau » (stop-desk) selon la commune.
			var $deskRadio = $form.find( 'input[name="delivery"][value="desk"]' );
			var $homeRadio = $form.find( 'input[name="delivery"][value="home"]' );
			var $deskLabel = $deskRadio.closest( '.aod-cod__radio' );
			var deskCache  = {}; // wilaya -> { gated:bool, set:[clés normalisées] }

			// Carte d'offre actuellement sélectionnée (index 0 = « 1 produit » par défaut).
			function $selectedCard() {
				var $c = $cards.filter( '.is-selected' ).first();
				return $c.length ? $c : $cards.first();
			}
			function selectedQty() {
				return Math.max( 1, parseInt( $selectedCard().attr( 'data-qty' ), 10 ) || 1 );
			}
			function selectedLotPrice() {
				return parseFloat( $selectedCard().attr( 'data-price' ) ) || price;
			}
			function $selectedPanel() {
				return $selectedCard().find( '.aod-cod__offer-panel' );
			}

			function totalQty() {
				return selectedQty();
			}

			// Somme des suppléments des variantes choisies dans le panneau actif.
			function panelSupplements() {
				if ( ! hasOptions ) { return 0; }
				var supp = 0;
				$selectedPanel().find( '.aod-cod__optsec input[type="radio"]:checked' ).each( function () {
					supp += parseFloat( $( this ).attr( 'data-price' ) ) || 0;
				} );
				$selectedPanel().find( '.aod-cod__optsec select.aod-cod__optselect' ).each( function () {
					supp += parseFloat( $( this ).find( 'option:selected' ).attr( 'data-price' ) ) || 0;
				} );
				return supp;
			}

			// Sous-total produits : prix de lot de l'offre + suppléments des variantes.
			function productsSubtotal() {
				return selectedLotPrice() + panelSupplements();
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

			// Affiche/masque l'option « bureau » selon que la commune choisie a un
			// point relais. Tant qu'on ne connaît pas la wilaya (cache absent) ou que
			// le livreur ne filtre pas, l'option reste visible.
			function applyDeskGate() {
				var code  = parseInt( $wilaya.val(), 10 );
				var entry = deskCache[ code ];
				var allowed = true;
				if ( entry && false === entry.supported ) {
					// Le livreur actif ne gère pas le stop-desk : pas d'option « bureau ».
					allowed = false;
				} else if ( entry && entry.gated ) {
					var val = $commune.val();
					if ( val ) {
						allowed = entry.set.indexOf( normCommune( val ) ) !== -1;
					}
				}
				if ( allowed ) {
					$deskLabel.show();
				} else {
					$deskLabel.hide();
					if ( $deskRadio.is( ':checked' ) ) {
						$homeRadio.prop( 'checked', true );
					}
				}
				render();
			}

			// Récupère (et met en cache) les communes éligibles au stop-desk d'une
			// wilaya auprès du livreur d'envoi automatique, puis applique le filtre.
			function loadDeskSet( code ) {
				if ( ! code ) { applyDeskGate(); return; }
				if ( deskCache[ code ] ) { applyDeskGate(); return; }
				$.post( AOD_COD.ajax_url, {
					action: 'aod_cod_desk_communes',
					nonce: AOD_COD.nonce,
					wilaya: code
				} ).done( function ( res ) {
					var d = ( res && res.data ) || {};
					// desk:false = livreur sans stop-desk → option « bureau » masquée.
					deskCache[ code ] = { supported: ( false !== d.desk ), gated: !! d.gated, set: d.communes || [] };
					applyDeskGate();
				} ).fail( function () {
					deskCache[ code ] = { supported: true, gated: false, set: [] };
					applyDeskGate();
				} );
			}

			// Recalcule cartes de livraison + récapitulatif + incitation au seuil.
			function render() {
				var subtotal = productsSubtotal();
				var th       = freeThreshold();
				var free     = wilayaIsFree() || ( th > 0 && subtotal >= th );

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

			// Sélection d'une carte d'offre : on affiche son panneau de variantes, on masque
			// les autres, et on recalcule. La photo de galerie suit la 1re variante visuelle.
			function selectCard( $card ) {
				if ( ! $card.length ) { return; }
				$cards.removeClass( 'is-selected' );
				$card.addClass( 'is-selected' );
				$card.children( '.aod-cod__offer-head' ).find( 'input[type="radio"]' ).prop( 'checked', true );
				if ( hasOptions ) {
					$panels.attr( 'hidden', 'hidden' );
					$selectedPanel().removeAttr( 'hidden' );
				}
				if ( $cmsg ) { $cmsg.text( '' ); }
				render();
			}

			$offers.on( 'change', '.aod-cod__offer-card input[type="radio"]', function () {
				selectCard( $( this ).closest( '.aod-cod__offer-card' ) );
			} );

			// Choisir une valeur d'option : marque la carte/option et change la grande photo.
			if ( hasOptions ) {
				$offers.on( 'change', '.aod-cod__optsec input[type="radio"]', function () {
					var $sec = $( this ).closest( '.aod-cod__optsec' );
					$sec.find( '.aod-cod__opt' ).removeClass( 'is-selected' );
					$( this ).closest( '.aod-cod__opt' ).addClass( 'is-selected' );
					if ( $sec.hasClass( 'is-visual' ) ) {
						swapGallery( $( this ) );
					}
					render();
				} );

				// Variante choisie via une liste déroulante : photo de galerie + recalcul.
				$offers.on( 'change', '.aod-cod__optsec select.aod-cod__optselect', function () {
					swapGallery( $( this ).find( 'option:selected' ) );
					render();
				} );
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
				applyDeskGate(); // rend immédiatement (option « bureau » visible le temps du chargement)
				loadDeskSet( code );
			} );

			$form.on( 'change', 'select[name="commune"]', applyDeskGate );
			$form.on( 'change input', 'input[name="delivery"]', render );

			// État initial : carte par défaut + panneau visible cohérents.
			selectCard( $selectedCard() );

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
			$form.on( 'input change', 'input[name="name"], input[name="phone"], select[name="wilaya"], select[name="commune"], input[name="delivery"]', function () {
				if ( leadStarted ) {
					scheduleLead();
				} else {
					var phone = ( $form.find( 'input[name="phone"]' ).val() || '' ).replace( /\D+/g, '' );
					if ( phone.length >= 9 ) { scheduleLead(); }
				}
			} );

			// Lit la sélection de variantes du panneau actif : une entrée par unité.
			// Renvoie { items: [...], missing: bool }.
			function collectItems() {
				if ( ! hasOptions ) {
					return { items: [ { qty: totalQty(), opt: {} } ], missing: false };
				}
				var items   = [];
				var missing = false;
				$selectedPanel().find( '.aod-cod__unit' ).each( function () {
					var opt = {};
					$( this ).find( '.aod-cod__optsec' ).each( function () {
						var si       = $( this ).data( 'si' );
						var $select  = $( this ).find( 'select.aod-cod__optselect' );
						var val;
						if ( $select.length ) {
							val = $select.val();
						} else {
							var $sel = $( this ).find( 'input[type="radio"]:checked' );
							val = $sel.length ? $sel.val() : '';
						}
						if ( val ) {
							opt[ si ] = val;
						} else {
							missing = true;
						}
					} );
					items.push( { qty: 1, opt: opt } );
				} );
				return { items: items, missing: missing };
			}

			// Soumission
			$form.on( 'submit', function ( e ) {
				e.preventDefault();
				$msg.removeClass( 'is-error is-success' ).text( '' );
				if ( $cmsg ) { $cmsg.text( '' ); }

				var name    = $form.find( 'input[name="name"]' ).val().trim();
				var phone   = $form.find( 'input[name="phone"]' ).val().replace( /\D+/g, '' );
				var wilaya  = $wilaya.val();
				var commune = $commune.val();

				var collected = collectItems();
				if ( collected.missing ) {
					if ( $cmsg ) { $cmsg.text( AOD_COD.i18n.choose_option ); }
					return showError( AOD_COD.i18n.choose_option );
				}
				var itemsData = collected.items;

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

			render();
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
