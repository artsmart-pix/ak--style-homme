<?php
/**
 * Helpers d'affichage : images (avec placeholders interchangeables),
 * bandeau de réassurance, briques réutilisables.
 *
 * @package BoutiqueFemme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retourne l'URL de la « vraie » photo pour un emplacement nommé, si elle a
 * été déposée dans assets/img/. Sinon chaîne vide → placeholder.
 *
 * Pour remplacer un placeholder par une vraie photo : déposer un fichier
 *   assets/img/<slot>.jpg   (ou .webp / .jpeg / .png)
 * — rien d'autre à faire, le slot le détecte automatiquement.
 *
 * @param string $slot Identifiant d'emplacement (ex. 'hero', 'cat-cargo').
 * @return string URL ou ''.
 */
function bf_image_url( $slot ) {
	static $cache = array();
	if ( isset( $cache[ $slot ] ) ) {
		return $cache[ $slot ];
	}
	$slot = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $slot ) );
	foreach ( array( 'webp', 'jpg', 'jpeg', 'png', 'avif' ) as $ext ) {
		$path = BF_DIR . "/assets/img/{$slot}.{$ext}";
		if ( file_exists( $path ) ) {
			return $cache[ $slot ] = BF_URI . "/assets/img/{$slot}.{$ext}";
		}
	}
	return $cache[ $slot ] = '';
}

/**
 * Palette de placeholders : dégradés nude/terracotta variés selon le slot,
 * pour que les blocs ne soient pas vides ni monotones avant les vraies photos.
 *
 * @param string $slot
 * @return string Valeur CSS de background.
 */
function bf_placeholder_gradient( $slot ) {
	$grads = array(
		'linear-gradient(135deg, #E8D9CF 0%, #C9A86A 100%)',
		'linear-gradient(135deg, #EBD7CC 0%, #C57B57 100%)',
		'linear-gradient(160deg, #F0E4DA 0%, #D8B79B 100%)',
		'linear-gradient(135deg, #DDC6B6 0%, #A9613F 100%)',
		'linear-gradient(135deg, #F3E9E1 0%, #CDA98C 100%)',
		'linear-gradient(150deg, #E3CDBD 0%, #B98A63 100%)',
	);
	$i = abs( crc32( $slot ) ) % count( $grads );
	return $grads[ $i ];
}

/**
 * Rend une image « plein cadre » pour un emplacement : <img> si une vraie
 * photo existe, sinon un bloc placeholder élégant (dégradé + libellé discret).
 *
 * @param string $slot
 * @param string $alt
 * @param array  $args  class, ratio (ex. '3/4'), label
 */
function bf_picture( $slot, $alt = '', $args = array() ) {
	$args  = wp_parse_args( $args, array(
		'class' => '',
		'ratio' => '3 / 4',
		'label' => '',
	) );
	$url   = bf_image_url( $slot );
	$class = trim( 'bf-pic ' . $args['class'] );

	if ( $url ) {
		printf(
			'<img class="%s" src="%s" alt="%s" loading="lazy" decoding="async" style="aspect-ratio:%s">',
			esc_attr( $class ),
			esc_url( $url ),
			esc_attr( $alt ),
			esc_attr( $args['ratio'] )
		);
		return;
	}

	printf(
		'<span class="%s bf-pic--ph" style="aspect-ratio:%s;background:%s" role="img" aria-label="%s"><span class="bf-pic__hint">%s</span></span>',
		esc_attr( $class ),
		esc_attr( $args['ratio'] ),
		esc_attr( bf_placeholder_gradient( $slot ) ),
		esc_attr( $alt ),
		esc_html( $args['label'] ?: $alt )
	);
}

/**
 * Style de fond (background-image) pour un emplacement, avec dégradé de repli.
 *
 * @param string $slot
 * @return string Attribut style prêt à l'emploi.
 */
function bf_bg_style( $slot ) {
	$url = bf_image_url( $slot );
	if ( $url ) {
		return 'background-image:linear-gradient(rgba(12,16,26,.50),rgba(12,16,26,.40)),url(' . esc_url( $url ) . ');';
	}
	return 'background-image:' . bf_placeholder_gradient( $slot ) . ';';
}

/**
 * Trois arguments de réassurance (COD, couverture nationale, échange) —
 * réutilisés en bandeau (Accueil) et en version compacte (fiche produit).
 *
 * @return array
 */
function bf_reassurance_items() {
	return array(
		array(
			'icon'  => 'truck',
			'title' => __( 'Paiement à la livraison', 'boutique-femme' ),
			'text'  => __( 'Vous payez en espèces, à la réception. Zéro risque.', 'boutique-femme' ),
		),
		array(
			'icon'  => 'pin',
			'title' => __( 'Livraison 58 wilayas', 'boutique-femme' ),
			'text'  => __( 'Partout en Algérie, à domicile ou en stop-desk.', 'boutique-femme' ),
		),
		array(
			'icon'  => 'heart',
			'title' => __( 'Coupe moderne homme', 'boutique-femme' ),
			'text'  => __( 'Du S au 3XL, coupe nette et confortable.', 'boutique-femme' ),
		),
	);
}

