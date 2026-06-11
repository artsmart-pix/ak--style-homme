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

		// Galerie produit : on injecte les photos des options (couleurs…) comme de vraies
		// diapos du carrousel WooCommerce, et on tague chaque diapo avec son ID de média
		// pour que le JS puisse sauter à la bonne photo quand une couleur est choisie.
		add_filter( 'woocommerce_product_get_gallery_image_ids', array( $this, 'inject_option_images_into_gallery' ), 10, 2 );
		add_filter( 'woocommerce_single_product_image_thumbnail_html', array( $this, 'tag_gallery_slide_attachment' ), 10, 2 );

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
				'free'     => AOD_COD_Data::is_free_wilaya( $code ) ? 1 : 0,
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
				'article'        => __( 'Article', 'aod-cod-form' ),
				'add_item'       => __( 'Ajouter un article', 'aod-cod-form' ),
				'remove_item'    => __( 'Retirer cet article', 'aod-cod-form' ),
				'cart_empty'     => __( 'Veuillez ajouter au moins un article.', 'aod-cod-form' ),
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
						'img_id'   => $img_id,
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
					'img_id'   => $img_id,
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
	 * IDs des médias attachés aux options (couleurs…) d'un produit, dans l'ordre
	 * d'affichage et sans doublon. Sert à intégrer ces photos à la galerie.
	 *
	 * @param WC_Product $product
	 * @return int[]
	 */
	protected function get_option_image_ids( $product ) {
		$ids = array();
		foreach ( $this->get_product_options( $product ) as $sec ) {
			foreach ( $sec['values'] as $val ) {
				$id = isset( $val['img_id'] ) ? (int) $val['img_id'] : 0;
				if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}
		}
		return $ids;
	}

	/**
	 * Ajoute les photos des options (couleurs…) comme vraies diapos de la galerie
	 * WooCommerce du produit affiché. On les insère juste après la photo principale
	 * et avant les photos supplémentaires, en évitant tout doublon (image déjà
	 * principale ou déjà dans la galerie).
	 *
	 * @param int[]      $value   IDs de la galerie d'origine.
	 * @param WC_Product $product Produit concerné par le getter.
	 * @return int[]
	 */
	public function inject_option_images_into_gallery( $value, $product ) {
		// Uniquement la galerie du produit réellement consulté (pas les produits liés,
		// le panier, etc.) pour ne pas polluer les autres contextes.
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return $value;
		}
		if ( ! ( $product instanceof WC_Product ) || $product->get_id() !== get_queried_object_id() ) {
			return $value;
		}

		$existing = is_array( $value ) ? array_map( 'intval', $value ) : array();
		$featured = (int) $product->get_image_id();
		$add      = array();
		foreach ( $this->get_option_image_ids( $product ) as $id ) {
			if ( $id === $featured || in_array( $id, $existing, true ) || in_array( $id, $add, true ) ) {
				continue; // Déjà présent comme diapo : le JS la retrouvera par son ID.
			}
			$add[] = $id;
		}
		return array_merge( $add, $existing );
	}

	/**
	 * Tague chaque diapo de la galerie avec l'ID du média qu'elle contient, pour
	 * que le JS puisse cibler la bonne photo (`[data-aod-attachment="ID"]`) au lieu
	 * de comparer des URL fragiles.
	 *
	 * @param string $html          HTML de la diapo.
	 * @param int    $attachment_id ID du média.
	 * @return string
	 */
	public function tag_gallery_slide_attachment( $html, $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id || false === strpos( $html, '<div' ) ) {
			return $html;
		}
		return preg_replace(
			'/<div /',
			'<div data-aod-attachment="' . esc_attr( $attachment_id ) . '" ',
			$html,
			1
		);
	}

	/**
	 * Le visiteur consulte-t-il le site en arabe ? (locale forcée par AOD_COD_Lang).
	 *
	 * @return bool
	 */
	protected function is_arabic() {
		return 0 === strpos( (string) get_locale(), 'ar' );
	}

	/**
	 * Dictionnaire FR→AR des libellés/valeurs de variantes courants (clés en minuscules).
	 * Utilisé en repli quand le gérant n'a pas saisi de traduction arabe explicite.
	 *
	 * @return array
	 */
	protected function variant_dictionary() {
		return array(
			// Phrases d'instruction par défaut (libellés des presets).
			'sélectionner une couleur'  => 'اختر اللون',
			'selectionner une couleur'  => 'اختر اللون',
			'selectioner une couleur'   => 'اختر اللون',
			'sélectionner une taille'   => 'اختر المقاس',
			'selectionner une taille'   => 'اختر المقاس',
			'selectioner une taille'    => 'اختر المقاس',
			'sélectionner une pointure' => 'اختر القياس',
			'selectionner une pointure' => 'اختر القياس',
			'selectioner une pointure'  => 'اختر القياس',
			// Libellés de section simples.
			'taille'    => 'المقاس',
			'tailles'   => 'المقاسات',
			'pointure'  => 'القياس',
			'pointures' => 'القياسات',
			'couleur'   => 'اللون',
			'couleurs'  => 'الألوان',
			'matière'   => 'الخامة',
			'matiere'   => 'الخامة',
			'modèle'    => 'الموديل',
			'modele'    => 'الموديل',
			// Couleurs.
			'noir'        => 'أسود',
			'blanc'       => 'أبيض',
			'gris'        => 'رمادي',
			'rouge'       => 'أحمر',
			'bleu'        => 'أزرق',
			'bleu ciel'   => 'أزرق سماوي',
			'bleu marine' => 'كحلي',
			'vert'        => 'أخضر',
			'jaune'       => 'أصفر',
			'orange'      => 'برتقالي',
			'rose'        => 'وردي',
			'violet'      => 'بنفسجي',
			'marron'      => 'بني',
			'beige'       => 'بيج',
			'doré'        => 'ذهبي',
			'dore'        => 'ذهبي',
			'or'          => 'ذهبي',
			'argent'      => 'فضي',
			'argenté'     => 'فضي',
			'bordeaux'    => 'عنابي',
			'turquoise'   => 'فيروزي',
			'kaki'        => 'كاكي',
			'fuchsia'     => 'فوشيا',
			'corail'      => 'مرجاني',
			'ciel'        => 'سماوي',
			'marine'      => 'كحلي',
			// Nuances (servent au repli mot-à-mot : « Gris clair » → « رمادي فاتح »).
			'clair'  => 'فاتح',
			'claire' => 'فاتح',
			'foncé'  => 'غامق',
			'fonce'  => 'غامق',
			'foncée' => 'غامق',
			'foncee' => 'غامق',
			'pâle'   => 'باهت',
			'pale'   => 'باهت',
			'vif'    => 'زاهي',
			'vive'   => 'زاهي',
		);
	}

	/**
	 * Traduit un libellé/valeur de variante pour l'AFFICHAGE en mode arabe.
	 *
	 * Priorité : dictionnaire intégré (correspondance exacte, puis mot-à-mot pour les
	 * valeurs composées comme « Gris clair ») > texte d'origine (français) si inconnu.
	 * Ne modifie JAMAIS la valeur soumise : le nom canonique reste le français, afin que
	 * le matching serveur et la commande enregistrée restent cohérents.
	 *
	 * @param string $fr Texte d'origine (français).
	 * @return string
	 */
	protected function localize_variant( $fr ) {
		if ( ! $this->is_arabic() ) {
			return (string) $fr;
		}
		$fr   = (string) $fr;
		$dict = $this->variant_dictionary();
		$key  = strtolower( trim( $fr ) );
		if ( isset( $dict[ $key ] ) ) {
			return $dict[ $key ];
		}
		// Repli mot-à-mot pour les valeurs composées (« Gris clair » → « رمادي فاتح »).
		// L'adjectif suit le nom en arabe comme en français : on garde le même ordre.
		// On ne traduit que si TOUS les mots sont connus, sinon on garde le français.
		$tokens = preg_split( '/[\s\-\/]+/u', $key, -1, PREG_SPLIT_NO_EMPTY );
		if ( is_array( $tokens ) && count( $tokens ) > 1 ) {
			$parts = array();
			foreach ( $tokens as $t ) {
				if ( ! isset( $dict[ $t ] ) ) {
					$parts = array();
					break;
				}
				$parts[] = $dict[ $t ];
			}
			if ( $parts ) {
				return implode( ' ', $parts );
			}
		}
		return $fr;
	}

	/**
	 * Sections d'options du configurateur (Taille, Couleur…), en boutons style Shopify.
	 *
	 * Ces champs ne sont PAS soumis directement : le JS lit la sélection au clic sur
	 * « Ajouter », l'ajoute comme ligne au tableau du panier, puis réinitialise le
	 * configurateur. Les `name` servent uniquement à grouper les radios par section.
	 * Chaque valeur porte son `data-si` (section), `data-name`, `data-price` et ses
	 * `data-img*` (échange de la photo de galerie + récap du panier).
	 *
	 * @param int    $product_id Produit concerné.
	 * @param array  $options    Sections d'options (voir get_product_options()).
	 * @param string $prefix     Préfixe unique pour les `name`/`id` des radios (ex. offre+unité),
	 *                           afin de répéter ces sections plusieurs fois sur la même page.
	 * @return string
	 */
	protected function option_sections_html( $product_id, $options, $prefix = '' ) {
		$prefix = '' !== (string) $prefix ? (string) $prefix : (string) (int) $product_id;
		ob_start();
		foreach ( $options as $si => $sec ) :
			$label_disp = $this->localize_variant( $sec['label'] );
			?>
			<div class="aod-cod__field aod-cod__optsec<?php echo $sec['visual'] ? ' is-visual' : ''; ?>" data-si="<?php echo esc_attr( $si ); ?>" data-label="<?php echo esc_attr( $sec['label'] ); ?>">
				<label class="aod-cod__optlabel"><?php echo esc_html( $label_disp ); ?> <span>*</span></label>
				<div class="aod-cod__opts">
					<?php
					foreach ( $sec['values'] as $vi => $val ) :
						$oid = 'aod-cod-opt-' . $prefix . '-' . (int) $si . '-' . (int) $vi;
						$has_hex   = ! empty( $val['hex'] );
						$is_visual = $sec['visual'] || $has_hex;
						// Pastille couleur sans photo : rendu compact (point + nom).
						$is_color  = $has_hex && empty( $val['img'] );
						$opt_class = 'aod-cod__opt';
						if ( $is_visual ) {
							$opt_class .= ' aod-cod__opt--visual';
						}
						if ( $is_color ) {
							$opt_class .= ' aod-cod__opt--color';
						}
						?>
						<label class="<?php echo esc_attr( $opt_class ); ?>" for="<?php echo esc_attr( $oid ); ?>">
							<input type="radio" id="<?php echo esc_attr( $oid ); ?>" name="aod-opt-<?php echo esc_attr( $prefix . '-' . (int) $si ); ?>" value="<?php echo esc_attr( $val['name'] ); ?>" data-si="<?php echo esc_attr( $si ); ?>" data-name="<?php echo esc_attr( $val['name'] ); ?>" data-price="<?php echo esc_attr( $val['price'] ); ?>" data-img="<?php echo esc_url( $val['img'] ); ?>" data-img-id="<?php echo esc_attr( $val['img_id'] ); ?>" data-img-full="<?php echo esc_url( $val['img_full'] ); ?>" data-img-w="<?php echo esc_attr( $val['img_w'] ); ?>" data-img-h="<?php echo esc_attr( $val['img_h'] ); ?>" data-srcset="<?php echo esc_attr( $val['srcset'] ); ?>">
							<span class="aod-cod__opt-card">
								<?php if ( $is_visual && $val['img'] ) : ?>
									<span class="aod-cod__opt-thumb" style="background-image:url('<?php echo esc_url( $val['img'] ); ?>')"></span>
								<?php elseif ( $has_hex ) : ?>
									<span class="aod-cod__opt-thumb aod-cod__opt-thumb--color" style="background-color:<?php echo esc_attr( $val['hex'] ); ?>"></span>
								<?php endif; ?>
								<span class="aod-cod__opt-name"><?php echo esc_html( $this->localize_variant( $val['name'] ) ); ?></span>
								<?php if ( $val['price'] > 0 ) : ?>
									<span class="aod-cod__opt-plus">+<?php echo wp_strip_all_tags( wc_price( $val['price'] ) ); ?></span>
								<?php endif; ?>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
			<?php
		endforeach;
		return ob_get_clean();
	}

	/**
	 * Offres d'un produit (N unités à prix de lot), pour le formulaire de commande.
	 *
	 * Lit la méta `_aod_offers` ; à défaut, migre l'ancienne méta `_aod_qty_tiers`
	 * (prix par pièce → prix total du lot). La carte « 1 produit » est implicite et
	 * n'est PAS incluse ici (elle est ajoutée au rendu avec le prix d'affichage).
	 *
	 * @param WC_Product $product
	 * @return array Liste de [ 'qty' => int, 'price' => float (total du lot) ], triée par qty.
	 */
	protected function get_product_offers( $product ) {
		$offers = array();
		if ( ! $product ) {
			return $offers;
		}
		$raw = $product->get_meta( '_aod_offers' );
		if ( is_array( $raw ) && $raw ) {
			foreach ( $raw as $o ) {
				$qty   = isset( $o['qty'] ) ? (int) $o['qty'] : 0;
				$price = isset( $o['price'] ) ? (float) $o['price'] : 0;
				if ( $qty >= 2 && $price > 0 ) {
					$offers[] = array( 'qty' => $qty, 'price' => $price );
				}
			}
			if ( $offers ) {
				usort( $offers, function ( $a, $b ) {
					return $a['qty'] - $b['qty'];
				} );
				return $offers;
			}
		}

		// Compat : ancien « prix par quantité » (prix par pièce) → offres (prix total du lot).
		$tiers = $product->get_meta( '_aod_qty_tiers' );
		if ( is_array( $tiers ) ) {
			foreach ( $tiers as $t ) {
				$min = isset( $t['min'] ) ? (int) $t['min'] : 0;
				$pp  = isset( $t['price'] ) ? (float) $t['price'] : 0;
				if ( $min >= 2 && $pp > 0 ) {
					$offers[] = array( 'qty' => $min, 'price' => $pp * $min );
				}
			}
		}
		usort( $offers, function ( $a, $b ) {
			return $a['qty'] - $b['qty'];
		} );
		return $offers;
	}

	/**
	 * Cartes d'offres : « 1 produit » (par défaut) + une carte par offre configurée.
	 *
	 * Chaque carte porte `data-qty` (nombre d'unités) et `data-price` (prix total du lot)
	 * pour que le JS calcule le sous-total. La première carte est sélectionnée par défaut.
	 * Si le produit a des variantes, le panneau de sélection (N blocs « Article k ») est
	 * imbriqué DANS la carte et se déplie à l'intérieur de celle-ci une fois sélectionnée.
	 *
	 * @param int   $product_id
	 * @param float $base_price  Prix d'affichage unitaire (carte « 1 produit »).
	 * @param array $offers      Offres (voir get_product_offers()).
	 * @param array $options     Sections d'options (vide = produit sans variantes).
	 * @return string
	 */
	protected function offer_cards_html( $product_id, $base_price, $offers, $options = array() ) {
		// Carte « 1 produit » implicite en tête, puis les offres.
		$cards       = array_merge( array( array( 'qty' => 1, 'price' => $base_price ) ), $offers );
		$name        = 'aod-offer-' . (int) $product_id;
		$has_options = ! empty( $options );
		ob_start();
		?>
		<div class="aod-cod__offers" role="radiogroup" aria-label="<?php esc_attr_e( 'Choisissez votre offre', 'aod-cod-form' ); ?>">
			<?php foreach ( $cards as $oi => $card ) :
				$qty   = (int) $card['qty'];
				$total = (float) $card['price'];
				$old   = $base_price * $qty;             // Prix « plein » (achat à l'unité).
				$save  = $old - $total;                  // Économie du lot.
				$oid   = 'aod-cod-offer-' . (int) $product_id . '-' . (int) $oi;
				?>
				<div class="aod-cod__offer-card<?php echo 0 === $oi ? ' is-selected' : ''; ?>" data-offer="<?php echo esc_attr( $oi ); ?>" data-qty="<?php echo esc_attr( $qty ); ?>" data-price="<?php echo esc_attr( $total ); ?>">
					<label class="aod-cod__offer-head" for="<?php echo esc_attr( $oid ); ?>">
						<input type="radio" id="<?php echo esc_attr( $oid ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $oi ); ?>" <?php checked( 0, $oi ); ?>>
						<span class="aod-cod__offer-body">
							<span class="aod-cod__offer-title">
								<?php
								/* translators: %d: nombre d'unités */
								echo esc_html( sprintf( _n( '%d produit', '%d produits', $qty, 'aod-cod-form' ), $qty ) );
								?>
							</span>
							<?php if ( $qty > 1 && $save > 0 ) : ?>
								<span class="aod-cod__offer-badge"><?php echo esc_html( sprintf( __( 'Économisez %s', 'aod-cod-form' ), wp_strip_all_tags( wc_price( $save ) ) ) ); ?></span>
							<?php endif; ?>
							<span class="aod-cod__offer-price">
								<span class="aod-cod__offer-now"><?php echo wp_kses_post( wc_price( $total ) ); ?></span>
								<?php if ( $qty > 1 && $save > 0 ) : ?>
									<s class="aod-cod__offer-old"><?php echo wp_kses_post( wc_price( $old ) ); ?></s>
								<?php endif; ?>
							</span>
						</span>
						<span class="aod-cod__offer-check" aria-hidden="true"></span>
					</label>
					<?php if ( $has_options ) : ?>
						<div class="aod-cod__offer-panel" data-offer="<?php echo esc_attr( $oi ); ?>"<?php echo 0 === $oi ? '' : ' hidden'; ?>>
							<?php for ( $u = 0; $u < $qty; $u++ ) :
								$prefix = (int) $product_id . '-' . (int) $oi . '-' . (int) $u;
								?>
								<div class="aod-cod__unit" data-unit="<?php echo esc_attr( $u ); ?>">
									<?php if ( $qty > 1 ) : ?>
										<div class="aod-cod__unit-head"><?php echo esc_html( sprintf( __( 'Article %d', 'aod-cod-form' ), $u + 1 ) ); ?></div>
									<?php endif; ?>
									<?php echo $this->option_sections_html( $product_id, $options, $prefix ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</div>
							<?php endfor; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
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

		// Offres (prix par quantité) : N unités de ce produit à prix de lot. La carte
		// « 1 produit » (prix d'affichage) est ajoutée implicitement au rendu.
		$offers = $this->get_product_offers( $product );

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
		<div class="aod-cod" data-product="<?php echo esc_attr( $product_id ); ?>" data-price="<?php echo esc_attr( $price ); ?>">
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
				<?php // Cartes d'offres : « 1 produit » par défaut + une carte par offre. Les
				// variantes (N blocs « Article k ») se déplient à l'intérieur de la carte choisie. ?>
				<?php echo $this->offer_cards_html( $product_id, $price, $offers, $has_options ? $options : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php if ( $has_options ) : ?>
					<p class="aod-cod__config-msg" role="alert"></p>
				<?php endif; ?>
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
		// Articles : chaque entrée = une combinaison de variantes + sa quantité,
		// ex. items[0][opt][0]=L, items[0][opt][1]=Rouge, items[0][qty]=2.
		$items_raw  = ( isset( $_POST['items'] ) && is_array( $_POST['items'] ) ) ? wp_unslash( $_POST['items'] ) : array();
		// Rétro-compat : ancien format mono-article (opt[]=… + qty).
		if ( ! $items_raw && ( isset( $_POST['opt'] ) || isset( $_POST['qty'] ) ) ) {
			$items_raw = array( array(
				'opt' => ( isset( $_POST['opt'] ) && is_array( $_POST['opt'] ) ) ? wp_unslash( $_POST['opt'] ) : array(),
				'qty' => isset( $_POST['qty'] ) ? absint( $_POST['qty'] ) : 1,
			) );
		}

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

		// Chaque article devient une ligne de commande WooCommerce distincte
		// (visible dans le tableau ET le détail de la commande), avec son propre
		// jeu de variantes (Taille=L, Couleur=Rouge…) et sa quantité. Le client peut
		// ainsi commander, par ex., 2× « L Rouge » + 1× « M Bleu » en une seule fois.
		$lines     = array();   // [ 'qty', 'option_meta', 'supplement' ] par article.
		$total_qty = 0;         // quantité cumulée (sert au calcul des paliers).
		if ( $product ) {
			$sections = $this->get_product_options( $product );
			$line_no  = 0;
			foreach ( $items_raw as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$line_no++;
				$line_qty = isset( $item['qty'] ) ? max( 1, absint( $item['qty'] ) ) : 1;
				$opt_sel  = ( isset( $item['opt'] ) && is_array( $item['opt'] ) ) ? $item['opt'] : array();

				$option_meta = array();   // label => valeur, visible sur la commande.
				$supplement  = 0.0;       // somme des suppléments des options de CETTE ligne.
				$line_ok     = true;
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
						/* translators: 1: numéro de l'article, 2: nom de la section (Taille, Couleur…) */
						$errors[] = sprintf( __( 'Article %1$d : veuillez choisir « %2$s ».', 'aod-cod-form' ), $line_no, $sec['label'] );
						$line_ok  = false;
						continue;
					}
					$option_meta[ $sec['label'] ] = $match['name'];
					$supplement                  += (float) $match['price'];
				}
				if ( ! $line_ok ) {
					continue;
				}
				$lines[]    = array( 'qty' => $line_qty, 'option_meta' => $option_meta, 'supplement' => $supplement );
				$total_qty += $line_qty;
			}
			if ( ! $lines && ! $errors ) {
				$errors[] = __( 'Veuillez ajouter au moins un article.', 'aod-cod-form' );
			}
		}

		if ( $errors ) {
			wp_send_json_error( array( 'message' => implode( ' ', $errors ) ), 400 );
		}

		// --- Création de la commande ---
		try {
			$order    = wc_create_order();
			$subtotal = 0;
			// Prix de lot de l'offre correspondant à la quantité TOTALE (« 2 produits : X »),
			// réparti à parts égales sur les unités ; les suppléments des variantes restent
			// propres à chaque ligne.
			$lot_total = $this->offer_lot_total( $product, $total_qty );
			$per_unit  = $total_qty > 0 ? $lot_total / $total_qty : (float) wc_get_price_to_display( $product );
			foreach ( $lines as $line ) {
				$unit       = $per_unit + $line['supplement'];
				$line_total = $unit * $line['qty'];
				$item_id    = $order->add_product( $product, $line['qty'] );
				if ( $item_id ) {
					$item = $order->get_item( $item_id );
					if ( $item ) {
						$item->set_subtotal( $line_total );
						$item->set_total( $line_total );
						// Choix de variantes visibles sur la commande (Taille : L, Couleur : Rouge…).
						foreach ( $line['option_meta'] as $olabel => $oval ) {
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
	 * Prix TOTAL du lot pour une quantité donnée, selon les offres du produit.
	 *
	 * Renvoie le prix de l'offre dont le nombre d'unités correspond exactement à la
	 * quantité (« 2 produits : X »), s'il est réellement inférieur au plein tarif.
	 * Sinon — pas d'offre pour cette taille de lot, produit variable, ou qty < 2 —
	 * on facture le plein tarif (prix d'affichage × quantité).
	 *
	 * @param WC_Product $product
	 * @param int        $qty
	 * @return float Prix total à facturer pour le lot.
	 */
	protected function offer_lot_total( $product, $qty ) {
		$base = (float) wc_get_price_to_display( $product );
		$qty  = max( 1, (int) $qty );
		if ( ! $product || $product->is_type( 'variation' ) || $qty < 2 ) {
			return $base * $qty;
		}
		foreach ( $this->get_product_offers( $product ) as $o ) {
			$oqty   = (int) $o['qty'];
			$oprice = (float) $o['price'];
			if ( $oqty === $qty && $oprice > 0 && $oprice < $base * $qty ) {
				return $oprice;
			}
		}
		return $base * $qty;
	}
}
