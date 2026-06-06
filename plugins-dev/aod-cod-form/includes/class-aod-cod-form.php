<?php
/**
 * Cœur : rendu du formulaire COD + traitement de la commande.
 *
 * @package AOD_COD_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AOD_COD_Form {

	/** @var AOD_COD_Form|null */
	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Assets front.
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );

		// Affichage auto sur la page produit, juste après le bouton d'ajout au panier.
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_auto' ), 35 );

		// Boutique 100 % COD : on retire le formulaire natif (dropdown variations + « Ajouter au panier »)
		// pour ne garder QUE le formulaire COD (évite la redondance du sélecteur de couleur).
		add_action( 'woocommerce_single_product_summary', array( $this, 'strip_native_add_to_cart' ), 1 );
		add_filter( 'astra_woo_single_product_structure', array( $this, 'remove_astra_add_to_cart' ) );

		// Shortcode : [aod_cod_form product_id="123"]
		add_shortcode( 'aod_cod_form', array( $this, 'shortcode' ) );

		// AJAX (connecté + visiteur).
		add_action( 'wp_ajax_aod_cod_submit', array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_nopriv_aod_cod_submit', array( $this, 'handle_submit' ) );
	}

	/**
	 * Retire le formulaire d'ajout au panier natif de WooCommerce sur la page produit
	 * (dropdown variations pour les produits variables, bouton « Ajouter au panier » pour les simples).
	 * La commande passe exclusivement par le formulaire COD ci-dessous.
	 */
	public function strip_native_add_to_cart() {
		// Thèmes standard : le bouton/dropdown est rendu par ce hook à la priorité 30.
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
	}

	/**
	 * Thème Astra : il ne passe PAS par le hook standard mais rend sa propre
	 * structure (Astra_Woocommerce::single_product_content_structure) qui appelle
	 * directement woocommerce_template_single_add_to_cart() pour le composant « add_cart ».
	 * On retire ce composant pour ne garder que le formulaire COD.
	 *
	 * @param array $structure Liste ordonnée des composants de la fiche produit.
	 * @return array
	 */
	public function remove_astra_add_to_cart( $structure ) {
		if ( is_array( $structure ) ) {
			$structure = array_values( array_diff( $structure, array( 'add_cart' ) ) );
		}
		return $structure;
	}

	/**
	 * Enregistre/charge CSS + JS et passe les données à JS.
	 */
	public function assets() {
		// Version basée sur la date de modif du fichier : invalide le cache navigateur à chaque édition.
		$css_path = AOD_COD_PATH . 'assets/css/aod-cod-form.css';
		$js_path  = AOD_COD_PATH . 'assets/js/aod-cod-form.js';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : AOD_COD_VERSION;
		$js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : AOD_COD_VERSION;

		wp_register_style( 'aod-cod-form', AOD_COD_URL . 'assets/css/aod-cod-form.css', array(), $css_ver );
		wp_register_script( 'aod-cod-form', AOD_COD_URL . 'assets/js/aod-cod-form.js', array( 'jquery' ), $js_ver, true );

		// Construit une table légère pour le JS. communes = [{v: nom latin, l: libellé affiché}].
		$wilayas = array();
		foreach ( AOD_COD_Data::places() as $w ) {
			$code     = (int) $w['code'];
			$communes = array();
			foreach ( $w['communes'] as $c ) {
				$ar         = isset( $c['name_ar'] ) ? $c['name_ar'] : '';
				$communes[] = array(
					'v' => $c['name'],
					'l' => AOD_COD_Data::label( $c['name'], $ar ),
				);
			}
			$wilayas[ $code ] = array(
				'name'     => AOD_COD_Data::label( $w['name'], isset( $w['name_ar'] ) ? $w['name_ar'] : '' ),
				'communes' => $communes,
				'home'     => AOD_COD_Data::price_for( $code, 'home' ),
				'desk'     => AOD_COD_Data::price_for( $code, 'desk' ),
			);
		}

		wp_localize_script( 'aod-cod-form', 'AOD_COD', array(
			'ajax_url'  => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'aod_cod_nonce' ),
			'wilayas'   => $wilayas,
			'currency'  => get_woocommerce_currency_symbol(),
			'free_shipping' => array(
				'threshold' => AOD_COD_Data::free_shipping_threshold(),
			),
			'i18n'      => array(
				'choose_wilaya'  => __( 'Choisir une wilaya', 'aod-cod-form' ),
				'choose_commune' => __( 'Choisir une commune', 'aod-cod-form' ),
				'choose_color'   => __( 'Veuillez choisir une couleur.', 'aod-cod-form' ),
				'sending'        => __( 'Envoi en cours…', 'aod-cod-form' ),
				'delivery'       => __( 'Livraison', 'aod-cod-form' ),
				'total'          => __( 'Total', 'aod-cod-form' ),
				'free'           => __( 'Offerte', 'aod-cod-form' ),
				/* translators: %s: montant restant à ajouter */
				'free_hint'      => __( 'Plus que %s pour la livraison gratuite !', 'aod-cod-form' ),
				'free_active'    => __( '🎉 Livraison gratuite débloquée !', 'aod-cod-form' ),
				'phone_invalid'  => __( 'Numéro de téléphone invalide (ex : 0550 12 34 56).', 'aod-cod-form' ),
				'required'       => __( 'Veuillez remplir tous les champs obligatoires.', 'aod-cod-form' ),
			),
		) );
	}

	/**
	 * Rendu automatique sur la page produit simple.
	 */
	public function render_auto() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		echo $this->get_form_html( $product->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Shortcode.
	 *
	 * @param array $atts
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'product_id' => 0 ), $atts, 'aod_cod_form' );
		$pid  = (int) $atts['product_id'];
		if ( ! $pid && is_product() ) {
			$pid = get_the_ID();
		}
		if ( ! $pid ) {
			return '';
		}
		return $this->get_form_html( $pid );
	}

	/**
	 * Génère le HTML du formulaire.
	 *
	 * @param int $product_id
	 * @return string
	 */
	public function get_form_html( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_purchasable() ) {
			return '';
		}

		wp_enqueue_style( 'aod-cod-form' );
		wp_enqueue_script( 'aod-cod-form' );

		// Produit variable : on liste les variations couleur disponibles.
		$is_variable = $product->is_type( 'variable' );
		$variations  = array();
		if ( $is_variable ) {
			foreach ( $product->get_children() as $cid ) {
				$v = wc_get_product( $cid );
				if ( ! $v || ! $v->is_purchasable() || ! $v->is_in_stock() ) {
					continue;
				}
				$atts  = $v->get_attributes();
				$color = isset( $atts['couleur'] ) ? $atts['couleur'] : ( $atts ? reset( $atts ) : '' );
				if ( '' === $color ) {
					continue;
				}
				$img_id        = $v->get_image_id();
				$variations[]  = array(
					'id'       => $v->get_id(),
					'color'    => $color,
					'price'    => (float) wc_get_price_to_display( $v ),
					'img'      => $img_id ? wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' ) : '',
					'img_full' => $img_id ? wp_get_attachment_image_url( $img_id, 'woocommerce_single' ) : '',
					'srcset'   => $img_id ? (string) wp_get_attachment_image_srcset( $img_id, 'woocommerce_single' ) : '',
				);
			}
		}

		$price = ( $is_variable && $variations )
			? (float) $variations[0]['price']
			: (float) wc_get_price_to_display( $product );

		ob_start();
		?>
		<div class="aod-cod" data-product="<?php echo esc_attr( $product_id ); ?>" data-price="<?php echo esc_attr( $price ); ?>">
			<h3 class="aod-cod__title"><?php esc_html_e( 'Commander maintenant — Paiement à la livraison', 'aod-cod-form' ); ?></h3>
			<form class="aod-cod__form" novalidate>
				<?php if ( $is_variable && $variations ) : ?>
					<div class="aod-cod__field aod-cod__colors">
						<label><?php esc_html_e( 'Couleur(s)', 'aod-cod-form' ); ?> <span>*</span></label>
						<p class="aod-cod__colors-hint"><?php esc_html_e( 'Indiquez la quantité par couleur (cliquez une couleur pour voir sa photo).', 'aod-cod-form' ); ?></p>
						<div class="aod-cod__color-list">
							<?php foreach ( $variations as $vi => $v ) : ?>
								<div class="aod-cod__color" data-id="<?php echo esc_attr( $v['id'] ); ?>" data-price="<?php echo esc_attr( $v['price'] ); ?>" data-img="<?php echo esc_url( $v['img'] ); ?>" data-img-full="<?php echo esc_url( $v['img_full'] ); ?>" data-srcset="<?php echo esc_attr( $v['srcset'] ); ?>">
									<span class="aod-cod__color-pick">
										<?php if ( $v['img'] ) : ?>
											<span class="aod-cod__color-thumb" style="background-image:url('<?php echo esc_url( $v['img'] ); ?>')"></span>
										<?php endif; ?>
										<span class="aod-cod__color-info">
											<span class="aod-cod__color-name"><?php echo esc_html( $v['color'] ); ?></span>
											<span class="aod-cod__color-price"><?php echo wp_strip_all_tags( wc_price( $v['price'] ) ); ?></span>
										</span>
									</span>
									<span class="aod-cod__color-qty">
										<button type="button" class="aod-cod__qstep" data-step="-1" aria-label="<?php esc_attr_e( 'Diminuer', 'aod-cod-form' ); ?>">&minus;</button>
										<input type="number" class="aod-cod__color-qtyinput" name="color_qty[<?php echo esc_attr( $v['id'] ); ?>]" value="0" min="0" step="1" inputmode="numeric" aria-label="<?php echo esc_attr( sprintf( /* translators: %s couleur */ __( 'Quantité %s', 'aod-cod-form' ), $v['color'] ) ); ?>">
										<button type="button" class="aod-cod__qstep" data-step="1" aria-label="<?php esc_attr_e( 'Augmenter', 'aod-cod-form' ); ?>">+</button>
									</span>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>
				<div class="aod-cod__field">
					<label><?php esc_html_e( 'Nom complet', 'aod-cod-form' ); ?> <span>*</span></label>
					<input type="text" name="name" autocomplete="name" required>
				</div>
				<div class="aod-cod__field">
					<label><?php esc_html_e( 'Téléphone', 'aod-cod-form' ); ?> <span>*</span></label>
					<input type="tel" name="phone" inputmode="numeric" autocomplete="tel" placeholder="0550 12 34 56" required>
				</div>
				<div class="aod-cod__row">
					<div class="aod-cod__field">
						<label><?php esc_html_e( 'Wilaya', 'aod-cod-form' ); ?> <span>*</span></label>
						<select name="wilaya" required>
							<option value=""><?php esc_html_e( 'Choisir une wilaya', 'aod-cod-form' ); ?></option>
							<?php foreach ( AOD_COD_Data::places() as $w ) : ?>
								<option value="<?php echo esc_attr( $w['code'] ); ?>">
									<?php echo esc_html( sprintf( '%02d - %s', $w['code'], AOD_COD_Data::label( $w['name'], isset( $w['name_ar'] ) ? $w['name_ar'] : '' ) ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="aod-cod__field">
						<label><?php esc_html_e( 'Commune', 'aod-cod-form' ); ?> <span>*</span></label>
						<select name="commune" required disabled>
							<option value=""><?php esc_html_e( 'Choisir une commune', 'aod-cod-form' ); ?></option>
						</select>
					</div>
				</div>
				<div class="aod-cod__field aod-cod__delivery">
					<label><?php esc_html_e( 'Mode de livraison', 'aod-cod-form' ); ?></label>
					<div class="aod-cod__radios">
						<label class="aod-cod__radio">
							<input type="radio" name="delivery" value="home" checked>
							<span class="aod-cod__radio-card">
								<span class="aod-cod__radio-icon" aria-hidden="true">🏠</span>
								<span class="aod-cod__radio-text">
									<span class="aod-cod__radio-title"><?php esc_html_e( 'À domicile', 'aod-cod-form' ); ?></span>
									<span class="aod-cod__radio-price" data-type="home"></span>
								</span>
							</span>
						</label>
						<label class="aod-cod__radio">
							<input type="radio" name="delivery" value="desk">
							<span class="aod-cod__radio-card">
								<span class="aod-cod__radio-icon" aria-hidden="true">🏢</span>
								<span class="aod-cod__radio-text">
									<span class="aod-cod__radio-title"><?php esc_html_e( 'Stop-desk (bureau)', 'aod-cod-form' ); ?></span>
									<span class="aod-cod__radio-price" data-type="desk"></span>
								</span>
							</span>
						</label>
					</div>
					<p class="aod-cod__free-hint" hidden></p>
				</div>
				<div class="aod-cod__field aod-cod__address">
					<label><?php esc_html_e( 'Adresse', 'aod-cod-form' ); ?></label>
					<input type="text" name="address" autocomplete="street-address">
				</div>
				<?php if ( ! ( $is_variable && $variations ) ) : ?>
				<div class="aod-cod__field aod-cod__qty">
					<label><?php esc_html_e( 'Quantité', 'aod-cod-form' ); ?></label>
					<input type="number" name="qty" value="1" min="1" step="1">
				</div>
				<?php endif; ?>

				<div class="aod-cod__summary">
					<div><span><?php esc_html_e( 'Sous-total', 'aod-cod-form' ); ?></span> <strong class="aod-cod__subtotal"></strong></div>
					<div><span><?php esc_html_e( 'Livraison', 'aod-cod-form' ); ?></span> <strong class="aod-cod__shipping"></strong></div>
					<div class="aod-cod__grand"><span><?php esc_html_e( 'Total', 'aod-cod-form' ); ?></span> <strong class="aod-cod__total"></strong></div>
				</div>

				<button type="submit" class="aod-cod__submit button">
					<?php esc_html_e( 'Confirmer la commande', 'aod-cod-form' ); ?>
				</button>
				<p class="aod-cod__reassure">
					<span aria-hidden="true">🔒</span>
					<?php esc_html_e( 'Paiement à la livraison — vous payez à la réception, aucun versement à l’avance.', 'aod-cod-form' ); ?>
				</p>
				<p class="aod-cod__msg" role="alert"></p>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Traite la soumission AJAX : valide, crée la commande WooCommerce en COD.
	 */
	public function handle_submit() {
		check_ajax_referer( 'aod_cod_nonce', 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$name       = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$phone_raw  = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$wilaya     = isset( $_POST['wilaya'] ) ? absint( $_POST['wilaya'] ) : 0;
		$commune    = isset( $_POST['commune'] ) ? sanitize_text_field( wp_unslash( $_POST['commune'] ) ) : '';
		$address    = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
		$delivery   = ( isset( $_POST['delivery'] ) && 'desk' === $_POST['delivery'] ) ? 'desk' : 'home';
		$qty        = isset( $_POST['qty'] ) ? max( 1, absint( $_POST['qty'] ) ) : 1;
		// Produit variable : quantité par couleur, ex. color_qty[37]=2&color_qty[39]=1.
		$color_qty  = ( isset( $_POST['color_qty'] ) && is_array( $_POST['color_qty'] ) ) ? wp_unslash( $_POST['color_qty'] ) : array();

		// Normalise le téléphone DZ : 9 ou 10 chiffres commençant par 0.
		$phone = preg_replace( '/\D+/', '', $phone_raw );

		// --- Validations serveur (ne jamais faire confiance au client) ---
		$errors = array();
		if ( '' === $name ) {
			$errors[] = __( 'Le nom est obligatoire.', 'aod-cod-form' );
		}
		if ( ! preg_match( '/^0[5-7][0-9]{8}$/', $phone ) ) {
			$errors[] = __( 'Numéro de téléphone invalide.', 'aod-cod-form' );
		}
		if ( ! $wilaya || '' === AOD_COD_Data::wilaya_name( $wilaya ) ) {
			$errors[] = __( 'Wilaya invalide.', 'aod-cod-form' );
		} elseif ( ! AOD_COD_Data::commune_valid( $wilaya, $commune ) ) {
			$errors[] = __( 'Commune invalide.', 'aod-cod-form' );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_purchasable() ) {
			$errors[] = __( 'Produit indisponible.', 'aod-cod-form' );
		}

		// Lignes à commander : [ ['product'=>WC_Product, 'qty'=>int], ... ].
		// Produit variable = une ligne par couleur dont la quantité est > 0.
		// Produit simple   = une seule ligne avec la quantité globale.
		$lines = array();
		if ( $product && $product->is_type( 'variable' ) ) {
			foreach ( $color_qty as $vid => $vqty ) {
				$vid  = absint( $vid );
				$vqty = absint( $vqty );
				if ( $vqty < 1 ) {
					continue;
				}
				$variation = wc_get_product( $vid );
				if ( ! $variation || ! $variation->is_type( 'variation' )
					|| (int) $variation->get_parent_id() !== (int) $product->get_id()
					|| ! $variation->is_purchasable() ) {
					continue; // Couleur inconnue ou indisponible : ignorée.
				}
				// Contrôle de stock par couleur.
				if ( $variation->managing_stock() && ! $variation->backorders_allowed() ) {
					$avail = $variation->get_stock_quantity();
					if ( null !== $avail && $vqty > $avail ) {
						$atts  = $variation->get_attributes();
						$label = isset( $atts['couleur'] ) ? $atts['couleur'] : ( $atts ? reset( $atts ) : '' );
						/* translators: 1: couleur, 2: stock restant */
						$errors[] = sprintf( __( 'Stock insuffisant pour « %1$s » (il en reste %2$d).', 'aod-cod-form' ), $label, (int) $avail );
						continue;
					}
				}
				$lines[] = array( 'product' => $variation, 'qty' => $vqty );
			}
			if ( ! $lines && ! $errors ) {
				$errors[] = __( 'Veuillez choisir au moins une couleur et sa quantité.', 'aod-cod-form' );
			}
		} elseif ( $product ) {
			$lines[] = array( 'product' => $product, 'qty' => $qty );
		}

		if ( $errors ) {
			wp_send_json_error( array( 'message' => implode( ' ', $errors ) ), 400 );
		}

		// --- Création de la commande ---
		try {
			$order    = wc_create_order();
			$subtotal = 0;
			foreach ( $lines as $line ) {
				$order->add_product( $line['product'], $line['qty'] );
				$subtotal += (float) wc_get_price_to_display( $line['product'] ) * $line['qty'];
			}

			$address_parts = array(
				'first_name' => $name,
				'phone'      => $phone,
				'address_1'  => $address,
				'city'       => $commune,
				'state'      => 'DZ-' . str_pad( $wilaya, 2, '0', STR_PAD_LEFT ),
				'country'    => 'DZ',
			);
			$order->set_address( $address_parts, 'billing' );
			$order->set_address( $address_parts, 'shipping' );

			// Frais de livraison (seuil de gratuité appliqué côté serveur).
			$base_cost = AOD_COD_Data::price_for( $wilaya, $delivery );
			$ship_cost = AOD_COD_Data::effective_shipping( $wilaya, $delivery, $subtotal );
			$is_free   = ( $base_cost > 0 && $ship_cost <= 0 );

			$label = ( 'desk' === $delivery )
				? __( 'Stop-desk', 'aod-cod-form' )
				: __( 'Domicile', 'aod-cod-form' );

			if ( $is_free ) {
				// Ligne à 0 pour que le client voie « Livraison offerte » sur la commande.
				$item = new WC_Order_Item_Shipping();
				$item->set_method_title( sprintf( '%s — %s', __( 'Livraison offerte', 'aod-cod-form' ), AOD_COD_Data::wilaya_name( $wilaya ) ) . ' (' . $label . ')' );
				$item->set_method_id( 'aod_cod' );
				$item->set_total( 0 );
				$order->add_item( $item );
			} elseif ( $ship_cost > 0 ) {
				$item = new WC_Order_Item_Shipping();
				$item->set_method_title( sprintf( '%s — %s', __( 'Livraison', 'aod-cod-form' ), AOD_COD_Data::wilaya_name( $wilaya ) ) . ' (' . $label . ')' );
				$item->set_method_id( 'aod_cod' );
				$item->set_total( $ship_cost );
				$order->add_item( $item );
			}

			$order->set_payment_method( 'cod' );
			$order->set_payment_method_title( __( 'Paiement à la livraison', 'aod-cod-form' ) );

			// Métadonnées utiles pour la préparation/livraison.
			$order->update_meta_data( '_aod_wilaya_code', $wilaya );
			$order->update_meta_data( '_aod_wilaya_name', AOD_COD_Data::wilaya_name( $wilaya ) );
			$order->update_meta_data( '_aod_wilaya_name_ar', AOD_COD_Data::wilaya_name_ar( $wilaya ) );
			$order->update_meta_data( '_aod_commune', $commune );
			$order->update_meta_data( '_aod_commune_ar', AOD_COD_Data::commune_name_ar( $wilaya, $commune ) );
			$order->update_meta_data( '_aod_delivery_type', $delivery );
			$order->update_meta_data( '_aod_source', 'aod-cod-form' );

			$order->calculate_totals();
			$order->update_status( 'processing', __( 'Commande COD via le formulaire AOD.', 'aod-cod-form' ) );

			// Le prospect (panier abandonné) devient une commande confirmée.
			if ( class_exists( 'AOD_COD_Leads' ) ) {
				$lead_token = isset( $_POST['lead_token'] ) ? sanitize_text_field( wp_unslash( $_POST['lead_token'] ) ) : '';
				AOD_COD_Leads::mark_converted( $lead_token, $order->get_id() );
			}

			// Permet aux modules (notif WhatsApp, etc.) de réagir à la commande COD finalisée.
			do_action( 'aod_cod_order_created', $order );

			$redirect = $order->get_checkout_order_received_url();

			wp_send_json_success( array(
				'order_id' => $order->get_id(),
				'redirect' => $redirect,
				'message'  => __( 'Commande enregistrée ! Nous vous appellerons pour confirmer.', 'aod-cod-form' ),
			) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => __( 'Erreur lors de la création de la commande.', 'aod-cod-form' ) ), 500 );
		}
	}
}