/**
 * Petites icônes SVG inline (sans dépendance externe).
 *
 * @param string $name
 * @return string
 */
function bf_icon( $name ) {
	$icons = array(
		'truck' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h11v8H3zM14 10h4l3 3v2h-7z"/><circle cx="7" cy="18" r="1.6"/><circle cx="17.5" cy="18" r="1.6"/></svg>',
		'pin'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.4"/></svg>',
		'heart' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20s-7-4.6-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.4-7 10-7 10z"/></svg>',
		'check' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
		'arrow' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
		'star'  => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="m12 2 2.9 6.3 6.9.7-5.1 4.6 1.4 6.8L12 17.8 5.9 20.4l1.4-6.8L2.2 9l6.9-.7z"/></svg>',
	);
	return isset( $icons[ $name ] ) ? $icons[ $name ] : '';
}

/**
 * Bandeau de réassurance (3 colonnes) — pour la page d'accueil.
 */
function bf_reassurance_band() {
	$items = bf_reassurance_items();
	echo '<section class="bf-reassure reveal" aria-label="' . esc_attr__( 'Nos engagements', 'boutique-femme' ) . '">';
	echo '<div class="bf-container bf-reassure__grid">';
	foreach ( $items as $it ) {
		echo '<div class="bf-reassure__item">';
		echo '<span class="bf-reassure__icon">' . bf_icon( $it['icon'] ) . '</span>'; // phpcs:ignore
		echo '<div><h3>' . esc_html( $it['title'] ) . '</h3><p>' . esc_html( $it['text'] ) . '</p></div>';
		echo '</div>';
	}
	echo '</div></section>';
}

/**
 * Version compacte de la réassurance, sous le formulaire COD (fiche produit).
 */
function bf_reassurance_inline() {
	echo '<ul class="bf-reassure-inline">';
	foreach ( bf_reassurance_items() as $it ) {
		echo '<li><span class="bf-reassure-inline__i">' . bf_icon( 'check' ) . '</span>' . esc_html( $it['title'] ) . '</li>'; // phpcs:ignore
	}
	echo '</ul>';
}

/**
 * Wordmark stylisé du nom de la boutique (repli quand aucun logo n'est défini
 * dans le Personnalisateur). Reste générique : le PREMIER mot du nom du site
 * est accentué (terracotta), le reste en encre. Fonctionne pour n'importe quel
 * nom de client — rien n'est codé en dur.
 *
 * @param bool $light Variante claire (sur fond foncé, ex. footer).
 */
function bf_brand_wordmark( $light = false ) {
	$name  = trim( wp_strip_all_tags( get_bloginfo( 'name' ) ) );
	$parts = preg_split( '/\s+/', $name, 2 );
	$first = $parts[0];
	$rest  = isset( $parts[1] ) ? $parts[1] : '';

	$class = 'bf-logo-text' . ( $light ? ' bf-logo-text--light' : '' );
	$html  = '<span class="bf-logo-text__a">' . esc_html( $first ) . '</span>';
	if ( '' !== $rest ) {
		$html .= ' <span class="bf-logo-text__b">' . esc_html( $rest ) . '</span>';
	}
	return sprintf(
		'<a class="%s" href="%s" rel="home" aria-label="%s"><span class="bf-logo-text__mark">%s</span></a>',
		esc_attr( $class ),
		esc_url( home_url( '/' ) ),
		esc_attr( $name ),
		$html
	);
}

/**
 * Bandeau défilant (marquee) d'arguments de vente — mouvement horizontal
 * continu. Décoratif (aria-hidden) ; le contenu reste traduisible FR/AR et le
 * sens s'inverse en RTL via la CSS. Le ruban contient DEUX groupes identiques
 * pour une boucle sans couture (translateX -50 %).
 */
function bf_marquee() {
	$items = array(
		array( 'truck', __( 'Paiement à la livraison', 'boutique-femme' ) ),
		array( 'pin',   __( 'Livraison 58 wilayas', 'boutique-femme' ) ),
		array( 'heart', __( 'Du S au 3XL', 'boutique-femme' ) ),
		array( 'check', __( 'Échange facile', 'boutique-femme' ) ),
		array( 'star',  __( 'Tissus qui ne se déforment pas', 'boutique-femme' ) ),
	);

	$group = '<span class="bf-marquee__group">';
	foreach ( $items as $it ) {
		$group .= '<span class="bf-marquee__item">' . bf_icon( $it[0] ) . '<span>' . esc_html( $it[1] ) . '</span></span>';
	}
	$group .= '</span>';

	echo '<div class="bf-marquee" aria-hidden="true"><div class="bf-marquee__track">'
		. $group . $group // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		. '</div></div>';
}

/**
 * Lien vers la Boutique.
 *
 * @return string URL.
 */
function bf_shop_url() {
	$id = wc_get_page_id( 'shop' );
	return $id ? get_permalink( $id ) : home_url( '/' );
}
