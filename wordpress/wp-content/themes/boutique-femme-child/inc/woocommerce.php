<?php
/**
 * Habillage WooCommerce de la Boutique + suppression totale du tunnel
 * panier / commande / compte (achat uniquement via le formulaire COD).
 *
 * @package BoutiqueFemme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 *  1. Aucun panier / compte / checkout côté acheteur (règle d'or n°4)
 * ---------------------------------------------------------------------- */

// Retire le bouton « Ajouter au panier » des cartes produit (archive/shop).
// NB : on NE rend PAS les produits « non achetables » — le formulaire COD
// (aod-cod-form) exige is_purchasable() pour s'afficher et créer la commande.
// On se contente de retirer les boutons/tunnel panier de l'UI.
add_action( 'init', function () {
	remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );
} );

// Redirige panier / commande / mon-compte vers la boutique.
add_action( 'template_redirect', function () {
	if ( is_admin() || ! function_exists( 'is_cart' ) ) {
		return;
	}
	if ( is_cart() || is_checkout() || is_account_page() ) {
		wp_safe_redirect( get_permalink( wc_get_page_id( 'shop' ) ) ?: home_url( '/' ) );
		exit;
	}
} );

// Masque l'éventuel widget/menu « panier » et fragments.
add_filter( 'woocommerce_add_to_cart_fragments', '__return_empty_array', 99 );

// Pas de panier → on retire le script cart-fragments (et son URL /cart, son requête AJAX).
add_action( 'wp_enqueue_scripts', function () {
	wp_dequeue_script( 'wc-cart-fragments' );
	wp_dequeue_script( 'wc-add-to-cart' );
}, 99 );

// Retire les liens Woo (Panier, Commande, Mon compte) s'ils traînent dans un menu.
add_filter( 'wp_nav_menu_objects', function ( $items ) {
	$shop_pages = array_filter( array(
		wc_get_page_id( 'cart' ),
		wc_get_page_id( 'checkout' ),
		wc_get_page_id( 'myaccount' ),
	) );
	foreach ( $items as $k => $item ) {
		if ( in_array( (int) $item->object_id, $shop_pages, true ) ) {
			unset( $items[ $k ] );
			continue;
		}
		// Le switcher FR/AR change la locale mais pas les titres d'items de menu
		// (texte stocké, pas du gettext). On les traduit donc via __() : si une
		// traduction existe dans le .po du thème, elle s'applique ; sinon le
		// libellé d'origine est conservé (reste générique pour tout client).
		if ( ! empty( $item->title ) ) {
			// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
			$item->title = __( $item->title, 'boutique-femme' );
		}
	}
	return $items;
}, 10, 1 );

/* -------------------------------------------------------------------------
 *  2. Présentation de la Boutique
 * ---------------------------------------------------------------------- */

// 12 produits par page, grille 3 colonnes.
add_filter( 'loop_shop_per_page', function () {
	return 12;
} );
add_filter( 'loop_shop_columns', function () {
	return 3;
} );

// Retire le fil d'ariane Woo (on garde une page épurée) et le tri par défaut.
add_action( 'init', function () {
	remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
} );

// Enveloppe d'ouverture/fermeture : on remplace les wrappers du thème parent
// pour maîtriser la largeur et le fond.
remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );
add_action( 'woocommerce_before_main_content', function () {
	echo '<div class="bf-shop-wrap"><div class="bf-container">';
}, 10 );
add_action( 'woocommerce_after_main_content', function () {
	echo '</div></div>';
}, 10 );

// En-tête de boutique sur-mesure (bandeau + filtres par catégorie).
add_action( 'woocommerce_before_main_content', function () {
	if ( ! ( is_shop() || is_product_category() || is_product_tag() ) ) {
		return;
	}

	$title    = woocommerce_page_title( false );
	$shop_url = get_permalink( wc_get_page_id( 'shop' ) );
	$current  = is_product_category() ? get_queried_object_id() : 0;

	// Sous-titre : description de la catégorie si dispo, sinon accroche générique.
	$sub = __( 'Des essentiels masculins bien coupés — t-shirts, polos, sweats et accessoires. Paiement à la livraison partout en Algérie.', 'boutique-femme' );
	if ( is_product_category() ) {
		$term = get_queried_object();
		if ( $term && ! empty( $term->description ) ) {
			$sub = wp_strip_all_tags( $term->description );
		}
	}

	// Catégories, rangées dans l'ordre logique du rayon (sinon alphabétique).
	$cats = get_terms( array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => true,
		'exclude'    => array( (int) get_option( 'default_product_cat' ) ),
	) );
	$rank = array( 't-shirts-polos' => 1, 'pantalons' => 2, 'sweats' => 3, 'accessoires' => 4 );
	if ( ! is_wp_error( $cats ) ) {
		usort( $cats, function ( $a, $b ) use ( $rank ) {
			$ra = isset( $rank[ $a->slug ] ) ? $rank[ $a->slug ] : 99;
			$rb = isset( $rank[ $b->slug ] ) ? $rank[ $b->slug ] : 99;
			return ( $ra === $rb ) ? strcmp( $a->name, $b->name ) : $ra - $rb;
		} );
	}

	echo '<header class="bf-shop-hero reveal">';
	echo '<span class="bf-shop-hero__glow" aria-hidden="true"></span>';
	echo '<p class="bf-eyebrow">' . esc_html__( 'Notre collection', 'boutique-femme' ) . '</p>';
	echo '<h1 class="bf-shop-title ak-grad-text">' . esc_html( $title ) . '</h1>';
	echo '<p class="bf-shop-sub">' . esc_html( $sub ) . '</p>';

	if ( ! empty( $cats ) && ! is_wp_error( $cats ) ) {
		echo '<nav class="bf-shop-filters" aria-label="' . esc_attr__( 'Filtrer par catégorie', 'boutique-femme' ) . '">';
		printf(
			'<a class="bf-chip%s" href="%s">%s</a>',
			$current ? '' : ' is-active',
			esc_url( $shop_url ),
			esc_html__( 'Tout', 'boutique-femme' )
		);
		foreach ( $cats as $cat ) {
			printf(
				'<a class="bf-chip%s" href="%s">%s<span class="bf-chip__n">%d</span></a>',
				( (int) $cat->term_id === (int) $current ) ? ' is-active' : '',
				esc_url( get_term_link( $cat ) ),
				esc_html( $cat->name ),
				(int) $cat->count
			);
		}
		echo '</nav>';
	}

	echo '</header>';
}, 25 );

