/* AOD Client Dashboard — interactions front-end */
( function () {
	'use strict';

	var CD = window.AOD_CD || {};
	var netErr = CD.i18nNetErr || 'Erreur réseau.';

	function toast( message, isBad ) {
		var t = document.createElement( 'div' );
		t.className = 'aod-cd-toast' + ( isBad ? ' bad' : '' );
		t.textContent = message;
		document.body.appendChild( t );
		requestAnimationFrame( function () { t.classList.add( 'show' ); } );
		setTimeout( function () {
			t.classList.remove( 'show' );
			setTimeout( function () { t.remove(); }, 300 );
		}, 2600 );
	}

	/* Changement de statut de commande (AJAX) */
	function bindStatus() {
		document.querySelectorAll( '.aod-cd-statussel' ).forEach( function ( widget ) {
			var btn  = widget.querySelector( '.aod-cd-statussel-btn' );
			var menu = widget.querySelector( '.aod-cd-statussel-menu' );
			if ( ! btn || ! menu ) { return; }
			var txt = btn.querySelector( '.aod-cd-statussel-text' );

			// Menu en position:fixed : on le place sous le bouton (ou au-dessus
			// s'il dépasse en bas), ce qui évite tout rognage par l'overflow du
			// tableau et le replace toujours au bon endroit.
			function place() {
				var r  = btn.getBoundingClientRect();
				var mh = menu.offsetHeight;
				var mw = menu.offsetWidth;
				var vw = window.innerWidth;
				var vh = window.innerHeight;
				var left = Math.max( 8, Math.min( r.left, vw - mw - 8 ) );
				var top  = r.bottom + 6;
				if ( top + mh > vh - 8 && r.top - 6 - mh > 8 ) { top = r.top - 6 - mh; }
				menu.style.left     = left + 'px';
				menu.style.top      = top + 'px';
				menu.style.minWidth = r.width + 'px';
			}

			function setOpen( on ) {
				if ( on ) {
					menu.hidden = false;
					place();
					widget.classList.add( 'is-open' );
				} else {
					menu.hidden = true;
					widget.classList.remove( 'is-open' );
				}
				btn.setAttribute( 'aria-expanded', on ? 'true' : 'false' );
			}

			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				setOpen( menu.hidden );
			} );

			menu.addEventListener( 'click', function ( e ) {
				var opt = e.target.closest( '.aod-cd-statussel-opt' );
				if ( ! opt ) { return; }
				e.preventDefault();
				setOpen( false );
				if ( opt.dataset.value !== widget.dataset.value ) {
					applyStatus( widget, btn, txt, opt );
				}
			} );

			document.addEventListener( 'click', function ( e ) {
				if ( ! menu.hidden && ! e.target.closest( '.aod-cd-statussel' ) ) { setOpen( false ); }
			} );
			document.addEventListener( 'keydown', function ( e ) {
				if ( 'Escape' === e.key && ! menu.hidden ) { setOpen( false ); btn.focus(); }
			} );
			// Le menu est en position:fixed : on le referme si la page bouge.
			window.addEventListener( 'resize', function () { if ( ! menu.hidden ) { setOpen( false ); } } );
			window.addEventListener( 'scroll', function () { if ( ! menu.hidden ) { setOpen( false ); } }, true );
		} );
	}

	/* Applique un changement de statut (AJAX) et met à jour l'affichage. */
	function applyStatus( widget, btn, txt, opt ) {
		var status   = opt.dataset.value;
		var newLabel = opt.querySelector( '.aod-cd-statussel-text' ).textContent;
		var newSlug  = opt.dataset.status;

		widget.classList.add( 'is-saving' );

		var body = new URLSearchParams();
		body.append( 'action', 'aod_cd_order_status' );
		body.append( 'nonce', CD.nonce );
		body.append( 'order_id', widget.dataset.order );
		body.append( 'status', status );

		fetch( CD.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				widget.classList.remove( 'is-saving' );
				if ( res && res.success ) {
					widget.dataset.value = status;
					txt.textContent      = newLabel;
					btn.dataset.status   = newSlug;
					widget.querySelectorAll( '.aod-cd-statussel-opt' ).forEach( function ( o ) {
						var on = ( o.dataset.value === status );
						o.classList.toggle( 'is-active', on );
						o.setAttribute( 'aria-selected', on ? 'true' : 'false' );
					} );
					widget.classList.add( 'is-ok' );
					setTimeout( function () { widget.classList.remove( 'is-ok' ); }, 1500 );
					// Met à jour l'icône camion de la colonne « Suivi » en direct
					// (gris → vert dès que l'envoi auto a réussi), sans recharger.
					if ( typeof res.data.ship_cell === 'string' ) {
						var row  = widget.closest( 'tr' );
						var cell = row && row.querySelector( '.aod-cd-cell-ship' );
						if ( cell ) {
							// Conserve l'attribut data-label (vue cartes mobile).
							var label = cell.getAttribute( 'data-label' );
							cell.innerHTML = res.data.ship_cell;
							if ( label !== null ) { cell.setAttribute( 'data-label', label ); }
							cell.classList.add( 'is-ship-updated' );
							setTimeout( function () { cell.classList.remove( 'is-ship-updated' ); }, 1500 );
						}
					}
					// Si le passage à « Confirmée » a tenté un envoi au livreur, on
					// affiche son résultat (vert = colis créé, rouge = échec / non envoyé)
					// pour que le gérant sache toujours si le colis est bien parti.
					if ( res.data.ship_message ) {
						var failed = res.data.ship_status && res.data.ship_status !== 'sent';
						toast( res.data.ship_message, !!failed );
					} else {
						var msg = res.data.message || 'OK';
						if ( res.data.tracking ) {
							msg += ' — ' + ( CD.i18nShipped || 'Colis créé' ) + ' : ' + res.data.tracking;
						}
						toast( msg, false );
					}
				} else {
					toast( ( res && res.data && res.data.message ) || 'Erreur.', true );
				}
			} )
			.catch( function () {
				widget.classList.remove( 'is-saving' );
				toast( netErr, true );
			} );
	}

	/* Produits : aperçu image, toggle stock, enregistrement, suppression */
	function bindProducts() {
		// Aperçu de la photo choisie.
		var file = document.querySelector( '.aod-cd-imgfile' );
		if ( file ) {
			file.addEventListener( 'change', function () {
				var prev  = document.querySelector( '.aod-cd-imgprev' );
				var empty = document.querySelector( '.aod-cd-imgempty' );
				if ( file.files && file.files[0] ) {
					var url = URL.createObjectURL( file.files[0] );
					if ( prev )  { prev.src = url; prev.style.display = ''; }
					if ( empty ) { empty.style.display = 'none'; }
				}
			} );
		}

		// Affiche/masque le champ quantité selon « Gérer le stock ».
		var toggle = document.querySelector( '.aod-cd-stock-toggle' );
		if ( toggle ) {
			toggle.addEventListener( 'change', function () {
				var qty = document.querySelector( '.aod-cd-stock-qty' );
				if ( qty ) { qty.style.display = toggle.checked ? '' : 'none'; }
			} );
		}

		// Enregistrement du produit (formulaire multipart via FormData).
		// IMPORTANT : on attache le handler de soumission AVANT les bindings
		// optionnels (options / paliers / packs). Si l'un d'eux levait une
		// exception, le « submit » resterait quand même branché : sans lui, le
		// navigateur ferait une soumission native du <form> et toutes les
		// saisies (description, stock, prix…) seraient perdues au rechargement.
		var form = document.getElementById( 'aod-cd-product-form' );
		if ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var btn = form.querySelector( 'button[type="submit"]' );
				var msg = form.querySelector( '.aod-cd-form-msg' );
				btn.disabled = true;
				if ( msg ) { msg.textContent = ''; }

				var data = new FormData( form );
				data.append( 'action', 'aod_cd_save_product' );
				data.append( 'nonce', CD.nonce );

				fetch( CD.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( res && res.success ) {
							// Un avertissement (ex. palier ignoré) reste affiché plus longtemps avant la redirection.
							if ( res.data && res.data.warning ) {
								toast( res.data.warning, true );
								setTimeout( function () { window.location.href = res.data.redirect || CD.base; }, 4000 );
							} else {
								toast( res.data.message || 'OK', false );
								setTimeout( function () { window.location.href = res.data.redirect || CD.base; }, 700 );
							}
						} else {
							btn.disabled = false;
							var m = ( res && res.data && res.data.message ) || 'Erreur.';
							if ( msg ) { msg.textContent = m; msg.classList.add( 'is-bad' ); }
							toast( m, true );
						}
					} )
					.catch( function () {
						btn.disabled = false;
						toast( netErr, true );
					} );
			} );
		}

		// Bindings optionnels : isolés en try/catch pour qu'une erreur dans l'un
		// (données inattendues, élément absent…) n'empêche jamais les autres ni la
		// soumission ci-dessus de fonctionner.
		try { bindOptions(); }    catch ( err ) { /* options */ }
		try { bindOfferRows(); }  catch ( err ) { /* offres */ }

		// Suppression (corbeille) avec confirmation.
		document.querySelectorAll( '.aod-cd-del-product' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				var name = b.dataset.name || '';
				var delMsg = ( CD.i18nProductDelConfirm || 'Supprimer « %s » ? (déplacé dans la corbeille)' ).replace( '%s', name );
				if ( ! window.confirm( delMsg ) ) { return; }
				b.disabled = true;
				var body = new URLSearchParams();
				body.append( 'action', 'aod_cd_delete_product' );
				body.append( 'nonce', CD.nonce );
				body.append( 'product_id', b.dataset.product );
				fetch( CD.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( res && res.success ) {
							var row = b.closest( 'tr' );
							if ( row ) { row.style.opacity = '0.4'; row.remove(); }
							toast( res.data.message || CD.i18nDeleted || 'Supprimé', false );
						} else {
							b.disabled = false;
							toast( ( res && res.data && res.data.message ) || 'Erreur.', true );
						}
					} )
					.catch( function () { b.disabled = false; toast( netErr, true ); } );
			} );
		} );
	}

	/* Sections d'options (Taille, Couleur, Pointure…) : sections + valeurs ajoutables */
	function bindOptions() {
		var wrap = document.getElementById( 'aod-cd-options' );
		if ( ! wrap ) { return; }
		var sectionsBox = wrap.querySelector( '.aod-cd-opt-sections' );
		var addSecBtn   = wrap.querySelector( '.aod-cd-opt-add' );
		var secTpl      = document.getElementById( 'aod-cd-opt-section-tpl' );
		if ( ! sectionsBox || ! addSecBtn || ! secTpl ) { return; }

		function makeSection() {
			var si   = parseInt( addSecBtn.dataset.next, 10 ) || 0;
			addSecBtn.dataset.next = String( si + 1 );
			var html = secTpl.innerHTML.replace( /\{SI\}/g, String( si ) );
			var tmp  = document.createElement( 'div' );
			tmp.innerHTML = html.trim();
			var node = tmp.firstElementChild;
			sectionsBox.appendChild( node );
			return node;
		}

		function addValue( section, value ) {
			var valuesBox = section.querySelector( '.aod-cd-opt-values' );
			var addBtn    = section.querySelector( '.aod-cd-opt-val-add' );
			var tpl       = section.querySelector( '.aod-cd-opt-val-tpl' );
			if ( ! valuesBox || ! addBtn || ! tpl ) { return null; }
			var vi   = parseInt( addBtn.dataset.next, 10 ) || 0;
			addBtn.dataset.next = String( vi + 1 );
			var html = tpl.innerHTML.replace( /\{VI\}/g, String( vi ) );
			var tmp  = document.createElement( 'div' );
			tmp.innerHTML = html.trim();
			var node = tmp.firstElementChild;
			valuesBox.appendChild( node );
			if ( value != null ) {
				var nameInput = node.querySelector( '.aod-cd-opt-name' );
				if ( nameInput ) { nameInput.value = value; }
			}
			return node;
		}

		function setVisual( section, on ) {
			var cb        = section.querySelector( '.aod-cd-opt-visual-cb' );
			var valuesBox = section.querySelector( '.aod-cd-opt-values' );
			if ( cb ) { cb.checked = !! on; }
			if ( valuesBox ) { valuesBox.classList.toggle( 'is-visual', !! on ); }
		}

		// + Ajouter une section (vide, avec une valeur vierge).
		addSecBtn.addEventListener( 'click', function () {
			var section = makeSection();
			var label   = section.querySelector( '.aod-cd-opt-label' );
			if ( label ) { label.focus(); }
		} );

		// Sections rapides (presets) : crée une section pré-remplie.
		wrap.querySelectorAll( '.aod-cd-opt-preset' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var section = makeSection();
				var label   = section.querySelector( '.aod-cd-opt-label' );
				if ( label ) { label.value = btn.dataset.label || ''; }
				var visual = btn.dataset.visual === '1';
				setVisual( section, visual );
				var values = ( btn.dataset.values || '' ).split( ',' ).map( function ( s ) {
					return s.trim();
				} ).filter( Boolean );
				// La section neuve possède déjà une valeur vierge : on la garnit puis on complète.
				if ( values.length ) {
					var firstEmpty = section.querySelector( '.aod-cd-opt-name' );
					if ( firstEmpty ) { firstEmpty.value = values.shift(); }
					values.forEach( function ( v ) { addValue( section, v ); } );
				}
				if ( label ) { label.focus(); }
			} );
		} );

		// Délégation : ajout de valeur, suppressions de valeur/section.
		wrap.addEventListener( 'click', function ( e ) {
			var addVal = e.target.closest( '.aod-cd-opt-val-add' );
			if ( addVal ) {
				var sec  = addVal.closest( '.aod-cd-opt-section' );
				var node = sec ? addValue( sec, null ) : null;
				if ( node ) {
					var n = node.querySelector( '.aod-cd-opt-name' );
					if ( n ) { n.focus(); }
				}
				return;
			}
			var valDel = e.target.closest( '.aod-cd-opt-val-del' );
			if ( valDel ) {
				var row = valDel.closest( '.aod-cd-opt-value' );
				if ( row ) { row.remove(); }
				return;
			}
			var secDel = e.target.closest( '.aod-cd-opt-sec-del' );
			if ( secDel ) {
				var s = secDel.closest( '.aod-cd-opt-section' );
				if ( s ) { s.remove(); }
			}
		} );

		// Délégation : toggle « avec photos » + aperçu des photos de valeurs.
		wrap.addEventListener( 'change', function ( e ) {
			var cb = e.target.closest( '.aod-cd-opt-visual-cb' );
			if ( cb ) {
				var sec       = cb.closest( '.aod-cd-opt-section' );
				var valuesBox = sec ? sec.querySelector( '.aod-cd-opt-values' ) : null;
				if ( valuesBox ) { valuesBox.classList.toggle( 'is-visual', cb.checked ); }
				return;
			}
			var file = e.target.closest( '.aod-cd-opt-imgfile' );
			if ( file && file.files && file.files[0] ) {
				var box   = file.closest( '.aod-cd-opt-imgbox' );
				var prev  = box ? box.querySelector( '.aod-cd-opt-imgprev' ) : null;
				var empty = box ? box.querySelector( '.aod-cd-opt-imgempty' ) : null;
				var url   = URL.createObjectURL( file.files[0] );
				if ( prev )  { prev.src = url; prev.style.display = ''; }
				if ( empty ) { empty.style.display = 'none'; }
			}
		} );
	}

	/* Offres (prix par quantité) : lignes ajoutables + aperçu de l'économie par offre */
	function bindOfferRows() {
		var wrap = document.getElementById( 'aod-cd-offers' );
		if ( ! wrap ) { return; }
		var rows   = wrap.querySelector( '.aod-cd-offer-rows' );
		var tpl    = document.getElementById( 'aod-cd-offer-tpl' );
		var addBtn = wrap.querySelector( '.aod-cd-offer-add' );

		if ( addBtn && tpl && rows ) {
			addBtn.addEventListener( 'click', function () {
				var next = parseInt( addBtn.dataset.next, 10 ) || 0;
				var html = tpl.innerHTML.replace( /__i__/g, String( next ) );
				var tmp  = document.createElement( 'div' );
				tmp.innerHTML = html.trim();
				var node = tmp.firstChild;
				rows.appendChild( node );
				addBtn.dataset.next = String( next + 1 );
				var first = node.querySelector( 'input' );
				if ( first ) { first.focus(); }
			} );
		}

		wrap.addEventListener( 'click', function ( e ) {
			var del = e.target.closest( '.aod-cd-offer-del' );
			if ( del ) {
				var row = del.closest( '.aod-cd-offer-row' );
				if ( row ) { row.remove(); updateOffers(); }
			}
		} );

		wrap.addEventListener( 'change', updateOffers );
		wrap.addEventListener( 'input', updateOffers );

		// Aperçu par offre : économie = prix normal × nb d'unités − prix du lot.
		function updateOffers() {
			if ( ! rows ) { return; }
			var base = parseFloat( ( document.querySelector( 'input[name="regular_price"]' ) || {} ).value ) || 0;
			rows.querySelectorAll( '.aod-cd-offer-row' ).forEach( function ( row ) {
				var qtyEl   = row.querySelector( 'input[name^="offer_qty"]' );
				var priceEl = row.querySelector( 'input[name^="offer_price"]' );
				var hint    = row.querySelector( '.aod-cd-offer-eco' );
				if ( ! hint ) {
					hint = document.createElement( 'span' );
					hint.className = 'aod-cd-offer-eco';
					row.appendChild( hint );
				}
				var qty   = qtyEl ? ( parseInt( qtyEl.value, 10 ) || 0 ) : 0;
				var total = priceEl ? ( parseFloat( priceEl.value ) || 0 ) : 0;
				if ( base > 0 && qty >= 2 && total > 0 && total < base * qty ) {
					var eco = Math.round( ( base * qty - total ) * 100 ) / 100;
					hint.textContent = ( CD.i18nEco || 'Économie : %s' ).replace( '%s', eco.toLocaleString( 'fr-DZ' ) );
					hint.classList.remove( 'is-bad' );
				} else if ( qty >= 2 && total > 0 ) {
					hint.textContent = CD.i18nNoDiscount || 'Aucune réduction (sera ignorée)';
					hint.classList.add( 'is-bad' );
				} else {
					hint.textContent = '';
					hint.classList.remove( 'is-bad' );
				}
			} );
		}

		updateOffers();
	}

	/* Catégories : création, renommage et suppression (page « Catégories ») */
	function bindCategories() {
		var wrap = document.getElementById( 'aod-cd-cats' );
		if ( ! wrap ) { return; }
		var list    = wrap.querySelector( '.aod-cd-cat-list' );
		var newForm = document.getElementById( 'aod-cd-cat-new' );
		var tpl     = document.getElementById( 'aod-cd-cat-row-tpl' );

		function post( action, params ) {
			var body = new URLSearchParams();
			body.append( 'action', action );
			body.append( 'nonce', CD.nonce );
			Object.keys( params ).forEach( function ( k ) { body.append( k, params[ k ] ); } );
			return fetch( CD.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } );
		}

		// Création.
		if ( newForm && list && tpl ) {
			newForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var input = newForm.querySelector( '.aod-cd-cat-newname' );
				var name  = ( input.value || '' ).trim();
				if ( ! name ) { return; }
				var btn = newForm.querySelector( 'button[type="submit"]' );
				if ( btn ) { btn.disabled = true; }
				post( 'aod_cd_save_category', { name: name } ).then( function ( res ) {
					if ( btn ) { btn.disabled = false; }
					if ( res && res.success ) {
						var empty = list.querySelector( '.aod-cd-cat-empty' );
						if ( empty ) { empty.remove(); }
						var node = tpl.content.firstElementChild.cloneNode( true );
						node.dataset.id = String( res.data.id );
						node.querySelector( '.aod-cd-cat-name' ).value = res.data.name;
						node.querySelector( '.aod-cd-cat-count' ).textContent = CD.i18nCatZero || '0';
						list.appendChild( node );
						input.value = '';
						input.focus();
						toast( res.data.message || 'OK', false );
					} else {
						toast( ( res && res.data && res.data.message ) || 'Erreur.', true );
					}
				} ).catch( function () {
					if ( btn ) { btn.disabled = false; }
					toast( netErr, true );
				} );
			} );
		}

		// Renommage / suppression (délégation sur la liste).
		if ( list ) {
			list.addEventListener( 'click', function ( e ) {
				var saveBtn = e.target.closest( '.aod-cd-cat-save' );
				var delBtn  = e.target.closest( '.aod-cd-cat-del' );
				if ( saveBtn ) {
					var row   = saveBtn.closest( '.aod-cd-cat-row' );
					var name  = ( row.querySelector( '.aod-cd-cat-name' ).value || '' ).trim();
					if ( ! name ) { return; }
					saveBtn.disabled = true;
					post( 'aod_cd_save_category', { term_id: row.dataset.id, name: name } ).then( function ( res ) {
						saveBtn.disabled = false;
						if ( res && res.success ) {
							row.querySelector( '.aod-cd-cat-name' ).value = res.data.name;
							toast( res.data.message || 'OK', false );
						} else {
							toast( ( res && res.data && res.data.message ) || 'Erreur.', true );
						}
					} ).catch( function () {
						saveBtn.disabled = false;
						toast( netErr, true );
					} );
					return;
				}
				if ( delBtn ) {
					var r = delBtn.closest( '.aod-cd-cat-row' );
					if ( ! window.confirm( CD.i18nCatDelConfirm || 'Supprimer ?' ) ) { return; }
					delBtn.disabled = true;
					post( 'aod_cd_delete_category', { term_id: r.dataset.id } ).then( function ( res ) {
						if ( res && res.success ) {
							r.remove();
							toast( res.data.message || 'OK', false );
						} else {
							delBtn.disabled = false;
							toast( ( res && res.data && res.data.message ) || 'Erreur.', true );
						}
					} ).catch( function () {
						delBtn.disabled = false;
						toast( netErr, true );
					} );
				}
			} );
		}
	}

	/* Palette de couleurs prédéfinies pour les valeurs d'options (pastille 🎨) */
	function bindColorPalette() {
		var palette = document.getElementById( 'aod-cd-palette' );
		if ( ! palette ) { return; }
		var current = null; // pastille déclencheuse en cours.

		function closePalette() {
			palette.hidden = true;
			current = null;
		}

		function openPalette( swatch ) {
			current = swatch;
			palette.hidden = false;
			var r = swatch.getBoundingClientRect();
			var w = palette.offsetWidth || 280;
			var left = Math.max( 8, Math.min( r.left, window.innerWidth - w - 8 ) );
			var top  = r.bottom + 6;
			// Si pas la place en dessous, on remonte au-dessus de la pastille.
			if ( top + palette.offsetHeight > window.innerHeight - 8 ) {
				top = Math.max( 8, r.top - palette.offsetHeight - 6 );
			}
			palette.style.left = left + 'px';
			palette.style.top  = top + 'px';
		}

		// Ouvre la palette depuis une pastille de valeur.
		document.addEventListener( 'click', function ( e ) {
			var sw = e.target.closest( '.aod-cd-opt-swatch' );
			if ( sw ) {
				e.preventDefault();
				if ( current === sw && ! palette.hidden ) { closePalette(); }
				else { openPalette( sw ); }
				return;
			}
			// Choix d'une couleur dans la palette.
			var pick = e.target.closest( '.aod-cd-palette-sw' );
			if ( pick && current ) {
				e.preventDefault();
				var value = current.closest( '.aod-cd-opt-value' );
				var hex   = pick.dataset.hex || '';
				var name  = pick.dataset.name || '';
				if ( value ) {
					var nameInput = value.querySelector( '.aod-cd-opt-name' );
					var hexInput  = value.querySelector( '.aod-cd-opt-hex' );
					if ( nameInput && ! nameInput.value.trim() ) { nameInput.value = name; }
					if ( hexInput ) { hexInput.value = hex; }
				}
				current.style.backgroundColor = hex;
				current.classList.add( 'has-color' );
				closePalette();
				return;
			}
			// Clic en dehors : on ferme.
			if ( ! palette.hidden && ! e.target.closest( '#aod-cd-palette' ) ) {
				closePalette();
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && ! palette.hidden ) { closePalette(); }
		} );
	}

	/* Catégories du produit : liste déroulante sous forme de bouton (multi-sélection) */
	function bindCatDropdown() {
		var dropdowns = document.querySelectorAll( '.aod-cd-catdd' );
		if ( ! dropdowns.length ) { return; }

		dropdowns.forEach( function ( dd ) {
			var btn   = dd.querySelector( '.aod-cd-catdd-btn' );
			var panel = dd.querySelector( '.aod-cd-catdd-panel' );
			var text  = dd.querySelector( '.aod-cd-catdd-text' );
			if ( ! btn || ! panel || ! text ) { return; }
			var placeholder = dd.dataset.placeholder || ( CD && CD.i18nCatPlaceholder ) || '';

			function updateLabel() {
				var names = [];
				panel.querySelectorAll( 'input[type="checkbox"]:checked' ).forEach( function ( cb ) {
					var lbl = cb.closest( '.aod-cd-catdd-opt' );
					names.push( lbl ? lbl.textContent.trim() : cb.value );
				} );
				if ( names.length ) {
					text.textContent = names.join( ', ' );
					text.classList.remove( 'is-placeholder' );
				} else {
					text.textContent = placeholder;
					text.classList.add( 'is-placeholder' );
				}
			}

			function setOpen( on ) {
				panel.hidden = ! on;
				btn.setAttribute( 'aria-expanded', on ? 'true' : 'false' );
			}

			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				setOpen( panel.hidden );
			} );

			panel.addEventListener( 'change', updateLabel );

			// Clic en dehors : on referme.
			document.addEventListener( 'click', function ( e ) {
				if ( ! panel.hidden && ! e.target.closest( '.aod-cd-catdd' ) ) { setOpen( false ); }
			} );
			document.addEventListener( 'keydown', function ( e ) {
				if ( 'Escape' === e.key && ! panel.hidden ) { setOpen( false ); }
			} );

			updateLabel();
		} );
	}

	/* Période de promotion : bouton effacer par date + garde-fou fin ≥ début */
	function bindPromoRange() {
		document.querySelectorAll( '.aod-cd-promo-range' ).forEach( function ( range ) {
			var from = range.querySelector( 'input[name="sale_from"]' );
			var to   = range.querySelector( 'input[name="sale_to"]' );

			// La date de fin ne peut pas précéder la date de début.
			function syncMin() {
				if ( ! from || ! to ) { return; }
				to.min = from.value || '';
				if ( from.value && to.value && to.value < from.value ) { to.value = from.value; }
			}

			range.querySelectorAll( '.aod-cd-datewrap' ).forEach( function ( wrap ) {
				var input = wrap.querySelector( 'input[type="date"]' );
				var clear = wrap.querySelector( '.aod-cd-date-clear' );
				if ( ! input || ! clear ) { return; }

				function refresh() { clear.hidden = ! input.value; }

				input.addEventListener( 'change', function () { refresh(); syncMin(); } );
				input.addEventListener( 'input', refresh );
				clear.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					input.value = '';
					refresh();
					syncMin();
					input.focus();
				} );
				refresh();
			} );

			syncMin();
		} );
	}

	/* Galerie produit : tuile d'ajout, aperçu en direct, glisser-déposer, retrait */
	function bindGallery() {
		var grid  = document.getElementById( 'aod-cd-gallery' );
		var input = document.querySelector( '.aod-cd-galleryfile' );
		if ( ! grid || ! input ) { return; }
		var addBtn = document.getElementById( 'aod-cd-gal-add' );
		var hasDT  = ( typeof DataTransfer === 'function' );
		var store  = hasDT ? new DataTransfer() : null; // accumulateur des fichiers ajoutés.

		// (Re)génère les vignettes d'aperçu des fichiers nouvellement choisis.
		function renderNew() {
			grid.querySelectorAll( '.aod-cd-gal-new' ).forEach( function ( n ) {
				if ( n.dataset.url ) { URL.revokeObjectURL( n.dataset.url ); }
				n.remove();
			} );
			Array.prototype.forEach.call( input.files, function ( f, i ) {
				var url  = URL.createObjectURL( f );
				var tile = document.createElement( 'div' );
				tile.className   = 'aod-cd-gal-item aod-cd-gal-new';
				tile.dataset.idx = String( i );
				tile.dataset.url = url;

				var img = document.createElement( 'img' );
				img.src = url; img.alt = '';

				var tag = document.createElement( 'span' );
				tag.className = 'aod-cd-gal-tag';
				tag.textContent = CD.i18nGalNew || 'Nouveau';

				var rm = document.createElement( 'button' );
				rm.type = 'button';
				rm.className = 'aod-cd-gal-rmnew';
				rm.setAttribute( 'aria-label', CD.i18nGalRemove || 'Retirer' );
				rm.textContent = '✕';

				tile.appendChild( img );
				tile.appendChild( tag );
				tile.appendChild( rm );
				grid.insertBefore( tile, addBtn );
			} );
		}

		// Ajoute des fichiers à la sélection (cumulatif si DataTransfer dispo).
		function addFiles( files ) {
			if ( ! files || ! files.length ) { return; }
			if ( store ) {
				Array.prototype.forEach.call( files, function ( f ) {
					if ( f.type && f.type.indexOf( 'image/' ) === 0 ) { store.items.add( f ); }
				} );
				input.files = store.files;
			}
			renderNew();
		}

		if ( addBtn ) {
			addBtn.addEventListener( 'click', function () { input.click(); } );

			// Glisser-déposer sur la tuile d'ajout.
			[ 'dragover', 'dragenter' ].forEach( function ( ev ) {
				addBtn.addEventListener( ev, function ( e ) { e.preventDefault(); addBtn.classList.add( 'is-drop' ); } );
			} );
			[ 'dragleave', 'dragend', 'drop' ].forEach( function ( ev ) {
				addBtn.addEventListener( ev, function () { addBtn.classList.remove( 'is-drop' ); } );
			} );
			addBtn.addEventListener( 'drop', function ( e ) {
				e.preventDefault();
				if ( e.dataTransfer ) { addFiles( e.dataTransfer.files ); }
			} );
		}

		input.addEventListener( 'change', function () {
			// Sans DataTransfer : pas de cumul, on affiche simplement la sélection courante.
			if ( store ) { addFiles( input.files ); } else { renderNew(); }
		} );

		// Délégation : retrait d'une photo (existante = marquage ; nouvelle = suppression).
		grid.addEventListener( 'click', function ( e ) {
			var rm = e.target.closest( '.aod-cd-gal-rm' );
			if ( rm ) {
				var item = rm.closest( '.aod-cd-gal-item' );
				if ( ! item ) { return; }
				var check = item.querySelector( '.aod-cd-gal-rmcheck' );
				var on    = item.classList.toggle( 'is-removing' );
				if ( check ) { check.checked = on; }
				return;
			}
			var rmNew = e.target.closest( '.aod-cd-gal-rmnew' );
			if ( rmNew ) {
				var tile = rmNew.closest( '.aod-cd-gal-new' );
				if ( ! tile ) { return; }
				if ( store ) {
					var drop = parseInt( tile.dataset.idx, 10 );
					var keep = new DataTransfer();
					Array.prototype.forEach.call( store.files, function ( f, i ) { if ( i !== drop ) { keep.items.add( f ); } } );
					store = keep;
					input.files = store.files;
					renderNew();
				} else {
					if ( tile.dataset.url ) { URL.revokeObjectURL( tile.dataset.url ); }
					tile.remove();
				}
			}
		} );
	}

	/* Selects personnalisés : remplace le <select> natif (qui s'ouvrait hors
	   champ sur mobile) par un menu en position:fixed clampé au viewport.
	   Le <select> d'origine reste dans le DOM (caché) pour la soumission. */
	function bindSelects() {
		document.querySelectorAll( 'select[data-aod-select]' ).forEach( function ( sel ) {
			if ( sel.dataset.aodSelectBound ) { return; }
			sel.dataset.aodSelectBound = '1';

			var wrap = document.createElement( 'div' );
			wrap.className = 'aod-cd-csel';
			sel.parentNode.insertBefore( wrap, sel );
			wrap.appendChild( sel );

			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'aod-cd-csel-btn';
			btn.setAttribute( 'aria-haspopup', 'listbox' );
			btn.setAttribute( 'aria-expanded', 'false' );
			var txt = document.createElement( 'span' );
			txt.className = 'aod-cd-csel-text';
			var caret = document.createElement( 'span' );
			caret.className = 'aod-cd-csel-caret';
			caret.setAttribute( 'aria-hidden', 'true' );
			btn.appendChild( txt );
			btn.appendChild( caret );
			wrap.appendChild( btn );

			// Le menu est attaché au <body> : ainsi aucun overflow parent ne le rogne.
			var menu = document.createElement( 'div' );
			menu.className = 'aod-cd-csel-menu';
			menu.setAttribute( 'role', 'listbox' );
			menu.hidden = true;
			document.body.appendChild( menu );

			function syncText() {
				var o = sel.options[ sel.selectedIndex ];
				txt.textContent = o ? o.textContent.trim() : '';
			}
			function buildMenu() {
				menu.innerHTML = '';
				Array.prototype.forEach.call( sel.options, function ( o ) {
					var opt = document.createElement( 'button' );
					opt.type = 'button';
					opt.className = 'aod-cd-csel-opt' + ( o.selected ? ' is-active' : '' );
					opt.textContent = o.textContent.trim();
					opt.dataset.value = o.value;
					menu.appendChild( opt );
				} );
			}
			function place() {
				var r  = btn.getBoundingClientRect();
				var mh = menu.offsetHeight;
				var mw = menu.offsetWidth;
				var vw = window.innerWidth;
				var vh = window.innerHeight;
				var left = Math.max( 8, Math.min( r.left, vw - mw - 8 ) );
				var top  = r.bottom + 6;
				if ( top + mh > vh - 8 && r.top - 6 - mh > 8 ) { top = r.top - 6 - mh; }
				menu.style.left     = left + 'px';
				menu.style.top      = top + 'px';
				menu.style.minWidth = r.width + 'px';
			}
			function setOpen( on ) {
				if ( on ) {
					buildMenu();
					menu.hidden = false;
					place();
					wrap.classList.add( 'is-open' );
				} else {
					menu.hidden = true;
					wrap.classList.remove( 'is-open' );
				}
				btn.setAttribute( 'aria-expanded', on ? 'true' : 'false' );
			}

			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				setOpen( menu.hidden );
			} );
			menu.addEventListener( 'click', function ( e ) {
				var opt = e.target.closest( '.aod-cd-csel-opt' );
				if ( ! opt ) { return; }
				e.preventDefault();
				sel.value = opt.dataset.value;
				sel.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				syncText();
				setOpen( false );
				btn.focus();
			} );
			document.addEventListener( 'click', function ( e ) {
				if ( menu.hidden ) { return; }
				if ( e.target.closest( '.aod-cd-csel-menu' ) || btn.contains( e.target ) ) { return; }
				setOpen( false );
			} );
			document.addEventListener( 'keydown', function ( e ) {
				if ( 'Escape' === e.key && ! menu.hidden ) { setOpen( false ); btn.focus(); }
			} );
			window.addEventListener( 'resize', function () { if ( ! menu.hidden ) { setOpen( false ); } } );
			window.addEventListener( 'scroll', function () { if ( ! menu.hidden ) { setOpen( false ); } }, true );

			syncText();
		} );
	}

	/* Formulaires de réglages génériques (Livraison, Pixels, WhatsApp…) */
	function bindSettingsForms() {
		document.querySelectorAll( '.aod-cd-settings-form' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var btn = form.querySelector( 'button[type="submit"]' );
				var msg = form.querySelector( '.aod-cd-form-msg' );
				if ( btn ) { btn.disabled = true; }
				if ( msg ) { msg.textContent = ''; }

				var data = new FormData( form );
				data.append( 'action', form.dataset.action );
				data.append( 'nonce', CD.nonce );

				fetch( CD.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( btn ) { btn.disabled = false; }
						if ( res && res.success ) {
							toast( res.data.message || CD.i18nSaved || 'Enregistré', false );
						} else {
							toast( ( res && res.data && res.data.message ) || 'Erreur.', true );
						}
					} )
					.catch( function () {
						if ( btn ) { btn.disabled = false; }
						toast( netErr, true );
					} );
			} );
		} );
	}

	/* Bouton « message test » WhatsApp */
	function bindWhatsappTest() {
		var btn = document.querySelector( '.aod-cd-wa-test' );
		if ( ! btn ) { return; }
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			var body = new URLSearchParams();
			body.append( 'action', 'aod_cd_test_whatsapp' );
			body.append( 'nonce', CD.nonce );
			fetch( CD.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					btn.disabled = false;
					if ( res && res.success ) {
						toast( res.data.message || CD.i18nSent || 'Envoyé', false );
					} else {
						toast( ( res && res.data && res.data.message ) || 'Erreur.', true );
					}
				} )
				.catch( function () { btn.disabled = false; toast( netErr, true ); } );
		} );
	}

	/* Commandes : sélection multiple + suppression groupée */
	function bindOrderBulkDelete() {
		var master = document.querySelector( '.aod-cd-ordercheck-all' );
		var bar    = document.getElementById( 'aod-cd-order-bulk' );
		var table  = document.querySelector( '.aod-cd-table' );
		if ( ! table ) { return; }

		function boxes() {
			return Array.prototype.slice.call( table.querySelectorAll( '.aod-cd-ordercheck' ) );
		}
		function checked() {
			return boxes().filter( function ( c ) { return c.checked; } );
		}

		// Met à jour la barre d'action (visible + compteur) et l'état du « tout cocher ».
		function sync() {
			var all = boxes();
			var sel = checked();
			if ( bar ) {
				bar.hidden = sel.length === 0;
				var n = bar.querySelector( '.aod-cd-bulkbar-n' );
				if ( n ) { n.textContent = String( sel.length ); }
				var bn = bar.querySelector( '.aod-cd-bulkbar-btnn' );
				if ( bn ) { bn.textContent = '(' + sel.length + ')'; }
			}
			if ( master ) {
				master.checked = all.length > 0 && sel.length === all.length;
				master.indeterminate = sel.length > 0 && sel.length < all.length;
			}
			boxes().forEach( function ( c ) {
				var row = c.closest( 'tr' );
				if ( row ) { row.classList.toggle( 'is-selected', c.checked ); }
			} );
		}

		if ( master ) {
			master.addEventListener( 'change', function () {
				boxes().forEach( function ( c ) { c.checked = master.checked; } );
				sync();
			} );
		}

		// Délégation : cocher/décocher une ligne.
		table.addEventListener( 'change', function ( e ) {
			if ( e.target && e.target.classList.contains( 'aod-cd-ordercheck' ) ) { sync(); }
		} );

		// Suppression groupée.
		var delBtn = bar ? bar.querySelector( '.aod-cd-order-bulk-del' ) : null;
		if ( delBtn ) {
			delBtn.addEventListener( 'click', function () {
				var sel = checked();
				if ( ! sel.length ) { return; }
				var ids = sel.map( function ( c ) { return c.value; } );
				var msg = ( CD.i18nOrderBulkDelConfirm || 'Supprimer %d commande(s) ?' ).replace( '%d', ids.length );
				if ( ! window.confirm( msg ) ) { return; }

				delBtn.disabled = true;
				var body = new URLSearchParams();
				body.append( 'action', 'aod_cd_delete_orders' );
				body.append( 'nonce', CD.nonce );
				ids.forEach( function ( id ) { body.append( 'order_ids[]', id ); } );

				fetch( CD.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						delBtn.disabled = false;
						if ( res && res.success ) {
							( res.data.deleted || [] ).forEach( function ( id ) {
								var cb = table.querySelector( '.aod-cd-ordercheck[value="' + id + '"]' );
								var row = cb ? cb.closest( 'tr' ) : null;
								if ( row ) { row.remove(); }
							} );
							if ( master ) { master.checked = false; master.indeterminate = false; }
							sync();
							toast( res.data.message || CD.i18nDeleted || 'Supprimé', false );
							// Plus aucune ligne ? On recharge pour afficher l'état vide / la pagination.
							if ( ! table.querySelectorAll( '.aod-cd-ordercheck' ).length ) {
								setTimeout( function () { window.location.reload(); }, 900 );
							}
						} else {
							toast( ( res && res.data && res.data.message ) || 'Erreur.', true );
						}
					} )
					.catch( function () { delBtn.disabled = false; toast( netErr, true ); } );
			} );
		}

		sync();
	}

	/* Zone de danger : réinitialisation complète de la boutique */
	function bindResetShop() {
		var form = document.getElementById( 'aod-cd-reset-form' );
		if ( ! form ) { return; }
		var input  = form.querySelector( '.aod-cd-reset-input' );
		var btn    = form.querySelector( 'button[type="submit"]' );
		var msg    = form.querySelector( '.aod-cd-form-msg' );
		var phrase = form.dataset.phrase || '';
		if ( ! input || ! btn ) { return; }

		// Le bouton ne s'active que si la phrase est recopiée à l'identique.
		function sync() {
			btn.disabled = ( input.value.trim() !== phrase );
		}
		input.addEventListener( 'input', sync );
		sync();

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( input.value.trim() !== phrase ) { return; }
			// Double garde-fou : confirmation native avant la purge irréversible.
			if ( ! window.confirm( CD.i18nResetConfirm || 'Tout supprimer ?' ) ) { return; }

			btn.disabled = true;
			input.disabled = true;
			if ( msg ) { msg.textContent = CD.i18nResetting || 'Réinitialisation…'; msg.classList.remove( 'is-bad' ); }

			var body = new URLSearchParams();
			body.append( 'action', 'aod_cd_reset_shop' );
			body.append( 'nonce', CD.nonce );
			body.append( 'phrase', input.value.trim() );

			fetch( CD.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( res && res.success ) {
						if ( msg ) { msg.textContent = res.data.message || CD.i18nResetDone || 'OK'; }
						toast( res.data.message || CD.i18nResetDone || 'OK', false );
						// Recharge pour repartir d'une boutique vide (stats, compteurs…).
						setTimeout( function () { window.location.href = CD.base + 'account'; }, 1800 );
					} else {
						input.disabled = false;
						sync();
						var m = ( res && res.data && res.data.message ) || 'Erreur.';
						if ( msg ) { msg.textContent = m; msg.classList.add( 'is-bad' ); }
						toast( m, true );
					}
				} )
				.catch( function () {
					input.disabled = false;
					sync();
					if ( msg ) { msg.textContent = netErr; msg.classList.add( 'is-bad' ); }
					toast( netErr, true );
				} );
		} );
	}

	/* Modale de détail de commande */
	function bindOrderDetail() {
		var modal = document.getElementById( 'aod-cd-modal' );
		if ( ! modal ) { return; }
		var titleEl = modal.querySelector( '.aod-cd-modal-title' );
		var bodyEl  = modal.querySelector( '.aod-cd-modal-body' );

		function open() { modal.hidden = false; document.body.style.overflow = 'hidden'; }
		function close() { modal.hidden = true; document.body.style.overflow = ''; bodyEl.innerHTML = ''; }

		modal.querySelectorAll( '[data-close]' ).forEach( function ( el ) {
			el.addEventListener( 'click', close );
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && ! modal.hidden ) { close(); }
		} );

		document.querySelectorAll( '.aod-cd-order-detail' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				titleEl.textContent = '…';
				bodyEl.innerHTML = '<p class="aod-cd-muted">Chargement…</p>';
				open();

				var body = new URLSearchParams();
				body.append( 'action', 'aod_cd_order_detail' );
				body.append( 'nonce', CD.nonce );
				body.append( 'order_id', btn.dataset.order );

				fetch( CD.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( res && res.success ) {
							titleEl.textContent = res.data.title || '';
							bodyEl.innerHTML = res.data.html || '';
							initOrderEdit( bodyEl, titleEl );
						} else {
							close();
							toast( ( res && res.data && res.data.message ) || 'Erreur.', true );
						}
					} )
					.catch( function () { close(); toast( netErr, true ); } );
			} );
		} );
	}

	/* Édition des infos client / livraison dans la modale détail */
	function initOrderEdit( root, titleEl ) {
		var form     = root.querySelector( '.aod-cd-od-edit' );
		var toggle   = root.querySelector( '.aod-cd-od-edit-toggle' );
		var readview = root.querySelector( '.aod-cd-od-readview' );
		if ( ! form || ! toggle || ! readview ) { return; }

		var cancel = form.querySelector( '.aod-cd-od-edit-cancel' );
		var wilSel = form.querySelector( '.aod-cd-od-wilaya' );
		var comSel = form.querySelector( '.aod-cd-od-commune' );
		var mapEl  = form.querySelector( '.aod-cd-od-wilmap' );
		var msg    = form.querySelector( '.aod-cd-form-msg' );
		var map    = {};
		if ( mapEl ) { try { map = JSON.parse( mapEl.textContent || '{}' ); } catch ( e ) { map = {}; } }

		function showForm( on ) {
			form.hidden = ! on;
			readview.hidden = on;
			toggle.hidden = on;
		}

		toggle.addEventListener( 'click', function () { showForm( true ); } );
		if ( cancel ) { cancel.addEventListener( 'click', function () { showForm( false ); } ); }

		if ( wilSel && comSel ) {
			wilSel.addEventListener( 'change', function () {
				var list = map[ parseInt( wilSel.value, 10 ) ] || [];
				comSel.innerHTML = '<option value="">—</option>';
				list.forEach( function ( c ) {
					var o = document.createElement( 'option' );
					o.value = c.v;
					o.textContent = c.l;
					comSel.appendChild( o );
				} );
			} );
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var btn = form.querySelector( 'button[type="submit"]' );
			if ( btn ) { btn.disabled = true; }
			if ( msg ) { msg.textContent = ''; }

			var body = new URLSearchParams();
			body.append( 'action', 'aod_cd_order_save_info' );
			body.append( 'nonce', CD.nonce );
			body.append( 'order_id', form.dataset.order );
			[ 'name', 'phone', 'address', 'wilaya', 'commune', 'delivery_type' ].forEach( function ( n ) {
				var el = form.querySelector( '[name="' + n + '"]' );
				body.append( n, el ? el.value : '' );
			} );

			// Articles : variantes + quantité + retrait éventuel.
			var items = [];
			form.querySelectorAll( '.aod-cd-od-item' ).forEach( function ( row ) {
				var opts = {};
				row.querySelectorAll( '.aod-cd-od-itemopt' ).forEach( function ( sel ) {
					opts[ sel.dataset.label ] = sel.value;
				} );
				var qtyEl = row.querySelector( '.aod-cd-od-itemqty' );
				var rmEl  = row.querySelector( '.aod-cd-od-itemremove' );
				items.push( {
					id:     row.dataset.item,
					qty:    qtyEl ? qtyEl.value : '1',
					remove: rmEl && rmEl.checked ? 1 : 0,
					opts:   opts
				} );
			} );
			if ( items.length ) {
				body.append( 'items_json', JSON.stringify( items ) );
			}

			fetch( CD.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( btn ) { btn.disabled = false; }
					if ( res && res.success ) {
						if ( titleEl && res.data.title ) { titleEl.textContent = res.data.title; }
						root.innerHTML = res.data.html || '';
						initOrderEdit( root, titleEl );
						toast( res.data.message || 'OK', false );
					} else {
						var m = ( res && res.data && res.data.message ) || 'Erreur.';
						if ( msg ) { msg.textContent = m; }
						toast( m, true );
					}
				} )
				.catch( function () {
					if ( btn ) { btn.disabled = false; }
					toast( netErr, true );
				} );
		} );
	}

	/* Petite modale stylée de saisie (remplace window.prompt) → Promise<string|null> */
	function textPromptModal( opts ) {
		opts = opts || {};
		return new Promise( function ( resolve ) {
			var esc = function ( s ) {
				return String( s == null ? '' : s ).replace( /[&<>"]/g, function ( c ) {
					return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ];
				} );
			};
			var overlay = document.createElement( 'div' );
			overlay.className = 'aod-cd-modal';
			overlay.innerHTML =
				'<div class="aod-cd-modal-backdrop" data-cancel></div>' +
				'<div class="aod-cd-modal-box aod-cd-modal-sm" role="dialog" aria-modal="true">' +
					'<header class="aod-cd-modal-head">' +
						'<h2 class="aod-cd-modal-title">' + esc( opts.title ) + '</h2>' +
						'<button type="button" class="aod-cd-modal-x" data-cancel aria-label="Fermer">&times;</button>' +
					'</header>' +
					'<div class="aod-cd-modal-body">' +
						'<textarea class="aod-cd-textinput" rows="4" placeholder="' + esc( opts.placeholder ) + '"></textarea>' +
						'<div class="aod-cd-modal-actions">' +
							'<button type="button" class="aod-cd-btn" data-cancel>' + esc( opts.cancel || 'Annuler' ) + '</button>' +
							'<button type="button" class="aod-cd-btn aod-cd-btn-primary" data-ok>' + esc( opts.ok || 'Enregistrer' ) + '</button>' +
						'</div>' +
					'</div>' +
				'</div>';
			document.body.appendChild( overlay );
			document.body.style.overflow = 'hidden';
			var ta = overlay.querySelector( '.aod-cd-textinput' );
			setTimeout( function () { ta.focus(); }, 30 );

			function done( val ) {
				document.body.removeChild( overlay );
				document.body.style.overflow = '';
				document.removeEventListener( 'keydown', onKey );
				resolve( val );
			}
			function onKey( e ) {
				if ( 'Escape' === e.key ) { done( null ); }
				else if ( ( e.ctrlKey || e.metaKey ) && 'Enter' === e.key ) { done( ta.value ); }
			}
			overlay.querySelectorAll( '[data-cancel]' ).forEach( function ( el ) {
				el.addEventListener( 'click', function () { done( null ); } );
			} );
			overlay.querySelector( '[data-ok]' ).addEventListener( 'click', function () { done( ta.value ); } );
			document.addEventListener( 'keydown', onKey );
		} );
	}

	/* Ajout d'une note à une commande (AJAX) */
	function bindOrderNote() {
		document.querySelectorAll( '.aod-cd-note-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				textPromptModal( {
					title:       CD.i18nNoteTitle || 'Ajouter une note',
					placeholder: CD.i18nNotePrompt || 'Note pour cette commande…',
					ok:          CD.i18nSave || 'Enregistrer',
					cancel:      CD.i18nCancel || 'Annuler'
				} ).then( function ( note ) {
					if ( null === note || '' === note.trim() ) { return; }
					submitOrderNote( btn, note );
				} );
			} );
		} );
	}

	/* Envoi AJAX de la note */
	function submitOrderNote( btn, note ) {
		btn.disabled = true;

		var body = new URLSearchParams();
		body.append( 'action', 'aod_cd_order_note' );
		body.append( 'nonce', CD.nonce );
		body.append( 'order_id', btn.dataset.order );
		body.append( 'note', note );

		fetch( CD.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				btn.disabled = false;
				if ( res && res.success ) {
					var badge = btn.querySelector( '.aod-cd-badge' );
					if ( res.data.count ) {
						if ( ! badge ) {
							badge = document.createElement( 'span' );
							badge.className = 'aod-cd-badge';
							btn.appendChild( document.createTextNode( ' ' ) );
							btn.appendChild( badge );
						}
						badge.textContent = res.data.count;
					}
					toast( res.data.message || 'OK', false );
				} else {
					toast( ( res && res.data && res.data.message ) || 'Erreur.', true );
				}
			} )
			.catch( function () { btn.disabled = false; toast( netErr, true ); } );
	}

	/* Livraison : checkbox « tout cocher » applique la gratuité à toutes les wilayas */
	function bindShippingFree() {
		var all = document.querySelector( '.aod-cd-free-all' );
		if ( ! all ) { return; }
		var ones = function () {
			return Array.prototype.slice.call( document.querySelectorAll( '.aod-cd-free-one' ) );
		};
		var syncMaster = function () {
			var list = ones();
			if ( ! list.length ) { return; }
			var checked = list.filter( function ( c ) { return c.checked; } ).length;
			all.checked = checked === list.length;
			all.indeterminate = checked > 0 && checked < list.length;
		};
		all.addEventListener( 'change', function () {
			ones().forEach( function ( c ) { c.checked = all.checked; } );
		} );
		document.addEventListener( 'change', function ( e ) {
			if ( e.target && e.target.classList.contains( 'aod-cd-free-one' ) ) { syncMaster(); }
		} );
		syncMaster();
	}

	// Tableau des transporteurs : ligne cliquable qui déplie le panneau d'identifiants.
	function bindCarrierRows() {
		var rows = Array.prototype.slice.call( document.querySelectorAll( '.aod-cd-carrier-row' ) );
		if ( ! rows.length ) { return; }

		var toggle = function ( row ) {
			var panel = row.nextElementSibling;
			if ( ! panel || ! panel.classList.contains( 'aod-cd-carrier-panel' ) ) { return; }
			var open = row.classList.toggle( 'is-open' );
			panel.hidden = ! open;
			row.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		};

		rows.forEach( function ( row ) {
			row.addEventListener( 'click', function () { toggle( row ); } );
			row.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key || ' ' === e.key ) {
					e.preventDefault();
					toggle( row );
				}
			} );
		} );
	}

	// Champs de recherche : filtre les lignes des tableaux Tarifs / Transporteurs.
	function bindShippingSearch() {
		var inputs = Array.prototype.slice.call( document.querySelectorAll( '.aod-cd-search-input' ) );
		if ( ! inputs.length ) { return; }

		var norm = function ( s ) {
			return ( s || '' ).toString().toLowerCase()
				.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' )
				.trim();
		};

		var filterPrices = function ( q ) {
			var table = document.querySelector( '.aod-cd-pricetable' );
			if ( ! table ) { return; }
			var rows = Array.prototype.slice.call( table.querySelectorAll( 'tbody > tr' ) );
			rows.forEach( function ( row ) {
				var cell = row.querySelector( 'td' );
				var hit  = ! q || norm( cell ? cell.textContent : '' ).indexOf( q ) !== -1;
				row.hidden = ! hit;
			} );
		};

		var filterCarriers = function ( q ) {
			var rows = Array.prototype.slice.call( document.querySelectorAll( '.aod-cd-carrier-row' ) );
			rows.forEach( function ( row ) {
				var name  = row.querySelector( '.aod-cd-carrier-name' );
				var hit   = ! q || norm( name ? name.textContent : '' ).indexOf( q ) !== -1;
				var panel = row.nextElementSibling;
				row.hidden = ! hit;
				if ( panel && panel.classList.contains( 'aod-cd-carrier-panel' ) && ! row.classList.contains( 'is-open' ) ) {
					panel.hidden = true;
				}
			} );
		};

		inputs.forEach( function ( input ) {
			input.addEventListener( 'input', function () {
				var q = norm( input.value );
				if ( 'prices' === input.getAttribute( 'data-filter' ) ) {
					filterPrices( q );
				} else {
					filterCarriers( q );
				}
			} );
		} );
	}

	/* Graphe à courbe : infobulle + repère vertical au survol (stats) */
	function bindCharts() {
		document.querySelectorAll( '.aod-cd-linechart' ).forEach( function ( box ) {
			var pts;
			try { pts = JSON.parse( box.dataset.points || '[]' ); } catch ( e ) { return; }
			if ( ! pts.length ) { return; }

			var vw    = parseFloat( box.dataset.vw ) || 820;
			var vh    = parseFloat( box.dataset.vh ) || 215;
			var guide = box.querySelector( '.aod-cd-guide' );
			var tip   = box.querySelector( '.aod-cd-tip' );
			var dots  = box.querySelectorAll( '.aod-cd-dot' );

			// Géométrie réellement rendue du SVG. Avec `preserveAspectRatio` +
			// `max-height` en CSS, le tracé peut être plus étroit que le conteneur
			// (marges vides à gauche/droite) : il faut donc l'échelle ajustée et le
			// décalage du letterboxing, sinon le survol vise le mauvais point.
			function geom() {
				var r  = box.getBoundingClientRect();
				var sc = Math.min( r.width / vw, r.height / vh );
				if ( ! sc || ! isFinite( sc ) ) { sc = r.width / vw; }
				return { rect: r, sc: sc, ox: ( r.width - vw * sc ) / 2, oy: ( r.height - vh * sc ) / 2 };
			}

			function show( i, g ) {
				g = g || geom();
				var p = pts[ i ];
				if ( guide ) {
					guide.setAttribute( 'x1', p.x );
					guide.setAttribute( 'x2', p.x );
					guide.style.opacity = '1';
				}
				dots.forEach( function ( d, j ) {
					d.setAttribute( 'r', j === i ? '4.2' : '2.6' );
				} );
				if ( tip ) {
					tip.innerHTML = '<b>' + p.amount + '</b>' + p.label;
					tip.classList.add( 'show' );
					// Maintient l'infobulle dans le cadre du graphe (clamp horizontal),
					// et la bascule sous le point quand l'espace au-dessus manque.
					var px    = g.ox + p.x * g.sc;
					var py    = g.oy + p.y * g.sc;
					var halfW = tip.offsetWidth / 2;
					var pad   = 6;
					var minX  = halfW + pad;
					var maxX  = box.clientWidth - halfW - pad;
					if ( maxX < minX ) { maxX = minX; }
					px = Math.max( minX, Math.min( maxX, px ) );
					tip.classList.toggle( 'is-below', ( py - tip.offsetHeight - 14 ) < 0 );
					tip.style.left = px + 'px';
					tip.style.top  = py + 'px';
				}
			}

			function hide() {
				if ( guide ) { guide.style.opacity = '0'; }
				if ( tip ) { tip.classList.remove( 'show' ); }
				dots.forEach( function ( d ) { d.setAttribute( 'r', '2.6' ); } );
			}

			function pick( clientX ) {
				var g  = geom();
				var mx = ( clientX - g.rect.left - g.ox ) / g.sc; // coord. viewBox
				var best = 0, bd = Infinity;
				for ( var i = 0; i < pts.length; i++ ) {
					var d = Math.abs( pts[ i ].x - mx );
					if ( d < bd ) { bd = d; best = i; }
				}
				show( best, g );
			}

			box.addEventListener( 'mousemove', function ( e ) { pick( e.clientX ); } );
			box.addEventListener( 'mouseleave', hide );
			box.addEventListener( 'touchstart', function ( e ) {
				if ( e.touches[ 0 ] ) { pick( e.touches[ 0 ].clientX ); }
			}, { passive: true } );
			box.addEventListener( 'touchmove', function ( e ) {
				if ( e.touches[ 0 ] ) { pick( e.touches[ 0 ].clientX ); }
			}, { passive: true } );
		} );
	}

	/* Menu déroulant de la barre latérale (mobile) */
	function bindNavToggle() {
		var btn  = document.querySelector( '.aod-cd-navtoggle' );
		var side = document.querySelector( '.aod-cd-side' );
		if ( ! btn || ! side ) { return; }
		btn.addEventListener( 'click', function () {
			var open = side.classList.toggle( 'is-open' );
			btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} );
	}

	/* Filtre de statut (page Commandes) regroupé dans un bouton déroulant. */
	function bindFilterDropdown() {
		document.querySelectorAll( '.aod-cd-filterdd' ).forEach( function ( dd ) {
			var btn  = dd.querySelector( '.aod-cd-filterdd-btn' );
			var menu = dd.querySelector( '.aod-cd-filterdd-menu' );
			if ( ! btn || ! menu ) { return; }

			function setOpen( on ) {
				menu.hidden = ! on;
				btn.setAttribute( 'aria-expanded', on ? 'true' : 'false' );
			}

			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				setOpen( menu.hidden );
			} );

			// Clic en dehors : on referme.
			document.addEventListener( 'click', function ( e ) {
				if ( ! menu.hidden && ! e.target.closest( '.aod-cd-filterdd' ) ) { setOpen( false ); }
			} );
			document.addEventListener( 'keydown', function ( e ) {
				if ( 'Escape' === e.key && ! menu.hidden ) { setOpen( false ); btn.focus(); }
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		try { bindNavToggle(); } catch ( err ) { /* menu mobile */ }
		try { bindFilterDropdown(); } catch ( err ) { /* filtre commandes */ }
		bindStatus();
		bindProducts();
		try { bindCategories(); }   catch ( err ) { /* catégories */ }
		try { bindCatDropdown(); }  catch ( err ) { /* déroulant catégories */ }
		try { bindPromoRange(); }   catch ( err ) { /* période promo */ }
		try { bindGallery(); }      catch ( err ) { /* galerie produit */ }
		try { bindColorPalette(); } catch ( err ) { /* palette */ }
		try { bindCharts(); }       catch ( err ) { /* graphe stats */ }
		try { bindSelects(); } catch ( err ) { /* selects custom */ }
		bindSettingsForms();
		bindShippingFree();
		bindCarrierRows();
		bindShippingSearch();
		bindWhatsappTest();
		bindOrderDetail();
		bindOrderNote();
		try { bindOrderBulkDelete(); } catch ( err ) { /* sélection commandes */ }
		try { bindResetShop(); } catch ( err ) { /* zone de danger */ }
	} );
}() );
