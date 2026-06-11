/* AOD Client Dashboard — interactions front-end */
( function () {
	'use strict';

	var CD = window.AOD_CD || {};

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
		document.querySelectorAll( '.aod-cd-status' ).forEach( function ( sel ) {
			sel.dataset.prev = sel.value;
			sel.addEventListener( 'change', function () {
				var orderId = sel.dataset.order;
				var status  = sel.value;
				sel.classList.add( 'is-saving' );
				sel.disabled = true;

				var body = new URLSearchParams();
				body.append( 'action', 'aod_cd_order_status' );
				body.append( 'nonce', CD.nonce );
				body.append( 'order_id', orderId );
				body.append( 'status', status );

				fetch( CD.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						sel.classList.remove( 'is-saving' );
						sel.disabled = false;
						if ( res && res.success ) {
							sel.dataset.prev = status;
							sel.classList.add( 'is-ok' );
							setTimeout( function () { sel.classList.remove( 'is-ok' ); }, 1500 );
							var msg = res.data.message || 'OK';
							if ( res.data.tracking ) {
								msg += ' — ' + ( CD.i18nShipped || 'Colis créé' ) + ' : ' + res.data.tracking;
							}
							toast( msg, false );
						} else {
							sel.value = sel.dataset.prev;
							toast( ( res && res.data && res.data.message ) || 'Erreur.', true );
						}
					} )
					.catch( function () {
						sel.classList.remove( 'is-saving' );
						sel.disabled = false;
						sel.value = sel.dataset.prev;
						toast( 'Erreur réseau.', true );
					} );
			} );
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
						toast( 'Erreur réseau.', true );
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
				if ( ! window.confirm( 'Supprimer « ' + name + ' » ? (déplacé dans la corbeille)' ) ) { return; }
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
							toast( res.data.message || 'Supprimé', false );
						} else {
							b.disabled = false;
							toast( ( res && res.data && res.data.message ) || 'Erreur.', true );
						}
					} )
					.catch( function () { b.disabled = false; toast( 'Erreur réseau.', true ); } );
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
					hint.textContent = 'Économie : ' + eco.toLocaleString( 'fr-DZ' );
					hint.classList.remove( 'is-bad' );
				} else if ( qty >= 2 && total > 0 ) {
					hint.textContent = 'Aucune réduction (sera ignorée)';
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
					toast( 'Erreur réseau.', true );
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
						toast( 'Erreur réseau.', true );
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
						toast( 'Erreur réseau.', true );
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
							toast( res.data.message || 'Enregistré', false );
						} else {
							toast( ( res && res.data && res.data.message ) || 'Erreur.', true );
						}
					} )
					.catch( function () {
						if ( btn ) { btn.disabled = false; }
						toast( 'Erreur réseau.', true );
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
						toast( res.data.message || 'Envoyé', false );
					} else {
						toast( ( res && res.data && res.data.message ) || 'Erreur.', true );
					}
				} )
				.catch( function () { btn.disabled = false; toast( 'Erreur réseau.', true ); } );
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
					.catch( function () { close(); toast( 'Erreur réseau.', true ); } );
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
					toast( 'Erreur réseau.', true );
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
			.catch( function () { btn.disabled = false; toast( 'Erreur réseau.', true ); } );
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
			var guide = box.querySelector( '.aod-cd-guide' );
			var tip   = box.querySelector( '.aod-cd-tip' );
			var dots  = box.querySelectorAll( '.aod-cd-dot' );

			function scale() { return box.clientWidth / vw; }

			function show( i ) {
				var s = scale();
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
					var px    = p.x * s;
					var py    = p.y * s;
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
				var rect = box.getBoundingClientRect();
				var mx   = ( clientX - rect.left ) / scale(); // coord. viewBox
				var best = 0, bd = Infinity;
				for ( var i = 0; i < pts.length; i++ ) {
					var d = Math.abs( pts[ i ].x - mx );
					if ( d < bd ) { bd = d; best = i; }
				}
				show( best );
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

	document.addEventListener( 'DOMContentLoaded', function () {
		bindStatus();
		bindProducts();
		try { bindCategories(); }   catch ( err ) { /* catégories */ }
		try { bindColorPalette(); } catch ( err ) { /* palette */ }
		try { bindCharts(); }       catch ( err ) { /* graphe stats */ }
		bindSettingsForms();
		bindShippingFree();
		bindCarrierRows();
		bindShippingSearch();
		bindWhatsappTest();
		bindOrderDetail();
		bindOrderNote();
	} );
}() );