// Supprime le titre Woo natif (remplacé par le nôtre).
add_filter( 'woocommerce_show_page_title', '__return_false' );

// Badge « Promo » plus joli (texte au lieu du « -x% » natif si pas voulu).
add_filter( 'woocommerce_sale_flash', function ( $html, $post, $product ) {
	return '<span class="bf-badge bf-badge--sale">' . esc_html__( 'Promo', 'boutique-femme' ) . '</span>';
}, 10, 3 );

// Sur les cartes produit, le bouton renvoie vers la fiche (jamais le panier).
add_action( 'woocommerce_after_shop_loop_item', function () {
	global $product;
	if ( ! $product ) {
		return;
	}
	printf(
		'<a href="%s" class="bf-btn bf-btn--card">%s</a>',
		esc_url( get_permalink( $product->get_id() ) ),
		esc_html__( 'Commander', 'boutique-femme' )
	);
}, 12 );

// Retire le compteur de résultats Woo (« Showing all… ») — plus épuré.
add_action( 'init', function () {
	remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
} );

// Corrige l'inversion du prix sur les cartes (Accueil/Boutique) et la fiche.
// Cause : le symbole de la devise DZD est arabe (« د.ج », fortement RTL) ; or
// WooCommerce enveloppe le prix dans un <bdi> sans dir → dir="auto" par défaut.
// L'algorithme bidi déduit alors la direction du 1er caractère FORT : les
// chiffres sont neutres, donc c'est le « د » arabe qui l'emporte → tout le bloc
// bascule en RTL et le symbole se retrouve à GAUCHE du nombre (« د.ج 4 900 »),
// y compris sur la page française. On force un dir="ltr" explicite : les chiffres
// (latins) précèdent toujours le symbole — ordre correct et lisible en FR ET AR.
add_filter( 'wc_price', function ( $html ) {
	return str_replace( '<bdi>', '<bdi dir="ltr">', $html );
}, 10, 1 );

/* -------------------------------------------------------------------------
 *  2bis. Traductions des chaînes WooCommerce visibles (FR/AR)
 *
 *  Les packs de langue Woo ne sont pas disponibles hors-ligne : on traduit
 *  à la main les quelques libellés front visibles (tri, onglets, produits
 *  liés). FR par défaut ; AR quand le switcher a basculé la locale.
 * ---------------------------------------------------------------------- */
add_filter( 'gettext_woocommerce', function ( $translation, $text, $domain ) {
	static $maps = null;
	if ( null === $maps ) {
		$maps = array(
			'fr' => array(
				'Default sorting'              => 'Tri par défaut',
				'Sort by popularity'           => 'Les plus populaires',
				'Sort by average rating'       => 'Les mieux notés',
				'Sort by latest'               => 'Les plus récents',
				'Sort by price: low to high'   => 'Prix croissant',
				'Sort by price: high to low'   => 'Prix décroissant',
				'Description'                  => 'Description',
				'Additional information'       => 'Informations complémentaires',
				'Reviews'                      => 'Avis',
				'Related products'             => 'Vous aimerez aussi',
				'You may also like&hellip;'    => 'Vous aimerez aussi',
				'Category:'                    => 'Catégorie :',
				'Categories:'                  => 'Catégories :',
				'No products were found matching your selection.' => 'Aucun produit ne correspond à votre recherche.',
			),
			'ar' => array(
				'Default sorting'              => 'الترتيب الافتراضي',
				'Sort by popularity'           => 'الأكثر رواجاً',
				'Sort by average rating'       => 'الأعلى تقييماً',
				'Sort by latest'               => 'الأحدث',
				'Sort by price: low to high'   => 'السعر: من الأقل إلى الأعلى',
				'Sort by price: high to low'   => 'السعر: من الأعلى إلى الأقل',
				'Description'                  => 'الوصف',
				'Additional information'       => 'معلومات إضافية',
				'Reviews'                      => 'التقييمات',
				'Related products'             => 'قد يعجبك أيضاً',
				'You may also like&hellip;'    => 'قد يعجبك أيضاً',
				'Category:'                    => 'الفئة:',
				'Categories:'                  => 'الفئات:',
				'No products were found matching your selection.' => 'لا يوجد منتج يطابق بحثك.',
			),
		);
	}
	$lang = ( 0 === strpos( get_locale(), 'ar' ) ) ? 'ar' : 'fr';
	return isset( $maps[ $lang ][ $text ] ) ? $maps[ $lang ][ $text ] : $translation;
}, 10, 3 );

/* -------------------------------------------------------------------------
 *  3. Réassurance sous le formulaire, sur la fiche produit
 * ---------------------------------------------------------------------- */
add_action( 'woocommerce_single_product_summary', function () {
	if ( ! function_exists( 'bf_reassurance_inline' ) ) {
		return;
	}
	bf_reassurance_inline();
}, 36 );
