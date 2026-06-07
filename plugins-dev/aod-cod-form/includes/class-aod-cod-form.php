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
				'choose_option'  => __( 'Veuillez sélectionner toutes les options.', 'aod-cod-form' ),
				'sending'        => __( 'Envoi en cours…', 'aod-cod-form' ),
				'delivery'       => __( 'Livraison', 'aod-cod-form' ),
				'total'          => __( 'Total', 'aod-cod-form' ),
				'free'           => __( 'Offerte', 'aod-cod-form' ),
				/* translators: %s: montant restant à ajouter */
				'free_hint'      => __( 'Plus que %s pour la livraison gratuite !', 'aod-cod-form' ),
				'free_active'    => __( '🎉 Livraison gratuite débloquée !', 'aod-cod-form' ),
				'phone_invalid'  => __( 'Numéro de téléphone invalide (ex : 0550 12 34 56).', 'aod-cod-form' ),
				'required'       => __( 'Veuillez remplir tous les champs obligatoires.', 'aod-cod-form' ),
				'prev_image'     => __( 'Image précédente', 'aod-cod-form' ),
				'next_image'     => __( 'Image suivante', 'aod-cod-form' ),
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
	 * Sections d'options d'un produit pour l'affichage (Taille, Couleur…).
	 *
	 * Lit la méta `_aod_options` ; à défaut, migre un ancien produit variable
	 * mono-axe (variations couleur) vers une section visuelle unique.
	 *
	 * @param WC_Product $product
	 * @return array Liste de [ 'label', 'visual', 'values' => [ ['name','price','img','img_full','srcset'], … ] ].
	 */
	protected function get_product_options( $product ) {
		$raw = $product->get_meta( '_aod_options' );
		$out = array();
		if ( is_array( $raw ) && $raw ) {
			foreach ( $raw as $sec ) {
				if ( ! is_array( $sec ) ) {
					continue;
				}
				$values = array();
				$src    = ( isset( $sec['values'] ) && is_array( $sec['values'] ) ) ? $sec['values'] : array();
				foreach ( $src as $val ) {
					$name = isset( $val['name'] ) ? (string) $val['name'] : '';
					if ( '' === $name ) {
						continue;
					}
					$img_id   = isset( $val['image_id'] ) ? (int) $val['image_id'] : 0;
					$hex      = ( isset( $val['hex'] ) && preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $val['hex'] ) ) ? strtolower( (string) $val['hex'] ) : '';
					$single   = $img_id ? wp_get_attachment_image_src( $img_id, 'woocommerce_single' ) : false;
					$values[] = array(
						'name'     => $name,
						'price'    => ( isset( $val['price'] ) && '' !== $val['price'] ) ? (float) $val['price'] : 0.0,
						'img'      => $img_id ? wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' ) : '',
						'img_full' => $single ? $single[0] : '',
						'img_w'    => $single ? (int) $single[1] : 0,
						'img_h'    => $single ? (int) $single[2] : 0,
						'srcset'   => $img_id ? (string) wp_get_attachment_image_srcset( $img_id, 'woocommerce_single' ) : '',
						'hex'      => $hex,
					);
				}
				if ( ! $values ) {
					continue;
				}
				$out[] = array(
					'label'  => isset( $sec['label'] ) ? (string) $sec['label'] : '',
					'visual' => ! empty( $sec['visual'] ),
					'values' => $values,
				);
			}
			if ( $out ) {
				return $out;
			}
		}

		// Compat : ancien produit variable mono-axe → une section visuelle unique.
		if ( $product->is_type( 'variable' ) ) {
			$label = (string) $product->get_meta( '_aod_variant_label' );
			if ( '' === $label ) {
				$label = __( 'Couleur', 'aod-cod-form' );
			}
			$values = array();
			foreach ( $product->get_children() as $cid ) {
				$v = wc_get_product( $cid );
				if ( ! $v || ! $v->is_purchasable() || ! $v->is_in_stock() ) {
					continue;
				}
				$atts = $v->get_attributes();
				$name = $atts ? (string) reset( $atts ) : '';
				if ( '' === $name ) {
					continue;
				}
				$img_id   = $v->get_image_id();
				$single   = $img_id ? wp_get_attachment_image_src( $img_id, 'woocommerce_single' ) : false;
				$values[] = array(
					'name'     => $name,
					'price'    => 0.0,
					'img'      => $img_id ? wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' ) : '',
					'img_full' => $single ? $single[0] : '',
					'img_w'    => $single ? (int) $single[1] : 0,
					'img_h'    => $single ? (int) $single[2] : 0,
					'srcset'   => $img_id ? (string) wp_get_attachment_image_srcset( $img_id, 'woocommerce_single' ) : '',
					'hex'      => '',
				);
			}
			if ( $values ) {
				$out[] = array( 'label' => $label, 'visual' => true, 'values' => $values );
			}
		}
		return $out;
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

		// Sections d'options (Taille, Couleur, Pointure…) — sélection style Shopify.
		$options     = $this->get_product_options( $product );
		$has_options = ! empty( $options );

		$price = (float) wc_get_price_to_display( $product );
		if ( $price <= 0 && $product->is_type( 'variable' ) ) {
			// Produit variable migré : le parent n'a pas de prix propre.
			foreach ( $product->get_children() as $cid ) {
				$cv = wc_get_product( $cid );
				if ( $cv && $cv->is_purchasable() ) {
					$price = (float) wc_get_price_to_display( $cv );
					break;
				}
			}
		}

		// Paliers de prix par quantité (« 2 pour X ») — cumulables avec les sections d'options.
		$tiers = array();
		$raw   = $product->get_meta( '_aod_qty_tiers' );
		if ( is_array( $raw ) ) {
			foreach ( $raw as $t ) {
				$min = isset( $t['min'] ) ? (int) $t['min'] : 0;
				$tp  = isset( $t['price'] ) ? (float) $t['price'] : 0;
				if ( $min >= 2 && $tp > 0 ) {
					$tiers[] = array( 'min' => $min, 'price' => $tp );
				}
			}
		}

		// Arguments de vente (liste à puces).
		$selling_points = $product->get_meta( '_aod_selling_points' );
		if ( ! is_array( $selling_points ) ) {
			$selling_points = array();
		}

		// Pack assortiment : liste des produits inclus (affichage informatif).
		$pack_items = array();
		if ( '1' === (string) $product->get_meta( '_aod_is_pack' ) ) {
			$raw = $product->get_meta( '_aod_pack_items' );
			if ( is_array( $raw ) ) {
				foreach ( $raw as $it ) {
					$cp = wc_get_product( isset( $it['id'] ) ? (int) $it['id'] : 0 );
					if ( $cp ) {
						$pack_items[] = array( 'name' => $cp->get_name(), 'qty' => isset( $it['qty'] ) ? max( 1, (int) $it['qty'] ) : 1 );
					}
				}
			}
		}

		ob_start();
		?>
		<div class="aod-cod" data-product="<?php echo esc_attr( $product_id ); ?>" data-price="<?php echo esc_attr( $price ); ?>" data-tiers="<?php echo esc_attr( wp_json_encode( $tiers ) ); ?>">
			<h3 class="aod-cod__title"><?php esc_html_e( 'Commander maintenant — Paiement à la livraison', 'aod-cod-form' ); ?></h3>
			<?php if ( $selling_points ) : ?>
				<ul class="aod-cod__points">
					<?php foreach ( $selling_points as $pt ) : ?>
						<li><?php echo esc_html( $pt ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( $pack_items ) : ?>
				<div class="aod-cod__pack">
					<span class="aod-cod__pack-label"><?php esc_html_e( 'Ce pack contient :', 'aod-cod-form' ); ?></span>
					<ul class="aod-cod__pack-list">
						<?php foreach ( $pack_items as $it ) : ?>
							<li><span class="aod-cod__pack-qty"><?php echo esc_html( $it['qty'] ); ?>×</span> <?php echo esc_html( $it['name'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
			<form class="aod-cod__form" novalidate>
				<?php foreach ( $options as $si => $sec ) : ?>
					<div class="aod-cod__field aod-cod__optsec<?php echo $sec['visual'] ? ' is-visual' : ''; ?>" data-si="<?php echo esc_attr( $si ); ?>">
						<label class="aod-cod__optlabel"><?php echo esc_html( $sec['label'] ); ?> <span>*</span></label>
						<div class="aod-cod__opts">
							<?php
							foreach ( $sec['values'] as $vi => $val ) :
								$oid = 'aod-cod-opt-' . (int) $product_id . '-' . (int) $si . '-' . (int) $vi;
								?>
								<?php
								$has_hex   = ! empty( $val['hex'] );
								$is_visual = $sec['visual'] || $has_hex;
								?>
								<label class="aod-cod__opt<?php echo $is_visual ? ' aod-cod__opt--visual' : ''; ?>" for="<?php echo esc_attr( $oid ); ?>">
									<input type="radio" id="<?php echo esc_attr( $oid ); ?>" name="opt[<?php echo esc_attr( $si ); ?>]" value="<?php echo esc_attr( $val['name'] ); ?>" data-price="<?php echo esc_attr( $val['price'] ); ?>" data-img="<?php echo esc_url( $val['img'] ); ?>" data-img-full="<?php echo esc_url( $val['img_full'] ); ?>" data-img-w="<?php echo esc_attr( $val['img_w'] ); ?>" data-img-h="<?php echo esc_attr( $val['img_h'] ); ?>" data-srcset="<?php echo esc_attr( $val['srcset'] ); ?>">
									<span class="aod-cod__opt-card">
										<?php if ( $is_visual && $val['img'] ) : ?>
											<span class="aod-cod__opt-thumb" style="background-image:url('<?php echo esc_url( $val['img'] ); ?>')"></span>
										<?php elseif ( $has_hex ) : ?>
											<span class="aod-cod__opt-thumb aod-cod__opt-thumb--color" style="background-color:<?php echo esc_attr( $val['hex'] ); ?>"></span>
										<?php endif; ?>
										<span class="aod-cod__opt-name"><?php echo esc_html( $val['name'] ); ?></span>
										<?php if ( $val['price'] > 0 ) : ?>
											<span class="aod-cod__opt-plus">+<?php echo wp_strip_all_tags( wc_price( $val['price'] ) ); ?></span>
										<?php endif; ?>
									</span>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
				<div class="aod-cod__field aod-cod__float">
					<input type="text" id="aod-cod-name" name="name" autocomplete="name" placeholder=" " required>
					<label for="aod-cod-name"><?php esc_html_e( 'Nom complet', 'aod-cod-form' ); ?> <span>*</span></label>
				</div>
				<div class="aod-cod__field aod-cod__float">
					<input type="tel" id="aod-cod-phone" name="phone" inputmode="numeric" autocomplete="tel" placeholder=" " required>
					<label for="aod-cod-phone"><?php esc_html_e( 'Téléphone', 'aod-cod-form' ); ?> <span>*</span></label>
				</div>
				<div class="aod-cod__row">
					<div class="aod-cod__field aod-cod__float aod-cod__float--sel">
						<select id="aod-cod-wilaya" name="wilaya" required>
							<option value=""><?php esc_html_e( 'Choisir une wilaya', 'aod-cod-form' ); ?></option>
							<?php foreach ( AOD_COD_Data::places() as $w ) : ?>
								<option value="<?php echo esc_attr( $w['code'] ); ?>">
									<?php echo esc_html( sprintf( '%02d - %s', $w['code'], AOD_COD_Data::label( $w['name'], isset( $w['name_ar'] ) ? $w['name_ar'] : '' ) ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<label for="aod-cod-wilaya"><?php esc_html_e( 'Wilaya', 'aod-cod-form' ); ?> <span>*</span></label>
					</div>
					<div class="aod-cod__field aod-cod__float aod-cod__float--sel">
						<select id="aod-cod-commune" name="commune" required disabled>
							<option value=""><?php esc_html_e( 'Choisir une commune', 'aod-cod-form' ); ?></option>
						</select>
						<label for="aod-cod-commune"><?php esc_html_e( 'Commune', 'aod-cod-form' ); ?> <span>*</span></label>
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
				<div class="aod-cod__field aod-cod__address aod-cod__float">
					<input type="text" id="aod-cod-address" name="address" autocomplete="street-address" placeholder=" ">
					<label for="aod-cod-address"><?php esc_html_e( 'Adresse', 'aod-cod-form' ); ?></label>
				</div>
				<div class="aod-cod__field aod-cod__qty aod-cod__float">
					<input type="number" id="aod-cod-qty" name="qty" value="1" min="1" step="1" placeholder=" ">
					<label for="aod-cod-qty"><?php esc_html_e( 'Quantité', 'aod-cod-form' ); ?></label>
					<?php if ( $tiers ) : ?>
						<ul class="aod-cod__tiers">
							<?php foreach ( $tiers as $t ) : ?>
								<li data-min="<?php echo esc_attr( $t['min'] ); ?>">
									<?php
									/* translators: 1: quantité, 2: prix total du lot */
									printf(
										esc_html__( '%1$d pièces : %2$s', 'aod-cod-form' ),
										(int) $t['min'],
										wp_strip_all_tags( wc_price( $t['price'] * $t['min'] ) )
									);
									?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>

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
		// Sélection des options : une valeur par section, ex. opt[0]=L&opt[1]=Rouge.
		$opt_sel    = ( isset( $_POST['opt'] ) && is_array( $_POST['opt'] ) ) ? wp_unslash( $_POST['opt'] ) : array();

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

		// Sélection des options (Taille=L, Couleur=Rouge…) : une valeur par section.
		// Une seule ligne de commande (produit + quantité), suppléments cumulés au prix.
		$option_meta = array();   // label => valeur, visible sur la commande.
		$supplement  = 0.0;       // somme des suppléments de prix des options choisies.
		$lines       = array();
		if ( $product ) {
			$sections = $this->get_product_options( $product );
			foreach ( $sections as $si => $sec ) {
				$chosen = isset( $opt_sel[ $si ] ) ? sanitize_text_field( (string) $opt_sel[ $si ] ) : '';
				$match  = null;
				foreach ( $sec['values'] as $val ) {
					if ( $val['name'] === $chosen ) {
						$match = $val;
						break;
					}
				}
				if ( null === $match ) {
					/* translators: %s : nom de la section (Taille, Couleur…) */
					$errors[] = sprintf( __( 'Veuillez choisir : %s.', 'aod-cod-form' ), $sec['label'] );
					continue;
				}
				$option_meta[ $sec['label'] ] = $match['name'];
				$supplement                  += (float) $match['price'];
			}
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
				// Prix unitaire : palier quantité (produit simple) + suppléments des options.
				$unit       = $this->tier_unit_price( $line['product'], $line['qty'] ) + $supplement;
				$line_total = $unit * $line['qty'];
				$item_id    = $order->add_product( $line['product'], $line['qty'] );
				if ( $item_id ) {
					$item = $order->get_item( $item_id );
					if ( $item ) {
						$item->set_subtotal( $line_total );
						$item->set_total( $line_total );
						// Choix de variantes visibles sur la commande (Taille : L, Couleur : Rouge…).
						foreach ( $option_meta as $olabel => $oval ) {
							$item->add_meta_data( $olabel, $oval, true );
						}
						$item->save();
					}
				}
				$subtotal += $line_total;
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

	/**
	 * Prix unitaire d'un produit pour une quantité donnée, en appliquant les
	 * paliers de prix par quantité (« packs ») définis sur le produit.
	 *
	 * Les paliers ne s'appliquent qu'aux produits simples (jamais aux variations).
	 * On retient le prix du plus grand palier dont la quantité minimale est atteinte,
	 * et uniquement s'il est réellement inférieur au prix de base (anti-incohérence).
	 *
	 * @param WC_Product $product
	 * @param int        $qty
	 * @return float Prix unitaire à facturer.
	 */
	protected function tier_unit_price( $product, $qty ) {
		$base = (float) wc_get_price_to_display( $product );
		if ( ! $product || $product->is_type( 'variation' ) ) {
			return $base;
		}
		$tiers = $product->get_meta( '_aod_qty_tiers' );
		if ( ! is_array( $tiers ) || ! $tiers ) {
			return $base;
		}
		$unit     = $base;
		$best_min = 1;
		foreach ( $tiers as $t ) {
			$min   = isset( $t['min'] ) ? (int) $t['min'] : 0;
			$price = isset( $t['price'] ) ? (float) $t['price'] : 0;
			if ( $min >= 2 && $price > 0 && $price < $base && $qty >= $min && $min >= $best_min ) {
				$best_min = $min;
				$unit     = $price;
			}
		}
		return $unit;
	}
}
