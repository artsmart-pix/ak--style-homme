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
		try { bindOptions(); }   catch ( err ) { /* options */ }
		try { bindTierRows(); }  catch ( err ) { /* paliers */ }
		try { bindPackRows(); }  catch ( err ) { /* packs */ }

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

	/* Paliers de prix par quantité (packs) : lignes ajoutables */
	function bindTierRows() {
		var wrap = document.getElementById( 'aod-cd-tiers' );
		if ( ! wrap ) { return; }
		var rows   = wrap.querySelector( '.aod-cd-tier-rows' );
		var tpl    = document.getElementById( 'aod-cd-tier-tpl' );
		var addBtn = wrap.querySelector( '.aod-cd-tier-add' );

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
			var del = e.target.closest( '.aod-cd-tier-del' );
			if ( del ) {
				var row = del.closest( '.aod-cd-tier-row' );
				if ( row ) { row.remove(); }
			}
		} );
	}

	/* Pack assortiment : toggle de section, lignes de composants, économie */
	function bindPackRows() {
		var wrap = document.getElementById( 'aod-cd-pack' );
		if ( ! wrap ) { return; }
		var toggle  = document.getElementById( 'aod-cd-pack-toggle' );
		var bodyEl  = wrap.querySelector( '.aod-cd-pack-body' );
		var rows    = wrap.querySelector( '.aod-cd-pack-rows' );
		var tpl     = document.getElementById( 'aod-cd-pack-tpl' );
		var addBtn  = wrap.querySelector( '.aod-cd-pack-add' );
		var savings = wrap.querySelector( '.aod-cd-pack-savings' );

		if ( toggle && bodyEl ) {
			toggle.addEventListener( 'change', function () {
				bodyEl.style.display = toggle.checked ? '' : 'none';
			} );
		}

		if ( addBtn && tpl && rows ) {
			addBtn.addEventListener( 'click', function () {
				var next = parseInt( addBtn.dataset.next, 10 ) || 0;
				var html = tpl.innerHTML.replace( /__i__/g, String( next ) );
				var tmp  = document.createElement( 'div' );
				tmp.innerHTML = html.trim();
				var node = tmp.firstChild;
				rows.appendChild( node );
				addBtn.dataset.next = String( next + 1 );
				updateSavings();
			} );
		}

		wrap.addEventListener( 'click', function ( e ) {
			var del = e.target.closest( '.aod-cd-pack-del' );
			if ( del ) {
				var row = del.closest( '.aod-cd-pack-row' );
				if ( row ) { row.remove(); updateSavings(); }
			}
		} );

		wrap.addEventListener( 'change', updateSavings );
		wrap.addEventListener( 'input', updateSavings );

		// Économie = somme(prix composant × qté) − prix du pack.
		function updateSavings() {
			if ( ! savings || ! rows ) { return; }
			var sum = 0;
			rows.querySelectorAll( '.aod-cd-pack-row' ).forEach( function ( row ) {
				var sel    = row.querySelector( '.aod-cd-pack-select' );
				var qtyEl  = row.querySelector( 'input[type=number]' );
				var qty    = qtyEl ? ( parseInt( qtyEl.value, 10 ) || 0 ) : 0;
				if ( sel && sel.value !== '0' ) {
					var opt = sel.options[ sel.selectedIndex ];
					var p   = parseFloat( opt && opt.getAttribute( 'data-price' ) ) || 0;
					sum += p * qty;
				}
			} );
			var packPrice = parseFloat( ( document.querySelector( 'input[name="regular_price"]' ) || {} ).value ) || 0;
			if ( sum > 0 && packPrice > 0 && sum > packPrice ) {
				var eco = Math.round( ( sum - packPrice ) * 100 ) / 100;
				savings.textContent = 'Valeur séparée : ' + sum.toLocaleString( 'fr-DZ' ) +
					' — économie pour le client : ' + eco.toLocaleString( 'fr-DZ' );
				savings.removeAttribute( 'hidden' );
			} else {
				savings.setAttribute( 'hidden', 'hidden' );
				savings.textContent = '';
			}
		}

		updateSavings();
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
						} else {
							close();
							toast( ( res && res.data && res.data.message ) || 'Erreur.', true );
						}
					} )
					.catch( function () { close(); toast( 'Erreur réseau.', true ); } );
			} );
		} );
	}

	/* Ajout d'une note à une commande (AJAX) */
	function bindOrderNote() {
		document.querySelectorAll( '.aod-cd-note-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var note = window.prompt( CD.i18nNotePrompt || 'Note pour cette commande :' );
				if ( null === note || '' === note.trim() ) { return; }
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
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindStatus();
		bindProducts();
		try { bindCategories(); }   catch ( err ) { /* catégories */ }
		try { bindColorPalette(); } catch ( err ) { /* palette */ }
		bindSettingsForms();
		bindWhatsappTest();
		bindOrderDetail();
		bindOrderNote();
	} );
}() );
