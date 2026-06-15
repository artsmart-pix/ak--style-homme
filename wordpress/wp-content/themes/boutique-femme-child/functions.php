<?php
/**
 * Boutique Femme — child theme de Hello Elementor.
 *
 * Boutique e-commerce COD (pantalon femme grande taille). Côté public :
 * exactement 3 pages (Accueil, Boutique, Contact). Aucun panier ni compte.
 * Branding (logo + nom) via le Personnalisateur. FR/AR + RTL.
 *
 * @package BoutiqueFemme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BF_VERSION', '1.0.8' );
define( 'BF_DIR', get_stylesheet_directory() );
define( 'BF_URI', get_stylesheet_directory_uri() );

require_once BF_DIR . '/inc/setup.php';        // supports, menus, image sizes, branding
require_once BF_DIR . '/inc/enqueue.php';      // styles + scripts
require_once BF_DIR . '/inc/template-tags.php'; // helpers d'affichage (héros, réassurance…)
require_once BF_DIR . '/inc/woocommerce.php';  // habillage Boutique + retrait panier/compte
require_once BF_DIR . '/inc/contact-form.php'; // formulaire de contact bilingue (AJAX)
