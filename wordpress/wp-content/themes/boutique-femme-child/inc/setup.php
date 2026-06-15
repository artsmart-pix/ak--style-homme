<?php
/**
 * Configuration du thème : supports, menus, tailles d'images, branding.
 *
 * @package BoutiqueFemme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chargement des traductions du thème.
 *
 * WordPress 7 charge l'i18n via WP_Translation_Controller : on passe la locale
 * effective EN 3e argument de load_textdomain() (le helper
 * load_child_theme_textdomain() ne suffit pas ici). Format .l10n.php prioritaire,
 * repli .mo. La locale 'ar' est posée par le switcher d'aod-cod-form (cookie).
 */
add_action( 'after_setup_theme', function () {
	$locale = determine_locale();
	foreach ( array( 'l10n.php', 'mo' ) as $ext ) {
		$file = BF_DIR . "/languages/boutique-femme-{$locale}.{$ext}";
		if ( is_readable( $file ) ) {
			load_textdomain( 'boutique-femme', $file, $locale );
			break;
		}
	}
}, 5 );

/**
 * Supports du thème + emplacements de menus.
 *
 * Le menu principal est assigné à « primary » ET « mobile_menu » :
 * le switcher FR/AR du plugin aod-cod-form ne s'injecte que si un menu
 * existe sur primary / secondary_menu / mobile_menu.
 */
add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'custom-logo', array(
		'height'      => 80,
		'width'       => 240,
		'flex-height' => true,
		'flex-width'  => true,
	) );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
	add_theme_support( 'woocommerce' );
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );

	register_nav_menus( array(
		'primary'     => __( 'Menu principal', 'boutique-femme' ),
		'mobile_menu' => __( 'Menu mobile', 'boutique-femme' ),
	) );

	// Vignettes produit nettes et au ratio portrait (mode).
	add_image_size( 'bf-portrait', 600, 800, true );
	add_image_size( 'bf-hero', 1600, 1100, true );
} );

/**
 * Le logo du Personnalisateur sert aussi de favicon de secours si aucune
 * icône de site n'est définie — branding centralisé, rien codé en dur.
 */

/**
 * Largeur de contenu (oEmbed, etc.).
 */
add_action( 'after_setup_theme', function () {
	$GLOBALS['content_width'] = 1200;
} );

/**
 * Coordonnées de la boutique — exposées via le Personnalisateur pour rester
 * génériques (aucune donnée client en dur). Utilisées par le footer et la
 * page Contact.
 */
add_action( 'customize_register', function ( $wp_customize ) {
	$wp_customize->add_section( 'bf_contact', array(
		'title'    => __( 'Boutique — Coordonnées', 'boutique-femme' ),
		'priority' => 30,
	) );

	$fields = array(
		'bf_phone'    => array( __( 'Téléphone', 'boutique-femme' ), '' ),
		'bf_whatsapp' => array( __( 'WhatsApp (format intl, ex. 213…)', 'boutique-femme' ), '' ),
		'bf_email'    => array( __( 'E-mail de contact', 'boutique-femme' ), get_option( 'admin_email' ) ),
		'bf_address'  => array( __( 'Adresse', 'boutique-femme' ), '' ),
		'bf_hours'    => array( __( 'Horaires', 'boutique-femme' ), '' ),
		'bf_instagram'=> array( __( 'Lien Instagram', 'boutique-femme' ), '' ),
		'bf_facebook' => array( __( 'Lien Facebook', 'boutique-femme' ), '' ),
		'bf_tiktok'   => array( __( 'Lien TikTok', 'boutique-femme' ), '' ),
	);
	foreach ( $fields as $id => $meta ) {
		$wp_customize->add_setting( $id, array(
			'default'           => $meta[1],
			'sanitize_callback' => 'wp_kses_post',
			'transport'         => 'refresh',
		) );
		$wp_customize->add_control( $id, array(
			'label'   => $meta[0],
			'section' => 'bf_contact',
			'type'    => ( 'bf_address' === $id ) ? 'textarea' : 'text',
		) );
	}

	// Slogan du héros (modifiable sans toucher au code).
	$wp_customize->add_setting( 'bf_hero_title', array(
		'default'           => __( 'Le pantalon qui épouse vos courbes', 'boutique-femme' ),
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'bf_hero_title', array(
		'label'   => __( 'Héros — slogan principal', 'boutique-femme' ),
		'section' => 'bf_contact',
		'type'    => 'text',
	) );
} );

/**
 * Le switcher FR/AR est placé manuellement (shortcode [aod_lang_switcher])
 * dans l'en-tête et l'off-canvas. On retire donc l'injection automatique du
 * plugin dans les menus, sinon il apparaîtrait en double (et dans le footer).
 */
add_action( 'init', function () {
	if ( class_exists( 'AOD_COD_Lang' ) ) {
		remove_filter( 'wp_nav_menu_items', array( 'AOD_COD_Lang', 'add_to_menu' ), 100 );
	}
}, 20 );

/**
 * Accès rapide à une coordonnée du Personnalisateur.
 *
 * @param string $key Identifiant du réglage (ex. bf_phone).
 * @return string
 */
function bf_info( $key ) {
	return (string) get_theme_mod( $key, '' );
}
