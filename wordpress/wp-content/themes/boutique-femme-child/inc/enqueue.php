<?php
/**
 * Chargement des feuilles de style et scripts.
 *
 * @package BoutiqueFemme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', function () {
	// Style du parent Hello Elementor (réinitialisation légère).
	$parent = 'hello-elementor';
	if ( wp_style_is( $parent, 'registered' ) || wp_style_is( $parent, 'enqueued' ) ) {
		// déjà géré par le parent
	}

	// Polices : Sora (titres display) + Space Grotesk (libellés) + Inter (texte).
	wp_enqueue_style(
		'bf-fonts',
		'https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap',
		array(),
		null
	);

	// Style principal du thème enfant.
	wp_enqueue_style(
		'bf-main',
		BF_URI . '/assets/css/main.css',
		array(),
		BF_VERSION
	);

	// Refonte « bold & coloré · animation maximale » (couche au-dessus de main).
	wp_enqueue_style(
		'bf-redesign',
		BF_URI . '/assets/css/redesign.css',
		array( 'bf-main' ),
		BF_VERSION
	);

	// Variante RTL (arabe).
	if ( is_rtl() ) {
		wp_enqueue_style(
			'bf-rtl',
			BF_URI . '/assets/css/rtl.css',
			array( 'bf-main', 'bf-redesign' ),
			BF_VERSION
		);
	}

	// Script du thème : nav mobile, animations au scroll, formulaire contact.
	wp_enqueue_script(
		'bf-main',
		BF_URI . '/assets/js/main.js',
		array(),
		BF_VERSION,
		true
	);

	// Moteur d'animations « spectaculaire » (curseur, magnétique, tilt, scroll).
	wp_enqueue_script(
		'bf-fx',
		BF_URI . '/assets/js/fx.js',
		array(),
		BF_VERSION,
		true
	);

	wp_localize_script( 'bf-main', 'BF', array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'bf_contact' ),
		'i18n'    => array(
			'sending' => __( 'Envoi…', 'boutique-femme' ),
			'sent'    => __( 'Message envoyé. Merci, nous vous répondrons vite.', 'boutique-femme' ),
			'error'   => __( 'Une erreur est survenue. Réessayez ou appelez-nous.', 'boutique-femme' ),
			'fill'    => __( 'Merci de remplir les champs obligatoires.', 'boutique-femme' ),
		),
	) );
}, 20 );

/**
 * Pas de surcharge des styles Woo par défaut, mais on garde la main : on
 * laisse WooCommerce charger ses styles puis main.css les ré-habille.
 */
